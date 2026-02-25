<?php
/**
 * Mobile upload WebSocket server powered by Ratchet.
 *
 * Run:
 *   php admin/mobile_upload_websocket_server.php 0.0.0.0 8090
 *
 * Client protocol (JSON text frames):
 * 1) Subscribe desktop client
 *    {"action":"subscribe","session_id":"<hex-session-id>","doc_type":"resolutions|minutes|ordinances"}
 *
 * 2) Notify completion from mobile client
 *    {"action":"upload_complete","session_id":"<hex-session-id>","doc_type":"...","title":"...","uploaded_by":"...","result_id":123,"object_keys":[...],"image_urls":[...]}
 *
 * 3) Push live camera frames from mobile client
 *    {"action":"camera_frame","session_id":"<hex-session-id>","doc_type":"...","frame_data":"data:image/jpeg;base64,...","width":640,"height":360,"ts":1730000000000}
 *
 * Server pushes to subscribed desktop clients:
 *    {"type":"upload_complete",...}
 *    {"type":"camera_frame",...}
 *    {"type":"camera_status",...}
 */

declare(strict_types=1);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Composer autoload not found at {$autoloadPath}. Run 'composer install' in admin/ first.\n");
    exit(1);
}
require_once $autoloadPath;

use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

final class MobileUploadWebSocket implements MessageComponentInterface
{
    private const WEBSOCKET_PATH = '/mobile-upload';

    /** @var \SplObjectStorage<ConnectionInterface, array{session_id: ?string}> */
    private \SplObjectStorage $clients;

    /** @var array<string, \SplObjectStorage<ConnectionInterface, null>> */
    private array $subscriptions = [];

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if (!$this->isExpectedRoute($conn)) {
            $this->sendJson($conn, [
                'type' => 'error',
                'message' => 'Invalid websocket route',
                'expected_path' => self::WEBSOCKET_PATH,
            ]);
            $conn->close();
            return;
        }

        $this->clients->attach($conn, ['session_id' => null]);

        $sessionId = $this->sessionIdFromQuery($conn);
        if ($sessionId !== null) {
            $this->subscribeConnection($conn, $sessionId);
        }

        $this->sendJson($conn, [
            'type' => 'connected',
            'session_id' => $this->getSessionId($conn),
        ]);
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode((string)$msg, true);
        if (!is_array($data)) {
            return;
        }

        $action = (string)($data['action'] ?? '');
        if ($action === 'subscribe') {
            $sessionId = sanitizeSessionId((string)($data['session_id'] ?? ''));
            if ($sessionId === null) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Invalid session_id']);
                return;
            }
            $this->subscribeConnection($from, $sessionId);
            $this->sendJson($from, ['type' => 'subscribed', 'session_id' => $sessionId]);
            return;
        }

        if ($action === 'upload_complete') {
            $sessionId = sanitizeSessionId((string)($data['session_id'] ?? ''));
            if ($sessionId === null) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Invalid session_id']);
                return;
            }

            $message = [
                'type' => 'upload_complete',
                'session_id' => $sessionId,
                'doc_type' => (string)($data['doc_type'] ?? ''),
                'title' => (string)($data['title'] ?? 'Document'),
                'uploaded_by' => (string)($data['uploaded_by'] ?? 'mobile'),
                'result_id' => isset($data['result_id']) ? (int)$data['result_id'] : null,
                'object_keys' => is_array($data['object_keys'] ?? null)
                    ? array_values(array_filter($data['object_keys'], 'is_string'))
                    : [],
                'image_urls' => is_array($data['image_urls'] ?? null)
                    ? array_values(array_filter($data['image_urls'], 'is_string'))
                    : [],
                'deferred_to_desktop' => !empty($data['deferred_to_desktop']),
            ];
            $delivered = $this->broadcastToSession($sessionId, $message);
            $this->sendJson($from, ['type' => 'ack', 'delivered' => $delivered, 'session_id' => $sessionId]);
            return;
        }

        if ($action === 'camera_frame') {
            $sessionId = sanitizeSessionId((string)($data['session_id'] ?? ''));
            $frameData = (string)($data['frame_data'] ?? '');
            if ($sessionId === null || $frameData === '') {
                return;
            }

            if (strlen($frameData) > 1200000) {
                return;
            }

            $this->broadcastToSession($sessionId, [
                'type' => 'camera_frame',
                'session_id' => $sessionId,
                'doc_type' => (string)($data['doc_type'] ?? ''),
                'frame_data' => $frameData,
                'width' => isset($data['width']) ? (int)$data['width'] : null,
                'height' => isset($data['height']) ? (int)$data['height'] : null,
                'ts' => isset($data['ts']) ? (int)$data['ts'] : null,
            ]);
            return;
        }

        if ($action === 'camera_status') {
            $sessionId = sanitizeSessionId((string)($data['session_id'] ?? ''));
            if ($sessionId === null) {
                return;
            }
            $status = (string)($data['status'] ?? 'idle');
            $this->broadcastToSession($sessionId, [
                'type' => 'camera_status',
                'session_id' => $sessionId,
                'doc_type' => (string)($data['doc_type'] ?? ''),
                'status' => $status,
            ]);
            return;
        }

        if ($action === 'ping') {
            $this->sendJson($from, ['type' => 'pong']);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $sessionId = $this->getSessionId($conn);
        if ($sessionId !== null) {
            $this->unsubscribeConnection($conn, $sessionId);
        }

        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->onClose($conn);
        $conn->close();
    }

    private function sessionIdFromQuery(ConnectionInterface $conn): ?string
    {
        if (!isset($conn->httpRequest)) {
            return null;
        }

        $query = (string)$conn->httpRequest->getUri()->getQuery();
        if ($query === '') {
            return null;
        }

        parse_str($query, $params);
        if (!isset($params['session'])) {
            return null;
        }

        return sanitizeSessionId((string)$params['session']);
    }

    private function isExpectedRoute(ConnectionInterface $conn): bool
    {
        if (!isset($conn->httpRequest)) {
            return false;
        }

        $path = (string)$conn->httpRequest->getUri()->getPath();
        return $path === self::WEBSOCKET_PATH;
    }

    private function getSessionId(ConnectionInterface $conn): ?string
    {
        if (!$this->clients->contains($conn)) {
            return null;
        }

        $meta = $this->clients[$conn];
        if (!is_array($meta)) {
            return null;
        }

        $sessionId = $meta['session_id'] ?? null;
        return is_string($sessionId) ? $sessionId : null;
    }

    private function setSessionId(ConnectionInterface $conn, ?string $sessionId): void
    {
        if (!$this->clients->contains($conn)) {
            return;
        }

        $meta = $this->clients[$conn];
        if (!is_array($meta)) {
            $meta = ['session_id' => null];
        }
        $meta['session_id'] = $sessionId;
        $this->clients[$conn] = $meta;
    }

    private function subscribeConnection(ConnectionInterface $conn, string $sessionId): void
    {
        $currentSessionId = $this->getSessionId($conn);
        if ($currentSessionId !== null) {
            $this->unsubscribeConnection($conn, $currentSessionId);
        }

        if (!isset($this->subscriptions[$sessionId])) {
            $this->subscriptions[$sessionId] = new \SplObjectStorage();
        }

        $this->subscriptions[$sessionId]->attach($conn);
        $this->setSessionId($conn, $sessionId);
    }

    private function unsubscribeConnection(ConnectionInterface $conn, string $sessionId): void
    {
        if (!isset($this->subscriptions[$sessionId])) {
            return;
        }

        $sessionSubs = $this->subscriptions[$sessionId];
        if ($sessionSubs->contains($conn)) {
            $sessionSubs->detach($conn);
        }

        if (count($sessionSubs) === 0) {
            unset($this->subscriptions[$sessionId]);
        }

        $this->setSessionId($conn, null);
    }

    private function broadcastToSession(string $sessionId, array $message): int
    {
        if (!isset($this->subscriptions[$sessionId])) {
            return 0;
        }

        $delivered = 0;
        foreach ($this->subscriptions[$sessionId] as $client) {
            if (!$this->clients->contains($client)) {
                continue;
            }
            $this->sendJson($client, $message);
            $delivered++;
        }

        return $delivered;
    }

    private function sendJson(ConnectionInterface $conn, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        try {
            $conn->send($json);
        } catch (\Throwable $e) {
            // Ignore send failures; dead sockets are cleaned up on close.
        }
    }
}

function sanitizeSessionId(string $sessionId): ?string
{
    $sanitized = preg_replace('/[^a-f0-9]/', '', strtolower($sessionId));
    if (!is_string($sanitized) || strlen($sanitized) < 16) {
        return null;
    }
    return $sanitized;
}

$host = $argv[1] ?? '0.0.0.0';
$port = isset($argv[2]) ? (int)$argv[2] : 8090;
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "Invalid port: {$port}\n");
    exit(1);
}

echo "[mobile-ws] Listening on {$host}:{$port}\n";

$server = IoServer::factory(
    new HttpServer(new WsServer(new MobileUploadWebSocket())),
    $port,
    $host
);
$server->run();

<?php
/**
 * Mobile upload WebSocket server for per-session desktop/mobile signaling.
 *
 * Run:
 *   php admin/mobile_upload_websocket_server.php 0.0.0.0 8090
 *
 * Client protocol (JSON text frames):
 * 1) Subscribe desktop client
 *    {"action":"subscribe","session_id":"<hex-session-id>","doc_type":"resolutions|minutes|ordinances"}
 *
 * 2) Notify completion from mobile client
 *    {"action":"upload_complete","session_id":"<hex-session-id>","doc_type":"...","title":"...","uploaded_by":"...","result_id":123}
 *
 * Server pushes to subscribed desktop clients:
 *    {"type":"upload_complete","session_id":"...","doc_type":"...","title":"...","uploaded_by":"...","result_id":123}
 */

declare(strict_types=1);

$host = $argv[1] ?? '0.0.0.0';
$port = isset($argv[2]) ? (int)$argv[2] : 8090;

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to start server on {$host}:{$port} ({$errno}) {$errstr}\n");
    exit(1);
}

stream_set_blocking($server, false);

echo "[mobile-ws] Listening on {$host}:{$port}\n";

/**
 * @var array<int, array{
 *   socket: resource,
 *   handshake: bool,
 *   buffer: string,
 *   session_id: ?string
 * }>
 */
$clients = [];

/** @var array<string, array<int, bool>> */
$subscriptions = [];

while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        $read[] = $client['socket'];
    }

    $write = null;
    $except = null;

    $ready = @stream_select($read, $write, $except, 1);
    if ($ready === false || $ready === 0) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn === false) {
                continue;
            }
            stream_set_blocking($conn, false);
            $id = (int)$conn;
            $clients[$id] = [
                'socket' => $conn,
                'handshake' => false,
                'buffer' => '',
                'session_id' => null,
            ];
            continue;
        }

        $id = (int)$socket;
        if (!isset($clients[$id])) {
            continue;
        }

        $chunk = @fread($socket, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                closeClient($id, $clients, $subscriptions);
            }
            continue;
        }

        $clients[$id]['buffer'] .= $chunk;

        if (!$clients[$id]['handshake']) {
            if (strpos($clients[$id]['buffer'], "\r\n\r\n") === false) {
                continue;
            }

            $request = $clients[$id]['buffer'];
            $clients[$id]['buffer'] = '';

            $reqMeta = parseWebSocketRequest($request);
            if ($reqMeta === null) {
                closeClient($id, $clients, $subscriptions);
                continue;
            }

            $response = buildHandshakeResponse($reqMeta['key']);
            @fwrite($socket, $response);
            $clients[$id]['handshake'] = true;

            if ($reqMeta['session_id'] !== null) {
                subscribeClient($id, $reqMeta['session_id'], $clients, $subscriptions);
            }

            sendJson($socket, [
                'type' => 'connected',
                'session_id' => $clients[$id]['session_id'],
            ]);
            continue;
        }

        while (true) {
            $frame = decodeFrame($clients[$id]['buffer']);
            if ($frame === null) {
                break;
            }

            $opcode = $frame['opcode'];
            $payload = $frame['payload'];

            if ($opcode === 0x8) {
                closeClient($id, $clients, $subscriptions);
                break;
            }

            if ($opcode === 0x9) {
                @fwrite($socket, encodeFrame($payload, 0xA));
                continue;
            }

            if ($opcode !== 0x1) {
                continue;
            }

            $data = json_decode($payload, true);
            if (!is_array($data)) {
                continue;
            }

            handleClientMessage($id, $data, $clients, $subscriptions);
        }
    }
}

function handleClientMessage(int $clientId, array $data, array &$clients, array &$subscriptions): void
{
    $socket = $clients[$clientId]['socket'];
    $action = (string)($data['action'] ?? '');

    if ($action === 'subscribe') {
        $sessionId = sanitizeSessionId((string)($data['session_id'] ?? ''));
        if ($sessionId === null) {
            sendJson($socket, ['type' => 'error', 'message' => 'Invalid session_id']);
            return;
        }
        subscribeClient($clientId, $sessionId, $clients, $subscriptions);
        sendJson($socket, ['type' => 'subscribed', 'session_id' => $sessionId]);
        return;
    }

    if ($action === 'upload_complete') {
        $sessionId = sanitizeSessionId((string)($data['session_id'] ?? ''));
        if ($sessionId === null) {
            sendJson($socket, ['type' => 'error', 'message' => 'Invalid session_id']);
            return;
        }

        $message = [
            'type' => 'upload_complete',
            'session_id' => $sessionId,
            'doc_type' => (string)($data['doc_type'] ?? ''),
            'title' => (string)($data['title'] ?? 'Document'),
            'uploaded_by' => (string)($data['uploaded_by'] ?? 'mobile'),
            'result_id' => isset($data['result_id']) ? (int)$data['result_id'] : null,
        ];

        if (!isset($subscriptions[$sessionId])) {
            sendJson($socket, ['type' => 'ack', 'delivered' => 0, 'session_id' => $sessionId]);
            return;
        }

        $delivered = 0;
        foreach (array_keys($subscriptions[$sessionId]) as $subscriberId) {
            if (!isset($clients[$subscriberId])) {
                continue;
            }
            sendJson($clients[$subscriberId]['socket'], $message);
            $delivered++;
        }

        sendJson($socket, ['type' => 'ack', 'delivered' => $delivered, 'session_id' => $sessionId]);
        return;
    }

    if ($action === 'ping') {
        sendJson($socket, ['type' => 'pong']);
    }
}

function parseWebSocketRequest(string $request): ?array
{
    if (!preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $request, $keyMatch)) {
        return null;
    }

    $rawKey = trim($keyMatch[1]);
    if ($rawKey === '') {
        return null;
    }

    $sessionId = null;
    if (preg_match('/GET\s+([^\s]+)\s+HTTP\/1\.[01]/i', $request, $pathMatch)) {
        $path = $pathMatch[1];
        $parts = parse_url($path);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (isset($query['session'])) {
                $sessionId = sanitizeSessionId((string)$query['session']);
            }
        }
    }

    return [
        'key' => $rawKey,
        'session_id' => $sessionId,
    ];
}

function buildHandshakeResponse(string $clientKey): string
{
    $accept = base64_encode(sha1($clientKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    return "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
}

function decodeFrame(string &$buffer): ?array
{
    if (strlen($buffer) < 2) {
        return null;
    }

    $byte1 = ord($buffer[0]);
    $byte2 = ord($buffer[1]);
    $opcode = $byte1 & 0x0F;
    $masked = ($byte2 >> 7) & 1;
    $length = $byte2 & 0x7F;
    $offset = 2;

    if ($length === 126) {
        if (strlen($buffer) < 4) {
            return null;
        }
        $length = unpack('n', substr($buffer, 2, 2))[1];
        $offset = 4;
    } elseif ($length === 127) {
        if (strlen($buffer) < 10) {
            return null;
        }
        $parts = unpack('N2', substr($buffer, 2, 8));
        $length = ((int)$parts[1] << 32) + (int)$parts[2];
        $offset = 10;
    }

    $mask = '';
    if ($masked) {
        if (strlen($buffer) < $offset + 4) {
            return null;
        }
        $mask = substr($buffer, $offset, 4);
        $offset += 4;
    }

    if (strlen($buffer) < $offset + $length) {
        return null;
    }

    $payload = substr($buffer, $offset, $length);
    $buffer = (string)substr($buffer, $offset + $length);

    if ($masked) {
        $unmasked = '';
        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $unmasked;
    }

    return ['opcode' => $opcode, 'payload' => $payload];
}

function encodeFrame(string $payload, int $opcode = 0x1): string
{
    $length = strlen($payload);
    $header = chr(0x80 | ($opcode & 0x0F));

    if ($length <= 125) {
        $header .= chr($length);
    } elseif ($length <= 65535) {
        $header .= chr(126) . pack('n', $length);
    } else {
        $header .= chr(127) . pack('N2', intdiv($length, 4294967296), $length % 4294967296);
    }

    return $header . $payload;
}

function sendJson($socket, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    @fwrite($socket, encodeFrame($json, 0x1));
}

function subscribeClient(int $clientId, string $sessionId, array &$clients, array &$subscriptions): void
{
    $current = $clients[$clientId]['session_id'];
    if (is_string($current) && isset($subscriptions[$current][$clientId])) {
        unset($subscriptions[$current][$clientId]);
        if (empty($subscriptions[$current])) {
            unset($subscriptions[$current]);
        }
    }

    $clients[$clientId]['session_id'] = $sessionId;
    $subscriptions[$sessionId][$clientId] = true;
}

function closeClient(int $clientId, array &$clients, array &$subscriptions): void
{
    if (!isset($clients[$clientId])) {
        return;
    }

    $sessionId = $clients[$clientId]['session_id'];
    if (is_string($sessionId) && isset($subscriptions[$sessionId][$clientId])) {
        unset($subscriptions[$sessionId][$clientId]);
        if (empty($subscriptions[$sessionId])) {
            unset($subscriptions[$sessionId]);
        }
    }

    @fclose($clients[$clientId]['socket']);
    unset($clients[$clientId]);
}

function sanitizeSessionId(string $sessionId): ?string
{
    $sanitized = preg_replace('/[^a-f0-9]/', '', strtolower($sessionId));
    if (!is_string($sanitized) || strlen($sanitized) < 16) {
        return null;
    }
    return $sanitized;
}

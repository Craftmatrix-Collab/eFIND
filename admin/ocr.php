<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendOcrResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function resolveSafeOcrImagePath(string $rawPath): ?string
{
    $rawPath = trim($rawPath);
    if ($rawPath === '' || preg_match('#^(https?:)?//#i', $rawPath)) {
        return null;
    }

    $relativePath = ltrim($rawPath, "/\\");
    $baseDirs = [
        realpath(__DIR__ . '/../uploads'),
        realpath(__DIR__ . '/uploads'),
    ];

    $candidatePaths = [
        __DIR__ . '/' . $relativePath,
        dirname(__DIR__) . '/' . $relativePath,
    ];

    foreach ($candidatePaths as $candidatePath) {
        $resolvedPath = realpath($candidatePath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            continue;
        }

        foreach ($baseDirs as $baseDir) {
            if ($baseDir === false) {
                continue;
            }
            if ($resolvedPath === $baseDir || strpos($resolvedPath, $baseDir . DIRECTORY_SEPARATOR) === 0) {
                return $resolvedPath;
            }
        }
    }

    return null;
}

if (!isLoggedIn()) {
    sendOcrResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$imagePath = trim((string)($_GET['image'] ?? ''));
if ($imagePath === '') {
    sendOcrResponse(['success' => false, 'error' => 'No image specified'], 400);
}

$fullPath = resolveSafeOcrImagePath($imagePath);
if ($fullPath === null) {
    sendOcrResponse(['success' => false, 'error' => 'Invalid image path'], 400);
}

$detectedMime = function_exists('mime_content_type') ? strtolower((string)mime_content_type($fullPath)) : '';
if ($detectedMime !== '' && strpos($detectedMime, 'image/') !== 0) {
    sendOcrResponse(['success' => false, 'error' => 'Unsupported file type'], 415);
}

$tesseractPath = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
if ($tesseractPath === '') {
    sendOcrResponse(['success' => false, 'error' => 'Tesseract OCR is not installed'], 500);
}

$timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));
$commandParts = [];
if ($timeoutPath !== '') {
    $commandParts[] = escapeshellarg($timeoutPath);
    $commandParts[] = '30';
}
$commandParts[] = escapeshellarg($tesseractPath);
$commandParts[] = escapeshellarg($fullPath);
$commandParts[] = 'stdout';
$command = implode(' ', $commandParts) . ' 2>&1';

$output = [];
exec($command, $output, $status);
if ($status === 0) {
    sendOcrResponse(['success' => true, 'text' => implode("\n", $output)]);
}

sendOcrResponse(['success' => false, 'error' => 'OCR process failed.'], 500);

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request payload']);
    exit;
}

$rawText = trim((string)($body['text'] ?? ''));
$documentType = trim((string)($body['document_type'] ?? 'document'));

if ($rawText === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'OCR text is required']);
    exit;
}

$maxInputChars = 50000;
if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($rawText, 'UTF-8') > $maxInputChars) {
        $rawText = mb_substr($rawText, 0, $maxInputChars, 'UTF-8');
    }
} elseif (strlen($rawText) > $maxInputChars) {
    $rawText = substr($rawText, 0, $maxInputChars);
}

$geminiApiKey = defined('GEMINI_API_KEY') ? trim((string)GEMINI_API_KEY) : '';
if ($geminiApiKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Gemini API key is not configured on the server (set GEMINI_API_KEY).',
    ]);
    exit;
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'cURL extension is required.']);
    exit;
}

$prompt = "You clean OCR output into well-structured Markdown for a barangay {$documentType} document.\n\n"
    . "Rules:\n"
    . "- Preserve original meaning and facts. Do not invent details.\n"
    . "- Correct obvious OCR mistakes only when clearly inferable.\n"
    . "- Keep names, dates, reference numbers, and legal terms intact.\n"
    . "- Produce readable Markdown with headings, lists, and paragraphs where appropriate.\n"
    . "- Return Markdown only (no code fences, no explanations).\n\n"
    . "OCR TEXT:\n"
    . $rawText;

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature' => 0.1,
        'topP' => 0.9,
        'maxOutputTokens' => 4096,
    ],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='
    . rawurlencode($geminiApiKey);

$ch = curl_init($url);
if ($ch === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to initialize Gemini request.']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 45,
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($responseBody === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Gemini request failed: ' . $curlError]);
    exit;
}

$decoded = json_decode($responseBody, true);
if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Invalid Gemini response format.']);
    exit;
}

if ($statusCode >= 400) {
    $errorMessage = (string)($decoded['error']['message'] ?? 'Gemini API error');
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $errorMessage]);
    exit;
}

$parts = $decoded['candidates'][0]['content']['parts'] ?? [];
$markdown = '';
if (is_array($parts)) {
    foreach ($parts as $part) {
        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
            $markdown .= $part['text'];
        }
    }
}

$markdown = trim($markdown);
$markdown = preg_replace('/^```(?:markdown)?\s*/i', '', $markdown);
$markdown = preg_replace('/\s*```$/', '', (string)$markdown);
$markdown = trim((string)$markdown);

if ($markdown === '') {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Gemini returned empty content.']);
    exit;
}

echo json_encode([
    'success' => true,
    'markdown' => $markdown,
    'model' => 'gemini-1.5-flash',
]);

<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/env_loader.php';

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

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Composer autoload not found. Run composer install in admin/.']);
    exit;
}
require_once $autoloadPath;

if (!class_exists(\thiagoalessio\TesseractOCR\TesseractOCR::class)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Composer Tesseract package is not installed.']);
    exit;
}

$resolveTesseractExecutable = static function (): string {
    $envCandidates = [
        trim((string)(getenv('TESSERACT_BINARY') ?: '')),
        trim((string)(getenv('TESSERACT_PATH') ?: '')),
        trim((string)(getenv('TESSERACT_EXECUTABLE') ?: '')),
    ];

    foreach ($envCandidates as $candidate) {
        if ($candidate !== '' && is_executable($candidate)) {
            return $candidate;
        }
    }

    $defaultCandidates = [
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
        '/bin/tesseract',
    ];
    foreach ($defaultCandidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    $resolvedFromPath = trim((string)@shell_exec('command -v tesseract 2>/dev/null'));
    if ($resolvedFromPath !== '' && is_executable($resolvedFromPath)) {
        return $resolvedFromPath;
    }

    return '';
};

$tesseractExecutable = $resolveTesseractExecutable();
if ($tesseractExecutable === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Tesseract OCR binary is not installed on the server. Install tesseract-ocr and tesseract-ocr-eng, or set TESSERACT_BINARY to the executable path.',
    ]);
    exit;
}

$ocrLanguage = trim((string)(getenv('TESSERACT_LANG') ?: 'eng'));
if ($ocrLanguage === '' || !preg_match('/^[A-Za-z_+]+$/', $ocrLanguage)) {
    $ocrLanguage = 'eng';
}

$maxBytes = 12 * 1024 * 1024;
$tmpPath = '';
$cleanupPath = null;

$file = $_FILES['file'] ?? null;
$fileErrorCode = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

if (is_array($file) && $fileErrorCode !== UPLOAD_ERR_NO_FILE) {
    if ($fileErrorCode !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Upload failed with code ' . $fileErrorCode]);
        exit;
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Uploaded file is not available.']);
        exit;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file size. Maximum is 12MB.']);
        exit;
    }
} else {
    $imageUrl = trim((string)($_POST['image_url'] ?? ''));
    if ($imageUrl === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'OCR file or image_url is required.']);
        exit;
    }

    $validatedUrl = filter_var($imageUrl, FILTER_VALIDATE_URL);
    $urlParts = $validatedUrl ? parse_url($validatedUrl) : false;
    $scheme = is_array($urlParts) ? strtolower((string)($urlParts['scheme'] ?? '')) : '';
    $urlHost = is_array($urlParts) ? strtolower((string)($urlParts['host'] ?? '')) : '';
    if (!$validatedUrl || !in_array($scheme, ['http', 'https'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid image_url.']);
        exit;
    }

    $requestHostRaw = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestHost = explode(':', $requestHostRaw)[0] ?? '';
    if ($requestHost !== '' && $urlHost !== '' && $urlHost !== $requestHost) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cross-host image_url is not allowed.']);
        exit;
    }

    if (!function_exists('curl_init')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'cURL extension is required for image_url OCR.']);
        exit;
    }

    $downloadPath = tempnam(sys_get_temp_dir(), 'ocr_');
    if ($downloadPath === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create temporary OCR file.']);
        exit;
    }

    $handle = fopen($downloadPath, 'wb');
    if ($handle === false) {
        @unlink($downloadPath);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to open temporary OCR file.']);
        exit;
    }

    $ch = curl_init($validatedUrl);
    if ($ch === false) {
        fclose($handle);
        @unlink($downloadPath);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to initialize image download request.']);
        exit;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FAILONERROR => false,
        CURLOPT_USERAGENT => 'eFIND Composer OCR',
    ]);

    $downloadOk = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($handle);

    if ($downloadOk === false || $statusCode >= 400) {
        @unlink($downloadPath);
        http_response_code(400);
        $message = $downloadOk === false ? ('Image download failed: ' . $curlError) : ('Image download HTTP ' . $statusCode);
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    clearstatcache(true, $downloadPath);
    $size = is_file($downloadPath) ? (int)filesize($downloadPath) : 0;
    if ($size <= 0 || $size > $maxBytes) {
        @unlink($downloadPath);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Downloaded image is empty or exceeds 12MB limit.']);
        exit;
    }

    $tmpPath = $downloadPath;
    $cleanupPath = $downloadPath;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowedMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/bmp',
    'image/webp',
    'image/tiff',
];
if (!in_array($mime, $allowedMimeTypes, true)) {
    if ($cleanupPath && is_file($cleanupPath)) {
        @unlink($cleanupPath);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported OCR file type.']);
    exit;
}

try {
    $ocr = new \thiagoalessio\TesseractOCR\TesseractOCR($tmpPath);
    $text = trim((string)$ocr->executable($tesseractExecutable)->lang($ocrLanguage)->run());

    if ($text === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'No text could be extracted from the image.']);
    } else {
        echo json_encode([
            'success' => true,
            'text' => $text,
            'confidence' => null,
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Composer OCR failed: ' . $e->getMessage(),
    ]);
} finally {
    if ($cleanupPath && is_file($cleanupPath)) {
        @unlink($cleanupPath);
    }
}

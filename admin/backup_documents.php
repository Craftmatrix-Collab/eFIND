<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/logger.php';

function redirectBackupWithError(string $message): void
{
    $_SESSION['error'] = $message;
    header('Location: dashboard.php');
    exit();
}

function splitStoredBackupPaths($value): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/\|/', $raw);
    if (!is_array($parts)) {
        return [];
    }

    $paths = [];
    foreach ($parts as $part) {
        $path = trim((string)$part);
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    return $paths;
}

function sanitizeBackupPathSegment($value, string $fallback = 'item'): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string)$value));
    if (!is_string($sanitized) || $sanitized === '') {
        return $fallback;
    }
    return $sanitized;
}

function isRemoteBackupPath(string $value): bool
{
    return preg_match('#^https?://#i', trim($value)) === 1;
}

function normalizeBackupPathForLookup(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '' || stripos($trimmed, 'data:') === 0) {
        return '';
    }

    $parsed = parse_url($trimmed);
    $pathOnly = is_array($parsed) && isset($parsed['path']) ? (string)$parsed['path'] : $trimmed;
    $decoded = urldecode($pathOnly);
    $withoutQuery = preg_replace('/[?#].*$/', '', $decoded);

    return trim((string)$withoutQuery);
}

function resolveLocalBackupPath(string $storedPath): ?string
{
    $normalized = normalizeBackupPathForLookup($storedPath);
    if ($normalized === '') {
        return null;
    }

    if ((preg_match('#^[A-Za-z]:[\\\\/]#', $normalized) === 1 || strpos($normalized, '/') === 0)
        && is_file($normalized)
        && is_readable($normalized)
    ) {
        return $normalized;
    }

    $relative = ltrim(str_replace('\\', '/', $normalized), '/');
    if ($relative === '') {
        return null;
    }

    $candidateRoots = [
        __DIR__,
        dirname(__DIR__),
        dirname(__DIR__, 2),
        dirname(__DIR__, 3),
        (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
    ];

    foreach ($candidateRoots as $root) {
        $base = trim((string)$root);
        if ($base === '') {
            continue;
        }

        $candidate = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

if (!(function_exists('isSuperAdmin') && isSuperAdmin())) {
    redirectBackupWithError('Only superadmin can access document backup.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectBackupWithError('Invalid request method for document backup.');
}

if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    redirectBackupWithError('Invalid security token. Please refresh and try again.');
}

if (!class_exists('ZipArchive')) {
    redirectBackupWithError('Backup is unavailable because ZipArchive is not enabled on the server.');
}

if (!($conn instanceof mysqli)) {
    redirectBackupWithError('Backup failed: database connection is unavailable.');
}

$tableSpecs = [
    ['name' => 'executive_orders', 'file_columns' => ['image_path', 'file_path']],
    ['name' => 'resolutions', 'file_columns' => ['image_path']],
    ['name' => 'minutes_of_meeting', 'file_columns' => ['image_path']],
    ['name' => 'document_ocr_content', 'file_columns' => []],
];

$backupPayload = [
    'meta' => [
        'generated_at' => date('c'),
        'generated_by' => [
            'id' => (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0),
            'name' => (string)($_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User'),
            'role' => (string)($_SESSION['role'] ?? ($_SESSION['staff_role'] ?? 'superadmin')),
        ],
        'source' => 'eFIND admin backup',
    ],
    'summary' => [],
    'tables' => [],
    'files' => [
        'local_included' => [],
        'external_references' => [],
        'missing_local_references' => [],
        'skipped_unreadable_files' => [],
    ],
];

$candidateFiles = [];
$knownLocalSource = [];
$usedZipPaths = [];
$externalReferences = [];
$missingLocalReferences = [];

foreach ($tableSpecs as $spec) {
    $tableName = (string)$spec['name'];
    $fileColumns = is_array($spec['file_columns']) ? $spec['file_columns'] : [];

    $escapedTableName = $conn->real_escape_string($tableName);
    $tableCheck = $conn->query("SHOW TABLES LIKE '{$escapedTableName}'");
    if (!$tableCheck) {
        error_log("Document backup table check failed for {$tableName}: " . $conn->error);
        redirectBackupWithError('Backup failed while checking document tables.');
    }

    if ((int)$tableCheck->num_rows === 0) {
        $backupPayload['summary'][$tableName] = 0;
        $backupPayload['tables'][$tableName] = [];
        continue;
    }

    $queryResult = $conn->query("SELECT * FROM {$tableName}");
    if (!$queryResult) {
        error_log("Document backup query failed for {$tableName}: " . $conn->error);
        redirectBackupWithError('Backup failed while reading document data.');
    }

    $rows = [];
    while ($row = $queryResult->fetch_assoc()) {
        $rows[] = $row;

        if (empty($fileColumns)) {
            continue;
        }

        foreach ($fileColumns as $columnName) {
            $storedPaths = splitStoredBackupPaths((string)($row[$columnName] ?? ''));
            if (empty($storedPaths)) {
                continue;
            }

            foreach ($storedPaths as $storedPath) {
                $resolvedPath = resolveLocalBackupPath($storedPath);
                if ($resolvedPath !== null) {
                    $realPath = realpath($resolvedPath);
                    if ($realPath === false) {
                        continue;
                    }

                    if (!isset($knownLocalSource[$realPath])) {
                        $rowId = sanitizeBackupPathSegment((string)($row['id'] ?? 'row'));
                        $basenameSource = normalizeBackupPathForLookup($storedPath);
                        $basename = basename($basenameSource !== '' ? $basenameSource : $realPath);
                        $basename = sanitizeBackupPathSegment($basename, 'document_' . substr(sha1($realPath), 0, 10));
                        $zipRelativePath = 'files/' . sanitizeBackupPathSegment($tableName) . '/' . $rowId . '/' . $basename;
                        $collisionIndex = 1;
                        while (isset($usedZipPaths[$zipRelativePath])) {
                            $zipRelativePath = 'files/' . sanitizeBackupPathSegment($tableName) . '/' . $rowId . '/' . $collisionIndex . '_' . $basename;
                            $collisionIndex++;
                        }

                        $knownLocalSource[$realPath] = true;
                        $usedZipPaths[$zipRelativePath] = true;
                        $candidateFiles[] = [
                            'source_path' => $realPath,
                            'zip_path' => $zipRelativePath,
                            'table' => $tableName,
                            'row_id' => (string)($row['id'] ?? ''),
                            'column' => (string)$columnName,
                            'stored_path' => $storedPath,
                        ];
                    }

                    continue;
                }

                $reference = [
                    'table' => $tableName,
                    'row_id' => (string)($row['id'] ?? ''),
                    'column' => (string)$columnName,
                    'stored_path' => $storedPath,
                ];

                if (isRemoteBackupPath($storedPath)) {
                    $externalReferences[] = $reference;
                } else {
                    $missingLocalReferences[] = $reference;
                }
            }
        }
    }
    $queryResult->free();

    $backupPayload['summary'][$tableName] = count($rows);
    $backupPayload['tables'][$tableName] = $rows;
}

$timestamp = date('Ymd_His');
$zipDownloadName = 'efind_documents_backup_' . $timestamp . '.zip';
$zipTempPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'efind_documents_backup_' . $timestamp . '_' . bin2hex(random_bytes(5)) . '.zip';

$zip = new ZipArchive();
$openStatus = $zip->open($zipTempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openStatus !== true) {
    error_log('Document backup zip open failed with status: ' . $openStatus);
    redirectBackupWithError('Backup failed while creating archive file.');
}

$includedFiles = [];
$skippedFiles = [];

foreach ($candidateFiles as $candidate) {
    $sourcePath = (string)$candidate['source_path'];
    $zipPath = (string)$candidate['zip_path'];

    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        $skippedFiles[] = $candidate + ['reason' => 'File is not readable at backup time.'];
        continue;
    }

    if (!$zip->addFile($sourcePath, $zipPath)) {
        $skippedFiles[] = $candidate + ['reason' => 'Failed to add file into ZIP archive.'];
        continue;
    }

    $includedFiles[] = $candidate;
}

$backupPayload['files']['local_included'] = $includedFiles;
$backupPayload['files']['external_references'] = $externalReferences;
$backupPayload['files']['missing_local_references'] = $missingLocalReferences;
$backupPayload['files']['skipped_unreadable_files'] = $skippedFiles;

$backupJson = json_encode($backupPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($backupJson === false) {
    $zip->close();
    if (is_file($zipTempPath)) {
        unlink($zipTempPath);
    }
    error_log('Document backup JSON encoding failed: ' . json_last_error_msg());
    redirectBackupWithError('Backup failed while packaging metadata.');
}

if (!$zip->addFromString('backup/documents_backup.json', $backupJson)) {
    $zip->close();
    if (is_file($zipTempPath)) {
        unlink($zipTempPath);
    }
    error_log('Document backup failed to write JSON payload into ZIP.');
    redirectBackupWithError('Backup failed while writing archive contents.');
}

if (!$zip->close()) {
    if (is_file($zipTempPath)) {
        unlink($zipTempPath);
    }
    error_log('Document backup failed to close ZIP archive.');
    redirectBackupWithError('Backup failed while finalizing archive.');
}

if (!is_file($zipTempPath) || !is_readable($zipTempPath)) {
    if (is_file($zipTempPath)) {
        unlink($zipTempPath);
    }
    redirectBackupWithError('Backup failed: archive file is unavailable.');
}

$backupDetails = sprintf(
    'Backup generated. EO=%d, Resolutions=%d, Minutes=%d, OCR=%d, Files included=%d, External refs=%d, Missing local refs=%d',
    (int)($backupPayload['summary']['executive_orders'] ?? 0),
    (int)($backupPayload['summary']['resolutions'] ?? 0),
    (int)($backupPayload['summary']['minutes_of_meeting'] ?? 0),
    (int)($backupPayload['summary']['document_ocr_content'] ?? 0),
    count($includedFiles),
    count($externalReferences),
    count($missingLocalReferences)
);

if (function_exists('logActivity')) {
    logActivity('backup', 'Superadmin downloaded document backup archive', $backupDetails);
}

while (ob_get_level() > 0) {
    if (!ob_end_clean()) {
        break;
    }
}

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipDownloadName . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($zipTempPath));

readfile($zipTempPath);
unlink($zipTempPath);
exit();

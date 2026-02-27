<?php
/**
 * Confirm a completed direct-to-MinIO upload and save metadata to the database.
 *
 * POST /admin/confirm_upload.php
 * Body (JSON): {
 *   "doc_type": "resolutions"|"minutes"|"executive_orders",
 *   "object_keys": ["resolutions/2025/01/file_abc123.jpg", ...],
 *   -- resolutions --
 *   "title", "description", "resolution_number", "resolution_date",
 *   "reference_number", "date_issued", "content"
 *   -- minutes --
 *   "title", "session_number", "meeting_date", "reference_number", "content"
 *   -- executive_orders --
 *   "title", "description", "executive_order_number", "executive_order_date",
 *   "status", "reference_number", "date_issued", "content"
 * }
 *
 * Response (JSON): { "success": true, "id": <new record id> }
 */
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/minio_helper.php';
require_once __DIR__ . '/includes/activity_logger.php';
const MAX_MOBILE_UPLOAD_FILES = 8;

header('Content-Type: application/json');

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

// Allow mobile session token as alternative to login
$mobileSession = preg_replace('/[^a-f0-9]/', '', $body['session_id'] ?? '');
$isMobileAuth  = false;
$mobileSessionDocType = '';
if ($mobileSession) {
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_upload_sessions (session_id VARCHAR(64) PRIMARY KEY, doc_type VARCHAR(50) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'waiting', result_id INT DEFAULT NULL, object_keys_json LONGTEXT DEFAULT NULL, image_urls_json LONGTEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $conn->prepare("SELECT session_id, doc_type FROM mobile_upload_sessions WHERE session_id = ? AND status = 'waiting'");
    $st->bind_param('s', $mobileSession);
    $st->execute();
    $sessionRow = $st->get_result()->fetch_assoc();
    $isMobileAuth = $sessionRow !== null;
    $mobileSessionDocType = (string)($sessionRow['doc_type'] ?? '');
    $st->close();
}

if (!isLoggedIn() && !$isMobileAuth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$docType = $body['doc_type'] ?? '';

$allowedTypes = ['resolutions', 'minutes', 'executive_orders'];
if (!in_array($docType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid doc_type']);
    exit;
}

$uploadedBy  = $_SESSION['username'] ?? $_SESSION['staff_username'] ?? $_SESSION['admin_username'] ?? ($isMobileAuth ? 'mobile' : 'admin');
$shouldDeferToDesktop = $isMobileAuth && !empty($body['defer_to_desktop']);
$datePosted  = date('Y-m-d H:i:s');

$newId = null;

/**
 * Ensure image path columns can hold multi-image URL payloads.
 */
function ensureImagePathColumns(mysqli $conn, string $docType): void
{
    $targetsByDocType = [
        'resolutions' => [['resolutions', 'image_path']],
        'minutes' => [['minutes_of_meeting', 'image_path']],
        'executive_orders' => [['executive_orders', 'image_path'], ['executive_orders', 'file_path']],
    ];

    if (!isset($targetsByDocType[$docType])) {
        return;
    }

    foreach ($targetsByDocType[$docType] as [$table, $column]) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if (!$columnCheck) {
            throw new Exception("Failed to inspect {$table}.{$column}: " . $conn->error);
        }

        $columnMeta = $columnCheck->fetch_assoc();
        if (!$columnMeta) {
            throw new Exception("Column {$table}.{$column} does not exist.");
        }

        $type = strtolower((string)($columnMeta['Type'] ?? ''));
        $shouldWiden = false;
        if (preg_match('/^varchar\((\d+)\)$/', $type, $matches)) {
            $shouldWiden = (int)$matches[1] < 2048;
        } elseif ($type === 'tinytext') {
            $shouldWiden = true;
        }

        if (!$shouldWiden) {
            continue;
        }

        $nullSql = strtoupper((string)($columnMeta['Null'] ?? 'YES')) === 'NO' ? 'NOT NULL' : 'NULL';
        $alterSql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` TEXT {$nullSql}";
        if (!$conn->query($alterSql)) {
            throw new Exception("Failed to widen {$table}.{$column}: " . $conn->error);
        }
    }
}

/**
 * Ensure mobile session payload columns exist for deferred desktop workflows.
 */
function ensureMobileSessionPayloadColumns(mysqli $conn): void
{
    $requiredColumns = [
        'object_keys_json' => "ALTER TABLE mobile_upload_sessions ADD COLUMN object_keys_json LONGTEXT DEFAULT NULL AFTER result_id",
        'image_urls_json'  => "ALTER TABLE mobile_upload_sessions ADD COLUMN image_urls_json LONGTEXT DEFAULT NULL AFTER object_keys_json",
    ];

    foreach ($requiredColumns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM mobile_upload_sessions LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query($alterSql)) {
                throw new Exception('Failed to update mobile session schema: ' . $conn->error);
            }
        }
    }
}

/**
 * Resolve a valid users.id for activity_logs.user_id (nullable when unavailable).
 */
function resolveActivityLogUserId(mysqli $conn): ?int
{
    $candidates = [
        $_SESSION['user_id'] ?? null,
        $_SESSION['staff_id'] ?? null,
        $_SESSION['admin_id'] ?? null,
    ];

    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        error_log('confirm_upload user lookup prepare failed: ' . $conn->error);
        return null;
    }

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '' || !is_numeric($candidate)) {
            continue;
        }

        $id = (int)$candidate;
        if ($id <= 0) {
            continue;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return $id;
        }
    }

    $stmt->close();
    return null;
}

/**
 * Normalize optional date values to YYYY-MM-DD or null.
 */
function normalizeOptionalDate($value, string $fieldName): ?string
{
    $date = trim((string)($value ?? ''));
    if ($date === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception("Invalid {$fieldName}. Expected YYYY-MM-DD.");
    }

    return $date;
}

/**
 * Keep only valid image object keys for the selected document type.
 */
function sanitizeObjectKeys($rawObjectKeys, string $docType): array
{
    if (!is_array($rawObjectKeys)) {
        return [];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $cleanKeys = [];
    foreach ($rawObjectKeys as $key) {
        if (!is_string($key)) {
            continue;
        }

        $normalizedKey = ltrim(trim($key), '/');
        if ($normalizedKey === '' || strpos($normalizedKey, $docType . '/') !== 0) {
            continue;
        }

        $ext = strtolower(pathinfo($normalizedKey, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            continue;
        }

        $cleanKeys[$normalizedKey] = true;
    }

    return array_keys($cleanKeys);
}

try {
    if ($isMobileAuth && $mobileSessionDocType !== '' && $mobileSessionDocType !== $docType) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Session document type mismatch']);
        exit;
    }

    $objectKeys = sanitizeObjectKeys($body['object_keys'] ?? [], $docType);
    if (count($objectKeys) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid uploaded images were provided']);
        exit;
    }
    if ($isMobileAuth && count($objectKeys) > MAX_MOBILE_UPLOAD_FILES) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Too many images in one upload. Maximum is ' . MAX_MOBILE_UPLOAD_FILES]);
        exit;
    }

    // Build the pipe-separated image_path from public URLs
    $minio = new MinioS3Client();
    $imagePaths = [];
    foreach ($objectKeys as $key) {
        $imagePaths[] = $minio->getPublicUrl($key);
    }
    $imagePath = implode('|', $imagePaths);

    if ($shouldDeferToDesktop) {
        ensureMobileSessionPayloadColumns($conn);

        $objectKeysJson = json_encode($objectKeys, JSON_UNESCAPED_SLASHES);
        $imageUrlsJson = json_encode($imagePaths, JSON_UNESCAPED_SLASHES);
        if ($objectKeysJson === false || $imageUrlsJson === false) {
            throw new Exception('Failed to encode uploaded image payload');
        }

        $stDeferred = $conn->prepare("UPDATE mobile_upload_sessions SET status='complete', result_id=NULL, object_keys_json=?, image_urls_json=? WHERE session_id=?");
        if (!$stDeferred) {
            throw new Exception('Failed to prepare mobile session update: ' . $conn->error);
        }
        $stDeferred->bind_param('sss', $objectKeysJson, $imageUrlsJson, $mobileSession);
        $stDeferred->execute();
        if ($stDeferred->affected_rows < 1) {
            $stDeferred->close();
            throw new Exception('Mobile session is no longer active');
        }
        $stDeferred->close();

        echo json_encode([
            'success' => true,
            'id' => null,
            'doc_type' => $docType,
            'deferred_to_desktop' => true,
            'object_keys' => $objectKeys,
            'image_urls' => $imagePaths,
        ]);
        exit;
    }

    ensureImagePathColumns($conn, $docType);

    if ($docType === 'resolutions') {
        $title            = $body['title']             ?? '';
        $description      = $body['description']       ?? '';
        $resolutionNumber = $body['resolution_number'] ?? '';
        $resolutionDate   = normalizeOptionalDate($body['resolution_date'] ?? null, 'resolution_date');
        $referenceNumber  = $body['reference_number']  ?? '';
        $dateIssued       = normalizeOptionalDate($body['date_issued'] ?? null, 'date_issued');
        $content          = $body['content']           ?? '';

        $stmt = $conn->prepare(
            "INSERT INTO resolutions
             (title, description, resolution_number, date_posted, resolution_date,
              content, image_path, reference_number, date_issued, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssssssss',
            $title, $description, $resolutionNumber, $datePosted, $resolutionDate,
            $content, $imagePath, $referenceNumber, $dateIssued, $uploadedBy
        );
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();

    } elseif ($docType === 'minutes') {
        $title           = $body['title']           ?? '';
        $sessionNumber   = $body['session_number']  ?? '';
        $meetingDate     = normalizeOptionalDate($body['meeting_date'] ?? null, 'meeting_date');
        $referenceNumber = $body['reference_number'] ?? '';
        $content         = $body['content']         ?? '';

        $stmt = $conn->prepare(
            "INSERT INTO minutes_of_meeting
             (title, session_number, date_posted, meeting_date,
              content, image_path, reference_number, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssssss',
            $title, $sessionNumber, $datePosted, $meetingDate,
            $content, $imagePath, $referenceNumber, $uploadedBy
        );
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();

    } elseif ($docType === 'executive_orders') {
        $title            = $body['title']             ?? '';
        $description      = $body['description']       ?? '';
        $executive_orderNumber  = $body['executive_order_number']  ?? '';
        $executive_orderDate    = normalizeOptionalDate($body['executive_order_date'] ?? null, 'executive_order_date');
        $status           = trim((string)($body['status'] ?? 'Active'));
        if ($status === '') {
            $status = 'Active';
        }
        $referenceNumber  = $body['reference_number']  ?? '';
        $dateIssued       = normalizeOptionalDate($body['date_issued'] ?? null, 'date_issued');
        $content          = $body['content']           ?? '';
        $filePath         = $imagePath; // executive_orders also stores as file_path

        $stmt = $conn->prepare(
            "INSERT INTO executive_orders
             (title, description, executive_order_number, date_posted, executive_order_date,
              status, content, image_path, reference_number, date_issued,
              file_path, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssssssssss',
            $title, $description, $executive_orderNumber, $datePosted, $executive_orderDate,
            $status, $content, $imagePath, $referenceNumber, $dateIssued,
            $filePath, $uploadedBy
        );
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();
    }

    // Log the activity
    if ($newId) {
        $docLabel = ['resolutions' => 'Resolution', 'minutes' => 'Minutes of Meeting', 'executive_orders' => 'Executive Order'][$docType];
        $userId   = resolveActivityLogUserId($conn);
        $userRole = isAdmin() ? 'admin' : 'staff';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
        $logStmt  = $conn->prepare(
            "INSERT INTO activity_logs
             (user_id, user_name, user_role, action, description, document_type, document_id, ip_address)
             VALUES (?, ?, ?, 'upload', ?, ?, ?, ?)"
        );
        $desc = "Uploaded $docLabel via mobile direct-upload";
        $logStmt->bind_param('issssis', $userId, $uploadedBy, $userRole, $desc, $docType, $newId, $ip);
        $logStmt->execute();
        $logStmt->close();
    }

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'doc_type' => $docType,
        'deferred_to_desktop' => false,
        'object_keys' => $objectKeys,
        'image_urls' => $imagePaths,
    ]);

    // Notify desktop that mobile upload is complete
    if ($mobileSession && $isMobileAuth) {
        $st2 = $conn->prepare("UPDATE mobile_upload_sessions SET status='complete', result_id=? WHERE session_id=?");
        $st2->bind_param('is', $newId, $mobileSession);
        $st2->execute();
    }


} catch (Exception $e) {
    error_log('confirm_upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

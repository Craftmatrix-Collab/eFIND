<?php
/**
 * Confirm a completed direct-to-MinIO upload and save metadata to the database.
 *
 * POST /admin/confirm_upload.php
 * Body (JSON): {
 *   "doc_type": "resolutions"|"minutes"|"ordinances",
 *   "object_keys": ["resolutions/2025/01/file_abc123.jpg", ...],
 *   -- resolutions --
 *   "title", "description", "resolution_number", "resolution_date",
 *   "reference_number", "date_issued", "content"
 *   -- minutes --
 *   "title", "session_number", "meeting_date", "reference_number", "content"
 *   -- ordinances --
 *   "title", "description", "ordinance_number", "ordinance_date",
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

header('Content-Type: application/json');

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

$body    = json_decode(file_get_contents('php://input'), true);
$docType = $body['doc_type'] ?? '';

$allowedTypes = ['resolutions', 'minutes', 'ordinances'];
if (!in_array($docType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid doc_type']);
    exit;
}

$objectKeys  = $body['object_keys'] ?? [];
$uploadedBy  = $_SESSION['username'] ?? $_SESSION['staff_username'] ?? 'admin';
$datePosted  = date('Y-m-d H:i:s');

// Build the comma-separated image_path from public URLs
$minio      = new MinioS3Client();
$imagePaths = [];
foreach ($objectKeys as $key) {
    $imagePaths[] = $minio->getPublicUrl($key);
}
$imagePath = implode(',', $imagePaths);

$newId = null;

try {
    if ($docType === 'resolutions') {
        $title            = $body['title']             ?? '';
        $description      = $body['description']       ?? '';
        $resolutionNumber = $body['resolution_number'] ?? '';
        $resolutionDate   = $body['resolution_date']   ?? null;
        $referenceNumber  = $body['reference_number']  ?? '';
        $dateIssued       = $body['date_issued']       ?? null;
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
        $meetingDate     = $body['meeting_date']    ?? null;
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

    } elseif ($docType === 'ordinances') {
        $title            = $body['title']             ?? '';
        $description      = $body['description']       ?? '';
        $ordinanceNumber  = $body['ordinance_number']  ?? '';
        $ordinanceDate    = $body['ordinance_date']    ?? null;
        $status           = $body['status']            ?? 'Active';
        $referenceNumber  = $body['reference_number']  ?? '';
        $dateIssued       = $body['date_issued']       ?? null;
        $content          = $body['content']           ?? '';
        $filePath         = $imagePath; // ordinances also stores as file_path

        $stmt = $conn->prepare(
            "INSERT INTO ordinances
             (title, description, ordinance_number, date_posted, ordinance_date,
              status, content, image_path, reference_number, date_issued,
              file_path, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssssssssss',
            $title, $description, $ordinanceNumber, $datePosted, $ordinanceDate,
            $status, $content, $imagePath, $referenceNumber, $dateIssued,
            $filePath, $uploadedBy
        );
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();
    }

    // Log the activity
    if ($newId) {
        $docLabel = ['resolutions' => 'Resolution', 'minutes' => 'Minutes of Meeting', 'ordinances' => 'Ordinance'][$docType];
        $userId   = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? 0;
        $userRole = isAdmin() ? 'admin' : 'staff';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
        $logStmt  = $conn->prepare(
            "INSERT INTO activity_logs
             (user_id, user_name, user_role, action, description, document_type, document_id, ip_address)
             VALUES (?, ?, ?, 'upload', ?, ?, ?, ?)"
        );
        $desc = "Uploaded $docLabel via mobile direct-upload";
        $logStmt->bind_param('issssss', $userId, $uploadedBy, $userRole, $desc, $docType, $newId, $ip);
        $logStmt->execute();
        $logStmt->close();
    }

    echo json_encode(['success' => true, 'id' => $newId, 'doc_type' => $docType]);

} catch (Exception $e) {
    error_log('confirm_upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

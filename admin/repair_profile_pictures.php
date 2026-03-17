<?php
/**
 * One-time profile picture repair utility.
 *
 * Usage:
 *   php repair_profile_pictures.php                 # dry run
 *   php repair_profile_pictures.php --apply         # apply safe recoveries only
 *   php repair_profile_pictures.php --apply --set-default-on-missing
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/minio_helper.php';
require_once __DIR__ . '/includes/profile_picture_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only run from CLI.\n";
    exit(1);
}

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$setDefaultOnMissing = in_array('--set-default-on-missing', $args, true);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($showHelp) {
    echo "Usage:\n";
    echo "  php repair_profile_pictures.php\n";
    echo "  php repair_profile_pictures.php --apply\n";
    echo "  php repair_profile_pictures.php --apply --set-default-on-missing\n";
    exit(0);
}

function repairHttpUrlExists(string $url): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 400) {
        return true;
    }

    if ($code === 405 || $code === 403) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_RANGE, '0-0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        $fallbackCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $fallbackCode >= 200 && $fallbackCode < 400;
    }

    return false;
}

function repairFindFirstExistingLocalCandidate(string $rawPath): ?string
{
    foreach (efind_profile_picture_local_candidates($rawPath) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function repairDeriveObjectNameFromUploads(string $rawPath): ?string
{
    $normalized = ltrim(str_replace('\\', '/', trim($rawPath)), '/');
    if ($normalized === '') {
        return null;
    }

    if (preg_match('#^uploads/#i', $normalized)) {
        $objectName = preg_replace('#^uploads/#i', '', $normalized);
        if (is_string($objectName) && preg_match('#^profiles/#i', $objectName)) {
            return $objectName;
        }
    }

    if (preg_match('#^profiles/#i', $normalized)) {
        return $normalized;
    }

    return null;
}

function repairIsRealMinioUploadResult(array $uploadResult): bool
{
    if (empty($uploadResult['success'])) {
        return false;
    }
    $url = trim((string)($uploadResult['url'] ?? ''));
    return preg_match('#^https?://#i', $url) === 1;
}

function repairCheckMinioHealth(MinioS3Client $minioClient): bool
{
    $tempFile = tempnam(sys_get_temp_dir(), 'efind-avatar-health-');
    if ($tempFile === false) {
        return false;
    }

    $ok = @copy(__DIR__ . '/images/profile.jpg', $tempFile);
    if (!$ok) {
        @unlink($tempFile);
        return false;
    }

    $objectName = 'profiles/diagnostic/health_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $result = $minioClient->uploadFile($tempFile, $objectName, 'image/jpeg');
    @unlink($tempFile);

    if (!repairIsRealMinioUploadResult($result)) {
        $fallbackUrl = trim((string)($result['url'] ?? ''));
        if ($fallbackUrl !== '' && strpos($fallbackUrl, 'uploads/') === 0) {
            @unlink(__DIR__ . '/' . ltrim($fallbackUrl, '/'));
        }
        return false;
    }

    $minioClient->deleteFile($objectName);
    return true;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$minioClient = new MinioS3Client();
$minioHealthy = repairCheckMinioHealth($minioClient);

echo "Mode: " . ($apply ? 'APPLY' : 'DRY_RUN') . "\n";
echo "Set default on missing: " . ($setDefaultOnMissing ? 'YES' : 'NO') . "\n";
echo "MinIO write health: " . ($minioHealthy ? 'HEALTHY' : 'UNHEALTHY (fallback/failure)') . "\n\n";

$query = "SELECT id, username, profile_picture FROM users WHERE profile_picture IS NOT NULL AND TRIM(profile_picture) <> '' ORDER BY id ASC";
$result = $conn->query($query);
if (!$result) {
    fwrite(STDERR, "Failed to query users: " . $conn->error . "\n");
    exit(1);
}

$updateStmt = $conn->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?");
$clearStmt = $conn->prepare("UPDATE users SET profile_picture = NULL, updated_at = NOW() WHERE id = ?");
if (!$updateStmt || !$clearStmt) {
    fwrite(STDERR, "Failed to prepare update statements.\n");
    exit(1);
}

$stats = [
    'checked' => 0,
    'changed' => 0,
    'migrated_to_minio' => 0,
    'recovered_from_minio' => 0,
    'reset_to_default' => 0,
    'already_ok' => 0,
    'unresolved' => 0,
];

while ($row = $result->fetch_assoc()) {
    $stats['checked']++;
    $id = (int)($row['id'] ?? 0);
    $username = (string)($row['username'] ?? '');
    $currentPath = trim((string)($row['profile_picture'] ?? ''));
    $normalized = ltrim(str_replace('\\', '/', $currentPath), '/');

    $newPath = null; // null => no change; '' => clear field
    $reason = '';

    if ($currentPath === '') {
        $stats['already_ok']++;
        continue;
    }

    if (preg_match('#^https?://#i', $currentPath)) {
        if (repairHttpUrlExists($currentPath)) {
            $stats['already_ok']++;
            continue;
        }

        if ($setDefaultOnMissing) {
            $newPath = '';
            $reason = 'Remote avatar URL is unreachable; reset to default.';
        } else {
            $stats['unresolved']++;
            echo "[UNRESOLVED] #{$id} {$username}: unreachable remote URL {$currentPath}\n";
            continue;
        }
    } elseif (preg_match('#^uploads/#i', $normalized) || preg_match('#^profiles/#i', $normalized)) {
        $localFile = repairFindFirstExistingLocalCandidate($normalized);
        $objectName = repairDeriveObjectNameFromUploads($normalized);

        if ($localFile !== null) {
            if ($minioHealthy && $objectName !== null) {
                $contentType = MinioS3Client::getMimeType($localFile);
                $uploadResult = $minioClient->uploadFile($localFile, $objectName, $contentType);
                if (repairIsRealMinioUploadResult($uploadResult)) {
                    $newPath = (string)$uploadResult['url'];
                    $reason = 'Migrated local avatar to MinIO.';
                    $stats['migrated_to_minio']++;
                } else {
                    $stats['already_ok']++;
                    continue;
                }
            } else {
                $stats['already_ok']++;
                continue;
            }
        } else {
            if ($objectName !== null) {
                $publicUrl = $minioClient->getPublicUrl($objectName);
                if (repairHttpUrlExists($publicUrl)) {
                    $newPath = $publicUrl;
                    $reason = 'Recovered avatar from existing MinIO object.';
                    $stats['recovered_from_minio']++;
                } elseif ($setDefaultOnMissing) {
                    $newPath = '';
                    $reason = 'Missing local avatar and MinIO object not found; reset to default.';
                    $stats['reset_to_default']++;
                } else {
                    $stats['unresolved']++;
                    echo "[UNRESOLVED] #{$id} {$username}: missing local avatar ({$currentPath}) and no MinIO object.\n";
                    continue;
                }
            } elseif ($setDefaultOnMissing) {
                $newPath = '';
                $reason = 'Invalid avatar path; reset to default.';
                $stats['reset_to_default']++;
            } else {
                $stats['unresolved']++;
                echo "[UNRESOLVED] #{$id} {$username}: invalid avatar path {$currentPath}\n";
                continue;
            }
        }
    } else {
        $localFile = repairFindFirstExistingLocalCandidate($normalized);
        if ($localFile !== null) {
            $stats['already_ok']++;
            continue;
        }

        if ($setDefaultOnMissing) {
            $newPath = '';
            $reason = 'Unrecognized avatar path with no local file; reset to default.';
            $stats['reset_to_default']++;
        } else {
            $stats['unresolved']++;
            echo "[UNRESOLVED] #{$id} {$username}: unrecognized avatar path {$currentPath}\n";
            continue;
        }
    }

    if ($newPath === null || trim((string)$newPath) === $currentPath) {
        $stats['already_ok']++;
        continue;
    }

    echo "[" . ($apply ? 'APPLY' : 'DRY') . "] #{$id} {$username}: {$reason}\n";
    echo "  from: {$currentPath}\n";
    echo "  to:   " . ($newPath === '' ? '(NULL/default)' : $newPath) . "\n";

    if ($apply) {
        if ($newPath === '') {
            $clearStmt->bind_param('i', $id);
            if (!$clearStmt->execute()) {
                echo "  !! update failed: " . $clearStmt->error . "\n";
                continue;
            }
        } else {
            $updateStmt->bind_param('si', $newPath, $id);
            if (!$updateStmt->execute()) {
                echo "  !! update failed: " . $updateStmt->error . "\n";
                continue;
            }
        }
    }

    $stats['changed']++;
}

$updateStmt->close();
$clearStmt->close();

echo "\nSummary\n";
echo "  checked: " . $stats['checked'] . "\n";
echo "  changed: " . $stats['changed'] . "\n";
echo "  migrated_to_minio: " . $stats['migrated_to_minio'] . "\n";
echo "  recovered_from_minio: " . $stats['recovered_from_minio'] . "\n";
echo "  reset_to_default: " . $stats['reset_to_default'] . "\n";
echo "  already_ok: " . $stats['already_ok'] . "\n";
echo "  unresolved: " . $stats['unresolved'] . "\n";

exit(0);

<?php
/**
 * Resolution Recovery Script
 * Reconstructs deleted resolution DB records from surviving MinIO files.
 * 
 * How it works:
 *   1. Lists all files in MinIO under resolutions/ prefix
 *   2. Groups them by upload timestamp (same-second = same resolution, multi-page)
 *   3. PREVIEW: shows what will be inserted before doing anything
 *   4. RECOVER: inserts placeholder DB records with the correct image_path URLs
 *   5. Admin then edits each record and uses OCR auto-fill to restore metadata
 */

error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/minio_helper.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// ─── MinIO: List all objects under resolutions/ ─────────────────────────────

function listMinioResolutionFiles() {
    $endpoint  = MINIO_ENDPOINT;
    $accessKey = MINIO_ACCESS_KEY;
    $secretKey = MINIO_SECRET_KEY;
    $bucket    = MINIO_BUCKET;

    $allKeys   = [];
    $marker    = '';

    // Paginate through results (MinIO returns max 1000 per request)
    do {
        $date          = gmdate('D, d M Y H:i:s T');
        $resource      = "/$bucket";
        $stringToSign  = "GET\n\n\n$date\n$resource";
        $sig           = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

        $qs = http_build_query(array_filter([
            'prefix'   => 'resolutions/',
            'max-keys' => 1000,
            'marker'   => $marker ?: null,
        ]));

        $ch = curl_init("https://{$endpoint}/{$bucket}?{$qs}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                "Host: $endpoint",
                "Date: $date",
                "Authorization: AWS $accessKey:$sig",
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['error' => "MinIO listing failed (HTTP $httpCode). Response: " . substr($response, 0, 300)];
        }

        $xml = @simplexml_load_string($response);
        if (!$xml) {
            return ['error' => 'Failed to parse MinIO XML response.'];
        }

        foreach ($xml->Contents as $item) {
            $allKeys[] = (string)$item->Key;
        }

        $isTruncated = strtolower((string)$xml->IsTruncated) === 'true';
        $marker      = $isTruncated ? end($allKeys) : '';

    } while ($isTruncated);

    return $allKeys;
}

// ─── Group files into per-resolution batches ─────────────────────────────────

function groupIntoResolutions(array $keys) {
    $baseUrl = 'https://' . MINIO_ENDPOINT . '/' . MINIO_BUCKET;

    // Parse filenames: uniqid_timestamp_index.ext
    $parsed = [];
    foreach ($keys as $key) {
        $filename = basename($key);
        if (preg_match('/^[a-f0-9]+_(\d+)_(\d+)\.(jpg|jpeg|png|gif|bmp|PNG|docx)$/i', $filename, $m)) {
            $parsed[] = [
                'key'       => $key,
                'url'       => "$baseUrl/$key",
                'timestamp' => (int) $m[1],
                'index'     => (int) $m[2],
            ];
        }
    }

    // Sort: oldest first, then by page-index
    usort($parsed, function ($a, $b) {
        return $a['timestamp'] !== $b['timestamp']
            ? $a['timestamp'] - $b['timestamp']
            : $a['index']     - $b['index'];
    });

    // Build groups: each _0 file starts a new resolution;
    // _1, _2 … attach to the nearest _0 within 10 seconds
    $groups = [];

    foreach ($parsed as $file) {
        if ($file['index'] === 0) {
            $groups[] = [
                'files'     => [$file],
                'base_ts'   => $file['timestamp'],
                'date'      => date('Y-m-d',          $file['timestamp']),
                'datetime'  => date('Y-m-d H:i:s',    $file['timestamp']),
            ];
        } else {
            // Walk backwards to find closest group whose _0 is within 10 s
            $attached = false;
            for ($i = count($groups) - 1; $i >= 0; $i--) {
                $gap = $file['timestamp'] - $groups[$i]['base_ts'];
                if ($gap >= 0 && $gap <= 10) {
                    $groups[$i]['files'][] = $file;
                    $attached = true;
                    break;
                }
            }
            if (!$attached) {
                // Orphan page — treat as its own record
                $groups[] = [
                    'files'     => [$file],
                    'base_ts'   => $file['timestamp'],
                    'date'      => date('Y-m-d',       $file['timestamp']),
                    'datetime'  => date('Y-m-d H:i:s', $file['timestamp']),
                ];
            }
        }
    }

    // Compute joined image_path for each group
    foreach ($groups as &$g) {
        $urls           = array_column($g['files'], 'url');
        $g['image_path']   = implode('|', $urls);
        $g['file_count']   = count($g['files']);
        $g['path_length']  = strlen($g['image_path']);
        $g['path_warning'] = $g['path_length'] > 255;
    }
    unset($g);

    return $groups;
}

// ─── Fetch image_path values already in DB (to skip duplicates) ──────────────

function getExistingMinioUrls($conn) {
    $existing = [];
    $res = $conn->query("SELECT image_path FROM resolutions WHERE image_path != '' AND image_path IS NOT NULL");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            foreach (explode('|', $row['image_path']) as $url) {
                $existing[trim($url)] = true;
            }
        }
    }
    return $existing;
}

// ─── Insert one recovered resolution ─────────────────────────────────────────

function insertRecoveredResolution($conn, $group, $seqNum) {
    $title            = '[Recovered] Resolution #' . $seqNum . ' — edit me';
    $description      = '';
    $resolution_number = 'PENDING-' . $seqNum;
    $date_posted      = $group['date'];
    $resolution_date  = $group['datetime'];
    $content          = '';
    $image_path       = $group['image_path'];
    $reference_number = 'REC' . date('Ymd', $group['base_ts']) . sprintf('%03d', $seqNum);
    $date_issued      = $group['date'];
    $uploaded_by      = 'recovery_script';

    // Truncate image_path if it would exceed column size (defensive, should not happen with ≤2 files)
    if (strlen($image_path) > 255) {
        $first_url  = explode('|', $image_path)[0];
        $image_path = substr($first_url, 0, 255);
    }

    $stmt = $conn->prepare(
        "INSERT INTO resolutions
            (title, description, resolution_number, date_posted, resolution_date,
             content, image_path, reference_number, date_issued, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return 'Prepare failed: ' . $conn->error;
    }
    $stmt->bind_param(
        'ssssssssss',
        $title, $description, $resolution_number,
        $date_posted, $resolution_date, $content,
        $image_path, $reference_number, $date_issued, $uploaded_by
    );
    if ($stmt->execute()) {
        $stmt->close();
        return true;
    }
    $err = $stmt->error;
    $stmt->close();
    return 'Execute failed: ' . $err;
}

// ─── Main logic ──────────────────────────────────────────────────────────────

$action  = $_POST['action'] ?? 'preview';
$results = null;
$groups  = null;
$error   = null;

// Always load the groups first
$keys = listMinioResolutionFiles();
if (isset($keys['error'])) {
    $error = $keys['error'];
} else {
    $groups        = groupIntoResolutions($keys);
    $existingUrls  = getExistingMinioUrls($conn);

    // Mark each group as "already recovered" or "new"
    foreach ($groups as &$g) {
        $g['already_exists'] = false;
        foreach ($g['files'] as $f) {
            if (isset($existingUrls[$f['url']])) {
                $g['already_exists'] = true;
                break;
            }
        }
    }
    unset($g);

    $newGroups  = array_filter($groups, fn($g) => !$g['already_exists']);
    $skipGroups = array_filter($groups, fn($g) =>  $g['already_exists']);
}

// Execute recovery
if ($action === 'recover' && !$error) {
    $inserted = 0;
    $failed   = 0;
    $errors   = [];
    $seqNum   = 1;

    foreach ($newGroups as $g) {
        $res = insertRecoveredResolution($conn, $g, $seqNum);
        if ($res === true) {
            $inserted++;
        } else {
            $failed++;
            $errors[] = "Group ts={$g['base_ts']}: $res";
        }
        $seqNum++;
    }

    $results = compact('inserted', 'failed', 'errors');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Resolution Recovery Tool</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
  body { background: #f8f9fa; }
  .badge-new   { background: #198754; }
  .badge-skip  { background: #6c757d; }
  .badge-warn  { background: #ffc107; color:#000; }
  .file-list   { font-size: .78rem; font-family: monospace; }
  .url-cell    { max-width: 420px; word-break: break-all; font-size:.75rem; }
</style>
</head>
<body>
<div class="container py-4">

  <h3 class="mb-1">🔧 Resolution Recovery Tool</h3>
  <p class="text-muted mb-4">
    Scans MinIO for orphaned resolution image files and re-inserts placeholder database records.<br>
    After recovery, open each record in the admin panel and use the <strong>OCR auto-fill</strong> button to restore the title, resolution number, and dates from the document image.
  </p>

  <?php if ($error): ?>
    <div class="alert alert-danger"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($results): ?>
    <div class="alert alert-<?= $results['failed'] === 0 ? 'success' : 'warning' ?>">
      <strong>Recovery complete!</strong><br>
      ✅ <?= $results['inserted'] ?> resolution records inserted.<br>
      <?php if ($results['failed']): ?>
        ❌ <?= $results['failed'] ?> failed.<br>
        <ul class="mb-0 mt-1">
          <?php foreach ($results['errors'] as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <a href="resolutions.php" class="btn btn-primary me-2">Go to Resolutions</a>
    <a href="recovery_resolutions.php" class="btn btn-outline-secondary">Refresh Preview</a>
    <hr>
  <?php endif; ?>

  <?php if (!$error && $groups): ?>

    <?php
      $totalFiles = count($keys);
      $totalGroups = count($groups);
      $newCount  = count($newGroups);
      $skipCount = count($skipGroups);
    ?>

    <div class="row g-3 mb-4">
      <div class="col-auto">
        <div class="card text-center px-4 py-3">
          <div class="fs-2 fw-bold text-primary"><?= $totalFiles ?></div>
          <div class="text-muted small">Files in MinIO</div>
        </div>
      </div>
      <div class="col-auto">
        <div class="card text-center px-4 py-3">
          <div class="fs-2 fw-bold text-success"><?= $totalGroups ?></div>
          <div class="text-muted small">Resolutions found</div>
        </div>
      </div>
      <div class="col-auto">
        <div class="card text-center px-4 py-3">
          <div class="fs-2 fw-bold text-warning"><?= $newCount ?></div>
          <div class="text-muted small">To recover</div>
        </div>
      </div>
      <div class="col-auto">
        <div class="card text-center px-4 py-3">
          <div class="fs-2 fw-bold text-secondary"><?= $skipCount ?></div>
          <div class="text-muted small">Already in DB</div>
        </div>
      </div>
    </div>

    <?php if ($newCount > 0 && !$results): ?>
      <form method="POST" onsubmit="return confirm('Insert <?= $newCount ?> resolution record(s) into the database?')">
        <input type="hidden" name="action" value="recover">
        <button type="submit" class="btn btn-success btn-lg mb-4">
          ▶ Recover <?= $newCount ?> Resolution<?= $newCount !== 1 ? 's' : '' ?>
        </button>
      </form>
    <?php elseif ($newCount === 0): ?>
      <div class="alert alert-info">All MinIO files are already linked to database records. Nothing to recover.</div>
    <?php endif; ?>

    <h5 class="mt-2">Preview of Files Found in MinIO</h5>
    <p class="text-muted small">
      <span class="badge badge-new">NEW</span> = will be recovered &nbsp;
      <span class="badge badge-skip">SKIP</span> = already in database &nbsp;
      <span class="badge badge-warn">⚠ LONG PATH</span> = image_path truncated to first file
    </p>

    <div class="table-responsive">
      <table class="table table-sm table-bordered table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Status</th>
            <th>Upload Date/Time</th>
            <th>Pages</th>
            <th>image_path (stored URL)</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $seq = 1;
          foreach ($groups as $i => $g):
            $statusBadge = $g['already_exists']
              ? '<span class="badge badge-skip">SKIP</span>'
              : '<span class="badge badge-new">NEW</span>';
            $warnBadge = $g['path_warning'] && !$g['already_exists']
              ? ' <span class="badge badge-warn">⚠ LONG PATH</span>'
              : '';
            $rowClass = $g['already_exists'] ? 'table-secondary' : '';
          ?>
          <tr class="<?= $rowClass ?>">
            <td><?= $seq++ ?></td>
            <td><?= $statusBadge . $warnBadge ?></td>
            <td class="text-nowrap"><?= htmlspecialchars($g['datetime']) ?></td>
            <td class="text-center"><?= $g['file_count'] ?></td>
            <td class="url-cell">
              <?php foreach ($g['files'] as $f): ?>
                <div class="file-list">
                  <a href="<?= htmlspecialchars($f['url']) ?>" target="_blank">
                    <?= htmlspecialchars(basename($f['key'])) ?>
                  </a>
                </div>
              <?php endforeach; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div>
</body>
</html>

<?php
/**
 * One-time MinIO CORS configuration script.
 * Run this once from a browser (while logged in as admin) to allow
 * direct browser-to-MinIO uploads via presigned URLs.
 *
 * Access: /admin/setup_minio_cors.php
 */
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/minio_helper.php';

if (!isAdmin()) {
    http_response_code(403);
    die('Admin access required.');
}

$result = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $minio = new MinioS3Client();

    // CORS XML: allow PUT/GET/HEAD from any origin with any header
    $corsXml = '<?xml version="1.0" encoding="UTF-8"?>
<CORSConfiguration>
  <CORSRule>
    <AllowedOrigin>*</AllowedOrigin>
    <AllowedMethod>PUT</AllowedMethod>
    <AllowedMethod>GET</AllowedMethod>
    <AllowedMethod>HEAD</AllowedMethod>
    <AllowedHeader>*</AllowedHeader>
    <ExposeHeader>ETag</ExposeHeader>
    <MaxAgeSeconds>3600</MaxAgeSeconds>
  </CORSRule>
</CORSConfiguration>';

    // Use the MinIO endpoint and credentials directly
    $endpoint  = MINIO_ENDPOINT;
    $bucket    = MINIO_BUCKET;
    $accessKey = MINIO_ACCESS_KEY;
    $secretKey = MINIO_SECRET_KEY;
    $region    = MINIO_REGION;
    $protocol  = MINIO_USE_SSL ? 'https' : 'http';

    $url  = "$protocol://$endpoint/$bucket?cors";
    $date = gmdate('D, d M Y H:i:s T');
    $md5  = base64_encode(md5($corsXml, true));

    $stringToSign = "PUT\n$md5\napplication/xml\n$date\n/$bucket?cors";
    $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

    $headers = [
        "Host: $endpoint",
        "Date: $date",
        'Content-Type: application/xml',
        "Content-MD5: $md5",
        "Authorization: AWS $accessKey:$signature",
        'Content-Length: ' . strlen($corsXml),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $corsXml,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $result = ['success' => true,  'message' => "CORS configured successfully (HTTP $httpCode)."];
    } else {
        $result = ['success' => false, 'message' => "Failed (HTTP $httpCode): $response"];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MinIO CORS Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container" style="max-width:600px">
  <h4 class="mb-3">MinIO CORS Setup (run once)</h4>
  <p class="text-muted small">This configures MinIO to accept direct PUT requests from browsers
     (required for mobile direct-upload via presigned URLs).</p>

  <?php if (!empty($result)): ?>
    <div class="alert alert-<?= $result['success'] ? 'success' : 'danger' ?>">
      <?= htmlspecialchars($result['message']) ?>
    </div>
    <?php if ($result['success']): ?>
      <p>✅ You can now use <a href="mobile_upload.php">mobile_upload.php</a> for direct uploads.</p>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post">
    <button class="btn btn-primary" type="submit">Apply CORS Policy to MinIO</button>
    <a href="dashboard.php" class="btn btn-secondary ms-2">Back to Dashboard</a>
  </form>

  <hr>
  <h6>CORS policy that will be applied:</h6>
  <pre class="bg-light p-3 small"><code>AllowedOrigin: *
AllowedMethod: PUT, GET, HEAD
AllowedHeader: *
MaxAgeSeconds: 3600</code></pre>
</div>
</body>
</html>

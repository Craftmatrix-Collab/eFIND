<?php
require_once __DIR__ . '/env_loader.php';

$minioUseSslRaw = strtolower((string)(getenv('MINIO_USE_SSL') ?: 'false'));
$minioUseSsl = in_array($minioUseSslRaw, ['1', 'true', 'yes', 'on'], true);
$minioPort = (int)(getenv('MINIO_PORT') ?: 9000);

// Test MinIO configuration for docker-compose.test.yml (internal network only)
define('MINIO_ENDPOINT', getenv('MINIO_ENDPOINT') ?: 'minio:9000');
define('MINIO_ACCESS_KEY', getenv('MINIO_ACCESS_KEY') ?: (getenv('MINIO_ROOT_USER') ?: ''));
define('MINIO_SECRET_KEY', getenv('MINIO_SECRET_KEY') ?: (getenv('MINIO_ROOT_PASSWORD') ?: ''));
define('MINIO_BUCKET', getenv('MINIO_BUCKET') ?: 'efind-documents');
define('MINIO_REGION', getenv('MINIO_REGION') ?: 'us-east-1');
define('MINIO_USE_SSL', $minioUseSsl);
define('MINIO_PORT', $minioPort);

define('MINIO_CONSOLE_URL', getenv('MINIO_CONSOLE_URL') ?: 'http://minio:9001');
define('MINIO_API_URL', getenv('MINIO_API_URL') ?: 'http://minio:9000');
?>

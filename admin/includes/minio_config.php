<?php
require_once __DIR__ . '/env_loader.php';

$minioUseSslRaw = strtolower((string)(getenv('MINIO_USE_SSL') ?: 'true'));
$minioUseSsl = in_array($minioUseSslRaw, ['1', 'true', 'yes', 'on'], true);
$minioPort = (int)(getenv('MINIO_PORT') ?: 443);

// MinIO S3 Configuration
define('MINIO_ENDPOINT', getenv('MINIO_ENDPOINT') ?: 'minio-gckgwk48ccskg4ogswwgk88s.craftmatrix.org');
define('MINIO_ACCESS_KEY', getenv('MINIO_ACCESS_KEY') ?: (getenv('MINIO_ROOT_USER') ?: ''));
define('MINIO_SECRET_KEY', getenv('MINIO_SECRET_KEY') ?: (getenv('MINIO_ROOT_PASSWORD') ?: ''));
define('MINIO_BUCKET', getenv('MINIO_BUCKET') ?: 'efind-documents'); // Default bucket name
define('MINIO_REGION', getenv('MINIO_REGION') ?: 'us-east-1'); // Default region
define('MINIO_USE_SSL', $minioUseSsl);
define('MINIO_PORT', $minioPort);

// MinIO URLs
define('MINIO_CONSOLE_URL', getenv('MINIO_CONSOLE_URL') ?: 'https://console-gckgwk48ccskg4ogswwgk88s.craftmatrix.org');
define('MINIO_API_URL', getenv('MINIO_API_URL') ?: 'https://minio-gckgwk48ccskg4ogswwgk88s.craftmatrix.org');
?>

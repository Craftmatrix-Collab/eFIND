<?php
// Test MinIO configuration for docker-compose.test.yml (internal network only)
define('MINIO_ENDPOINT', 'minio:9000');
define('MINIO_ACCESS_KEY', 'Mq3UbZJnPo9noPnN');
define('MINIO_SECRET_KEY', 'ASrDcE00C6I8mwn4Be8uhM91FQ0rEH6K');
define('MINIO_BUCKET', 'efind-documents');
define('MINIO_REGION', 'us-east-1');
define('MINIO_USE_SSL', false);
define('MINIO_PORT', 9000);

define('MINIO_CONSOLE_URL', 'http://minio:9001');
define('MINIO_API_URL', 'http://minio:9000');
?>

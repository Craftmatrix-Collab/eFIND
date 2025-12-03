<?php
/**
 * MinIO Connection Test Script
 * Test if MinIO S3 connection is working
 */

require_once __DIR__ . '/includes/minio_helper.php';

echo "=== MinIO Connection Test ===\n\n";

// Display configuration
echo "MinIO Configuration:\n";
echo "- Endpoint: " . MINIO_ENDPOINT . "\n";
echo "- Bucket: " . MINIO_BUCKET . "\n";
echo "- Region: " . MINIO_REGION . "\n";
echo "- Use SSL: " . (MINIO_USE_SSL ? 'Yes' : 'No') . "\n\n";

// Create MinIO client
$minioClient = new MinioS3Client();

// Create a test file
$testFileName = 'test_' . time() . '.txt';
$testFilePath = sys_get_temp_dir() . '/' . $testFileName;
file_put_contents($testFilePath, 'This is a test file for MinIO upload. Time: ' . date('Y-m-d H:i:s'));

echo "Test file created: $testFilePath\n\n";

// Upload test file
echo "Uploading test file to MinIO...\n";
$objectName = 'test/' . $testFileName;
$uploadResult = $minioClient->uploadFile($testFilePath, $objectName, 'text/plain');

if ($uploadResult['success']) {
    echo "✓ Upload successful!\n";
    echo "- URL: " . $uploadResult['url'] . "\n";
    echo "- Object: " . $uploadResult['object_name'] . "\n";
    echo "- Bucket: " . $uploadResult['bucket'] . "\n\n";
    
    echo "Testing file deletion...\n";
    if ($minioClient->deleteFile($objectName)) {
        echo "✓ File deleted successfully!\n";
    } else {
        echo "✗ Failed to delete file\n";
    }
} else {
    echo "✗ Upload failed!\n";
    echo "Error: " . $uploadResult['error'] . "\n";
    if (isset($uploadResult['http_code'])) {
        echo "HTTP Code: " . $uploadResult['http_code'] . "\n";
    }
}

// Clean up test file
unlink($testFilePath);
echo "\nTest file cleaned up.\n";

echo "\n=== Test Complete ===\n";
?>

# MinIO S3 Integration for eFIND System

## Overview
The eFIND system has been successfully integrated with MinIO S3-compatible object storage for handling file uploads in:
- **Ordinances** (ordinances.php)
- **Resolutions** (resolutions.php)  
- **Minutes of Meeting** (minutes_of_meeting.php)

## MinIO Configuration

### Access Details
- **MinIO Console URL**: https://console-gckgwk48ccskg4ogswwgk88s.craftmatrix.org
- **MinIO S3 API URL**: https://minio-gckgwk48ccskg4ogswwgk88s.craftmatrix.org
- **Admin User**: Mq3UbZJnPo9noPnN
- **Admin Password**: ASrDcE00C6I8mwn4Be8uhM91FQ0rEH6K
- **Bucket Name**: efind-documents
- **Region**: us-east-1

### Files Created

1. **includes/minio_config.php**
   - Contains all MinIO connection settings
   - Credentials and endpoint configuration

2. **includes/minio_helper.php**
   - `MinioS3Client` class for S3 operations
   - Methods: uploadFile(), deleteFile(), getPublicUrl()
   - Automatic bucket creation and policy management
   - MIME type detection

3. **test_minio.php**
   - Connection test script
   - Upload and delete functionality test
   - Run with: `php test_minio.php`

## File Organization in MinIO

Files are organized by document type and date:
```
efind-documents/
├── ordinances/
│   └── 2025/
│       └── 12/
│           └── {unique_id}_{timestamp}.{ext}
├── resolutions/
│   └── 2025/
│       └── 12/
│           └── {unique_id}_{timestamp}_0.{ext}
└── minutes/
    └── 2025/
        └── 12/
            └── {unique_id}_{timestamp}_0.{ext}
```

## Changes Made

### All Three Files (ordinances.php, resolutions.php, minutes_of_meeting.php)

#### 1. Added MinIO Helper Include
```php
include(__DIR__ . '/includes/minio_helper.php');
```

#### 2. Updated File Upload Logic

**Before (Local Storage):**
```php
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$uniqueFileName = uniqid() . '.' . $fileExt;
move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $uniqueFileName);
$image_path = 'uploads/' . $uniqueFileName;
```

**After (MinIO S3):**
```php
$uniqueFileName = uniqid() . '_' . time() . '.' . $fileExt;
$objectName = 'ordinances/' . date('Y/m/') . $uniqueFileName;

$minioClient = new MinioS3Client();
$contentType = MinioS3Client::getMimeType($fileName);
$uploadResult = $minioClient->uploadFile($_FILES['image_file']['tmp_name'], $objectName, $contentType);

if ($uploadResult['success']) {
    $image_path = $uploadResult['url']; // Full MinIO URL
} else {
    $_SESSION['error'] = "Failed to upload: " . $uploadResult['error'];
}
```

## Features

### Automatic Bucket Management
- Bucket is created automatically if it doesn't exist
- Public read policy is set for easy file access
- No manual bucket setup required

### File URL Storage
Files are now stored with full MinIO URLs in the database:
```
https://minio-gckgwk48ccskg4ogswwgk88s.craftmatrix.org/efind-documents/ordinances/2025/12/abc123_1733245678.pdf
```

### Multiple File Support
Resolutions and Minutes support multiple file uploads:
- Files are uploaded to MinIO
- URLs are stored as pipe-separated values: `url1|url2|url3`

### Supported File Types
- Images: JPG, JPEG, PNG, GIF, BMP
- Documents: PDF
- Auto-detected MIME types

## Testing

### Connection Test
```bash
cd /home/delfin/code/clone/eFIND/admin
php test_minio.php
```

Expected output:
```
✓ Upload successful!
✓ File deleted successfully!
```

### Web Interface Test
1. Go to Admin Panel
2. Navigate to Ordinances/Resolutions/Minutes
3. Click "Add" button
4. Fill form and upload an image/PDF
5. Submit form
6. Verify file appears in MinIO Console

## Viewing Files in MinIO Console

1. Visit: https://console-gckgwk48ccskg4ogswwgk88s.craftmatrix.org
2. Login with admin credentials
3. Navigate to "Buckets" → "efind-documents"
4. Browse folders: ordinances/, resolutions/, minutes/

## Benefits of MinIO Integration

1. **Scalability**: No local disk space limitations
2. **Centralized Storage**: All files in one S3-compatible storage
3. **Easy Backup**: Simple bucket-level backups
4. **CDN Ready**: Can be integrated with CDN for faster delivery
5. **High Availability**: Distributed object storage
6. **File Management**: Web-based console for file management
7. **Access Control**: S3-compatible policies and permissions

## Troubleshooting

### Upload Fails
1. Check MinIO service is running
2. Verify credentials in `includes/minio_config.php`
3. Check PHP error logs
4. Run `test_minio.php` to verify connection

### Files Not Visible
1. Verify bucket policy allows public read
2. Check file was actually uploaded (MinIO Console)
3. Verify URL is correct in database

### SSL Certificate Issues
If you encounter SSL errors, the helper is configured to bypass SSL verification:
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
```

For production, consider using proper SSL certificates.

## Database Changes

No database schema changes required. The `image_path` column now stores:
- **Before**: Relative paths like `uploads/abc123.jpg`
- **After**: Full MinIO URLs like `https://minio-...org/efind-documents/ordinances/2025/12/abc123.jpg`

## Backward Compatibility

Files uploaded before MinIO integration (stored in `/uploads/`) will still work. The system handles both:
- Old format: `uploads/filename.jpg` (local files)
- New format: `https://...` (MinIO URLs)

## Security Notes

1. **Credentials**: Stored in `includes/minio_config.php` - ensure proper file permissions (600)
2. **Public Access**: Bucket policy allows public read access for uploaded files
3. **Authentication**: Admin authentication required to upload files
4. **File Validation**: File types are validated before upload

## Future Enhancements

1. **Migration Script**: Create script to migrate old local files to MinIO
2. **Thumbnail Generation**: Generate thumbnails for images
3. **File Versioning**: Enable S3 versioning for file history
4. **CDN Integration**: Configure CloudFront or similar CDN
5. **Signed URLs**: Implement temporary signed URLs for private files

## Summary

✅ MinIO S3 integration completed
✅ All three document types updated (ordinances, resolutions, minutes)
✅ Connection tested successfully
✅ Files organized by type and date
✅ Automatic bucket and policy management
✅ Error handling and validation in place

The system is now ready to use MinIO for all file uploads!

# Multiple Image Upload Feature - Implementation Guide

## Current Status

### ✅ Already Implemented
- **Resolutions** - Supports multiple image uploads (page 1, page 2, etc.)
- **Minutes of Meeting** - Supports multiple image uploads

### ❌ Not Yet Implemented  
- **Ordinances** - Currently only supports single file upload

---

## How It Works

### Frontend (HTML)
```html
<!-- Multiple file upload input -->
<input type="file" 
       name="image_file[]"    <!-- Array notation with [] -->
       multiple                <!-- HTML5 multiple attribute -->
       accept=".jpg,.jpeg,.png,.pdf">
```

### Backend (PHP)
```php
// Check if files are uploaded as array
if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
    $image_paths = [];
    
    // Loop through each uploaded file
    foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
        // Skip if file has error
        if ($_FILES['image_file']['error'][$key] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        // Validate file type
        $fileName = basename($_FILES['image_file']['name'][$key]);
        // ... validation code ...
        
        // Upload to MinIO storage
        $uploadResult = $minioClient->uploadFile($tmpName, $objectName, $contentType);
        
        if ($uploadResult['success']) {
            $image_paths[] = $uploadResult['url'];  // Collect all URLs
        }
    }
    
    // Store all image URLs separated by pipe (|)
    if (!empty($image_paths)) {
        $image_path = implode('|', $image_paths);
    }
}
```

### Database Storage
```
image_path column stores multiple URLs separated by |:
https://minio.com/file1.jpg|https://minio.com/file2.jpg|https://minio.com/file3.jpg
```

### Display (Frontend)
```php
// Split the image_path back into array
$image_urls = !empty($resolution['image_path']) 
    ? explode('|', $resolution['image_path']) 
    : [];

// Display as carousel or gallery
foreach ($image_urls as $index => $url) {
    echo "<img src='$url' alt='Page " . ($index + 1) . "'>";
}
```

---

## Implementation Example for Ordinances

To add multiple image upload support to ordinances, here's what needs to be changed:

### 1. Update the Add Form (Line ~1766)

**Current:**
```html
<input type="file" class="form-control" id="image_file" 
       name="image_file" accept=".jpg,.jpeg,.png,.pdf" 
       onchange="processFileWithAutoFill(this)">
<small class="text-muted">Max file size: 5MB.</small>
```

**Updated:**
```html
<input type="file" class="form-control" id="image_file" 
       name="image_file[]" accept=".jpg,.jpeg,.png,.pdf" 
       multiple onchange="processFilesWithAutoFill(this)">
<small class="text-muted">Max file size: 5MB per file. 
You can upload multiple images (e.g., page 1, page 2).</small>
```

### 2. Update the Edit Form (Line ~1834)

**Current:**
```html
<input type="file" class="form-control" id="editImageFile" 
       name="image_file" accept=".jpg,.jpeg,.png,.pdf" 
       onchange="processFile(this, 'edit')">
```

**Updated:**
```html
<input type="file" class="form-control" id="editImageFile" 
       name="image_file[]" accept=".jpg,.jpeg,.png,.pdf" 
       multiple onchange="processFiles(this, 'edit')">
```

### 3. Update PHP Backend (Add Ordinance - Line ~460)

**Current:**
```php
$image_path = null;
if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
    // Single file upload logic
}
```

**Updated:**
```php
$image_path = null;
// Handle multiple file uploads to MinIO
if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
    $minioClient = new MinioS3Client();
    $image_paths = [];
    
    foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['image_file']['error'][$key] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        if (!isValidOrdinanceDocument(['type' => $_FILES['image_file']['type'][$key]])) {
            $_SESSION['error'] = "Invalid file type. Only JPG, PNG, GIF, BMP, PDF, or DOCX files are allowed.";
            header("Location: ordinances.php");
            exit();
        }
        
        $fileName = basename($_FILES['image_file']['name'][$key]);
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        $uniqueFileName = uniqid() . '_' . time() . '_' . $key . '.' . $fileExt;
        $objectName = 'ordinances/' . date('Y/m/') . $uniqueFileName;
        
        // Upload to MinIO
        $contentType = MinioS3Client::getMimeType($fileName);
        $uploadResult = $minioClient->uploadFile($tmpName, $objectName, $contentType);
        
        if ($uploadResult['success']) {
            $image_paths[] = $uploadResult['url'];
            logDocumentUpload('ordinance', $fileName, $uniqueFileName);
        } else {
            $_SESSION['error'] = "Failed to upload file: $fileName. " . $uploadResult['error'];
            header("Location: ordinances.php");
            exit();
        }
    }
    
    if (!empty($image_paths)) {
        $image_path = implode('|', $image_paths);
    }
}
```

### 4. Update PHP Backend (Update Ordinance - Line ~520)

Apply the same logic as add, but preserve existing images:

```php
$existing_image_path = $_POST['existing_image_path'];
$image_path = $existing_image_path;

// Handle multiple file uploads for update
if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
    // Same loop logic as add
    // ...
    
    if (!empty($image_paths)) {
        $new_images = implode('|', $image_paths);
        // Option 1: Replace all images
        $image_path = $new_images;
        
        // Option 2: Append new images to existing
        // $image_path = !empty($existing_image_path) 
        //     ? $existing_image_path . '|' . $new_images 
        //     : $new_images;
    }
}
```

### 5. Update Display Logic

When displaying ordinance details, split the image paths:

```php
$image_urls = !empty($ordinance['image_path']) 
    ? explode('|', $ordinance['image_path']) 
    : [];

if (count($image_urls) > 1) {
    // Display as carousel
    echo '<div id="imageCarousel" class="carousel slide">';
    foreach ($image_urls as $index => $url) {
        echo '<div class="carousel-item' . ($index === 0 ? ' active' : '') . '">';
        echo '<img src="' . htmlspecialchars($url) . '" class="d-block w-100" alt="Page ' . ($index + 1) . '">';
        echo '</div>';
    }
    echo '</div>';
} else if (count($image_urls) === 1) {
    // Display single image
    echo '<img src="' . htmlspecialchars($image_urls[0]) . '" alt="Document">';
}
```

---

## Benefits

1. **Multi-page Documents**: Upload scanned pages separately (page 1, page 2, etc.)
2. **Better Organization**: Keep all pages of one document together
3. **OCR Processing**: Each page can be OCR'd separately for better text extraction
4. **User Friendly**: Easier to manage and view multi-page documents

---

## Testing Checklist

- [ ] Upload single image - should work as before
- [ ] Upload multiple images (2-5 files)
- [ ] Check database - image_path should contain URLs separated by |
- [ ] View document - should display all images
- [ ] Edit document - should preserve existing images
- [ ] Upload additional images when editing
- [ ] Delete document - all images should be removed from storage
- [ ] Download document - should download all pages

---

## Database Schema

No changes needed! The `image_path` column already exists and can store multiple URLs:

```sql
-- Existing column (VARCHAR or TEXT)
image_path VARCHAR(500) or TEXT

-- Stores multiple URLs like this:
-- 'https://minio/file1.jpg|https://minio/file2.jpg|https://minio/file3.jpg'
```

---

## File Size Limits

- **Per File**: 5MB (configurable)
- **Total**: Depends on server configuration
- **Recommended**: Keep total under 50MB for better performance

---

## Supported File Types

- **Images**: JPG, JPEG, PNG, GIF, BMP
- **Documents**: PDF, DOCX, DOC

---

## References

See existing implementation in:
- `/admin/resolutions.php` (lines ~450-500 for add, ~520-570 for update)
- `/admin/minutes_of_meeting.php` (similar implementation)

---

## Summary

**YES, it is absolutely possible to upload multiple images for page 1, page 2, etc.**

The system already supports this for Resolutions and Minutes of Meeting. To enable it for Ordinances, you just need to:

1. Change `name="image_file"` to `name="image_file[]"`
2. Add `multiple` attribute to the input
3. Update the PHP backend to loop through the file array
4. Store multiple URLs separated by `|` in the database
5. Update display logic to show all images (carousel or gallery)

The implementation is straightforward and follows the same pattern already working in resolutions.php and minutes_of_meeting.php.

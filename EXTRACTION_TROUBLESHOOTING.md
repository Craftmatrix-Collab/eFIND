# DOCX/PDF Text Extraction - Troubleshooting Guide

## ✅ Files Installed

All extraction files have been copied to: `/clone/eFIND/`

```
/clone/eFIND/
├── upload_handler.php      ← API endpoint
├── TextExtractor.php       ← Text extraction engine
├── FileUploadManager.php   ← Upload manager
└── uploads/                ← Upload directory (writable)
```

## ✅ Modules Updated

All 3 modules now call `../upload_handler.php`:

1. ✅ admin/resolutions.php
2. ✅ admin/minutes_of_meeting.php
3. ✅ admin/ordinances.php

## 🔍 How to Test

### Step 1: Check Browser Console

1. Open your browser's Developer Tools (F12)
2. Go to the **Console** tab
3. Upload a DOCX file
4. Look for errors like:
   - `404 Not Found` - File path issue
   - `500 Internal Server Error` - PHP error
   - `CORS error` - Cross-origin issue

### Step 2: Check Network Tab

1. Open **Network** tab in Developer Tools
2. Upload a DOCX file
3. Look for the request to `upload_handler.php`
4. Click on it to see:
   - **Status**: Should be `200`
   - **Response**: Should have JSON with `success: true`
   - **Preview**: Should show extracted text

### Step 3: Test Extraction Manually

SSH into server and run:

```bash
cd /home/delfin/code/clone/eFIND
rm -f test_manual_flow.php
php test_manual_flow.php
```

This tests if extraction works on the server.

## 🐛 Common Issues & Fixes

### Issue 1: "No text could be extracted"

**Cause**: JavaScript can't reach upload_handler.php

**Fix**:
```bash
cd /home/delfin/code/clone/eFIND
ls -la upload_handler.php    # Should exist
ls -la uploads/               # Should be writable (777)
```

### Issue 2: 404 Error in Browser

**Cause**: Wrong file path

**Fix**: Check browser console for the exact URL being called.
Should be: `http://yoursite/upload_handler.php?action=upload`

If you see `/upload_handler.php` (starting with `/`), the path is wrong.

### Issue 3: 500 Internal Server Error

**Cause**: PHP error in upload_handler.php

**Fix**: Check PHP error logs:
```bash
tail -f /var/log/apache2/error.log
# or
tail -f /var/log/nginx/error.log
```

### Issue 4: File uploads but no extraction

**Cause**: `extract_text` parameter not being sent

**Fix**: Check Network tab, verify these parameters are sent:
- `file`: [file data]
- `extract_text`: "1"
- `use_ocr`: "1"
- `force_upload`: "1"

## 🧪 Debug Mode

A debug endpoint has been created: `debug_upload.php`

To test:
1. Open browser to: `http://yoursite/debug_upload.php?action=upload`
2. Upload a file using a form
3. See exactly what the server receives

## 📋 Quick Checklist

- [ ] Files exist in `/clone/eFIND/`
- [ ] `uploads/` directory is writable (777)
- [ ] Browser console shows no 404 errors
- [ ] Network tab shows `upload_handler.php` being called
- [ ] Response status is `200`
- [ ] Response JSON contains `extraction` object

## 🔧 Manual Test Commands

```bash
# Test if PHP can extract text
cd /home/delfin/code/clone/eFIND
php -r "
require 'TextExtractor.php';
\$e = new TextExtractor('uploads/');
\$caps = \$e->getCapabilities();
print_r(\$caps);
"

# Should show:
# [docx] => 1  (DOCX supported)
# [pdf] => 1   (PDF supported)
```

## 💡 What Should Happen

When you upload a DOCX file:

1. **Progress message appears**:
   ```
   🔄 Extracting text from DOCX file 1 of 1...
      Using server-side extraction
   ```

2. **Success message appears**:
   ```
   ✅ Successfully extracted 42 words from application.docx
   ```

3. **Form fields auto-fill** with detected data

4. **Smart detection panel** shows what was detected

## 🆘 Still Not Working?

Run this diagnostic:

```bash
cd /home/delfin/code/clone/eFIND
php << 'PHPCODE'
<?php
echo "=== Diagnostics ===\n\n";

// Check files
echo "1. Files:\n";
echo "   upload_handler.php: " . (file_exists('upload_handler.php') ? '✅' : '❌') . "\n";
echo "   TextExtractor.php: " . (file_exists('TextExtractor.php') ? '✅' : '❌') . "\n";
echo "   FileUploadManager.php: " . (file_exists('FileUploadManager.php') ? '✅' : '❌') . "\n";

// Check uploads directory
echo "\n2. Uploads directory:\n";
echo "   Exists: " . (is_dir('uploads') ? '✅' : '❌') . "\n";
echo "   Writable: " . (is_writable('uploads') ? '✅' : '❌') . "\n";

// Check PHP extensions
echo "\n3. PHP Extensions:\n";
echo "   ZIP: " . (class_exists('ZipArchive') ? '✅' : '❌') . "\n";
echo "   JSON: " . (function_exists('json_encode') ? '✅' : '❌') . "\n";

// Test extraction
echo "\n4. Test Extraction:\n";
try {
    require_once 'TextExtractor.php';
    $e = new TextExtractor('uploads/');
    $caps = $e->getCapabilities();
    echo "   DOCX Support: " . ($caps['supported_formats']['docx'] ? '✅' : '❌') . "\n";
    echo "   PDF Support: " . ($caps['supported_formats']['pdf'] ? '✅' : '❌') . "\n";
    echo "\n✅ Everything looks good!\n";
} catch (Exception $ex) {
    echo "   ❌ Error: " . $ex->getMessage() . "\n";
}
?>
PHPCODE
```

---

**Last Updated**: January 20, 2026
**Status**: Ready for testing

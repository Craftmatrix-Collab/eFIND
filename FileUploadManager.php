<?php

class FileUploadManager {
    private $uploadDir;
    private $metadataFile;
    private $lockFile;
    
    public function __construct($uploadDir = 'uploads/') {
        $this->uploadDir = rtrim($uploadDir, '/') . '/';
        $this->metadataFile = $this->uploadDir . '.upload_metadata.json';
        $this->lockFile = $this->uploadDir . '.upload.lock';
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * Upload file with duplicate detection
     * @param array $file $_FILES array element
     * @param array $options ['force' => bool, 'user_id' => string]
     * @return array Result with status, message, and data
     */
    public function upload($file, $options = []) {
        $force = $options['force'] ?? false;
        $userId = $options['user_id'] ?? 'anonymous';
        
        // Validate file upload
        $validation = $this->validateUpload($file);
        if (!$validation['success']) {
            return $validation;
        }
        
        // Calculate file hash
        $fileHash = hash_file('sha256', $file['tmp_name']);
        $fileName = basename($file['name']);
        $fileSize = $file['size'];
        
        // Acquire lock for concurrent upload protection
        $lock = $this->acquireLock();
        
        try {
            // Load metadata
            $metadata = $this->loadMetadata();
            
            // Check for duplicates
            $duplicate = $this->checkDuplicate($fileHash, $fileName, $fileSize, $metadata, $userId);
            
            if ($duplicate && !$force) {
                $this->releaseLock($lock);
                return [
                    'success' => false,
                    'error' => 'duplicate',
                    'message' => $this->formatDuplicateMessage($duplicate),
                    'duplicate_info' => $duplicate,
                    'hint' => 'Use force_upload option to override this restriction.'
                ];
            }
            
            // Generate unique filename if needed
            $targetFileName = $this->generateUniqueFileName($fileName, $metadata);
            $targetPath = $this->uploadDir . $targetFileName;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                $this->releaseLock($lock);
                return [
                    'success' => false,
                    'error' => 'upload_failed',
                    'message' => 'Failed to save uploaded file.'
                ];
            }
            
            // Save metadata
            $uploadInfo = [
                'id' => uniqid('upload_', true),
                'original_name' => $fileName,
                'stored_name' => $targetFileName,
                'file_hash' => $fileHash,
                'file_size' => $fileSize,
                'mime_type' => $file['type'],
                'uploaded_by' => $userId,
                'upload_time' => date('Y-m-d H:i:s'),
                'timestamp' => time(),
                'is_forced' => $duplicate ? true : false
            ];
            
            $metadata[] = $uploadInfo;
            $this->saveMetadata($metadata);
            
            $this->releaseLock($lock);
            
            $message = 'File uploaded successfully.';
            if ($duplicate) {
                $message .= ' (Forced upload - duplicate detected but allowed)';
            }
            
            return [
                'success' => true,
                'message' => $message,
                'data' => $uploadInfo
            ];
            
        } catch (Exception $e) {
            $this->releaseLock($lock);
            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Upload error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validateUpload($file) {
        if (!isset($file['error']) || is_array($file['error'])) {
            return [
                'success' => false,
                'error' => 'invalid_parameters',
                'message' => 'Invalid file upload parameters.'
            ];
        }
        
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return [
                    'success' => false,
                    'error' => 'file_too_large',
                    'message' => 'File exceeds maximum allowed size.'
                ];
            case UPLOAD_ERR_PARTIAL:
                return [
                    'success' => false,
                    'error' => 'upload_incomplete',
                    'message' => 'File upload was interrupted. Please try again.'
                ];
            case UPLOAD_ERR_NO_FILE:
                return [
                    'success' => false,
                    'error' => 'no_file',
                    'message' => 'No file was uploaded.'
                ];
            default:
                return [
                    'success' => false,
                    'error' => 'upload_error',
                    'message' => 'File upload failed with error code: ' . $file['error']
                ];
        }
        
        // Check if file is corrupted
        if ($file['size'] == 0) {
            return [
                'success' => false,
                'error' => 'corrupted_file',
                'message' => 'The uploaded file appears to be corrupted (0 bytes).'
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Check for duplicate files
     */
    private function checkDuplicate($fileHash, $fileName, $fileSize, $metadata, $userId) {
        foreach ($metadata as $record) {
            // Check 1: Exact same file (same hash)
            if ($record['file_hash'] === $fileHash) {
                if ($record['original_name'] === $fileName) {
                    return [
                        'type' => 'exact_duplicate',
                        'description' => 'Exact same file already uploaded',
                        'original_upload' => $record
                    ];
                } else {
                    return [
                        'type' => 'same_content_different_name',
                        'description' => 'File with identical content but different name already exists',
                        'original_upload' => $record
                    ];
                }
            }
            
            // Check 2: Same filename but different content
            if ($record['original_name'] === $fileName && $record['file_hash'] !== $fileHash) {
                // Check if previous upload was corrupted
                if ($record['file_size'] == 0 || $record['file_size'] < $fileSize * 0.5) {
                    return [
                        'type' => 'replacing_corrupted',
                        'description' => 'Replacing potentially corrupted version with valid file',
                        'original_upload' => $record
                    ];
                } else {
                    return [
                        'type' => 'same_name_different_content',
                        'description' => 'A different file with the same name already exists',
                        'original_upload' => $record
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Format duplicate message for user
     */
    private function formatDuplicateMessage($duplicate) {
        $original = $duplicate['original_upload'];
        
        switch ($duplicate['type']) {
            case 'exact_duplicate':
                return sprintf(
                    "⚠️ Duplicate file detected!\n\n" .
                    "This exact file has already been uploaded.\n\n" .
                    "Original upload:\n" .
                    "  • File: %s\n" .
                    "  • Uploaded by: %s\n" .
                    "  • Date: %s\n" .
                    "  • Size: %s\n\n" .
                    "The file you're trying to upload is identical to the existing one.",
                    $original['original_name'],
                    $original['uploaded_by'],
                    $original['upload_time'],
                    $this->formatFileSize($original['file_size'])
                );
            
            case 'same_content_different_name':
                return sprintf(
                    "⚠️ Duplicate content detected!\n\n" .
                    "A file with identical content already exists with a different name.\n\n" .
                    "Existing file:\n" .
                    "  • Name: %s\n" .
                    "  • Uploaded by: %s\n" .
                    "  • Date: %s\n" .
                    "  • Size: %s\n\n" .
                    "Your file: %s\n\n" .
                    "These files have the same content but different names.",
                    $original['original_name'],
                    $original['uploaded_by'],
                    $original['upload_time'],
                    $this->formatFileSize($original['file_size']),
                    $original['original_name']
                );
            
            case 'same_name_different_content':
                return sprintf(
                    "⚠️ Filename conflict detected!\n\n" .
                    "A different file with the same name already exists.\n\n" .
                    "Existing file:\n" .
                    "  • Name: %s\n" .
                    "  • Uploaded by: %s\n" .
                    "  • Date: %s\n" .
                    "  • Size: %s\n\n" .
                    "Your file has the same name but different content.",
                    $original['original_name'],
                    $original['uploaded_by'],
                    $original['upload_time'],
                    $this->formatFileSize($original['file_size'])
                );
            
            case 'replacing_corrupted':
                return sprintf(
                    "⚠️ Potential corrupted file replacement!\n\n" .
                    "A previous upload of this file appears to be corrupted or incomplete.\n\n" .
                    "Previous upload:\n" .
                    "  • Name: %s\n" .
                    "  • Date: %s\n" .
                    "  • Size: %s (potentially corrupted)\n\n" .
                    "Your file size: %s\n\n" .
                    "Use force upload to replace the corrupted version.",
                    $original['original_name'],
                    $original['upload_time'],
                    $this->formatFileSize($original['file_size']),
                    'Valid size'
                );
            
            default:
                return "⚠️ Duplicate file detected! Use force upload option to proceed.";
        }
    }
    
    /**
     * Acquire lock for concurrent upload protection
     */
    private function acquireLock($timeout = 30) {
        $lock = fopen($this->lockFile, 'c');
        $start = time();
        
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (time() - $start > $timeout) {
                fclose($lock);
                throw new Exception('Could not acquire upload lock - concurrent upload timeout');
            }
            usleep(100000); // 100ms
        }
        
        return $lock;
    }
    
    /**
     * Release lock
     */
    private function releaseLock($lock) {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    
    /**
     * Load metadata from file
     */
    private function loadMetadata() {
        if (!file_exists($this->metadataFile)) {
            return [];
        }
        
        $content = file_get_contents($this->metadataFile);
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }
    
    /**
     * Save metadata to file
     */
    private function saveMetadata($metadata) {
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->metadataFile, $json);
    }
    
    /**
     * Generate unique filename
     */
    private function generateUniqueFileName($fileName, $metadata) {
        $existingNames = array_column($metadata, 'stored_name');
        
        if (!in_array($fileName, $existingNames)) {
            return $fileName;
        }
        
        $pathInfo = pathinfo($fileName);
        $baseName = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        
        $counter = 1;
        do {
            $newName = $baseName . '_' . $counter . $extension;
            $counter++;
        } while (in_array($newName, $existingNames));
        
        return $newName;
    }
    
    /**
     * Format file size for display
     */
    private function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    /**
     * Get upload history
     */
    public function getUploadHistory($filter = []) {
        $metadata = $this->loadMetadata();
        
        if (isset($filter['user_id'])) {
            $metadata = array_filter($metadata, function($record) use ($filter) {
                return $record['uploaded_by'] === $filter['user_id'];
            });
        }
        
        return array_values($metadata);
    }
    
    /**
     * Delete upload by ID
     */
    public function deleteUpload($uploadId) {
        $lock = $this->acquireLock();
        
        try {
            $metadata = $this->loadMetadata();
            $found = false;
            
            foreach ($metadata as $key => $record) {
                if ($record['id'] === $uploadId) {
                    $filePath = $this->uploadDir . $record['stored_name'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    unset($metadata[$key]);
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                $this->saveMetadata(array_values($metadata));
                $this->releaseLock($lock);
                return ['success' => true, 'message' => 'Upload deleted successfully.'];
            }
            
            $this->releaseLock($lock);
            return ['success' => false, 'error' => 'not_found', 'message' => 'Upload not found.'];
            
        } catch (Exception $e) {
            $this->releaseLock($lock);
            return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
        }
    }
}

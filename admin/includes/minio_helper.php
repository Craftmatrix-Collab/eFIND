<?php
/**
 * MinIO S3 Helper Class
 * Simple S3-compatible client for MinIO uploads
 */

require_once __DIR__ . '/minio_config.php';

class MinioS3Client {
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $region;
    private $useSSL;
    
    public function __construct() {
        $this->endpoint = MINIO_ENDPOINT;
        $this->accessKey = MINIO_ACCESS_KEY;
        $this->secretKey = MINIO_SECRET_KEY;
        $this->bucket = MINIO_BUCKET;
        $this->region = MINIO_REGION;
        $this->useSSL = MINIO_USE_SSL;
    }
    
    /**
     * Upload file to MinIO
     * @param string $filePath Local file path
     * @param string $objectName Object name in bucket (path in MinIO)
     * @param string $contentType MIME type of file
     * @return array ['success' => bool, 'url' => string, 'error' => string]
     */
    public function uploadFile($filePath, $objectName, $contentType = 'application/octet-stream') {
        try {
            // Ensure bucket exists (create if not)
            $this->ensureBucketExists();
            
            // Read file content
            if (!file_exists($filePath)) {
                return ['success' => false, 'error' => 'File not found: ' . $filePath];
            }
            
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                return ['success' => false, 'error' => 'Failed to read file'];
            }
            
            // Prepare request
            $url = $this->getEndpointUrl() . '/' . $this->bucket . '/' . ltrim($objectName, '/');
            $date = gmdate('D, d M Y H:i:s T');
            $contentMD5 = base64_encode(md5($fileContent, true));
            
            // Create signature
            $stringToSign = "PUT\n{$contentMD5}\n{$contentType}\n{$date}\n/{$this->bucket}/{$objectName}";
            $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
            
            // Set headers
            $headers = [
                'Host: ' . $this->endpoint,
                'Date: ' . $date,
                'Content-Type: ' . $contentType,
                'Content-MD5: ' . $contentMD5,
                'Authorization: AWS ' . $this->accessKey . ':' . $signature,
                'Content-Length: ' . strlen($fileContent)
            ];
            
            // Upload using cURL
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For self-signed certificates
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $publicUrl = $this->getPublicUrl($objectName);
                return [
                    'success' => true,
                    'url' => $publicUrl,
                    'object_name' => $objectName,
                    'bucket' => $this->bucket
                ];
            } else {
                error_log("MinIO upload failed. HTTP Code: $httpCode, Response: $response, cURL Error: $curlError");
                return [
                    'success' => false,
                    'error' => "Upload failed with HTTP code: $httpCode. " . ($curlError ?: $response),
                    'http_code' => $httpCode
                ];
            }
            
        } catch (Exception $e) {
            error_log("MinIO upload exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Ensure bucket exists, create if not
     */
    private function ensureBucketExists() {
        // Check if bucket exists
        $url = $this->getEndpointUrl() . '/' . $this->bucket;
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "HEAD\n\n\n{$date}\n/{$this->bucket}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->endpoint,
            'Date: ' . $date,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // If bucket doesn't exist (404), create it
        if ($httpCode == 404) {
            $this->createBucket();
        }
    }
    
    /**
     * Create bucket
     */
    private function createBucket() {
        $url = $this->getEndpointUrl() . '/' . $this->bucket;
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "PUT\n\n\n{$date}\n/{$this->bucket}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->endpoint,
            'Date: ' . $date,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            // Set bucket policy to public-read for easier access
            $this->setBucketPolicy();
        } else {
            error_log("Failed to create bucket. HTTP Code: $httpCode, Response: $response");
        }
    }
    
    /**
     * Set bucket policy to allow public read access
     */
    private function setBucketPolicy() {
        $policy = json_encode([
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => ['*']],
                    'Action' => ['s3:GetObject'],
                    'Resource' => ["arn:aws:s3:::{$this->bucket}/*"]
                ]
            ]
        ]);
        
        $url = $this->getEndpointUrl() . '/' . $this->bucket . '?policy';
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "PUT\n\napplication/json\n{$date}\n/{$this->bucket}?policy";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->endpoint,
            'Date: ' . $date,
            'Content-Type: application/json',
            'Authorization: AWS ' . $this->accessKey . ':' . $signature,
            'Content-Length: ' . strlen($policy)
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $policy);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Delete file from MinIO
     */
    public function deleteFile($objectName) {
        $url = $this->getEndpointUrl() . '/' . $this->bucket . '/' . ltrim($objectName, '/');
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "DELETE\n\n\n{$date}\n/{$this->bucket}/{$objectName}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->endpoint,
            'Date: ' . $date,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode == 204 || $httpCode == 200);
    }
    
    /**
     * Get endpoint URL
     */
    private function getEndpointUrl() {
        $protocol = $this->useSSL ? 'https' : 'http';
        return $protocol . '://' . $this->endpoint;
    }
    
    /**
     * Get public URL for an object
     */
    public function getPublicUrl($objectName) {
        return $this->getEndpointUrl() . '/' . $this->bucket . '/' . ltrim($objectName, '/');
    }
    
    /**
     * Generate a presigned PUT URL for direct browser-to-MinIO upload (AWS SigV4)
     * @param string $objectName  Object key in bucket (e.g. 'resolutions/2025/01/file.jpg')
     * @param int    $expiresIn   URL validity in seconds (default 15 min)
     * @return string  Presigned URL the browser can PUT to directly
     */
    public function generatePresignedPutUrl($objectName, $expiresIn = 900) {
        $now = new DateTime('UTC');
        $datetime = $now->format('Ymd\THis\Z');
        $date     = $now->format('Ymd');

        $host   = $this->endpoint;
        $region = $this->region;

        // Encode each path segment individually
        $encodedKey   = implode('/', array_map('rawurlencode', explode('/', $objectName)));
        $canonicalUri = '/' . $this->bucket . '/' . $encodedKey;

        $credentialScope = "$date/$region/s3/aws4_request";
        $credential      = $this->accessKey . '/' . $credentialScope;

        // Build canonical query string (keys must be sorted)
        $queryParams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $credential,
            'X-Amz-Date'          => $datetime,
            'X-Amz-Expires'       => (string)$expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);
        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [
            'PUT',
            $canonicalUri,
            $canonicalQueryString,
            "host:$host\n",   // canonical headers (blank line included by trailing \n)
            'host',           // signed headers
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->_deriveSigV4Key($date, $region);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $protocol = $this->useSSL ? 'https' : 'http';
        return $protocol . '://' . $host . $canonicalUri
             . '?' . $canonicalQueryString
             . '&X-Amz-Signature=' . $signature;
    }

    /** Derive the AWS SigV4 signing key */
    private function _deriveSigV4Key($date, $region) {
        $kDate    = hash_hmac('sha256', $date,         'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $region,       $kDate,    true);
        $kService = hash_hmac('sha256', 's3',          $kRegion,  true);
        return    hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * Get file extension from filename
     */
    public static function getFileExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Get MIME type from file extension
     */
    public static function getMimeType($filename) {
        $ext = self::getFileExtension($filename);
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
}
?>

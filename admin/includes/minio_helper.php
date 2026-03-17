<?php
/**
 * MinIO S3 Helper Class
 * Simple S3-compatible client for MinIO uploads
 */

require_once __DIR__ . '/minio_config.php';

class MinioS3Client {
    private $endpoint;
    private $requestEndpoint;
    private $publicBaseUrl;
    private $endpointProbeResults = [];
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
        $this->requestEndpoint = $this->resolveRequestEndpoint($this->endpoint);
        $this->publicBaseUrl = $this->resolvePublicBaseUrl();
    }

    private function applyCurlTlsOptions($ch): void
    {
        $verifyPeer = !(defined('MINIO_INSECURE_SSL') && MINIO_INSECURE_SSL);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyPeer ? 2 : 0);
    }

    private function parseEndpointHostAndPort($endpoint): ?array
    {
        $normalized = trim((string)$endpoint);
        if ($normalized === '') {
            return null;
        }

        if (strpos($normalized, '://') === false) {
            $normalized = 'http://' . $normalized;
        }

        $parts = parse_url($normalized);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return [
            'host' => strtolower((string)$parts['host']),
            'port' => isset($parts['port']) ? (int)$parts['port'] : null,
        ];
    }

    private function resolveEndpointPort(?int $port): int
    {
        if ($port !== null && $port > 0 && $port <= 65535) {
            return $port;
        }

        $configuredPort = defined('MINIO_PORT') ? (int)MINIO_PORT : 0;
        if ($configuredPort > 0 && $configuredPort <= 65535) {
            return $configuredPort;
        }

        return $this->useSSL ? 443 : 80;
    }

    private function normalizeEndpointCandidate(string $endpoint, int $defaultPort): ?string
    {
        $parts = $this->parseEndpointHostAndPort($endpoint);
        if ($parts === null) {
            return null;
        }

        $host = trim((string)$parts['host']);
        if ($host === '') {
            return null;
        }

        $port = $parts['port'];
        if ($port === null || $port <= 0 || $port > 65535) {
            $port = $defaultPort;
        }

        return $host . ':' . (int)$port;
    }

    private function canReachEndpoint(string $endpoint): bool
    {
        $parts = $this->parseEndpointHostAndPort($endpoint);
        if ($parts === null) {
            return false;
        }

        $host = trim((string)$parts['host']);
        if ($host === '') {
            return false;
        }

        $port = $this->resolveEndpointPort($parts['port']);
        $targetHost = $host;
        if (strpos($targetHost, ':') !== false && $targetHost[0] !== '[') {
            $targetHost = '[' . $targetHost . ']';
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $targetHost . ':' . $port,
            $errno,
            $errstr,
            0.75,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);
        return true;
    }

    private function buildRequestEndpointCandidates(string $configuredEndpoint, array $parts): array
    {
        $defaultPort = $this->resolveEndpointPort($parts['port']);
        $candidates = [];
        $append = function (string $candidate) use (&$candidates, $defaultPort): void {
            $normalized = $this->normalizeEndpointCandidate($candidate, $defaultPort);
            if ($normalized !== null && !in_array($normalized, $candidates, true)) {
                $candidates[] = $normalized;
            }
        };

        $append($configuredEndpoint);

        $localHosts = ['localhost', '127.0.0.1', '::1'];
        if (in_array($parts['host'], $localHosts, true)) {
            $append('minio:' . $defaultPort);

            $dockerHost = trim((string)(getenv('MINIO_DOCKER_HOST') ?: 'host.docker.internal'));
            if ($dockerHost !== '') {
                $append($dockerHost . ':' . $defaultPort);
            }
        }

        if (in_array($parts['host'], ['minio', 'host.docker.internal'], true)) {
            $append('localhost:' . $defaultPort);
            $append('127.0.0.1:' . $defaultPort);
        }

        $apiUrl = trim((string)(getenv('MINIO_API_URL') ?: (defined('MINIO_API_URL') ? MINIO_API_URL : '')));
        if ($apiUrl !== '') {
            $append($apiUrl);
        }

        $fallbackRaw = trim((string)(getenv('MINIO_FALLBACK_ENDPOINTS') ?: ''));
        if ($fallbackRaw !== '') {
            foreach (explode(',', $fallbackRaw) as $fallbackEndpoint) {
                $fallbackEndpoint = trim((string)$fallbackEndpoint);
                if ($fallbackEndpoint !== '') {
                    $append($fallbackEndpoint);
                }
            }
        }

        return $candidates;
    }

    private function formatEndpointProbeResults(): string
    {
        if (empty($this->endpointProbeResults)) {
            return '';
        }

        $summary = [];
        foreach ($this->endpointProbeResults as $endpoint => $reachable) {
            $summary[] = $endpoint . ($reachable ? ' (reachable)' : ' (unreachable)');
        }

        return implode(', ', $summary);
    }

    private function resolveRequestEndpoint($configuredEndpoint)
    {
        $endpoint = trim((string)$configuredEndpoint);
        $parts = $this->parseEndpointHostAndPort($endpoint);
        if ($parts === null) {
            return $endpoint;
        }

        $defaultPort = $this->resolveEndpointPort($parts['port']);
        $normalizedConfigured = $this->normalizeEndpointCandidate($endpoint, $defaultPort) ?? $endpoint;

        $probeHosts = ['localhost', '127.0.0.1', '::1', 'minio', 'host.docker.internal'];
        $hasFallbackEndpoints = trim((string)(getenv('MINIO_FALLBACK_ENDPOINTS') ?: '')) !== '';
        if (!$hasFallbackEndpoints && !in_array($parts['host'], $probeHosts, true)) {
            return $normalizedConfigured;
        }

        $candidates = $this->buildRequestEndpointCandidates($endpoint, $parts);
        if (empty($candidates)) {
            return $normalizedConfigured;
        }

        $this->endpointProbeResults = [];
        foreach ($candidates as $candidate) {
            $reachable = $this->canReachEndpoint($candidate);
            $this->endpointProbeResults[$candidate] = $reachable;
            if ($reachable) {
                if ($candidate !== $normalizedConfigured) {
                    error_log("MinIO request endpoint fallback selected: {$normalizedConfigured} -> {$candidate}");
                }
                return $candidate;
            }
        }

        return $normalizedConfigured;
    }

    private function resolvePublicBaseUrl(): string
    {
        $publicUrl = trim((string)(getenv('MINIO_PUBLIC_URL') ?: ''));
        if ($publicUrl === '' && defined('MINIO_API_URL')) {
            $publicUrl = trim((string)MINIO_API_URL);
        }
        if ($publicUrl === '' && getenv('MINIO_API_URL') !== false) {
            $publicUrl = trim((string)getenv('MINIO_API_URL'));
        }

        if ($publicUrl === '') {
            return $this->buildEndpointUrl($this->endpoint);
        }

        if (strpos($publicUrl, '://') === false) {
            $protocol = $this->useSSL ? 'https' : 'http';
            $publicUrl = $protocol . '://' . $publicUrl;
        }

        return rtrim($publicUrl, '/');
    }

    private function buildUploadFailureMessage($httpCode, $curlError, $response): string
    {
        $details = trim((string)($curlError !== '' ? $curlError : $response));
        $message = "Upload failed with HTTP code: $httpCode.";
        if ($details !== '') {
            $message .= ' ' . $details;
        }

        if ((int)$httpCode === 0) {
            $message .= " MinIO upload endpoint: {$this->requestEndpoint}.";
            if ($this->requestEndpoint !== $this->endpoint) {
                $message .= " Configured MINIO_ENDPOINT: {$this->endpoint}.";
            }
            $probeSummary = $this->formatEndpointProbeResults();
            if ($probeSummary !== '') {
                $message .= " Endpoint probe results: {$probeSummary}.";
            }
            $message .= ' Ensure MinIO is running and reachable from the PHP runtime.';
        }

        return $message;
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

            if ($contentType === 'application/octet-stream') {
                $contentType = self::getMimeType($filePath);
            }

            // Optimize uploaded images before storage when compression is beneficial
            $optimizedImage = $this->optimizeImageForUpload($filePath, $contentType, $fileContent);
            if ($optimizedImage !== null) {
                $fileContent = $optimizedImage['content'];
                $contentType = $optimizedImage['content_type'];
            }
            
            // Prepare request
            $url = $this->getRequestEndpointUrl() . '/' . $this->bucket . '/' . ltrim($objectName, '/');
            $date = gmdate('D, d M Y H:i:s T');
            $contentMD5 = base64_encode(md5($fileContent, true));
            
            // Create signature
            $stringToSign = "PUT\n{$contentMD5}\n{$contentType}\n{$date}\n/{$this->bucket}/{$objectName}";
            $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
            
            // Set headers
            $headers = [
                'Host: ' . $this->requestEndpoint,
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
            $this->applyCurlTlsOptions($ch);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            
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
                    'error' => $this->buildUploadFailureMessage($httpCode, $curlError, $response),
                    'http_code' => $httpCode
                ];
            }
            
        } catch (Exception $e) {
            error_log("MinIO upload exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Compress and resize supported images (JPEG/PNG/WebP) before upload.
     * Returns null when optimization is unavailable or not beneficial.
     */
    private function optimizeImageForUpload($filePath, $contentType, $originalContent) {
        if (!extension_loaded('gd')) {
            return null;
        }

        $mimeType = strtolower((string)$contentType);
        $supported = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $supported, true)) {
            return null;
        }

        $image = @imagecreatefromstring($originalContent);
        if (!$image) {
            return null;
        }

        $maxDimension = 1920;
        $width = imagesx($image);
        $height = imagesy($image);
        $optimizedImage = $image;

        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = (int)round($width * $ratio);
            $newHeight = (int)round($height * $ratio);
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }

            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $optimizedImage = $resizedImage;
        }

        ob_start();
        $writeOk = false;
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $writeOk = imagejpeg($optimizedImage, null, 82);
            $mimeType = 'image/jpeg';
        } elseif ($mimeType === 'image/png') {
            $writeOk = imagepng($optimizedImage, null, 6);
        } elseif ($mimeType === 'image/webp' && function_exists('imagewebp')) {
            $writeOk = imagewebp($optimizedImage, null, 82);
        }
        $compressedContent = ob_get_clean();

        if ($optimizedImage !== $image) {
            imagedestroy($optimizedImage);
        }
        imagedestroy($image);

        if (!$writeOk || $compressedContent === false || $compressedContent === '') {
            return null;
        }

        if (strlen($compressedContent) >= strlen($originalContent)) {
            return null;
        }

        return [
            'content' => $compressedContent,
            'content_type' => $mimeType,
        ];
    }
    
    /**
     * Ensure bucket exists, create if not
     */
    private function ensureBucketExists() {
        // Check if bucket exists
        $url = $this->getRequestEndpointUrl() . '/' . $this->bucket;
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "HEAD\n\n\n{$date}\n/{$this->bucket}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->requestEndpoint,
            'Date: ' . $date,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->applyCurlTlsOptions($ch);
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
        $url = $this->getRequestEndpointUrl() . '/' . $this->bucket;
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "PUT\n\n\n{$date}\n/{$this->bucket}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->requestEndpoint,
            'Date: ' . $date,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->applyCurlTlsOptions($ch);
        
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
        
        $url = $this->getRequestEndpointUrl() . '/' . $this->bucket . '?policy';
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "PUT\n\napplication/json\n{$date}\n/{$this->bucket}?policy";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->requestEndpoint,
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
        $this->applyCurlTlsOptions($ch);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Delete file from MinIO
     */
    public function deleteFile($objectName) {
        $url = $this->getRequestEndpointUrl() . '/' . $this->bucket . '/' . ltrim($objectName, '/');
        $date = gmdate('D, d M Y H:i:s T');
        
        $stringToSign = "DELETE\n\n\n{$date}\n/{$this->bucket}/{$objectName}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        $headers = [
            'Host: ' . $this->requestEndpoint,
            'Date: ' . $date,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->applyCurlTlsOptions($ch);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode == 204 || $httpCode == 200);
    }
    
    /**
     * Get endpoint URL
     */
    private function buildEndpointUrl($endpoint) {
        $protocol = $this->useSSL ? 'https' : 'http';
        return $protocol . '://' . trim((string)$endpoint);
    }

    private function getRequestEndpointUrl() {
        return $this->buildEndpointUrl($this->requestEndpoint);
    }
    
    /**
     * Get public URL for an object
     */
    public function getPublicUrl($objectName) {
        return $this->publicBaseUrl . '/' . $this->bucket . '/' . ltrim($objectName, '/');
    }

    /**
     * Extract object name from a public MinIO URL for this configured bucket.
     * Returns null when the URL doesn't match this bucket path format.
     */
    public function extractObjectNameFromUrl($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['path'])) {
            return null;
        }

        $path = ltrim((string)$parsed['path'], '/');
        $bucketPrefix = trim((string)$this->bucket, '/') . '/';
        if (strpos($path, $bucketPrefix) !== 0) {
            return null;
        }

        $objectName = rawurldecode(substr($path, strlen($bucketPrefix)));
        return $objectName !== '' ? $objectName : null;
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

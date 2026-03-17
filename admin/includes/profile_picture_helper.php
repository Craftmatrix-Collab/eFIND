<?php

if (!function_exists('efind_profile_picture_is_local_host')) {
    function efind_profile_picture_is_local_host(string $host): bool
    {
        $normalized = strtolower(trim($host, "[] \t\n\r\0\x0B"));
        if ($normalized === '') {
            return true;
        }

        return in_array(
            $normalized,
            ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'minio', 'host.docker.internal'],
            true
        );
    }
}

if (!function_exists('efind_profile_picture_minio_client')) {
    function efind_profile_picture_minio_client()
    {
        static $client = null;
        static $initialized = false;

        if ($initialized) {
            return $client;
        }

        $initialized = true;
        if (!class_exists('MinioS3Client')) {
            $minioHelperPath = __DIR__ . '/minio_helper.php';
            if (is_file($minioHelperPath)) {
                require_once $minioHelperPath;
            }
        }

        if (class_exists('MinioS3Client')) {
            $client = new MinioS3Client();
        }

        return $client;
    }
}

if (!function_exists('efind_profile_picture_with_cache_bust')) {
    function efind_profile_picture_with_cache_bust(string $src, bool $appendCacheBust = true): string
    {
        if (!$appendCacheBust) {
            return $src;
        }

        if ($src === '' || strpos($src, 'data:') === 0 || preg_match('#^images/#i', $src)) {
            return $src;
        }

        return $src . (strpos($src, '?') === false ? '?t=' : '&t=') . time();
    }
}

if (!function_exists('efind_resolve_profile_picture_src')) {
    function efind_resolve_profile_picture_src($rawPath, bool $appendCacheBust = true): string
    {
        $path = trim((string)$rawPath);
        if ($path === '') {
            return 'images/profile.jpg';
        }

        if (stripos($path, 'data:image/') === 0) {
            return $path;
        }

        if (preg_match('#^/?images/#i', $path)) {
            return ltrim($path, '/');
        }

        $minioClient = efind_profile_picture_minio_client();

        if (preg_match('#^(https?:)?//#i', $path)) {
            $resolved = $path;
            $parsed = parse_url($path);
            $host = strtolower((string)($parsed['host'] ?? ''));
            if ($host !== '' && efind_profile_picture_is_local_host($host) && class_exists('MinioS3Client') && $minioClient instanceof MinioS3Client) {
                $objectName = $minioClient->extractObjectNameFromUrl($path);
                if ($objectName !== null) {
                    $resolved = $minioClient->getPublicUrl($objectName);
                }
            }

            return efind_profile_picture_with_cache_bust($resolved, $appendCacheBust);
        }

        $normalized = ltrim($path, '/');
        if (preg_match('#^uploads/#i', $normalized)) {
            return efind_profile_picture_with_cache_bust($normalized, $appendCacheBust);
        }

        if (class_exists('MinioS3Client') && $minioClient instanceof MinioS3Client) {
            $bucketPrefix = trim((string)(defined('MINIO_BUCKET') ? MINIO_BUCKET : ''), '/');
            if ($bucketPrefix !== '' && strpos($normalized, $bucketPrefix . '/') === 0) {
                $normalized = substr($normalized, strlen($bucketPrefix) + 1);
            }

            if (preg_match('#^profiles/#i', $normalized)) {
                return efind_profile_picture_with_cache_bust($minioClient->getPublicUrl($normalized), $appendCacheBust);
            }
        }

        return efind_profile_picture_with_cache_bust('uploads/profiles/' . basename($normalized), $appendCacheBust);
    }
}

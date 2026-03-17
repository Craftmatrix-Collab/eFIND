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

if (!function_exists('efind_profile_picture_local_candidates')) {
    function efind_profile_picture_local_candidates(string $rawPath): array
    {
        $normalized = ltrim(str_replace('\\', '/', trim($rawPath)), '/');
        if ($normalized === '' || strpos($normalized, '..') !== false) {
            return [];
        }

        $adminRoot = dirname(__DIR__);
        $candidates = [];
        $appendCandidate = static function (string $candidate) use (&$candidates): void {
            if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        };

        if (preg_match('#^uploads/#i', $normalized)) {
            $appendCandidate($adminRoot . '/' . $normalized);
        }

        if (preg_match('#^profiles/#i', $normalized)) {
            $appendCandidate($adminRoot . '/uploads/' . $normalized);
        }

        if (preg_match('#^uploads/profiles/#i', $normalized)) {
            $appendCandidate($adminRoot . '/uploads/profiles/' . basename($normalized));
        } elseif (!preg_match('#^(https?:)?//#i', $normalized) && !preg_match('#^/?images/#i', $normalized)) {
            $appendCandidate($adminRoot . '/uploads/profiles/' . basename($normalized));
        }

        return $candidates;
    }
}

if (!function_exists('efind_delete_profile_picture_asset')) {
    function efind_delete_profile_picture_asset($rawPath): bool
    {
        $path = trim((string)$rawPath);
        if ($path === '') {
            return false;
        }

        $minioClient = efind_profile_picture_minio_client();
        if (preg_match('#^(https?:)?//#i', $path) && class_exists('MinioS3Client') && $minioClient instanceof MinioS3Client) {
            $objectName = $minioClient->extractObjectNameFromUrl($path);
            if (!empty($objectName) && $minioClient->deleteFile($objectName)) {
                return true;
            }
        }

        foreach (efind_profile_picture_local_candidates($path) as $candidatePath) {
            if (is_file($candidatePath) && @unlink($candidatePath)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('efind_is_remote_profile_picture_path')) {
    function efind_is_remote_profile_picture_path(string $path): bool
    {
        return preg_match('#^https?://#i', trim($path)) === 1;
    }
}

if (!function_exists('efind_resolve_durable_profile_picture_upload')) {
    function efind_resolve_durable_profile_picture_upload(array $uploadResult, string $context, ?string &$errorMessage = null): ?string
    {
        $errorMessage = 'Profile picture upload failed. Please try again.';

        if (empty($uploadResult['success'])) {
            $rawError = trim((string)($uploadResult['error'] ?? ''));
            if ($rawError !== '') {
                error_log($context . ' upload error: ' . $rawError);
            }
            return null;
        }

        $resolvedPath = trim((string)($uploadResult['url'] ?? ''));
        if ($resolvedPath === '') {
            error_log($context . ' upload succeeded but returned an empty URL/path.');
            return null;
        }

        if (efind_is_remote_profile_picture_path($resolvedPath)) {
            return $resolvedPath;
        }

        // Local fallback is intentionally rejected for profile photos to avoid
        // non-durable avatar paths that later resolve to default images.
        efind_delete_profile_picture_asset($resolvedPath);
        $rawReason = trim((string)($uploadResult['minio_error'] ?? ($uploadResult['warning'] ?? '')));
        if ($rawReason !== '') {
            error_log($context . ' fallback rejected: ' . $rawReason);
        } else {
            error_log($context . ' fallback rejected: non-remote profile picture path (' . $resolvedPath . ')');
        }

        $errorMessage = 'Profile picture storage is temporarily unavailable. Please try again shortly.';
        return null;
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
            $adminRoot = dirname(__DIR__);
            foreach (efind_profile_picture_local_candidates($normalized) as $candidatePath) {
                if (is_file($candidatePath)) {
                    $relativePath = str_replace('\\', '/', $candidatePath);
                    $adminRootPrefix = rtrim(str_replace('\\', '/', $adminRoot), '/') . '/';
                    if (strpos($relativePath, $adminRootPrefix) === 0) {
                        $relativePath = substr($relativePath, strlen($adminRootPrefix));
                    }
                    return efind_profile_picture_with_cache_bust(ltrim($relativePath, '/'), $appendCacheBust);
                }
            }

            if (class_exists('MinioS3Client') && $minioClient instanceof MinioS3Client) {
                $derivedObjectName = preg_replace('#^uploads/#i', '', $normalized);
                if (is_string($derivedObjectName) && preg_match('#^profiles/#i', $derivedObjectName)) {
                    return efind_profile_picture_with_cache_bust($minioClient->getPublicUrl($derivedObjectName), $appendCacheBust);
                }
            }

            error_log('Profile picture path points to missing local file: ' . $normalized);
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

<?php
if (!function_exists('normalizeImageHashDocumentType')) {
    function normalizeImageHashDocumentType($documentType)
    {
        $type = strtolower(trim((string)$documentType));
        $map = [
            'ordinance' => 'ordinance',
            'ordinances' => 'ordinance',
            'resolution' => 'resolution',
            'resolutions' => 'resolution',
            'minute' => 'minutes',
            'minutes' => 'minutes',
            'minutes_of_meeting' => 'minutes',
        ];

        return $map[$type] ?? null;
    }
}

if (!function_exists('getImageHashDocumentConfig')) {
    function getImageHashDocumentConfig($documentType)
    {
        $type = normalizeImageHashDocumentType($documentType);
        if ($type === 'ordinance') {
            return ['type' => 'ordinance', 'table' => 'ordinances', 'title_column' => 'title', 'image_column' => 'image_path'];
        }
        if ($type === 'resolution') {
            return ['type' => 'resolution', 'table' => 'resolutions', 'title_column' => 'title', 'image_column' => 'image_path'];
        }
        if ($type === 'minutes') {
            return ['type' => 'minutes', 'table' => 'minutes_of_meeting', 'title_column' => 'title', 'image_column' => 'image_path'];
        }
        return null;
    }
}

if (!function_exists('ensureDocumentImageHashTable')) {
    function ensureDocumentImageHashTable($conn)
    {
        $sql = "CREATE TABLE IF NOT EXISTS document_image_hashes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_type VARCHAR(32) NOT NULL,
            document_id INT NOT NULL,
            image_index INT NOT NULL DEFAULT 0,
            image_path TEXT NULL,
            image_hash CHAR(16) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_doc_image (document_type, document_id, image_index),
            KEY idx_doc_type_hash (document_type, image_hash),
            KEY idx_doc_type_id (document_type, document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        return $conn->query($sql) === true;
    }
}

if (!function_exists('parseStoredImagePaths')) {
    function parseStoredImagePaths($imagePath)
    {
        $raw = trim((string)$imagePath);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\|/', $raw);
        if (!is_array($parts)) {
            return [];
        }

        $paths = [];
        foreach ($parts as $part) {
            $value = trim((string)$part);
            if ($value !== '') {
                $paths[] = $value;
            }
        }
        return $paths;
    }
}

if (!function_exists('computeAverageImageHashFromFile')) {
    function computeAverageImageHashFromFile($filePath)
    {
        if (!extension_loaded('gd') || !is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return null;
        }

        $image = @imagecreatefromstring($content);
        if (!$image) {
            return null;
        }

        $hashSize = 8;
        $resized = imagecreatetruecolor($hashSize, $hashSize);
        if (!$resized) {
            imagedestroy($image);
            return null;
        }

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $hashSize,
            $hashSize,
            imagesx($image),
            imagesy($image)
        );

        $values = [];
        $sum = 0;
        for ($y = 0; $y < $hashSize; $y++) {
            for ($x = 0; $x < $hashSize; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int)round(($r * 299 + $g * 587 + $b * 114) / 1000);
                $values[] = $gray;
                $sum += $gray;
            }
        }

        imagedestroy($resized);
        imagedestroy($image);

        if (count($values) !== 64) {
            return null;
        }

        $average = $sum / 64;
        $hash = '';
        for ($i = 0; $i < 16; $i++) {
            $nibble = 0;
            for ($bit = 0; $bit < 4; $bit++) {
                $idx = ($i * 4) + $bit;
                if ($values[$idx] >= $average) {
                    $nibble |= (1 << (3 - $bit));
                }
            }
            $hash .= dechex($nibble);
        }

        return strtolower($hash);
    }
}

if (!function_exists('downloadImageForHashing')) {
    function normalizeImageHashHost($value)
    {
        $raw = trim(strtolower((string)$value));
        if ($raw === '') {
            return '';
        }

        if (strpos($raw, '://') === false) {
            $raw = 'http://' . $raw;
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') {
            return '';
        }

        return rtrim($host, '.');
    }
}

if (!function_exists('getTrustedImageHashHosts')) {
    function getTrustedImageHashHosts()
    {
        $hosts = [];
        $append = function ($value) use (&$hosts) {
            $host = normalizeImageHashHost($value);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        };

        if (defined('MINIO_ENDPOINT')) {
            $append(MINIO_ENDPOINT);
        }
        $append((string)(getenv('MINIO_ENDPOINT') ?: ''));
        if (defined('MINIO_API_URL')) {
            $append(MINIO_API_URL);
        }
        $append((string)(getenv('MINIO_API_URL') ?: ''));

        $append((string)($_SERVER['HTTP_HOST'] ?? ''));

        $extraHosts = trim((string)(getenv('IMAGE_HASH_ALLOWED_HOSTS') ?: ''));
        if ($extraHosts !== '') {
            foreach (explode(',', $extraHosts) as $extraHost) {
                $append($extraHost);
            }
        }

        return $hosts;
    }
}

if (!function_exists('isTrustedImageHashUrl')) {
    function isTrustedImageHashUrl($url)
    {
        $value = trim((string)$url);
        if ($value === '') {
            return false;
        }

        $validatedUrl = filter_var($value, FILTER_VALIDATE_URL);
        if ($validatedUrl === false) {
            return false;
        }

        $parts = parse_url($validatedUrl);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }

        $host = normalizeImageHashHost($validatedUrl);
        if ($host === '') {
            return false;
        }

        $trustedHosts = getTrustedImageHashHosts();
        if (empty($trustedHosts)) {
            return false;
        }

        return in_array($host, $trustedHosts, true);
    }
}

if (!function_exists('downloadImageForHashing')) {
    function downloadImageForHashing($url)
    {
        if (!isTrustedImageHashUrl($url)) {
            return null;
        }

        $allowInsecureSsl = (string)(getenv('IMAGE_HASH_ALLOW_INSECURE_SSL') ?: '') === '1';
        $tmpPath = tempnam(sys_get_temp_dir(), 'img_hash_');
        if ($tmpPath === false) {
            return null;
        }

        $downloaded = false;
        if (function_exists('curl_init')) {
            $fp = fopen($tmpPath, 'wb');
            $ch = curl_init($url);
            if ($fp && $ch) {
                $curlOptions = [
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => !$allowInsecureSsl,
                    CURLOPT_SSL_VERIFYHOST => $allowInsecureSsl ? 0 : 2,
                    CURLOPT_USERAGENT => 'eFIND ImageHash',
                ];
                if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                    $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                }
                curl_setopt_array($ch, $curlOptions);
                $ok = curl_exec($ch);
                $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $downloaded = ($ok !== false && $statusCode >= 200 && $statusCode < 400);
                curl_close($ch);
            }
            if ($fp) {
                fclose($fp);
            }
        }

        if (!$downloaded) {
            $context = stream_context_create([
                'http' => ['timeout' => 20],
                'ssl' => [
                    'verify_peer' => !$allowInsecureSsl,
                    'verify_peer_name' => !$allowInsecureSsl,
                ],
            ]);
            $content = @file_get_contents($url, false, $context);
            if ($content !== false && $content !== '') {
                @file_put_contents($tmpPath, $content);
                $downloaded = true;
            }
        }

        if (!$downloaded || !is_file($tmpPath) || filesize($tmpPath) <= 0) {
            @unlink($tmpPath);
            return null;
        }

        return $tmpPath;
    }
}

if (!function_exists('computeAverageImageHashFromSource')) {
    function computeAverageImageHashFromSource($source)
    {
        $value = trim((string)$source);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            $tmpPath = downloadImageForHashing($value);
            if ($tmpPath === null) {
                return null;
            }
            $hash = computeAverageImageHashFromFile($tmpPath);
            @unlink($tmpPath);
            return $hash;
        }

        $candidatePaths = [$value];
        $candidatePaths[] = dirname(__DIR__, 2) . '/' . ltrim($value, '/');
        $candidatePaths[] = dirname(__DIR__) . '/' . ltrim($value, '/');

        foreach ($candidatePaths as $candidatePath) {
            if (is_file($candidatePath)) {
                return computeAverageImageHashFromFile($candidatePath);
            }
        }

        return null;
    }
}

if (!function_exists('saveDocumentImageHashes')) {
    function saveDocumentImageHashes($conn, $documentType, $documentId, $hashEntries)
    {
        $config = getImageHashDocumentConfig($documentType);
        if (!$config || $documentId <= 0 || !is_array($hashEntries) || empty($hashEntries)) {
            return;
        }

        if (!ensureDocumentImageHashTable($conn)) {
            return;
        }

        $stmt = $conn->prepare(
            "INSERT INTO document_image_hashes (document_type, document_id, image_index, image_path, image_hash)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE image_path = VALUES(image_path), image_hash = VALUES(image_hash), updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return;
        }

        $index = 0;
        foreach ($hashEntries as $entry) {
            $hash = strtolower(trim((string)($entry['hash'] ?? '')));
            if ($hash === '') {
                $index++;
                continue;
            }
            $path = trim((string)($entry['path'] ?? ''));
            $docType = $config['type'];
            $stmt->bind_param('siiss', $docType, $documentId, $index, $path, $hash);
            $stmt->execute();
            $index++;
        }

        $stmt->close();
    }
}

if (!function_exists('findMatchingImageHashes')) {
    function findMatchingImageHashes($conn, $documentType, $imageHash, $excludeDocumentId = 0)
    {
        $config = getImageHashDocumentConfig($documentType);
        $hash = strtolower(trim((string)$imageHash));
        if (!$config || $hash === '') {
            return [];
        }

        if (!ensureDocumentImageHashTable($conn)) {
            return [];
        }

        $table = $config['table'];
        $titleColumn = $config['title_column'];
        $sql = "SELECT h.document_id,
                       COALESCE(MAX(t.{$titleColumn}), CONCAT('#', h.document_id)) AS title,
                       MIN(h.image_path) AS image_path
                FROM document_image_hashes h
                LEFT JOIN {$table} t ON t.id = h.document_id
                WHERE h.document_type = ? AND h.image_hash = ?";

        if ((int)$excludeDocumentId > 0) {
            $sql .= " AND h.document_id <> ?";
        }

        $sql .= " GROUP BY h.document_id ORDER BY h.document_id DESC LIMIT 10";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $docType = $config['type'];
        if ((int)$excludeDocumentId > 0) {
            $excludeId = (int)$excludeDocumentId;
            $stmt->bind_param('ssi', $docType, $hash, $excludeId);
        } else {
            $stmt->bind_param('ss', $docType, $hash);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('backfillDocumentImageHashes')) {
    function backfillDocumentImageHashes($conn, $documentType, $limit = 120)
    {
        $config = getImageHashDocumentConfig($documentType);
        if (!$config) {
            return;
        }
        if (!extension_loaded('gd')) {
            return;
        }
        if (!ensureDocumentImageHashTable($conn)) {
            return;
        }

        $table = $config['table'];
        $titleColumn = $config['title_column'];
        $imageColumn = $config['image_column'];
        $sql = "SELECT d.id, d.{$titleColumn} AS title, d.{$imageColumn} AS image_path
                FROM {$table} d
                LEFT JOIN (
                    SELECT document_id, COUNT(*) AS hash_count
                    FROM document_image_hashes
                    WHERE document_type = ?
                    GROUP BY document_id
                ) h ON h.document_id = d.id
                WHERE COALESCE(d.{$imageColumn}, '') <> ''
                  AND (h.hash_count IS NULL OR h.hash_count = 0)
                ORDER BY d.id DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $docType = $config['type'];
        $safeLimit = max(1, min(300, (int)$limit));
        $stmt->bind_param('si', $docType, $safeLimit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        if (!is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $documentId = (int)($row['id'] ?? 0);
            if ($documentId <= 0) {
                continue;
            }

            $paths = parseStoredImagePaths((string)($row['image_path'] ?? ''));
            if (empty($paths)) {
                continue;
            }

            $entries = [];
            foreach ($paths as $path) {
                $hash = computeAverageImageHashFromSource($path);
                if ($hash !== null && $hash !== '') {
                    $entries[] = [
                        'hash' => $hash,
                        'path' => $path,
                    ];
                }
            }

            if (!empty($entries)) {
                saveDocumentImageHashes($conn, $docType, $documentId, $entries);
            }
        }
    }
}

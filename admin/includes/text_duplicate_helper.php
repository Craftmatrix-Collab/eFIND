<?php
if (!function_exists('normalizeTextDuplicateDocumentType')) {
    function normalizeTextDuplicateDocumentType($documentType)
    {
        $type = strtolower(trim((string)$documentType));
        $map = [
            'executive_order' => 'executive_order',
            'executive_orders' => 'executive_order',
            'resolution' => 'resolution',
            'resolutions' => 'resolution',
            'minute' => 'minutes',
            'minutes' => 'minutes',
            'meeting' => 'minutes',
            'meeting_minutes' => 'minutes',
            'minutes_of_meeting' => 'minutes',
        ];

        return $map[$type] ?? null;
    }
}

if (!function_exists('getTextDuplicateDocumentConfig')) {
    function getTextDuplicateDocumentConfig($documentType)
    {
        $type = normalizeTextDuplicateDocumentType($documentType);
        if ($type === 'executive_order') {
            return ['type' => 'executive_order', 'table' => 'executive_orders', 'title_column' => 'title', 'content_column' => 'content'];
        }
        if ($type === 'resolution') {
            return ['type' => 'resolution', 'table' => 'resolutions', 'title_column' => 'title', 'content_column' => 'content'];
        }
        if ($type === 'minutes') {
            return ['type' => 'minutes', 'table' => 'minutes_of_meeting', 'title_column' => 'title', 'content_column' => 'content'];
        }

        return null;
    }
}

if (!function_exists('normalizeDocumentDuplicateText')) {
    function normalizeDocumentDuplicateText($value)
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        if (function_exists('mb_strtolower')) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));

        return trim((string)$text);
    }
}

if (!function_exists('buildDocumentDuplicateTokenSet')) {
    function buildDocumentDuplicateTokenSet($normalizedText)
    {
        $tokens = preg_split('/\s+/u', trim((string)$normalizedText));
        if (!is_array($tokens)) {
            return [];
        }

        $set = [];
        foreach ($tokens as $token) {
            $word = trim((string)$token);
            $wordLength = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            if ($word === '' || $wordLength < 3) {
                continue;
            }
            $set[$word] = true;
        }

        return $set;
    }
}

if (!function_exists('calculateDocumentDuplicateCoverage')) {
    function calculateDocumentDuplicateCoverage($baseSet, $candidateSet)
    {
        if (!is_array($baseSet) || !is_array($candidateSet) || empty($baseSet) || empty($candidateSet)) {
            return 0.0;
        }

        $intersection = array_intersect_key($baseSet, $candidateSet);
        $denominator = min(count($baseSet), count($candidateSet));
        if ($denominator <= 0) {
            return 0.0;
        }

        return count($intersection) / $denominator;
    }
}

if (!function_exists('findMatchingDocumentTextDuplicates')) {
    function findMatchingDocumentTextDuplicates($conn, $documentType, $text, $excludeDocumentId = 0, $candidateLimit = 280, $matchLimit = 8)
    {
        $config = getTextDuplicateDocumentConfig($documentType);
        if (!$config || !($conn instanceof mysqli)) {
            return [];
        }

        $normalizedQuery = normalizeDocumentDuplicateText($text);
        if ($normalizedQuery === '' || strlen($normalizedQuery) < 80) {
            return [];
        }

        $queryLength = strlen($normalizedQuery);
        $minLength = max(60, (int)floor($queryLength * 0.55));
        $maxLength = max($minLength, (int)ceil($queryLength * 1.60));
        $safeCandidateLimit = max(50, min(500, (int)$candidateLimit));
        $safeMatchLimit = max(1, min(20, (int)$matchLimit));

        $table = $config['table'];
        $titleColumn = $config['title_column'];
        $contentColumn = $config['content_column'];

        $sql = "SELECT id, {$titleColumn} AS title, {$contentColumn} AS content
                FROM {$table}
                WHERE COALESCE(TRIM({$contentColumn}), '') <> ''
                  AND CHAR_LENGTH({$contentColumn}) BETWEEN ? AND ?";
        if ((int)$excludeDocumentId > 0) {
            $sql .= " AND id <> ?";
        }
        $sql .= " ORDER BY id DESC LIMIT ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ((int)$excludeDocumentId > 0) {
            $excludeId = (int)$excludeDocumentId;
            $stmt->bind_param('iiii', $minLength, $maxLength, $excludeId, $safeCandidateLimit);
        } else {
            $stmt->bind_param('iii', $minLength, $maxLength, $safeCandidateLimit);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $queryFingerprint = str_replace(' ', '', $normalizedQuery);
        $queryTokens = buildDocumentDuplicateTokenSet($normalizedQuery);
        $queryTokenCount = count($queryTokens);

        $matches = [];
        foreach ($rows as $row) {
            $candidateId = (int)($row['id'] ?? 0);
            if ($candidateId <= 0) {
                continue;
            }

            $candidateText = normalizeDocumentDuplicateText((string)($row['content'] ?? ''));
            if ($candidateText === '') {
                continue;
            }

            $candidateLength = strlen($candidateText);
            $lengthRatio = min($queryLength, $candidateLength) / max($queryLength, $candidateLength);

            $score = 0.0;
            $matchType = '';

            if ($candidateText === $normalizedQuery) {
                $score = 1.0;
                $matchType = 'exact';
            } else {
                $candidateFingerprint = str_replace(' ', '', $candidateText);
                if ($candidateFingerprint !== '' && $candidateFingerprint === $queryFingerprint) {
                    $score = 0.995;
                    $matchType = 'fingerprint';
                } elseif ($queryTokenCount >= 12 && $lengthRatio >= 0.85) {
                    $candidateTokens = buildDocumentDuplicateTokenSet($candidateText);
                    if (count($candidateTokens) >= 12) {
                        $coverage = calculateDocumentDuplicateCoverage($queryTokens, $candidateTokens);
                        if ($coverage >= 0.92) {
                            $score = min(0.99, ($coverage * 0.85) + ($lengthRatio * 0.15));
                            $matchType = 'token_overlap';
                        }
                    }
                }
            }

            if ($score > 0) {
                $matches[] = [
                    'document_id' => $candidateId,
                    'title' => (string)($row['title'] ?? ('#' . $candidateId)),
                    'similarity' => round($score, 4),
                    'match_type' => $matchType,
                ];
            }
        }

        if (empty($matches)) {
            return [];
        }

        usort($matches, static function ($a, $b) {
            $scoreA = (float)($a['similarity'] ?? 0);
            $scoreB = (float)($b['similarity'] ?? 0);
            if ($scoreA === $scoreB) {
                return ((int)($b['document_id'] ?? 0)) <=> ((int)($a['document_id'] ?? 0));
            }

            return $scoreB <=> $scoreA;
        });

        return array_slice($matches, 0, $safeMatchLimit);
    }
}

if (!function_exists('getTypoSearchDocumentConfig')) {
    function getTypoSearchDocumentConfig($documentType)
    {
        $type = normalizeTextDuplicateDocumentType($documentType);
        if ($type === 'executive_order') {
            return [
                'table' => 'executive_orders',
                'columns' => ['title', 'description', 'reference_number', 'executive_order_number', 'status', 'content', 'uploaded_by'],
            ];
        }
        if ($type === 'resolution') {
            return [
                'table' => 'resolutions',
                'columns' => ['title', 'description', 'reference_number', 'resolution_number', 'content', 'uploaded_by'],
            ];
        }
        if ($type === 'minutes') {
            return [
                'table' => 'minutes_of_meeting',
                'columns' => ['title', 'reference_number', 'session_number', 'content', 'uploaded_by'],
            ];
        }

        return null;
    }
}

if (!function_exists('tokenizeTypoSearchText')) {
    function tokenizeTypoSearchText($value)
    {
        $normalized = normalizeDocumentDuplicateText($value);
        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', trim((string)$normalized));
        if (!is_array($tokens)) {
            return [];
        }

        $result = [];
        foreach ($tokens as $token) {
            $word = trim((string)$token);
            if ($word === '') {
                continue;
            }
            $result[] = $word;
        }

        return $result;
    }
}

if (!function_exists('toAsciiTypoSearchToken')) {
    function toAsciiTypoSearchToken($token)
    {
        $value = trim((string)$token);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false && $converted !== '') {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return trim((string)$value);
    }
}

if (!function_exists('buildTypoTolerantSearchVariants')) {
    function buildTypoTolerantSearchVariants($conn, $documentType, $searchQuery, $rowLimit = 220, $maxDictionaryTokens = 5000)
    {
        $baseQuery = trim((string)$searchQuery);
        if ($baseQuery === '') {
            return [];
        }

        $baseQuery = preg_replace('/\s+/u', ' ', $baseQuery) ?? $baseQuery;
        $variants = [$baseQuery];

        try {
            if (!($conn instanceof mysqli)) {
                return $variants;
            }

            $config = getTypoSearchDocumentConfig($documentType);
            if (!$config) {
                return $variants;
            }

            $queryTokens = tokenizeTypoSearchText($baseQuery);
            if (empty($queryTokens)) {
                return $variants;
            }

        $eligibleQueryTokens = [];
        foreach ($queryTokens as $index => $token) {
            $tokenLength = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($tokenLength < 4 || preg_match('/^\d+$/', $token) === 1) {
                continue;
            }

            $asciiToken = toAsciiTypoSearchToken($token);
            if ($asciiToken === '') {
                continue;
            }
            $eligibleQueryTokens[$index] = $asciiToken;
        }

        if (empty($eligibleQueryTokens)) {
            return $variants;
        }

        $table = (string)($config['table'] ?? '');
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return $variants;
        }

        $columns = $config['columns'] ?? [];
        if (!is_array($columns) || empty($columns)) {
            return $variants;
        }

        $selectParts = [];
        $aliases = [];
        $aliasIndex = 0;
        foreach ($columns as $column) {
            $columnName = trim((string)$column);
            if ($columnName === '' || preg_match('/^[A-Za-z0-9_]+$/', $columnName) !== 1) {
                continue;
            }

            $alias = 'col_' . $aliasIndex;
            $selectParts[] = "LEFT(COALESCE({$columnName}, ''), 450) AS {$alias}";
            $aliases[] = $alias;
            $aliasIndex++;
        }

        if (empty($selectParts)) {
            return $variants;
        }

        $safeRowLimit = max(60, min(500, (int)$rowLimit));
        $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$table} ORDER BY id DESC LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Failed to prepare typo search candidate query for {$table}: " . $conn->error);
            return $variants;
        }

        $stmt->bind_param('i', $safeRowLimit);
        if (!$stmt->execute()) {
            error_log("Failed to execute typo search candidate query for {$table}: " . $stmt->error);
            $stmt->close();
            return $variants;
        }

        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        if (empty($rows)) {
            return $variants;
        }

        $safeDictionaryLimit = max(1000, min(12000, (int)$maxDictionaryTokens));
        $dictionary = [];
        $bucketByFirstAndLength = [];
        $bucketByLength = [];

        foreach ($rows as $row) {
            foreach ($aliases as $alias) {
                $tokens = tokenizeTypoSearchText((string)($row[$alias] ?? ''));
                if (empty($tokens)) {
                    continue;
                }

                foreach ($tokens as $candidateToken) {
                    $candidateLength = function_exists('mb_strlen') ? mb_strlen($candidateToken, 'UTF-8') : strlen($candidateToken);
                    if ($candidateLength < 4 || $candidateLength > 40 || preg_match('/^\d+$/', $candidateToken) === 1) {
                        continue;
                    }

                    $asciiCandidate = toAsciiTypoSearchToken($candidateToken);
                    $asciiLength = strlen($asciiCandidate);
                    if ($asciiCandidate === '' || $asciiLength < 4 || $asciiLength > 40 || preg_match('/^\d+$/', $asciiCandidate) === 1) {
                        continue;
                    }
                    if (isset($dictionary[$asciiCandidate])) {
                        continue;
                    }

                    $dictionary[$asciiCandidate] = true;
                    $firstCharacter = $asciiCandidate[0];

                    if (!isset($bucketByFirstAndLength[$firstCharacter])) {
                        $bucketByFirstAndLength[$firstCharacter] = [];
                    }
                    if (!isset($bucketByFirstAndLength[$firstCharacter][$asciiLength])) {
                        $bucketByFirstAndLength[$firstCharacter][$asciiLength] = [];
                    }
                    $bucketByFirstAndLength[$firstCharacter][$asciiLength][] = $asciiCandidate;

                    if (!isset($bucketByLength[$asciiLength])) {
                        $bucketByLength[$asciiLength] = [];
                    }
                    $bucketByLength[$asciiLength][] = $asciiCandidate;

                    if (count($dictionary) >= $safeDictionaryLimit) {
                        break 3;
                    }
                }
            }
        }

        if (empty($dictionary)) {
            return $variants;
        }

        $correctedTokens = $queryTokens;
        $replacementCount = 0;

        foreach ($eligibleQueryTokens as $tokenIndex => $asciiToken) {
            if (isset($dictionary[$asciiToken])) {
                continue;
            }

            $tokenLength = strlen($asciiToken);
            $maxDistance = $tokenLength >= 9 ? 2 : 1;
            $firstCharacter = $asciiToken[0];
            $candidatePool = [];

            for ($length = max(4, $tokenLength - 1); $length <= min(40, $tokenLength + 1); $length++) {
                if (!isset($bucketByFirstAndLength[$firstCharacter][$length])) {
                    continue;
                }

                foreach ($bucketByFirstAndLength[$firstCharacter][$length] as $candidate) {
                    $candidatePool[$candidate] = true;
                }
            }

            if (empty($candidatePool)) {
                for ($length = max(4, $tokenLength - 1); $length <= min(40, $tokenLength + 1); $length++) {
                    if (!isset($bucketByLength[$length])) {
                        continue;
                    }

                    foreach ($bucketByLength[$length] as $candidate) {
                        $candidatePool[$candidate] = true;
                        if (count($candidatePool) >= 400) {
                            break;
                        }
                    }

                    if (count($candidatePool) >= 400) {
                        break;
                    }
                }
            }

            if (empty($candidatePool)) {
                continue;
            }

            $bestCandidate = '';
            $bestDistance = PHP_INT_MAX;
            foreach (array_keys($candidatePool) as $candidate) {
                $distance = levenshtein($asciiToken, $candidate);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestCandidate = $candidate;
                }
            }

            if ($bestCandidate === '' || $bestDistance > $maxDistance) {
                continue;
            }

            $similarity = 1 - ($bestDistance / max(strlen($asciiToken), strlen($bestCandidate)));
            if ($similarity < 0.70) {
                continue;
            }

            $correctedTokens[$tokenIndex] = $bestCandidate;
            $replacementCount++;
        }

        if ($replacementCount <= 0) {
            return $variants;
        }

        $correctedQuery = trim(implode(' ', $correctedTokens));
        if ($correctedQuery !== '') {
            $correctedQuery = preg_replace('/\s+/u', ' ', $correctedQuery) ?? $correctedQuery;
        }

            if ($correctedQuery !== '' && !in_array($correctedQuery, $variants, true)) {
                $variants[] = $correctedQuery;
            }

            return array_slice($variants, 0, 3);
        } catch (Throwable $e) {
            error_log("Typo search variant generation failed for " . (string)$documentType . ": " . $e->getMessage());
            return $variants;
        }
    }
}

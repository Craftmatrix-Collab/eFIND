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

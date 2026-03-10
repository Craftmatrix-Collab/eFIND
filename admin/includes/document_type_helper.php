<?php

if (!function_exists('normalizeDocumentTypeToken')) {
    function normalizeDocumentTypeToken($value)
    {
        $token = strtolower(trim((string)$value));
        $token = str_replace('-', '_', $token);
        $token = preg_replace('/[^a-z0-9_]/', '', $token) ?? '';
        return trim((string)$token);
    }
}

if (!function_exists('normalizeCanonicalDocumentType')) {
    function normalizeCanonicalDocumentType($value)
    {
        $token = normalizeDocumentTypeToken($value);
        $map = [
            'executive_order' => 'executive_order',
            'executive_orders' => 'executive_order',
            'executiveorder' => 'executive_order',
            'ordinance' => 'executive_order',
            'ordinances' => 'executive_order',
            'resolution' => 'resolution',
            'resolutions' => 'resolution',
            'minutes' => 'minutes',
            'minute' => 'minutes',
            'meeting' => 'minutes',
            'meeting_minutes' => 'minutes',
            'minutes_of_meeting' => 'minutes',
        ];

        return $map[$token] ?? null;
    }
}

if (!function_exists('resolveDocumentTableByCanonicalType')) {
    function resolveDocumentTableByCanonicalType($canonicalType)
    {
        if ($canonicalType === 'executive_order') {
            return 'executive_orders';
        }
        if ($canonicalType === 'resolution') {
            return 'resolutions';
        }
        if ($canonicalType === 'minutes') {
            return 'minutes_of_meeting';
        }

        return null;
    }
}

if (!function_exists('normalizeOcrDocumentType')) {
    function normalizeOcrDocumentType($value)
    {
        return normalizeCanonicalDocumentType($value);
    }
}

if (!function_exists('getDocumentTypeAliases')) {
    function getDocumentTypeAliases($value)
    {
        $canonicalType = normalizeCanonicalDocumentType($value);
        if ($canonicalType === 'executive_order') {
            return ['executive_order', 'executive_orders', 'ordinance', 'ordinances'];
        }
        if ($canonicalType === 'resolution') {
            return ['resolution', 'resolutions'];
        }
        if ($canonicalType === 'minutes') {
            return ['minutes', 'minute', 'meeting', 'meeting_minutes', 'minutes_of_meeting'];
        }

        return [];
    }
}


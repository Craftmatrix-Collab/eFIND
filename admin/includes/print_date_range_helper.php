<?php
if (!function_exists('normalizePrintDateInput')) {
    function normalizePrintDateInput($rawDate, $label)
    {
        $dateValue = trim((string)$rawDate);
        if ($dateValue === '') {
            return ['', null];
        }

        $dateObject = DateTime::createFromFormat('Y-m-d', $dateValue);
        $errors = DateTime::getLastErrors();
        $hasParseErrors = is_array($errors)
            && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0);

        if (!$dateObject || $hasParseErrors || $dateObject->format('Y-m-d') !== $dateValue) {
            return ['', "Invalid {$label} date format. Please use YYYY-MM-DD."];
        }

        return [$dateValue, null];
    }
}

if (!function_exists('getValidatedPrintDateRange')) {
    function getValidatedPrintDateRange($rawStartDate, $rawEndDate)
    {
        [$printStartDate, $startError] = normalizePrintDateInput($rawStartDate, 'start');
        if ($startError !== null) {
            return ['', '', $startError];
        }

        [$printEndDate, $endError] = normalizePrintDateInput($rawEndDate, 'end');
        if ($endError !== null) {
            return ['', '', $endError];
        }

        if ($printStartDate !== '' && $printEndDate !== '' && strcmp($printStartDate, $printEndDate) > 0) {
            return ['', '', 'End date must be after start date.'];
        }

        return [$printStartDate, $printEndDate, null];
    }
}

if (!function_exists('appendPrintDateRangeConditions')) {
    function appendPrintDateRangeConditions($dateColumn, $printStartDate, $printEndDate, &$conditions, &$params, &$types)
    {
        if ($printStartDate !== '' && $printEndDate !== '') {
            $conditions[] = "({$dateColumn} >= ? AND {$dateColumn} < DATE_ADD(?, INTERVAL 1 DAY))";
            $params[] = $printStartDate;
            $params[] = $printEndDate;
            $types .= 'ss';
            return;
        }

        if ($printStartDate !== '') {
            $conditions[] = "{$dateColumn} >= ?";
            $params[] = $printStartDate;
            $types .= 's';
            return;
        }

        if ($printEndDate !== '') {
            $conditions[] = "{$dateColumn} < DATE_ADD(?, INTERVAL 1 DAY)";
            $params[] = $printEndDate;
            $types .= 's';
        }
    }
}

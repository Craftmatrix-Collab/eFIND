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

if (!function_exists('renderPrintDateRangeErrorAndExit')) {
    function renderPrintDateRangeErrorAndExit($errorMessage)
    {
        http_response_code(400);
        $safeMessage = htmlspecialchars((string)$errorMessage, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invalid Print Date Range</title>
    <style>
        body {
            margin: 0;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
        }
        .card {
            max-width: 560px;
            margin: 50px auto;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
        }
        h1 {
            margin: 0 0 12px;
            font-size: 20px;
            color: #b91c1c;
        }
        p {
            margin: 0;
            line-height: 1.5;
        }
        .actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .close-btn {
            background: #2563eb;
            color: #ffffff;
        }
        .back-btn {
            background: #e5e7eb;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Unable to print report</h1>
        <p>' . $safeMessage . '</p>
        <div class="actions">
            <button type="button" class="close-btn" onclick="window.close();">Close</button>
            <button type="button" class="back-btn" onclick="history.back();">Go back</button>
        </div>
    </div>
</body>
</html>';
        exit();
    }
}

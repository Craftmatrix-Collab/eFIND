<?php

if (!function_exists('efind_extract_sender_email')) {
    function efind_extract_sender_email(string $fromEmail): string
    {
        $trimmed = trim($fromEmail);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $trimmed, $matches)) {
            return strtolower(trim((string)$matches[1]));
        }

        return strtolower($trimmed);
    }
}

if (!function_exists('efind_validate_resend_otp_config')) {
    function efind_validate_resend_otp_config(): ?string
    {
        $apiKey = trim((string)(defined('RESEND_API_KEY') ? RESEND_API_KEY : ''));
        $normalizedApiKey = strtolower($apiKey);
        $placeholderApiKeys = [
            'your-resend-api-key-here',
            'your-api-key-here',
            'yourapikeyhere',
            'your-key-here',
            'change-me',
            'changeme',
        ];
        $isPlaceholderSecret = function_exists('efind_is_placeholder_secret') && efind_is_placeholder_secret($apiKey);
        if ($apiKey === '' || in_array($normalizedApiKey, $placeholderApiKeys, true) || $isPlaceholderSecret) {
            return 'Email service is not configured. Set RESEND_API_KEY in .env, then restart the server.';
        }

        $fromEmailRaw = trim((string)(defined('FROM_EMAIL') ? FROM_EMAIL : ''));
        if ($fromEmailRaw === '') {
            return 'Sender email is not configured. Set FROM_EMAIL in .env to a valid sender address.';
        }

        $fromEmailAddress = efind_extract_sender_email($fromEmailRaw);
        if ($fromEmailAddress === '' || !filter_var($fromEmailAddress, FILTER_VALIDATE_EMAIL)) {
            return 'Sender email format is invalid. Update FROM_EMAIL to a valid sender address.';
        }

        $domain = strtolower((string)substr(strrchr($fromEmailAddress, '@') ?: '', 1));
        if (in_array($domain, ['example.com', 'example.org', 'example.net', 'localhost', 'localdomain'], true)) {
            return 'Sender email uses a placeholder domain. Use onboarding@resend.dev (testing) or a verified domain sender.';
        }

        return null;
    }
}

if (!function_exists('efind_resend_otp_error_message')) {
    function efind_resend_otp_error_message(Throwable $exception): string
    {
        $error = strtolower(trim($exception->getMessage()));
        if ($error === '') {
            return 'Failed to send OTP email. Please verify RESEND_API_KEY and FROM_EMAIL configuration.';
        }

        $contains = static function (string $needle) use ($error): bool {
            return strpos($error, $needle) !== false;
        };

        if ($contains('api key') || $contains('unauthorized') || $contains('forbidden') || $contains('invalid access token')) {
            return 'Resend API key is invalid or unauthorized. Update RESEND_API_KEY in .env and restart the server.';
        }

        if (($contains('domain') && $contains('verify')) || $contains('domain is not verified')) {
            return 'Sender domain is not verified in Resend. Verify your domain or use onboarding@resend.dev for testing.';
        }

        if ($contains('testing emails') || $contains('sandbox')) {
            return 'Resend sandbox restriction: onboarding@resend.dev can only send to your own verified email.';
        }

        if ($contains('from') && ($contains('invalid') || $contains('not allowed') || $contains('not verified'))) {
            return 'FROM_EMAIL is not allowed by Resend. Use a verified sender address.';
        }

        return 'Failed to send OTP email. Please verify RESEND_API_KEY and FROM_EMAIL configuration.';
    }
}

if (!function_exists('efind_clear_otp_session_state')) {
    function efind_clear_otp_session_state(array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }
    }
}

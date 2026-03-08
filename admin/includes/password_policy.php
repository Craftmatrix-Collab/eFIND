<?php

if (!function_exists('getPasswordPolicyMinLength')) {
    function getPasswordPolicyMinLength(): int
    {
        return 9;
    }
}

if (!function_exists('getPasswordPolicyHintText')) {
    function getPasswordPolicyHintText(): string
    {
        return sprintf(
            'Minimum %d characters with at least one uppercase letter, one number, and one special character.',
            getPasswordPolicyMinLength()
        );
    }
}

if (!function_exists('getPasswordPolicyClientConfig')) {
    function getPasswordPolicyClientConfig(): array
    {
        return [
            'minLength' => getPasswordPolicyMinLength(),
            'requirements' => [
                'length' => sprintf('At least %d characters', getPasswordPolicyMinLength()),
                'uppercase' => 'One uppercase letter',
                'number' => 'One number',
                'special' => 'One special character',
            ],
            'hint' => getPasswordPolicyHintText(),
        ];
    }
}

if (!function_exists('validatePasswordPolicy')) {
    function validatePasswordPolicy(string $password): array
    {
        $minLength = getPasswordPolicyMinLength();
        $checks = [
            'length' => strlen($password) >= $minLength,
            'uppercase' => preg_match('/[A-Z]/', $password) === 1,
            'number' => preg_match('/[0-9]/', $password) === 1,
            'special' => preg_match('/[^A-Za-z0-9]/', $password) === 1,
        ];

        if (!$checks['length']) {
            return [
                'is_valid' => false,
                'message' => sprintf('Password must be at least %d characters long.', $minLength),
                'checks' => $checks,
            ];
        }

        if (!$checks['uppercase']) {
            return [
                'is_valid' => false,
                'message' => 'Password must include at least one uppercase letter.',
                'checks' => $checks,
            ];
        }

        if (!$checks['number']) {
            return [
                'is_valid' => false,
                'message' => 'Password must include at least one number.',
                'checks' => $checks,
            ];
        }

        if (!$checks['special']) {
            return [
                'is_valid' => false,
                'message' => 'Password must include at least one special character.',
                'checks' => $checks,
            ];
        }

        return [
            'is_valid' => true,
            'message' => '',
            'checks' => $checks,
        ];
    }
}

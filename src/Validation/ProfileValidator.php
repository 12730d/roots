<?php

declare(strict_types=1);

namespace ROOTS\Validation;

/**
 * ProfileValidator - Strict input validation for profile updates
 */
class ProfileValidator
{
    private const USERNAME_REGEX = '/^\w{3,50}$/';
    private const DISPLAY_NAME_MAX = 100;
    private const WALLET_MAX = 255;

    /**
     * Validate username
     *
     * @return array<string, mixed>
     */
    public static function validateUsername(string $username): array
    {
        if (empty($username)) {
            return ['valid' => false, 'error' => 'Username cannot be empty'];
        }

        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['valid' => false, 'error' => 'Username must be 3-50 characters'];
        }

        if (!preg_match(self::USERNAME_REGEX, $username)) {
            return ['valid' => false, 'error' => 'Username contains invalid characters'];
        }

        return ['valid' => true];
    }

    /**
     * Validate display name
     *
     * @return array<string, mixed>
     */
    public static function validateDisplayName(string $name): array
    {
        $trimmed = trim($name);

        if (empty($trimmed)) {
            return ['valid' => false, 'error' => 'Display name cannot be empty'];
        }

        if (strlen($trimmed) > self::DISPLAY_NAME_MAX) {
            return ['valid' => false, 'error' => 'Display name too long'];
        }

        return ['valid' => true, 'value' => $trimmed];
    }

    /**
     * Validate email
     *
     * @return array<string, mixed>
     */
    public static function validateEmail(string $email): array
    {
        $trimmed = trim($email);

        if (empty($trimmed)) {
            return ['valid' => false, 'error' => 'Email cannot be empty'];
        }

        if (!filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'error' => 'Invalid email format'];
        }

        if (strlen($trimmed) > 255) {
            return ['valid' => false, 'error' => 'Email too long'];
        }

        return ['valid' => true, 'value' => $trimmed];
    }

    /**
     * Validate wallet address
     *
     * @return array<string, mixed>
     */
    public static function validateWalletAddress(string $address): array
    {
        $trimmed = trim($address);

        if (empty($trimmed)) {
            return ['valid' => true, 'value' => ''];
        }

        if (strlen($trimmed) > self::WALLET_MAX) {
            return ['valid' => false, 'error' => 'Wallet address too long'];
        }

        // Basic alphanumeric check - adjust based on your wallet format
        if (!preg_match('/^[a-zA-Z0-9]+$/', $trimmed)) {
            return ['valid' => false, 'error' => 'Invalid wallet address format'];
        }

        return ['valid' => true, 'value' => $trimmed];
    }

    /**
     * Validate password strength
     *
     * @return array<string, mixed>
     */
    public static function validatePassword(string $password): array
    {
        if (empty($password)) {
            return ['valid' => false, 'error' => 'Password cannot be empty'];
        }

        if (strlen($password) < 8) {
            return ['valid' => false, 'error' => 'Password must be at least 8 characters'];
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'error' => 'Password must include uppercase, lowercase, and numbers'];
        }

        return ['valid' => true];
    }

    /**
     * Sanitize and validate all profile fields
     *
     * @param array<string, mixed> $data
     * @return array{sanitized: array<string, mixed>, errors: array<string, string>}
     */
    public static function sanitizeProfileData(array $data): array
    {
        $sanitized = [];
        $errors = [];

        // Display name
        if (isset($data['display_name'])) {
            $result = self::validateDisplayName($data['display_name']);
            if ($result['valid']) {
                $sanitized['display_name'] = $result['value'];
            } else {
                $errors['display_name'] = $result['error'];
            }
        }

        // Email
        if (isset($data['email'])) {
            $result = self::validateEmail($data['email']);
            if ($result['valid']) {
                $sanitized['email'] = $result['value'];
            } else {
                $errors['email'] = $result['error'];
            }
        }

        // Wallet
        if (isset($data['wallet_address'])) {
            $result = self::validateWalletAddress($data['wallet_address']);
            if ($result['valid']) {
                $sanitized['wallet_address'] = $result['value'];
            } else {
                $errors['wallet_address'] = $result['error'];
            }
        }

        return ['sanitized' => $sanitized, 'errors' => $errors];
    }
}

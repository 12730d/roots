<?php

declare(strict_types=1);

namespace ROOTS\Services;

use InvalidArgumentException;

/**
 * ValidationService - Input Validation and Sanitization
 *
 * Provides centralized validation methods for common input patterns
 * to prevent SQL injection, XSS, and other input-based vulnerabilities.
 */
class ValidationService
{
    /**
     * Validate and sanitize integer input
     *
     * @param mixed $value The value to validate
     * @param string $name Field name for error messages
     * @param int|null $min Minimum allowed value
     * @param int|null $max Maximum allowed value
     * @return int Validated integer
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateInt(
        mixed $value,
        string $name,
        ?int $min = null,
        ?int $max = null,
    ): int {
        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        if ($filtered === false) {
            throw new InvalidArgumentException("Invalid integer for {$name}");
        }

        if ($min !== null && $filtered < $min) {
            throw new InvalidArgumentException(
                "{$name} must be at least {$min}",
            );
        }

        if ($max !== null && $filtered > $max) {
            throw new InvalidArgumentException(
                "{$name} must not exceed {$max}",
            );
        }

        return $filtered;
    }

    /**
     * Validate and sanitize float input
     *
     * @param mixed $value The value to validate
     * @param string $name Field name for error messages
     * @param float|null $min Minimum allowed value
     * @param float|null $max Maximum allowed value
     * @return float Validated float
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateFloat(
        mixed $value,
        string $name,
        ?float $min = null,
        ?float $max = null,
    ): float {
        $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);

        if ($filtered === false) {
            throw new InvalidArgumentException("Invalid number for {$name}");
        }

        if ($min !== null && $filtered < $min) {
            throw new InvalidArgumentException(
                "{$name} must be at least {$min}",
            );
        }

        if ($max !== null && $filtered > $max) {
            throw new InvalidArgumentException(
                "{$name} must not exceed {$max}",
            );
        }

        return $filtered;
    }

    /**
     * Validate email address
     *
     * @param mixed $value The email to validate
     * @param string $name Field name for error messages
     * @return string Validated email address
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateEmail(mixed $value, string $name = "email"): string
    {
        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);

        if ($filtered === false) {
            throw new InvalidArgumentException("Invalid {$name} address");
        }

        return $filtered;
    }

    /**
     * Validate URL
     *
     * @param mixed $value The URL to validate
     * @param string $name Field name for error messages
     * @return string Validated URL
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateUrl(mixed $value, string $name = "URL"): string
    {
        $filtered = filter_var($value, FILTER_VALIDATE_URL);

        if ($filtered === false) {
            throw new InvalidArgumentException("Invalid {$name}");
        }

        return $filtered;
    }

    /**
     * Sanitize string input
     *
     * Removes HTML tags, trims whitespace, and optionally limits length
     *
     * @param mixed $value The string to sanitize
     * @param int $maxLength Maximum allowed length
     * @param bool $allowHtml Whether to allow HTML tags (they will be escaped)
     * @return string Sanitized string
     */
    public static function sanitizeString(
        mixed $value,
        int $maxLength = 255,
        bool $allowHtml = false,
    ): string {
        $value = trim((string) $value);

        if (!$allowHtml) {
            $value = strip_tags($value);
        }

        $sanitized = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, "UTF-8");

        return substr($sanitized, 0, $maxLength);
    }

    /**
     * Validate string length
     *
     * @param string $value The string to validate
     * @param string $name Field name for error messages
     * @param int|null $min Minimum length
     * @param int|null $max Maximum length
     * @return string Validated string
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateLength(
        string $value,
        string $name,
        ?int $min = null,
        ?int $max = null,
    ): string {
        $length = mb_strlen($value, "UTF-8");

        if ($min !== null && $length < $min) {
            throw new InvalidArgumentException(
                "{$name} must be at least {$min} characters",
            );
        }

        if ($max !== null && $length > $max) {
            throw new InvalidArgumentException(
                "{$name} must not exceed {$max} characters",
            );
        }

        return $value;
    }

    /**
     * Validate that value is in allowed list
     *
     * @param mixed $value The value to validate
     * @param array $allowedValues List of allowed values
     * @param string $name Field name for error messages
     * @return mixed Validated value
     * @throws InvalidArgumentException If validation fails
     */
    /**
     * @param array<int, mixed> $allowedValues
     */
    public static function validateEnum(
        mixed $value,
        array $allowedValues,
        string $name,
    ): mixed {
        if (!in_array($value, $allowedValues, true)) {
            throw new InvalidArgumentException(
                "{$name} must be one of: " . implode(", ", $allowedValues),
            );
        }

        return $value;
    }

    /**
     * Validate date string
     *
     * @param string $value The date string to validate
     * @param string $format Expected date format (default: Y-m-d)
     * @param string $name Field name for error messages
     * @return string Validated date string
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateDate(
        string $value,
        string $format = "Y-m-d",
        string $name = "date",
    ): string {
        $date = \DateTime::createFromFormat($format, $value);

        if (!$date || $date->format($format) !== $value) {
            throw new InvalidArgumentException(
                "Invalid {$name} format. Expected: {$format}",
            );
        }

        return $value;
    }

    /**
     * Validate and sanitize array of integers
     *
     * @param array $values Array of values to validate
     * @param string $name Field name for error messages
     * @return array Array of validated integers
     * @throws InvalidArgumentException If any value is invalid
     */
    /**
     * @param array<int, mixed> $values
     * @return array<int, int>
     */
    public static function validateIntArray(array $values, string $name): array
    {
        $validated = [];

        foreach ($values as $index => $value) {
            $validated[] = self::validateInt($value, "{$name}[{$index}]");
        }

        return $validated;
    }

    /**
     * Sanitize HTML for trusted output
     *
     * Allows specific safe HTML tags while removing potentially dangerous content
     *
     * @param string $html The HTML to sanitize
     * @param array|null $allowedTags List of allowed tags (default: basic formatting tags)
     * @return string Sanitized HTML
     */
    /**
     * @param array<int, string>|null $allowedTags
     */
    public static function sanitizeHtml(
        string $html,
        ?array $allowedTags = null,
    ): string {
        if ($allowedTags === null) {
            $allowedTags = [
                "p",
                "br",
                "strong",
                "em",
                "u",
                "a",
                "ul",
                "ol",
                "li",
            ];
        }

        return strip_tags($html, $allowedTags);
    }

    /**
     * Validate phone number
     *
     * Accepts international format with optional + prefix and digits/spaces/dashes
     *
     * @param string $value The phone number to validate
     * @param string $name Field name for error messages
     * @return string Validated phone number
     * @throws InvalidArgumentException If validation fails
     */
    public static function validatePhone(
        string $value,
        string $name = "phone",
    ): string {
        // Remove common formatting characters
        $cleaned = preg_replace("/[\s\-\(\)]+/", "", $value) ?? $value;

        // Validate format: optional +, then 7-15 digits
        if (!preg_match('/^\+?\d{7,15}$/', $cleaned)) {
            throw new InvalidArgumentException("Invalid {$name} number format");
        }

        return $value; // Return original format
    }

    /**
     * Validate username
     *
     * Alphanumeric plus underscore and dash, 3-30 characters
     *
     * @param string $value The username to validate
     * @param string $name Field name for error messages
     * @return string Validated username
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateUsername(
        string $value,
        string $name = "username",
    ): string {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $value)) {
            throw new InvalidArgumentException(
                "{$name} must be 3-30 characters and contain only letters, numbers, underscores, and hyphens",
            );
        }

        return $value;
    }

    /**
     * Validate password strength
     *
     * Minimum 8 characters, must contain at least one letter and one number
     *
     * @param string $value The password to validate
     * @param int $minLength Minimum password length
     * @return string Validated password
     * @throws InvalidArgumentException If validation fails
     */
    public static function validatePassword(
        string $value,
        int $minLength = 8,
    ): string {
        if (strlen($value) < $minLength) {
            throw new InvalidArgumentException(
                "Password must be at least {$minLength} characters",
            );
        }

        if (!preg_match("/[a-zA-Z]/", $value)) {
            throw new InvalidArgumentException(
                "Password must contain at least one letter",
            );
        }

        if (!preg_match("/\d/", $value)) {
            throw new InvalidArgumentException(
                "Password must contain at least one number",
            );
        }

        return $value;
    }

    /**
     * Sanitize filename
     *
     * Removes path traversal attempts and dangerous characters
     *
     * @param string $filename The filename to sanitize
     * @return string Sanitized filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove path traversal attempts
        $filename = basename($filename);

        // Remove any characters that aren't alphanumeric, dot, dash, or underscore
        $filename = preg_replace("/[^a-zA-Z0-9._-]/", "_", $filename) ?? $filename;

        return $filename;
    }

    /**
     * Validate JSON string
     *
     * @param string $json The JSON string to validate
     * @param string $name Field name for error messages
     * @return array<string, mixed>
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateJson(
        string $json,
        string $name = "JSON",
    ): array {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "{$name} is not valid: " . json_last_error_msg(),
            );
        }

        return $decoded;
    }
}

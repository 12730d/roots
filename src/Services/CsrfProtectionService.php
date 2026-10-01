<?php

namespace ROOTS\Services;

/**
 * CsrfProtectionService - Handles CSRF token generation and validation
 */
class CsrfProtectionService
{
    private static bool $enabled = true;

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Initialize CSRF protection
     */
    public static function initialize(): void
    {
        // CSRF is now primarily handled by the global router.php for script-based pages.
        // This service remains for class-based controllers if needed.
        if (!self::$enabled) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            if (class_exists('\ROOTS\Auth\Session')) {
                \ROOTS\Auth\Session::start();
            } else {
                session_start();
            }
        }

        // The router already validates CSRF for POST requests.
        // We only ensure the token exists here.
        if (!isset($_SESSION["csrf_token"])) {
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            $_SESSION["csrf_token_time"] = time();
        }
    }

    /**
     * Get current CSRF token
     */
    public static function getToken(): ?string
    {
        return $_SESSION["csrf_token"] ?? null;
    }

    /**
     * Generate new CSRF token
     */
    public static function regenerateToken(): string
    {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
        $_SESSION["csrf_token_time"] = time();
        return $_SESSION["csrf_token"];
    }
}

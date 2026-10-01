<?php

declare(strict_types=1);

namespace ROOTS\Middleware;

/**
 * SecurityHeadersMiddleware - Centralized security header management
 *
 * Applies consistent security headers across the application
 */
class SecurityHeadersMiddleware
{
    private const CSP_SELF = "'self'";
    private static bool $applied = false;

    /**
     * Apply security headers once per request
     */
    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }

        $isOnion = self::isOnionService();
        $isHttps = self::isHttps();

        // Basic security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy
        self::applyCsp($isOnion);

        // HSTS for HTTPS non-onion
        if (!$isOnion && $isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        self::$applied = true;
    }

    /**
     * Get CSP nonce for use in inline scripts
     * @return string The CSP nonce or empty string if not available
     */
    public static function getNonce(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['csp_nonce'])) {
            return $_SESSION['csp_nonce'];
        }
        return '';
    }

    /**
     * Get CSP nonce attribute for HTML elements
     * @return string The nonce attribute (e.g., 'nonce-abc123')
     */
    public static function getNonceAttribute(): string
    {
        $nonce = self::getNonce();
        return $nonce ? "nonce=\"{$nonce}\"" : '';
    }

    /**
     * Detect if running on Onion service
     */
    private static function isOnionService(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        return str_ends_with(strtolower($host), '.onion');
    }

    /**
     * Detect if HTTPS
     */
    private static function isHttps(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    }

    /**
     * Apply Content Security Policy based on context
     * Enhanced security with nonce-based inline script protection
     */
    private static function applyCsp(bool $isOnion): void
    {
        // Generate a nonce for this request (if session exists)
        $nonce = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['csp_nonce'])) {
                $_SESSION['csp_nonce'] = bin2hex(random_bytes(16));
            }
            $nonce = "'nonce-" . $_SESSION['csp_nonce'] . "'";
        }

        $directives = [
            'default-src' => self::CSP_SELF . " https:",
            'script-src' => self::CSP_SELF . " " . $nonce . " 'unsafe-inline' 'unsafe-eval' https:",
            'style-src' => self::CSP_SELF . " 'unsafe-inline' https:",
            'img-src' => $isOnion ? self::CSP_SELF . " data: http: https:" : self::CSP_SELF . " data: https:",
            'font-src' => self::CSP_SELF . " data: https:",
            'connect-src' => self::CSP_SELF . " https:",
            'frame-ancestors' => self::CSP_SELF,
            'base-uri' => self::CSP_SELF,
            'form-action' => self::CSP_SELF,
            'object-src' => "'none'",
        ];

        $csp = [];
        foreach ($directives as $name => $value) {
            $csp[] = "{$name} {$value}";
        }

        header('Content-Security-Policy: ' . implode('; ', $csp));
    }
}

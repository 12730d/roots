<?php

declare(strict_types=1);

namespace ROOTS\Security;

/**
 * CSRF Protection System
 * Provides CSRF token generation and validation with token rotation
 */
class CsrfProtection
{
    private const ROTATION_INTERVAL = 3600; // 1 hour (Increased from 5m for better UX)
    private const SESSION_MANAGER_CLASS = '\ROOTS\Services\SessionManager';
    private const SESSION_AUTH_CLASS = '\ROOTS\Auth\Session';
    private const TYPE_JSON = 'application/json';

    /**
     * Generate and store CSRF token in session with rotation
     * Token rotates every 5 minutes for enhanced security
     * @return string The generated CSRF token
     */
    public static function generateToken(): string
    {
        self::ensureSessionStarted();

        if (self::shouldSkipRotation() && isset($_SESSION['csrf_token'])) {
            self::synchronizeCookieWithToken();
            return $_SESSION['csrf_token'];
        }

        $now = time();

        // Check if token needs rotation
        if (self::needsRotation($now)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = $now;
            self::setCsrfCookie($_SESSION['csrf_token'], $now);
        } else {
            self::synchronizeCookieWithToken();
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Get current CSRF token
     * @return string|null The CSRF token or null if not set
     */
    public static function getToken(): ?string
    {
        self::ensureSessionStarted();
        return $_SESSION['csrf_token'] ?? null;
    }

    /**
     * Verify CSRF token from POST request or headers
     * Supports both form field and header-based tokens
     * Implements double-submit cookie pattern for enhanced security
     * @param string|null $token Token to verify (if null, gets from POST or headers)
     * @return bool True if valid, false otherwise
     */
    public static function verifyToken(?string $token = null): bool
    {
        self::ensureSessionStarted();

        if ($token === null) {
            $token = self::getTokenFromRequest();
        }

        // Check if token and session token exist, and if primary validation passes
        if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }

        // Double-submit cookie validation (additional layer)
        $cookieToken = $_COOKIE['csrf_cookie'] ?? '';
        if (!empty($cookieToken) && !hash_equals($_SESSION['csrf_token'], $cookieToken)) {
            self::logSecurityWarning('CSRF double-submit cookie mismatch');
            return false;
        }

        return true;
    }

    /**
     * Require valid CSRF token for POST requests
     * Dies with error if invalid
     */
    public static function requireToken(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !self::verifyToken()) {
            http_response_code(403);
            self::logSecurityWarning('CSRF token validation failed');

            if (self::isAjaxRequest()) {
                header('Content-Type: ' . self::TYPE_JSON);
                echo json_encode(['success' => false, 'message' => 'CSRF token validation failed']);
                exit;
            }

            self::showErrorPage();
        }
    }

    /**
     * Ensure session is started using available handlers
     */
    private static function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        if (class_exists(self::SESSION_MANAGER_CLASS)) {
            (self::SESSION_MANAGER_CLASS)::initialize();
        } elseif (class_exists(self::SESSION_AUTH_CLASS)) {
            (self::SESSION_AUTH_CLASS)::start();
        } else {
            session_name("ROOTS_SESSION");
            session_start();
        }
    }

    /**
     * Detect if current request is AJAX or JSON
     */
    private static function isAjaxRequest(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) ||
               (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], self::TYPE_JSON) !== false) ||
               (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], self::TYPE_JSON) !== false);
    }

    /**
     * Determine if token rotation should be skipped
     */
    private static function shouldSkipRotation(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST' || self::isAjaxRequest();
    }

    /**
     * Check if the CSRF token needs to be rotated
     */
    private static function needsRotation(int $now): bool
    {
        return !isset($_SESSION['csrf_token']) ||
               !isset($_SESSION['csrf_token_time']) ||
               ($now - $_SESSION['csrf_token_time']) > self::ROTATION_INTERVAL;
    }

    /**
     * Set the CSRF double-submit cookie
     */
    private static function setCsrfCookie(string $token, int $now): void
    {
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie('csrf_cookie', $token, [
            'expires' => $now + self::ROTATION_INTERVAL,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => $secure ? 'Strict' : 'Lax'
        ]);
    }

    private static function synchronizeCookieWithToken(): void
    {
        if (headers_sent() || !isset($_SESSION['csrf_token'])) {
            return;
        }

        $cookieToken = $_COOKIE['csrf_cookie'] ?? '';
        if (empty($cookieToken) || !hash_equals($_SESSION['csrf_token'], $cookieToken)) {
            $now = time();
            self::setCsrfCookie($_SESSION['csrf_token'], $now);
        }
    }

    /**
     * Extract token from various request sources
     */
    private static function getTokenFromRequest(): string
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (empty($token) && isset($GLOBALS['ROOTS_REQUEST_DATA']['csrf_token'])) {
            return (string)$GLOBALS['ROOTS_REQUEST_DATA']['csrf_token'];
        }

        return $token;
    }

    /**
     * Log a security warning with context
     */
    private static function logSecurityWarning(string $reason): void
    {
        $user = $_SESSION['username'] ?? 'anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        error_log("{$reason} for user: {$user} from IP: {$ip}");
    }

    /**
     * Show the security error page
     */
    private static function showErrorPage(): never
    {
        die('<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Error</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 50px; text-align: center; }
        .error-box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        h1 { color: #e74c3c; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>⚠️ Security Error</h1>
        <p>CSRF Token validation failed.</p>
        <p>Please <a href="javascript:history.back()">go back</a> and try again.</p>
    </div>
</body>
</html>');
    }

    /**
     * Generate hidden CSRF token input field for forms
     * @return string HTML input field
     */
    public static function tokenField(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Get CSRF token as meta tag for AJAX requests
     * @return string HTML meta tag
     */
    public static function tokenMeta(): string
    {
        $token = self::generateToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

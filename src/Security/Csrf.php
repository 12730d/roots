<?php

declare(strict_types=1);

namespace ROOTS\Security;

/**
 * CSRF Protection System
 * Provides CSRF token generation and validation with token rotation
 */
class Csrf
{
    /**
     * Generate and store CSRF token in session with rotation
     * Token rotates every 5 minutes for enhanced security
     * @return string The generated CSRF token
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Prevent rotation on POST requests to avoid race conditions during validation
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['csrf_token'])) {
            return $_SESSION['csrf_token'];
        }

        $rotationInterval = 300; // 5 minutes
        $now = time();

        // Check if token needs rotation
        if (
            !isset($_SESSION['csrf_token']) ||
            !isset($_SESSION['csrf_token_time']) ||
            ($now - $_SESSION['csrf_token_time']) > $rotationInterval
        ) {

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = $now;

            // Set double-submit cookie for additional protection (only if headers not sent)
            if (!headers_sent()) {
                if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                    setcookie('csrf_cookie', $_SESSION['csrf_token'], [
                        'expires' => $now + $rotationInterval,
                        'path' => '/',
                        'domain' => '',
                        'secure' => true,
                        'httponly' => false, // Must be readable by JavaScript for double-submit
                        'samesite' => 'Strict'
                    ]);
                } else {
                    setcookie('csrf_cookie', $_SESSION['csrf_token'], [
                        'expires' => $now + $rotationInterval,
                        'path' => '/',
                        'domain' => '',
                        'secure' => false,
                        'httponly' => false,
                        'samesite' => 'Strict'
                    ]);
                }
            } else {
                // Headers already sent; log for diagnostics. Cookie will be set on next request.
                error_log('CSRF cookie not sent — headers already sent for ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
            }
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Get current CSRF token
     * @return string|null The CSRF token or null if not set
     */
    public static function getToken(): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($token === null) {
            // Try POST field first, then header
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }

        if (empty($token) || !isset($_SESSION['csrf_token'])) {
            return false;
        }

        // Primary validation: session token must match
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }

        // Double-submit cookie validation (additional layer)
        $cookieToken = $_COOKIE['csrf_cookie'] ?? '';
        if (!empty($cookieToken) && !hash_equals($_SESSION['csrf_token'], $cookieToken)) {
            // Cookie doesn't match session token - possible attack
            error_log('CSRF double-submit cookie mismatch for user: ' . ($_SESSION['username'] ?? 'anonymous') . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::verifyToken()) {
                http_response_code(403);
                error_log('CSRF token validation failed for user: ' . ($_SESSION['username'] ?? 'anonymous') . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                // If AJAX request, return JSON
                $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                          (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) ||
                          (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                          (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'CSRF token validation failed']);
                    exit;
                }

                // Otherwise show error page
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
        }
    }

    /**
     * Generate hidden CSRF token input field for forms
     * @return string HTML input field
     */
    public static function getTokenField(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Get CSRF token as meta tag for AJAX requests
     * @return string HTML meta tag
     */
    public static function getTokenMeta(): string
    {
        $token = self::generateToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

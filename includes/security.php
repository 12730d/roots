<?php

/**
 * Security Helper Functions
 * Provides security headers, rate limiting, and other security utilities
 */

/**
 * Set security headers for all pages
 * @return void
 */
function setSecurityHeaders(): void
{
    // Prevent clickjacking
    header("X-Frame-Options: DENY");

    // Prevent MIME sniffing
    header("X-Content-Type-Options: nosniff");

    // XSS Protection (legacy browsers)
    header("X-XSS-Protection: 1; mode=block");

    // Content Security Policy
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self' https://cdnjs.cloudflare.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';",
    );

    // Referrer Policy
    header("Referrer-Policy: strict-origin-when-cross-origin");

    // Permissions Policy
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

    // Force HTTPS (if on production)
    if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        header(
            "Strict-Transport-Security: max-age=31536000; includeSubDomains; preload",
        );
    }
}

/**
 * Simple rate limiting implementation
 * @param string $action Action identifier
 * @param int $max_attempts Maximum attempts allowed
 * @param int $window Time window in seconds
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimit(string $action, int $max_attempts = 5, int $window = 300): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session settings before starting
        ini_set("session.cookie_httponly", "1");
        ini_set("session.use_only_cookies", "1");
        ini_set("session.cookie_samesite", "Strict");
        ini_set("session.use_strict_mode", "1");
        ini_set("session.use_trans_sid", "0");
        ini_set("session.gc_maxlifetime", "1800");
        if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
            ini_set("session.cookie_secure", "1");
        }
        session_name("ROOTS_SESSION");
        session_start();
    }

    $username = $_SESSION["username"] ?? ($_SERVER["REMOTE_ADDR"] ?? "unknown");
    $key = "rate_limit:{$action}:{$username}";

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ["count" => 0, "time" => time()];
    }

    $data = $_SESSION[$key];

    // Reset if window expired
    if (time() - $data["time"] > $window) {
        $_SESSION[$key] = ["count" => 1, "time" => time()];
        return true;
    }

    // Check if exceeded
    if ($data["count"] >= $max_attempts) {
        return false;
    }

    // Increment counter
    $_SESSION[$key]["count"]++;
    return true;
}

/**
 * Enforce rate limit or die
 * @param string $action Action identifier
 * @param int $max_attempts Maximum attempts allowed
 * @param int $window Time window in seconds
 * @return void
 */
function requireRateLimit(string $action, int $max_attempts = 5, int $window = 300): void
{
    if (!checkRateLimit($action, $max_attempts, $window)) {
        http_response_code(429);

        // Log rate limit violation
        error_log(
            "Rate limit exceeded for action: {$action}, user: " .
                ($_SESSION["username"] ?? "anonymous") .
                ", IP: " .
                ($_SERVER["REMOTE_ADDR"] ?? "unknown"),
        );

        // If AJAX, return JSON
        if (
            !empty($_SERVER["HTTP_X_REQUESTED_WITH"]) &&
            strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest"
        ) {
            echo json_encode([
                "success" => false,
                "message" => "Too many requests. Please try again later.",
            ]);
            exit();
        }

        die('<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rate Limit Exceeded</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 50px; text-align: center; }
        .error-box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        h1 { color: #f39c12; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>⏱️ Rate Limit Exceeded</h1>
        <p>You have exceeded the maximum number of attempts allowed.</p>
        <p>Please wait a while before trying again.</p>
    </div>
</body>
</html>');
    }
}

/**
 * Validate password strength
 * @param string $password Password to validate
 * @return string|null Error message or null if valid
 */
function validatePasswordStrength(string $password): ?string
{
    $error = null;

    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif (!preg_match("/[A-Z]/", $password)) {
        $error = "Password must contain at least one uppercase letter";
    } elseif (!preg_match("/[a-z]/", $password)) {
        $error = "Password must contain at least one lowercase letter";
    } elseif (!preg_match("/\d/", $password)) {
        $error = "Password must contain at least one number";
    } elseif (!preg_match("/[^A-Za-z0-9]/", $password)) {
        $error = "Password must contain at least one special character";
    }

    return $error;
}

/**
 * Sanitize and validate filename
 * @param string $filename Filename to sanitize
 * @param int $maxLength Maximum allowed length
 * @return string|null Sanitized filename or null if invalid
 */
function sanitizeFilename(string $filename, int $maxLength = 255): ?string
{
    // Remove path components
    $filename = basename($filename);

    // Check length
    if (mb_strlen($filename) > $maxLength) {
        return null;
    }

    // Remove dangerous characters
    $filename = preg_replace("/[^a-zA-Z0-9._\-]/", "_", $filename) ?? '';

    // Prevent double extensions that might bypass filters
    $filename = preg_replace("/\.{2,}/", ".", $filename);

    return $filename;
}

if (!function_exists('logSecurityEvent')) {
    /**
     * Log security events
     * @param string $event Event type
     * @param array<mixed> $details Additional details
     * @return void
     */
    function logSecurityEvent(string $event, array $details = []): void
    {
        // Logging disabled
    }
}

<?php

// SECURITY FIX: Configure secure session parameters before starting session
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session parameters
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '1800');
    ini_set('session.use_trans_sid', '0'); // Never pass session ID in URL

    // Enable HTTPS-only cookies if on HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }

    // Set session name before starting (consistent across application)
    session_name('ROOTS_SESSION');

    session_start();

    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        // Session expired
        session_unset();
        session_destroy();
        session_start();
    }


    // Update last activity time
    $_SESSION['last_activity'] = time();
}

// Common session-related functions
define('LOGIN_REDIRECT_URL', '/login');
define('LOCATION_HEADER', 'Location: ');
function isLoggedIn(): bool
{
    return isset($_SESSION["username"]);
}

function getUserRole(): ?string
{
    if (!isLoggedIn()) {
        return null; // Return null if not logged in
    }

    // Use Database class to get connection
    // Ensure autoload is loaded in the entry point file (e.g. index.php) or require it here if this is a standalone utility
    // Assuming Autoload is loaded by the caller or we can require it safely
    if (!class_exists('ROOTS\Config\Database')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    $result = null;
    $db = \ROOTS\Config\Database::getConnection();

    if ($db) {
        // query 'login' table for 'subscription'
        $stmt = mysqli_prepare($db, "SELECT subscription FROM login WHERE username = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $_SESSION["username"]);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res !== false) {
                $user = mysqli_fetch_assoc($res);
            } else {
                $user = null;
            }
            if ($user && !empty($user['subscription'])) {
                $result = (string) $user['subscription'];
            } else {
                $result = 'user';
            }
            mysqli_stmt_close($stmt);
        }
        // Connection is managed by the class, better not close it if it's shared, or close if we know we are done.
        // The original code closed it. Let's keep it open if it's shared, or check Database class implementation.
        // Assuming Database class manages connection reuse.
    }

    return $result;
}

function isAdmin(): bool
{
    $role = getUserRole();
    return $role === 'admin';
}

function isPup(): bool
{
    return isLoggedIn(); // All logged in users are considered "pup"
}

function requirePup(): void
{
    if (!isPup()) {
        header(LOCATION_HEADER . LOGIN_REDIRECT_URL);
        exit();
    }
}

function requireAdminOrPup(): void
{
    if (!isAdmin() && !isPup()) {
        header(LOCATION_HEADER . LOGIN_REDIRECT_URL);
        exit();
    }
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header(LOCATION_HEADER . LOGIN_REDIRECT_URL);
        exit();
    }
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        header(LOCATION_HEADER . LOGIN_REDIRECT_URL);
        exit();
    }
}

/**
 * Regenerate session ID to prevent session fixation
 * Call this after successful login
 * @return void
 */
function regenerateSessionId(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Regenerate session ID and delete old session
    session_regenerate_id(true);

    // Log the regeneration for security audit
    error_log('Session ID regenerated for user: ' . ($_SESSION['username'] ?? 'unknown') . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

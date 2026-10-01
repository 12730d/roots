<?php
// PERF: compress output (Gzip) for faster page delivery
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}
/**
 * SECURE LOGIN PAGE - HARDENED VERSION WITH ADVANCED SECURITY
 *
 * This is an example of a security-hardened login page implementing
 * all critical security recommendations from the security audit.
 *
 * Advanced Security Features:
 * - IP Rate Limiting
 * - Account Lockout Protection
 * - Remember Me (Secure Tokens)
 * - Comprehensive Security Logging
 *
 * DO NOT USE IN PRODUCTION WITHOUT THOROUGH TESTING
 */

// Use Security Classes
use ROOTS\Security\SecurityLogger;
use ROOTS\Security\RateLimiter;
use ROOTS\Security\AccountLockout;
use ROOTS\Security\RememberMe;
use ROOTS\Security\FailedLoginProtection;
use ROOTS\Config\Database;
use ROOTS\Config\EnvLoader;
use ROOTS\Exceptions\ValidationException;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use ROOTS\Auth\AuthHandler;

// Bootstrap Composer autoload for this entry point (cannot be replaced by `use`)
$composerLoader = require __DIR__ . "/vendor/autoload.php"; // NOSONAR

// Securimage classmap loader removed as it's now handled by Composer.

// Load environment variables from .env when present (PSR-4 via Composer autoload)
EnvLoader::load();

// ============================================================================
// GLOBAL CONSTANTS
// ============================================================================
define("ONION_REGEX", "/\.onion$/i");
if (!defined("CONTENT_TYPE_JSON")) {
    define("CONTENT_TYPE_JSON", "Content-Type: application/json; charset=UTF-8");
}
define("DEFAULT_IP_FALLBACK", "0.0.0.0");

// ============================================================================
// ONION SERVICE DETECTION & HTTPS REDIRECT (CRITICAL FIX #1.4)
// ============================================================================
$httpHost = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$serverName = isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$isOnionService = preg_match(ONION_REGEX, $httpHost) > 0 || preg_match(ONION_REGEX, $serverName) > 0;

// The user is using Tor with a TLS layer, so application-level HTTPS redirect is disabled.
// if (!$isOnionService && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
//     $location = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
//     header('HTTP/1.1 301 Moved Permanently');
//     header('Location: ' . $location);
//     exit();
// }

// ============================================================================
// CHECK IF USER IS ALREADY LOGGED IN - REDIRECT TO DASHBOARD
// ============================================================================
if (isset($_SESSION["username"]) && !empty($_SESSION["username"]) && isset($_SESSION["user_id"]) && !empty($_SESSION["user_id"]) && !headers_sent()) {
    // User is already logged in - redirect to dashboard instead of showing login form
    header("Location: /dashboard");
    exit();
}

// ============================================================================
// SESSION CONFIGURATION
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session parameters BEFORE session_start()
    ini_set("session.cookie_httponly", "1"); // Prevent XSS cookie theft
    ini_set("session.use_only_cookies", "1"); // Prevent session fixation via URL
    ini_set("session.cookie_samesite", "Strict"); // CSRF protection
    ini_set("session.use_strict_mode", "1"); // Reject uninitialized session IDs
    ini_set("session.use_trans_sid", "0"); // Never pass session ID in URL
    ini_set("session.gc_maxlifetime", "1800"); // 30 minutes

    // For Onion services: Tor provides transport security, so secure flag is not needed
    // For HTTPS connections: Always set secure flag
    // This ensures compatibility with both Onion and HTTPS access
    if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        ini_set("session.cookie_secure", "1");
    } elseif ($isOnionService) {
        // For Onion: Trust Tor's encryption, set HttpOnly (already done above)
        // Session cookies are protected by Tor's transport layer
    }

    // Set session name before starting
    session_name("ROOTS_SESSION");
    session_start();

    // Initialize login attempts counter if not set
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
    }
}

// BANNED SESSION ENFORCEMENT
// TEMPORARILY DISABLED FOR DEVELOPMENT
// if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 15) {
//     $_SESSION['session_banned'] = true;
// }
//
// if (isset($_SESSION['session_banned']) && $_SESSION['session_banned'] === true) {
//     header("Location: /blocked_403?code=403&reason=SESSION_BANNED");
//     exit();
// }

/**
 * Validate CSRF token for CAPTCHA refresh request.
 */
function validateCaptchaCsrfToken(): bool
{
    $csrfToken = isset($_GET['csrf_token']) && is_string($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
    $sessionCsrfToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    return !empty($csrfToken) && hash_equals($sessionCsrfToken, $csrfToken);
}

/**
 * Validate CORS/referer for CAPTCHA refresh request.
 */
function validateCaptchaCors(): bool
{
    $referer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if (empty($referer)) {
        return true;
    }

    $refererHost = parse_url($referer, PHP_URL_HOST);
    $currentHost = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    return $refererHost === $currentHost;
}

/**
 * Check rate limit for CAPTCHA refresh requests.
 * @return bool True if rate limit exceeded, false otherwise
 */
function checkCaptchaRateLimit(): bool
{
    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : DEFAULT_IP_FALLBACK;
    $cacheKey = 'captcha_ajax_' . $remoteAddr;

    if (!isset($_SESSION[$cacheKey])) {
        $_SESSION[$cacheKey] = ['count' => 0, 'time' => time()];
        return false;
    }

    $captchaData = $_SESSION[$cacheKey];
    $isWithinTimeWindow = is_array($captchaData) && isset($captchaData['time']) && is_int($captchaData['time']) && time() - $captchaData['time'] < 60;

    if ($isWithinTimeWindow) {
        $captchaData['count'] = isset($captchaData['count']) && is_int($captchaData['count']) ? $captchaData['count'] + 1 : 1;
        $_SESSION[$cacheKey] = $captchaData;
        return $captchaData['count'] > 5;
    }

    $_SESSION[$cacheKey] = ['count' => 1, 'time' => time()];
    return false;
}

/**
 * Lightweight CAPTCHA refresh for AJAX (avoids full page bootstrap).
 * CRITICAL FIX #1.5: Added CSRF token validation and CORS protection
 */
function handleLoginCaptchaRefreshAjax(): void
{
    // Verify this is a CAPTCHA refresh request
    if (!isset($_GET['ajax']) || $_GET['ajax'] !== 'refresh_captcha') {
        return;
    }

    // CRITICAL: CSRF Token Validation (FIX #1.5)
    if (!validateCaptchaCsrfToken()) {
        http_response_code(403);
        header(CONTENT_TYPE_JSON);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    // MEDIUM FIX #3.3: CORS Protection - Verify referer origin
    if (!validateCaptchaCors()) {
        http_response_code(403);
        header(CONTENT_TYPE_JSON);
        echo json_encode(['success' => false, 'error' => 'Cross-origin request denied']);
        exit;
    }

    // Rate limit AJAX CAPTCHA requests (max 5 per minute per IP)
    if (checkCaptchaRateLimit()) {
        http_response_code(429);
        header(CONTENT_TYPE_JSON);
        echo json_encode(['success' => false, 'error' => 'Too many requests']);
        exit;
    }

    $phraseBuilder = new PhraseBuilder(5, '0123456789');
    $captchaBuilder = new CaptchaBuilder(null, $phraseBuilder);
    $captchaBuilder->setMaxBehindLines(0);
    $captchaBuilder->setMaxFrontLines(0);
    $captchaBuilder->build();
    $_SESSION['captcha_phrase'] = $captchaBuilder->getPhrase();

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo json_encode([
        'success' => true,
        'image' => $captchaBuilder->inline(),
    ]);
    exit;
}

handleLoginCaptchaRefreshAjax();

// If user is already logged in, check ban status first
// TEMPORARILY DISABLED FOR DEVELOPMENT
// if (isset($_SESSION["username"]) && !empty($_SESSION["username"])) {
//     // Check if user is banned and redirect to blocked
//     if (isset($_SESSION["user_id"]) && \ROOTS\Auth\BanSystem::isBanned((int)$_SESSION["user_id"])) {
//         header("Location: /blocked");
//         exit();
//     }
//     // TEMPORARILY DISABLE AUTO-REDIRECT TO ALLOW LOGIN PAGE ACCESS
//     // header("Location: /index");
//     // exit();
// }

// ============================================================================
// SECURITY HEADERS (MEDIUM FIX #3.2) - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Set Content-Security-Policy based on connection type
// For Onion: Allow http (self), for HTTPS: restrict to https only
if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src \'self\' data: http:;',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src \'self\' data: https:;',
    );
}

// Set HSTS header only for non-Onion HTTPS connections
// Onion services don't benefit from HSTS, but HTTPS on Onion can use it
if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// ============================================================================
// CSRF TOKEN GENERATION (CRITICAL FIX #1.1)
// ============================================================================
if (empty($_SESSION["csrf_token"])) {
    try {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Fallback for older PHP versions
        $_SESSION["csrf_token"] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// Snapshot the current captcha phrase for POST validation.
// IMPORTANT: Do not regenerate captcha_phrase before validation, or validation will always fail.
$captchaPhraseForValidation = $_SESSION['captcha_phrase'] ?? null;

// Get database connection (via Composer autoload + `use ROOTS\Config\Database;`)
$con = Database::getConnection();
// ============================================================================
// LOGGING HELPER FUNCTION
// ============================================================================
/**
 * Log security events using syslog (avoids nginx error log pollution)
 * Log non-security events to file
 */
if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent(string $message, string $channel = "security"): void
    {
        $isSecurity = $channel === "security";
        if ($isSecurity && function_exists("syslog")) {
            syslog(LOG_WARNING, $message);
        } else {
            // File-based logging disabled
        }
    }
}

// Validate connection
if ($con === null || !($con instanceof mysqli)) {
    logSecurityEvent("[LOGIN] No valid mysqli connection available", "file");
    $con = null;
    // Stop execution if connection is null to prevent errors
    die("Database connection failed. Please try again later.");
}

// ============================================================================
// ADVANCED SECURITY COMPONENTS INITIALIZATION
// ============================================================================
$securityLogger = null;
$rateLimiter = null;
$accountLockout = null;
$rememberMe = null;

try {
    $securityLogger = new SecurityLogger($con);
    $rateLimiter = new RateLimiter($con, $securityLogger);
    $accountLockout = new AccountLockout($con, $securityLogger);
    $rememberMe = new RememberMe($con, $securityLogger);
} catch (Exception $e) {
    logSecurityEvent(
        "[SECURITY] Failed to initialize security components: " .
        $e->getMessage(),
        "file",
    );
}

// Get client IP address
$ipAddress = $_SERVER["REMOTE_ADDR"] ?? DEFAULT_IP_FALLBACK;
$userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "Unknown";

// ============================================================================
// REMEMBER ME - AUTO LOGIN CHECK (SECURE VERSION)
// ============================================================================
if (!isset($_SESSION["username"]) && $rememberMe !== null) {
    try {
        $cookie = $rememberMe->getCookie();

        if ($cookie) {
            $tokenData = $rememberMe->validateToken($cookie);

            // Validate token data structure BEFORE any action
            if (!is_array($tokenData) || empty($tokenData['user_id']) || empty($tokenData['username'])) {
                $rememberMe->deleteCookie();
                logSecurityEvent("[SECURITY] Invalid token structure - cookie cleared", "security");
                throw new ValidationException("Invalid token structure");
            }

            // Sanitize token data
            $tokenUserId = isset($tokenData['user_id']) && is_numeric($tokenData['user_id']) ? (int) $tokenData['user_id'] : 0;
            $tokenUsername = isset($tokenData['username']) && is_string($tokenData['username']) ? $tokenData['username'] : '';

            // CRITICAL: Check ban status BEFORE creating session
            $banData = \ROOTS\Auth\BanSystem::getBanDetails($tokenUserId);
            $isSuspended = !empty($banData['suspended']);
            $banUntil = isset($banData['ban_until']) && is_string($banData['ban_until']) ? $banData['ban_until'] : null;
            $isTempBanned = !empty($banUntil) && strtotime($banUntil) > time();

            if ($isSuspended || $isTempBanned) {
                // User is banned - clear cookie and redirect to blocked page
                $rememberMe->deleteCookie();
                logSecurityEvent(
                    "[SECURITY] Banned user attempted auto-login - user_id: {$tokenUserId}, suspended: " . ($isSuspended ? 'yes' : 'no') . ", temp_ban: " . ($isTempBanned ? 'yes' : 'no'),
                    "security"
                );
                // TEMPORARILY DISABLED FOR DEVELOPMENT
                // header("Location: /blocked");
                exit();
            }

            // All validations passed - create session
            session_regenerate_id(true);
            $_SESSION["username"] = $tokenUsername;
            $_SESSION["user_id"] = $tokenUserId;
            $_SESSION["auto_login"] = true;

            // Rotate token for security
            $newToken = $rememberMe->rotateToken($cookie, $tokenUserId, $tokenUsername);
            if ($newToken && isset($newToken["token"]) && is_string($newToken["token"])) {
                $rememberMe->setCookie($newToken["token"]);
            }

            // Validate redirect destination using allow list
            $allowedRedirects = ["index", "blocked", "blocked_403"];
            $redirect = "index";

            if (!in_array($redirect, $allowedRedirects)) {
                $redirect = "index";
            }

            header("Location: /" . ltrim($redirect, "/"));
            exit();
        }
    } catch (Exception $e) {
        logSecurityEvent(
            "[SECURITY] Remember Me validation error: " . $e->getMessage(),
            "file",
        );
    }
}

// ============================================================================
// IP RATE LIMITING CHECK
// ============================================================================
$blocked = false;
$remaining_time = 0;
$blockReason = "";

if ($rateLimiter !== null) {
    try {
        $ipAddress = is_string($ipAddress) ? $ipAddress : DEFAULT_IP_FALLBACK;
        $ipStatus = $rateLimiter->isBlocked($ipAddress);
        if ($ipStatus["blocked"]) {
            $blocked = true;
            $remaining_time = isset($ipStatus["remaining_time"]) && is_numeric($ipStatus["remaining_time"]) ? $ipStatus["remaining_time"] : 0;
            $blockReason =
                "IP Address blocked due to too many failed attempts.";
        }
    } catch (Exception $e) {
        logSecurityEvent(
            "[SECURITY] Rate limiter check error: " . $e->getMessage(),
            "file",
        );
    }
}

// ============================================================================
// SESSION-BASED COOLDOWN FREEZE CHECK
// ============================================================================
if (isset($_SESSION["login_cooldown_until"]) && is_numeric($_SESSION["login_cooldown_until"])) {
    $timeRemaining = (int) $_SESSION["login_cooldown_until"] - time();
    if ($timeRemaining > 0) {
        $blocked = true;
        $remaining_time = $timeRemaining;
        $blockReason = "Session frozen due to too many failed attempts.";
    } else {
        // Cooldown has expired
        unset($_SESSION["login_cooldown_until"]);
    }
}


$statusMsg = "";
$statusType = "";

// ============================================================================
// AUTHENTICATION HANDLER INITIALIZATION
// ============================================================================
$authHandler = null;
if ($securityLogger !== null) {
    try {
        $authHandler = new AuthHandler($securityLogger, $accountLockout, $rateLimiter, $rememberMe);
    } catch (Exception $e) {
        logSecurityEvent(
            "[SECURITY] Failed to initialize AuthHandler: " . $e->getMessage(),
            "file"
        );
    }
}

// Ensure login_attempts is initialized to avoid warnings
if (!isset($_SESSION["login_attempts"])) {
    $_SESSION["login_attempts"] = 0;
}

// ============================================================================
// LOGIN PROCESSING (WITH ENHANCED BRUTE FORCE PROTECTION)
// ============================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$blocked && $authHandler !== null) {
    $result = $authHandler->handleLoginRequest($_POST, $_SESSION);

    if ($result['status'] === 'error') {
        $statusMsg = $result['message'];
        $statusType = 'err';
        $_SESSION["login_attempts"] = isset($_SESSION["login_attempts"]) && is_int($_SESSION["login_attempts"]) ? $_SESSION["login_attempts"] + 1 : 1;
        if ($_SESSION["login_attempts"] >= 5) {
            $_SESSION["login_cooldown_until"] = time() + 60;
        }
        if ($_SESSION["login_attempts"] >= 15) {
            $_SESSION["session_banned"] = true;
        }
    } else {
        $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $captcha = isset($_POST['captcha_code']) && is_string($_POST['captcha_code']) ? trim($_POST['captcha_code']) : '';
        $captchaPhraseForValidation = $_SESSION['captcha_phrase'] ?? null;

        // ================================================================
        // CAPTCHA VALIDATION
        // ================================================================
        $captchaRequired = (isset($_SESSION['captcha_required']) && $_SESSION['captcha_required'] === true) || (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] > 5);
        $captchaValid = true;
        $captchaErrorMsg = '';

        if ($captchaRequired) {
            $captchaPhraseForValidation = is_string($captchaPhraseForValidation) ? $captchaPhraseForValidation : null;
            $captchaResult = $authHandler->validateCaptcha($captcha, $captchaPhraseForValidation);
            if (!$captchaResult['valid']) {
                $captchaValid = false;
                $captchaErrorMsg = $captchaResult['message'];
            }
        }

        if (!$captchaValid) {
            $statusMsg = $captchaErrorMsg;
            $statusType = 'err';
            $_SESSION["login_attempts"] = isset($_SESSION["login_attempts"]) && is_int($_SESSION["login_attempts"]) ? $_SESSION["login_attempts"] + 1 : 1;
            if ($_SESSION["login_attempts"] >= 5) {
                $_SESSION["login_cooldown_until"] = time() + 60;
            }
            if ($_SESSION["login_attempts"] >= 15) {
                $_SESSION["session_banned"] = true;
            }

            // Evaluate failed attempt and apply protections
            $failedProtection = new FailedLoginProtection($con, $securityLogger);
            $evaluation = $failedProtection->evaluateFailedAttempt(
                $username,
                is_string($ipAddress) ? $ipAddress : DEFAULT_IP_FALLBACK,
                is_string($userAgent) ? $userAgent : null,
                null
            );

            // Apply progressive delay
            if (isset($evaluation['delay_seconds']) && is_numeric($evaluation['delay_seconds']) && $evaluation['delay_seconds'] > 0) {
                usleep((int) $evaluation['delay_seconds'] * 1000000);
            }

            $attemptsLeft = 5 - $_SESSION["login_attempts"];
            if ($_SESSION["login_attempts"] > 5) {
                $attemptsLeft = 15 - $_SESSION["login_attempts"];
            }
            $evaluation['attempts_remaining'] = max(0, $attemptsLeft);

            $_SESSION['login_evaluation'] = $evaluation;
            $_SESSION['captcha_required'] = $evaluation['requires_captcha'] || ($_SESSION['login_attempts'] > 5);
        } else {
            // ============================================================
            // DATABASE AUTHENTICATION
            // ============================================================
            $user = $authHandler->authenticate($username, $password);

            if ($user) {
                // Authentication successful - reset attempts and handle session
                $userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;

                // Reset failed attempts
                $stmt = $con->prepare(
                    "UPDATE account_lockouts SET failed_attempts = 0, locked_until = NULL WHERE username = ?"
                );
                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $stmt->close();
                }

                // Record successful login
                $insertStmt = $con->prepare(
                    "INSERT INTO login_attempts (username, ip_address, user_agent, attempt_type, failure_reason, attempted_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                );
                if ($insertStmt) {
                    $attemptType = 'success';
                    $failureReason = null;
                    $insertStmt->bind_param('sssss', $username, $ipAddress, $userAgent, $attemptType, $failureReason);
                    $insertStmt->execute();
                    $insertStmt->close();
                }

                // Clear session variables
                unset($_SESSION['login_evaluation']);
                unset($_SESSION['captcha_required']);
                $_SESSION['login_attempts'] = 0;
                unset($_SESSION['login_cooldown_until']);

                // Handle session and redirect
                $rememberMeChecked = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
                $redirectPath = $authHandler->handleSuccessfulLogin($user, $rememberMeChecked, is_string($ipAddress) ? $ipAddress : DEFAULT_IP_FALLBACK, is_string($userAgent) ? $userAgent : '');

                header("Location: /" . ltrim($redirectPath, '/'));
                exit();
            } else {
                // Authentication failed - apply brute force protections
                $_SESSION["login_attempts"] = isset($_SESSION["login_attempts"]) && is_int($_SESSION["login_attempts"]) ? $_SESSION["login_attempts"] + 1 : 1;
                if ($_SESSION["login_attempts"] >= 5) {
                    $_SESSION["login_cooldown_until"] = time() + 60;
                }
                if ($_SESSION["login_attempts"] >= 15) {
                    $_SESSION["session_banned"] = true;
                }
                $statusMsg = AuthHandler::AUTH_FAILED_INVALID_CREDENTIALS;
                $statusType = "err";

                // Evaluate failed attempt
                $failedProtection = new FailedLoginProtection($con, $securityLogger);
                $evaluation = $failedProtection->evaluateFailedAttempt(
                    $username,
                    is_string($ipAddress) ? $ipAddress : DEFAULT_IP_FALLBACK,
                    is_string($userAgent) ? $userAgent : null,
                    null
                );

                // Apply progressive delay
                if (isset($evaluation['delay_seconds']) && is_numeric($evaluation['delay_seconds']) && $evaluation['delay_seconds'] > 0) {
                    usleep((int) $evaluation['delay_seconds'] * 1000000);
                }

                // Check if account is now locked
                if ($evaluation['should_block']) {
                    $statusMsg = 'Account temporarily locked due to multiple failed login attempts. Please try again later.';
                    $blocked = true;
                } elseif ($evaluation['requires_captcha'] || ($_SESSION['login_attempts'] > 5)) {
                    $_SESSION['captcha_required'] = true;
                    $statusMsg = 'Additional verification required. Please complete the CAPTCHA.';
                }

                $attemptsLeft = 5 - $_SESSION["login_attempts"];
                if ($_SESSION["login_attempts"] > 5) {
                    $attemptsLeft = 15 - $_SESSION["login_attempts"];
                }
                $evaluation['attempts_remaining'] = max(0, $attemptsLeft);

                $_SESSION['login_evaluation'] = $evaluation;
            }
        }
    }

}

// CAPTCHA support using Gregwar/Captcha
// Plan B: Always generate a fresh CAPTCHA for display after processing POST,
// and generate for GET as well.
$phraseBuilder = new PhraseBuilder(5, '0123456789');
$captchaBuilder = new CaptchaBuilder(null, $phraseBuilder);
$captchaBuilder->setMaxBehindLines(0);
$captchaBuilder->setMaxFrontLines(0);
$captchaBuilder->build();
$_SESSION['captcha_phrase'] = $captchaBuilder->getPhrase();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ROOTS // SECURE_LOGIN</title>
    <meta name="description" content="ROOTS secure access terminal - hardened authentication gateway.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0a0f0a">
    <link rel="canonical" href="/login">
    <meta property="og:type" content="website">
    <meta property="og:title" content="ROOTS // SECURE_LOGIN">
    <meta property="og:description" content="ROOTS secure access terminal - hardened authentication gateway.">
    <meta name="twitter:card" content="summary">
    <!-- PERF v1.0: disable heavy visual effects (matrix canvas, CRT scanlines, overlays, animations) -->
    <style>
    #matrix-bg,
    .grid-overlay,
    .crt-overlay {
        display: none !important;
    }

    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }

    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            animation: none !important;
            transition: none !important;
        }
    }

    .term-window,
    .term-body {
        content-visibility: auto;
        contain-intrinsic-size: auto 480px;
    }

    html {
        -webkit-font-smoothing: antialiased;
    }
    </style>
    <!-- Google Fonts - SRI not applicable: CDN content changes dynamically based on user agent/browser -->
    <!-- For production, consider self-hosting fonts to enable SRI and improve security -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Share+Tech+Mono&display=swap"
        rel="stylesheet" crossorigin="anonymous">
    <!-- NOSONAR: SRI not applicable for Google Fonts as content is dynamically generated per user agent -->
    <link rel="stylesheet" href="/css/login-terminal.css?v=5">
    <link rel="stylesheet" href="/css/captcha-terminal.css?v=3">
</head>

<body class="page-login">
    <canvas id="matrix-bg" class="matrix-canvas" hidden></canvas>
    <div class="grid-overlay" aria-hidden="true"></div>
    <div class="crt-overlay" aria-hidden="true"></div>

    <main class="login-shell" aria-label="Login Form">
        <div class="term-window">

            <div class="term-body terminal-box">
                <h1 class="term-prompt-header">
                    secure login
                </h1>

                <form method="POST" autocomplete="on" aria-label="Login Form" novalidate>
                    <!-- CSRF TOKEN (CRITICAL FIX #1.1) -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(
                    isset($_SESSION["csrf_token"]) && is_string($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : "",
                    ENT_QUOTES,
                    "UTF-8",
                ); ?>">

                    <div class="input-group">
                        <label for="user">Username</label>
                        <div class="prompt-field">
                            <span class="prompt-prefix" aria-hidden="true">$</span>
                            <input type="text" id="user" name="username" autocomplete="username" required
                                maxlength="255" placeholder="username" aria-required="true" <?php if ($blocked) {
                                echo "disabled";
                            } ?>>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="pass">Password</label>
                        <div class="prompt-field">
                            <span class="prompt-prefix" aria-hidden="true">#</span>
                            <input type="password" id="pass" name="password" autocomplete="current-password" required
                                maxlength="512" placeholder="password" aria-required="true" <?php if ($blocked) {
                                echo "disabled";
                            } ?>>
                        </div>
                    </div>

                    <?php if ((isset($_SESSION['captcha_required']) && $_SESSION['captcha_required']) || (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] > 5)): ?>
                    <div class="input-group">
                        <label for="captcha-input">Verification code</label>
                        <div class="captcha-container">
                            <img src="<?php echo is_string($captchaBuilder->inline()) ? $captchaBuilder->inline() : ''; ?>"
                                alt="Security verification code" class="captcha-img" id="captcha-image"
                                decoding="async">
                            <input type="text" id="captcha-input" inputmode="numeric" pattern="[0-9]{5}" maxlength="5"
                                name="captcha_code" class="captcha-input" placeholder="00000" autocomplete="off"
                                required aria-required="true" aria-label="CAPTCHA Code" <?php if (
                                $blocked
                            ) {
                                echo "disabled";
                            } ?>>
                        </div>
                        <button type="button" class="regen-link"
                            aria-label="refresh code - Refresh verification code image"
                            data-csrf-token="<?php echo htmlspecialchars(isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                            refresh code
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Remember Me Checkbox -->
                    <div class="remember-container">
                        <label class="remember-label">
                            <input type="checkbox" name="remember_me" value="1" <?php if (
                            $blocked
                        ) {
                            echo "disabled";
                        } ?> aria-label="Remember me for 30 days">
                            <span class="remember-text">Remember me (3 hours)</span>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="window.location.href='signup'">
                            Contact admin
                        </button>

                        <button type="submit" <?php if ($blocked) {
                        echo "disabled";
                    } ?> aria-label="Sign in">
                            Sign in
                        </button>
                    </div>
                </form>

                <?php if (isset($_SESSION["flash_message"])): ?>
                <?php
                $flashType = $_SESSION["flash_type"] ?? "info";
                $flashClass = match ($flashType) {
                    "success" => "term-log status-ok",
                    "error" => "term-log status-err",
                    default => "term-log status-info",
                };
                $flashPrefix = match ($flashType) {
                    "success" => "SUCCESS",
                    "error" => "ERR",
                    default => "INFO",
                };
                ?>
                <div class="<?php echo htmlspecialchars($flashClass, ENT_QUOTES, 'UTF-8'); ?>" role="alert"
                    aria-live="polite" data-ts="">
                    <?php echo htmlspecialchars($flashPrefix, ENT_QUOTES, 'UTF-8'); ?> &gt; <?php echo htmlspecialchars(
                            $_SESSION["flash_message"],
                            ENT_QUOTES,
                            "UTF-8",
                        ); ?>
                </div>
                <?php unset(
                    $_SESSION["flash_message"],
                    $_SESSION["flash_type"],
                ); ?>
                <?php endif; ?>

                <?php if ($statusMsg): ?>
                <!-- XSS FIX: Always escape output (CRITICAL FIX #1.4) -->
                <div class="term-log status-err" role="alert" aria-live="polite" data-ts="">
                    ERR &gt; <?php echo htmlspecialchars(
                        is_string($statusMsg) ? $statusMsg : '',
                        ENT_QUOTES,
                        "UTF-8",
                    ); ?>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['login_attempts']) && is_int($_SESSION['login_attempts']) && $_SESSION['login_attempts'] > 0): ?>
                <div class="term-log status-warn" role="alert" aria-live="polite">
                    WARNING &gt; Login attempts: <?php echo $_SESSION['login_attempts']; ?>
                </div>
                <?php endif; ?>

                <?php
            $loginEvaluation = isset($_SESSION['login_evaluation']) && is_array($_SESSION['login_evaluation']) ? $_SESSION['login_evaluation'] : null;
            $delaySeconds = isset($loginEvaluation['delay_seconds']) && is_numeric($loginEvaluation['delay_seconds']) ? (int) $loginEvaluation['delay_seconds'] : 0;
            ?>
                <?php if ($delaySeconds > 0): ?>
                <div class="term-log status-info" role="alert" aria-live="polite">
                    INFO &gt; Please wait <?php echo $delaySeconds; ?> seconds before trying again
                </div>
                <?php endif; ?>

                <?php if ($blocked): ?>
                <div class="term-log term-log--lockdown status-err" role="alert" aria-live="polite" data-ts="">
                    <strong>!!! SYSTEM LOCKDOWN !!!</strong>
                    <?php echo htmlspecialchars(
                        $blockReason,
                        ENT_QUOTES,
                        "UTF-8",
                    ); ?><br>
                    RETRY IN: <span id="countdown" class="countdown-value"><?php echo htmlspecialchars(
                            (string) $remaining_time,
                            ENT_QUOTES,
                            "UTF-8",
                        ); ?></span> SEC
                </div>
                <script>
                let secs = <?php echo (int) $remaining_time; ?>;
                const timer = setInterval(() => {
                    if (secs > 0) {
                        secs--;
                        document.getElementById('countdown').textContent = secs;
                    } else {
                        clearInterval(timer);
                        location.reload();
                    }
                }, 1000);
                </script>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="/js/captcha-terminal.js?v=4" defer></script>
    <script src="/js/login-terminal.js?v=3" defer></script>

</body>

</html>
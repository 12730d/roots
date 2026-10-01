<?php
declare(strict_types=1);

/**
 *
 * CORE ROUTER v3.0 (PROFESSIONAL EDITION)
 *
 * This file serves as the unified entry point for the application.
 *
 * upgrades:
 * 1. Professional Session Management via ROOTS\Auth\Session
 * 2. Autoloading & Dependency Injection
 * 3. Enhanced File System Resolution with Security Interceptors
 * 4. Legacy Compatibility Layer (Globals)
 * 5. Terminal-styled Error Pages
 */

// ============================================================================
// 1. BOOTSTRAP & AUTOLOAD
// ============================================================================

// Start session first - must be before anything else
if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.cookie_httponly", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.cookie_samesite", "Lax");
    ini_set("session.use_strict_mode", "1");
    ini_set("session.gc_maxlifetime", "1800");
    ini_set("session.use_trans_sid", "0");
    if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        ini_set("session.cookie_secure", "1");
    }
    session_name("ROOTS_SESSION");
    session_start();
}

// Require the Composer/Project autoloader
header("X-Router-Active: true");
require_once __DIR__ . "/vendor/autoload.php";

// Load Drone Face Scanner Integration AFTER session is started
require_once __DIR__ . "/drone_integration.php";

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Routing\Dispatcher;
use ROOTS\Auth\BanSystem;
use ROOTS\Security\CsrfProtection;
use ROOTS\Middleware\BotProtectionMiddleware;
use ROOTS\Middleware\SecurityHeadersMiddleware;

if (!defined("DATE_FORMAT")) {
    define("DATE_FORMAT", "Y-m-d H:i:s");
}
if (!defined("ERROR_COLOR_RED")) {
    define("ERROR_COLOR_RED", "#ff0000");
}
if (!defined("CONTENT_TYPE_JSON")) {
    define("CONTENT_TYPE_JSON", "application/json");
}
if (!defined("BLOCKED_403_PATH")) {
    define("BLOCKED_403_PATH", "/blocked_403.php");
}
if (!defined("ATTEMPTED_PATH_MSG")) {
    define("ATTEMPTED_PATH_MSG", "Attempted path: ");
}

// ============================================================================
// 1.5. GLOBAL SECURITY HELPERS (Must be defined before use)
// ============================================================================

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
        // Use a safer fallback if BanSystem is not yet available
        if (class_exists('ROOTS\Auth\BanSystem')) {
            return BanSystem::getClientIp();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '10.152.152.10';
    }
}

// Security audit logging helper
if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent(string $message, string $channel = "security"): void
    {
        $isSecurity = $channel === "security";
        if ($isSecurity && function_exists("syslog")) {
            syslog(LOG_WARNING, $message);
        }
    }
}

// Path validation helper to prevent Path Traversal attacks
if (!function_exists('isSafePath')) {
    function isSafePath(string $path, string $baseDir): bool
    {
        $realPath = realpath($path);
        $realBase = realpath($baseDir);
        return $realPath && $realBase && strpos($realPath, $realBase) === 0;
    }
}

// URI validation helper to prevent malicious URIs
if (!function_exists('isValidUri')) {
    function isValidUri(string $uri): bool
    {
        // Only validate the path part of the URI to allow query parameters
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        // Allow alphanumeric, slashes, dots, hyphens, underscores AND UTF-8 characters (for Arabic, etc.)
        // Also allow percent-encoded characters
        return preg_match('/^[a-zA-Z0-9\-_\/\.% \x{0600}-\x{06FF}]+$/u', (string) $path) === 1;
    }
}

// Helper to detect if a request is an AJAX/background request
if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) ||
            (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], CONTENT_TYPE_JSON) !== false) ||
            (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], CONTENT_TYPE_JSON) !== false);
    }
}

// ============================================================================
// 2. SECURITY & SESSION SYSTEM
// ============================================================================

// Detect request path early for exemptions
$requestUriRaw = $_SERVER["REQUEST_URI"] ?? '';
$requestPathOnly = parse_url($requestUriRaw, PHP_URL_PATH) ?: '';
$criticalPages = ['/blocked_403', '/blocked', '/logout', '/login', BLOCKED_403_PATH, '/blocked.php', '/login.php'];
$isCriticalPage = in_array($requestPathOnly, $criticalPages, true);

// Initialize Secure Session
// This handles: HTTPOnly, Secure, SameSite=Strict, and Session Name consistency.
Session::start();

// [OPTIMIZATION] Detect if this is a static asset or AJAX request early to skip rate limiting
// Extended list of assets to include modern web formats and common icons
$isAssetRequest = preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|webp|webm|mp4|woff2?|ttf|eot|map|txt|json|xml|webmanifest)$/i', $requestPathOnly);
$isBackgroundRequest = isAjaxRequest();

// Apply Global Protections (Skip for critical error pages and static assets to prevent loops)
if (!$isCriticalPage && !$isAssetRequest) {
    BotProtectionMiddleware::apply();

    // [NEW] Check if browser fingerprint is blocked (Strikes/Security Violations)
    // We primarily check this for guests; logged-in users are managed by their account status
    // and will be forced to logout if their browser is blocked during a session.
    if (!Session::isLoggedIn()) {
        $browserBlock = BanSystem::isBrowserBlocked();
        if ($browserBlock['is_blocked']) {
            $reason = $browserBlock['reason'] ?? 'Multiple strikes';
            $isRateLimit = str_contains($reason, 'RATE_LIMIT_EXCEEDED');

            http_response_code(403);
            renderErrorPage(
                403,
                $isRateLimit ? "RATE_LIMIT_BLOCKED" : "SECURITY_VIOLATION_BLOCKED",
                $isRateLimit
                ? "Your browser is temporarily throttled due to high request volume. Reason: $reason"
                : "Your browser has been temporarily blocked due to repeated security violations. Reason: $reason",
            );
            exit();
        }
    }
}
SecurityHeadersMiddleware::apply();

// Apply User-Agent based Rate Limiting for guests (non-logged-in users)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$isLoggedIn = isset($_SESSION["user_id"]) && !empty($_SESSION["user_id"]);

if (!$isLoggedIn && !$isAssetRequest && !$isBackgroundRequest && !$isCriticalPage) {
    $rateLimit = BanSystem::checkGuestRateLimit(150, 60); // Increased to 150 requests per 60 seconds

    if ($rateLimit['is_blocked']) {
        http_response_code(429);
        header('Content-Type: text/html; charset=utf-8');
        $blockType = $rateLimit['type'] ?? 'ip';
        $blockUntil = $rateLimit['until'] ?? 'unknown';
        $blockReason = $rateLimit['reason'] ?? 'Too many requests';

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // Show rate limit error page
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>429 - Too Many Requests</title>
    <style>
        body { background: #0a0a0a; color: #00ff00; font-family: "Courier New", Courier, monospace; padding: 50px; }
        .box { border: 1px solid #00ff00; padding: 20px; box-shadow: 0 0 15px #00ff00; max-width: 600px; margin: 0 auto; }
        .alert { color: #ff0000; font-weight: bold; }
        .info { color: #00aaff; }
    </style>
</head>
<body>
    <div class="box">
        <p>> [SYSTEM_INIT] Rate Limiting Active...</p>
        <p>> [DETECTED] Excessive requests detected</p>';

        if ($blockType === 'user_agent') {
            echo '<p class="alert">> [BLOCKED] Browser/User-Agent temporarily blocked</p>';
            echo '<p class="info">> User-Agent: ' . htmlspecialchars(substr($userAgent, 0, 100)) . '</p>';
            echo '<p class="info">> Try using a different browser or wait</p>';
        } else {
            echo '<p class="alert">> [BLOCKED] IP temporarily blocked</p>';
        }

        echo '<p class="info">> Blocked until: ' . htmlspecialchars($blockUntil) . '</p>';
        echo '<p class="info">> Reason: ' . htmlspecialchars($blockReason) . '</p>';
        echo '<p>> Connection terminated.</p>
    </div>
</body>
</html>';
        exit();
    }
}

// Handle static files directly when using the PHP built-in server or similar routing
$requestUri = $requestPathOnly;

// Security: Validate URI to prevent malicious input (Skip for error pages)
$decodedUri = urldecode((string) $requestUri);
if (!$isCriticalPage && !isValidUri($decodedUri)) {
    http_response_code(400);
    renderErrorPage(
        400,
        "INVALID_REQUEST",
        "Invalid request URI detected. Prohibited characters found.",
    );
    exit();
}

// Robust path resolution for static assets
$cleanPath = ltrim((string) $requestUri, '/');

// Normalize Path for Routing
$path = trim((string) $requestUri, "/");
if ($path === "" || $path === "index") {
    $path = "index";
}

// Allow direct access to blocked_403
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
if (basename($requestPath) === 'blocked_403' && file_exists(__DIR__ . BLOCKED_403_PATH)) {
    return require_once __DIR__ . BLOCKED_403_PATH;
}

// CRITICAL: Skip router.php processing for core PHP files to prevent redirect loops
$coreFiles = ['login.php', 'blocked.php', 'blocked_403.php', 'dashboard.php', 'drone-dashboard.php', 'welcome.php', 'logout.php'];
$currentFile = basename(parse_url($requestUri, PHP_URL_PATH) ?: '');
if (in_array($currentFile, $coreFiles) && file_exists(__DIR__ . '/' . $currentFile)) {
    // Let these files handle themselves directly
    return require_once __DIR__ . '/' . $currentFile;
}

// Strip extension for clean URL routing
if (substr($path, -4) === ".php") {
    $path = substr($path, 0, -4);
}

// [NEW] Smart Path Resolution: Handle trailing slashes for files
// If "dir/contact/" is requested, check if "dir/contact.php" exists
if (str_ends_with($path, '/') && !is_dir(__DIR__ . '/' . $path)) {
    $testPath = rtrim($path, '/');
    if (file_exists(__DIR__ . '/' . $testPath . '.php')) {
        $path = $testPath;
    }
}

// Important: Use __DIR__ to resolve absolute path correctly
$publicFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cleanPath);

// Security: If file exists, validate real path against base directory to prevent Path Traversal
$resolvedPath = realpath($publicFile);
if ($resolvedPath !== false) {
    if (!isSafePath($resolvedPath, __DIR__)) {
        http_response_code(403);
        renderErrorPage(
            403,
            "ACCESS_DENIED",
            "Access to the requested resource is denied.",
        );
        exit();
    }
    $publicFile = $resolvedPath;
}

// Static access debug logging disabled in production to avoid noisy logs

if (file_exists($publicFile) && !is_dir($publicFile) && !str_ends_with($publicFile, '.php')) {
    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'json' => CONTENT_TYPE_JSON,
        'map' => CONTENT_TYPE_JSON,
    ];

    header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($publicFile));
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    header('X-Router-Handled: true');
    header('X-Content-Path: ' . $cleanPath);
    readfile($publicFile);
    exit;
}

// Lightweight CSRF token (session-backed) for form POSTs when using cookie sessions
// Only rotate on full page GET requests to avoid breaking CSRF for concurrent AJAX requests
if (session_status() === PHP_SESSION_ACTIVE && $_SERVER["REQUEST_METHOD"] === "GET" && !isAjaxRequest()) {
    CsrfProtection::generateToken();
}

// ============================================================================
// 3. GLOBAL DEPENDENCIES (LEGACY COMPATIBILITY)
// ============================================================================

// Many existing files (login, dashboard) expect a global $con variable.
// We initialize it here using the robust Database class.
try {
    $con = Database::getConnection();
    // Global variable $con is now available to all included files.
} catch (Exception $e) {
    renderErrorPage(
        500,
        "DATABASE_CRITICAL_FAILURE",
        "Unable to establish secure connection to data core.",
    );
    exit();
}

// Securimage is no longer used globally. It's handled by specific pages.

// ============================================================================
// 4. SECURITY INTERCEPTORS
// ============================================================================

// Global Captcha Enforcement for POST requests (except for login which has its own handler)
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["captcha_code"]) &&
    !empty($_SESSION['captcha_phrase']) &&
    $path !== "login"
) {
    $captchaCode = (string) ($_POST["captcha_code"] ?? "");
    $captchaPhrase = $_SESSION['captcha_phrase'];

    if (empty($captchaCode) || strcasecmp($captchaCode, $captchaPhrase) !== 0) {
        logSecurityEvent('CAPTCHA_FAILED', "IP: " . getClientIp());
        renderErrorPage(
            403,
            "SECURITY_PROTOCOL_VIOLATION",
            "Invalid Verification Code detected. Access Denied.",
        );
        exit();
    }

    // Unset captcha phrase after successful validation to prevent reuse
    unset($_SESSION['captcha_phrase']);
}

// Helper: extract Bearer token from Authorization header or custom header
function getBearerToken(): ?string
{
    $headers = [];
    if (function_exists("getallheaders")) {
        $headers = getallheaders();
    }
    // normalize keys
    $normalized = [];
    foreach ($headers as $k => $v) {
        $normalized[strtolower($k)] = $v;
    }

    $auth =
        $normalized["authorization"] ??
        ($_SERVER["HTTP_AUTHORIZATION"] ?? null);
    $token = null;

    if (!$auth && isset($normalized["x-auth-token"])) {
        $token = trim((string) $normalized["x-auth-token"]);
    } elseif ($auth && stripos((string) $auth, "bearer ") === 0) {
        $token = trim(substr((string) $auth, 7));
    }

    return $token;
}

// Lightweight token validation: compare against environment variable `API_ACCESS_TOKEN`
function validateApiToken(?string $token): bool
{
    if (empty($token)) {
        return false;
    }
    $expected = getenv("API_ACCESS_TOKEN") ?: "";
    if (empty($expected)) {
        return false;
    }
    // Use hash_equals when possible to mitigate timing attacks
    return function_exists("hash_equals")
        ? hash_equals($expected, $token)
        : $expected === $token;
}

// =================================="
// 4.5. SECURITY ENFORCEMENT
// ==================================

// Enforce CSRF for POST requests unless a valid API token is presented
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $bearer = getBearerToken();
    if (!validateApiToken($bearer)) {
        // [BYPASS] Allow terminal_api to fetch initial CSRF token
        $isGetCsrfAction = ($path === 'massage/terminal_api' && ($_POST['action'] ?? '') === 'get_csrf_token');

        if (!$isGetCsrfAction) {
            // require csrf token for non-API POSTs
            $csrfHeader = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? null;
            $csrfField = $_POST["csrf_token"] ?? null;

            // Check JSON body for CSRF if it's an AJAX request
            $jsonCsrf = null;
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, CONTENT_TYPE_JSON)) {
                $rawInput = file_get_contents("php://input");
                if ($rawInput) {
                    $jsonInput = json_decode($rawInput, true);
                    if (is_array($jsonInput) && isset($jsonInput['csrf_token'])) {
                        $jsonCsrf = $jsonInput['csrf_token'];
                    }
                    // Store the decoded input in a global variable for later use by scripts
                    // to avoid re-reading php://input
                    $GLOBALS['ROOTS_REQUEST_DATA'] = $jsonInput;
                }
            }

            $csrf = $csrfHeader ?? ($csrfField ?? $jsonCsrf);
            $sessionCsrf = $_SESSION["csrf_token"] ?? null;

            if (
                empty($csrf) ||
                empty($sessionCsrf) ||
                !hash_equals((string) $sessionCsrf, (string) $csrf)
            ) {
                $details = sprintf(
                    "IP: %s | Received: %s | Session: %s | Match: %s",
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $csrf ? substr((string) $csrf, 0, 8) . '...' : 'NONE',
                    $sessionCsrf ? substr((string) $sessionCsrf, 0, 8) . '...' : 'NONE',
                    ($csrf && $sessionCsrf && hash_equals((string) $sessionCsrf, (string) $csrf)) ? 'YES' : 'NO'
                );
                logSecurityEvent('CSRF_VALIDATION_FAILED', $details);

                // Check if it's an AJAX request or an API endpoint
                $isAjax = isAjaxRequest() || strpos($path, 'api') !== false || strpos($path, 'delete_') !== false || strpos($path, 'purchase_') !== false;

                if ($isAjax) {
                    http_response_code(403);
                    header('Content-Type: ' . CONTENT_TYPE_JSON);
                    echo json_encode([
                        'success' => false,
                        'message' => 'CSRF_VALIDATION_FAILED: Missing or invalid security token.',
                        'details' => $details
                    ]);
                    exit();
                }

                renderErrorPage(
                    403,
                    "CSRF_VALIDATION_FAILED",
                    "Missing or invalid CSRF token.",
                );
                exit();
            }
        }
    }
}

$activeBanUntil = null;
if (
    Session::isLoggedIn() &&
    isset($_SESSION["user_id"]) &&
    $con instanceof \mysqli
) {
    $uid = (int) $_SESSION["user_id"];
    $banDetails = BanSystem::getBanDetails($uid);

    if (!empty($banDetails)) {
        $isSuspended = !empty($banDetails["suspended"]);
        $banUntil = $banDetails["ban_until"] ?? null;

        if ($isSuspended) {
            logSecurityEvent('SUSPENDED_ACCOUNT_ACCESS', "User ID: $uid");
            header("Location: /blocked");
            exit();
        }

        if (!empty($banUntil) && strtotime($banUntil) > time()) {
            $activeBanUntil = (string) $banUntil;
        }
    }
}

if (empty($activeBanUntil) && !empty($_SESSION["blocked_notice_shown"])) {
    unset($_SESSION["blocked_notice_shown"]);
}

// ============================================================================
// 5. FILE SYSTEM & ROUTING LOGIC
// ============================================================================

// 5.3 Sanitize Path (File System Abstraction)
// Allow: a-z, A-Z, 0-9, underscores, hyphens, and forward slashes.
// Block: '..' (Directory Traversal), null bytes, or other control chars.
// Block: paths starting with / (absolute paths)
if (strpos($path, "..") !== false || strpos($path, "\0") !== false || strpos($path, "//") !== false) {
    logSecurityEvent('PATH_TRAVERSAL', ATTEMPTED_PATH_MSG . substr($path, 0, 255));
    renderErrorPage(
        403,
        "ILLEGAL_TRAVERSAL_ATTEMPT",
        "Path traversal blocked by system integrity monitor.",
    );
    exit();
}

// Prevent absolute paths and protocol wrappers
if (preg_match('/^(\/|\\|[a-zA-Z]:|php:\/\/|file:\/\/|https?:\/\/|ftps?:\/\/|data:\/\/|expect:\/\/|input:\/\/|phar:\/\/|zip:\/\/)/i', $path)) {
    logSecurityEvent('PROTOCOL_WRAPPER_ATTEMPT', ATTEMPTED_PATH_MSG . substr($path, 0, 255));
    renderErrorPage(
        403,
        "INVALID_PATH_PROTOCOL",
        "Invalid path protocol or format detected.",
    );
    exit();
}

// Block deep directory traversal attempts
if (substr_count($path, '/') > 5) {
    logSecurityEvent('DEEP_DIRECTORY_TRAVERSAL', ATTEMPTED_PATH_MSG . substr($path, 0, 255));
    renderErrorPage(
        403,
        "DEEP_DIRECTORY_TRAVERSAL",
        "Deep directory traversal blocked by system integrity monitor.",
    );
    exit();
}

$safePath = preg_replace("/[^a-zA-Z0-9_\-\/\. \x{0600}-\x{06FF}]/u", "", $path);
if ($safePath !== $path) {
    renderErrorPage(
        400,
        "MALFORMED_REQUEST_SYNTAX",
        "The request URI contains prohibited characters.",
    );
    exit();
}

if (
    Session::isLoggedIn() &&
    isset($_SESSION["user_id"]) &&
    $con instanceof \mysqli
) {
    $uid = (int) $_SESSION["user_id"];

    // First: Check if user is banned (suspended or temp banned)
    $isBanned = BanSystem::isBanned($uid);
    if ($isBanned) {
        // Define allowed paths for banned users
        $allowedForBanned = false;
        if (
            $safePath === "add" ||
            $safePath === "blocked" ||
            $safePath === "botworm" ||
            $safePath === "blocked_403" ||
            $safePath === "logout" ||
            strpos($safePath, "massage") === 0
        ) {
            $allowedForBanned = true;
        }

        // If banned user tries to access non-allowed page, redirect to botworm
        if (!$allowedForBanned) {
            header("Location: /botworm");
            exit();
        }
    }

    // Check for violation count via BanSystem
    $violationCount = BanSystem::getViolationCount($uid);

    // [NEW] Enforce Premium Access Redirect for direct page access
    BanSystem::enforcePremiumAccess($uid, $safePath);

    // If user has 50+ violations, redirect to blocked (match login)
    if ($violationCount >= 50 && $safePath !== "blocked") {
        header("Location: /blocked");
        exit();
    }
}

// 5.4 File Resolution Strategy
// We look for files in the following priority:
// 1. Direct file match: path/to/file.php
// 2. Directory match: path/to/dir/index.php
// Security: Use realpath to prevent LFI attacks

$baseDir = realpath(__DIR__);
if ($baseDir === false) {
    renderErrorPage(500, "SYSTEM_ERROR", "Unable to resolve base directory.");
    exit();
}

// Normalize safePath to handle cases where it might be empty
$lookupPath = empty($safePath) ? "index" : $safePath;

// Try to find the .php file
$targetFile = $baseDir . "/" . $lookupPath . ".php";
$realTargetFile = realpath($targetFile);

// If direct file doesn't exist, try directory index
if ($realTargetFile === false && is_dir($baseDir . "/" . $lookupPath)) {
    $potentialIndex = $baseDir . "/" . $lookupPath . "/index.php";
    $realTargetFile = realpath($potentialIndex);
}

// Security: Ensure resolved file is within base directory (prevent LFI)
if ($realTargetFile !== false) {
    $realBaseDir = realpath($baseDir);
    // Normalize both paths to ensure trailing slash consistency
    $normalizedBase = rtrim($realBaseDir ?: '', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $normalizedTarget = $realTargetFile;

    if (strpos($normalizedTarget, $normalizedBase) !== 0) {
        logSecurityEvent('LFI_ATTEMPT', "Blocked access to: $lookupPath | Target: $normalizedTarget | Base: $normalizedBase");
        renderErrorPage(403, "ACCESS_DENIED", "Access to requested resource is not permitted.");
        exit();
    }
    $targetFile = $realTargetFile;
}

// 5.5 Access Control Lists (ACL)
// Block direct access to configuration and system files
$blockedResources = [
    "config",
    "config/config",
    "config/db_config",
    "src",
    "vendor",
    "autoload",
    "composer",
    "composer.lock",
    "router",
    "init_db",
    "fix_users_table",
];

if (in_array($safePath, $blockedResources)) {
    logSecurityEvent('BLOCKED_RESOURCE_ACCESS', "Attempted: $safePath");
    renderErrorPage(
        403,
        "ACCESS_RESTRICTED_AREA",
        "You do not have clearance to access this system resource.",
    );
    exit();
}

// ============================================================================
// 6. DISPATCH
// ============================================================================

if ($realTargetFile !== false && is_file($realTargetFile)) {
    // 6.1 Middleware: Protected Route Check
    // Define routes that require authentication
    // Check if path starts with any protected prefix
    $protectedAppAreas = [
        "dashboard",
        "drone-dashboard",
        "admin",
        "profile",
        "my_payments",
    ];

    $requiresAuth = false;
    foreach ($protectedAppAreas as $area) {
        if (strpos($safePath, $area) === 0) {
            $requiresAuth = true;
            break;
        }
    }

    if ($requiresAuth && !Session::isLoggedIn()) {
        // Allow API Bearer token to access protected routes when session is not present
        $bearer = getBearerToken();
        if (!validateApiToken($bearer)) {
            header("Location: /login");
            exit();
        }
    }

    // 6.2 Execute Target (Namespaced Dispatcher)
    Dispatcher::dispatch($safePath, $realTargetFile, [
        "con" => $con,
    ]);
} else {
    // 404 handler - log not found attempts for security monitoring
    // [SEC-FIX] Do not log security events for 404s on common static assets
    $isAsset = preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot|map|txt)$/i', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

    if (!$isAsset) {
        logSecurityEvent('RESOURCE_NOT_FOUND', "Path: $safePath");
    }

    renderErrorPage(
        404,
        "RESOURCE_NOT_FOUND",
        "The requested resource could not be found.",
    );
}

// ============================================================================
// 7. ERROR HANDLING (Simplified - Redirects to blocked_403)
// ============================================================================

function renderErrorPage(int $code, string $title, string $message): void
{
    // Set HTTP response code
    http_response_code($code);

    $path = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $requestPathOnly = parse_url($path, PHP_URL_PATH) ?: '';

    // [SEC-FIX] Detect if this is a common static asset or a critical page
    $isAsset = preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot|map|txt)$/i', $requestPathOnly);
    $criticalPages = ['/blocked_403', '/blocked', '/logout', BLOCKED_403_PATH, '/blocked.php'];
    $isCritical = in_array($requestPathOnly, $criticalPages, true);

    // Record violation for all visitors (logged in or guest)
    // Only exclude 404s on assets and critical pages to avoid noise and loops
    if (($code !== 404 || !$isAsset) && !$isCritical) {
        $uid = isset($_SESSION["user_id"]) ? (int) $_SESSION["user_id"] : null;

        // [NEW] If the browser is already blocked, don't record more violations
        // to prevent "Strike Accumulation" which makes the block permanent.
        $isAlreadyBlocked = false;
        if (!$uid) {
            $browserStatus = BanSystem::isBrowserBlocked();
            $isAlreadyBlocked = $browserStatus['is_blocked'];
        }

        if (!$isAlreadyBlocked) {
            // Determine severity: 403 (Forbidden) is more serious than 404 (Not Found)
            $severity = 1; // Default for 404
            if ($code === 403) {
                $severity = 5; // Critical: Accessing restricted files
            } elseif ($code === 400) {
                $severity = 2; // Medium: Malformed requests
            }

            BanSystem::recordBlockedViolation($uid, $path, $severity);
        }
    }

    // [OPTIMIZATION] Do not redirect for static asset 404s/errors
    if ($isAsset) {
        header('Content-Type: text/plain');
        echo "Error $code: $title - $message";
        exit();
    }

    // Redirect to blocked_403 with error code and reason
    $reason = urlencode($title);
    header("Location: /blocked_403?code=$code&reason=$reason");
    exit();
}

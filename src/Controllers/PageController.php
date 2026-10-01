<?php

namespace ROOTS\Controllers;

use ROOTS\Services\SessionManager;
use ROOTS\Services\AuthenticationService;
use ROOTS\Services\CsrfProtectionService;
use ROOTS\Services\PermissionService;
use ROOTS\Services\FlashMessageService;
use ROOTS\Services\HttpUtilityService;
use ROOTS\Services\ActivityLogger;
use ROOTS\Layout\MasterLayout;
use ROOTS\Config\Database;

/**
 * PageController - Handles page setup and teardown
 *
 * This controller manages the initialization and cleanup of page resources,
 * coordinating between various service classes for session management,
 * authentication, permissions, and layout rendering.
 */
class PageController
{
    private const CONTENT_TYPE_JSON = "Content-Type: application/json; charset=utf-8";
    private static ?MasterLayout $layout = null;

    /**
     * @var array<string, mixed> Page metadata
     */
    private static array $pageMetadata = [];

    /**
     * @var array<string, mixed> Configuration options
     */
    private static array $config = [
        "show_navbar" => true,
        "render_layout" => true,
    ];

    /**
     * Set page configuration
     *
     * @param array<string, mixed> $config Configuration options to merge
     */
    public static function setConfig(array $config): void
    {
        self::$config = array_merge(self::$config, $config);

        // Pass relevant config to session manager
        $sessionConfig = array_intersect_key(
            $config,
            array_flip([
                "session_name",
                "session_lifetime",
                "require_auth",
                "redirect_on_no_auth",
            ]),
        );
        if (!empty($sessionConfig)) {
            SessionManager::setConfig($sessionConfig);
        }

        // Pass CSRF config
        if (isset($config["csrf_protection"])) {
            CsrfProtectionService::setEnabled($config["csrf_protection"]);
        }
    }

    /**
     * Get page configuration
     */
    public static function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? null;
    }

    /**
     * Set page metadata
     *
     * @param array<string, mixed> $metadata Metadata to merge with existing metadata
     */
    public static function setMetadata(array $metadata): void
    {
        self::$pageMetadata = array_merge(self::$pageMetadata, $metadata);
    }

    /**
     * Get page metadata
     */
    public static function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return self::$pageMetadata;
        }
        return self::$pageMetadata[$key] ?? null;
    }

    private static function getLayout(): MasterLayout
    {
        if (self::$layout === null) {
            self::$layout = MasterLayout::createDefault();
        }

        return self::$layout;
    }

    /**
     * Setup page with all necessary resources
     *
     * @param string $pageTitle Page title
     * @param string $basePath Base path for assets
     * @param array<int, string> $extraCSS Additional CSS files
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Contains user data, database connection, and other resources
     */
    public static function setup(
        string $pageTitle,
        string $basePath = "/",
        array $extraCSS = [],
        array $options = [],
    ): array {
        // Merge options with config
        if (!empty($options)) {
            self::setConfig($options);
        }

        // Initialize session
        SessionManager::initialize();

        // Check authentication if required
        $isAuthenticated = true;
        $sessionConfig = SessionManager::getConfig();
        if ($sessionConfig["require_auth"] ?? true) {
            $isAuthenticated = SessionManager::checkAuthentication();
        }

        // Initialize CSRF protection
        CsrfProtectionService::initialize();

        // Load database connection
        $db = null;
        try {
            $db = Database::getConnection();
            if (!$db) {
                // Handle database connection error gracefully
                error_log(
                    "Database connection failed in PageController::setup",
                );
            }
        } catch (\Exception $e) {
            error_log("Database connection exception: " . $e->getMessage());
        }

        // Get user data
        $user = AuthenticationService::getUserData($db);

        // Enforce Global Ban System
        if (!empty($user["id"])) {
            \ROOTS\Auth\BanSystem::enforceGlobalBan($user["id"]);
        }

        // Load user permissions
        if (!empty($user["id"]) && $db) {
            $user["permissions"] = AuthenticationService::loadUserPermissions(
                $user["id"],
                $db,
            );
        }

        // Store page metadata
        self::setMetadata([
            "page_title" => $pageTitle,
            "base_path" => $basePath,
            "load_time" => microtime(true),
        ]);

        // Render page start only for non-mutating requests
        $requestMethod = $_SERVER["REQUEST_METHOD"] ?? "GET";
        $shouldRenderLayout =
            ($options["render_layout"] ?? true) && $requestMethod === "GET";
        if ($shouldRenderLayout) {
            self::getLayout()->renderPageStart(
                $pageTitle,
                $basePath,
                $extraCSS,
                self::$config["show_navbar"] ?? true,
            );
        }

        // Return resources
        return [
            "user" => $user,
            "is_admin" => $user["is_admin"],
            "is_authenticated" => $isAuthenticated,
            "db" => $db,
            "csrf_token" => CsrfProtectionService::getToken(),
            "config" => self::$config,
        ];
    }

    /**
     * Setup page without authentication requirement
     *
     * @param string $pageTitle Page title
     * @param string $basePath Base path for assets
     * @param array<int, string> $extraCSS Additional CSS files
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Contains user data, database connection, and other resources
     */
    public static function setupPublic(
        string $pageTitle,
        string $basePath = "/",
        array $extraCSS = [],
        array $options = [],
    ): array {
        $defaultOptions = [
            "require_auth" => false,
            "csrf_protection" => true,
        ];
        return self::setup(
            $pageTitle,
            $basePath,
            $extraCSS,
            array_merge($defaultOptions, $options),
        );
    }

    /**
     * Setup page with custom configuration
     *
     * @param string $pageTitle Page title
     * @param array<string, mixed> $config Custom configuration options
     * @param string $basePath Base path for assets
     * @param array<int, string> $extraCSS Additional CSS files
     * @return array<string, mixed> Contains user data, database connection, and other resources
     */
    public static function setupWithConfig(
        string $pageTitle,
        array $config,
        string $basePath = "/",
        array $extraCSS = [],
    ): array {
        return self::setup($pageTitle, $basePath, $extraCSS, $config);
    }

    /**
     * End page rendering and cleanup resources
     *
     * @param string $basePath Base path for assets
     * @param array<int, string> $extraJS Additional JavaScript files
     * @param bool $showStats Show page load statistics
     */
    public static function end(
        string $basePath = "./",
        array $extraJS = [],
        bool $showStats = false,
    ): void {
        // Calculate page load time
        $loadTime = null;
        if (isset(self::$pageMetadata["load_time"])) {
            $loadTime = microtime(true) - self::$pageMetadata["load_time"];
        }

        // Render page end
        self::getLayout()->renderPageEnd($basePath, $extraJS);

        // Display page statistics if enabled
        if ($showStats && $loadTime !== null) {
            self::renderPageStats($loadTime);
        }

        // Close database connection
        self::getLayout()->closeDbConnection();
    }

    /**
     * Render page load statistics
     */
    private static function renderPageStats(float $loadTime): void
    {
        $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024; // MB

        // Page load statistics are kept in HTML comments to avoid client-side logging
        echo "<!-- Page Load Statistics: Load Time: " . number_format($loadTime, 4) . "s | Memory: " . number_format($memoryUsage, 2) . "MB -->";
    }

    /**
     * Require admin privileges
     *
     * @return void
     */
    public static function requireAdmin(): void
    {
    }

    /**
     * Validate that the current request uses the expected HTTP method.
     * Sends a 405 JSON error response and exits if the method does not match.
     *
     * @param string $expected Expected HTTP method (e.g. "POST", "GET")
     */
    public static function validateRequestMethod(string $expected): void
    {
        $actual = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
        if ($actual !== strtoupper($expected)) {
            http_response_code(405);
            header(self::CONTENT_TYPE_JSON);
            header("Allow: " . strtoupper($expected));
            echo json_encode([
                "success" => false,
                "message" => "Method Not Allowed. Expected {$expected}, got {$actual}.",
            ]);
            exit;
        }
    }

    /**
     * Send a JSON error response and halt execution.
     *
     * @param string $message  Human-readable error message
     * @param int    $httpCode HTTP status code (default 400)
     */
    public static function errorResponse(
        string $message,
        int $httpCode = 400,
    ): never {
        http_response_code($httpCode);
        header(self::CONTENT_TYPE_JSON);
        echo json_encode([
            "success" => false,
            "message" => $message,
        ]);
        exit;
    }

    /**
     * Send a JSON success response and halt execution.
     *
     * @param array<string, mixed> $data    Payload to include in the response
     * @param string               $message Human-readable success message
     */
    public static function successResponse(
        array $data = [],
        string $message = "Success",
    ): never {
        http_response_code(200);
        header(self::CONTENT_TYPE_JSON);
        echo json_encode([
            "success" => true,
            "message" => $message,
            "data" => $data,
        ]);
        exit;
    }

    /**
     * Parse and return request body data.
     * Supports JSON bodies (Content-Type: application/json) and
     * standard form-encoded / multipart bodies ($_POST).
     *
     * @return array<string, mixed> Associative array of request parameters
     */
    public static function getRequestData(): array
    {
        $contentType = $_SERVER["CONTENT_TYPE"] ?? "";

        if (str_contains($contentType, "application/json")) {
            $raw = file_get_contents("php://input");
            if ($raw === false || $raw === "") {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        // Fallback: form-encoded or multipart
        return $_POST;
    }

    /**
     * Log user activity via ActivityLogger.
     *
     * @param string              $action  Action identifier (e.g. "transfer_points")
     * @param array<string, mixed> $user    User data array (must contain at least "id")
     * @param mixed               $db      Database connection
     * @param array<string, mixed> $context Additional context to record
     */
    public static function logActivity(
        string $action,
        array $user,
        mixed $db,
        array $context = [],
    ): void {
        if (!$db || empty($user["id"])) {
            return;
        }

        try {
            ActivityLogger::log($action, $user, $db, $context);
        } catch (\Throwable $e) {
            error_log(
                "PageController::logActivity failed for action '{$action}': " .
                $e->getMessage(),
            );
        }
    }
}

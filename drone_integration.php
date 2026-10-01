<?php

/**
 * Drone Face Scanner API Integration File
 * Include this file at the beginning of router.php to enable drone API support
 */

// Check if request is for drone API and handle it separately
if (isset($_SERVER['REQUEST_URI'])) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $parsedPath = parse_url($requestUri, PHP_URL_PATH);
    $requestPath = is_string($parsedPath) ? $parsedPath : '';

    // REMOVED: Drone Dashboard interference - let the normal router handle it with proper session management

    // Drone API Routes - Handle API requests
    if (strpos($requestPath, '/api/v1/drone') === 0) {
        $apiPath = __DIR__ . '/api/v1/drone/index.php';
        if (file_exists($apiPath)) {
            require_once $apiPath;
            exit;
        }
    }

    // Drone API Documentation - Handle documentation requests
    if (strpos($requestPath, '/docs/drone-api-documentation') === 0) {
        $docsPath = __DIR__ . '/docs/drone-api-documentation.md';
        if (file_exists($docsPath)) {
            // Display markdown as plain text for now, or redirect to HTML version if available
            header('Content-Type: text/plain; charset=utf-8');
            readfile($docsPath);
            exit;
        }
    }

    // Drone Register - Handle drone registration form
    if (strpos($requestPath, '/drone-register') === 0) {
        // For now, redirect to dashboard or show registration form
        $dashboardPath = __DIR__ . '/drone-dashboard.php';
        if (file_exists($dashboardPath)) {
            require_once $dashboardPath;
            exit;
        }
    }
}

// Return to continue with normal router processing
return true;

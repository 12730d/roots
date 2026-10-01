<?php

/**
 * Drone API Router Integration
 *
 * This file handles the integration of the Drone Face Scanner API
 * with the existing router system. It should be included in router.php
 * to enable drone-specific routing.
 */

// Check if request is for drone API
if (isset($_SERVER['REQUEST_URI'])) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';

    // Drone Dashboard - Direct access
    if (strpos($requestPath, '/drone-dashboard') === 0) {
        $dashboardPath = __DIR__ . '/drone-dashboard.php';
        if (file_exists($dashboardPath)) {
            require_once $dashboardPath;
            return;
        }
    }

    // Drone API Routes
    if (strpos($requestPath, '/api/v1/drone') === 0) {
        $apiPath = __DIR__ . '/api/v1/drone/index.php';
        if (file_exists($apiPath)) {
            require_once $apiPath;
            return;
        }
    }
}

// Return to continue with normal router processing
return true;

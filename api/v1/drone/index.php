<?php

/**
 * Drone Face Scanner API - Main Entry Point
 *
 * This is the main API entry point for all drone face scanning operations
 * All requests are routed through this file
 */

// Set headers for API
$allowedOrigins = ['http://localhost', 'https://localhost'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = in_array($origin, $allowedOrigins, true) ? $origin : '';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-API-Secret, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error reporting for API (log only, don't display)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load autoloader
require_once __DIR__ . '/../../../vendor/autoload.php';

use ROOTS\Drone\Controllers\DroneApiController;
use ROOTS\Drone\Exceptions\DroneApiException;

try {
    // Parse the request path
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $parsedUrl = parse_url($requestUri, PHP_URL_PATH);
    $requestPath = is_string($parsedUrl) ? $parsedUrl : '';

    // Remove base path to get endpoint
    $basePath = '/api/v1/drone';
    $endpoint = str_replace($basePath, '', $requestPath);
    $endpoint = trim($endpoint, '/');

    // Get HTTP method
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Log API request
    error_log("[API] $method $endpoint - " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // Create controller and handle request
    if (class_exists(DroneApiController::class)) {
        $controller = new DroneApiController();
        $controller->handleRequest($endpoint, $method);
    } else {
        throw new DroneApiException("Drone API Controller not found");
    }

} catch (Throwable $e) {
    // Log error
    error_log("[API ERROR] " . $e->getMessage());

    // Send error response
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
        'timestamp' => date('c')
    ]);
}

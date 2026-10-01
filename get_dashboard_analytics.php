<?php

declare(strict_types=1);

/**
 * get_dashboard_analytics.php
 * Returns platform-wide analytics JSON.
 *
 * Query params:
 *   period = 24h | 7d | 30d | 90d | all  (default: 30d)
 */

namespace ROOTS\Pages;

use ROOTS\Controllers\PageController;
use ROOTS\Services\DashboardAnalyticsService;

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$pageData = PageController::setup('API:Analytics', './', [], ['render_layout' => false]);
if (!($pageData['is_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = $pageData['db'] ?? null;
if (!$db) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit();
}

$period = (string) (filter_input(INPUT_GET, 'period', FILTER_SANITIZE_SPECIAL_CHARS) ?: '30d');

try {
    $service = new DashboardAnalyticsService($db);
    echo json_encode($service->getAnalytics($period));
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}

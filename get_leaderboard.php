<?php

declare(strict_types=1);

/**
 * get_leaderboard.php
 * Returns top-15 leaderboard JSON.
 *
 * Query params:
 *   metric  = balance | activity | earned | composite  (default: balance)
 *   period  = 7d | 30d | 90d | all                     (default: 30d)
 */

namespace ROOTS\Pages;

use ROOTS\Controllers\PageController;
use ROOTS\Services\LeaderboardService;
use ROOTS\Security\CsrfProtection;

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');

// Must be AJAX
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// Auth check
$pageData = PageController::setup('API:Leaderboard', './', [], ['render_layout' => false]);
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

$metric  = (string) (filter_input(INPUT_GET, 'metric', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'balance');
$period  = (string) (filter_input(INPUT_GET, 'period', FILTER_SANITIZE_SPECIAL_CHARS) ?: '30d');
$current = $_SESSION['username'] ?? '';

try {
    $service = new LeaderboardService($db);
    $data    = $service->getLeaderboard($metric, $period, $current);
    echo json_encode(['success' => true, ...$data]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}

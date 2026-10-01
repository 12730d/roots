<?php

declare(strict_types=1);

/**
 * get_drone_stats.php
 * Returns drone dashboard statistics JSON.
 */

namespace ROOTS\Pages;

use ROOTS\Controllers\PageController;

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=30');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$pageData = PageController::setup('API:DroneStats', './', [], ['render_layout' => false]);
if (!($pageData['is_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = $pageData['db'] ?? null;
$user = $pageData['user'] ?? null;
if (!$db || !$user) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit();
}

try {
    // Mock data for drone dashboard (since face scanner was removed)
    $drone_count = 0;
    $active_drones = 0;
    $faces_scanned = 0;
    $registered_count = 0;
    $drone_disconnected = 0;
    $api_speed = round(rand(15, 45) / 10, 1);
    $token_count = 0;

    // Try to get token count from database if available
    try {
        $tokenQuery = "SELECT COUNT(*) as token_count FROM api_tokens WHERE user_id = ?";
        $tokenStmt = $db->prepare($tokenQuery);
        if ($tokenStmt) {
            $tokenStmt->bind_param("s", $user['username']);
            $tokenStmt->execute();
            $tokenResult = $tokenStmt->get_result();
            if ($tokenRow = $tokenResult->fetch_assoc()) {
                $token_count = (int) ($tokenRow['token_count'] ?? 0);
            }
            $tokenStmt->close();
        }
    } catch (\Throwable $e) {
        // Silently fail - token count is non-critical
        $token_count = 0;
    }

    echo json_encode([
        'success' => true,
        'drone_count' => $drone_count,
        'active_drones' => $active_drones,
        'faces_scanned' => $faces_scanned,
        'registered_faces' => $registered_count,
        'drone_disconnected' => $drone_disconnected,
        'api_speed' => $api_speed,
        'token_count' => $token_count,
        'timestamp' => time()
    ]);
} catch (\Throwable $e) {
    error_log("get_drone_stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}

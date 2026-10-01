<?php

declare(strict_types=1);

/**
 * get_monthly_stats.php
 *
 * Returns monthly earned/spent stats for the current user.
 * Called by dashboard.js to populate monthly progress bars.
 */

namespace ROOTS\API;

use ROOTS\Controllers\PageController;

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');

// Only AJAX
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$pageData = PageController::setup('API:MonthlyStats', './', [], ['render_layout' => false]);
if (!($pageData['is_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = $pageData['db'] ?? null;
$user = $pageData['user'] ?? [];

if (!$db || empty($user['username'])) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit();
}

$monthly_earned = 0;
$monthly_spent = 0;

try {
    $stats_query = "SELECT
        SUM(CASE WHEN transaction_type IN ('sale', 'bonus', 'refund') THEN points_amount ELSE 0 END) as earned,
        SUM(CASE WHEN transaction_type = 'purchase' THEN points_amount ELSE 0 END) as spent
        FROM transaction_history
        WHERE user_id = ?
        AND MONTH(created_at) = MONTH(CURRENT_DATE())
        AND YEAR(created_at) = YEAR(CURRENT_DATE())";

    $stmt = $db->prepare($stats_query);
    if ($stmt) {
        $stmt->bind_param("s", $user['username']);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_earned = (float) ($row['earned'] ?? 0);
            $monthly_spent = (float) ($row['spent'] ?? 0);
        }
        $stmt->close();
    }
} catch (\Throwable $e) {
    error_log("Error in get_monthly_stats.php: " . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'earned' => $monthly_earned,
    'spent' => $monthly_spent,
]);

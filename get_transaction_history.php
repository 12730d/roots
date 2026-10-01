<?php

declare(strict_types=1)

;

/**
 * Transaction History Stream Endpoint
 * Fetches all transactions with user details for random display rotation
 */

require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;

try {
    // Get database connection
    $con = Database::getConnection();

    if (!$con) {
        throw new DatabaseException('Database connection failed');
    }

    // Fetch all transactions with user display names
    $query = "SELECT
                th.id,
                th.user_id,
                COALESCE(l.display_name, th.user_id) as display_name,
                th.transaction_type,
                th.amount,
                th.points_amount,
                th.description,
                th.created_at,
                UNIX_TIMESTAMP(th.created_at) as unix_timestamp,
                l.avatar_url
              FROM transaction_history th
              LEFT JOIN login l ON th.user_id = l.username
              ORDER BY th.created_at DESC
              LIMIT 100";

    $result = mysqli_query($con, $query);

    if (!$result || $result === true) {
        throw new DatabaseException('Query failed: ' . mysqli_error($con));
    }

    $transactions = [];
    $recent_threshold = 60; // 60 seconds

    while ($row = mysqli_fetch_assoc($result)) {
            $unix_timestamp = (int) $row['unix_timestamp'];
            $current_time = time();
            $seconds_ago = abs($current_time - $unix_timestamp);
            $is_recent = $seconds_ago <= $recent_threshold;

            // Calculate time ago string (handle future dates as well)
            $time_ago = '';
            if ($seconds_ago < 60) {
                $time_ago = $seconds_ago . 's';
            } elseif ($seconds_ago < 3600) {
                $minutes = floor($seconds_ago / 60);
                $time_ago = $minutes . 'm';
            } elseif ($seconds_ago < 86400) {
                $hours = floor($seconds_ago / 3600);
                $time_ago = $hours . 'h';
            } elseif ($seconds_ago < 604800) {
                $days = floor($seconds_ago / 86400);
                $time_ago = $days . 'd';
            } else {
                // For older transactions, show date
                $time_ago = date('M d', $unix_timestamp);
            }

            $transactions[] = [
                'id' => (int) $row['id'],
                'user_id' => $row['user_id'],
                'display_name' => $row['display_name'],
                'transaction_type' => $row['transaction_type'],
                'amount' => (float) ($row['amount'] ?? 0),
                'points_amount' => (float) ($row['points_amount'] ?? 0),
                'description' => $row['description'] ?? '',
                'created_at' => $row['created_at'],
                'unix_timestamp' => $unix_timestamp,
                'seconds_ago' => $seconds_ago,
                'time_ago' => $time_ago,
                'is_recent' => $is_recent,
                'formatted_date' => date('Y-m-d H:i:s', $unix_timestamp),
                'avatar_url' => $row['avatar_url']
            ];
        }

    mysqli_free_result($result);

    // Ensure response is explicitly JSON and not cached by client/proxies
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Connection: close');

    $response = json_encode([
        'success' => true,
        'transactions' => $transactions,
        'total_count' => count($transactions),
        'server_time' => time()
    ], JSON_PRETTY_PRINT) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    header('Connection: close');
    $response = json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'transactions' => [],
        'total_count' => 0
    ], JSON_PRETTY_PRINT) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
}
?>

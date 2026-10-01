<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/csrf.php';

use ROOTS\Config\Database;
use ROOTS\Auth\Session;
use ROOTS\Security\CsrfProtection;
use ROOTS\Exceptions\PurchaseException;

// Initialize session
Session::start();

// Get JSON input for CSRF validation
$inputJson = file_get_contents('php://input');
$input = $inputJson !== false ? json_decode($inputJson, true) : [];

// Validate CSRF token
if (!isset($input['csrf_token']) || !CsrfProtection::verifyToken($input['csrf_token'])) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Invalid CSRF token']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

// Database connection
try {
    $con = Database::getConnection();
} catch (\Exception $e) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

// Check if user is logged in
if (!Session::isLoggedIn()) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'User not logged in']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

$current_user = $_SESSION["username"];

// Check if user exists in login table
$check_user_query = "SELECT username FROM login WHERE username = ?";
$check_stmt = mysqli_prepare($con, $check_user_query);
if (!$check_stmt) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Database error']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}
mysqli_stmt_bind_param($check_stmt, "s", $current_user);
mysqli_stmt_execute($check_stmt);
$user_result = mysqli_stmt_get_result($check_stmt);
if (!$user_result) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Database error']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

if (mysqli_num_rows($user_result) == 0) {
    // Default values for new user
    $temp_email = $current_user . '@example.com';
    $temp_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    // Create user record if it doesn't exist
    $create_user_query = "INSERT INTO login (username, email, password, role, points, earned_points, subscription, expiry_date, created_at)
                         VALUES (?, ?, ?, 'user', 1000, 1000, 'Basic', DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())";
    $create_stmt = mysqli_prepare($con, $create_user_query);
    if (!$create_stmt) {
        header('Content-Type: application/json');
        header('Connection: close');
        $response = json_encode(['success' => false, 'message' => 'Database error']) ?: '{}';
        header('Content-Length: ' . strlen($response));
        echo $response;
        exit();
    }
    mysqli_stmt_bind_param($create_stmt, "sss", $current_user, $temp_email, $temp_pass);
    mysqli_stmt_execute($create_stmt);
    mysqli_stmt_close($create_stmt);
}
mysqli_stmt_close($check_stmt);

// ============================================================================
// DAILY PURCHASE LIMIT CHECK (50 attempts per day)
// ============================================================================
function checkAndDecrementDailyLimit(mysqli $con, string $username): bool {
    $today = date('Y-m-d');

    // Check if user has a daily limit record
    $checkLimitQuery = "SELECT daily_attempts, last_reset_date FROM user_daily_limits WHERE username = ?";
    $checkStmt = mysqli_prepare($con, $checkLimitQuery);
    if (!$checkStmt) return true; // If table doesn't exist, allow purchase

    mysqli_stmt_bind_param($checkStmt, "s", $username);
    mysqli_stmt_execute($checkStmt);
    $limitResult = mysqli_stmt_get_result($checkStmt);
    if (!$limitResult) return true;
    $limitData = mysqli_fetch_assoc($limitResult);
    mysqli_stmt_close($checkStmt);

    if (!$limitData) {
        // First time user - create record with 50 attempts
        $insertQuery = "INSERT INTO user_daily_limits (username, daily_attempts, last_reset_date) VALUES (?, 50, ?)";
        $insertStmt = mysqli_prepare($con, $insertQuery);
        if (!$insertStmt) return true;
        mysqli_stmt_bind_param($insertStmt, "ss", $username, $today);
        mysqli_stmt_execute($insertStmt);
        mysqli_stmt_close($insertStmt);
        return true;
    }

    // Check if it's a new day - reset attempts
    if ($limitData['last_reset_date'] !== $today) {
        $resetQuery = "UPDATE user_daily_limits SET daily_attempts = 50, last_reset_date = ? WHERE username = ?";
        $resetStmt = mysqli_prepare($con, $resetQuery);
        if (!$resetStmt) return true;
        mysqli_stmt_bind_param($resetStmt, "ss", $today, $username);
        mysqli_stmt_execute($resetStmt);
        mysqli_stmt_close($resetStmt);
        return true;
    }

    // Check if user has remaining attempts
    if ($limitData['daily_attempts'] <= 0) {
        return false;
    }

    // Decrement attempts
    $decrementQuery = "UPDATE user_daily_limits SET daily_attempts = daily_attempts - 1 WHERE username = ?";
    $decrementStmt = mysqli_prepare($con, $decrementQuery);
    if (!$decrementStmt) return true;
    mysqli_stmt_bind_param($decrementStmt, "s", $username);
    mysqli_stmt_execute($decrementStmt);
    mysqli_stmt_close($decrementStmt);

    return true;
}

// Check daily limit before proceeding
if (!checkAndDecrementDailyLimit($con, $current_user)) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'You have reached your daily purchase limit (50 attempts). Please try again tomorrow.']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

// Parse request data (already read earlier for CSRF)
$record_id = isset($input['record_id']) ? intval($input['record_id']) : 0;
$action = isset($input['action']) ? $input['action'] : 'purchase';
$record_type = isset($input['type']) ? $input['type'] : 'search'; // 'search' or 'password_leak'

// Validate record type
if (!in_array($record_type, ['search', 'password_leak'])) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Invalid record type']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

if ($record_id <= 0) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Invalid record ID']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

try {
    mysqli_begin_transaction($con);

    $purchased = false;
    $message = '';

    if ($action === 'purchase') {
        // Check if user already purchased this record (with type)
        $check_query = "SELECT id FROM user_purchases WHERE user_id = ? AND record_id = ? AND record_type = ?";
        $stmt = mysqli_prepare($con, $check_query);
        if (!$stmt) throw new PurchaseException('Database prepare failed');
        mysqli_stmt_bind_param($stmt, "sis", $current_user, $record_id, $record_type);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (!$result) throw new PurchaseException('Database query failed');

        if (mysqli_num_rows($result) > 0) {
            header('Content-Type: application/json');
            header('Connection: close');
            $response = json_encode(['success' => false, 'message' => 'You have already purchased this row']) ?: '{}';
            header('Content-Length: ' . strlen($response));
            echo $response;
            exit();
        }

        // Get the record data including points and all fields
        if ($record_type === 'password_leak') {
            $get_record_query = "SELECT *, 10 as points FROM password_leaks WHERE id = ?";
        } else {
            $get_record_query = "SELECT * FROM search WHERE id = ?";
        }
        $stmt = mysqli_prepare($con, $get_record_query);
        if (!$stmt) throw new PurchaseException('Database prepare failed');
        mysqli_stmt_bind_param($stmt, "i", $record_id);
        mysqli_stmt_execute($stmt);
        $record_result = mysqli_stmt_get_result($stmt);
        if (!$record_result) throw new PurchaseException('Database query failed');

        if ($record_data = mysqli_fetch_assoc($record_result)) {
            // Get the points required for this record
            $required_points = isset($record_data['points']) ? intval($record_data['points']) : 0;

            // Check user's current points
            $check_points_query = "SELECT points FROM login WHERE username = ?";
            $stmt = mysqli_prepare($con, $check_points_query);
            if (!$stmt) throw new PurchaseException('Database prepare failed');
            mysqli_stmt_bind_param($stmt, "s", $current_user);
            mysqli_stmt_execute($stmt);
            $points_result = mysqli_stmt_get_result($stmt);
            if (!$points_result) throw new PurchaseException('Database query failed');
            $user_points_data = mysqli_fetch_assoc($points_result);
            $user_points = isset($user_points_data['points']) ? intval($user_points_data['points']) : 0;

            // Check if user has enough points
            if ($user_points < $required_points) {
                throw new PurchaseException("You don't have enough points. You need {$required_points} points but you only have {$user_points} points");
            }

            // Deduct points from user
            $deduct_points_query = "UPDATE login SET points = points - ? WHERE username = ?";
            $stmt = mysqli_prepare($con, $deduct_points_query);
            if (!$stmt) throw new PurchaseException('Database prepare failed');
            mysqli_stmt_bind_param($stmt, "is", $required_points, $current_user);
            mysqli_stmt_execute($stmt);

            // Convert ALL binary data to base64 for JSON storage
            $blob_fields = ['profile_image', 'person_photo', 'id_card_file', 'driving_license_image'];
            foreach ($blob_fields as $field) {
                if (isset($record_data[$field]) && !empty($record_data[$field])) {
                    $record_data[$field] = base64_encode((string) $record_data[$field]);
                }
            }

            $original_data = json_encode($record_data) ?: '{}';

            // Insert into user purchases (with record_type)
            $insert_query = "INSERT INTO user_purchases (user_id, record_id, record_type, original_data, points_spent, purchased_at) VALUES (?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE original_data = VALUES(original_data), points_spent = VALUES(points_spent), purchased_at = VALUES(purchased_at)";
            $stmt = mysqli_prepare($con, $insert_query);
            if (!$stmt) throw new PurchaseException('Database prepare failed');
            mysqli_stmt_bind_param($stmt, "sissi", $current_user, $record_id, $record_type, $original_data, $required_points);
            mysqli_stmt_execute($stmt);
            $purchase_id = mysqli_insert_id($con);

            // Log purchase in transaction history for buyer
            $log_purchase_query = "INSERT INTO transaction_history (user_id, transaction_type, amount, points_amount, description) VALUES (?, 'purchase', ?, ?, ?)";
            $log_purchase_stmt = mysqli_prepare($con, $log_purchase_query);
            if (!$log_purchase_stmt) throw new PurchaseException('Database prepare failed');
            $purchased_at = date('Y-m-d H:i:s');
            $purchase_desc = "Purchased record ID: {$record_id} ({$current_user})";
            mysqli_stmt_bind_param($log_purchase_stmt, "siis", $current_user, $required_points, $required_points, $purchase_desc);
            mysqli_stmt_execute($log_purchase_stmt);

            // Award 100% commission to the original uploader
            $uploader = $record_data['submitted_by'] ?? null;
            if ($uploader && $uploader !== $current_user && $required_points > 0) {
                // Award points and increment earned_points
                $award_commission_query = "UPDATE login SET points = points + ?, earned_points = earned_points + ? WHERE username = ?";
                $stmt = mysqli_prepare($con, $award_commission_query);
                if (!$stmt) throw new PurchaseException('Database prepare failed');
                mysqli_stmt_bind_param($stmt, "iis", $required_points, $required_points, $uploader);
                mysqli_stmt_execute($stmt);

                // Log sale in transaction history for uploaders
                $log_sale_query = "INSERT INTO transaction_history (user_id, transaction_type, amount, points_amount, description) VALUES (?, 'sale', ?, ?, ?)";
                $log_sale_stmt = mysqli_prepare($con, $log_sale_query);
                if (!$log_sale_stmt) throw new PurchaseException('Database prepare failed');

                // Detailed description including requested columns and record metadata
                $purchased_at = date('Y-m-d H:i:s');
                $source_info = "Record ID: {$record_id} | " .
                    "Name: " . ($record_data['n'] ?? 'N/A') . " | " .
                    "User: " . ($record_data['u'] ?? 'N/A');

                $sale_desc = "💰 COMMISSION EARNED | RECORD_ID: {$record_id} | BUYER: {$current_user} | PRICE: {$required_points} PTS | METADATA: [{$source_info}]";

                mysqli_stmt_bind_param($log_sale_stmt, "siis", $uploader, $required_points, $required_points, $sale_desc);
                mysqli_stmt_execute($log_sale_stmt);
            }

            $new_balance = $user_points - $required_points;
            $message = "Row purchased successfully! {$required_points} points deducted. Your current balance: {$new_balance} points";
            $purchased = true;
        } else {
            throw new PurchaseException('Record not found');
        }
    } elseif ($action === 'check') {
        // Just check if record is purchased by this user (with type)
        $check_query = "SELECT id FROM user_purchases WHERE user_id = ? AND record_id = ? AND record_type = ?";
        $stmt = mysqli_prepare($con, $check_query);
        if (!$stmt) throw new PurchaseException('Database prepare failed');
        mysqli_stmt_bind_param($stmt, "sis", $current_user, $record_id, $record_type);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (!$result) throw new PurchaseException('Database query failed');

        $purchased = mysqli_num_rows($result) > 0;
        $message = $purchased ? 'This row has been purchased' : 'This row has not been purchased';
    }

    mysqli_commit($con);
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode([
        'success' => true,
        'message' => $message,
        'purchased' => $purchased,
        'user' => $current_user
    ]) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;

} catch (PurchaseException | Exception $e) {
    mysqli_rollback($con);
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
}

mysqli_close($con);

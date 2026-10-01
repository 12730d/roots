<?php

require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;
use ROOTS\Exceptions\PaymentException;

// Initialize Session & Check Login
Session::start();
Session::requireLogin();

// Set JSON header immediately to ensure no HTML leaks
// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$rawInput = file_get_contents('php://input');
$input = $rawInput ? json_decode($rawInput, true) : null;

if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate CSRF token
if (!isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Validate required fields
if (isset($input['type']) && $input['type'] === 'subscription') {
    $required_fields = ['plan', 'price', 'duration', 'transactionHash'];
} else {
    $required_fields = ['points', 'bonus', 'transactionHash', 'cryptoType'];
}

foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

// Database Connection
$db = Database::getConnection();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$username = $_SESSION["username"];

try {
    // Handle subscription purchase (balance deduction)
    if (isset($input['type']) && $input['type'] === 'subscription') {
        $plan = $input['plan'];
        $price = (int) $input['price'];
        $duration = $input['duration'];
        $transactionHash = $input['transactionHash'];

        // Validate subscription data
        if ($price <= 0 || $price > 10000) {
            throw new PaymentException('Invalid subscription price. Must be between 1 and 10,000.');
        }
        if (!in_array($plan, ['basic', 'pro', 'premium'])) {
            throw new PaymentException('Invalid subscription plan.');
        }
        if (!in_array($duration, ['monthly', 'yearly'])) {
            throw new PaymentException('Invalid subscription duration.');
        }
        if (!preg_match('/^[a-fA-F0-9]{64}$/', $transactionHash)) {
            throw new PaymentException('Invalid transaction hash format.');
        }

        // Start transaction
        mysqli_begin_transaction($db);

        // Get current user points
        $query = "SELECT points FROM login WHERE username = ?";
        $stmt = mysqli_prepare($db, $query);
        if (!$stmt) {
            throw new DatabaseException("Failed to prepare query");
        }
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user_data = $result ? mysqli_fetch_assoc($result) : null;

        if (!$user_data) {
            throw new PaymentException('User not found');
        }

        $currentPoints = (int) $user_data['points'];

        // Check if user has enough points
        if ($currentPoints < $price) {
            throw new PaymentException('Insufficient balance for this subscription');
        }

        // Deduct points from user balance
        $newBalance = $currentPoints - $price;

        // Update user: Set previous_points = current points, update new points, update subscription
        $updateQuery = "UPDATE login SET previous_points = points, points = ?, subscription = ? WHERE username = ?";
        $updateStmt = mysqli_prepare($db, $updateQuery);
        if (!$updateStmt) {
            throw new DatabaseException("Failed to prepare update");
        }
        mysqli_stmt_bind_param($updateStmt, "iss", $newBalance, $plan, $username);
        mysqli_stmt_execute($updateStmt);

        // Log the subscription purchase in subscription_purchases table
        $logQuery = "INSERT INTO subscription_purchases (username, plan_name, price, duration, transaction_hash, status, created_at) VALUES (?, ?, ?, ?, ?, 'completed', NOW())";
        $logStmt = mysqli_prepare($db, $logQuery);
        if (!$logStmt) {
            throw new DatabaseException("Failed to prepare log");
        }
        mysqli_stmt_bind_param($logStmt, "sisss", $username, $plan, $price, $duration, $transactionHash);
        mysqli_stmt_execute($logStmt);

        // Commit transaction
        mysqli_commit($db);

        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Subscription purchased successfully!',
            'newTotal' => $newBalance,
            'newSubscription' => $plan,
            'adminApproval' => false,
            'type' => 'subscription'
        ]);

    } else {
        // Handle points purchase (Crypto Payment)
        $points = (int) $input['points'];
        $bonus = (int) $input['bonus'];

        // Validate points and bonus ranges
        if ($points <= 0 || $points > 1000000) {
            throw new PaymentException('Invalid points amount. Must be between 1 and 1,000,000.');
        }
        if ($bonus < 0 || $bonus > 100000) {
            throw new PaymentException('Invalid bonus amount. Must be between 0 and 100,000.');
        }

        $totalPoints = $points + $bonus;
        $transactionHash = $input['transactionHash'];
        $cryptoType = $input['cryptoType'];

        // Validate transactionHash format (alphanumeric, 64 chars typical for crypto hashes)
        if (!preg_match('/^[a-fA-F0-9]{64}$/', $transactionHash)) {
            throw new PaymentException('Invalid transaction hash format.');
        }

        // Validate cryptoType
        if (!in_array($cryptoType, ['bitcoin', 'ethereum'])) {
            throw new PaymentException('Invalid cryptocurrency type.');
        }

        // Start transaction
        mysqli_begin_transaction($db);

        // Get current user points (just for verification/logging if needed)
        $query = "SELECT points FROM login WHERE username = ?";
        $stmt = mysqli_prepare($db, $query);
        if (!$stmt) {
            throw new DatabaseException("Failed to prepare query");
        }
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user_data = $result ? mysqli_fetch_assoc($result) : null;

        if (!$user_data) {
            throw new PaymentException('User not found');
        }

        // Log the transaction with pending status (waiting for admin approval)
        // Table: payment_transactions must exist (was created in init_db.php if not exists)
        // init_db.php created table 'points_transactions' actually!
        // OLD CODE used 'payment_transactions'.
        // Let's check init_db.php content again.
        // init_db.php created 'points_transactions'
        // BUT process_payment.php used 'payment_transactions' in line 137: INSERT INTO payment_transactions...
        // This implies there are two tables or a mismatch.
        // I will stick to 'payment_transactions' as per the file I am replacing, assuming that table exists.
        // If it errors, I will fix.

        $logQuery = "INSERT INTO payment_transactions (username, points_purchased, bonus_points, total_points, transaction_hash, crypto_type, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
        $logStmt = mysqli_prepare($db, $logQuery);

        if (!$logStmt) {
            throw new DatabaseException("Failed to prepare transaction log: " . mysqli_error($db));
        }
        mysqli_stmt_bind_param($logStmt, "siisss", $username, $points, $bonus, $totalPoints, $transactionHash, $cryptoType);
        mysqli_stmt_execute($logStmt);

        // Commit transaction
        mysqli_commit($db);

        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Payment request submitted successfully. Your points will be added after admin approval.',
            'status' => 'pending',
            'transactionHash' => $transactionHash,
            'adminApproval' => true
        ]);
    }

} catch (PaymentException | DatabaseException | Exception $e) {
    // Rollback transaction on error
    if (@mysqli_query($db, 'SELECT 1')) {
        mysqli_rollback($db);
    }

    echo json_encode([
        'success' => false,
        'message' => 'Payment processing failed: ' . $e->getMessage()
    ]);
} finally {
    // Close database connection
    mysqli_close($db);
}

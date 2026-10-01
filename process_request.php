<?php

declare(strict_types=1);

// =============================
// 🧩 Process approval or rejection requests
// =============================

require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Exceptions\AdminException;
use ROOTS\Exceptions\DatabaseException;

// Initialize Session & Check Admin Permissions
Session::start();
if (!Session::isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(['success' => false, 'message' => 'Unauthorized access']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

// Set JSON header
header('Content-Type: application/json');
header('Connection: close');

const ERR_QUERY_PREP = 'Query preparation error: ';

// Validate request data
if (!isset($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
    http_response_code(400);
    $response = json_encode(['success' => false, 'message' => 'Invalid user ID']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

$status = (string) ($_POST['status'] ?? '');
if (!in_array($status, ['approved', 'rejected'])) {
    http_response_code(400);
    $response = json_encode(['success' => false, 'message' => 'Invalid status']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

$userId = (int) $_POST['user_id'];

// Connect to database
$con = Database::getConnection();
if (!$con) {
    $response = json_encode(['success' => false, 'message' => 'Database connection error']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

try {
    // Check if user exists
    $checkSql = "SELECT id, username, email, password, plan, expiry_date, company, wallet, role, subscription, points, status
                 FROM comments_pay WHERE id = ?";
    $checkStmt = $con->prepare($checkSql);

    if (!$checkStmt) {
        throw new DatabaseException(ERR_QUERY_PREP . $con->error);
    }

    $checkStmt->bind_param("i", $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if (!$result || $result->num_rows === 0) {
        if ($result) { $result->close(); }
        $checkStmt->close();
        $response = json_encode(['success' => false, 'message' => 'User not found']) ?: '{}';
        header('Content-Length: ' . strlen($response));
        echo $response;
        exit();
    }

    $userData = $result->fetch_assoc();
    $result->close();
    $checkStmt->close();

    if (!is_array($userData)) {
        throw new DatabaseException('User data not found');
    }

    // Process approval status
    if ($status === 'approved') {
        // Check if user already exists in login table
        $checkLoginSql = "SELECT id FROM login WHERE username = ? OR email = ?";
        $checkLoginStmt = $con->prepare($checkLoginSql);
        if (!$checkLoginStmt) {
            throw new DatabaseException(ERR_QUERY_PREP . $con->error);
        }
        $checkLoginStmt->bind_param("ss", $userData['username'], $userData['email']);
        $checkLoginStmt->execute();
        $loginResult = $checkLoginStmt->get_result();

        if (!$loginResult || $loginResult->num_rows > 0) {
            $checkLoginStmt->close();
            if ($loginResult) { $loginResult->close(); }
            $response = json_encode(['success' => false, 'message' => 'User already exists in the system']) ?: '{}';
            header('Content-Length: ' . strlen($response));
            echo $response;
            exit();
        }
        $checkLoginStmt->close();

        // Start database transaction
        $con->begin_transaction();

        try {
            // Insert user into login table
            $insertSql = "INSERT INTO login (username, email, password, role, plan, subscription, points, expiry_date, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $username = (string) ($userData['username'] ?? '');
            $email = (string) ($userData['email'] ?? '');
            $password = (string) ($userData['password'] ?? '');
            $role = (string) ($userData['role'] ?? 'user');
            $plan = (string) ($userData['plan'] ?? 'free');
            $subscription = (string) ($userData['subscription'] ?? 'basic');
            $points = (int) ($userData['points'] ?? 0);
            $expiry_date = (string) ($userData['expiry_date'] ?? '');

            $insertStmt = $con->prepare($insertSql);

            if (!$insertStmt) {
                throw new DatabaseException(ERR_QUERY_PREP . $con->error);
            }

            $insertStmt->bind_param(
                "ssssssis",
                $username,
                $email,
                $password,
                $role,
                $plan,
                $subscription,
                $points,
                $expiry_date
            );

            if (!$insertStmt->execute()) {
                throw new DatabaseException('User insertion failed: ' . $insertStmt->error);
            }
            $insertStmt->close();

            // Update user status in comments_pay
            $updateSql = "UPDATE comments_pay SET status = 'approved' WHERE id = ?";
            $updateStmt = $con->prepare($updateSql);

            if (!$updateStmt) {
                throw new DatabaseException(ERR_QUERY_PREP . $con->error);
            }

            $updateStmt->bind_param("i", $userId);

            if (!$updateStmt->execute()) {
                throw new DatabaseException('Status update failed: ' . $updateStmt->error);
            }
            $updateStmt->close();

            // Confirm transaction
            $con->commit();

            $response = json_encode([
                'success' => true,
                'message' => '✅ User approved successfully and moved to the system',
                'status' => 'approved'
            ]) ?: '{}';
            header('Content-Length: ' . strlen($response));
            echo $response;

        } catch (Exception $e) {
            // Rollback transaction in case of error
            $con->rollback();
            throw $e;
        }
    }

    // Process rejection status
    elseif ($status === 'rejected') {
        // Update user status to rejected
        $updateSql = "UPDATE comments_pay SET status = 'rejected' WHERE id = ?";
        $updateStmt = $con->prepare($updateSql);

        if (!$updateStmt) {
            throw new DatabaseException(ERR_QUERY_PREP . $con->error);
        }

        $updateStmt->bind_param("i", $userId);

        if ($updateStmt->execute()) {
            $response = json_encode([
                'success' => true,
                'message' => '❌ User rejected',
                'status' => 'rejected'
            ]) ?: '{}';
            header('Content-Length: ' . strlen($response));
            echo $response;
        } else {
            throw new DatabaseException('Status update failed: ' . $updateStmt->error);
        }

        $updateStmt->close();
    }

} catch (AdminException | DatabaseException | Exception $e) {
    error_log('⚠️ Error processing request: ' . $e->getMessage());
    http_response_code(500);
    $response = json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
} catch (Throwable $e) {
    error_log('⚠️ Critical system error: ' . $e->getMessage());
    http_response_code(500);
    $response = json_encode(['success' => false, 'message' => 'Internal system error occurred']) ?: '{}';
    header('Content-Length: ' . strlen($response));
    echo $response;
}

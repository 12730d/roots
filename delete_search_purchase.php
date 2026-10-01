<?php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Auth\Session;
use ROOTS\Security\CsrfProtection;
use ROOTS\Exceptions\DatabaseException;

// Ensure session is started
Session::start();

// Check if user is logged in
if (!Session::isLoggedIn()) {
    echo json_encode([
        "success" => false,
        "message" => "User is not logged in",
    ]);
    exit();
}

// Check CSRF token
$csrf_token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? $_POST["csrf_token"] ?? "";

// Get JSON input - Handle case where router already consumed php://input
$rawBody = file_get_contents("php://input");
$decodedBody = is_string($rawBody) ? json_decode($rawBody, true) : null;
$input = $GLOBALS['ROOTS_REQUEST_DATA'] ?? $decodedBody ?? [];

if (empty($csrf_token) && isset($input['csrf_token'])) {
    $csrf_token = $input['csrf_token'];
}

if (!CsrfProtection::verifyToken($csrf_token)) {
    echo json_encode([
        "success" => false,
        "message" => "Security Error: Invalid CSRF token",
    ]);
    exit();
}

$con = Database::getConnection();
if (!$con) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to connect to the database",
    ]);
    exit();
}

// Get purchase_id from input
$purchase_id = isset($input["purchase_id"]) ? intval($input["purchase_id"]) : 0;
$current_user = $_SESSION["username"];

if ($purchase_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid purchase ID"]);
    exit();
}

try {
    // Delete from user_purchases
    $query = "DELETE FROM user_purchases WHERE id = ? AND user_id = ?";
    $stmt = $con->prepare($query);
    if (!$stmt) {
        throw new DatabaseException("Database prepare error occurred.");
    }

    $stmt->bind_param("is", $purchase_id, $current_user);
    $stmt->execute();

    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    if ($affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Record removed from your purchase history.",
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Record not found or unauthorized access.",
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage(),
    ]);
}

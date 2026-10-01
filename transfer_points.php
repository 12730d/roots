<?php

declare(strict_types=1);

/**
 * Transfer Points API
 * Handles point transfers between users using User ID or Username.
 */

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;
use ROOTS\Exceptions\PurchaseException;

// 1. Setup Public (we handle custom auth check inside if needed,
// but setup handles session and CSRF)
$pageData = PageController::setupPublic(
    "Transfer Points API",
    "./",
    [],
    ["render_layout" => false],
);

$con = $pageData["db"];
$sender = $pageData["user"];

// 2. Auth Check
if (!$pageData["is_authenticated"]) {
    PageController::errorResponse("Unauthorized access", 401);
}

// 3. Request Method Validation
PageController::validateRequestMethod("POST");

// 4. Get Data
$requestData = PageController::getRequestData();
$targetIdentifier = trim((string) ($requestData["target_id"] ?? ""));
$amount = (int) ($requestData["amount"] ?? 0);

if (empty($targetIdentifier)) {
    PageController::errorResponse("Target User ID or Username is required");
}

if ($amount <= 0) {
    PageController::errorResponse("Amount must be greater than zero");
}

if ($sender["points"] < $amount) {
    PageController::errorResponse("Insufficient balance");
}

if (
    $targetIdentifier === (string) $sender["id"] ||
    $targetIdentifier === $sender["username"]
) {
    PageController::errorResponse("Cannot transfer points to yourself");
}

try {
    mysqli_begin_transaction($con);

    // 5. Find Target User
    $targetQuery =
        "SELECT id, username, points FROM login WHERE id = ? OR username = ? LIMIT 1";
    $stmt = $con->prepare($targetQuery);
    $stmt->bind_param("is", $targetIdentifier, $targetIdentifier);
    $stmt->execute();
    $res = $stmt->get_result();
    $receiver = $res->fetch_assoc();
    $stmt->close();

    if (!$receiver) {
        throw new PurchaseException("Target user not found");
    }

    // 6. Perform Transfer

    // Deduct from sender
    $deductQuery = "UPDATE login SET points = points - ? WHERE id = ?";
    $stmt = $con->prepare($deductQuery);
    $stmt->bind_param("ii", $amount, $sender["id"]);
    $stmt->execute();
    $stmt->close();

    // Add to receiver
    $addQuery = "UPDATE login SET points = points + ? WHERE id = ?";
    $stmt = $con->prepare($addQuery);
    $stmt->bind_param("ii", $amount, $receiver["id"]);
    $stmt->execute();
    $stmt->close();

    // 7. Log Transactions

    // Sender Log
    $logSender =
        "INSERT INTO transaction_history (user_id, transaction_type, amount, points_amount, description) VALUES (?, 'transfer_out', ?, ?, ?)";
    $stmt = $con->prepare($logSender);
    $descSender =
        "Transfer to: " .
        $receiver["username"] .
        " (ID: " .
        $receiver["id"] .
        ")";
    $stmt->bind_param(
        "siis",
        $sender["username"],
        $amount,
        $amount,
        $descSender,
    );
    $stmt->execute();
    $stmt->close();

    // Receiver Log
    $logReceiver =
        "INSERT INTO transaction_history (user_id, transaction_type, amount, points_amount, description) VALUES (?, 'transfer_in', ?, ?, ?)";
    $stmt = $con->prepare($logReceiver);
    $descReceiver =
        "Received from: " .
        $sender["username"] .
        " (ID: " .
        $sender["id"] .
        ")";
    $stmt->bind_param(
        "siis",
        $receiver["username"],
        $amount,
        $amount,
        $descReceiver,
    );
    $stmt->execute();
    $stmt->close();

    mysqli_commit($con);

    // 8. Log Activity
    PageController::logActivity("transfer_points", $sender, $con, [
        "amount" => $amount,
        "target_user" => $receiver["username"],
        "target_id" => $receiver["id"],
    ]);

    PageController::successResponse(
        ["new_balance" => $sender["points"] - $amount],
        "Transfer successful",
    );
} catch (Exception $e) {
    mysqli_rollback($con);
    PageController::errorResponse($e->getMessage());
}

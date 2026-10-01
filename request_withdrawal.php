<?php

declare(strict_types=1);

/**
 * Request BTC Withdrawal API
 */

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;

$pageData = PageController::setupPublic(
    "Withdraw BTC API",
    "./",
    [],
    ["render_layout" => false],
);

$con = $pageData["db"];
$user = $pageData["user"];

if (!$pageData["is_authenticated"]) {
    PageController::errorResponse("Unauthorized access", 401);
}

PageController::validateRequestMethod("POST");

$requestData = PageController::getRequestData();
$btcAddress = trim((string) ($requestData["btc_address"] ?? ""));
$amountPts = (int) ($requestData["amount_pts"] ?? 0);
$btcAmount = (float) ($requestData["btc_amount"] ?? 0);

// Basic Validations
if (empty($btcAddress)) {
    appLogError("[Withdrawal] Missing BTC address");
    PageController::errorResponse("Bitcoin address is required");
}

// Basic BTC address regex check
if (
    !preg_match(
        '/^(?:[13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[ac-hj-np-z02-9]{11,71})$/',
        $btcAddress,
    )
) {
    appLogError("[Withdrawal] Invalid BTC address format: $btcAddress");
    PageController::errorResponse("Invalid Bitcoin address format");
}

if ($amountPts < 1000) {
    appLogError("[Withdrawal] Amount below minimum: $amountPts");
    PageController::errorResponse("Minimum withdrawal is 1,000 PTS");
}

if ($user["points"] < $amountPts) {
    appLogError(
        "[Withdrawal] Insufficient balance. User: {$user["points"]}, Request: $amountPts",
    );
    PageController::errorResponse("Insufficient balance");
}

if ($btcAmount <= 0) {
    appLogError("[Withdrawal] Invalid BTC amount: $btcAmount. PTS: $amountPts");
    PageController::errorResponse("Invalid BTC amount conversion");
}

try {
    mysqli_begin_transaction($con);

    // 1. Deduct points
    $deductQuery = "UPDATE login SET points = points - ? WHERE id = ?";
    $stmt = $con->prepare($deductQuery);
    $stmt->bind_param("ii", $amountPts, $user["id"]);
    $stmt->execute();
    $stmt->close();

    // 2. Insert withdrawal request
    $insertQuery =
        "INSERT INTO withdrawals (user_id, amount_pts, btc_address, btc_amount, status) VALUES (?, ?, ?, ?, 'pending')";
    $stmt = $con->prepare($insertQuery);
    $userIdStr = (string) $user["id"];
    $stmt->bind_param("sisd", $userIdStr, $amountPts, $btcAddress, $btcAmount);
    $stmt->execute();
    $withdrawalId = $stmt->insert_id;
    $stmt->close();

    // 3. Log in transaction history
    $logQuery =
        "INSERT INTO transaction_history (user_id, transaction_type, amount, points_amount, description) VALUES (?, 'transfer_out', ?, ?, ?)";
    $stmt = $con->prepare($logQuery);
    $desc = "BTC Withdrawal Request (ID: $withdrawalId) to $btcAddress";
    $stmt->bind_param("siis", $user["username"], $amountPts, $amountPts, $desc);
    $stmt->execute();
    $stmt->close();

    mysqli_commit($con);

    PageController::logActivity("withdraw_btc", $user, $con, [
        "withdrawal_id" => $withdrawalId,
        "amount_pts" => $amountPts,
        "btc_amount" => $btcAmount,
        "target_address" => $btcAddress,
    ]);

    PageController::successResponse(
        ["new_balance" => $user["points"] - $amountPts],
        "Withdrawal request submitted for processing",
    );
} catch (Exception $e) {
    mysqli_rollback($con);
    PageController::errorResponse($e->getMessage());
}

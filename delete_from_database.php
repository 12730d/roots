<?php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;
use ROOTS\Config\Database;
use ROOTS\Services\ValidationService;
use ROOTS\Exceptions\DeletionException;

// Use PageController to handle session, user authentication, and CSRF.
$pageData = PageController::setupWithConfig(
    "API",
    [
        "require_auth" => false,
        "csrf_protection" => false, // Already handled by router.php
        "render_layout" => false,
    ],
    "",
    [],
);

header("Content-Type: application/json");

// Return JSON 401 for unauthenticated AJAX calls instead of redirecting to /login.php
if (empty($pageData["is_authenticated"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

$con = Database::getConnection();
if (!$con instanceof mysqli) {
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}
$current_user = $pageData["user"]["username"];
$is_admin = $pageData["is_admin"];

// Get and validate JSON input
$rawBody = file_get_contents("php://input");
$decodedBody = is_string($rawBody) ? json_decode($rawBody, true) : null;
$input = $GLOBALS['ROOTS_REQUEST_DATA'] ?? $decodedBody;

// Fallback: if input is still empty, check $_POST
if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

$purchase_id = 0;
$record_id = 0;
try {
    PageController::validateRequestMethod("POST");

    if (!is_array($input)) {
        // Log for debugging
        error_log("Delete Error: Invalid Input or Empty Request.");
        throw new InvalidArgumentException("Invalid JSON payload or empty request.");
    }
    $purchase_id = ValidationService::validateInt(
        $input["purchase_id"] ?? 0,
        "purchase_id",
        1,
    );
    $record_id = ValidationService::validateInt(
        $input["record_id"] ?? 0,
        "record_id",
        1,
    );
    $record_type = $input["record_type"] ?? "search";

    if (!in_array($record_type, ["search", "password_leak"])) {
        throw new InvalidArgumentException("Invalid record type.");
    }
} catch (InvalidArgumentException $e) {
    echo json_encode(["success" => false, "message" => "Invalid request."]);
    exit();
}

$DELETION_COST = 200; // Points to deduct for deletion

try {
    $con->begin_transaction();

    // Verify purchase ownership or admin status
    $verify_query = "SELECT user_id FROM user_purchases WHERE id = ?";
    $verify_stmt = $con->prepare($verify_query);
    if (!$verify_stmt) {
        throw new DeletionException("Failed to prepare verification query.");
    }
    $verify_stmt->bind_param("i", $purchase_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    if (!$verify_result) {
        throw new DeletionException("Failed to execute verification query.");
    }
    $purchase_data = $verify_result->fetch_assoc();

    if (
        !$purchase_data ||
        (!$is_admin && $purchase_data["user_id"] !== $current_user)
    ) {
        throw new DeletionException(
            "Permission denied. You do not own this record.",
        );
    }
    $verify_stmt->close();

    // Check user points
    $check_points_query =
        "SELECT points FROM login WHERE username = ? FOR UPDATE";
    $check_stmt = $con->prepare($check_points_query);
    if (!$check_stmt) {
        throw new DeletionException("Failed to prepare points query.");
    }
    $check_stmt->bind_param("s", $current_user);
    $check_stmt->execute();
    $points_result = $check_stmt->get_result();
    if (!$points_result) {
        throw new DeletionException("Failed to execute points query.");
    }
    $user_data = $points_result->fetch_assoc();
    $user_points = (int) ($user_data["points"] ?? 0);
    $check_stmt->close();

    if (!$is_admin && $user_points < $DELETION_COST) {
        throw new DeletionException(
            "Insufficient points. Required: {$DELETION_COST}, Available: {$user_points}",
        );
    }

    // Deduct points if not an admin
    if (!$is_admin) {
        $deduct_query =
            "UPDATE login SET points = points - ? WHERE username = ?";
        $deduct_stmt = $con->prepare($deduct_query);
        if (!$deduct_stmt) {
            throw new DeletionException("Failed to prepare deduction query.");
        }
        $deduct_stmt->bind_param("is", $DELETION_COST, $current_user);
        $deduct_stmt->execute();
        $deduct_stmt->close();
    }

    // Delete from the appropriate master table
    $master_table = ($record_type === 'password_leak') ? 'password_leaks' : 'search';
    $delete_master_query = "DELETE FROM {$master_table} WHERE id = ?";
    $delete_master_stmt = $con->prepare($delete_master_query);
    if (!$delete_master_stmt) {
        throw new DeletionException("Failed to prepare master delete query.");
    }
    $delete_master_stmt->bind_param("i", $record_id);
    $delete_master_stmt->execute();
    $affected_master = $delete_master_stmt->affected_rows;
    $delete_master_stmt->close();

    // Delete from 'user_purchases' table
    $delete_purchase_query = "DELETE FROM user_purchases WHERE id = ?";
    $delete_purchase_stmt = $con->prepare($delete_purchase_query);
    if (!$delete_purchase_stmt) {
        throw new DeletionException("Failed to prepare purchase delete query.");
    }
    $delete_purchase_stmt->bind_param("i", $purchase_id);
    $delete_purchase_stmt->execute();
    $delete_purchase_stmt->close();

    $con->commit();

    $new_balance = $user_points - ($is_admin ? 0 : $DELETION_COST);
    $record_label = ($record_type === 'password_leak') ? "Password Record" : "Search Record";
    $message = "{$record_label} #{$record_id} has been permanently deleted.";
    if (!$is_admin) {
        $message .= "\n\n{$DELETION_COST} points deducted. New balance: {$new_balance} points.";
    }

    if ($affected_master > 0) {
        echo json_encode(["success" => true, "message" => $message]);
    } else {
        // This case might happen if the record was already deleted but the purchase link remained.
        echo json_encode([
            "success" => true,
            "message" =>
                "Purchase record removed, but the master record was already deleted.",
        ]);
    }
} catch (DeletionException $e) {
    $con->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit();
} catch (Exception $e) {
    $con->rollback();
    // Log the detailed error for admins/devs, but show a generic message to the user.
    error_log("Deletion Error: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "An error occurred during deletion. Please try again.",
    ]);
} finally {
    $con->close();
}

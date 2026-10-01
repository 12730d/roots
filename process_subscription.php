<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;
use ROOTS\Exceptions\PaymentException;

/**
 * Process subscription purchase - Reusable function
 *
 * @param string $username Username of the purchaser
 * @param string $plan Plan identifier (free, basic, pro, premium)
 * @param int $price Cost in points
 * @param string $duration Subscription duration
 * @param mysqli|null $db Database connection (optional, will create if null)
 * @return array{success: bool, message: string, newBalance: int, transactionHash: string}
 * @throws DatabaseException
 * @throws PaymentException
 */
function processSubscriptionPurchase(
    string $username,
    string $plan,
    int $price,
    string $duration,
    ?mysqli $db = null
): array {
    // Validate inputs
    if (empty($username) || empty($plan) || $price <= 0) {
        throw new PaymentException("Invalid purchase data");
    }

    // Create database connection if not provided
    $closeDb = false;
    if ($db === null) {
        $db = Database::getConnection();
        if (!$db) {
            throw new DatabaseException("Database connection failed");
        }
        $closeDb = true;
    }

    try {
        mysqli_begin_transaction($db);

        // Fetch current points
        $sel = "SELECT points FROM login WHERE username = ? LIMIT 1";
        $stmt = mysqli_prepare($db, $sel);
        if (!$stmt) {
            throw new DatabaseException("Database error: " . mysqli_error($db));
        }
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            throw new PaymentException("User not found");
        }

        $currentPoints = (int) $row["points"];
        if ($currentPoints < $price) {
            throw new PaymentException("Insufficient balance for this purchase");
        }

        $newBalance = $currentPoints - $price;

        // Update user balance and subscription
        $update = "UPDATE login SET previous_points = points, points = ?, subscription = ? WHERE username = ?";
        $upStmt = mysqli_prepare($db, $update);
        if (!$upStmt) {
            throw new DatabaseException("Database communication error occurred.");
        }
        mysqli_stmt_bind_param($upStmt, "iss", $newBalance, $plan, $username);
        mysqli_stmt_execute($upStmt);
        mysqli_stmt_close($upStmt);

        // Log purchase
        $txHash = bin2hex(random_bytes(16));
        $logSql = "INSERT INTO subscription_purchases (username, plan_name, price, duration, transaction_hash, status, created_at) VALUES (?, ?, ?, ?, ?, 'completed', NOW())";
        $logStmt = mysqli_prepare($db, $logSql);
        if (!$logStmt) {
            throw new DatabaseException("Transaction logging failure.");
        }
        mysqli_stmt_bind_param($logStmt, "ssiss", $username, $plan, $price, $duration, $txHash);
        mysqli_stmt_execute($logStmt);
        mysqli_stmt_close($logStmt);

        mysqli_commit($db);

        // Log to syslog
        openlog("ROOTS", LOG_PID, LOG_USER);
        syslog(LOG_INFO, "[processSubscriptionPurchase] User {$username} purchased {$plan} for {$price} points. TX={$txHash}");
        closelog();

        return [
            'success' => true,
            'message' => 'Subscription purchased successfully',
            'newBalance' => $newBalance,
            'transactionHash' => $txHash
        ];

    } catch (Exception $e) {
        if (isset($db) && @mysqli_query($db, "SELECT 1")) {
            mysqli_rollback($db);
        }
        openlog("ROOTS", LOG_PID, LOG_USER);
        syslog(LOG_ERR, "[processSubscriptionPurchase] Error: " . $e->getMessage());
        closelog();
        throw $e;
    } finally {
        if ($closeDb && isset($db)) {
            mysqli_close($db);
        }
    }
}

// Start session and require login
Session::start();
Session::requireLogin();

// Helper to redirect with flash
function redirectWithFlash(
    string $url,
    string $message = "",
    string $type = "error",
): void {
    $_SESSION["flash_message"] = $message;
    $_SESSION["flash_type"] = $type;
    header("Location: " . $url);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirectWithFlash("buy_points", "Invalid request method.", "error");
}

// Basic CSRF validation: only enforce if session token exists
$csrf = isset($_POST["csrf_token"]) ? (string) $_POST["csrf_token"] : "";
if (
    !empty($_SESSION["csrf_token"]) &&
    (empty($csrf) || !hash_equals($_SESSION["csrf_token"], $csrf))
) {
    redirectWithFlash(
        "buy_points",
        "Security token mismatch. Please try again.",
        "error",
    );
}

$plan = isset($_POST["plan"]) ? trim((string) $_POST["plan"]) : "";
$price = isset($_POST["price"]) ? (int) $_POST["price"] : 0;
$duration = isset($_POST["duration"]) ? trim((string) $_POST["duration"]) : "";

if (empty($plan) || $price <= 0) {
    redirectWithFlash("buy_points", "Invalid purchase data.", "error");
}

$username = $_SESSION["username"] ?? null;
if (!$username) {
    redirectWithFlash(
        "login",
        "You must be logged in to make purchases.",
        "error",
    );
}

try {
    $db = Database::getConnection();
    if (!$db) {
        throw new DatabaseException("Server error: database unavailable.");
    }

    // Use the reusable function
    $result = processSubscriptionPurchase($username, $plan, $price, $duration, $db);

    // Update session so the new subscription and balance show immediately
    $_SESSION["subscription"] = $plan;
    if (!isset($_SESSION["user"])) {
        $_SESSION["user"] = [];
    }
    $_SESSION["user"]["subscription"] = $plan;
    $_SESSION["user"]["points"] = $result['newBalance'];

    redirectWithFlash(
        "buy_points",
        "Subscription purchased successfully!",
        "success",
    );
} catch (PaymentException | DatabaseException | Exception $e) {
    redirectWithFlash(
        "buy_points",
        "Purchase failed: " . $e->getMessage(),
        "error",
    );
}

<?php
declare(strict_types=1);
/**
 * Delete a user's purchase record (physical delete) with safety checks.
 * - POST only
 * - Requires `id` and `csrf_token` in POST
 * - Ensures record belongs to logged-in user
 * - Prevents deleting completed purchases
 */
require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
// Detect if running on Onion service
$isOnionService = (isset($_SERVER['HTTP_HOST']) && preg_match('/\.onion$/i', $_SERVER['HTTP_HOST'])) ||
                  (isset($_SERVER['SERVER_NAME']) && preg_match('/\.onion$/i', $_SERVER['SERVER_NAME']));

const LOG_USER_TAG = " user=";
const CONTENT_TYPE_JSON = "application/json";
const HEADER_CONTENT_TYPE_JSON = "Content-Type: application/json";

// Start session with app session name and secure settings
if (session_status() === PHP_SESSION_NONE) {
    session_name("ROOTS_SESSION");
    // Secure session cookie settings
    ini_set("session.cookie_httponly", "1");
    // For Onion services: Tor provides transport security
    if (!$isOnionService) {
        ini_set("session.cookie_secure", "1");
    }
    ini_set("session.cookie_samesite", "Strict");
    ini_set("session.use_strict_mode", "1");
    session_start();
}

// ============================================================================
// SECURITY HEADERS - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($isOnionService) {
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:;');
} else {
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:;');
}

if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// Helper to redirect with flash
function redirectWithFlash(
    string $url,
    string $message = "",
    string $type = "error",
): void {
    if ($message !== "") {
        $_SESSION["flash_message"] = $message;
        $_SESSION["flash_type"] = $type;
    }
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', CONTENT_TYPE_JSON)) {
        header(HEADER_CONTENT_TYPE_JSON);
        echo json_encode(["success" => false, "message" => $message]);
        exit();
    }
    header("Location: " . $url);
    exit();
}

// Rate limiting
if (
    isset($_SESSION["last_action_time"]) &&
    time() - $_SESSION["last_action_time"] < 2
) {
    redirectWithFlash(
        "my_payments.php",
        "Please wait a moment before trying again.",
        "error",
    );
}
$_SESSION["last_action_time"] = time();

// 1) Enforce POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    appLogError("[DELETE_PURCHASE] rejected non-POST request");
    redirectWithFlash("my_payments.php", "Invalid request method.", "error");
}

// 2) Require login
if (empty($_SESSION["username"])) {
    redirectWithFlash(
        "../login.php",
        "You must be logged in to delete purchases.",
        "error",
    );
}

$username = (string) ($_SESSION["username"] ?? "");

// 3) Validate input
$rawBody = file_get_contents("php://input");
$decodedBody = is_string($rawBody) ? json_decode($rawBody, true) : null;
$input = $GLOBALS['ROOTS_REQUEST_DATA'] ?? $decodedBody ?? [];
if (isset($_POST["id"])) {
    $id = (int)$_POST["id"];
} elseif (isset($input["purchase_id"])) {
    $id = (int)$input["purchase_id"];
} elseif (isset($input["id"])) {
    $id = (int)$input["id"];
} else {
    $id = 0;
}
if (isset($_POST["csrf_token"])) {
    $csrf = (string)$_POST["csrf_token"];
} elseif (isset($input["csrf_token"])) {
    $csrf = (string)$input["csrf_token"];
} else {
    $csrf = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
}

if ($id <= 0) {
    appLogError(
        "[DELETE_PURCHASE] invalid id: " .
            var_export($_POST["id"] ?? null, true),
    );
    redirectWithFlash("my_payments.php", "Invalid purchase id.", "error");
}

// CSRF check
if (
    empty($_SESSION["csrf_token"]) ||
    strlen($csrf) > 256 ||
    !hash_equals($_SESSION["csrf_token"], $csrf)
) {
    appLogError(
        "[DELETE_PURCHASE] csrf failure for" .
            LOG_USER_TAG .
            $username .
            " id=" .
            $id,
    );
    redirectWithFlash(
        "my_payments.php",
        "Security token mismatch. Please try again.",
        "error",
    );
}

// 4) DB connection
$db = Database::getConnection();
if (!$db || !($db instanceof mysqli)) {
    appLogError("[DELETE_PURCHASE] db connection failed");
    redirectWithFlash(
        "my_payments.php",
        "Server error: database unavailable.",
        "error",
    );
}
if (!$db->set_charset("utf8mb4")) {
    appLogError(
        "[DELETE_PURCHASE] failed to set charset for" .
            LOG_USER_TAG .
            $username,
    );
    redirectWithFlash(
        "my_payments.php",
        "Server error: database configuration failed.",
        "error",
    );
}

try {
    $db->begin_transaction();

    // Check ownership in user_purchases table (for my_purchases page)
    $checkSql =
        "SELECT id FROM user_purchases WHERE id = ? AND user_id = ? LIMIT 1";
    $checkStmt = $db->prepare($checkSql);
    if (!$checkStmt) {
        throw DatabaseException::prepareFailed($db->error);
    }
    $checkStmt->bind_param("is", $id, $username);
    $checkStmt->execute();
    $res = $checkStmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $checkStmt->close();

    if (!$row) {
        $db->rollback();
        appLogError(
            "[DELETE_PURCHASE] not found id=" . $id . LOG_USER_TAG . $username,
        );
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', CONTENT_TYPE_JSON)) {
            header('Content-Type: application/json');
            echo json_encode(["success" => false, "message" => "Purchase not found."]);
            exit();
        }
        redirectWithFlash("my_purchases.php", "Purchase not found.", "error");
    }

    // Delete from user_purchases table only (remove from user's list, not from main database)
    $delSql = "DELETE FROM user_purchases WHERE id = ? AND user_id = ? LIMIT 1";
    $delStmt = $db->prepare($delSql);
    if (!$delStmt) {
        throw DatabaseException::prepareFailed($db->error);
    }
    $delStmt->bind_param("is", $id, $username);
    $ok = $delStmt->execute();
    $delStmt->close();

    if (!$ok) {
        throw DatabaseException::executeFailed($db->error);
    }

    $db->commit();
    appLogInfo(
        "[DELETE_PURCHASE] deleted purchase from user list id=" .
            $id .
            LOG_USER_TAG .
            $username,
    );
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', CONTENT_TYPE_JSON)) {
        header(HEADER_CONTENT_TYPE_JSON);
        echo json_encode(["success" => true, "message" => "Purchase removed from your list."]);
        exit();
    }
    redirectWithFlash(
        "my_purchases.php",
        "Purchase removed from your list.",
        "success",
    );
} catch (Throwable $e) {
    try {
        $db->rollback();
    } catch (Throwable $ex) {
        appLogError("[DELETE_PURCHASE] rollback failed: " . $ex->getMessage());
    }
    appLogError(
        "[DELETE_PURCHASE] exception: " .
            $e->getMessage() .
            " id=" .
            $id .
            LOG_USER_TAG .
            $username,
    );
    redirectWithFlash(
        "my_payments.php",
        "Failed to delete purchase. Contact support.",
        "error",
    );
}

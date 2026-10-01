<?php declare(strict_types=1);

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

// Logging constants
const LOG_USER_PREFIX = " user=";

// Start session with app session name and secure settings
if (session_status() === PHP_SESSION_NONE) {
    session_name("ROOTS_SESSION");
    // Secure session cookie settings
    ini_set("session.cookie_httponly", "1"); // Prevent JavaScript access
    // For Onion services: Tor provides transport security
    // For HTTPS: Set secure flag
    if (!$isOnionService) {
        ini_set("session.cookie_secure", "1"); // HTTPS only (ensure SSL is enabled)
    }
    ini_set("session.cookie_samesite", "Strict"); // CSRF protection
    ini_set("session.use_strict_mode", "1"); // Reject uninitialized session IDs
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

// ============================================================================
// BAN STATUS CHECKING
// ============================================================================
$isBlockedUser = false;
try {
    if (class_exists('ROOTS\Auth\Session')) {
        \ROOTS\Auth\Session::start();
        if (\ROOTS\Auth\Session::isLoggedIn() && !empty($_SESSION["user_id"])) {
            $dbCheck = null;
            try {
                $dbCheck = Database::getConnection();
            } catch (Exception $e) {
                $dbCheck = null;
            }
            if ($dbCheck instanceof \mysqli) {
                $stmt = $dbCheck->prepare(
                    "SELECT ban_until, suspended FROM user_security_guard WHERE user_id = ? LIMIT 1",
                );
                if ($stmt) {
                    $uid = (int) $_SESSION["user_id"];
                    $stmt->bind_param("i", $uid);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        if (is_array($row)) {
                            if (!empty($row["suspended"])) {
                                $isBlockedUser = true;
                            } else {
                                $banUntil = $row["ban_until"] ?? null;
                                if (
                                    !empty($banUntil) &&
                                    strtotime((string) $banUntil) > time()
                                ) {
                                    $isBlockedUser = true;
                                }
                            }
                        }
                    } else {
                        error_log("Failed to execute ban check query: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    error_log("Failed to prepare ban check query: " . $dbCheck->error);
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking user ban status: " . $e->getMessage());
}

// Redirect blocked users
if ($isBlockedUser) {
    header("Location: blocked");
    exit();
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
    header("Location: " . $url);
    exit();
}

// Rate limiting (prevent spam/abuse) - allow 1 request every 2 seconds
if (
    isset($_SESSION["last_action_time"]) &&
    time() - $_SESSION["last_action_time"] < 2
) {
    redirectWithFlash(
        "my_payments",
        "Please wait a moment before trying again.",
        "error",
    );
}
$_SESSION["last_action_time"] = time();

// 1) Enforce POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    appLogError("[DELETE_PURCHASE] rejected non-POST request");
    redirectWithFlash("my_payments", "Invalid request method.", "error");
}

// 2) Require login
if (empty($_SESSION["username"])) {
    redirectWithFlash(
        "../login",
        "You must be logged in to delete purchases.",
        "error",
    );
}

$username = (string) ($_SESSION["username"] ?? "");

// 3) Validate input
$id = isset($_POST["id"]) ? (int) $_POST["id"] : 0;
$csrf = isset($_POST["csrf_token"]) ? (string) $_POST["csrf_token"] : "";

// Validate ID range (prevent integer overflow and invalid values)
if ($id <= 0 || $id > PHP_INT_MAX) {
    appLogError(
        "[DELETE_PURCHASE] invalid id: " .
            var_export($_POST["id"] ?? null, true),
    );
    redirectWithFlash("my_payments.php", "Invalid purchase id.", "error");
}

// CSRF check with length validation
if (
    empty($_SESSION["csrf_token"]) ||
    strlen($csrf) > 256 ||
    !hash_equals($_SESSION["csrf_token"], $csrf)
) {
    appLogError(
        "[DELETE_PURCHASE] csrf failure for" .
            LOG_USER_PREFIX .
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
    appLogError(
        "[DELETE_PURCHASE] db connection failed for" .
            LOG_USER_PREFIX .
            $username,
    );
    redirectWithFlash(
        "my_payments.php",
        "Server error: database unavailable.",
        "error",
    );
}

// Set charset for security (prevent encoding attacks)
if (!$db->set_charset("utf8mb4")) {
    appLogError(
        "[DELETE_PURCHASE] failed to set charset for" .
            LOG_USER_PREFIX .
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

    // Check ownership and status
    $checkSql =
        "SELECT status FROM payment_transactions WHERE id = ? AND username = ? LIMIT 1";
    $checkStmt = $db->prepare($checkSql);
    if (!$checkStmt) {
        throw DatabaseException::prepareFailed($db->error);
    }
    $checkStmt->bind_param("is", $id, $username);
    $checkStmt->execute();
    $row = null;
    $res = $checkStmt->get_result();
    if ($res !== false) {
        $fetched = $res->fetch_assoc();
        if (is_array($fetched)) {
            $row = $fetched;
        }
    }
    $checkStmt->close();

    if (!is_array($row)) {
        $db->rollback();
        appLogError(
            "[DELETE_PURCHASE] not found id=" .
                $id .
                LOG_USER_PREFIX .
                $username,
        );
        redirectWithFlash("my_payments.php", "Purchase not found.", "error");
    }

    // Prevent deletion of completed purchases
    if ((string) ($row["status"] ?? "") === "completed") {
        $db->rollback();
        appLogError(
            "[DELETE_PURCHASE] attempt to delete completed purchase id=" .
                $id .
                LOG_USER_PREFIX .
                $username,
        );
        redirectWithFlash(
            "my_payments.php",
            "Cannot delete a completed purchase.",
            "error",
        );
    }

    // Perform delete
    $delSql =
        "DELETE FROM payment_transactions WHERE id = ? AND username = ? LIMIT 1";
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
        "[DELETE_PURCHASE] deleted purchase id=" .
            $id .
            LOG_USER_PREFIX .
            $username,
    );
    redirectWithFlash(
        "my_payments.php",
        "Purchase deleted successfully.",
        "success",
    );
} catch (Throwable $e) {
    try {
        $db->rollback();
    } catch (Throwable $ex) {
        // Ignore rollback failures - we're already in an error state
    }
    appLogError(
        "[DELETE_PURCHASE] exception: " .
            $e->getMessage() .
            " id=" .
            $id .
            LOG_USER_PREFIX .
            $username,
    );
    redirectWithFlash(
        "my_payments.php",
        "Failed to delete purchase. Contact support.",
        "error",
    );
}

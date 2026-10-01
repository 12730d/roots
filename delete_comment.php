<?php

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
// Detect if running on Onion service
$isOnionService = (isset($_SERVER['HTTP_HOST']) && preg_match('/\.onion$/i', $_SERVER['HTTP_HOST'])) ||
                  (isset($_SERVER['SERVER_NAME']) && preg_match('/\.onion$/i', $_SERVER['SERVER_NAME']));

// Only configure and start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session parameters BEFORE session_start()
    ini_set("session.cookie_httponly", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.cookie_samesite", "Strict");
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_trans_sid", "0");
    ini_set("session.gc_maxlifetime", "1800");

    if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        ini_set("session.cookie_secure", "1");
    }

    session_name("ROOTS_SESSION");
    session_start();
}
require_once __DIR__ . "/vendor/autoload.php";
use ROOTS\Config\AppConfig;
use ROOTS\Services\CommentService;

AppConfig::init();

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

/**
 * Comment Management System
 * Handles fetching, deleting, and censoring comments_pay
 */

/**
 * Fetch comments_pay from the database
 *
 * @param int $limit Optional limit for number of comments_pay to return
 * @return array Array of comment data
 */
/**
 * @return array<int, array<string, mixed>>
 */
function fetchComments(?int $limit = null): array
{
    global $host, $db, $user, $pass;
    $comments_pay = [];

    if ($limit === null) {
        $limit = MAX_COMMENTS_PER_PAGE;
    }

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$db;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        $sql =
            "SELECT id, name, email, wallet, plan, comment, created_at FROM comments_pay ORDER BY created_at DESC";

        if ($limit > 0) {
            $sql .= " LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":limit", (int) $limit, PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare($sql);
        }

        $stmt->execute();
        $comments_pay = $stmt->fetchAll();
    } catch (PDOException $e) {
        appLogError("Database error in fetchComments: " . $e->getMessage());
    }

    return $comments_pay;
}

/**
 * Delete a comment from the database
 *
 * @param int $comment_id ID of comment to delete
 * @return bool Success status
 */
function deleteComment($comment_id)
{
    global $host, $db, $user, $pass;
    $success = false;

    try {
        if (!filter_var($comment_id, FILTER_VALIDATE_INT)) {
            throw new \ROOTS\Exceptions\CommentException("Invalid comment ID");
        }

        $pdo = new PDO(
            "mysql:host=$host;dbname=$db;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        );

        $stmt = $pdo->prepare("DELETE FROM comments_pay WHERE id = ?");
        $stmt->execute([$comment_id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION["flash_message"] = "Comment deleted successfully";
            $_SESSION["flash_type"] = "success";
            $success = true;
        } else {
            throw new \ROOTS\Exceptions\CommentException("Comment not found");
        }
    } catch (\Exception $e) {
        $_SESSION["flash_message"] = $e->getMessage();
        $_SESSION["flash_type"] = "error";
        $success = false;
    }

    return $success;
}

/**
 * Censor text based on mode
 */
function censorText(string $text, string $mode = "partial"): string
{
    $result = $text;

    if ($mode === "full") {
        $result = preg_replace("/[^\s]/", "*", $text) ?? "";
    } elseif ($mode !== "none" && strlen($text) > 2) {
        $firstChar = mb_substr($text, 0, 1);
        $lastChar = mb_substr($text, -1, 1);
        $middleLength = mb_strlen($text) - 2;
        $censoredMiddle = str_repeat("*", $middleLength);

        $result = $firstChar . $censoredMiddle . $lastChar;
    }

    return $result;
}

/**
 * Censor sensitive information in text
 */
function censorSensitiveInfo(string $text): string
{
    $text = preg_replace(
        "/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/",
        '$1@***.$2',
        $text,
    ) ?? '';
    $text = preg_replace(
        "/(\d{3})[.-]?(\d{3})[.-]?(\d{4})/",
        '$1-***-$3',
        $text,
    ) ?? '';
    $text = preg_replace(
        "/\b(\d{4})[- ]?(\d{4})[- ]?(\d{4})[- ]?(\d{4})\b/",
        '$1-****-****-$4',
        $text,
    ) ?? '';
    $text = preg_replace(
        "/\b(0x[a-fA-F0-9]{6})[a-fA-F0-9]+(a-fA-F0-9{4})\b/",
        '$1....$2',
        $text,
    ) ?? '';
    return $text;
}

// Handle delete requests
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["id"])) {
    deleteComment($_POST["id"]);
    // Safe redirect
    header("Location: contact.php");
    exit();
}

// Handle JSON requests
if (
    basename($_SERVER["SCRIPT_FILENAME"]) == basename(__FILE__) &&
    isset($_GET["json"])
) {
    header('Content-Type: application/json');
    header('Connection: close');
    $response = json_encode(fetchComments(AppConfig::MAX_COMMENTS_PER_PAGE));
    if ($response === false) {
        http_response_code(500);
        exit();
    }
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit();
}

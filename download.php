<?php

// Define session name BEFORE starting session to match PageController
if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.cookie_httponly", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.cookie_samesite", "Strict");
    session_name("ROOTS_SESSION");
    session_start();
}

require_once __DIR__ . "/vendor/autoload.php";
use ROOTS\Config\Database;

// Check authentication
if (!isset($_SESSION["username"])) {
    // If not logged in, we can't verify purchase
    header("Location: login.php");
    exit();
}

$username = $_SESSION["username"];

// Get Database Connection
$db = Database::getConnection();
if (!$db) {
    die("System Error: Database connection failed.");
}

// 1. Get File ID
$file_id = isset($_GET["file_id"]) ? intval($_GET["file_id"]) : 0;

if ($file_id <= 0) {
    die("Error: Invalid file identifier.");
}

// 2. Fetch File Details
$query = "
    SELECT sf.id, sf.filename, sf.file_path, sf.file_type, sf.original_owner_id, sf.current_owner_id
    FROM shared_files sf
    WHERE sf.id = ?
";

$stmt = $db->prepare($query);
if (!$stmt) {
    die("Database Error: " . $db->error);
}
$stmt->bind_param("i", $file_id);
$stmt->execute();
$result = $stmt->get_result();
$file = null;
if ($result !== false) {
    $fetched = $result->fetch_assoc();
    if (is_array($fetched)) {
        $file = $fetched;
    }
}
$stmt->close();

if (!$file) {
    die("Error: File not found.");
}

// 3. Verify Access (Ownership or Purchase)
$has_access = false;

// a. Is Owner?
if (
    $file["original_owner_id"] === $username ||
    $file["current_owner_id"] === $username
) {
    $has_access = true;
}

// b. Is Admin?
if (isset($_SESSION["subscription"]) && $_SESSION["subscription"] === "admin") {
    $has_access = true;
}

// c. Has Purchased?
if (!$has_access) {
    $purch_query =
        "SELECT id FROM file_purchases WHERE file_id = ? AND buyer_username = ?";
    $p_stmt = $db->prepare($purch_query);
    if ($p_stmt) {
        $p_stmt->bind_param("is", $file_id, $username);
        $p_stmt->execute();
        $purch_result = $p_stmt->get_result();
        if ($purch_result && $purch_result->num_rows > 0) {
            $has_access = true;
        }
        $p_stmt->close();
    }
}

if (!$has_access) {
    http_response_code(403);
    die("Access Denied: You have not purchased this file.");
}

// 4. Serve the file
$filepath = (string) ($file["file_path"] ?? '');

// Handle relative/absolute paths and verification
if (!file_exists($filepath)) {
    // Try relative to current script (ROOTS/)
    $try_path = __DIR__ . "/" . ltrim($filepath, "/");
    if (file_exists($try_path)) {
        $filepath = $try_path;
    } else {
        // Try inside uploads directory if path is just a filename or relative
        $try_path_2 = __DIR__ . "/uploads/" . basename($filepath);
        if (file_exists($try_path_2)) {
            $filepath = $try_path_2;
        } elseif (isset($file["original_owner_id"])) {
            // Try user specific upload folder: uploads/USERNAME/filename
            $try_path_3 =
                __DIR__ .
                "/uploads/" .
                $file["original_owner_id"] .
                "/" .
                basename($filepath);
            if (file_exists($try_path_3)) {
                $filepath = $try_path_3;
            }
        }

        if (!file_exists($filepath)) {
            die(
                "Error: File content not found on server storage. Path: " .
                    htmlspecialchars((string) ($file["file_path"] ?? ''))
            );
        }
    }
}

// Determine MIME type
$mimeType = $file["file_type"] ?? "application/octet-stream";
if (function_exists("finfo_open")) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected_mime = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        if ($detected_mime !== false) {
            $mimeType = $detected_mime;
        }
    }
}

// Clear any previous output
if (ob_get_length()) {
    ob_clean();
}

// Set Headers
header("Content-Description: File Transfer");
header("Content-Type: " . $mimeType);
header(
    'Content-Disposition: attachment; filename="' .
        basename((string) ($file["filename"] ?? '')) .
        '"',
);
header("Expires: 0");
header("Cache-Control: must-revalidate");
header("Pragma: public");
header("Content-Length: " . filesize($filepath));

// Output file
readfile($filepath);
exit();

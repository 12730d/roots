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

// Include database configuration
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Exceptions\CommentException;

// Define constants for better maintainability
define('REDIRECT_LOCATION', 'Location: index2.php');

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

// Initialize response array
$response = [
    'success' => false,
    'message' => ''
];

try {
    // Load DB config from environment (config.php may not be analyzed by PHPStan)
    $dbHost = $_ENV["DB_HOST"] ?? (getenv("DB_HOST") ?: "localhost");
    $dbName = $_ENV["DB_NAME"] ?? (getenv("DB_NAME") ?: "users_app");
    $dbUser = $_ENV["DB_USER"] ?? (getenv("DB_USER") ?: "root");
    $dbPass = $_ENV["DB_PASS"] ?? (getenv("DB_PASS") ?: "");

    // Create PDO connection
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    // Check if form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate and sanitize input
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $wallet = filter_input(INPUT_POST, 'wallet', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $plan = filter_input(INPUT_POST, 'plan', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $comment = filter_input(INPUT_POST, 'comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        // Basic validation
        if (empty($name) || empty($email) || empty($plan)) {
            throw new CommentException("Name, email, and plan are required fields.");
        }

        if (strlen($name) > 100) {
            throw new CommentException("Name cannot exceed 100 characters.");
        }

        if (strlen($comment) > 1000) {
            throw new CommentException("Comment cannot exceed 1000 characters.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CommentException("Please enter a valid email address.");
        }

        if (!empty($wallet) && strlen($wallet) > 42) {
            throw new CommentException("Wallet address cannot exceed 42 characters.");
        }

        // Prepare and execute the SQL statement
        $stmt = $pdo->prepare("INSERT INTO comments_pay (name, email, wallet, plan, comment) VALUES (:name, :email, :wallet, :plan, :comment)");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':wallet' => $wallet,
            ':plan' => $plan,
            ':comment' => $comment
        ]);

        // Set success message
        $_SESSION['flash_message'] = "Your registration has been completed successfully!";
        $_SESSION['flash_type'] = "success";

        // Redirect back to the main page
        header(REDIRECT_LOCATION);
        exit();
    } else {
        throw new Exception("Invalid request method.");
    }
} catch (PDOException $e) {
    // Log the error (in a production environment)
    error_log("Database error: " . $e->getMessage());

    $_SESSION['flash_message'] = "There was a problem processing your registration. Our team has been notified of the issue.";
    $_SESSION['flash_type'] = "error";

    header(REDIRECT_LOCATION);
    exit();
} catch (Exception $e) {
    $_SESSION['flash_message'] = $e->getMessage();
    $_SESSION['flash_type'] = "error";

    header(REDIRECT_LOCATION);
    exit();
}
?>

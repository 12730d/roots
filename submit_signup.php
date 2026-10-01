<?php
declare(strict_types=1);

require_once (__DIR__) . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Exceptions\RegistrationException;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
// Detect if running on Onion service
$isOnionService = (isset($_SERVER['HTTP_HOST']) && preg_match('/\.onion$/i', $_SERVER['HTTP_HOST'])) ||
                  (isset($_SERVER['SERVER_NAME']) && preg_match('/\.onion$/i', $_SERVER['SERVER_NAME']));

// Only configure and start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session parameters BEFORE session_start()
    ini_set("session.cookie_httponly", "1"); // Prevent XSS cookie theft
    ini_set("session.use_only_cookies", "1"); // Prevent session fixation via URL
    ini_set("session.cookie_samesite", "Strict"); // CSRF protection
    ini_set("session.use_strict_mode", "1"); // Reject uninitialized session IDs
    ini_set("session.use_trans_sid", "0"); // Never pass session ID in URL
    ini_set("session.gc_maxlifetime", "1800"); // 30 minutes

    // For Onion services: Tor provides transport security
    // For HTTPS connections: Always set secure flag
    if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        ini_set("session.cookie_secure", "1");
    }

    session_name("ROOTS_SESSION");
    session_start();
}

// ============================================================================
// SECURITY HEADERS - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Set Content-Security-Policy based on connection type
if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:;',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:;',
    );
}

// Set HSTS header only for non-Onion HTTPS connections
if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

try {
    // 1. Method Check
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RegistrationException("Invalid request method.");
    }

    // 2. CSRF Security Check
    $token = $_POST["csrf_token"] ?? "";
    if (
        empty($_SESSION["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        throw new RegistrationException(
            "Security token expired. Please refresh and try again.",
        );
    }

    // 3. Database Connection
    $mysqli = Database::getConnection();
    if (!($mysqli instanceof mysqli)) {
        throw new RegistrationException("Database connection failed.");
    }
    $mysqli->set_charset("utf8mb4");

    // 4. Input Sanitization & Validation
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    // Optional fields from signup form
    $fullName = trim($_POST["name"] ?? "");
    $walletAddress = trim($_POST["wallet"] ?? "");
    $registrationComment = trim($_POST["comment"] ?? "");

    if (empty($username) || empty($email) || empty($password)) {
        throw new RegistrationException(
            "All fields (Username, Email, Password) are required.",
        );
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RegistrationException("Invalid email address format.");
    }

    // Validate wallet address if provided (optional field, supports various crypto formats)
    // Allow alphanumeric plus common special chars used in wallet addresses
    if (!empty($walletAddress)) {
        $walletAddress = trim($walletAddress);
        // Allow letters, numbers, hyphens, underscores, and common crypto address chars
        if (!preg_match('/^[a-zA-Z0-9_\-]{26,100}$/', $walletAddress)) {
            throw new RegistrationException(
                "Invalid wallet address format. Must be 26-100 characters (letters, numbers, hyphens, underscores).",
            );
        }
    }

    // 5. Prepare Default Values (Must be variables for bind_param)
    $planValue = "free";
    $roleValue = "user";
    $subValue = "free";
    $initialPoints = 500;

    // Calculate Expiry (60 days from now)
    $dateTime = new DateTimeImmutable("now");
    $expiryDate = $dateTime->add(new DateInterval("P60D"))->format("Y-m-d");

    // 6. Start Transaction
    $mysqli->begin_transaction();

    // 7. Duplicate User Check
    // We check both username and email to ensure uniqueness
    $dupStmt = $mysqli->prepare(
        "SELECT id FROM login WHERE username = ? OR email = ? LIMIT 1",
    );
    if (!$dupStmt) {
        throw new RegistrationException("Database error: " . $mysqli->error);
    }
    $dupStmt->bind_param("ss", $username, $email);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if (!$dupResult || $dupResult->num_rows > 0) {
        $dupStmt->close();
        throw new RegistrationException("Username or Email already exists.");
    }
    $dupStmt->close();

    // 8. Hash Password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);

    // 9. Insert New User
    // Mapping 12 parameters to match the table schema and values (including optional fields)
    $sql = "INSERT INTO login (
        username,
        email,
        wallet_address,
        registration_comment,
        display_name,
        password,
        role,
        plan,
        subscription,
        points,
        earned_points,
        expiry_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $insertStmt = $mysqli->prepare($sql);
    if (!$insertStmt) {
        throw new RegistrationException("Prepare failed: " . $mysqli->error);
    }

    // TYPE DEFINITION: 'sssssssssiis'
    // s = string, i = integer
    // 1. username (s)
    // 2. email (s)
    // 3. wallet_address (s)
    // 4. registration_comment (s)
    // 5. display_name (s)
    // 6. password (s)
    // 7. role (s)
    // 8. plan (s)
    // 9. subscription (s)
    // 500. points (i)
    // 11. earned_points (i)
    // 12. expiry_date (s - Date string)

    $insertStmt->bind_param(
        "sssssssssiis",
        $username,
        $email,
        $walletAddress,
        $registrationComment,
        $fullName,
        $hashedPassword,
        $roleValue,
        $planValue,
        $subValue,
        $initialPoints,
        $initialPoints,
        $expiryDate,
    );

    if (!$insertStmt->execute()) {
        throw new RegistrationException(
            "Registration failed: " . $insertStmt->error,
        );
    }
    $insertStmt->close();

    // 10. Commit Transaction
    $mysqli->commit();

    // Success Response
    $_SESSION["flash_message"] = "Account created successfully!";
    $_SESSION["flash_type"] = "success";
    header("Location: login");
    exit();
} catch (Throwable $e) {
    // Rollback transaction on error
    if (isset($mysqli) && $mysqli->connect_errno === 0) {
        try {
            $mysqli->rollback();
        } catch (Exception $ex) {
            // Ignore rollback failure
        }
    }

    // Log error for admin
    error_log("Registration Error: " . $e->getMessage());

    // User feedback
    $_SESSION["flash_message"] = $e->getMessage();
    $_SESSION["flash_type"] = "error";
    header("Location: signup");
    exit();
}

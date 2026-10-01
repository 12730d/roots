<?php
header('Content-Type: application/json; charset=UTF-8');

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

// Error reporting - log errors and display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load environment variables
function load_env(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (!file_exists($path)) {
        error_log("Environment file not found at: " . $path);
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // skip comments
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Ignore malformed lines safely.
        if (strpos($line, '=') === false) {
            continue;
        }

        // split only on first '='
        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        // remove surrounding quotes
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // add escape support
        $value = str_replace(['\n', '\r', '\t'], ["\n", "\r", "\t"], $value);

        $_ENV[$key] = $value;
        putenv("$key=$value");
    }

    $loaded = true;
}

load_env(__DIR__ . '/.env');

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_name("ROOTS_SESSION");
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Check if user is logged in and is admin
if (!isset($_SESSION["username"])) {
    error_log("User not logged in - session username not set");
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

error_log("update_points.php: authenticated request for admin check");

// Check if user is admin
$password = $_ENV["SECRET"] ?? '';
if (empty($password)) {
    error_log("Database password not found in environment");
    echo json_encode(['success' => false, 'message' => 'Database configuration error']);
    exit();
}

$con = mysqli_connect("localhost", "root", $password, "users_app");
if (!$con) {
    error_log("Database connection failed: " . mysqli_connect_error());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$username = $_SESSION["username"];
$query = "SELECT role FROM login WHERE username = ?";
$stmt = mysqli_prepare($con, $query);
if (!$stmt) {
    error_log("Failed to prepare query: " . mysqli_error($con));
    echo json_encode(['success' => false, 'message' => 'Database query error']);
    exit();
}

mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

error_log("User role check result: " . print_r($user, true));

if (!$user || $user['role'] !== 'admin') {
    error_log("User not authorized or role mismatch. User role: " . ($user['role'] ?? 'null'));
    echo json_encode(['success' => false, 'message' => 'You are not authorized to perform this action']);
    exit();
}

// Validate CSRF token
$token = $_POST['csrf_token'] ?? '';
if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    error_log("update_points.php: CSRF token validation failed");
    echo json_encode(['success' => false, 'message' => 'Security validation failed']);
    exit();
}

// Validate input
if (!isset($_POST['record_id']) || !isset($_POST['points'])) {
    echo json_encode(['success' => false, 'message' => 'Required data is incomplete']);
    exit();
}

$record_id = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
$points = filter_input(INPUT_POST, 'points', FILTER_VALIDATE_INT);

if ($record_id === false || $record_id === null || $record_id < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
    exit();
}

// Validate points range
if ($points === false || $points === null || $points < 1 || $points > 500000) {
    echo json_encode(['success' => false, 'message' => 'Points must be between 1 and 500,000']);
    exit();
}

// Update the points in the database
$update_query = "UPDATE pending_records SET points = ? WHERE id = ?";
$update_stmt = mysqli_prepare($con, $update_query);
if (!$update_stmt) {
    error_log("update_points.php: failed to prepare update statement: " . mysqli_error($con));
    echo json_encode(['success' => false, 'message' => 'Database query error']);
    mysqli_close($con);
    exit();
}
mysqli_stmt_bind_param($update_stmt, "ii", $points, $record_id);

if (mysqli_stmt_execute($update_stmt)) {
    mysqli_stmt_close($update_stmt);

    // Admin action logged successfully
    echo json_encode(['success' => true, 'message' => 'Points updated successfully']);
} else {
    error_log("update_points.php: failed to update points for record " . $record_id);
    mysqli_stmt_close($update_stmt);
    echo json_encode(['success' => false, 'message' => 'Failed to update points']);
}

mysqli_close($con);
?>

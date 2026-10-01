<?php

require_once __DIR__ . '/vendor/autoload.php';
use ROOTS\Auth\Session;
use ROOTS\Config\Database;

Session::requireLogin();

$db = Database::getConnection();
if (!$db) {
    die("Connection failed: Database error");
}

// Get current user
$username = (string) ($_SESSION["username"] ?? '');

// Get count of pending payments
$count = 0;
$query = "SELECT COUNT(*) as pending_count FROM payment_transactions WHERE username = ? AND status = 'pending'";
$stmt = mysqli_prepare($db, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $count = (int) ($row['pending_count'] ?? 0);
        mysqli_free_result($result);
    }
}
// Database::getConnection returns shared connection, do not close output.
// mysqli_close($db);

// Return JSON response
echo json_encode(['pending_count' => $count]);


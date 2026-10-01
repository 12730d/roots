<?php

function loadEnv(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (!file_exists($path)) {
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

loadEnv(__DIR__ . '/.env');

// Ensure this file can only be executed via AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    die('Access denied');
}

// Database connection
$password = $_ENV["SECRET"];
$conn = mysqli_connect("localhost", "root", $password, "users_app");

if ($conn) {
    // Delete all records from transaction_history table
    $query = "TRUNCATE TABLE transaction_history";
    if (mysqli_query($conn, $query)) {
        echo "Records deleted successfully";
    } else {
        echo "Error occurred while deleting records: " . mysqli_error($conn);
    }
    mysqli_close($conn);
} else {
    echo "Database connection failed";
}


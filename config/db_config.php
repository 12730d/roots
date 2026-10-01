<?php

/**
 * Centralized Database Configuration
 * Reads credentials from environment variables for security
 */

// Ensure autoload is loaded
require_once __DIR__ . "/../vendor/autoload.php";

use ROOTS\Config\EnvLoader;

// Call EnvLoader to populate $_ENV from .env file
EnvLoader::load();

// Get database credentials from environment variables
$db_host = $_ENV["DB_HOST"] ?: (getenv("DB_HOST") ?: "localhost");
$db_user = $_ENV["DB_USER"] ?: (getenv("DB_USER") ?: "root");
$db_pass = $_ENV["DB_PASS"] ?: (getenv("DB_PASS") ?: "");
$db_name = $_ENV["DB_NAME"] ?: (getenv("DB_NAME") ?: "users_app");
$db_port = $_ENV["DB_PORT"] ?: (getenv("DB_PORT") ?: 3306);

// Function to get MySQLi connection
/**
 * @return \mysqli
 */
function getDbConnection()
{
    // robustly get credentials regardless of scope
    $host = $_ENV["DB_HOST"] ?: getenv("DB_HOST") ?: "localhost";
    $user = $_ENV["DB_USER"] ?: getenv("DB_USER") ?: "root";
    $pass = $_ENV["DB_PASS"] ?: getenv("DB_PASS") ?: "";
    $name = $_ENV["DB_NAME"] ?: getenv("DB_NAME") ?: "users_app";
    $port = $_ENV["DB_PORT"] ?: getenv("DB_PORT") ?: 3306;

    $conn = mysqli_connect($host, $user, $pass, $name, $port);

    if (!$conn) {
        appLogError("Database connection failed: " . mysqli_connect_error());
        die("Database connection error. Please contact support.");
    }

    mysqli_set_charset($conn, "utf8mb4");
    return $conn;
}

// Function to get PDO connection
/**
 * @return \PDO
 */
function getPdoConnection()
{
    $host = $_ENV["DB_HOST"] ?: getenv("DB_HOST") ?: "localhost";
    $user = $_ENV["DB_USER"] ?: getenv("DB_USER") ?: "root";
    $pass = $_ENV["DB_PASS"] ?: getenv("DB_PASS") ?: "";
    $name = $_ENV["DB_NAME"] ?: getenv("DB_NAME") ?: "users_app";
    $port = $_ENV["DB_PORT"] ?: getenv("DB_PORT") ?: 3306;

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        appLogError("PDO Connection failed: " . $e->getMessage());
        die("Database connection error. Please contact support.");
    }
}

// Define global constants
if (!defined("PENDING_REQUESTS_REDIRECT")) {
    define("PENDING_REQUESTS_REDIRECT", "Location: pending_requests.php");
}

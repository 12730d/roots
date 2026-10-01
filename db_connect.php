<?php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;

$con = Database::getConnection();
if (!$con) {
    appLogError(
        "Failed to get database connection in search_db/db_connect.php",
    );
    die("Database connection error. Please try again later.");
}

mysqli_query($con, "SET time_zone = '+03:00'");

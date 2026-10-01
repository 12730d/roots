<?php

/**
 * Debug Script - Database Connection Test
 */

require_once dirname(__DIR__) . "/vendor/autoload.php";

use ROOTS\Config\Database;

// Use centralized connection function from namespaced Database class
$con = Database::getConnection();

// Check connection (Note: Database::getConnection already handles connection errors)
if (!$con) {
    echo "Failed to connect to MySQL";
    exit();
}

echo "Successfully connected to the database.";

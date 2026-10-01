<?php

/**
 * Drone Face Scanner Database Setup
 *
 * This script initializes the complete database schema for the drone face scanning system
 * Run this script to set up all required tables, views, stored procedures, and triggers
 */

// Increase execution time and memory limit for setup
set_time_limit(300);
ini_set('memory_limit', '512M');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load autoloader
require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Database\DroneDatabaseInitializer;
use ROOTS\Config\Database;

echo "===========================================\n";
echo "DRONE FACE SCANNER DATABASE SETUP\n";
echo "===========================================\n\n";

try {
    // Test database connection
    echo "1. Testing database connection...\n";
    $db = Database::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    echo "✓ Database connection successful\n\n";

    // Initialize database schema
    echo "2. Initializing database schema...\n";
    $initializer = new DroneDatabaseInitializer();
    $results = $initializer->initializeAll();

    if ($results['success']) {
        echo "✓ Database initialization successful\n";
        echo "\nTables created:\n";
        foreach ($results['tables_created'] as $tableResult) {
            echo "  - $tableResult\n";
        }
    } else {
        echo "✗ Database initialization failed\n";
        echo "\nErrors:\n";
        foreach ($results['errors'] as $error) {
            echo "  - $error\n";
        }
    }

    // Verify tables
    echo "\n3. Verifying tables...\n";
    $verification = $initializer->verifyTables();
    $allVerified = true;

    foreach ($verification as $table => $exists) {
        if ($exists) {
            echo "  ✓ $table\n";
        } else {
            echo "  ✗ $table (missing)\n";
            $allVerified = false;
        }
    }

    echo "\n";
    if ($allVerified) {
        echo "===========================================\n";
        echo "✓ SETUP COMPLETED SUCCESSFULLY\n";
        echo "===========================================\n";
    } else {
        echo "===========================================\n";
        echo "✗ SETUP COMPLETED WITH ERRORS\n";
        echo "===========================================\n";
    }

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

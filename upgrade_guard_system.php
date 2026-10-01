<?php
require_once __DIR__ . "/vendor/autoload.php";
use ROOTS\Config\Database;
$db = Database::getConnection();

echo "Expanding security guard system to support guest tracking...\n";
$queries = [
    // 1. Add identifier column to support User-Agent based tracking for guests
    "ALTER TABLE blocked_navigation_guard ADD COLUMN identifier VARCHAR(100) DEFAULT NULL AFTER user_id",
    // 2. Add index for faster lookups by identifier
    "CREATE INDEX idx_guard_identifier ON blocked_navigation_guard(identifier)",
    // 3. Make user_id nullable so guests can be recorded
    "ALTER TABLE blocked_navigation_guard MODIFY COLUMN user_id INT NULL",
    // 4. Also update blocked_nav table which logs individual hits
    "ALTER TABLE blocked_nav MODIFY COLUMN user_id INT NULL",
    "ALTER TABLE blocked_nav MODIFY COLUMN ip VARCHAR(100) NOT NULL"
];

foreach ($queries as $q) {
    if ($db->query($q)) {
        echo "Executed: $q\n";
    } else {
        echo "Error executing $q: " . $db->error . "\n";
    }
}

unlink(__FILE__);

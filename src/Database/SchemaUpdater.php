<?php

declare(strict_types=1);

namespace ROOTS\Database;

use mysqli;

/**
 * Schema Updater
 * Handles database schema updates and migrations
 */
class SchemaUpdater
{
    /**
     * Update leaderboard schema if needed
     *
     * @param mysqli $con Database connection
     * @return bool True if successful, false otherwise
     */
    public static function updateLeaderboardSchema(mysqli $con): bool
    {
        $success = false;
        try {
            // Check if leaderboard table exists
            $result = $con->query("SHOW TABLES LIKE 'leaderboard'");
            if (!is_object($result) || $result->num_rows === 0) {
                // Create leaderboard table if it doesn't exist
                $createTable = "CREATE TABLE IF NOT EXISTS `leaderboard` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(50) NOT NULL,
                    `total_points` INT NOT NULL DEFAULT 0,
                    `approved_submissions` INT NOT NULL DEFAULT 0,
                    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `username` (`username`),
                    KEY `idx_total_points` (`total_points` DESC),
                    KEY `idx_approved_submissions` (`approved_submissions` DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

                if (!$con->query($createTable)) {
                    error_log("Failed to create leaderboard table: " . $con->error);
                    return false;
                }
            }

            // Check and add missing columns if needed
            $result = $con->query("SHOW COLUMNS FROM `leaderboard` LIKE 'last_updated'");
            if (!is_object($result) || $result->num_rows === 0) {
                $alterTable = "ALTER TABLE `leaderboard`
                    ADD COLUMN `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";

                if (!$con->query($alterTable)) {
                    error_log("Failed to add last_updated column: " . $con->error);
                    return false;
                }
            }

            $success = true;
        } catch (\Throwable $e) {
            error_log("SchemaUpdater error: " . $e->getMessage());
        }

        return $success;
    }

    /**
     * Update user_purchases schema if needed
     *
     * @param mysqli $con Database connection
     * @return bool True if successful, false otherwise
     */
    public static function updateUserPurchasesSchema(mysqli $con): bool
    {
        try {
            // Check if user_purchases table exists
            $result = $con->query("SHOW TABLES LIKE 'user_purchases'");
            if (!is_object($result) || $result->num_rows === 0) {
                // Create user_purchases table if it doesn't exist
                $createTable = "CREATE TABLE IF NOT EXISTS `user_purchases` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` VARCHAR(50) NOT NULL,
                    `record_id` INT NOT NULL,
                    `original_data` LONGTEXT,
                    `purchased_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `points_spent` INT NOT NULL DEFAULT 0,
                    KEY `idx_user_id` (`user_id`),
                    KEY `idx_record_id` (`record_id`),
                    KEY `idx_purchased_at` (`purchased_at` DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

                if (!$con->query($createTable)) {
                    error_log("Failed to create user_purchases table: " . $con->error);
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            error_log("SchemaUpdater error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Run all schema updates
     *
     * @param mysqli $con Database connection
     * @return bool True if all updates successful, false otherwise
     */
    public static function runAll(mysqli $con): bool
    {
        return self::updateLeaderboardSchema($con)
            && self::updateUserPurchasesSchema($con);
    }
}

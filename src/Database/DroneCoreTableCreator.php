<?php

declare(strict_types=1);

namespace ROOTS\Database;

/**
 * DroneCoreTableCreator - Creates core drone-related tables
 */
class DroneCoreTableCreator extends AbstractTableCreator
{
    /**
     * Create drone_registrations table
     */
    public function createDroneRegistrationsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            drone_id VARCHAR(64) UNIQUE NOT NULL,
            drone_name VARCHAR(255),
            user_id INT NOT NULL,
            api_key VARCHAR(128) UNIQUE NOT NULL,
            api_secret VARCHAR(255) NOT NULL,
            drone_type VARCHAR(100),
            camera_specs JSON,
            status ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
            last_active TIMESTAMP NULL,
            subscription_tier ENUM('basic', 'premium', 'vip', 'elite') DEFAULT 'basic',
            rate_limit_per_minute INT DEFAULT 60,
            rate_limit_per_hour INT DEFAULT 1000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_user_drones (user_id),
            INDEX idx_api_key (api_key),
            INDEX idx_status (status),
            INDEX idx_subscription (subscription_tier),
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_registrations');
    }

    /**
     * Create drone_api_stats table
     */
    public function createDroneApiStatsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_api_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            stat_hour TINYINT,
            user_id INT NULL,
            drone_id VARCHAR(64) NULL,

            total_drones_active INT DEFAULT 0,
            total_face_scans INT DEFAULT 0,
            successful_matches INT DEFAULT 0,
            failed_matches INT DEFAULT 0,
            faces_detected_total INT DEFAULT 0,
            avg_response_time_ms DECIMAL(10,2),
            avg_confidence_score DECIMAL(5,2),
            total_data_processed_mb DECIMAL(12,2),
            api_calls_total INT DEFAULT 0,
            api_calls_successful INT DEFAULT 0,
            api_calls_failed INT DEFAULT 0,

            UNIQUE KEY idx_date_hour_user_drone (stat_date, stat_hour, user_id, drone_id),
            INDEX idx_stat_date (stat_date),
            INDEX idx_user_stats (user_id),
            INDEX idx_drone_stats (drone_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_api_stats');
    }
}

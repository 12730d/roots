<?php

declare(strict_types=1);

namespace ROOTS\Database;

/**
 * SessionTableCreator - Creates session and rate limiting tables
 */
class SessionTableCreator extends AbstractTableCreator
{
    /**
     * Create drone_active_sessions table
     */
    public function createDroneActiveSessionsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_active_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) UNIQUE NOT NULL,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            session_token VARCHAR(255) UNIQUE NOT NULL,
            session_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            status ENUM('active', 'expired', 'revoked') DEFAULT 'active',
            expires_at TIMESTAMP,
            activity_count INT DEFAULT 0,

            INDEX idx_drone_session (drone_id),
            INDEX idx_session_token (session_token),
            INDEX idx_session_status (status),
            INDEX idx_expires_at (expires_at),
            INDEX idx_user_sessions (user_id),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_active_sessions');
    }

    /**
     * Create drone_rate_limit_tracking table
     */
    public function createDroneRateLimitTrackingTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_rate_limit_tracking (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_key VARCHAR(128) UNIQUE NOT NULL,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            endpoint VARCHAR(100),
            request_count INT DEFAULT 0,
            window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            window_end TIMESTAMP,
            blocked_until TIMESTAMP NULL,
            violation_count INT DEFAULT 0,

            INDEX idx_tracking_key (tracking_key),
            INDEX idx_drone_tracking (drone_id),
            INDEX idx_window_end (window_end),
            INDEX idx_blocked_until (blocked_until),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_rate_limit_tracking');
    }
}

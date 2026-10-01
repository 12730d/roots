<?php

declare(strict_types=1);

namespace ROOTS\Database;

/**
 * MonitoringTableCreator - Creates monitoring and logging tables
 */
class MonitoringTableCreator extends AbstractTableCreator
{
    /**
     * Create drone_activity_log table
     */
    public function createDroneActivityLogTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            activity_type VARCHAR(50) NOT NULL,
            activity_description TEXT,
            activity_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            success BOOLEAN DEFAULT TRUE,
            error_message TEXT,
            metadata JSON,

            INDEX idx_drone_activity (drone_id),
            INDEX idx_user_activity (user_id),
            INDEX idx_activity_timestamp (activity_timestamp),
            INDEX idx_activity_type (activity_type),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_activity_log');
    }

    /**
     * Create drone_notifications table
     */
    public function createDroneNotificationsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id VARCHAR(64) UNIQUE NOT NULL,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            title VARCHAR(255),
            message TEXT,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            status ENUM('unread', 'read', 'archived') DEFAULT 'unread',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            metadata JSON,

            INDEX idx_drone_notifications (drone_id),
            INDEX idx_user_notifications (user_id),
            INDEX idx_notification_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_priority (priority),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_notifications');
    }

    /**
     * Create drone_security_events table
     */
    public function createDroneSecurityEventsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_security_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) UNIQUE NOT NULL,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            severity ENUM('info', 'warning', 'critical') DEFAULT 'warning',
            description TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            event_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved BOOLEAN DEFAULT FALSE,
            resolved_at TIMESTAMP NULL,
            resolved_by INT NULL,
            metadata JSON,

            INDEX idx_drone_security (drone_id),
            INDEX idx_user_security (user_id),
            INDEX idx_event_timestamp (event_timestamp),
            INDEX idx_severity (severity),
            INDEX idx_resolved (resolved),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_security_events');
    }
}

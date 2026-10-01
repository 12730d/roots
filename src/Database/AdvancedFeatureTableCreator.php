<?php

declare(strict_types=1);

namespace ROOTS\Database;

/**
 * AdvancedFeatureTableCreator - Creates advanced feature tables
 */
class AdvancedFeatureTableCreator extends AbstractTableCreator
{
    /**
     * Create drone_webhook_configs table
     */
    public function createDroneWebhookConfigsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_webhook_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            drone_id VARCHAR(64) NULL,
            webhook_url VARCHAR(2048) NOT NULL,
            webhook_events JSON NOT NULL,
            webhook_secret VARCHAR(255),
            is_active BOOLEAN DEFAULT TRUE,
            last_triggered TIMESTAMP NULL,
            trigger_count INT DEFAULT 0,
            failure_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_user_webhooks (user_id),
            INDEX idx_drone_webhooks (drone_id),
            INDEX idx_webhook_status (is_active),
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE,
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_webhook_configs');
    }

    /**
     * Create drone_api_keys_history table
     */
    public function createDroneApiKeysHistoryTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS drone_api_keys_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            old_api_key VARCHAR(128) NOT NULL,
            old_api_secret VARCHAR(255) NOT NULL,
            new_api_key VARCHAR(128) NOT NULL,
            new_api_secret VARCHAR(255) NOT NULL,
            change_reason VARCHAR(255),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            changed_by INT NULL,

            INDEX idx_drone_history (drone_id),
            INDEX idx_user_history (user_id),
            INDEX idx_changed_at (changed_at),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'drone_api_keys_history');
    }
}

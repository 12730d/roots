<?php

declare(strict_types=1);

namespace ROOTS\Database;

/**
 * FaceScanTableCreator - Creates face scan related tables
 */
class FaceScanTableCreator extends AbstractTableCreator
{
    /**
     * Create face_scan_logs table
     */
    public function createFaceScanLogsTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS face_scan_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(64) UNIQUE NOT NULL,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            scan_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            image_hash VARCHAR(64),
            image_size BIGINT,
            location_lat DECIMAL(10,8),
            location_lng DECIMAL(11,8),
            location_alt DECIMAL(8,2),
            face_detected BOOLEAN DEFAULT FALSE,
            faces_detected_count INT DEFAULT 0,
            face_match_found BOOLEAN DEFAULT FALSE,
            matched_face_id INT NULL,
            matched_user_id INT NULL,
            confidence_score DECIMAL(5,2),
            processing_time_ms INT,
            encryption_method VARCHAR(50),
            api_response_code INT,
            error_message TEXT,
            metadata JSON,

            INDEX idx_drone_scans (drone_id),
            INDEX idx_user_scans (user_id),
            INDEX idx_scan_timestamp (scan_timestamp),
            INDEX idx_scan_id (scan_id),
            INDEX idx_location (location_lat, location_lng),
            INDEX idx_face_match (face_match_found, matched_face_id),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'face_scan_logs');
    }

    /**
     * Create registered_faces table
     */
    public function createRegisteredFacesTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS registered_faces (
            id INT AUTO_INCREMENT PRIMARY KEY,
            face_id VARCHAR(64) UNIQUE NOT NULL,
            user_id INT NOT NULL,
            face_name VARCHAR(255),
            face_encoding BLOB,
            face_encoding_hash VARCHAR(64),
            face_thumbnail BLOB,
            face_thumbnail_hash VARCHAR(64),
            registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            registration_source VARCHAR(50),
            metadata JSON,
            status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
            scan_count INT DEFAULT 0,

            INDEX idx_user_faces (user_id),
            INDEX idx_face_status (status),
            INDEX idx_face_id (face_id),
            INDEX idx_registration_date (registration_date),
            FOREIGN KEY (user_id) REFERENCES login(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'registered_faces');
    }

    /**
     * Create face_scan_queue table
     */
    public function createFaceScanQueueTable(): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS face_scan_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            queue_id VARCHAR(64) UNIQUE NOT NULL,
            drone_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            image_path VARCHAR(512),
            image_hash VARCHAR(64),
            priority TINYINT DEFAULT 5,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            retry_count INT DEFAULT 0,
            max_retries INT DEFAULT 3,
            error_message TEXT,
            processing_time_ms INT,

            INDEX idx_queue_status (status),
            INDEX idx_priority (priority),
            INDEX idx_created_at (created_at),
            INDEX idx_drone_queue (drone_id),
            FOREIGN KEY (drone_id) REFERENCES drone_registrations(drone_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $this->executeSql($sql, 'face_scan_queue');
    }
}

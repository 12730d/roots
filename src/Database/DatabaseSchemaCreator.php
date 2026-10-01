<?php

declare(strict_types=1);

namespace ROOTS\Database;

/**
 * DatabaseSchemaCreator - Creates database schema elements (views, procedures, triggers, indexes)
 */
class DatabaseSchemaCreator extends AbstractTableCreator
{
    /**
     * Create database views
     */
    public function createViews(): string
    {
        $views = [
            // Active drones view
            "CREATE OR REPLACE VIEW v_active_drones AS
            SELECT
                dr.id, dr.drone_id, dr.drone_name, dr.user_id, l.username,
                l.subscription as user_subscription, dr.status, dr.subscription_tier,
                dr.last_active, dr.rate_limit_per_minute, dr.rate_limit_per_hour,
                (SELECT COUNT(*) FROM face_scan_logs WHERE drone_id = dr.drone_id) as total_scans,
                (SELECT COUNT(*) FROM face_scan_logs WHERE drone_id = dr.drone_id AND face_match_found = TRUE) as successful_matches
            FROM drone_registrations dr
            JOIN login l ON dr.user_id = l.id
            WHERE dr.status = 'active'",

            // Recent face scans view
            "CREATE OR REPLACE VIEW v_recent_face_scans AS
            SELECT
                fsl.id, fsl.scan_id, fsl.drone_id, fsl.user_id, l.username as operator,
                fsl.scan_timestamp, fsl.face_detected, fsl.faces_detected_count,
                fsl.face_match_found, fsl.confidence_score, fsl.processing_time_ms,
                fsl.location_lat, fsl.location_lng, dr.drone_name
            FROM face_scan_logs fsl
            JOIN drone_registrations dr ON fsl.drone_id = dr.drone_id
            JOIN login l ON fsl.user_id = l.id
            ORDER BY fsl.scan_timestamp DESC LIMIT 1000",

            // User statistics view
            "CREATE OR REPLACE VIEW v_user_drone_stats AS
            SELECT
                u.id as user_id, u.username, u.subscription,
                COUNT(DISTINCT dr.id) as total_drones,
                SUM(CASE WHEN dr.status = 'active' THEN 1 ELSE 0 END) as active_drones,
                SUM(das.total_face_scans) as total_scans,
                SUM(das.successful_matches) as total_matches,
                AVG(das.avg_response_time_ms) as avg_response_time
            FROM login u
            LEFT JOIN drone_registrations dr ON u.id = dr.user_id
            LEFT JOIN drone_api_stats das ON u.id = das.user_id
            GROUP BY u.id, u.username, u.subscription",
        ];

        foreach ($views as $view) {
            $this->db->query($view);
        }

        return "database_views";
    }

    /**
     * Create stored procedures
     */
    public function createStoredProcedures(): string
    {
        $procedures = [
            // Procedure to record API usage
            "DROP PROCEDURE IF EXISTS sp_record_api_usage",
            "CREATE PROCEDURE sp_record_api_usage(
                IN p_drone_id VARCHAR(64),
                IN p_user_id INT,
                IN p_endpoint VARCHAR(100),
                IN p_success BOOLEAN,
                IN p_response_time_ms INT
            )
            BEGIN
                INSERT INTO drone_activity_log (drone_id, user_id, activity_type, activity_description, success, metadata)
                VALUES (p_drone_id, p_user_id, 'api_call', CONCAT('Endpoint: ', p_endpoint), p_success,
                        JSON_OBJECT('response_time_ms', p_response_time_ms));

                INSERT INTO drone_api_stats (stat_date, stat_hour, user_id, drone_id, api_calls_total, api_calls_successful, api_calls_failed)
                VALUES (CURDATE(), HOUR(NOW()), p_user_id, p_drone_id, 1, IF(p_success, 1, 0), IF(p_success, 0, 1))
                ON DUPLICATE KEY UPDATE
                    api_calls_total = api_calls_total + 1,
                    api_calls_successful = api_calls_successful + IF(p_success, 1, 0),
                    api_calls_failed = api_calls_failed + IF(p_success, 0, 1);
            END",

            // Procedure to get drone status
            "DROP PROCEDURE IF EXISTS sp_get_drone_status",
            "CREATE PROCEDURE sp_get_drone_status(IN p_drone_id VARCHAR(64))
            BEGIN
                SELECT
                    dr.id, dr.drone_id, dr.drone_name, dr.user_id, dr.status,
                    dr.subscription_tier, dr.last_active, dr.rate_limit_per_minute, dr.rate_limit_per_hour,
                    (SELECT COUNT(*) FROM face_scan_logs WHERE drone_id = p_drone_id AND scan_timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as scans_last_24h,
                    (SELECT COUNT(*) FROM face_scan_logs WHERE drone_id = p_drone_id AND face_match_found = TRUE AND scan_timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as matches_last_24h
                FROM drone_registrations dr
                WHERE dr.drone_id = p_drone_id;
            END",
        ];

        foreach ($procedures as $procedure) {
            $this->db->query($procedure);
        }

        return "stored_procedures";
    }

    /**
     * Create database triggers
     */
    public function createTriggers(): string
    {
        $triggers = [
            // Trigger to update face scan count on registered faces
            "DROP TRIGGER IF EXISTS tr_face_scan_update_count",
            "CREATE TRIGGER tr_face_scan_update_count
            AFTER INSERT ON face_scan_logs
            FOR EACH ROW
            BEGIN
                IF NEW.face_match_found = TRUE AND NEW.matched_face_id IS NOT NULL THEN
                    UPDATE registered_faces SET scan_count = scan_count + 1 WHERE id = NEW.matched_face_id;
                END IF;
            END",

            // Trigger to log webhook failures
            "DROP TRIGGER IF EXISTS tr_webhook_failure_log",
            "CREATE TRIGGER tr_webhook_failure_log
            AFTER UPDATE ON drone_webhook_configs
            FOR EACH ROW
            BEGIN
                IF NEW.failure_count > OLD.failure_count THEN
                    INSERT INTO drone_security_events (event_id, drone_id, user_id, event_type, severity, description)
                    VALUES (UUID(), NEW.drone_id, NEW.user_id, 'webhook_failure', 'warning',
                            CONCAT('Webhook failure count increased to ', NEW.failure_count));
                END IF;
            END",
        ];

        foreach ($triggers as $trigger) {
            $this->db->query($trigger);
        }

        return "database_triggers";
    }

    /**
     * Create optimization indexes
     */
    public function createOptimizationIndexes(): string
    {
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_scan_logs_composite ON face_scan_logs (drone_id, scan_timestamp, face_match_found)",
            "CREATE INDEX IF NOT EXISTS idx_activity_log_composite ON drone_activity_log (drone_id, activity_timestamp, activity_type)",
            "CREATE INDEX IF NOT EXISTS idx_api_stats_composite ON drone_api_stats (stat_date, user_id, drone_id)",
            "CREATE INDEX IF NOT EXISTS idx_sessions_composite ON drone_active_sessions (drone_id, status, last_activity)",
            "CREATE INDEX IF NOT EXISTS idx_security_events_composite ON drone_security_events (drone_id, event_timestamp, severity)",
        ];

        foreach ($indexes as $index) {
            try {
                $this->db->query($index);
            } catch (\Exception $e) {
                // Some MySQL versions don't support IF NOT EXISTS for indexes
                // Ignore errors if index already exists
            }
        }

        return "optimization_indexes";
    }
}

<?php

declare(strict_types=1);

namespace ROOTS\Database;

use ROOTS\Config\Database;
use Exception;

/**
 * DroneDatabaseInitializer - Initialize all tables for Drone Face Scanner API
 *
 * This class coordinates the database schema setup by delegating to specialized creator classes
 */
class DroneDatabaseInitializer
{
    private \mysqli $db;
    /** @var array<int, string> */
    private array $errors = [];

    public function __construct()
    {
        $this->db = Database::getConnection();
        if (!$this->db) {
            throw new DatabaseInitializationException("Database connection failed");
        }
    }

    /**
     * Initialize all database tables
     * @return array<string, mixed>
     */
    public function initializeAll(): array
    {
        $results = [
            'success' => true,
            'tables_created' => [],
            'errors' => []
        ];

        try {
            // Initialize specialized creators
            $coreCreator = new DroneCoreTableCreator($this->db);
            $faceScanCreator = new FaceScanTableCreator($this->db);
            $sessionCreator = new SessionTableCreator($this->db);
            $monitoringCreator = new MonitoringTableCreator($this->db);
            $advancedCreator = new AdvancedFeatureTableCreator($this->db);
            $schemaCreator = new DatabaseSchemaCreator($this->db);

            // Core tables
            $results['tables_created'][] = $coreCreator->createDroneRegistrationsTable();
            $results['tables_created'][] = $faceScanCreator->createFaceScanLogsTable();
            $results['tables_created'][] = $faceScanCreator->createRegisteredFacesTable();
            $results['tables_created'][] = $coreCreator->createDroneApiStatsTable();

            // Session and rate limiting
            $results['tables_created'][] = $sessionCreator->createDroneActiveSessionsTable();
            $results['tables_created'][] = $sessionCreator->createDroneRateLimitTrackingTable();

            // Logging and monitoring
            $results['tables_created'][] = $monitoringCreator->createDroneActivityLogTable();
            $results['tables_created'][] = $monitoringCreator->createDroneNotificationsTable();
            $results['tables_created'][] = $advancedCreator->createDroneWebhookConfigsTable();

            // Advanced features
            $results['tables_created'][] = $faceScanCreator->createFaceScanQueueTable();
            $results['tables_created'][] = $advancedCreator->createDroneApiKeysHistoryTable();
            $results['tables_created'][] = $monitoringCreator->createDroneSecurityEventsTable();

            // Create schema elements
            $results['tables_created'][] = $schemaCreator->createViews();
            $results['tables_created'][] = $schemaCreator->createStoredProcedures();
            $results['tables_created'][] = $schemaCreator->createTriggers();
            $results['tables_created'][] = $schemaCreator->createOptimizationIndexes();

            // Collect errors from all creators
            $this->errors = array_merge(
                $this->errors,
                $coreCreator->getErrors(),
                $faceScanCreator->getErrors(),
                $sessionCreator->getErrors(),
                $monitoringCreator->getErrors(),
                $advancedCreator->getErrors(),
                $schemaCreator->getErrors()
            );

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }

        $results['errors'] = array_merge($results['errors'], $this->errors);
        return $results;
    }

    /**
     * Get all errors that occurred during initialization
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Verify all tables exist
     * @return array<string, mixed>
     */
    public function verifyTables(): array
    {
        $requiredTables = [
            'drone_registrations', 'face_scan_logs', 'registered_faces',
            'drone_api_stats', 'drone_active_sessions', 'drone_rate_limit_tracking',
            'drone_activity_log', 'drone_notifications', 'drone_webhook_configs',
            'face_scan_queue', 'drone_api_keys_history', 'drone_security_events'
        ];

        $verification = [];
        foreach ($requiredTables as $table) {
            $result = $this->db->query("SHOW TABLES LIKE '$table'");
            $verification[$table] = (is_object($result) && $result->num_rows > 0);
        }

        return $verification;
    }
}

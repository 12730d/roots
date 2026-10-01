<?php

namespace ROOTS\Database;

use ROOTS\Config\Database;
use Exception;

class HealthMonitor
{

    private \mysqli $db;
    private DatabaseValidator $validator;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->validator = new DatabaseValidator();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkHealth(): array
    {
        $status = [
            'status' => 'healthy',
            'database_connection' => 'ok',
            'timestamp' => date('c'),
            'checks' => []
        ];

        // Check 1: Database Connection
        if ($this->db->connect_error) {
            $status['status'] = 'critical';
            $status['database_connection'] = 'failed';
            return $status;
        }

        // Check 2: Core Table Existence
        $missingTables = $this->validator->validateSchema();
        if (!empty($missingTables)) {
            $status['status'] = 'degraded';
            $status['checks']['missing_tables'] = $missingTables;
        } else {
            $status['checks']['schema'] = 'ok';
        }

        // Check 3: Database Load (simple query time check)
        $start = microtime(true);
        $this->db->query("SELECT 1");
        $duration = microtime(true) - $start;

        $status['checks']['latency_ms'] = round($duration * 1000, 2);

        if ($duration > 1.0) {
            $status['checks']['performance'] = 'slow';
            $status['status'] = 'warning';
        } else {
            $status['checks']['performance'] = 'ok';
        }

        return $status;
    }
}

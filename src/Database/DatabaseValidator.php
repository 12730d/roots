<?php

namespace ROOTS\Database;

use ROOTS\Config\Database;
use Exception;

class DatabaseValidator
{

    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * @param array<int, string> $requiredTables
     * @return array<int, string>
     */
    public function validateTables(array $requiredTables): array
    {
        $missingTables = [];

        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $missingTables[] = $table;
            }
        }

        return $missingTables;
    }

    public function tableExists(string $tableName): bool
    {
        // Sanitize table name to prevent SQL injection (though this is internal use)
        $tableName = preg_replace('/\W/', '', $tableName);

        $result = $this->db->query("SHOW TABLES LIKE '$tableName'");
        return is_object($result) && $result->num_rows > 0;
    }

    /**
     * @return array<int, string>
     */
    public function validateSchema(): array
    {
        // Check for core critical tables
        $tables = ['login', 'user_permissions', 'user_activity_log', 'shared_files'];
        return $this->validateTables($tables);
    }
}

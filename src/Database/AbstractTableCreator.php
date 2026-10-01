<?php

declare(strict_types=1);

namespace ROOTS\Database;

use Exception;

/**
 * AbstractTableCreator - Base class for table creation with common functionality
 */
abstract class AbstractTableCreator
{
    protected \mysqli $db;
    /** @var array<int, string> */
    protected array $errors = [];

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Execute SQL and return table name or error message
     */
    protected function executeSql(string $sql, string $tableName): string
    {
        try {
            $result = $this->db->query($sql);
            if ($result) {
                return $tableName;
            }

            $error = $this->db->error;
            $this->errors[] = "$tableName creation failed: $error";
            return "$tableName creation failed";
        } catch (Exception $e) {
            $this->errors[] = "$tableName error: " . $e->getMessage();
            return "$tableName error";
        }
    }

    /**
     * Get accumulated errors
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

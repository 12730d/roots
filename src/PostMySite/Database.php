<?php

namespace ROOTS\PostMySite;

use ROOTS\Config\Database as MainDatabase;
use ROOTS\Config\EnvLoader;
use Exception;
use mysqli;

class Database
{
    /** @var self|null */
    private static $instance = null;

    /** @var mysqli */
    private $connection;

    private function __construct()
    {
        // Use the main Database configuration to get connection parameters
        // But the main Database::getConnection() returns a connection,
        // while this class wraps it and adds methods.
        // We can reuse the MainDatabase logic or just use its connection.

        $this->connection = MainDatabase::getConnection();

        if ($this->connection->connect_error) {
            throw new Exception(
                "Connection failed: " . $this->connection->connect_error,
            );
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): mysqli
    {
        return $this->connection;
    }

    /** @param array<int, mixed> $params */
    public function query(string $sql, array $params = []): \mysqli_stmt
    {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }

        if (!empty($params)) {
            $types = "";
            $values = [];

            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= "i";
                } elseif (is_float($param)) {
                    $types .= "d";
                } else {
                    $types .= "s";
                }
                $values[] = $param;
            }

            $stmt->bind_param($types, ...$values);
        }

        $stmt->execute();
        return $stmt;
    }

    public function escape(string $string): string
    {
        return $this->connection->real_escape_string($string);
    }

    public function lastInsertId(): int
    {
        return (int)$this->connection->insert_id;
    }

    public function beginTransaction(): bool
    {
        return $this->connection->begin_transaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollback();
    }

    // Additional helper methods from original class...
    /** @param array<int, mixed> $params
     * @return array<int, array<string, string|null>> */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** @param array<int, mixed> $params
     * @return array<string, float|int|string|null>|null */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        if (!$result) return null;
        $row = $result->fetch_assoc();
        return $row !== false ? $row : null;
    }

    // ... insert, update, delete, exists, rowCount could be added similarly if used.
    // Copying them for completeness:

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        $columns = implode(", ", array_keys($data));
        $placeholders = str_repeat("?,", count($data) - 1) . "?";
        $values = array_values($data);

        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $this->query($sql, $values);

        return $this->lastInsertId();
    }

    /** @param array<string, mixed> $data
     * @param array<int, mixed> $whereParams */
    public function update(string $table, array $data, string $where, array $whereParams = []): int|false
    {
        $setClause = [];
        $values = [];

        foreach ($data as $column => $value) {
            $setClause[] = "$column = ?";
            $values[] = $value;
        }

        $setClause = implode(", ", $setClause);
        $sql = "UPDATE $table SET $setClause WHERE $where";
        $allValues = array_merge($values, (array) $whereParams);

        $stmt = $this->query($sql, $allValues);
        return (int)$stmt->affected_rows;
    }

    /** @param array<int, mixed> $params */
    public function delete(string $table, string $where, array $params = []): int|false
    {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->query($sql, $params);
        return (int)$stmt->affected_rows;
    }

    /** @param array<int, mixed> $params */
    public function exists(string $table, string $where, array $params = []): bool
    {
        $sql = "SELECT 1 FROM $table WHERE $where LIMIT 1";
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    /** @param array<int, mixed> $params */
    public function rowCount(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        return $result !== false ? (int)$result->num_rows : 0;
    }

    private function __clone()
    {
        throw new Exception("Cannot clone singleton");
    }
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}

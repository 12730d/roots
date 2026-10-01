<?php

declare(strict_types=1);

/**
 * Database Configuration and Optimization
 * Uses centralized configuration from config/db_config.php
 */
namespace ROOTS\Database;

use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;
use Exception;
use mysqli;
use mysqli_result;

// Performance settings
if (!defined("DB_CONNECT_TIMEOUT")) {
    define("DB_CONNECT_TIMEOUT", 5);
}
if (!defined("DB_READ_TIMEOUT")) {
    define("DB_READ_TIMEOUT", 30);
}
if (!defined("DB_WRITE_TIMEOUT")) {
    define("DB_WRITE_TIMEOUT", 30);
}

// Cache settings
if (!defined("CACHE_ENABLED")) {
    define("CACHE_ENABLED", true);
}
if (!defined("CACHE_TTL")) {
    define("CACHE_TTL", 300); // 5 minutes
}

/**
 * Database Connection Manager
 * Database connection manager
 */
class DatabaseManager
{
    /** @var self|null */
    private static $instance = null;
    /** @var mysqli|null */
    private $connection = null;
    /** @var array<string, array{result: array<int, array<string, mixed>>, timestamp: int}> */
    private $queryCache = [];

    private function __construct()
    {
        $this->connect();
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return void
     */
    private function connect(): void
    {
        try {
            // Use the centralized Database class to get the MySQLi connection
            $this->connection = Database::getConnection();

            if (!$this->connection) {
                throw new DatabaseException(
                    "Failed to initialize database connection",
                );
            }

            // Set connection options for better performance
            mysqli_options(
                $this->connection,
                MYSQLI_OPT_CONNECT_TIMEOUT,
                DB_CONNECT_TIMEOUT,
            );
            mysqli_options(
                $this->connection,
                MYSQLI_OPT_READ_TIMEOUT,
                DB_READ_TIMEOUT,
            );

            // Set session variables for better performance
            $this->executeQuery(
                "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            );
            $this->executeQuery("SET SESSION wait_timeout=300");
            $this->executeQuery("SET SESSION interactive_timeout=300");
            $this->executeQuery("SET SESSION query_cache_type=1");

            error_log("Database connection established successfully");
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return mysqli
     */
    public function getConnection(): mysqli
    {
        // Check if connection is still alive
        if (
            !$this->connection ||
            !@mysqli_query($this->connection, "SELECT 1")
        ) {
            $this->connect();
        }
        if (!$this->connection) {
            throw new DatabaseException(
                "Connection failed after reconnect attempt",
            );
        }
        return $this->connection;
    }

    /**
     * Execute a prepared statement with caching
     * Execute a prepared query with caching
     * @param string $query
     * @param array<int, mixed> $params
     * @param string $types
     * @return array<int, array<string, mixed>>
     */
    public function executePrepared(
        string $query,
        array $params = [],
        string $types = ""
    ): array {
        $cacheKey = hash('sha256', $query . serialize($params));

        // Check cache first
        if (isset($this->queryCache[$cacheKey])) {
            $cached = $this->queryCache[$cacheKey];
            if (time() - $cached["timestamp"] < CACHE_TTL) {
                return $cached["result"];
            }
        }

        $stmt = mysqli_prepare($this->getConnection(), $query);
        if (!$stmt) {
            throw new DatabaseException(
                "Failed to prepare query: " .
                    mysqli_error($this->getConnection()),
            );
        }

        if (!empty($params)) {
            if (empty($types)) {
                $types = str_repeat("s", count($params));
            }
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }

        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new DatabaseException("Failed to execute query: " . $error);
        }

        $result = mysqli_stmt_get_result($stmt);
        $data = [];

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $data[] = $row;
            }
        }

        mysqli_stmt_close($stmt);

        // Cache the result
        $this->queryCache[$cacheKey] = [
            "result" => $data,
            "timestamp" => time(),
        ];

        return $data;
    }

    /**
     * Execute a simple query
     * Execute a simple query
     * @param string $query
     * @return mysqli_result|bool
     */
    public function executeQuery(string $query): mysqli_result|bool {
        $result = mysqli_query($this->getConnection(), $query);
        if (!$result) {
            throw new DatabaseException(
                "Query failed: " . mysqli_error($this->getConnection()),
            );
        }
        return $result;
    }

    /**
     * Begin transaction
     * Begin transaction
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return mysqli_begin_transaction($this->getConnection());
    }

    /**
     * Commit transaction
     * Confirm transaction
     * @return bool
     */
    public function commit(): bool
    {
        return mysqli_commit($this->getConnection());
    }

    /**
     * Rollback transaction
     * Cancel transaction (Rollback)
     * @return bool
     */
    public function rollback(): bool
    {
        return mysqli_rollback($this->getConnection());
    }

    /**
     * Clear query cache
     * Clear cache
     * @return void
     */
    public function clearCache(): void
    {
        $this->queryCache = [];
    }

    public function __destruct()
    {
        if ($this->connection) {
            mysqli_close($this->connection);
        }
    }
}

/**
 * Image optimization helper
 * Image optimization helper
 */
class ImageOptimizer
{
    const MAX_WIDTH = 800;
    const MAX_HEIGHT = 600;
    const QUALITY = 75;

    /**
     * @param string $imageData
     * @param int $maxWidth
     * @param int $quality
     * @return string|null
     */
    public static function compress(
        string $imageData,
        int $maxWidth = self::MAX_WIDTH,
        int $quality = self::QUALITY,
    ): ?string {
        $result = $imageData; // Default to original data
        if (empty($imageData)) {
            return null;
        }

        try {
            $image = imagecreatefromstring($imageData);
            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);

                // Calculate new dimensions
                $ratio = $width / $maxWidth;
                $newWidth = $ratio > 1 ? $maxWidth : $width;
                $newHeight = $ratio > 1 ? (int) ($height / $ratio) : $height;

                $newImage = imagecreatetruecolor(max(1, $newWidth), max(1, $newHeight));
                if ($newImage) {
                    // Preserve transparency
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);

                    imagecopyresampled(
                        $newImage,
                        $image,
                        0,
                        0,
                        0,
                        0,
                        $newWidth,
                        $newHeight,
                        $width,
                        $height,
                    );

                    ob_start();
                    imagejpeg($newImage, null, $quality);
                    $obContents = ob_get_contents();
                    ob_end_clean();
                    if ($obContents !== false) {
                        $result = $obContents;
                    }

                    imagedestroy($newImage);
                }
                imagedestroy($image);
            }
        } catch (Exception $e) {
            error_log("Image compression error: " . $e->getMessage());
        }

        return $result;
    }
}

/**
 * Query builder helper
 * Query builder helper
 */
class QueryBuilder
{
    /** @var string */
    private $query = "";
    /** @var array<int, mixed> */
    private $params = [];
    /** @var string */
    private $types = "";

    /**
     * @param string|array<int, string> $columns
     * @return self
     */
    public function select($columns): self
    {
        $this->query =
            "SELECT " .
            (is_array($columns) ? implode(", ", $columns) : $columns);
        return $this;
    }

    /**
     * @param string $table
     * @return self
     */
    public function from(string $table): self
    {
        $this->query .= " FROM " . $table;
        return $this;
    }

    /**
     * @param string $condition
     * @param array<int, mixed> $params
     * @param string $types
     * @return self
     */
    public function where(
        string $condition,
        array $params = [],
        string $types = ""
    ): self
    {
        $this->query .= " WHERE " . $condition;
        $this->params = array_merge($this->params, $params);
        $this->types .= $types;
        return $this;
    }

    /**
     * @param string $column
     * @param string $direction
     * @return self
     */
    public function orderBy(string $column, string $direction = "ASC"): self
    {
        $this->query .= " ORDER BY " . $column . " " . $direction;
        return $this;
    }

    /**
     * @param int $count
     * @param int $offset
     * @return self
     */
    public function limit(int $count, int $offset = 0): self
    {
        $this->query .= " LIMIT " . (int) $offset . ", " . (int) $count;
        return $this;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @return array<int, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return string
     */
    public function getTypes(): string
    {
        return $this->types;
    }
}

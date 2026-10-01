<?php

namespace ROOTS\Config;

use PDO;
use PDOException;

class Database
{
    private static function loadEnv(): void
    {
        EnvLoader::load();
    }

    /**
     * Get MySQLi connection
     * @return \mysqli
     */
    public static function getConnection()
    {
        self::loadEnv();

        /** @phpstan-ignore-next-line */
        $db_host = $_ENV["DB_HOST"] ?? getenv("DB_HOST") ?? "localhost";
        /** @phpstan-ignore-next-line */
        $db_user = $_ENV["DB_USER"] ?? getenv("DB_USER") ?? "root";
        /** @phpstan-ignore-next-line */
        $db_pass = $_ENV["DB_PASS"] ?? getenv("DB_PASS") ?? "";
        /** @phpstan-ignore-next-line */
        $db_name = $_ENV["DB_NAME"] ?? getenv("DB_NAME") ?? "users_app";
        /** @phpstan-ignore-next-line */
        $db_port = $_ENV["DB_PORT"] ?? getenv("DB_PORT") ?? 3306;

        $conn = \mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

        if (!$conn) {
            error_log("Database connection failed: " . \mysqli_connect_error());
            die("Database connection error. Please contact support.");
        }

        \mysqli_set_charset($conn, "utf8mb4");
        return $conn;
    }

    /**
     * Get PDO connection
     * @return PDO
     */
    public static function getPdoConnection()
    {
        self::loadEnv();

        /** @phpstan-ignore-next-line */
        $db_host = $_ENV["DB_HOST"] ?? getenv("DB_HOST") ?? "localhost";
        /** @phpstan-ignore-next-line */
        $db_user = $_ENV["DB_USER"] ?? getenv("DB_USER") ?? "root";
        /** @phpstan-ignore-next-line */
        $db_pass = $_ENV["DB_PASS"] ?? getenv("DB_PASS") ?? "";
        /** @phpstan-ignore-next-line */
        $db_name = $_ENV["DB_NAME"] ?? getenv("DB_NAME") ?? "users_app";
        /** @phpstan-ignore-next-line */
        $db_port = $_ENV["DB_PORT"] ?? getenv("DB_PORT") ?? 3306;

        $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            return new PDO($dsn, $db_user, $db_pass, $options);
        } catch (PDOException $e) {
            error_log("PDO Connection failed: " . $e->getMessage());
            die("Database connection error. Please contact support.");
        }
    }
}

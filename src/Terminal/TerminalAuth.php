<?php

namespace ROOTS\Terminal;

use ROOTS\Config\AppConfig;
use mysqli;
use Exception;

class TerminalAuth
{
    /**
     * Initialize terminal database connection and verify authentication
     *
     * @return array{0: \mysqli, 1: int}
     * @throws TerminalException On connection or authentication failure
     */
    public static function init(): array
    {
        AppConfig::init();

        $db_host = $_ENV["DB_HOST"] ?? "localhost";
        $db_user = $_ENV["DB_USER"] ?? "root";
        $db_pass = $_ENV["DB_PASS"] ?? "";
        $db_name = $_ENV["DB_NAME"] ?? "users_app";

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
            $mysqli->set_charset("utf8mb4");
        } catch (Exception $e) {
            error_log("[DB Connection Error] " . $e->getMessage());
            die(
                "System maintenance: Database connection unavailable. Please try again later."
            );
        }

        self::startSecureSession();

        if (!isset($_SESSION["username"])) {
            header("Location: ../login.php");
            exit();
        }

        try {
            $stmt = $mysqli->prepare(
                "SELECT id, username FROM login WHERE username = ? LIMIT 1",
            );
            if (!$stmt) {
                throw new TerminalException(
                    "Failed to prepare user lookup statement.",
                );
            }

            $stmt->bind_param("s", $_SESSION["username"]);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result !== false ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user) {
                session_unset();
                session_destroy();
                header("Location: ../login.php?error=invalid_user");
                exit();
            }

            return [$mysqli, (int) $user["id"]];
        } catch (Exception $e) {
            error_log("[Auth Exception] " . $e->getMessage());
            die("Authentication system error.");
        }
    }

    /**
     * Start a secure session for the terminal
     */
    private static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set("session.cookie_httponly", "1");
            ini_set("session.use_only_cookies", "1");
            ini_set("session.cookie_samesite", "Strict");
            ini_set("session.use_strict_mode", "1");
            ini_set("session.gc_maxlifetime", "1800");
            ini_set("session.use_trans_sid", "0");

            if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
                ini_set("session.cookie_secure", "1");
            }

            session_name("ROOTS_SESSION");
            session_start();
        }
    }
}

<?php

namespace ROOTS\Auth;

use ROOTS\Config\Database;

class Session
{
    const LOGIN_REDIRECT_URL = "/login";
    const LOCATION_HEADER = "Location: ";

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Match PageController settings - Unified secure session configuration
            ini_set("session.cookie_httponly", "1");
            ini_set("session.use_only_cookies", "1");
            ini_set("session.cookie_samesite", "Lax");
            ini_set("session.use_strict_mode", "1");
            ini_set("session.gc_maxlifetime", "1800");
            ini_set("session.use_trans_sid", "0"); // Never pass session ID in URL

            // Enable HTTPS-only cookies if on HTTPS
            if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
                ini_set("session.cookie_secure", "1");
            }

            session_name("ROOTS_SESSION");
            session_start();
        }
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION["username"]);
    }

    public static function getUserRole(): ?string
    {
        if (!self::isLoggedIn()) {
            return null;
        }

        $role = "user";
        $db = Database::getConnection();

        if ($db) {
            $stmt = \mysqli_prepare(
                $db,
                "SELECT subscription FROM login WHERE username = ?",
            );
            if ($stmt) {
                \mysqli_stmt_bind_param($stmt, "s", $_SESSION["username"]);
                \mysqli_stmt_execute($stmt);
                $result = \mysqli_stmt_get_result($stmt);
                $user = $result !== false ? \mysqli_fetch_assoc($result) : null;
                \mysqli_stmt_close($stmt);

                if ($user && !empty($user["subscription"])) {
                    $role = (string)$user["subscription"];
                }
            }
        }

        return $role;
    }

    public static function isAdmin(): bool
    {
        return self::getUserRole() === "admin";
    }

    public static function isPup(): bool
    {
        return self::isLoggedIn();
    }

    public static function requirePup(): void
    {
        if (!self::isPup()) {
            header(self::LOCATION_HEADER . self::LOGIN_REDIRECT_URL);
            exit();
        }
    }

    public static function requireAdminOrPup(): void
    {
        if (!self::isAdmin() && !self::isPup()) {
            header(self::LOCATION_HEADER . self::LOGIN_REDIRECT_URL);
            exit();
        }
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header(self::LOCATION_HEADER . self::LOGIN_REDIRECT_URL);
            exit();
        }
    }

    /**
     * Completely destroy the current session.
     */
    public static function destroy(): void
    {
        self::start();

        // Unset all session variables
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            $sessName = session_name();
            if ($sessName !== false) {
                setcookie(
                    $sessName,
                    "",
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"],
                );
            }
        }

        // Finally, destroy the session
        session_destroy();
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            header(self::LOCATION_HEADER . self::LOGIN_REDIRECT_URL);
            exit();
        }
    }

    /**
     * Set a session variable
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Get a session variable
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if a session variable exists
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }
}

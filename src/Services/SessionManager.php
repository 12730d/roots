<?php

namespace ROOTS\Services;

/**
 * SessionManager - Handles session lifecycle and security
 * Compatible with Onion services and HTTPS
 */
class SessionManager
{
    private const HEADER_LOCATION = "Location: ";

    /** @var array<string, mixed> */
    private static array $config = [
        "session_name" => "ROOTS_SESSION",
        "session_lifetime" => 3600, // 1 hour
        "require_auth" => true,
        "redirect_on_no_auth" => "/login",
    ];

    /**
     * Detect if running on Onion service
     */
    private static function isOnionService(): bool
    {
        return (isset($_SERVER['HTTP_HOST']) && preg_match('/\.onion$/i', $_SERVER['HTTP_HOST'])) ||
               (isset($_SERVER['SERVER_NAME']) && preg_match('/\.onion$/i', $_SERVER['SERVER_NAME']));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function setConfig(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? null;
    }

    /**
     * Initialize session with security settings
     * Compatible with Onion services and HTTPS
     */
    public static function initialize(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Set secure session configuration
            ini_set("session.cookie_httponly", "1");
            ini_set("session.use_only_cookies", "1");
            ini_set("session.cookie_samesite", "Lax");
            ini_set("session.use_strict_mode", "1");
            ini_set("session.gc_maxlifetime", "1800");
            ini_set("session.use_trans_sid", "0");

            // Enable HTTPS-only cookies for non-Onion connections
            // For Onion: Tor provides transport security, so secure flag is not needed
            $isOnion = self::isOnionService();
            if (!$isOnion && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
                ini_set("session.cookie_secure", "1");
            }

            session_name(self::$config["session_name"]);
            session_start();

            // Regenerate session ID periodically
            if (!isset($_SESSION["created"])) {
                $_SESSION["created"] = time();
            } elseif (time() - $_SESSION["created"] > 1800) {
                session_regenerate_id(true);
                $_SESSION["created"] = time();
            }

            // Check session expiry
            if (
                isset($_SESSION["last_activity"]) &&
                time() - $_SESSION["last_activity"] >
                    self::$config["session_lifetime"]
            ) {
                self::destroy();
                if (self::$config["require_auth"]) {
                    header(
                        self::HEADER_LOCATION .
                            self::$config["redirect_on_no_auth"],
                    );
                    exit();
                }
            }
            $_SESSION["last_activity"] = time();
        }
    }

    /**
     * Destroy session completely
     */
    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            $sessName = session_name();
            if ($sessName !== false && isset($_COOKIE[$sessName])) {
                $domain = self::isOnionService() ? $_SERVER['HTTP_HOST'] : null;
                setcookie($sessName, "", time() - 3600, "/", $domain, !self::isOnionService(), true);
            }

            session_destroy();
        }
    }

    /**
     * Check if user is authenticated
     */
    public static function checkAuthentication(): bool
    {
        $username = $_SESSION["username"] ?? null;

        if (self::$config["require_auth"] && empty($username)) {
            $_SESSION["intended_url"] = $_SERVER["REQUEST_URI"] ?? "/";
            header(
                self::HEADER_LOCATION . self::$config["redirect_on_no_auth"],
            );
            exit();
        }

        return !empty($username);
    }
}

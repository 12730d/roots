<?php

namespace ROOTS\Services;

use ROOTS\Config\Database;
use ROOTS\ErrorHandling\DatabaseErrorHandler;

/**
 * AuthenticationService - Handles user authentication and data management
 */
class AuthenticationService
{
    private const DEFAULT_AVATAR = "/img/user.jpg";

    /**
     * Get user data from session and database
     *
     * @param \mysqli|null $db
     * @return array<string, mixed>
     */
    public static function getUserData(?\mysqli $db): array
    {
        $username = $_SESSION["username"] ?? null;
        $userData = self::getDefaultUserData($username);

        if (!$username || !$db) {
            return $userData;
        }

        $is_admin = ($_SESSION["subscription"] ?? "") === "admin";
        self::applySessionUserData($userData);
        self::fetchUserDataFromDatabase($db, $username, $userData);

        if ($is_admin) {
            $userData["is_admin"] = true;
        }

        return $userData;
    }

    /**
     * Get default user data structure
     *
     * @return array<string, mixed>
     */
    private static function getDefaultUserData(?string $username): array
    {
        return [
            "username" => $username,
            "is_admin" => false,
            "is_authenticated" => !empty($username),
            "avatar" => self::DEFAULT_AVATAR,
            "display_name" => "Guest",
            "email" => null,
            "id" => null,
            "points" => 0,
            "subscription" => "Basic",
            "expiry_date" => null,
            "role" => "user",
            "permissions" => [],
        ];
    }

    /**
     * Apply session user data to user data array
     *
     * @param array<string, mixed> &$userData
     */
    private static function applySessionUserData(array &$userData): void
    {
        $sessionUser = $_SESSION["user"] ?? null;

        if (empty($sessionUser)) {
            return;
        }

        $userData["is_admin"] = !empty($sessionUser["role"]) && $sessionUser["role"] === "admin";
        $userData["avatar"] = !empty($sessionUser["avatar"]) ? $sessionUser["avatar"] : self::DEFAULT_AVATAR;
        $userData["display_name"] = !empty($sessionUser["display_name"]) ? $sessionUser["display_name"] : $sessionUser["username"] ?? "User";
        $userData["role"] = $sessionUser["role"] ?? "user";
    }

    /**
     * Fetch user data from database
     *
     * @param \mysqli $db
     * @param array<string, mixed> &$userData
     */
    private static function fetchUserDataFromDatabase(\mysqli $db, string $username, array &$userData): void
    {
        try {
            $query = "SELECT
                        id, username, email, display_name, avatar_url, points,
                        subscription, expiry_date, role, wallet_address,
                        last_display_name_change, last_email_change, last_wallet_address_change
                      FROM login WHERE username = ? LIMIT 1";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                error_log("Failed to prepare user query: " . $db->error);
                return;
            }

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result !== false && $row = $result->fetch_assoc()) {
                self::mapDatabaseRowToUserData($row, $userData);
            }

            $stmt->close();
        } catch (\Exception $e) {
            DatabaseErrorHandler::getInstance()->handleDatabaseError(
                "get_user_data",
                $e->getMessage(),
                ["username" => $username],
            );
        }
    }

    /**
     * Map database row to user data array
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> &$userData
     */
    private static function mapDatabaseRowToUserData(array $row, array &$userData): void
    {
        $userData["id"] = $row["id"];
        $userData["username"] = $row["username"];
        $userData["email"] = $row["email"];
        $userData["display_name"] = !empty($row["display_name"]) ? $row["display_name"] : $row["username"];
        $userData["avatar"] = !empty($row["avatar_url"]) ? $row["avatar_url"] : self::DEFAULT_AVATAR;
        $userData["points"] = (int) ($row["points"] ?? 0);
        $userData["subscription"] = $row["subscription"] ?? "Basic";
        $userData["expiry_date"] = $row["expiry_date"];
        $userData["role"] = $row["role"] ?? "user";
        $userData["wallet_address"] = $row["wallet_address"] ?? null;
        $userData["last_display_name_change"] = $row["last_display_name_change"] ?? null;
        $userData["last_email_change"] = $row["last_email_change"] ?? null;
        $userData["last_wallet_address_change"] = $row["last_wallet_address_change"] ?? null;
        $userData["is_admin"] = $row["role"] === "admin" || $row["subscription"] === "admin";
        $userData["created_at"] = null;
        $userData["last_login"] = null;

        // Update session with fresh data
        $_SESSION["user"] = $userData;
        $_SESSION["subscription"] = $userData["subscription"];
    }

    /**
     * Load user permissions
     *
     * @param int|string|null $userId
     * @param \mysqli|null $db
     * @return array<int, string>
     */
    public static function loadUserPermissions(int|string|null $userId, ?\mysqli $db): array
    {
        $permissions = [];

        if (!$userId || !$db) {
            return $permissions;
        }

        try {
            $query = "SELECT permission_name FROM user_permissions WHERE user_id = ?";
            $stmt = $db->prepare($query);

            if (!$stmt) {
                return $permissions;
            }

            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $permissions[] = (string)$row["permission_name"];
                }
            }

            $stmt->close();
        } catch (\Exception $e) {
            DatabaseErrorHandler::getInstance()->handleDatabaseError(
                "load_user_permissions",
                $e->getMessage(),
                ["user_id" => $userId],
            );
        }

        return $permissions;
    }
}

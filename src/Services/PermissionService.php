<?php

namespace ROOTS\Services;

/**
 * PermissionService - Handles permission checks and authorization
 */
class PermissionService
{
    /**
     * Check if user has permission
     *
     * @param array<string, mixed> $user
     */
    public static function hasPermission(string $permission, array $user): bool
    {
        // Admin has all permissions
        if (!empty($user["is_admin"])) {
            return true;
        }

        // Check user permissions
        return in_array($permission, $user["permissions"] ?? []);
    }

    /**
     * Require permission or redirect
     *
     * @param array<string, mixed> $user
     */
    public static function requirePermission(string $permission, array $user, string $redirectUrl = "/"): void
    {
        if (!self::hasPermission($permission, $user)) {
            $_SESSION["error_message"] = "You do not have permission to access this page.";
            header("Location: " . $redirectUrl);
            exit();
        }
    }

    /**
     * Require admin or redirect
     *
     * @param array<string, mixed> $user
     */
    public static function requireAdmin(array $user, string $redirectUrl = "/"): void
    {
        if (empty($user["is_admin"])) {
            $_SESSION["error_message"] = "Admin access required.";
            header("Location: " . $redirectUrl);
            exit();
        }
    }
}

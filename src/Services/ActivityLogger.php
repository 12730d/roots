<?php

namespace ROOTS\Services;

use ROOTS\ErrorHandling\DatabaseErrorHandler;
use ROOTS\Auth\BanSystem;

/**
 * ActivityLogger - Handles user activity logging
 */
class ActivityLogger
{
    /**
     * Log user activity
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $details
     */
    public static function log(
        string $action,
        array $user,
        \mysqli $db,
        array $details = [],
    ): void {
        if (empty($user["id"])) {
            return;
        }

        try {
            $query = "INSERT INTO user_activity_log
                      (user_id, action, details, ip_address, user_agent, created_at)
                      VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                return;
            }

            $detailsJson = json_encode($details);
            $ipAddress = BanSystem::getClientIp();
            $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? null;

            $stmt->bind_param(
                "issss",
                $user["id"],
                $action,
                $detailsJson,
                $ipAddress,
                $userAgent,
            );

            $stmt->execute();
            $stmt->close();
        } catch (\Exception $e) {
            DatabaseErrorHandler::getInstance()->handleDatabaseError(
                "log_activity",
                $e->getMessage(),
                ["action" => $action],
            );
        }
    }
}

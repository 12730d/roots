<?php

namespace ROOTS\Services;

/**
 * FlashMessageService - Handles flash messages for user notifications
 */
class FlashMessageService
{
    /**
     * Set flash message
     */
    public static function set(string $message, string $type = "info"): void
    {
        $_SESSION["flash_message"] = $message;
        $_SESSION["flash_type"] = $type;
    }

    /**
     * Get flash message
     *
     * @return array<string, string>|null
     */
    public static function get(): ?array
    {
        if (isset($_SESSION["flash_message"])) {
            $message = [
                "message" => $_SESSION["flash_message"],
                "type" => $_SESSION["flash_type"] ?? "info",
            ];

            unset($_SESSION["flash_message"]);
            unset($_SESSION["flash_type"]);

            return $message;
        }

        return null;
    }

    /**
     * Display flash message
     */
    public static function display(): void
    {
        $flash = self::get();

        if ($flash) {
            echo '<div class="alert alert-' . htmlspecialchars($flash["type"]) . ' alert-dismissible fade show" role="alert">';
            echo htmlspecialchars($flash["message"]);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo "</div>";
        }
    }

    /**
     * Check if flash message exists
     */
    public static function has(): bool
    {
        return isset($_SESSION["flash_message"]);
    }

    /**
     * Clear flash message
     */
    public static function clear(): void
    {
        unset($_SESSION["flash_message"]);
        unset($_SESSION["flash_type"]);
    }
}

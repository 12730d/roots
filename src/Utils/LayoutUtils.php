<?php

namespace ROOTS\Utils;

class LayoutUtils
{
    /**
     * Utility: Format file size
     */
    public static function formatFileSize(int|float $bytes): string
    {
        if ($bytes === 0) {
            return "0 B";
        }

        $units = ["B", "KB", "MB", "GB", "TB"];
        $i = (int) floor(log($bytes) / log(1024));

        return round($bytes / pow(1024, $i), 2) . " " . $units[$i];
    }

    /**
     * Utility: Time ago format
     */
    public static function timeAgo(int|string $timestamp): string
    {
        $time = is_numeric($timestamp)
            ? (int) $timestamp
            : strtotime($timestamp);
        if ($time === false) {
            $time = time();
        }
        $diff = time() - $time;
        $result = date("M j, Y", $time);

        if ($diff < 60) {
            $result = "Just now";
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            $result = $mins . " minute" . ($mins > 1 ? "s" : "") . " ago";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            $result = $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            $result = $days . " day" . ($days > 1 ? "s" : "") . " ago";
        }

        return $result;
    }

    /**
     * Utility: Sanitize output
     */
    public static function sanitize(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES, "UTF-8");
    }

    /**
     * Utility: Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        return isset($_SESSION["username"]) && !empty($_SESSION["username"]);
    }

    /**
     * Utility: Redirect with message
     */
    public static function redirect(string $url, ?string $message = null, string $type = "info"): never
    {
        if ($message) {
            $_SESSION["flash_message"] = $message;
            $_SESSION["flash_type"] = $type;
        }
        header("Location: " . $url);
        exit();
    }

    /**
     * Utility: Display flash message
     */
    public static function displayFlashMessage(): void
    {
        if (isset($_SESSION["flash_message"])) {
            $message = $_SESSION["flash_message"];
            $type = $_SESSION["flash_type"] ?? "info";

            echo '<div class="alert alert-' .
                htmlspecialchars($type) .
                ' alert-dismissible fade show" role="alert">';
            echo htmlspecialchars($message);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo "</div>";

            unset($_SESSION["flash_message"]);
            unset($_SESSION["flash_type"]);
        }
    }
}

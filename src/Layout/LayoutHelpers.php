<?php
declare(strict_types=1);

namespace ROOTS\Layout;

class LayoutHelpers
{
    /**
     * Normalize base path
     */
    public static function normalizeBasePath(string $base_path): string
    {
        return rtrim($base_path, '/') . '/';
    }

    /**
     * Sanitize string output
     */
    public static function sanitize(?string $string): string
    {
        return $string !== null ? htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    /**
     * HTML escape alias
     */
    public static function h(?string $string): string
    {
        return self::sanitize($string);
    }

    /**
     * Validate asset relative path
     */
    public static function validateAssetRelativePath(string $path): ?string
    {
        $result = null;

        if (!empty($path)) {
            $normalized = str_replace('\\', '/', $path);
            $realPath = realpath(__DIR__ . '/../../' . $normalized);

            if ($realPath !== false) {
                $basePath = realpath(__DIR__ . '/../..');

                if ($basePath !== false && strpos($realPath, $basePath) === 0) {
                    $allowedExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot'];
                    $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

                    if (in_array($extension, $allowedExtensions, true)) {
                        $result = $normalized;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Generate stable cache-busting query string for local assets.
     * Uses filemtime when the file exists; falls back to a process-stable salt.
     */
    public static function assetUrl(string $base_path, string $asset_path): string
    {
        $base_path = self::normalizeBasePath($base_path);
        $safeRelative = self::validateAssetRelativePath($asset_path);
        
        if ($safeRelative === null) {
            return self::sanitize($base_path);
        }

        // __DIR__ is /src/Layout; project public root is two levels up
        $docRoot = realpath(__DIR__ . "/../..");
        $full = $docRoot ? $docRoot . "/" . $safeRelative : null;

        $v = null;
        if ($full && is_file($full)) {
            $mtime = @filemtime($full);
            if ($mtime !== false) {
                $v = (string) $mtime;
            }
        }

        if ($v === null) {
            static $assetVersionSalt = null;
            if ($assetVersionSalt === null) {
                $assetVersionSalt = (string) time();
            }
            $v = $assetVersionSalt;
        }

        $url = $base_path . $safeRelative;
        $sep = str_contains($url, "?") ? "&" : "?";
        return $url . $sep . "v=" . rawurlencode($v);
    }

    /**
     * Format file size for display
     */
    public static function formatFileSize(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max(0, (float)$bytes);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[(int)$pow];
    }

    /**
     * Format timestamp as time ago
     */
    public static function timeAgo(int|string $timestamp): string
    {
        if (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
        }

        $now = time();
        $diff = $now - $timestamp;

        if ($diff < 60) {
            return 'just now';
        }

        $intervals = [
            1 => ['year', 31536000],
            2 => ['month', 2592000],
            3 => ['week', 604800],
            4 => ['day', 86400],
            5 => ['hour', 3600],
            6 => ['minute', 60],
        ];

        foreach ($intervals as $interval) {
            $seconds = $interval[1];
            $name = $interval[0];

            if ($diff >= $seconds) {
                $count = floor($diff / $seconds);
                return $count . ' ' . $name . ($count > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

    /**
     * Sanitize redirect URL to prevent open redirect vulnerabilities
     */
    public static function sanitizeRedirectUrl(string $url): string
    {
        $result = '/';
        $parsed = parse_url($url);

        if ($parsed !== false) {
            $schemeValid = !isset($parsed['scheme']) || in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
            $hostValid = !isset($parsed['host']) || $parsed['host'] === $_SERVER['HTTP_HOST'];

            if ($schemeValid && $hostValid) {
                $result = $url;
            }
        }

        return $result;
    }

    /**
     * Convert string to JavaScript string literal
     */
    public static function jsString(string $str): string
    {
        $encoded = json_encode($str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $encoded !== false ? $encoded : '';
    }

    /**
     * Send an HTTP header only when still possible
     */
    public static function safeHeader(string $header): void
    {
        if (!headers_sent()) {
            header($header);
        }
    }

    /**
     * Get base path for JavaScript
     */
    public static function basePathForJs(string $base_path): string
    {
        return $base_path === '' ? '/' : rtrim($base_path, '/') . '/';
    }

    /**
     * Process avatar URL with proper validation and security checks
     */
    public static function processAvatarUrl(
        ?string $avatarUrlRaw,
        string $base_path,
        string $default_avatar,
    ): string {
        $avatarUrlRaw = trim($avatarUrlRaw ?? "");
        $result = $default_avatar;

        if ($avatarUrlRaw !== "") {
            if (str_starts_with($avatarUrlRaw, "data:image/")) {
                $result = $avatarUrlRaw;
            } elseif (filter_var($avatarUrlRaw, FILTER_VALIDATE_URL)) {
                $parsed = parse_url($avatarUrlRaw);
                $scheme = strtolower($parsed["scheme"] ?? "");
                if (in_array($scheme, ["http", "https"], true)) {
                    $result = $avatarUrlRaw;
                }
            } elseif (str_starts_with($avatarUrlRaw, "/") && !str_starts_with($avatarUrlRaw, "//")) {
                $result = $avatarUrlRaw;
            } else {
                // Relative path: verify existence and prevent path traversal
                $clean_path = ltrim($avatarUrlRaw, "/");
                if (strpos($clean_path, "..") === false) {
                    $full_path = $base_path . $clean_path;
                    // __DIR__ is /src/Layout; project root is two levels up
                    if (file_exists(__DIR__ . "/../../" . $clean_path)) {
                        $result = $full_path;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Render LayoutHelpers utility CSS styles
     */
    public static function renderUtilityStyles(): void
    {
        ?>
        <style>
        /* CSS Custom Properties - Professional System */
        :root {
            /* Brand Colors — terminal green */
            --bc-primary: #0D1B0D;
            --bc-secondary: #1A2F1A;
            --bc-tertiary: #243524;
            --bc-accent: #2ECC71;
            --bc-accent-hover: #27AE60;
            --bc-accent-light: rgba(46, 204, 113, 0.25);
            --bc-accent-glow: rgba(46, 204, 113, 0.18);
            --bc-text: #E8F5E8;
            --bc-text-muted: #A0BFA0;
            --bc-border: rgba(255, 255, 255, 0.08);
            --bc-border-accent: rgba(46, 204, 113, 0.15);
            --bc-overlay: rgba(0, 0, 0, 0.65);

            /* Glassmorphism */
            --bc-nav-bg: rgba(10, 20, 10, 0.65);
            --bc-nav-blur: 12px;
            --bc-nav-bg-scroll: rgba(8, 17, 8, 0.75);

            /* Dimensions */
            --bc-height-mobile: 36px;
            --bc-height-desktop: 44px;

            /* Spacing System */
            --bc-spacing-xs: 4px;
            --bc-spacing-sm: 8px;
            --bc-spacing-md: 12px;
            --bc-spacing-lg: 16px;

            /* Border Radius */
            --bc-radius-sm: 4px;
            --bc-radius-md: 6px;
            --bc-radius-lg: 10px;
            --bc-radius-full: 9999px;

            /* Shadows */
            --bc-shadow-sm: 0 1px 3px rgba(0,0,0,.2);
            --bc-shadow-md: 0 4px 14px rgba(0,0,0,.3);
            --bc-shadow-lg: 0 8px 28px rgba(0,0,0,.4);

            /* Transitions */
            --bc-ease-out: cubic-bezier(0.16, 1, 0.3, 1);
            --bc-transition-fast: 140ms ease-in-out;
            --bc-transition-base: 220ms ease-in-out;
            --bc-transition-slow: 320ms cubic-bezier(0.16, 1, 0.3, 1);

            /* Z-index layers */
            --bc-z-overlay: 1105;
            --bc-z-menu: 1110;
            --bc-z-dropdown: 1120;
            --bc-z-sticky: 1100;
        }

        /* Core Reset for Navigation */
        .bc-nav, .bc-nav * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* Global Utility Classes */
        .bc-text-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .bc-flex-center {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .bc-flex-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .bc-glass-effect {
            background: rgba(10, 20, 10, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .bc-glow-effect {
            box-shadow: 0 0 20px rgba(46, 204, 113, 0.3);
        }

        /* Responsive Utilities */
        @media (max-width: 768px) {
            :root {
                --bc-height-mobile: 42px;
                --bc-height-desktop: 48px;
            }
        }

        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                transition-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
            }
        }
        </style>
        <?php
    }
}

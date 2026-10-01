<?php

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = "ROOTS\\";

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . "/src/";

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace("\\", "/", $relative_class) . ".php";

    // If the file exists, require it
    if (file_exists($file)) {
        require_once $file;
    }
});

// Autoloader for DatabaseManager (legacy location)
spl_autoload_register(function ($class) {
    if ($class === "ROOTS\\Database\\DatabaseManager") {
        $file = __DIR__ . "/db_config.php";
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Autoloader for ROOTS\Database namespace (db_config.php)
spl_autoload_register(function ($class) {
    $prefix = "ROOTS\\Database\\";
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = __DIR__ . "/db_config.php";
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Autoloader for Securimage and Securimage_Color
spl_autoload_register(function ($class) {
    if ($class === "Securimage" || $class === "Securimage_Color") {
        $file = __DIR__ . "/securimage/securimage.php";
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

/**
 * Centralized application logging helpers.
 * Uses syslog when available to avoid polluting Nginx error stream (stderr).
 */
function appLogInfo(string $message): void
{
    if (function_exists("openlog") && function_exists("syslog")) {
        openlog("ROOTS", LOG_PID, LOG_USER);
        syslog(LOG_INFO, $message);
        closelog();
    } else {
        error_log($message);
    }
}

function appLogError(string $message): void
{
    if (function_exists("openlog") && function_exists("syslog")) {
        openlog("ROOTS", LOG_PID, LOG_USER);
        syslog(LOG_ERR, $message);
        closelog();
    } else {
        error_log($message);
    }
}

/**
 * Calculate base path relative to project root
 * Returns the relative path needed to reach the root from current script location
 *
 * @return string Base path (e.g., '' for root, '../' for one level deep, '../../' for two levels, etc.)
 */
function calculateBasePath()
{
    // Get the script path relative to document root
    $scriptPath = $_SERVER["SCRIPT_NAME"];

    // Remove leading slash and get path parts
    $scriptPath = ltrim($scriptPath, "/");
    $parts = explode("/", $scriptPath);

    // Count depth (number of directories)
    // If we're in /index.php, parts is ['index.php'], count is 1, depth is 0
    // If we're in /sub/file.php, parts is ['sub', 'file.php'], count is 2, depth is 1
    $depth = count($parts) - 1;

    // Return appropriate base path
    if ($depth <= 0) {
        return "";
    }

    return str_repeat("../", $depth);
}

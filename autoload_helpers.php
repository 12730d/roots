<?php
/**
 * Helper Functions for ROOTS Application
 *
 * This file is automatically loaded by Composer autoloader.
 * Contains utility functions used across the application.
 *
 * @package ROOTS
 */

/**
 * Log informational message
 *
 * @param string $message Message to log
 * @return void
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

/**
 * Log error message
 *
 * @param string $message Error message to log
 * @return void
 */
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
 *
 * Returns the relative path needed to reach the root from current script location.
 * Useful for building URLs and file paths in nested directories.
 *
 * @return string Base path (e.g., '' for root, '../' for one level deep, '../../' for two levels, etc.)
 *
 * @example
 * // In /index.php
 * calculateBasePath(); // returns ''
 *
 * // In /dir/page.php
 * calculateBasePath(); // returns '../'
 *
 * // In /dir/sub/deep.php
 * calculateBasePath(); // returns '../../'
 */
function calculateBasePath(): string
{
    // Get the script path relative to document root
    $scriptPath = $_SERVER["SCRIPT_NAME"] ?? "";

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

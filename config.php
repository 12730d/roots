<?php

// Load autoloader if not already loaded (though it should be by entry point, but config.php is included early)
if (!class_exists("ROOTS\Config\EnvLoader")) {
    require_once __DIR__ . "/vendor/autoload.php";
}

use ROOTS\Config\EnvLoader;

// Load environment variables first
EnvLoader::load();

// Database configuration from environment
// These variables are often used globally in legacy parts, so we might need to keep them or suggest removal.
// However, the Database class is what we should rely on.
// For now, let's keep the variables populated from $_ENV as the original code did, to avoid breaking other files that might rely on $host global.
$host = $_ENV["DB_HOST"] ?? (getenv("DB_HOST") ?: "localhost");
$db = $_ENV["DB_NAME"] ?? (getenv("DB_NAME") ?: "users_app");
$user = $_ENV["DB_USER"] ?? (getenv("DB_USER") ?: "root");
$pass = $_ENV["DB_PASS"] ?? (getenv("DB_PASS") ?: "");

// Enable error reporting in development, disable in production
error_reporting(getenv("ENVIRONMENT") === "production" ? 0 : E_ALL);
ini_set("display_errors", getenv("ENVIRONMENT") === "production" ? "0" : "1");

// Application settings
define("MAX_COMMENTS_PER_PAGE", 50);
define("SITE_NAME", "Comment System");

// Privacy default settings
define("NAME_PRIVACY_DEFAULT", "partial"); // Options: 'none', 'partial', 'full'
define("CENSOR_COMMENTS_DEFAULT", true);

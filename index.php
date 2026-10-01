
<?php

/**
 * ENTRY POINT - ROOTS APPLICATION
 *
 * This file serves as the main entry point for the application.
 * It handles the initial request and redirects the user to the appropriate page.
 *
 * Secure redirection with HTTP/1.1 301 Moved Permanently.
 */

if (!headers_sent()) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: dashboard");
    exit();
}

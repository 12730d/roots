<?php

namespace ROOTS\Layout;

/**
 * Navbar - Entry point for rendering the main application navigation bar
 * Proxies the call to MasterLayout::renderNavbar for centralized control
 */
class Navbar
{
    /**
     * Render the main navbar
     * @param string $base_path Base path for the application
     */
    public static function render(string $base_path = ""): void
    {
        MasterLayout::createDefault()->renderNavbar($base_path);
    }
}

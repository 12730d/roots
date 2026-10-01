<?php

/**
 * Standalone Navigation Bar
 * Separated and independent from page content
 * Can be included from any page without affecting page structure
 */

use ROOTS\Layout\MasterLayout;

// Default base path if not provided
$navbar_base_path = $navbar_base_path ?? '../';

// Render navbar
$layout = MasterLayout::createDefault();
$layout->renderNavbar($navbar_base_path);
?>

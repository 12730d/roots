<?php
/**
 * Application Logo Component
 * 
 * This file contains the function to render the dynamic SVG logo.
 */

/**
 * Renders the application logo as an inline SVG.
 *
 * @param string|int $size The width and height of the logo (default: 100).
 * @param string $class Additional CSS classes to apply to the SVG.
 * @param string $fillColor The color of the inner shapes (default: white).
 * @param string $bgColor The background color of the circle (default: black).
 * @return string The SVG HTML string.
 */
function render_logo($size = 100, $class = '', $fillColor = 'white', $bgColor = 'black') {
    $classAttr = !empty($class) ? ' class="' . htmlspecialchars($class) . '"' : '';
    
    // Ensure size has a unit if it's purely numeric
    $width = is_numeric($size) ? $size . 'px' : $size;
    $height = is_numeric($size) ? $size . 'px' : $size;

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="' . htmlspecialchars($width) . '" height="' . htmlspecialchars($height) . '"' . $classAttr . ' style="display: inline-block;">
  <circle cx="100" cy="100" r="100" fill="' . htmlspecialchars($bgColor) . '" />
  <path d="M 30,45 C 50,10 85,25 100,65 C 115,105 128,110 128,140" fill="none" stroke="' . htmlspecialchars($fillColor) . '" stroke-width="24" stroke-linecap="butt" />
  <path d="M 170,45 C 150,10 115,25 100,65 C 85,105 72,110 72,140" fill="none" stroke="' . htmlspecialchars($fillColor) . '" stroke-width="24" stroke-linecap="butt" />
  <circle cx="72" cy="165" r="12" fill="' . htmlspecialchars($fillColor) . '" />
  <circle cx="128" cy="165" r="12" fill="' . htmlspecialchars($fillColor) . '" />
</svg>';
}

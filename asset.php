<?php
declare(strict_types=1);

/**
 * asset.php
 * Serve static assets through PHP as a fallback when the web server
 * routes static requests to the application (e.g., missing try_files).
 *
 * Usage: /asset.php?f=lib/tempusdominus/js/moment.min.js
 */

const CT_TEXT_PLAIN = 'Content-Type: text/plain; charset=UTF-8';

$file = $_GET['f'] ?? '';
if (!is_string($file) || $file === '') {
    http_response_code(400);
    header(CT_TEXT_PLAIN);
    echo "Bad Request";
    exit;
}

// Normalize and prevent traversal
$file = str_replace(["\0", "\\\\"], ['', '/'], $file);
$file = ltrim($file, '/');
if (str_contains($file, '..')) {
    http_response_code(400);
    header(CT_TEXT_PLAIN);
    echo "Invalid path";
    exit;
}

// Allowlist top-level directories
$allowedPrefixes = ['lib/', 'js/', 'css/'];
$allowed = false;
foreach ($allowedPrefixes as $p) {
    if (str_starts_with($file, $p)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    header(CT_TEXT_PLAIN);
    echo "Forbidden";
    exit;
}

$root = realpath(__DIR__);
$full = $root ? realpath($root . '/' . $file) : false;
if ($full === false || $root === false || !str_starts_with($full, $root . '/')) {
    http_response_code(404);
    header(CT_TEXT_PLAIN);
    echo "Not Found";
    exit;
}

if (!is_file($full) || !is_readable($full)) {
    http_response_code(404);
    header(CT_TEXT_PLAIN);
    echo "Not Found";
    exit;
}

// Basic content type mapping
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$contentType = match ($ext) {
    'js' => 'application/javascript; charset=UTF-8',
    'css' => 'text/css; charset=UTF-8',
    'map' => 'application/json; charset=UTF-8',
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml; charset=UTF-8',
    default => 'application/octet-stream',
};

$mtime = filemtime($full) ?: time();
$etag = '"' . sha1($full . '|' . $mtime . '|' . filesize($full)) . '"';

header('Content-Type: ' . $contentType);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

// Conditional GET
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if (is_string($ifNoneMatch) && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

// Stream file
readfile($full);

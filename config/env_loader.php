<?php

/**
 * Simple .env file loader
 * Place at the very beginning of your application entry point
 */

function findEnvFile(?string $filepath = null): string|false {
    if ($filepath !== null) {
        return file_exists($filepath) ? $filepath : false;
    }

    $possible_paths = [
        __DIR__ . '/../.env',
        __DIR__ . '/.env',
        $_SERVER['DOCUMENT_ROOT'] . '/.env',
    ];

    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return false;
}

function isCommentLine(string $line): bool {
    return strpos(trim($line), '#') === 0;
}

function hasValidFormat(string $line): bool {
    return strpos($line, '=') !== false;
}

function removeQuotes(string $value): string {
    if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
        (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
        return substr($value, 1, -1);
    }
    return $value;
}

function setEnvVariable(string $key, string $value): void {
    if (!isset($_ENV[$key])) {
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function parseEnvLine(string $line): void {
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = removeQuotes(trim($value));
    setEnvVariable($key, $value);
}

function loadEnvFile(?string $filepath = null): bool {
    $filepath = findEnvFile($filepath);

    if (!$filepath) {
        return false;
    }

    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        if (isCommentLine($line)) {
            continue;
        }

        if (hasValidFormat($line)) {
            parseEnvLine($line);
        }
    }

    return true;
}

// Auto-load .env file on require
if (!isset($_ENV['DB_LOADED'])) {
    loadEnvFile();
    $_ENV['DB_LOADED'] = true;
}


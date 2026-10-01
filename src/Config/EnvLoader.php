<?php

namespace ROOTS\Config;

class EnvLoader
{
    public static function load(?string $filepath = null): bool
    {
        $success = false;

        if (isset($_ENV['DB_LOADED'])) {
            return true;
        }

        $filepath = self::findEnvFile($filepath);

        if (!$filepath) {
            return false;
        }

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines !== false) {
            foreach ($lines as $line) {
                if (self::isCommentLine($line)) {
                    continue;
                }

                if (self::hasValidFormat($line)) {
                    self::parseEnvLine($line);
                }
            }

            $_ENV['DB_LOADED'] = true;
            $success = true;
        }

        return $success;
    }

    private static function findEnvFile(?string $filepath = null): string|false
    {
        if ($filepath !== null) {
            return file_exists($filepath) ? $filepath : false;
        }

        $possible_paths = [
            __DIR__ . '/../../.env',
            __DIR__ . '/../.env',
            $_SERVER['DOCUMENT_ROOT'] . '/.env',
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }

    private static function isCommentLine(string $line): bool
    {
        return strpos(trim($line), '#') === 0;
    }

    private static function hasValidFormat(string $line): bool
    {
        return strpos($line, '=') !== false;
    }

    private static function removeQuotes(string $value): string
    {
        if (
            (strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
            (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)
        ) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    private static function setEnvVariable(string $key, string $value): void
    {
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    private static function parseEnvLine(string $line): void
    {
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = self::removeQuotes(trim($value));
        self::setEnvVariable($key, $value);
    }
}

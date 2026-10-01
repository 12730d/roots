<?php

namespace ROOTS\ErrorHandling;

class DatabaseErrorHandler
{
    private static ?self $instance = null;
    private string $logDir;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->logDir = __DIR__ . "/../../logs";
        $this->ensureLogDirectory();
    }

    /**
     * Handles database errors by logging them.
     *
     * @param string $operation The operation being performed.
     * @param string $error The error message.
     * @param array<string, mixed> $context Additional context.
     * @return void
     */
    public function handleDatabaseError(string $operation, string $error, array $context = []): void
    {
        $userId = null;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION["username"])) {
            $userId = $_SESSION["username"];
        }

        $errorData = [
            "timestamp" => date("Y-m-d H:i:s"),
            "operation" => $operation,
            "error" => $error,
            "context" => $context,
            "request_id" => $this->getRequestId(),
            "user_id" => $userId,
            "url" => $_SERVER["REQUEST_URI"] ?? null,
            "method" => $_SERVER["REQUEST_METHOD"] ?? null,
        ];

        $this->logError($errorData);
        // In a real scenario, we might want to return a formatted error or throw an exception
        // depending on the context. For now, we handle it silently for the user.
    }

    private function getRequestId(): string
    {
        return $_SERVER["HTTP_X_REQUEST_ID"] ?? uniqid("req_", true);
    }

    private function ensureLogDirectory(): void
    {
        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0755, true)) {
            // Fallback to temporary directory if logs directory cannot be created
            $this->logDir = sys_get_temp_dir() . "/roots_logs";
            if (!is_dir($this->logDir)) {
                @mkdir($this->logDir, 0755, true);
            }
        }

        // Protect log directory
        if (is_dir($this->logDir)) {
            $htaccess = $this->logDir . "/.htaccess";
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
        }
    }

    /**
     * @param array<string, mixed> $errorData
     */
    private function logError(array $errorData): void
    {
        $logFile = $this->logDir . "/database_errors.log";
        $logEntry = (string)json_encode($errorData) . "\n";

        // Rotate log if too large (>10MB)
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
            $backupFile =
                $this->logDir .
                "/database_errors_" .
                date("Y-m-d_H-i-s") .
                ".log";
            rename($logFile, $backupFile);
        }

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

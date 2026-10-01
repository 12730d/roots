<?php

namespace ROOTS\Database;

use ROOTS\ErrorHandling\DatabaseErrorHandler;

class GracefulDegradation
{

    private DatabaseErrorHandler $logger;

    public function __construct()
    {
        $this->logger = DatabaseErrorHandler::getInstance();
    }

    /**
     * Execute a callback with fallback logic
     *
     * @param callable $operation The primary operation to execute
     * @param mixed $fallbackValue The value to return if operation fails
     * @param string $contextDescription Description for logging
     * @return mixed
     */
    public function executeSafe(callable $operation, $fallbackValue, string $contextDescription)
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            $this->logger->handleDatabaseError($contextDescription, $e->getMessage());
            return $fallbackValue;
        } catch (\Throwable $t) {
            $this->logger->handleDatabaseError($contextDescription, $t->getMessage());
            return $fallbackValue;
        }
    }

    /**
     * Attempt to run a list of operations in sequence until one succeeds.
     * @param array<int, callable> $operations
     * @param mixed $fallbackValue
     * @return mixed
     */
    public function executeCascade(array $operations, $fallbackValue, string $contextDescription)
    {
        foreach ($operations as $index => $operation) {
            try {
                return $operation();
            } catch (\Exception $e) {
                // Log only if it's the last attempt or if debug is on
                if ($index === count($operations) - 1) {
                    $this->logger->handleDatabaseError($contextDescription . "_cascade_final_failure", $e->getMessage());
                }
                continue;
            }
        }
        return $fallbackValue;
    }
}

<?php

namespace ROOTS\Exceptions;

use RuntimeException;

/**
 * Exception thrown when database operations fail.
 * This includes connection errors, query preparation failures, and execution errors.
 */
class DatabaseException extends RuntimeException
{
    /**
     * Create a new DatabaseException for query preparation failures.
     *
     * @param string $error The database error message
     * @return self
     */
    public static function prepareFailed(string $error): self
    {
        return new self("Database query preparation failed: {$error}");
    }

    /**
     * Create a new DatabaseException for query execution failures.
     *
     * @param string $error The database error message
     * @return self
     */
    public static function executeFailed(string $error): self
    {
        return new self("Database query execution failed: {$error}");
    }

    /**
     * Create a new DatabaseException for connection failures.
     *
     * @param string $error The database error message
     * @return self
     */
    public static function connectionFailed(string $error): self
    {
        return new self("Database connection failed: {$error}");
    }
}

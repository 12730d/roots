<?php

namespace ROOTS\ErrorHandling;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = "Success",
        int $statusCode = 200,
    ): never {
        http_response_code($statusCode);
        echo json_encode([
            "status" => "success",
            "message" => $message,
            "data" => $data,
            "timestamp" => date("c"),
        ]);
        exit();
    }

    public static function error(
        string $message = "An error occurred",
        int $statusCode = 500,
        string|null $code = null,
        mixed $details = null,
    ): never {
        http_response_code($statusCode);
        $response = [
            "status" => "error",
            "message" => $message,
            "timestamp" => date("c"),
        ];

        if ($code) {
            $response["code"] = $code;
        }

        // Only show detailed errors if debug mode is enabled
        if (($details || $code) && ($_ENV["APP_DEBUG"] ?? false) === "true") {
            $response["details"] = $details;
        }

        echo json_encode($response);
        exit();
    }

    public static function notFound(string $message = "Resource not found"): never
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = "Unauthorized access"): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = "Forbidden access"): never
    {
        self::error($message, 403);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function validationError(
        array $errors,
        string $message = "Validation failed",
    ): never {
        self::error($message, 422, "VALIDATION_ERROR", $errors);
    }
}

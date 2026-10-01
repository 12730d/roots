<?php

namespace ROOTS\Services;

/**
 * HttpUtilityService - Handles HTTP utilities and responses
 */
class HttpUtilityService
{
    private const HEADER_LOCATION = "Location: ";

    /**
     * Redirect with message
     */
    public static function redirect(string $url, ?string $message = null, string $type = "info"): void
    {
        if ($message) {
            FlashMessageService::set($message, $type);
        }

        header(self::HEADER_LOCATION . $url);
        exit();
    }

    /**
     * Redirect back with message
     */
    public static function redirectBack(?string $message = null, string $type = "info", string $default = "/"): void
    {
        $referer = $_SERVER["HTTP_REFERER"] ?? $default;
        self::redirect($referer, $message, $type);
    }

    /**
     * Get current URL
     */
    public static function getCurrentUrl(): string
    {
        $protocol = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        $uri = $_SERVER["REQUEST_URI"] ?? "/";

        return $protocol . "://" . $host . $uri;
    }

    /**
     * Get base URL
     */
    public static function getBaseUrl(): string
    {
        $protocol = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";

        return $protocol . "://" . $host;
    }

    /**
     * Validate request method
     */
    public static function validateRequestMethod(string $method): void
    {
        if ($_SERVER["REQUEST_METHOD"] !== strtoupper($method)) {
            http_response_code(405);
            die("Method Not Allowed");
        }
    }

    /**
     * Get request data
     *
     * @return array<string, mixed>
     */
    public static function getRequestData(): array
    {
        $method = $_SERVER["REQUEST_METHOD"];
        $data = [];

        switch ($method) {
            case "GET":
                $data = $_GET;
                break;

            case "POST":
                $data = $_POST;
                break;

            case "PUT":
            case "DELETE":
            case "PATCH":
                $input = file_get_contents("php://input");
                if ($input !== false) {
                    parse_str($input, $data);
                }
                break;

            default:
                $data = [];
                break;
        }

        return $data;
    }

    /**
     * Send JSON response
     *
     * @param mixed $data
     * @param array<string, string> $headers
     */
    public static function jsonResponse(mixed $data, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);

        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        echo json_encode($data);
        exit();
    }

    /**
     * Send error response
     *
     * @param array<int, string> $errors
     */
    public static function errorResponse(string $message, int $statusCode = 400, array $errors = []): void
    {
        self::jsonResponse(
            [
                "success" => false,
                "message" => $message,
                "errors" => $errors,
            ],
            $statusCode,
        );
    }

    /**
     * Send success response
     *
     * @param mixed $data
     */
    public static function successResponse(mixed $data = [], string $message = "Success", int $statusCode = 200): void
    {
        self::jsonResponse(
            [
                "success" => true,
                "message" => $message,
                "data" => $data,
            ],
            $statusCode,
        );
    }
}

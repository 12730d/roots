<?php


namespace ROOTS\PostMySite\Utils;

class Response {

    /** @param array<string, mixed> $data */
    public static function success(string $message, array $data = []): never
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    public static function error(string $message, int $code = 400): never
    {
        http_response_code($code);
        self::json([
            'success' => false,
            'error' => $message
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function redirect(string $url, ?string $message = null): never
    {
        if ($message) {
            $_SESSION['flash_message'] = $message;
        }
        header('Location: ' . $url);
        exit;
    }
}

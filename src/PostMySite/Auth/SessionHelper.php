<?php


namespace ROOTS\PostMySite\Auth;

class SessionHelper {

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', 0);
            session_start();
        }
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION['user_id']) && isset($_SESSION['username']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
    }

    public static function isAdmin(): bool
    {
        self::start();
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: /index.php');
            exit;
        }
    }

    public static function getCurrentUserId(): ?int
    {
        self::start();
        return $_SESSION['user_id'] ?? null;
    }

    public static function getCurrentUsername(): ?string
    {
        self::start();
        return $_SESSION['username'] ?? null;
    }

    /** @param array<string, mixed> $user */
    public static function setUserSession(array $user): void
    {
        self::start();
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'] ?? '';
        $_SESSION['is_admin'] = $user['is_admin'] ?? false;
        $_SESSION['balance'] = $user['balance'] ?? 0;
    }

    public static function clearUserSession(): void
    {
        self::start();
        session_unset();
        session_destroy();
    }

    public static function updateSessionBalance(int|float $newBalance): void
    {
        self::start();
        $_SESSION['balance'] = $newBalance;
    }
}

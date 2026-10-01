<?php

namespace ROOTS\Controllers;

use ROOTS\Services\PermissionService;
use ROOTS\Services\FlashMessageService;
use ROOTS\Services\HttpUtilityService;
use ROOTS\Services\ActivityLogger;
use ROOTS\Services\SessionManager;

/**
 * PageControllerFacade - Provides convenient access to common page controller operations
 *
 * This facade class delegates to various service classes to provide a clean API
 * for common operations used throughout the application.
 */
class PageControllerFacade
{
    public static function destroySession(): void
    {
        SessionManager::destroy();
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function hasPermission(string $permission, array $user): bool
    {
        return PermissionService::hasPermission($permission, $user);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requirePermission(string $permission, array $user, string $redirectUrl = "/"): void
    {
        PermissionService::requirePermission($permission, $user, $redirectUrl);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requireAdmin(array $user, string $redirectUrl = "/"): void
    {
        PermissionService::requireAdmin($user, $redirectUrl);
    }

    public static function setFlashMessage(string $message, string $type = "info"): void
    {
        FlashMessageService::set($message, $type);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getFlashMessage(): ?array
    {
        return FlashMessageService::get();
    }

    public static function displayFlashMessage(): void
    {
        FlashMessageService::display();
    }

    public static function redirect(string $url, ?string $message = null, string $type = "info"): void
    {
        HttpUtilityService::redirect($url, $message, $type);
    }

    public static function redirectBack(?string $message = null, string $type = "info", string $default = "/"): void
    {
        HttpUtilityService::redirectBack($message, $type, $default);
    }

    public static function getCurrentUrl(): string
    {
        return HttpUtilityService::getCurrentUrl();
    }

    public static function getBaseUrl(): string
    {
        return HttpUtilityService::getBaseUrl();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $details
     */
    public static function logActivity(string $action, array $user, \mysqli $db, array $details = []): void
    {
        ActivityLogger::log($action, $user, $db, $details);
    }

    public static function validateRequestMethod(string $method): void
    {
        HttpUtilityService::validateRequestMethod($method);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getRequestData(): array
    {
        return HttpUtilityService::getRequestData();
    }

    /**
     * @param array<string, string> $headers
     */
    public static function jsonResponse(mixed $data, int $statusCode = 200, array $headers = []): void
    {
        HttpUtilityService::jsonResponse($data, $statusCode, $headers);
    }

    /**
     * @param array<int, string> $errors
     */
    public static function errorResponse(string $message, int $statusCode = 400, array $errors = []): void
    {
        HttpUtilityService::errorResponse($message, $statusCode, $errors);
    }

    public static function successResponse(mixed $data = [], string $message = "Success", int $statusCode = 200): void
    {
        HttpUtilityService::successResponse($data, $message, $statusCode);
    }
}

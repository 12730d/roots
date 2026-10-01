<?php

declare(strict_types=1);

namespace ROOTS\Routing;

/**
 * Dispatcher - Dynamically resolves and executes the target for a given route.
 *
 * This class provides a bridge between the modern Namespace-based Controller system
 * and the legacy script-based file system.
 */
class Dispatcher
{
    /**
     * Executes the target for the given route.
     *
     * @param string $safePath The normalized path from the router
     * @param string $targetFile The physical file path on disk
     * @param array<string, mixed> $context Global variables to be made available to the target (for legacy scripts)
     */
    public static function dispatch(string $safePath, string $targetFile, array $context = []): void
    {
        // 1. Try to resolve to a Controller Class
        // Convention: ROOTS\Controllers\{SafePath}Controller
        $controllerClass = 'ROOTS\\Controllers\\' . str_replace('/', '\\', ucwords($safePath, '/')) . 'Controller';

        if (class_exists($controllerClass)) {
            $controller = new $controllerClass();
            if (method_exists($controller, 'index')) {
                $controller->index();
                return;
            }
        }

        // 2. Fallback to legacy include_once for script-based pages
        if (file_exists($targetFile)) {
            // Extract context variables to the local scope of the include
            extract($context);
            include_once $targetFile; // NOSONAR - Legacy bridge for script-based pages
        }
    }
}

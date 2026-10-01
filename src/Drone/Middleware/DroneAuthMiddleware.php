<?php

declare(strict_types=1);

namespace ROOTS\Drone\Middleware;

use ROOTS\Drone\Services\DroneAuthService;
use Exception;

/**
 * DroneAuthMiddleware - Handles drone authentication for API requests
 *
 * This middleware validates drone API credentials and enforces rate limiting
 */
class DroneAuthMiddleware
{
    private DroneAuthService $authService;

    public function __construct()
    {
        $this->authService = new DroneAuthService();
    }

    /**
     * Authenticate drone from request headers
     *
     * @return array<string, mixed> Authentication result
     */
    public function authenticate(): array
    {
        $result = [
            'success' => false,
            'error' => 'Authentication error',
            'code' => 500
        ];

        try {
            // Get API credentials from headers
            $apiKey = $this->getHeader('X-API-Key');
            $apiSecret = $this->getHeader('X-API-Secret');

            if (empty($apiKey) || empty($apiSecret)) {
                $result = [
                    'success' => false,
                    'error' => 'Missing API credentials',
                    'code' => 401
                ];
            } else {
                // Authenticate drone
                $authResult = $this->authService->authenticateDrone($apiKey, $apiSecret);

                if (!$authResult['success']) {
                    $result = [
                        'success' => false,
                        'error' => $authResult['error'],
                        'code' => 401
                    ];
                } else {
                    // Validate rate limits
                    $rateLimitResult = $this->authService->validateDroneAccess($authResult['drone_id'], 'minute');

                    if (!$rateLimitResult['success']) {
                        $result = [
                            'success' => false,
                            'error' => 'Rate limit exceeded',
                            'code' => 429,
                            'rate_limit_info' => $rateLimitResult
                        ];
                    } else {
                        $result = [
                            'success' => true,
                            'drone_id' => $authResult['drone_id'],
                            'user_id' => $authResult['user_id'],
                            'subscription_tier' => $authResult['subscription_tier'],
                            'rate_limit_remaining' => $rateLimitResult['remaining']
                        ];
                    }
                }
            }

        } catch (Exception $e) {
            $result = [
                'success' => false,
                'error' => 'Authentication error: ' . $e->getMessage(),
                'code' => 500
            ];
        }

        return $result;
    }

    /**
     * Get header value
     *
     * @param string $headerName Header name
     * @return string Header value
     */
    private function getHeader(string $headerName): string
    {
        $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return $_SERVER[$headerName] ?? '';
    }

    /**
     * Send unauthorized response
     *
     * @param string $message Error message
     */
    public static function sendUnauthorizedResponse(string $message = 'Unauthorized'): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
        exit();
    }

    /**
     * Send rate limit exceeded response
     *
     * @param array<string, mixed> $rateLimitInfo Rate limit information
     */
    public static function sendRateLimitResponse(array $rateLimitInfo): void
    {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded',
            'rate_limit_info' => $rateLimitInfo,
            'timestamp' => date('c')
        ]);
        exit();
    }
}

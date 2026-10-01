<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use Exception;


/**
 * DroneAuthenticator - Handles drone authentication and registration
 */
class DroneAuthenticator extends AbstractDroneService
{
    /**
     * Register a new drone
     *
     * @param int $userId User ID from login table
     * @param string $droneId Unique drone identifier
     * @param string $droneName Human-readable drone name
     * @param string $droneType Type of drone
     * @param array<string, mixed> $cameraSpecs Camera specifications
     * @return array<string, mixed> Registration result with API keys
     */
    public function registerDrone(
        int $userId,
        string $droneId,
        string $droneName,
        string $droneType,
        array $cameraSpecs = []
    ): array {
        try {
            // Check if drone ID already exists
            $stmt = $this->db->prepare("SELECT id FROM drone_registrations WHERE drone_id = ?");
            if ($stmt) {
                $stmt->bind_param("s", $droneId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result !== false && $result->num_rows > 0) {
                    return [
                        'success' => false,
                        'error' => 'Drone ID already exists'
                    ];
                }
            }

            // Get user subscription tier
            $subscriptionTier = $this->getUserSubscriptionTier($userId);

            // Generate API keys
            $apiKey = $this->generateApiKey();
            $apiSecret = $this->generateApiSecret();
            $hashedSecret = password_hash($apiSecret, PASSWORD_ARGON2ID);

            // Set rate limits based on subscription tier
            $rateLimits = $this->getRateLimitsForTier($subscriptionTier);
            $droneRegistrationId = 0;

            // Insert drone registration
            $stmt = $this->db->prepare(
                "INSERT INTO drone_registrations
                (drone_id, drone_name, user_id, api_key, api_secret, drone_type, camera_specs,
                subscription_tier, rate_limit_per_minute, rate_limit_per_hour, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
            );

            $cameraSpecsJson = json_encode($cameraSpecs);
            if ($stmt) {
                $stmt->bind_param("ssissssiis",
                    $droneId, $droneName, $userId, $apiKey, $hashedSecret,
                    $droneType, $cameraSpecsJson, $subscriptionTier,
                    $rateLimits['per_minute'], $rateLimits['per_hour']
                );

                if (!$stmt->execute()) {
                    throw new DroneServiceException("Failed to register drone: " . $this->db->error);
                }

                $droneRegistrationId = $this->db->insert_id;
                $stmt->close();
            }

            // Log the activity
            $this->logDroneActivity($droneId, $userId, 'registration',
                'Drone registered successfully', true);

            return [
                'success' => true,
                'drone_id' => $droneId,
                'drone_registration_id' => $droneRegistrationId,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'subscription_tier' => $subscriptionTier,
                'rate_limits' => $rateLimits,
                'webhook_url' => $this->getWebhookUrl(),
                'status' => 'active'
            ];

        } catch (Exception $e) {
            error_log("Drone registration error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Authenticate a drone using API key and secret
     *
     * @param string $apiKey Public API key
     * @param string $apiSecret Secret key
     * @return array<string, mixed> Authentication result
     */
    public function authenticateDrone(string $apiKey, string $apiSecret): array
    {
        $result = [
            'success' => false,
            'error' => 'Authentication failed'
        ];

        try {
            $drone = $this->fetchDroneByApiKey($apiKey);
            
            if (!$drone) {
                return [
                    'success' => false,
                    'error' => 'Invalid API key or drone not active'
                ];
            }

            $result = $this->verifyDroneCredentials($drone, $apiSecret);

        } catch (Exception $e) {
            error_log("Drone authentication error: " . $e->getMessage());
            $result = [
                'success' => false,
                'error' => 'Authentication failed: ' . $e->getMessage()
            ];
        }

        return $result;
    }

    /**
     * Fetch drone registration by API key
     * @return array<string, mixed>|null
     */
    private function fetchDroneByApiKey(string $apiKey): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, drone_id, user_id, api_secret, status, subscription_tier,
            rate_limit_per_minute, rate_limit_per_hour
            FROM drone_registrations
            WHERE api_key = ? AND status = 'active'"
        );

        if ($stmt) {
            $stmt->bind_param("s", $apiKey);
            $stmt->execute();
            $queryResult = $stmt->get_result();

            if ($queryResult !== false && $queryResult->num_rows > 0) {
                $drone = $queryResult->fetch_assoc();
                $stmt->close();
                return is_array($drone) ? $drone : null;
            }
        }

        return null;
    }

    /**
     * Verify drone credentials and return result
     * @param array<string, mixed> $drone
     * @return array<string, mixed>
     */
    private function verifyDroneCredentials(array $drone, string $apiSecret): array
    {
        // Verify API secret
        if (!password_verify($apiSecret, (string)$drone['api_secret'])) {
            // Log failed authentication attempt
            $this->logSecurityEvent((string)$drone['drone_id'], (int)$drone['user_id'],
                'authentication_failure', 'Invalid API secret', 'medium');

            return [
                'success' => false,
                'error' => 'Invalid API secret'
            ];
        }

        // Update last active timestamp
        $this->updateDroneLastActive((string)$drone['drone_id']);

        return [
            'success' => true,
            'drone_id' => $drone['drone_id'],
            'user_id' => $drone['user_id'],
            'subscription_tier' => $drone['subscription_tier'],
            'rate_limits' => [
                'per_minute' => $drone['rate_limit_per_minute'],
                'per_hour' => $drone['rate_limit_per_hour']
            ]
        ];
    }

    /**
     * Generate cryptographically secure API key
     */
    private function generateApiKey(): string
    {
        return 'DRONE-' . bin2hex(random_bytes(16));
    }

    /**
     * Generate cryptographically secure API secret
     */
    private function generateApiSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get user subscription tier from login table
     */
    private function getUserSubscriptionTier(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT subscription FROM login WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result !== false) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return strtolower((string)($row['subscription'] ?? 'basic'));
            }
        }
        return 'basic';
    }

    /**
     * Get rate limits based on subscription tier
     * @return array<string, int>
     */
    private function getRateLimitsForTier(string $tier): array
    {
        $limits = [
            'basic' => ['per_minute' => 30, 'per_hour' => 500],
            'premium' => ['per_minute' => 60, 'per_hour' => 1000],
            'vip' => ['per_minute' => 120, 'per_hour' => 2000],
            'elite' => ['per_minute' => 300, 'per_hour' => 5000]
        ];
        return $limits[$tier] ?? $limits['basic'];
    }

    /**
     * Update drone last active timestamp
     */
    private function updateDroneLastActive(string $droneId): void
    {
        $stmt = $this->db->prepare("UPDATE drone_registrations SET last_active = NOW() WHERE drone_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $droneId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Get webhook URL configuration
     */
    private function getWebhookUrl(): string
    {
        return $_ENV['DRONE_WEBHOOK_URL'] ?? '';
    }

    /**
     * Log drone activity
     */
    private function logDroneActivity(string $droneId, int $userId, string $activityType, string $description, bool $success): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO drone_activity_log (drone_id, user_id, activity_type, activity_description, success)
            VALUES (?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("sissi", $droneId, $userId, $activityType, $description, $success ? 1 : 0);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Log security event
     */
    private function logSecurityEvent(string $droneId, int $userId, string $eventType, string $description, string $severity): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO drone_security_events (event_id, drone_id, user_id, event_type, severity, description)
            VALUES (UUID(), ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("sissss", $droneId, $userId, $eventType, $severity, $description);
            $stmt->execute();
            $stmt->close();
        }
    }
}

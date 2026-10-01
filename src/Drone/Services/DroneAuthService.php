<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use ROOTS\Config\Database;
use Exception;

/**
 * DroneAuthService - Coordinator for drone service operations
 *
 * This service coordinates between specialized drone service classes:
 * - Authentication and registration (via DroneAuthenticator)
 * - Session management (via DroneSessionManager)
 * - Rate limiting (via DroneRateLimiter)
 */
class DroneAuthService
{
    private DroneAuthenticator $authenticator;
    private DroneSessionManager $sessionManager;
    private DroneRateLimiter $rateLimiter;

    public function __construct()
    {
        $this->authenticator = new DroneAuthenticator();
        $this->sessionManager = new DroneSessionManager();
        $this->rateLimiter = new DroneRateLimiter();
    }

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
        return $this->authenticator->registerDrone($userId, $droneId, $droneName, $droneType, $cameraSpecs);
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
        return $this->authenticator->authenticateDrone($apiKey, $apiSecret);
    }

    /**
     * Validate drone access and check rate limits
     *
     * @param string $droneId Drone identifier
     * @param string $windowType Rate limit window type (minute, hour)
     * @return array<string, mixed> Validation result
     */
    public function validateDroneAccess(string $droneId, string $windowType = 'minute'): array
    {
        return $this->rateLimiter->validateDroneAccess($droneId, $windowType);
    }

    /**
     * Create or get active session for a drone
     *
     * @param string $droneId Drone identifier
     * @param string $ipAddress IP address
     * @param string $userAgent User agent string
     * @return array<string, mixed> Session information
     */
    public function createDroneSession(string $droneId, string $ipAddress, string $userAgent): array
    {
        return $this->sessionManager->createDroneSession($droneId, $ipAddress, $userAgent);
    }

    /**
     * Validate drone session
     *
     * @param string $sessionToken Session token
     * @return array<string, mixed> Session validation result
     */
    public function validateSession(string $sessionToken): array
    {
        return $this->sessionManager->validateSession($sessionToken);
    }

    /**
     * Get all drones for a user
     *
     * @param int $userId User ID
     * @return array<int, array<string, mixed>> List of drones
     */
    public function getUserDrones(int $userId): array
    {
        $db = Database::getConnection();
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT id, drone_id, drone_name, status, subscription_tier, last_active
            FROM drone_registrations
            WHERE user_id = ? ORDER BY last_active DESC"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $drones = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $drones[] = $row;
            }
        }

        $stmt->close();
        return $drones;
    }

    /**
     * Rotate API keys for security
     *
     * @param string $droneId Drone identifier
     * @param string $userId User ID for authorization
     * @return array<string, mixed> Key rotation result
     */
    public function rotateApiKeys(string $droneId, string $userId): array
    {
        $db = Database::getConnection();
        if (!$db) {
            return ['success' => false, 'error' => 'Database connection failed'];
        }

        try {
            // Generate new keys
            $newApiKey = 'DRONE-' . bin2hex(random_bytes(16));
            $newApiSecret = bin2hex(random_bytes(32));
            $newHashedSecret = password_hash($newApiSecret, PASSWORD_ARGON2ID);

            // Store old keys in history
            $stmt = $db->prepare(
                "INSERT INTO drone_api_keys_history
                (drone_id, user_id, old_api_key, old_api_secret, new_api_key, new_api_secret, changed_at)
                SELECT drone_id, user_id, api_key, api_secret, ?, ?, NOW()
                FROM drone_registrations
                WHERE drone_id = ? AND user_id = ?"
            );

            if ($stmt) {
                $stmt->bind_param("ssss", $newApiKey, $newApiSecret, $droneId, $userId);

                if (!$stmt->execute()) {
                    throw new DroneServiceException("Failed to store key history: " . $db->error);
                }

                $stmt->close();
            }

            // Update with new keys
            $stmt = $db->prepare(
                "UPDATE drone_registrations
                SET api_key = ?, api_secret = ?
                WHERE drone_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("sss", $newApiKey, $newHashedSecret, $droneId);

                if (!$stmt->execute()) {
                    throw new DroneServiceException("Failed to rotate keys: " . $db->error);
                }

                $stmt->close();
            }

            return [
                'success' => true,
                'new_api_key' => $newApiKey,
                'new_api_secret' => $newApiSecret,
                'message' => 'Keys rotated successfully'
            ];

        } catch (Exception $e) {
            error_log("Key rotation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Key rotation failed'
            ];
        }
    }
}

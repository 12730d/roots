<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use Exception;

/**
 * DroneSessionManager - Handles drone session management
 */
class DroneSessionManager extends AbstractDroneService
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

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
        try {
            $drone = $this->getDroneInfo($droneId);
            if (!is_array($drone)) {
                return ['success' => false, 'error' => 'Drone not found'];
            }

            $sessionId = $this->generateSessionId();
            $sessionToken = $this->generateSessionToken();
            $expiresAt = date(self::DATETIME_FORMAT, strtotime('+24 hours'));

            // Clean expired sessions
            $this->cleanExpiredSessions($droneId);

            // Create new session
            $stmt = $this->db->prepare(
                "INSERT INTO drone_active_sessions
                (session_id, drone_id, user_id, session_token, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param("sssssss",
                    $sessionId, $droneId, $drone['user_id'], $sessionToken,
                    $ipAddress, $userAgent, $expiresAt
                );

                if (!$stmt->execute()) {
                    throw new DroneServiceException("Failed to create session: " . $this->db->error);
                }

                $stmt->close();
            }

            return [
                'success' => true,
                'session_id' => $sessionId,
                'session_token' => $sessionToken,
                'expires_at' => $expiresAt
            ];

        } catch (Exception $e) {
            error_log("Session creation error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Session creation failed'];
        }
    }

    /**
     * Validate drone session
     *
     * @param string $sessionToken Session token
     * @return array<string, mixed> Session validation result
     */
    public function validateSession(string $sessionToken): array
    {
        $result = ['success' => false, 'error' => 'Session validation failed'];

        try {
            $session = $this->getSessionByToken($sessionToken);
            if ($session === null) {
                $result = ['success' => false, 'error' => 'Invalid session'];
            } elseif (!$this->isSessionValid($session)) {
                $result = ['success' => false, 'error' => 'Session expired'];
            } else {
                $this->updateSessionActivity($session['session_id']);
                $result = $this->buildSessionResult($session);
            }

        } catch (Exception $e) {
            // Result already set to default error state
        }

        return $result;
    }

    /**
     * Get session by token
     *
     * @param string $sessionToken Session token
     * @return array<string, mixed>|null Session data or null if not found
     */
    private function getSessionByToken(string $sessionToken): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT session_id, drone_id, user_id, status, expires_at
            FROM drone_active_sessions
            WHERE session_token = ? AND status = 'active'"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $queryResult = $stmt->get_result();

        if ($queryResult === false || $queryResult->num_rows === 0) {
            $stmt->close();
            return null;
        }

        $session = $queryResult->fetch_assoc();
        $stmt->close();

        return is_array($session) ? $session : null;
    }

    /**
     * Check if session is valid and not expired
     *
     * @param array<string, mixed> $session Session data
     * @return bool True if session is valid
     */
    private function isSessionValid(array $session): bool
    {
        if (strtotime((string)$session['expires_at']) < time()) {
            $this->expireSession($session['session_id']);
            return false;
        }
        return true;
    }

    /**
     * Build successful session result
     *
     * @param array<string, mixed> $session Session data
     * @return array<string, mixed> Success result
     */
    private function buildSessionResult(array $session): array
    {
        return [
            'success' => true,
            'drone_id' => $session['drone_id'],
            'user_id' => $session['user_id'],
            'session_id' => $session['session_id']
        ];
    }

    /**
     * Get drone information
     * @return array<string, mixed>|null
     */
    private function getDroneInfo(string $droneId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM drone_registrations WHERE drone_id = ?"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("s", $droneId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result !== false && $result->num_rows > 0) {
            $drone = $result->fetch_assoc();
            $stmt->close();
            return is_array($drone) ? $drone : null;
        }

        return null;
    }

    /**
     * Generate session ID
     */
    private function generateSessionId(): string
    {
        return 'SESSION-' . bin2hex(random_bytes(16));
    }

    /**
     * Generate session token
     */
    private function generateSessionToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Clean expired sessions for a drone
     */
    private function cleanExpiredSessions(string $droneId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM drone_active_sessions
            WHERE drone_id = ? AND expires_at < NOW()"
        );
        if ($stmt) {
            $stmt->bind_param("s", $droneId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Expire a session
     */
    private function expireSession(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE drone_active_sessions SET status = 'expired' WHERE session_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Update session last activity
     */
    private function updateSessionActivity(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE drone_active_sessions
            SET last_activity = NOW(), activity_count = activity_count + 1
            WHERE session_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

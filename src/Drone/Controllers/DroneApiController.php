<?php

declare(strict_types=1);

namespace ROOTS\Drone\Controllers;

use ROOTS\Drone\Services\DroneAuthService;
use ROOTS\Drone\Services\FaceRecognitionService;
use ROOTS\Drone\Middleware\DroneAuthMiddleware;
use ROOTS\Config\Database;
use Exception;

/**
 * DroneApiController - Main API controller for drone face scanning operations
 *
 * This controller handles all drone API endpoints:
 * - Drone authentication and registration
 * - Face scanning and recognition
 * - Face management
 * - Statistics and monitoring
 */
class DroneApiController
{
    private const string ERROR_METHOD_NOT_ALLOWED = 'Method not allowed';
    private DroneAuthService $authService;
    private FaceRecognitionService $faceService;
    private \mysqli $db;

    public function __construct()
    {
        $this->authService = new DroneAuthService();
        $this->faceService = new FaceRecognitionService();
        $this->db = Database::getConnection();

        if (!$this->db) {
            throw new DroneApiException("Database connection failed");
        }
    }

    /**
     * Handle API request routing
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     */
    public function handleRequest(string $endpoint, string $method): void
    {
        // Set JSON response headers
        $allowedOrigins = ['http://localhost', 'https://localhost'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigin = in_array($origin, $allowedOrigins, true) ? $origin : '';
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-API-Secret');

        // Handle preflight requests
        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        try {
            switch ($endpoint) {
                case 'register':
                    $this->handleDroneRegistration();
                    break;
                case 'authenticate':
                    $this->handleDroneAuthentication();
                    break;
                case 'face-scan':
                    $this->handleFaceScan();
                    break;
                case 'register-face':
                    $this->handleFaceRegistration();
                    break;
                case 'delete-face':
                    $this->handleFaceDeletion();
                    break;
                case 'statistics':
                    $this->handleStatistics();
                    break;
                case 'drone-info':
                    $this->handleDroneInfo();
                    break;
                case 'webhook':
                    $this->handleWebhook();
                    break;
                default:
                    $this->sendErrorResponse('Endpoint not found', 404);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse('Internal server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle drone registration
     * POST /api/v1/drone/register
     */
    private function handleDroneRegistration(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Get request data
        $input = $this->getJsonInput();

        $userId = $input['user_id'] ?? null;
        $droneId = $input['drone_id'] ?? null;
        $droneName = $input['drone_name'] ?? 'Unnamed Drone';
        $droneType = $input['drone_type'] ?? 'Unknown';
        $cameraSpecs = $input['camera_specs'] ?? [];

        // Validate required fields
        if (!$userId || !$droneId) {
            $this->sendErrorResponse('Missing required fields: user_id, drone_id', 400);
        }

        // Register drone
        $result = $this->authService->registerDrone($userId, $droneId, $droneName, $droneType, $cameraSpecs);

        if ($result['success']) {
            $this->sendSuccessResponse($result);
        } else {
            $this->sendErrorResponse($result['error'], 400);
        }
    }

    /**
     * Handle drone authentication
     * POST /api/v1/drone/authenticate
     */
    private function handleDroneAuthentication(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        $input = $this->getJsonInput();
        $apiKey = $input['api_key'] ?? null;
        $apiSecret = $input['api_secret'] ?? null;

        if (!$apiKey || !$apiSecret) {
            $this->sendErrorResponse('Missing required fields: api_key, api_secret', 400);
        }

        $result = $this->authService->authenticateDrone($apiKey, $apiSecret);

        if ($result['success']) {
            // Create session
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $sessionResult = $this->authService->createDroneSession(
                $result['drone_id'],
                $ipAddress,
                $userAgent
            );

            $this->sendSuccessResponse(array_merge($result, $sessionResult));
        } else {
            $this->sendErrorResponse($result['error'], 401);
        }
    }

    /**
     * Handle face scanning
     * POST /api/v1/drone/face-scan
     */
    private function handleFaceScan(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Authenticate drone
        $middleware = new DroneAuthMiddleware();
        $authResult = $middleware->authenticate();

        if (!$authResult['success']) {
            $this->sendErrorResponse($authResult['error'], $authResult['code']);
        }

        // Get request data
        $input = $this->getJsonInput();
        $imageData = $input['image_data'] ?? null;
        $location = $input['location'] ?? [];
        $options = $input['options'] ?? [];

        if (!$imageData) {
            $this->sendErrorResponse('Missing required field: image_data', 400);
        }

        // Generate scan ID
        $scanId = 'SCAN-' . bin2hex(random_bytes(16));
        $startTime = microtime(true);

        // Process image for face recognition
        $processResult = $this->faceService->processImageForFaceRecognition($imageData, $options);

        if (!$processResult['success']) {
            // Log failed scan
            $this->logFaceScan(new FaceScanLogData(
                $scanId,
                $authResult['drone_id'],
                $authResult['user_id'],
                $imageData,
                $location,
                false,
                null,
                null,
                0,
                (int) round((microtime(true) - $startTime) * 1000),
                $processResult['error']
            ));

            $this->sendErrorResponse($processResult['error'], 500);
        }

        // Find best match
        $bestMatch = null;
        if (!empty($processResult['matched_faces'])) {
            usort($processResult['matched_faces'], function($a, $b) {
                return $b['confidence'] <=> $a['confidence'];
            });
            $bestMatch = $processResult['matched_faces'][0];
        }

        // Log successful scan
        $this->logFaceScan(new FaceScanLogData(
            $scanId,
            $authResult['drone_id'],
            $authResult['user_id'],
            $imageData,
            $location,
            $processResult['faces_detected'] > 0,
            $bestMatch['face_db_id'] ?? null,
            $bestMatch['user_id'] ?? null,
            $bestMatch['confidence'] ?? 0,
            $processResult['processing_time_ms'],
            null,
            $processResult['faces_detected'],
            $bestMatch !== null
        ));

        $this->sendSuccessResponse([
            'scan_id' => $scanId,
            'drone_id' => $authResult['drone_id'],
            'faces_detected' => $processResult['faces_detected'],
            'matches_found' => $processResult['matches_found'],
            'matched_faces' => $processResult['matched_faces'],
            'processing_time_ms' => $processResult['processing_time_ms'],
            'timestamp' => date('c'),
            'rate_limit_remaining' => $authResult['rate_limit_remaining']
        ]);
    }

    /**
     * Handle face registration
     * POST /api/v1/faces/register
     */
    private function handleFaceRegistration(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Authenticate drone
        $middleware = new DroneAuthMiddleware();
        $authResult = $middleware->authenticate();

        if (!$authResult['success']) {
            $this->sendErrorResponse($authResult['error'], $authResult['code']);
        }

        // Get request data
        $input = $this->getJsonInput();
        $faceName = $input['face_name'] ?? 'Unknown Face';
        $imageData = $input['face_image'] ?? null;
        $metadata = $input['metadata'] ?? [];

        if (!$imageData) {
            $this->sendErrorResponse('Missing required field: face_image', 400);
        }

        // Register face
        $result = $this->faceService->registerFace(
            $authResult['user_id'],
            $faceName,
            $imageData,
            $metadata
        );

        if ($result['success']) {
            $this->sendSuccessResponse($result);
        } else {
            $this->sendErrorResponse($result['error'], 500);
        }
    }

    /**
     * Handle face deletion
     * DELETE /api/v1/faces/{face_id}
     */
    private function handleFaceDeletion(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Authenticate drone
        $middleware = new DroneAuthMiddleware();
        $authResult = $middleware->authenticate();

        if (!$authResult['success']) {
            $this->sendErrorResponse($authResult['error'], $authResult['code']);
        }

        // Get face ID from URL
        $faceId = $_GET['face_id'] ?? null;

        if (!$faceId) {
            $this->sendErrorResponse('Missing required parameter: face_id', 400);
        }

        // Delete face
        $result = $this->faceService->deleteFace($faceId, $authResult['user_id']);

        if ($result['success']) {
            $this->sendSuccessResponse($result);
        } else {
            $this->sendErrorResponse($result['error'], 400);
        }
    }

    /**
     * Handle statistics request
     * GET /api/v1/drone/statistics
     */
    private function handleStatistics(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Authenticate drone
        $middleware = new DroneAuthMiddleware();
        $authResult = $middleware->authenticate();

        if (!$authResult['success']) {
            $this->sendErrorResponse($authResult['error'], $authResult['code']);
        }

        // Get drone statistics
        $stats = $this->getDroneStatistics($authResult['drone_id'], $authResult['user_id']);

        $this->sendSuccessResponse($stats);
    }

    /**
     * Handle drone info request
     * GET /api/v1/drone/info
     */
    private function handleDroneInfo(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Authenticate drone
        $middleware = new DroneAuthMiddleware();
        $authResult = $middleware->authenticate();

        if (!$authResult['success']) {
            $this->sendErrorResponse($authResult['error'], $authResult['code']);
        }

        // Get drone info
        $droneInfo = $this->getDroneInfo($authResult['drone_id']);

        $this->sendSuccessResponse($droneInfo);
    }

    /**
     * Handle webhook
     * POST /api/v1/drone/webhook
     */
    private function handleWebhook(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendErrorResponse(self::ERROR_METHOD_NOT_ALLOWED, 405);
        }

        // Verify webhook signature
        $this->getHeader('X-Webhook-Signature');
        $payload = file_get_contents('php://input');
        if ($payload === false) {
            $payload = '';
        }

        // Process webhook event
        $event = json_decode($payload, true);

        if (!$event) {
            $this->sendErrorResponse('Invalid webhook payload', 400);
        }

        $eventType = $event['event_type'] ?? 'unknown';
        $eventData = $event['data'] ?? [];

        // Handle different event types
        switch ($eventType) {
            case 'scan_complete':
                $this->handleScanCompleteEvent($eventData);
                break;
            case 'match_found':
                $this->handleMatchFoundEvent($eventData);
                break;
            default:
                $this->sendSuccessResponse(['message' => 'Webhook received']);
        }
    }

    /**
     * Log face scan to database
     *
     * @param FaceScanLogData $data Face scan logging data
     */
    private function logFaceScan(FaceScanLogData $data): void {
        try {
            $imageHash = hash('sha256', $data->imageData);
            $imageSize = strlen($data->imageData);
            $locationLat = $data->location['lat'] ?? null;
            $locationLng = $data->location['lng'] ?? null;
            $locationAlt = $data->location['alt'] ?? null;
            $metadataJson = json_encode([
                'scan_id' => $data->scanId,
                'timestamp' => date('c')
            ]);

            $stmt = $this->db->prepare(
                "INSERT INTO face_scan_logs
                (scan_id, drone_id, user_id, image_hash, image_size,
                location_lat, location_lng, location_alt, face_detected,
                faces_detected_count, face_match_found, matched_face_id,
                matched_user_id, confidence_score, processing_time_ms,
                encryption_method, api_response_code, error_message, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'AES-256-GCM', 200, ?, ?)"
            );

            $responseCode = $data->errorMessage ? 500 : 200;
            $faceDetectedVal = $data->faceDetected ? 1 : 0;
            $faceMatchFoundVal = $data->faceMatchFound ? 1 : 0;

            if ($stmt) {
                $stmt->bind_param("ssisdddiiiddissss",
                    $data->scanId, $data->droneId, $data->userId, $imageHash, $imageSize,
                    $locationLat, $locationLng, $locationAlt,
                    $faceDetectedVal,
                    $data->facesDetectedCount,
                    $faceMatchFoundVal,
                    $data->matchedFaceId,
                    $data->matchedUserId,
                    $data->confidenceScore,
                    $data->processingTimeMs,
                    $responseCode,
                    $data->errorMessage,
                    $metadataJson
                );

                $stmt->execute();
                $stmt->close();
            }

        } catch (Exception $e) {
            error_log("Face scan logging error: " . $e->getMessage());
        }
    }

    /**
     * Get drone statistics
     *
     * @return array<string, mixed>
     */
    private function getDroneStatistics(string $droneId, int $userId): array
    {
        try {
            // Get face statistics
            $faceStats = $this->faceService->getFaceStatistics($userId);

            // Get scan statistics
            $stmt = $this->db->prepare(
                "SELECT
                COUNT(*) as total_scans,
                SUM(CASE WHEN face_match_found = TRUE THEN 1 ELSE 0 END) as successful_matches,
                AVG(processing_time_ms) as avg_processing_time,
                AVG(confidence_score) as avg_confidence
                FROM face_scan_logs
                WHERE drone_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("s", $droneId);
                $stmt->execute();
                $scanStatsResult = $stmt->get_result();
                $scanStats = $scanStatsResult !== false ? $scanStatsResult->fetch_assoc() : null;
                $stmt->close();
            } else {
                $scanStats = null;
            }

            return [
                'face_statistics' => $faceStats,
                'scan_statistics' => [
                    'total_scans' => (int)($scanStats['total_scans'] ?? 0),
                    'successful_matches' => (int)($scanStats['successful_matches'] ?? 0),
                    'avg_processing_time_ms' => round((float)($scanStats['avg_processing_time'] ?? 0), 2),
                    'avg_confidence' => round((float)($scanStats['avg_confidence'] ?? 0), 2)
                ],
                'timestamp' => date('c')
            ];

        } catch (Exception $e) {
            error_log("Get drone statistics error: " . $e->getMessage());
            return [
                'face_statistics' => [],
                'scan_statistics' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get drone information
     *
     * @return array<string, mixed>
     */
    private function getDroneInfo(string $droneId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT drone_id, drone_name, drone_type, status, subscription_tier,
                rate_limit_per_minute, rate_limit_per_hour, last_active, created_at
                FROM drone_registrations
                WHERE drone_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("s", $droneId);
                $stmt->execute();
                $result = $stmt->get_result();
                $drone = $result !== false ? $result->fetch_assoc() : null;
                $stmt->close();
            } else {
                $drone = null;
            }

            if (!$drone) {
                return ['error' => 'Drone not found'];
            }

            return $drone;

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get JSON input from request
     *
     * @return array<string, mixed>
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input ?: '', true) ?? [];
    }

    /**
     * Get header value
     */
    private function getHeader(string $headerName): string
    {
        $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return $_SERVER[$headerName] ?? '';
    }

    /**
     * Send success response
     *
     * @param array<string, mixed> $data
     */
    private function sendSuccessResponse(array $data): void
    {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Send error response
     */
    private function sendErrorResponse(string $message, int $code): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Handle scan complete event (webhook)
     *
     * @param array<string, mixed> $data
     */
    private function handleScanCompleteEvent(array $data): void
    {
        // Send notification to user
        $userId = $data['user_id'] ?? null;
        $droneId = $data['drone_id'] ?? null;
        $scanId = $data['scan_id'] ?? null;

        if ($userId && $droneId && $scanId) {
            $this->sendNotification($userId, $droneId, 'scan_complete',
                'Face Scan Complete', "Scan $scanId has been completed successfully");
        }
    }

    /**
     * Handle match found event (webhook)
     *
     * @param array<string, mixed> $data
     */
    private function handleMatchFoundEvent(array $data): void
    {
        $userId = $data['user_id'] ?? null;
        $droneId = $data['drone_id'] ?? null;
        $faceName = $data['face_name'] ?? 'Unknown Face';

        if ($userId && $droneId) {
            $this->sendNotification($userId, $droneId, 'match_found',
                'Face Match Found', "Match found for: $faceName");
        }
    }

    /**
     * Send notification to user
     */
    private function sendNotification(int $userId, string $droneId, string $type, string $title, string $message): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO drone_notifications
                (user_id, drone_id, notification_type, notification_title, notification_message)
                VALUES (?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param("issss", $userId, $droneId, $type, $title, $message);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Send notification error: " . $e->getMessage());
        }
    }
}

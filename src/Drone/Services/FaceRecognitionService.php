<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;
use ROOTS\Exceptions\RegistrationException;
use ROOTS\Exceptions\DeletionException;
use ROOTS\Exceptions\ValidationException;
use Exception;

/**
 * FaceRecognitionService - Handles face detection and recognition using OpenCV
 *
 * This service manages:
 * - Face detection from images
 * - Face encoding/feature extraction
 * - Face matching against registered faces
 * - Image processing and optimization
 * - Integration with OpenCV Python scripts
 */
class FaceRecognitionService
{
    private \mysqli $db;
    private string $pythonPath;
    private string $opencvScriptPath;
    private string $tempImagePath;
    private float $confidenceThreshold;

    public function __construct()
    {
        $this->db = Database::getConnection();
        if (!$this->db) {
            throw DatabaseException::connectionFailed("Could not establish database connection");
        }

        // Configuration
        $this->pythonPath = $this->findPythonPath();
        $this->opencvScriptPath = __DIR__ . '/../../../scripts/opencv_face_recognition.py';
        $this->tempImagePath = __DIR__ . '/../../../temp/faces/';
        $this->confidenceThreshold = 0.75; // Default confidence threshold

        // Ensure temp directory exists
        if (!is_dir($this->tempImagePath)) {
            mkdir($this->tempImagePath, 0755, true);
        }
    }

    /**
     * Process image for face detection and recognition
     *
     * @param string $imageData Base64 encoded image data
     * @param array<string, mixed> $options Processing options
     * @return array<string, mixed> Processing results
     */
    public function processImageForFaceRecognition(string $imageData, array $options = []): array
    {
        $startTime = microtime(true);
        $imageContent = null;
        $imageHash = '';
        $facesDetected = [];
        $matchedFaces = [];
        $processingTime = 0;
        $error = '';

        try {
            // Decode base64 image
            $imageContent = $this->decodeBase64Image($imageData);
            if (!$imageContent) {
                $error = 'Invalid image data';
                throw new ValidationException($error);
            }

            // Generate image hash for deduplication
            $imageHash = hash('sha256', $imageContent);

            // Save temporary image file
            $tempImagePath = $this->saveTempImage($imageContent, $imageHash);

            // Set confidence threshold from options
            $confidenceThreshold = $options['confidence_threshold'] ?? $this->confidenceThreshold;
            $maxFaces = $options['max_faces'] ?? 5;

            // Call OpenCV script for face detection
            $detectionResult = $this->detectFacesWithOpenCV($tempImagePath, $confidenceThreshold, $maxFaces);

            if (!$detectionResult['success']) {
                $error = 'Face detection failed: ' . $detectionResult['error'];
                throw new ValidationException($error);
            }

            $facesDetected = $detectionResult['faces'];
            $processingTime = round((microtime(true) - $startTime) * 1000);

            // If faces detected, try to match them
            if (!empty($facesDetected)) {
                foreach ($facesDetected as $faceData) {
                    $matchResult = $this->matchFace($faceData['encoding'], $confidenceThreshold);
                    if ($matchResult['match_found']) {
                        $matchedFaces[] = [
                            'face_id' => $matchResult['face_id'],
                            'face_name' => $matchResult['face_name'],
                            'confidence' => $matchResult['confidence'],
                            'user_id' => $matchResult['user_id'],
                            'bounding_box' => $faceData['bounding_box']
                        ];
                    }
                }
            }

            // Clean up temporary file
            if (file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }

        } catch (Exception $e) {
            error_log("Face processing error: " . $e->getMessage());
            if (empty($error)) {
                $error = 'Image processing failed: ' . $e->getMessage();
            }
        }

        return [
            'success' => empty($error),
            'error' => $error,
            'image_hash' => $imageHash,
            'faces_detected' => count($facesDetected),
            'faces_data' => $facesDetected,
            'matches_found' => count($matchedFaces),
            'matched_faces' => $matchedFaces,
            'processing_time_ms' => $processingTime
        ];
    }

    /**
     * Register a new face for recognition
     *
     * @param int $userId User ID
     * @param string $faceName Name/label for the face
     * @param string $imageData Base64 encoded image data
     * @param array<string, mixed> $metadata Additional metadata
     * @return array<string, mixed> Registration result
     */
    public function registerFace(int $userId, string $faceName, string $imageData, array $metadata = []): array
    {
        $faceId = '';
        $faceRegistrationId = 0;
        $encodingConfidence = 0;
        $error = '';
        $tempImagePath = '';

        try {
            // Decode image
            $imageContent = $this->decodeBase64Image($imageData);
            if (!$imageContent) {
                $error = 'Invalid image data';
                throw new ValidationException($error);
            }

            // Generate unique face ID
            $faceId = 'face_' . bin2hex(random_bytes(16));

            // Generate image hashes
            $imageHash = hash('sha256', $imageContent);

            // Save temporary image
            $tempImagePath = $this->saveTempImage($imageContent, $imageHash);

            // Extract face encoding using OpenCV
            $encodingResult = $this->extractFaceEncoding($tempImagePath);

            if (!$encodingResult['success']) {
                $error = 'Face encoding failed: ' . $encodingResult['error'];
                throw new ValidationException($error);
            }

            $encodingConfidence = $encodingResult['confidence'];

            // Generate thumbnail
            $thumbnailResult = $this->generateThumbnail($tempImagePath);

            // Save face encoding and thumbnail to database
            $stmt = $this->db->prepare(
                "INSERT INTO registered_faces
                (face_id, user_id, face_name, face_encoding, face_encoding_hash,
                face_thumbnail, face_thumbnail_hash, registration_source, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'upload', ?)"
            );

            $encodingHash = hash('sha256', $encodingResult['encoding']);
            $thumbnailHash = hash('sha256', $thumbnailResult['thumbnail']);
            $metadataJson = json_encode($metadata);

            if ($stmt) {
                $stmt->bind_param("sisbbss",
                    $faceId, $userId, $faceName, $encodingResult['encoding'], $encodingHash,
                    $thumbnailResult['thumbnail'], $thumbnailHash, $metadataJson);

                if (!$stmt->execute()) {
                    throw new RegistrationException("Failed to register face: " . $this->db->error);
                }

                $faceRegistrationId = $this->db->insert_id;
                $stmt->close();
            }

            // Clean up temporary file
            if (file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }

        } catch (Exception $e) {
            error_log("Face registration error: " . $e->getMessage());
            if (empty($error)) {
                $error = 'Face registration failed: ' . $e->getMessage();
            }
            // Clean up temporary file on error
            if (file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
        }

        return [
            'success' => empty($error),
            'error' => $error,
            'face_id' => $faceId,
            'face_registration_id' => $faceRegistrationId,
            'face_name' => $faceName,
            'encoding_confidence' => $encodingConfidence
        ];
    }

    /**
     * Match face encoding against registered faces
     *
     * @param string $faceEncoding Face encoding data
     * @param float $threshold Confidence threshold
     * @return array<string, mixed> Match result
     */
    private function matchFace(string $faceEncoding, float $threshold): array
    {
        try {
            // Get all active registered faces
            $stmt = $this->db->prepare(
                "SELECT id, face_id, user_id, face_name, face_encoding, face_encoding_hash
                FROM registered_faces
                WHERE status = 'active'"
            );
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = false;
            }

            $bestMatch = null;
            $bestConfidence = 0;

            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    // Compare encodings using OpenCV
                    $comparisonResult = $this->compareFaceEncodings(
                        $faceEncoding,
                        (string)$row['face_encoding']
                    );

                    if ($comparisonResult['confidence'] > $bestConfidence) {
                        $bestConfidence = $comparisonResult['confidence'];
                        $bestMatch = [
                            'face_id' => $row['face_id'],
                            'face_db_id' => $row['id'],
                            'user_id' => $row['user_id'],
                            'face_name' => $row['face_name'],
                            'confidence' => $comparisonResult['confidence']
                        ];
                    }
                }
            }

            if ($stmt) {
                $stmt->close();
            }

            if ($bestMatch && $bestConfidence >= $threshold) {
                return [
                    'match_found' => true,
                    'face_id' => $bestMatch['face_id'],
                    'face_db_id' => $bestMatch['face_db_id'],
                    'user_id' => $bestMatch['user_id'],
                    'face_name' => $bestMatch['face_name'],
                    'confidence' => $bestConfidence
                ];
            }

            return ['match_found' => false];

        } catch (Exception $e) {
            error_log("Face matching error: " . $e->getMessage());
            return ['match_found' => false];
        }
    }

    /**
     * Detect faces using OpenCV Python script
     *
     * @param string $imagePath Path to image file
     * @param float $confidenceThreshold Confidence threshold
     * @param int $maxFaces Maximum number of faces to detect
     * @return array<string, mixed> Detection results
     */
    private function detectFacesWithOpenCV(string $imagePath, float $confidenceThreshold, int $maxFaces): array
    {
        if (!file_exists($this->opencvScriptPath)) {
            // Fallback to basic face detection without OpenCV
            return $this->basicFaceDetection($imagePath);
        }

        try {
            $command = escapeshellcmd($this->pythonPath) . ' ' .
                      escapeshellarg($this->opencvScriptPath) . ' ' .
                      escapeshellarg($imagePath) . ' ' .
                      escapeshellarg((string)$confidenceThreshold) . ' ' .
                      escapeshellarg((string)$maxFaces) . ' detect';

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                $resultJson = implode('', $output);
                $parsedResult = json_decode($resultJson, true);

                if ($parsedResult && isset($parsedResult['success'])) {
                    return $parsedResult;
                }
            }

        } catch (Exception $e) {
            error_log("OpenCV detection error: " . $e->getMessage());
        }

        return $this->basicFaceDetection($imagePath);
    }

    /**
     * Extract face encoding using OpenCV
     *
     * @param string $imagePath Path to image file
     * @return array<string, mixed> Encoding result
     */
    private function extractFaceEncoding(string $imagePath): array
    {
        $result = [
            'success' => false,
            'error' => ''
        ];

        try {
            if (!file_exists($this->opencvScriptPath)) {
                $result['error'] = 'OpenCV script not found';
            } else {
                $command = escapeshellcmd($this->pythonPath) . ' ' .
                          escapeshellarg($this->opencvScriptPath) . ' ' .
                          escapeshellarg($imagePath) . ' encode';

                $output = [];
                $returnCode = 0;
                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    $result['error'] = 'OpenCV encoding failed';
                } else {
                    $resultJson = implode('', $output);
                    $parsedResult = json_decode($resultJson, true);

                    if ($parsedResult && isset($parsedResult['success'])) {
                        return $parsedResult;
                    }
                    $result['error'] = 'Invalid encoding response';
                }
            }

        } catch (Exception $e) {
            error_log("Face encoding error: " . $e->getMessage());
            $result['error'] = 'Encoding failed: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Compare two face encodings
     *
     * @param string $encoding1 First face encoding
     * @param string $encoding2 Second face encoding
     * @return array<string, mixed> Comparison result
     */
    private function compareFaceEncodings(string $encoding1, string $encoding2): array
    {
        $result = ['confidence' => 0];

        try {
            if (!file_exists($this->opencvScriptPath)) {
                // Fallback: simple distance calculation
                return $this->simpleEncodingComparison($encoding1, $encoding2);
            }

            $tempFile1 = $this->saveTempData($encoding1, 'enc1');
            $tempFile2 = $this->saveTempData($encoding2, 'enc2');

            $command = escapeshellcmd($this->pythonPath) . ' ' .
                      escapeshellarg($this->opencvScriptPath) . ' ' .
                      escapeshellarg($tempFile1) . ' ' .
                      escapeshellarg($tempFile2) . ' compare';

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // Clean up temp files
            if (file_exists($tempFile1)) {
                unlink($tempFile1);
            }
            if (file_exists($tempFile2)) {
                unlink($tempFile2);
            }

            if ($returnCode === 0) {
                $resultJson = implode('', $output);
                $parsedResult = json_decode($resultJson, true);
                $result['confidence'] = $parsedResult['confidence'] ?? 0;
            }

            return $result;

        } catch (Exception $e) {
            return $this->simpleEncodingComparison($encoding1, $encoding2);
        }
    }

    /**
     * Basic face detection (fallback when OpenCV is not available)
     *
     * @param string $imagePath Path to image file
     * @return array<string, mixed> Detection results
     */
    private function basicFaceDetection(string $imagePath): array
    {
        try {
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                return [
                    'success' => false,
                    'error' => 'Invalid image file'
                ];
            }

            // Simulate face detection (in real implementation, this would use GD or similar)
            // This is a placeholder for when OpenCV is not available
            return [
                'success' => true,
                'faces' => [],
                'message' => 'OpenCV not available, basic detection mode'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Basic detection failed'
            ];
        }
    }

    /**
     * Simple encoding comparison (fallback)
     *
     * @param string $encoding1 First encoding
     * @param string $encoding2 Second encoding
     * @return array<string, mixed> Comparison result
     */
    private function simpleEncodingComparison(string $encoding1, string $encoding2): array
    {
        // Simple hamming distance calculation as fallback
        $len1 = strlen($encoding1);
        $len2 = strlen($encoding2);

        if ($len1 !== $len2) {
            return ['confidence' => 0];
        }

        $distance = 0;
        for ($i = 0; $i < $len1; $i++) {
            if ($encoding1[$i] !== $encoding2[$i]) {
                $distance++;
            }
        }

        $confidence = 1 - ($distance / $len1);
        return ['confidence' => $confidence];
    }

    /**
     * Generate thumbnail from image
     *
     * @param string $imagePath Path to original image
     * @return array<string, mixed> Thumbnail result
     */
    private function generateThumbnail(string $imagePath): array
    {
        $thumbnailData = null;
        $success = false;
        $image = null;

        try {
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                return [
                    'success' => false,
                    'thumbnail' => null
                ];
            }

            $imageType = $imageInfo[2];
            $thumbnailSize = 150;

            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($imagePath);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($imagePath);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($imagePath);
                    break;
                default:
                    return [
                        'success' => false,
                        'thumbnail' => null
                    ];
            }

            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);

                // Calculate thumbnail dimensions
                if ($width > $height) {
                    $newWidth = $thumbnailSize;
                    $newHeight = (int)($height * $thumbnailSize / $width);
                } else {
                    $newHeight = $thumbnailSize;
                    $newWidth = (int)($width * $thumbnailSize / $height);
                }

                $thumbnail = imagecreatetruecolor(max(1, $newWidth), max(1, $newHeight));
                imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                ob_start();
                imagejpeg($thumbnail, null, 85);
                $thumbnailData = ob_get_clean();
                ob_end_clean();

                imagedestroy($thumbnail);
                $success = true;
            }

            if ($image) {
                imagedestroy($image);
            }

        } catch (Exception $e) {
            error_log("Thumbnail generation error: " . $e->getMessage());
            if ($image) {
                imagedestroy($image);
            }
        }

        return [
            'success' => $success,
            'thumbnail' => $thumbnailData
        ];
    }

    /**
     * Decode base64 image data
     *
     * @param string $base64Data Base64 encoded image data
     * @return string|false Binary image data or false
     */
    private function decodeBase64Image(string $base64Data)
    {
        // Remove data URI scheme if present
        if (strpos($base64Data, 'data:image') === 0) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        }

        $imageData = base64_decode($base64Data);

        if ($imageData === false) {
            return false;
        }

        // Verify it's actually an image
        $imageInfo = @getimagesizefromstring($imageData);
        if ($imageInfo === false) {
            return false;
        }

        return $imageData;
    }

    /**
     * Save temporary image file
     *
     * @param string $imageContent Binary image content
     * @param string $imageHash Image hash for filename
     * @return string Path to temporary file
     */
    private function saveTempImage(string $imageContent, string $imageHash): string
    {
        $filename = $imageHash . '.jpg';
        $filepath = $this->tempImagePath . $filename;
        file_put_contents($filepath, $imageContent);
        return $filepath;
    }

    /**
     * Save temporary data file
     *
     * @param string $data Data to save
     * @param string $suffix Filename suffix
     * @return string Path to temporary file
     */
    private function saveTempData(string $data, string $suffix): string
    {
        $filename = bin2hex(random_bytes(8)) . '_' . $suffix . '.dat';
        $filepath = $this->tempImagePath . $filename;
        file_put_contents($filepath, $data);
        return $filepath;
    }

    /**
     * Find Python executable path
     *
     * @return string Python path
     */
    private function findPythonPath(): string
    {
        $possiblePaths = [
            '/usr/bin/python3',
            '/usr/bin/python',
            '/usr/local/bin/python3',
            '/usr/local/bin/python',
            'python3',
            'python'
        ];

        foreach ($possiblePaths as $path) {
            if (is_executable($path)) {
                return $path;
            }
            // Secure shell_exec with escapeshellarg
            $escapedPath = escapeshellarg($path);
            $whichResult = shell_exec("which $escapedPath 2>&1");
            if ($whichResult && trim($whichResult) !== '') {
                return $path;
            }
        }

        return 'python3'; // Default fallback
    }

    /**
     * Get registered faces for a user
     *
     * @param int $userId User ID
     * @return array<int, array<string, mixed>> List of registered faces
     */
    public function getUserRegisteredFaces(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, face_id, face_name, registration_date,
                status, scan_count, metadata
                FROM registered_faces
                WHERE user_id = ? AND status = 'active'
                ORDER BY registration_date DESC"
            );
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = false;
            }

            $faces = [];
            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $faces[] = [
                        'id' => $row['id'],
                        'face_id' => $row['face_id'],
                        'face_name' => $row['face_name'],
                        'registration_date' => $row['registration_date'],
                        'status' => $row['status'],
                        'scan_count' => $row['scan_count'],
                        'metadata' => json_decode((string)($row['metadata'] ?? '{}'), true)
                    ];
                }
            }

            if ($stmt) {
                $stmt->close();
            }
            return $faces;

        } catch (Exception $e) {
            error_log("Get user faces error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete a registered face
     *
     * @param string $faceId Face ID
     * @param int $userId User ID for authorization
     * @return array<string, mixed> Deletion result
     */
    public function deleteFace(string $faceId, int $userId): array
    {
        $result = [
            'success' => false,
            'error' => ''
        ];

        try {
            // Verify user owns the face
            $stmt = $this->db->prepare(
                "SELECT id FROM registered_faces WHERE face_id = ? AND user_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("si", $faceId, $userId);
                $stmt->execute();
                $dbResult = $stmt->get_result();
            } else {
                $dbResult = false;
            }

            $row = $dbResult !== false ? $dbResult->fetch_assoc() : null;
            if ($stmt) {
                $stmt->close();
            }

            if (!is_array($row)) {
                $result['error'] = 'Face not found or unauthorized';
                return $result;
            }

            // Soft delete (mark as deleted)
            $stmt = $this->db->prepare(
                "UPDATE registered_faces SET status = 'deleted', last_updated = NOW() WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("i", (int)$row['id']);

                if (!$stmt->execute()) {
                    throw new DeletionException("Failed to delete face: " . $this->db->error);
                }

                $stmt->close();
            }

            return ['success' => true, 'message' => 'Face deleted successfully'];

        } catch (Exception $e) {
            error_log("Face deletion error: " . $e->getMessage());
            $result['error'] = 'Face deletion failed: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Get face statistics
     *
     * @param int $userId User ID
     * @return array<string, int> Face statistics
     */
    public function getFaceStatistics(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT
                COUNT(*) as total_faces,
                SUM(scan_count) as total_scans,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_faces
                FROM registered_faces
                WHERE user_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats = $result !== false ? $result->fetch_assoc() : null;
                $stmt->close();
            } else {
                $stats = null;
            }

            return [
                'total_faces' => (int)($stats['total_faces'] ?? 0),
                'total_scans' => (int)($stats['total_scans'] ?? 0),
                'active_faces' => (int)($stats['active_faces'] ?? 0)
            ];

        } catch (Exception $e) {
            error_log("Get face statistics error: " . $e->getMessage());
            return [
                'total_faces' => 0,
                'total_scans' => 0,
                'active_faces' => 0
            ];
        }
    }
}

<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use ROOTS\Config\Database;
use Exception;

/**
 * SearchDatabaseService - Integration with the "search" database for face matching
 *
 * This service handles:
 * - Connection to the external "search" database
 * - Face search and matching operations
 * - Cross-database synchronization
 * - Performance optimization for large-scale face searches
 */
class SearchDatabaseService
{
    private \mysqli $mainDb;
    private ?\mysqli $searchDb = null;
    /** @var array<string, mixed> */
    private array $searchDbConfig;

    public function __construct()
    {
        $this->mainDb = Database::getConnection();
        $this->searchDbConfig = $this->getSearchDbConfig();

        if (!$this->mainDb) {
            throw new DroneServiceException("Main database connection failed");
        }
    }

    /**
     * Get search database configuration
     *
     * @return array<string, mixed>
     */
    private function getSearchDbConfig(): array
    {
        // Configuration for the "search" database
        return [
            'host' => $_ENV['SEARCH_DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['SEARCH_DB_PORT'] ?? 3306,
            'user' => $_ENV['SEARCH_DB_USER'] ?? 'root',
            'pass' => $_ENV['SEARCH_DB_PASS'] ?? '',
            'name' => $_ENV['SEARCH_DB_NAME'] ?? 'search',
            'charset' => 'utf8mb4'
        ];
    }

    /**
     * Connect to search database
     */
    private function connectToSearchDb(): \mysqli
    {
        if ($this->searchDb !== null && $this->searchDb->ping()) {
            return $this->searchDb;
        }

        try {
            $config = $this->searchDbConfig;
            $this->searchDb = new \mysqli(
                $config['host'],
                $config['user'],
                $config['pass'],
                $config['name'],
                (int)$config['port']
            );

            if ($this->searchDb->connect_error) {
                throw new DroneServiceException("Search DB connection failed: " . $this->searchDb->connect_error);
            }

            $this->searchDb->set_charset($config['charset']);
            return $this->searchDb;

        } catch (Exception $e) {
            error_log("Search database connection error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for face in search database
     *
     * @param string $faceEncoding Face encoding data
     * @param float $confidenceThreshold Minimum confidence threshold
     * @param int $limit Maximum number of results
     * @return array<string, mixed> Search results
     */
    public function searchFace(string $faceEncoding, float $confidenceThreshold = 0.75, int $limit = 5): array
    {
        try {
            $searchDb = $this->connectToSearchDb();

            // Calculate encoding hash for efficient lookup
            $encodingHash = hash('sha256', $faceEncoding);

            // First, try exact hash match for performance
            $exactMatch = $this->findExactHashMatch($searchDb, $encodingHash);
            if ($exactMatch) {
                return [
                    'success' => true,
                    'matches' => [$exactMatch],
                    'match_type' => 'exact',
                    'total_found' => 1
                ];
            }

            // If no exact match, perform similarity search
            $similarFaces = $this->findSimilarFaces($searchDb, $faceEncoding, $confidenceThreshold, $limit);

            return [
                'success' => true,
                'matches' => $similarFaces,
                'match_type' => 'similarity',
                'total_found' => count($similarFaces)
            ];

        } catch (Exception $e) {
            error_log("Face search error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage(),
                'matches' => []
            ];
        }
    }

    /**
     * Find exact hash match
     *
     * @return array<string, mixed>|null
     */
    private function findExactHashMatch(\mysqli $searchDb, string $encodingHash): ?array
    {
        $result = null;

        try {
            $stmt = $searchDb->prepare(
                "SELECT id, face_id, person_name, person_category, registration_date,
                metadata, is_active FROM face_search_index
                WHERE encoding_hash = ? AND is_active = 1 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param("s", $encodingHash);
                $stmt->execute();
                $queryResult = $stmt->get_result();
            } else {
                $queryResult = false;
            }

            if ($queryResult !== false && $queryResult->num_rows > 0) {
                $row = $queryResult->fetch_assoc();
                if ($stmt) {
                    $stmt->close();
                }

                if (is_array($row)) {
                    $result = [
                        'face_id' => $row['face_id'],
                        'person_name' => $row['person_name'],
                        'person_category' => $row['person_category'],
                        'confidence' => 1.0,
                        'registration_date' => $row['registration_date'],
                        'metadata' => json_decode((string)($row['metadata'] ?? '{}'), true)
                    ];
                }
            }

            if ($stmt) {
                $stmt->close();
            }

        } catch (Exception $e) {
            error_log("Exact hash match error: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Find similar faces using encoding comparison
     *
     * @return array<int, array<string, mixed>>
     */
    private function findSimilarFaces(\mysqli $searchDb, string $faceEncoding, float $threshold, int $limit): array
    {
        try {
            // Get all active faces from search database
            $stmt = $searchDb->prepare(
                "SELECT id, face_id, person_name, person_category, face_encoding,
                registration_date, metadata FROM face_search_index
                WHERE is_active = 1 LIMIT 1000"
            );
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = false;
            }

            $similarFaces = [];

            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                // Compare encodings
                $comparisonResult = $this->compareEncodings($faceEncoding, (string)$row['face_encoding']);

                if ($comparisonResult['confidence'] >= $threshold) {
                    $similarFaces[] = [
                        'face_id' => $row['face_id'],
                        'person_name' => $row['person_name'],
                        'person_category' => $row['person_category'],
                        'confidence' => $comparisonResult['confidence'],
                        'registration_date' => $row['registration_date'],
                        'metadata' => json_decode((string)($row['metadata'] ?? '{}'), true)
                    ];
                }
            }
            }

            if ($stmt) {
                $stmt->close();
            }

            // Sort by confidence (highest first) and limit results
            usort($similarFaces, function($a, $b) {
                return $b['confidence'] <=> $a['confidence'];
            });

            return array_slice($similarFaces, 0, $limit);

        } catch (Exception $e) {
            error_log("Similar faces search error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Compare two face encodings
     *
     * @return array<string, mixed>
     */
    private function compareEncodings(string $encoding1, string $encoding2): array
    {
        try {
            // Decode encodings if they're base64
            $enc1 = $this->decodeEncoding($encoding1);
            $enc2 = $this->decodeEncoding($encoding2);

            if (!$enc1 || !$enc2) {
                return ['confidence' => 0];
            }

            // Calculate Hamming distance for binary comparison
            $distance = 0;
            $len = min(strlen($enc1), strlen($enc2));

            for ($i = 0; $i < $len; $i++) {
                if ($enc1[$i] !== $enc2[$i]) {
                    $distance++;
                }
            }

            // Convert distance to confidence score
            $maxDistance = $len * 8; // Assuming bytes
            $confidence = 1.0 - min($distance / $maxDistance, 1.0);

            return ['confidence' => $confidence];

        } catch (Exception $e) {
            error_log("Encoding comparison error: " . $e->getMessage());
            return ['confidence' => 0];
        }
    }

    /**
     * Decode face encoding
     */
    private function decodeEncoding(string $encoding): ?string
    {
        try {
            // Try base64 decode first
            $decoded = base64_decode($encoding);
            if ($decoded && strlen($decoded) > 0) {
                return $decoded;
            }

            // Return as-is if not base64
            return $encoding;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Synchronize face from main database to search database
     *
     * @param int $faceId Face ID from main database
     * @param array<string, mixed> $metadata Additional metadata
     * @return array<string, mixed> Synchronization result
     */
    public function synchronizeFace(int $faceId, array $metadata = []): array
    {
        $result = ['success' => false, 'error' => 'Synchronization failed'];

        try {
            $searchDb = $this->connectToSearchDb();
            $faceData = $this->getFaceDataFromMainDb($faceId);

            if ($faceData === null) {
                $result = ['success' => false, 'error' => 'Face not found in main database'];
            } else {
                $exists = $this->checkFaceExistsInSearchDb($searchDb, $faceData['face_id']);
                $syncResult = $exists
                    ? $this->updateFaceInSearchDb($searchDb, $faceData, $metadata, $faceId)
                    : $this->insertFaceInSearchDb($searchDb, $faceData, $metadata, $faceId);
                $result = $syncResult;
            }

        } catch (Exception $e) {
            error_log("Face synchronization error: " . $e->getMessage());
            $result = [
                'success' => false,
                'error' => 'Synchronization failed: ' . $e->getMessage()
            ];
        }

        return $result;
    }

    /**
     * Get face data from main database
     *
     * @param int $faceId Face ID
     * @return array<string, mixed>|null Face data or null if not found
     */
    private function getFaceDataFromMainDb(int $faceId): ?array
    {
        $stmt = $this->mainDb->prepare(
            "SELECT face_id, user_id, face_name, face_encoding, face_encoding_hash,
            registration_date, metadata FROM registered_faces
            WHERE id = ? AND status = 'active'"
        );
        if ($stmt) {
            $stmt->bind_param("i", $faceId);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = false;
        }

        if ($result !== false && $result->num_rows === 0) {
            if ($stmt) {
                $stmt->close();
            }
            return null;
        }

        $faceData = $result !== false ? $result->fetch_assoc() : null;
        if ($stmt) {
            $stmt->close();
        }

        return is_array($faceData) ? $faceData : null;
    }

    /**
     * Check if face exists in search database
     *
     * @param \mysqli $searchDb Search database connection
     * @param string $faceId Face ID
     * @return bool True if face exists
     */
    private function checkFaceExistsInSearchDb(\mysqli $searchDb, string $faceId): bool
    {
        $checkStmt = $searchDb->prepare(
            "SELECT id FROM face_search_index WHERE face_id = ?"
        );
        if ($checkStmt) {
            $checkStmt->bind_param("s", $faceId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $exists = $checkResult !== false && $checkResult->num_rows > 0;
            $checkStmt->close();
        } else {
            $exists = false;
        }

        return $exists;
    }

    /**
     * Update face in search database
     *
     * @param \mysqli $searchDb Search database connection
     * @param array<string, mixed> $faceData Face data
     * @param array<string, mixed> $metadata Additional metadata
     * @param int $faceId Original face ID
     * @return array<string, mixed> Update result
     */
    private function updateFaceInSearchDb(\mysqli $searchDb, array $faceData, array $metadata, int $faceId): array
    {
        $encodingHash = hash('sha256', (string)$faceData['face_encoding']);
        $metadataJson = $this->buildMetadataJson($metadata, $faceId, $faceData);

        $updateStmt = $searchDb->prepare(
            "UPDATE face_search_index
            SET person_name = ?, face_encoding = ?, encoding_hash = ?,
            metadata = ?, last_synced = NOW()
            WHERE face_id = ?"
        );

        if ($updateStmt) {
            $updateStmt->bind_param("sssss",
                $faceData['face_name'], $faceData['face_encoding'], $encodingHash,
                $metadataJson, $faceData['face_id']);

            $success = $updateStmt->execute();
            $updateStmt->close();
        } else {
            $success = false;
        }

        return [
            'success' => $success,
            'action' => 'updated',
            'message' => $success ? 'Face updated in search database' : 'Update failed'
        ];
    }

    /**
     * Insert face into search database
     *
     * @param \mysqli $searchDb Search database connection
     * @param array<string, mixed> $faceData Face data
     * @param array<string, mixed> $metadata Additional metadata
     * @param int $faceId Original face ID
     * @return array<string, mixed> Insert result
     */
    private function insertFaceInSearchDb(\mysqli $searchDb, array $faceData, array $metadata, int $faceId): array
    {
        $encodingHash = hash('sha256', (string)$faceData['face_encoding']);
        $metadataJson = $this->buildMetadataJson($metadata, $faceId, $faceData);
        $category = $metadata['category'] ?? 'general';

        $insertStmt = $searchDb->prepare(
            "INSERT INTO face_search_index
            (face_id, person_name, face_encoding, encoding_hash,
            person_category, registration_date, metadata, last_synced, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)"
        );

        if ($insertStmt) {
            $insertStmt->bind_param("sssssss",
                $faceData['face_id'], $faceData['face_name'], $faceData['face_encoding'],
                $encodingHash, $category, $faceData['registration_date'], $metadataJson);

            $success = $insertStmt->execute();
            $insertStmt->close();
        } else {
            $success = false;
        }

        return [
            'success' => $success,
            'action' => 'created',
            'message' => $success ? 'Face added to search database' : 'Insert failed'
        ];
    }

    /**
     * Build metadata JSON for synchronization
     *
     * @param array<string, mixed> $metadata Additional metadata
     * @param int $faceId Face ID
     * @param array<string, mixed> $faceData Face data
     * @return string JSON encoded metadata
     */
    private function buildMetadataJson(array $metadata, int $faceId, array $faceData): string
    {
        $json = json_encode(array_merge($metadata, [
            'main_db_face_id' => $faceId,
            'main_db_user_id' => $faceData['user_id'],
            'sync_timestamp' => date('c')
        ]));

        if ($json === false) {
            error_log("Failed to encode metadata JSON");
            return '{"error":"metadata_encoding_failed"}';
        }

        return $json;
    }

    /**
     * Batch synchronize multiple faces
     *
     * @param array<int, int> $faceIds Array of face IDs to synchronize
     * @return array<string, mixed> Batch synchronization result
     */
    public function batchSynchronizeFaces(array $faceIds): array
    {
        $results = [
            'total' => count($faceIds),
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];

        foreach ($faceIds as $faceId) {
            $syncResult = $this->synchronizeSingleFace($faceId);

            if ($syncResult['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $results['details'][] = [
                'face_id' => $faceId,
                'result' => $syncResult
            ];
        }

        return $results;
    }

    /**
     * Synchronize a single face for batch processing
     *
     * @param int $faceId Face ID
     * @return array<string, mixed> Synchronization result
     */
    private function synchronizeSingleFace(int $faceId): array
    {
        try {
            return $this->synchronizeFace($faceId);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove face from search database
     *
     * @param string $faceId Face ID
     * @return array<string, mixed> Removal result
     */
    public function removeFromSearchDb(string $faceId): array
    {
        try {
            $searchDb = $this->connectToSearchDb();

            $stmt = $searchDb->prepare(
                "UPDATE face_search_index SET is_active = 0, removed_at = NOW() WHERE face_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("s", $faceId);
                $success = $stmt->execute();
                $stmt->close();
            } else {
                $success = false;
            }

            return [
                'success' => $success,
                'message' => $success ? 'Face removed from search database' : 'Removal failed'
            ];

        } catch (Exception $e) {
            error_log("Face removal error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Removal failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get search database statistics
     *
     * @return array<string, mixed> Search database statistics
     */
    public function getSearchDbStats(): array
    {
        try {
            $searchDb = $this->connectToSearchDb();

            // Get total faces in search database
            $result = $searchDb->query("SELECT COUNT(*) as total FROM face_search_index WHERE is_active = 1");
            $totalRow = is_object($result) ? $result->fetch_assoc() : null;
            $totalFaces = $totalRow['total'] ?? 0;

            // Get faces by category
            $result = $searchDb->query(
                "SELECT person_category, COUNT(*) as count
                FROM face_search_index
                WHERE is_active = 1
                GROUP BY person_category"
            );
            $byCategory = [];
            if (is_object($result)) {
                while ($row = $result->fetch_assoc()) {
                    $byCategory[(string)$row['person_category']] = (int)$row['count'];
                }
            }

            // Get recent synchronizations
            $result = $searchDb->query(
                "SELECT COUNT(*) as recent_syncs
                FROM face_search_index
                WHERE last_synced >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $recentRow = is_object($result) ? $result->fetch_assoc() : null;
            $recentSyncs = $recentRow['recent_syncs'] ?? 0;

            return [
                'total_faces' => (int)$totalFaces,
                'by_category' => $byCategory,
                'recent_synchronizations' => (int)$recentSyncs,
                'database_status' => 'connected'
            ];

        } catch (Exception $e) {
            error_log("Search DB stats error: " . $e->getMessage());
            return [
                'total_faces' => 0,
                'by_category' => [],
                'recent_synchronizations' => 0,
                'database_status' => 'disconnected',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Test search database connection
     *
     * @return array<string, mixed> Connection test result
     */
    public function testConnection(): array
    {
        try {
            $searchDb = $this->connectToSearchDb();
            $result = $searchDb->query("SELECT 1 as test");
            $testRow = is_object($result) ? $result->fetch_assoc() : null;

            if ($testRow && (int)$testRow['test'] === 1) {
                return [
                    'success' => true,
                    'message' => 'Search database connection successful',
                    'config' => [
                        'host' => $this->searchDbConfig['host'],
                        'database' => $this->searchDbConfig['name']
                    ]
                ];
            }

            return ['success' => false, 'error' => 'Connection test failed'];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Close search database connection
     */
    public function closeConnection(): void
    {
        if ($this->searchDb !== null) {
            $this->searchDb->close();
            $this->searchDb = null;
        }
    }
}

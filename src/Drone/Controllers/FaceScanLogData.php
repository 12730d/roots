<?php

declare(strict_types=1);

namespace ROOTS\Drone\Controllers;

/**
 * FaceScanLogData - Data class for face scan logging parameters
 */
class FaceScanLogData
{
    /**
     * @param array<string, float|int|string|null> $location
     */
    public function __construct(
        public string $scanId,
        public string $droneId,
        public int $userId,
        public string $imageData,
        public array $location,
        public bool $faceDetected,
        public ?int $matchedFaceId,
        public ?int $matchedUserId,
        public float $confidenceScore,
        public int $processingTimeMs,
        public ?string $errorMessage,
        public int $facesDetectedCount = 0,
        public bool $faceMatchFound = false
    ) {
    }
}

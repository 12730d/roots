<?php

declare(strict_types=1);

namespace ROOTS\Services;

/**
 * SearchService
 * 
 * Handles all search-related operations for the personnel database.
 * Provides a clean separation between business logic and presentation.
 */
class SearchService
{
    private const RESULTS_PER_PAGE = 20;
    private const MAX_SEARCH_LENGTH = 100;
    private const SEARCH_PATTERN = '/^[A-Za-z0-9+\-@_.\s]+$/';

    public function __construct(private readonly \mysqli $db) {}

    /**
     * Validate search term
     */
    public function validateSearchTerm(?string $searchTerm): ?string
    {
        if ($searchTerm === null || trim($searchTerm) === '') {
            return null;
        }

        $searchTerm = trim($searchTerm);

        if (strlen($searchTerm) > self::MAX_SEARCH_LENGTH) {
            return null;
        }

        if (!preg_match(self::SEARCH_PATTERN, $searchTerm)) {
            return null;
        }

        return $searchTerm;
    }

    /**
     * Search for records with pagination
     *
     * @return array{rows: array, page: int, total_pages: int, total_records: int}|null
     * @phpstan-return array{rows: array<array<string, mixed>>, page: int, total_pages: int, total_records: int}|null
     */
    public function search(string $searchTerm, int $page = 1): ?array
    {
        $offset = ($page - 1) * self::RESULTS_PER_PAGE;

        // Count total records
        $countQuery = "SELECT COUNT(*) as total FROM personnel_database
                      WHERE MATCH(name, username, email) AGAINST(? IN BOOLEAN MODE)
                      OR name LIKE ?
                      OR username LIKE ?
                      OR email LIKE ?";

        $searchPattern = '%' . $searchTerm . '%';
        $countStmt = $this->db->prepare($countQuery);

        if (!$countStmt) {
            return null;
        }

        $countStmt->bind_param('ssss', $searchTerm, $searchPattern, $searchPattern, $searchPattern);

        if (!$countStmt->execute()) {
            $countStmt->close();
            return null;
        }

        $countResult = $countStmt->get_result();
        if ($countResult === false) {
            $countStmt->close();
            return null;
        }
        $countRow = $countResult->fetch_assoc();
        $totalRecords = (int) ($countRow['total'] ?? 0);
        $countStmt->close();

        if ($totalRecords === 0) {
            return [
                'rows' => [],
                'page' => 1,
                'total_pages' => 1,
                'total_records' => 0
            ];
        }

        $totalPages = (int) ceil($totalRecords / self::RESULTS_PER_PAGE);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * self::RESULTS_PER_PAGE;

        // Fetch results
        $searchQuery = "SELECT * FROM personnel_database 
                       WHERE MATCH(name, username, email) AGAINST(? IN BOOLEAN MODE)
                       OR name LIKE ? 
                       OR username LIKE ? 
                       OR email LIKE ?
                       ORDER BY id DESC 
                       LIMIT ? OFFSET ?";
        
        $searchStmt = $this->db->prepare($searchQuery);
        
        if (!$searchStmt) {
            return null;
        }

        $searchStmt->bind_param('ssssii', $searchTerm, $searchPattern, $searchPattern, $searchPattern, 
                                   self::RESULTS_PER_PAGE, $offset);
        
        if (!$searchStmt->execute()) {
            $searchStmt->close();
            return null;
        }

        $result = $searchStmt->get_result();
        $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $searchStmt->close();

        return [
            'rows' => $rows,
            'page' => $page,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords
        ];
    }

    /**
     * Get user purchase count
     */
    public function getUserPurchaseCount(string $username): int
    {
        $query = "SELECT COUNT(*) AS total FROM user_purchases 
                 WHERE user_id = ? AND (record_type IS NULL OR record_type != 'password_leak')";
        
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $data = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int) ($data['total'] ?? 0);
    }

    /**
     * Check if user has search access based on subscription
     */
    public function hasSearchAccess(string $subscription): bool
    {
        $allowedSubscriptions = ['premium', 'vip', 'admin'];
        return in_array(strtolower(trim($subscription)), $allowedSubscriptions, true);
    }
}

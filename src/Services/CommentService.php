<?php

namespace ROOTS\Services;

use ROOTS\Config\Database;
use PDO;
use PDOException;

class CommentService
{
    /**
     * Fetch comments from the database
     *
     * @param mixed $limit Optional limit for number of comments to return
     * @return array<int, array<string, mixed>>
     */
    public static function fetchComments(mixed $limit = null): array {
        $comments = [];

        if (is_null($limit) || !is_numeric($limit)) {
            // Check if constant is defined, otherwise use default
            $limit = defined('MAX_COMMENTS_PER_PAGE') ? MAX_COMMENTS_PER_PAGE : 50;
        }

        try {
            // Get PDO connection from Database class
            $pdo = Database::getPdoConnection();

            // Prepare SQL query - now including all fields
            $sql = "SELECT id, name, email, wallet, plan, comment, created_at FROM comments_pay ORDER BY created_at DESC";

            // Add limit if specified
            if ($limit > 0) {
                $sql .= " LIMIT :limit";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare($sql);
            }

            // Execute query
            $stmt->execute();

            // Fetch all comments
            $comments = $stmt->fetchAll();

        } catch (PDOException $e) {
            // Log the error (in a production environment)
            error_log("Database error in fetchComments: " . $e->getMessage());
        }

        return $comments;
    }

    /**
     * Censor personal information in text
     *
     * @param string $text Text to censor
     * @param string $mode Mode of censoring ('partial', 'full', or 'none')
     * @return string Censored text
     */
    public static function censorText(string $text, string $mode = 'partial'): string {
        if ($mode === 'none') {
            return $text;
        }

        if ($mode === 'full') {
            // Replace all characters with asterisks, keeping spaces
            return preg_replace('/[^\s]/', '*', $text) ?? $text;
        }

        // Partial censoring - keep first and last characters
        if (strlen($text) <= 2) {
            return $text; // Too short to censor effectively
        }

        // For names, keep first letter and last letter
        $firstChar = mb_substr($text, 0, 1);
        $lastChar = mb_substr($text, -1, 1);
        $middleLength = mb_strlen($text) - 2;
        $censoredMiddle = str_repeat('*', $middleLength);

        return $firstChar . $censoredMiddle . $lastChar;
    }

    /**
     * Censor potentially sensitive information in comments
     *
     * @param string $text Comment text to process
     * @return string Processed comment with sensitive info censored
     */
    public static function censorSensitiveInfo(string $text): string {
        // Censor email addresses
        $text = preg_replace('/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/', '$1@***.$2', $text) ?? $text;

        // Censor phone numbers (various formats)
        $text = preg_replace('/(\d{3})[.-]?(\d{3})[.-]?(\d{4})/', '$1-***-$3', $text) ?? $text;

        // Censor credit card numbers (basic pattern)
        $text = preg_replace('/\b(\d{4})[- ]?(\d{4})[- ]?(\d{4})[- ]?(\d{4})\b/', '$1-****-****-$4', $text) ?? $text;

        // Censor wallet addresses
        $text = preg_replace('/\b(0x[a-fA-F0-9]{6})[a-fA-F0-9]+(a-fA-F0-9{4})\b/', '$1....$2', $text) ?? $text;

        return $text;
    }
}

<?php

// Include database configuration
require_once __DIR__ . '/vendor/autoload.php';
use ROOTS\Config\AppConfig;

AppConfig::init();

/**
 * Fetch comments_pay from the database
 *
 * @param int|null $limit Optional limit for number of comments_pay to return
 * @return array<int, array<string, mixed>> Array of comment data
 */
function fetchComments(?int $limit = null): array {
    global $host, $db, $user, $pass;
    $comments_pay = [];

    if ($limit === null) {
        $limit = MAX_COMMENTS_PER_PAGE;
    }

    try {
        // Create PDO connection
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

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

        // Fetch all comments_pay
        $comments_pay = $stmt->fetchAll();

    } catch (PDOException $e) {
        // Log the error (in a production environment)
        appLogError("Database error in fetchComments: " . $e->getMessage());
    }

    return $comments_pay;
}

/**
 * Censor personal information in text
 *
 * @param string $text Text to censor
 * @param string $mode Mode of censoring ('partial', 'full', or 'none')
 * @return string Censored text
 */
function censorText(string $text, string $mode = 'partial'): string {
    $result = $text;

    if ($mode === 'full') {
        $result = preg_replace('/[^\s]/', '*', $text) ?? '';
    } elseif ($mode !== 'none' && strlen($text) > 2) {
        $firstChar = mb_substr($text, 0, 1);
        $lastChar = mb_substr($text, -1, 1);
        $middleLength = mb_strlen($text) - 2;
        $censoredMiddle = str_repeat('*', $middleLength);

        $result = $firstChar . $censoredMiddle . $lastChar;
    }

    return $result;
}

/**
 * Censor potentially sensitive information in comments_pay
 *
 * @param string $text Comment text to process
 * @return string Processed comment with sensitive info censored
 */
function censorSensitiveInfo(string $text): string {
    // Censor email addresses
    $text = preg_replace('/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/', '$1@***.$2', $text) ?? '';

    // Censor phone numbers (various formats)
    $text = preg_replace('/(\d{3})[.-]?(\d{3})[.-]?(\d{4})/', '$1-***-$3', $text) ?? '';

    // Censor credit card numbers (basic pattern)
    $text = preg_replace('/\b(\d{4})[- ]?(\d{4})[- ]?(\d{4})[- ]?(\d{4})\b/', '$1-****-****-$4', $text) ?? '';

    // Censor wallet addresses
    $text = preg_replace('/\b(0x[a-fA-F0-9]{6})[a-fA-F0-9]+(a-fA-F0-9{4})\b/', '$1....$2', $text) ?? '';

    return $text;
}

// If this file is called directly, return comments_pay as JSON
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__) && isset($_GET['json'])) {
    echo json_encode(fetchComments(MAX_COMMENTS_PER_PAGE));
    exit;
}

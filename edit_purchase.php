<?php
declare(strict_types=1);

namespace ROOTS\SearchDB;

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;
use ROOTS\Config\Database;
use ROOTS\Services\ValidationService;
use ROOTS\Services\SensitiveDataService;
use ROOTS\Exceptions\PurchaseException;
use ROOTS\Exceptions\SecurityException;
use ROOTS\Exceptions\DatabaseException;
use ROOTS\Security\CsrfProtection;
use Exception;
use mysqli;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
// Detect if running on Onion service
$isOnionService =
    (isset($_SERVER["HTTP_HOST"]) &&
        preg_match('/\.onion$/i', $_SERVER["HTTP_HOST"])) ||
    (isset($_SERVER["SERVER_NAME"]) &&
        preg_match('/\.onion$/i', $_SERVER["SERVER_NAME"]));

// ============================================================================
// SECURITY HEADERS - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Set Content-Security-Policy based on connection type with enhanced XSS protection
if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\'; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\'; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
}

// Set HSTS header only for non-Onion HTTPS connections
if (
    !$isOnionService &&
    isset($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] === "on"
) {
    header(
        "Strict-Transport-Security: max-age=31536000; includeSubDomains; preload",
    );
}

// ============================================================================
// BAN STATUS CHECKING
// ============================================================================
$isBlockedUser = false;
try {
    if (class_exists('ROOTS\Auth\Session')) {
        \ROOTS\Auth\Session::start();
        if (\ROOTS\Auth\Session::isLoggedIn() && !empty($_SESSION["user_id"])) {
            $dbCheck = null;
            try {
                $dbCheck = Database::getConnection();
            } catch (Exception $e) {
                $dbCheck = null;
            }
            if ($dbCheck instanceof \mysqli) {
                $stmt = $dbCheck->prepare(
                    "SELECT ban_until, suspended FROM user_security_guard WHERE user_id = ? LIMIT 1",
                );
                if ($stmt) {
                    $uid = (int) $_SESSION["user_id"];
                    $stmt->bind_param("i", $uid);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        if (is_array($row)) {
                            if (!empty($row["suspended"])) {
                                $isBlockedUser = true;
                            } else {
                                $banUntil = $row["ban_until"] ?? null;
                                if (
                                    !empty($banUntil) &&
                                    strtotime((string) $banUntil) > time()
                                ) {
                                    $isBlockedUser = true;
                                }
                            }
                        }
                    } else {
                        error_log("Failed to execute ban check query: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    error_log("Failed to prepare ban check query: " . $dbCheck->error);
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking user ban status: " . $e->getMessage());
}

// Redirect blocked users
if ($isBlockedUser) {
    header("Location: blocked");
    exit();
}

// --- PAGE SETUP ---
$base_path = "../";
$pageData = PageController::setup(
    "SECURE_EDIT // HUD_EDIT_MODE",
    $base_path,
    [],
);
$is_admin = $pageData["is_admin"];
$user_data = $pageData["user"];

// --- DB CONNECTION ---
$con = Database::getConnection();
if (!$con) {
    die("SYSTEM ERROR: UNABLE TO ESTABLISH DATABASE UPLINK.");
}

// System Constants
define("TIMEZONE", "Asia/Riyadh");
date_default_timezone_set(TIMEZONE);

const SKIN_VERY_FAIR = 'Very Fair';
const SKIN_LIGHT_MEDIUM = 'Light Medium';
const SKIN_VERY_DEEP = 'Very Deep';

// --- HELPER FUNCTIONS ---
/**
 * @param array<mixed> $data
 * @return string
 */
function getOldVal(array $data, string $key): string
{
    return isset($data[$key]) ? htmlspecialchars((string) $data[$key]) : "";
}

/**
 * @param array<mixed> $data
 * @return string
 */
function isSelected(array $data, string $key, string $value): string
{
    if (!isset($data[$key])) {
        return "";
    }
    return strtolower((string) $data[$key]) === strtolower((string) $value)
        ? "selected"
        : "";
}

function safeExternalUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === "" || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts["scheme"] ?? ""));
    if (!in_array($scheme, ["http", "https"], true)) {
        return null;
    }
    return $url;
}

function hudVal(mixed $val): string
{
    if (empty($val) || $val === "-") {
        return '<span class="hud-null">N/A</span>';
    }
    return htmlspecialchars((string) $val, ENT_QUOTES, "UTF-8");
}

// --- LOGIC: FETCH & UPDATE ---
$message = "";
$messageType = "";
$purchase_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;
$r = []; // This will hold the record data

if (!$purchase_id) {
    die("SYSTEM ERROR: TARGET_ID MISSING.");
}

// --- LOGIC: POINT DEDUCTION ON INITIALIZATION ---
$deduction_amount = isset($_GET["deduct"]) ? intval($_GET["deduct"]) : 0;
if ($deduction_amount > 0) {
    // Handle token from POST (preferred) or GET (legacy/redirect)
    $provided_token = $_POST["csrf_token"] ?? $_GET["csrf_token"] ?? $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";

    if (
        empty($provided_token) ||
        !isset($_SESSION["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $provided_token)
    ) {
        die("SECURITY_ERROR: UNAUTHORIZED_REDIRECTION.");
    }

    $deduction_key = "deducted_edit_" . $purchase_id;
    if (!isset($_SESSION[$deduction_key])) {
        try {
            mysqli_begin_transaction($con);

            $username = $_SESSION["username"] ?? "";
            $check_stmt = $con->prepare(
                "SELECT points FROM login WHERE username = ?",
            );
            if (!$check_stmt) {
                throw new PurchaseException("QUERY_PREP_FAILED");
            }
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            $res = $check_stmt->get_result();
            $user_info = $res ? $res->fetch_assoc() : null;
            $current_points = $user_info["points"] ?? 0;

            if ($current_points < $deduction_amount) {
                throw new PurchaseException(
                    "INSUFFICIENT_FUNDS: Required {$deduction_amount} PTS, available {$current_points} PTS.",
                );
            }

            $update_points = $con->prepare(
                "UPDATE login SET points = points - ? WHERE username = ?",
            );
            if (!$update_points) {
                throw new PurchaseException("QUERY_PREP_FAILED");
            }
            $update_points->bind_param("is", $deduction_amount, $username);
            $update_points->execute();

            mysqli_commit($con);
            $_SESSION[$deduction_key] = true;
            $message = "AUTHORIZATION GRANTED. {$deduction_amount} POINTS DEDUCTED.";
            $messageType = "success";

            // SECURITY: If we just deducted points via GET token, redirect to clean URL
            if (isset($_GET["csrf_token"])) {
                header("Location: edit_purchase?id=" . $purchase_id);
                exit();
            }
        } catch (Exception $e) {
            mysqli_rollback($con);
            $message = "DEDUCTION_FAILED: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

try {
    $stmt = $con->prepare(
        "SELECT original_data FROM user_purchases WHERE id = ? LIMIT 1",
    );
    if (!$stmt) {
        throw new PurchaseException("QUERY_PREP_FAILED");
    }
    $stmt->bind_param("i", $purchase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        throw new PurchaseException("QUERY_EXECUTION_FAILED");
    }

    if ($row = $result->fetch_assoc()) {
        $r = json_decode((string) ($row["original_data"] ?? "{}"), true) ?? [];
    } else {
        throw new PurchaseException("RECORD NOT FOUND IN DATABASE.");
    }

    // 2. HANDLE UPDATE SUBMISSION
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (
            !isset($_POST["csrf_token"]) ||
            !isset($_SESSION["csrf_token"]) ||
            !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
        ) {
            throw new SecurityException("SECURITY_ERROR: Invalid CSRF token");
        }

        $newData = $r;

        // Access Code (ID Number) encryption
        if (isset($_POST["id_number"]) && !empty(trim($_POST["id_number"]))) {
            $idNumber = ValidationService::sanitizeString(
                $_POST["id_number"],
                100,
            );
            $newData["access_code"] = SensitiveDataService::encrypt($idNumber);
            $newData["a"] = $newData["access_code"]; // Also update short form
        } else {
            // Keep existing encrypted value if not provided
            $newData["access_code"] = $r["access_code"] ?? ($r["a"] ?? "");
            $newData["a"] = $newData["access_code"];
        }

        // Validate email if provided
        if (!empty($_POST["email"])) {
            $newData["email"] = ValidationService::validateEmail(
                $_POST["email"],
                "email",
            );
        }

        // Validate phone if provided
        if (!empty($_POST["phone"])) {
            $newData["phone"] = ValidationService::validatePhone(
                $_POST["phone"],
                "phone",
            );
        }

        // Validate numeric ranges
        if (!empty($_POST["height"])) {
            $newData["height"] = ValidationService::validateInt(
                $_POST["height"],
                "height",
                1,
                300,
            );
        }
        if (!empty($_POST["weight"])) {
            $newData["weight"] = ValidationService::validateInt(
                $_POST["weight"],
                "weight",
                1,
                500,
            );
        }
        if (!empty($_POST["age"])) {
            $newData["age"] = ValidationService::validateInt(
                $_POST["age"],
                "age",
                0,
                150,
            );
        }
        if (!empty($_POST["children_count"])) {
            $newData["children_count"] = ValidationService::validateInt(
                $_POST["children_count"],
                "children_count",
                0,
                50,
            );
        }
        if (!empty($_POST["request_price"])) {
            $newData["request_price"] = ValidationService::validateInt(
                $_POST["request_price"],
                "request_price",
                0,
                500000,
            );
        }

        // Validate enums
        if (!empty($_POST["blood_type"])) {
            $newData["blood_type"] = ValidationService::validateEnum(
                $_POST["blood_type"],
                ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"],
                "blood_type",
            );
        }
        if (!empty($_POST["marital_status"])) {
            $newData["marital_status"] = ValidationService::validateEnum(
                strtolower($_POST["marital_status"]),
                ["single", "married", "divorced", "widowed"],
                "marital_status",
            );
        }
        if (!empty($_POST["skin_color"])) {
            $skinColorRaw = $_POST["skin_color"];
            if ($skinColorRaw === "Olive/Tan") {
                $skinColorRaw = "Olive";
            }
            $newData["skin_color"] = ValidationService::validateEnum(
                $skinColorRaw,
                [
                    "Porcelain",
                    SKIN_VERY_FAIR,
                    "Fair",
                    "Light",
                    SKIN_LIGHT_MEDIUM,
                    "Medium",
                    "Olive",
                    "Tan",
                    "Deep",
                    SKIN_VERY_DEEP,
                ],
                "skin_color",
            );
        }

        // Enhanced field validation with strict limits and XSS protection
        $fieldLimits = [
            'username' => ['max' => 50, 'type' => 'alphanumeric', 'min' => 3],
            'name' => ['max' => 255, 'type' => 'name', 'min' => 2],
            'email' => ['max' => 255, 'type' => 'email'],
            'phone' => ['max' => 20, 'type' => 'phone'],
            'id_number' => ['max' => 16, 'type' => 'numeric', 'exact' => 16],
            'birth_cert' => ['max' => 50, 'type' => 'alphanumeric'],
            'birth_certificate_number' => ['max' => 30, 'type' => 'alphanumeric'],
            'nationality' => ['max' => 50, 'type' => 'name'],
            'marital_status' => ['max' => 20, 'type' => 'enum', 'values' => ['single', 'married', 'divorced', 'widowed']],
            'birth_date' => ['type' => 'date'],
            'age' => ['max' => 3, 'type' => 'number', 'min' => 0, 'max_val' => 150],
            'blood_type' => ['max' => 3, 'type' => 'enum', 'values' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']],
            'height' => ['max' => 3, 'type' => 'number', 'min' => 1, 'max_val' => 300],
            'weight' => ['max' => 3, 'type' => 'number', 'min' => 1, 'max_val' => 500],
            'skin_color' => ['max' => 20, 'type' => 'enum', 'values' => ['Porcelain', SKIN_VERY_FAIR, 'Fair', 'Light', SKIN_LIGHT_MEDIUM, 'Medium', 'Olive', 'Tan', 'Deep', SKIN_VERY_DEEP]],
            'city' => ['max' => 100, 'type' => 'name'],
            'district' => ['max' => 100, 'type' => 'name'],
            'street' => ['max' => 200, 'type' => 'address'],
            'building_number' => ['max' => 10, 'type' => 'alphanumeric'],
            'apartment_number' => ['max' => 10, 'type' => 'alphanumeric'],
            'postal_code' => ['max' => 20, 'type' => 'alphanumeric'],
            'relatives' => ['max' => 500, 'type' => 'text'],
            'children_count' => ['max' => 2, 'type' => 'number', 'min' => 0, 'max_val' => 50],
            'personal_car_number' => ['max' => 17, 'type' => 'alphanumeric'], // VIN format
            'social_media' => ['max' => 1000, 'type' => 'text']
        ];

        foreach ($fieldLimits as $field => $rules) {
            if (!empty($_POST[$field])) {
                $value = $_POST[$field];

                // XSS Protection - Strip all HTML tags and special characters
                $value = strip_tags($value);
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Remove any potential JavaScript event handlers
                $value = preg_replace('/on\w+\s*=\s*["\']?[^"\']*["\']?/i', '', $value) ?? '';

                // Remove javascript: protocol
                $value = preg_replace('/javascript\s*:/i', '', $value) ?? '';

                // Strict validation based on field type
                switch ($rules['type']) {
                    case 'numeric':
                        $value = preg_replace('/\D/', '', $value) ?? '';
                        if (isset($rules['exact']) && strlen($value) != $rules['exact']) {
                            throw new PurchaseException("Invalid {$field}: Must be exactly {$rules['exact']} digits");
                        }
                        break;

                    case 'alphanumeric':
                        $value = preg_replace('/[^a-zA-Z0-9]/', '', $value) ?? '';
                        break;

                    case 'name':
                        $value = preg_replace('/[^a-zA-Z\s\-\'\.]/', '', $value) ?? '';
                        $value = ucwords(strtolower(trim($value)));
                        break;

                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            throw new PurchaseException("Invalid email format");
                        }
                        break;

                    case 'phone':
                        $value = preg_replace('/[^0-9+\-\s\(\)]/', '', $value) ?? '';
                        break;

                    case 'address':
                        $value = preg_replace('/[^a-zA-Z0-9\s\-\.\,\#]/', '', $value) ?? '';
                        break;

                    case 'enum':
                        if (!in_array($value, $rules['values'])) {
                            throw new PurchaseException("Invalid {$field}: Must be one of " . implode(', ', $rules['values']));
                        }
                        break;

                    case 'date':
                        if (!\DateTime::createFromFormat('Y-m-d', $value)) {
                            throw new PurchaseException("Invalid date format. Use YYYY-MM-DD");
                        }
                        break;

                    case 'number':
                        $value = (int)$value;
                        if (isset($rules['min']) && $value < $rules['min'] && (!empty($_POST[$field]) || $value === 0)) {
                            throw new PurchaseException("{$field} must be at least {$rules['min']}");
                        }
                        if (isset($rules['max_val']) && $value > $rules['max_val']) {
                            throw new PurchaseException("{$field} must not exceed {$rules['max_val']}");
                        }
                        break;

                    case 'text':
                        $value = preg_replace('/[<>"\']/', '', $value) ?? '';
                        break;
                    default:
                        // No specific validation for unknown types
                        break;
                }

                // Length validation (ensure value is string for strlen)
                $stringValue = (string) $value;
                if (isset($rules['max']) && strlen($stringValue) > $rules['max']) {
                    throw new PurchaseException("{$field} exceeds maximum length of {$rules['max']} characters");
                }

                if (isset($rules['min']) && strlen($stringValue) < $rules['min']) {
                    throw new PurchaseException("{$field} must be at least {$rules['min']} characters");
                }

                $newData[$field] = $value;
            }
        }

        // Handle JSON Arrays (Social & Banks)
        if (isset($_POST["social_media"])) {
            $socialMediaInput = $_POST["social_media"];
            // Handle both string (textarea) and array inputs
            if (
                is_string($socialMediaInput) &&
                !empty(trim($socialMediaInput))
            ) {
                // Split by newlines and commas
                $lines = preg_split('/[\r\n,]+/', $socialMediaInput) ?: [];
                $socialMediaArray = array_values(
                    array_filter(
                        array_map(
                            fn($v) => trim(
                                ValidationService::sanitizeString($v, 500),
                            ),
                            $lines,
                        ),
                        fn($v) => $v !== "",
                    ),
                );
                $newData["social_media"] = json_encode(
                    array_slice($socialMediaArray, 0, 5),
                    JSON_UNESCAPED_UNICODE,
                );
            } elseif (is_array($socialMediaInput)) {
                $socialMediaRaw = array_slice($socialMediaInput, 0, 5);
                $socialMediaArray = array_values(
                    array_filter(
                        array_map(
                            fn($v) => ValidationService::sanitizeString(
                                $v,
                                500,
                            ),
                            $socialMediaRaw,
                        ),
                        fn($v) => $v !== "",
                    ),
                );
                $newData["social_media"] = json_encode(
                    $socialMediaArray,
                    JSON_UNESCAPED_UNICODE,
                );
            } else {
                $newData["social_media"] = json_encode(
                    [],
                    JSON_UNESCAPED_UNICODE,
                );
            }
        } else {
            // Keep existing value if not provided
            $newData["social_media"] =
                $r["social_media"] ?? json_encode([], JSON_UNESCAPED_UNICODE);
        }

        // Bank Accounts
        if (isset($_POST["bank_cards"]) && is_array($_POST["bank_cards"])) {
            $validatedBankCards = [];
            foreach ($_POST["bank_cards"] as $card) {
                if (
                    isset($card["number"]) &&
                    isset($card["expiry"]) &&
                    isset($card["cvv"])
                ) {
                    $validatedBankCards[] = [
                        "number" => ValidationService::sanitizeString(
                            $card["number"],
                            25,
                        ),
                        "expiry" => ValidationService::sanitizeString(
                            $card["expiry"],
                            7,
                        ),
                        "cvv" => ValidationService::sanitizeString(
                            $card["cvv"],
                            4,
                        ),
                    ];
                }
            }
            $newData["bank_accounts"] = SensitiveDataService::encrypt(
                json_encode($validatedBankCards, JSON_UNESCAPED_UNICODE) ?: '[]',
            );
        } else {
            $newData["bank_accounts"] = SensitiveDataService::encrypt("[]");
        }

        // Map descriptive names to short search table keys for full compatibility
        $mapping = [
            'username' => 'u',
            'name'     => 'n',
            'email'    => 'e',
            'phone'    => 't',
            'id_number' => 'a'
        ];

        foreach ($mapping as $long => $short) {
            if (isset($newData[$long])) {
                $val = $newData[$long];
                // Encrypt sensitive fields before saving
                if ($long === 'id_number') {
                    $val = SensitiveDataService::encrypt((string)$val);
                }
                $newData[$short] = $val;
            }
        }

        // Handle Address Composition
        $addr_parts = array_filter([
            $newData["city"] ?? "",
            $newData["district"] ?? "",
            $newData["street"] ?? "",
            $newData["building_number"] ?? ""
                ? "Bldg " . $newData["building_number"]
                : "",
            $newData["apartment_number"] ?? ""
                ? "Apt " . $newData["apartment_number"]
                : "",
            $newData["postal_code"] ?? ""
                ? "ZIP " . $newData["postal_code"]
                : "",
        ]);
        $newData["address"] = implode(", ", $addr_parts);

        // Encode back to JSON
        $jsonPayload = json_encode($newData, JSON_UNESCAPED_UNICODE);

        // --- NEW: Update $r with the failed attempt data to persist it in the form ---
        $r = $newData;

        // Update DB - Begin transaction
        mysqli_begin_transaction($con);

        try {
            // 1. Update user_purchases table
            $updateStmt = $con->prepare(
                "UPDATE user_purchases SET original_data = ?, updated_at = NOW() WHERE id = ?",
            );
            if (!$updateStmt) {
                throw new DatabaseException("QUERY_PREP_FAILED");
            }
            $updateStmt->bind_param("si", $jsonPayload, $purchase_id);

            if (!$updateStmt->execute()) {
                throw new DatabaseException("DATA_WRITE_ERROR. UPLINK INTERRUPTED.");
            }

            // 2. Get the record_id from user_purchases to update search table
            $getRecordIdStmt = $con->prepare(
                "SELECT record_id FROM user_purchases WHERE id = ? LIMIT 1",
            );
            if (!$getRecordIdStmt) {
                throw new DatabaseException("QUERY_PREP_FAILED");
            }
            $getRecordIdStmt->bind_param("i", $purchase_id);
            $getRecordIdStmt->execute();
            $recordIdResult = $getRecordIdStmt->get_result();
            if (!$recordIdResult) {
                throw new DatabaseException("QUERY_EXECUTION_FAILED");
            }

            if ($recordIdRow = $recordIdResult->fetch_assoc()) {
                $record_id = $recordIdRow["record_id"];

                // Get existing search record data for fallback
                $getSearchStmt = $con->prepare("SELECT * FROM search WHERE id = ?");
                if (!$getSearchStmt) {
                    throw new DatabaseException("QUERY_PREP_FAILED");
                }
                $getSearchStmt->bind_param("i", $record_id);
                $getSearchStmt->execute();
                $searchResult = $getSearchStmt->get_result();
                if (!$searchResult) {
                    throw new DatabaseException("QUERY_EXECUTION_FAILED");
                }
                $searchData = $searchResult->fetch_assoc() ?? [];

                // Extract values into variables for bind_param (required for by-reference passing)
                $search_username = $newData["username"] ?? $newData["u"] ?? $searchData["u"] ?? "";
                $search_name = $newData["n"] ?? $searchData["n"] ?? "";
                $search_email = $newData["e"] ?? $searchData["e"] ?? "";
                $search_phone = $newData["t"] ?? $searchData["t"] ?? "";
                $search_access_code = $newData["a"] ?? $searchData["a"] ?? "";
                $search_address = $newData["address"] ?? $searchData["address"] ?? "";
                $search_birth_cert = $newData["birth_cert"] ?? $searchData["birth_cert"] ?? "";
                $search_birth_cert_number = $newData["birth_certificate_number"] ?? $searchData["birth_certificate_number"] ?? "";
                $search_nationality = $newData["nationality"] ?? $searchData["nationality"] ?? "";
                $search_marital_status = $newData["marital_status"] ?? $searchData["marital_status"] ?? "";
                $search_birth_date = $newData["birth_date"] ?? $searchData["birth_date"] ?? null;
                if (is_string($search_birth_date)) {
                    $search_birth_date = trim($search_birth_date);
                    if ($search_birth_date === "") {
                        $search_birth_date = null;
                    } else {
                        $dt = \DateTime::createFromFormat("Y-m-d", $search_birth_date);
                        if ($dt instanceof \DateTime) {
                            $search_birth_date = $dt->format("Y-m-d");
                        } else {
                            $ts = strtotime($search_birth_date);
                            $search_birth_date = ($ts === false) ? null : date("Y-m-d", $ts);
                        }
                    }
                }
                $search_age = (int)($newData["age"] ?? $searchData["age"] ?? 0);
                $search_blood_type = $newData["blood_type"] ?? $searchData["blood_type"] ?? "";
                $search_height = (int)($newData["height"] ?? $searchData["height"] ?? 0);
                $search_weight = (int)($newData["weight"] ?? $searchData["weight"] ?? 0);
                $search_skin_color = $newData["skin_color"] ?? $searchData["skin_color"] ?? "";
                $search_city = $newData["city"] ?? $searchData["city"] ?? "";
                $search_district = $newData["district"] ?? $searchData["district"] ?? "";
                $search_street = $newData["street"] ?? $searchData["street"] ?? "";
                $search_building_number = $newData["building_number"] ?? $searchData["building_number"] ?? "";
                $search_apartment_number = $newData["apartment_number"] ?? $searchData["apartment_number"] ?? "";
                $search_postal_code = $newData["postal_code"] ?? $searchData["postal_code"] ?? "";
                $search_relatives = $newData["relatives"] ?? $searchData["relatives"] ?? "";
                $search_children_count = (int)($newData["children_count"] ?? $searchData["children_count"] ?? 0);
                $search_personal_car_number = $newData["personal_car_number"] ?? $searchData["personal_car_number"] ?? "";
                $search_social_media = $newData["social_media"] ?? $searchData["social_media"] ?? "";
                $search_bank_accounts = $newData["bank_accounts"] ?? $searchData["bank_accounts"] ?? "";

                // 3. Update search table with the same data
                $searchUpdateStmt = $con->prepare(
                    "UPDATE search SET " .
                    "u = ?, n = ?, e = ?, t = ?, a = ?, address = ?, " .
                    "birth_cert = ?, birth_certificate_number = ?, nationality = ?, " .
                    "marital_status = ?, birth_date = ?, age = ?, blood_type = ?, " .
                    "height = ?, weight = ?, skin_color = ?, city = ?, district = ?, " .
                    "street = ?, building_number = ?, apartment_number = ?, postal_code = ?, " .
                    "relatives = ?, children_count = ?, " .
                    "personal_car_number = ?, social_media = ?, bank_accounts = ? " .
                    "WHERE id = ?",
                );
                if (!$searchUpdateStmt) {
                    throw new DatabaseException("QUERY_PREP_FAILED");
                }

                $searchUpdateStmt->bind_param(
                    "sssssssssssisiissssssssisssi",
                    $search_username,
                    $search_name,
                    $search_email,
                    $search_phone,
                    $search_access_code,
                    $search_address,
                    $search_birth_cert,
                    $search_birth_cert_number,
                    $search_nationality,
                    $search_marital_status,
                    $search_birth_date,
                    $search_age,
                    $search_blood_type,
                    $search_height,
                    $search_weight,
                    $search_skin_color,
                    $search_city,
                    $search_district,
                    $search_street,
                    $search_building_number,
                    $search_apartment_number,
                    $search_postal_code,
                    $search_relatives,
                    $search_children_count,
                    $search_personal_car_number,
                    $search_social_media,
                    $search_bank_accounts,
                    $record_id
                );

                if (!$searchUpdateStmt->execute()) {
                    throw new DatabaseException("SEARCH_INDEX_UPDATE_ERROR.");
                }
            }

            // Commit transaction
            mysqli_commit($con);
            $message = "OVERRIDE COMPLETE. DATA UPDATED SUCCESSFULLY.";
            $messageType = "success";
            $r = $newData;

        } catch (Exception $e) {
            mysqli_rollback($con);
            throw new DatabaseException("DATA_COMMIT_FAILURE. PLEASE RETRY.");
        }
    }
} catch (Exception $e) {
    $message = "SYSTEM_NOTIFICATION: " . $e->getMessage();
    $messageType = "error";

    // --- FIX: Persist $_POST data back into $r so the form doesn't reset on error ---
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        foreach ($_POST as $key => $val) {
            if (is_string($val)) {
                $r[$key] = $val;
                // Sync short names for display if needed
                if ($key === 'username') {
                    $r['u'] = $val;
                }
                if ($key === 'name') {
                    $r['n'] = $val;
                }
                if ($key === 'email') {
                    $r['e'] = $val;
                }
                if ($key === 'phone') {
                    $r['t'] = $val;
                }
            }
        }
    }
}

// Parse existing JSON fields for display
$skinColorExisting = $r["skin_color"] ?? "";
if ($skinColorExisting === "Olive/Tan") {
    $r["skin_color"] = "Olive";
}

$social_data = $r["social_media"] ?? [];
$social_links = is_string($social_data)
    ? json_decode($social_data, true) ?? []
    : $social_data;
if (!is_array($social_links)) {
    $social_links = [];
}
$social_links = array_slice($social_links, 0, 5);

$bank_data = $r["bank_accounts"] ?? [];
$bank_cards = is_string($bank_data)
    ? json_decode($bank_data, true) ?? []
    : $bank_data;
if (!is_array($bank_cards)) {
    $bank_cards = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SECURE_EDIT // HUD_MODE</title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Roboto+Mono:wght@400;700&display=swap"
        rel="stylesheet" integrity="sha384-..." crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        integrity="sha384-..." crossorigin="anonymous">

    <style>
    :root {
        --hud-bg: #030507;
        --hud-panel: rgba(10, 20, 30, 0.9);
        --hud-primary: #0f0;
        --hud-secondary: #0f0;
        --hud-alert: #ff2a6d;
        --hud-text: #0f0;
        --hud-dim: #004400;
        --hud-grid: rgba(0, 255, 0, 0.03);
        --hud-edit: #ffb000;
    }

    body {
        background-color: var(--hud-bg) !important;
        background-image:
            linear-gradient(var(--hud-grid) 1px, transparent 1px),
            linear-gradient(90deg, var(--hud-grid) 1px, transparent 1px);
        background-size: 30px 30px;
        color: var(--hud-text) !important;
        font-family: 'Segoe UI', 'Roboto Mono', monospace !important;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        min-height: 100vh;
    }

    .hud-wrapper {
        width: 100vw;
        height: 100vh;
        margin: 0;
        padding: 20px;
        position: relative;
        box-sizing: border-box;
    }

    .hud-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        border-bottom: 2px solid var(--hud-edit);
        padding-bottom: 15px;
        margin-bottom: 40px;
        position: relative;
    }

    .hud-header::after {
        content: '';
        position: absolute;
        bottom: -2px;
        right: 0;
        width: 100px;
        height: 6px;
        background: var(--hud-edit);
        box-shadow: 0 0 15px var(--hud-edit);
    }

    .hud-title {
        font-size: 2rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 4px;
        color: var(--hud-edit);
        text-shadow: 0 0 10px rgba(255, 176, 0, 0.5);
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .hud-meta {
        font-family: 'Roboto Mono', monospace;
        font-size: 0.9rem;
        color: var(--hud-dim);
        text-align: right;
        line-height: 1.4;
    }

    .hud-meta span {
        color: var(--hud-edit);
    }

    .hud-main-grid {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 25px;
    }

    .hud-panel {
        background: var(--hud-panel);
        border: 1px solid rgba(0, 243, 255, 0.2);
        padding: 20px;
        position: relative;
        margin-bottom: 25px;
        clip-path: polygon(0 0, 100% 0, 100% calc(100% - 20px), calc(100% - 20px) 100%, 0 100%);
        backdrop-filter: blur(5px);
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
    }

    .hud-panel::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 20px;
        height: 20px;
        border-top: 2px solid var(--hud-primary);
        border-left: 2px solid var(--hud-primary);
    }

    .hud-section-header {
        font-family: 'Roboto Mono', monospace;
        color: var(--hud-secondary);
        font-size: 0.85rem;
        letter-spacing: 2px;
        text-transform: uppercase;
        border-bottom: 1px solid rgba(0, 243, 255, 0.1);
        padding-bottom: 5px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .hud-section-header i {
        color: var(--hud-primary);
    }

    .data-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .data-grid-3 {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 15px;
    }

    .data-row {
        margin-bottom: 10px;
        display: flex;
        flex-direction: column;
    }

    .data-label {
        font-size: 0.75rem;
        color: var(--hud-dim);
        text-transform: uppercase;
        margin-bottom: 2px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .data-value {
        font-size: 1.05rem;
        color: var(--hud-text);
        border-left: 2px solid var(--hud-dim);
        padding-left: 10px;
        transition: 0.3s;
        word-break: break-word;
        cursor: pointer;
        position: relative;
    }

    .data-value:hover {
        border-left-color: var(--hud-edit);
        background: linear-gradient(90deg, rgba(255, 176, 0, 0.05), transparent);
        text-shadow: 0 0 8px var(--hud-edit);
    }

    .data-value.editable:hover::after {
        content: '✎';
        position: absolute;
        right: 5px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--hud-edit);
        font-size: 0.8rem;
    }

    .data-value.editing {
        border-left-color: var(--hud-edit);
        background: linear-gradient(90deg, rgba(255, 176, 0, 0.1), transparent);
    }

    .data-value input,
    .data-value select {
        width: 100%;
        background-color: #000 !important;
        border: 1px solid var(--hud-edit);
        color: var(--hud-edit) !important;
        padding: 8px;
        font-family: 'Roboto Mono', monospace;
        font-size: 1rem;
        outline: none;
    }

    .hud-null {
        color: rgba(255, 255, 255, 0.2);
        font-style: italic;
        font-size: 0.9em;
    }

    .img-display {
        width: 100%;
        margin-bottom: 10px;
        position: relative;
        border: 1px solid var(--hud-dim);
        background: #000;
        min-height: 150px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
        outline: none;
    }

    .img-display:focus {
        border-color: var(--hud-primary);
        box-shadow: 0 0 10px var(--hud-primary);
    }

    .img-display img {
        max-width: 100%;
        height: auto;
        display: block;
        filter: contrast(1.1) grayscale(30%) sepia(20%) hue-rotate(180deg);
        transition: 0.4s;
        cursor: pointer;
    }

    .img-display img:hover {
        filter: none;
    }

    .img-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.8);
        border: 1px solid rgba(0, 243, 255, 0.3);
    }

    .img-overlay::after {
        content: 'LOCKED';
        position: absolute;
        bottom: 5px;
        right: 5px;
        background: var(--hud-alert);
        color: #000;
        font-size: 0.6rem;
        padding: 2px 4px;
        font-weight: bold;
    }

    .hud-actions {
        display: flex;
        gap: 15px;
        margin-top: 30px;
        justify-content: flex-end;
    }

    .hud-btn {
        background: transparent;
        border: 1px solid var(--hud-primary);
        color: var(--hud-primary);
        padding: 12px 25px;
        text-transform: uppercase;
        font-weight: bold;
        letter-spacing: 1px;
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
        clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .hud-btn:hover {
        background: rgba(0, 243, 255, 0.1);
        box-shadow: 0 0 15px rgba(0, 243, 255, 0.4);
        text-shadow: 0 0 5px var(--hud-primary);
    }

    .hud-btn.save {
        border-color: var(--hud-edit);
        color: var(--hud-edit);
    }

    .hud-btn.save:hover {
        background: rgba(255, 176, 0, 0.1);
        box-shadow: 0 0 15px rgba(255, 176, 0, 0.4);
        text-shadow: 0 0 5px var(--hud-edit);
    }

    .hud-btn.danger {
        border-color: var(--hud-alert);
        color: var(--hud-alert);
    }

    .hud-btn.danger:hover {
        background: rgba(255, 42, 109, 0.1);
        box-shadow: 0 0 15px rgba(255, 42, 109, 0.4);
        text-shadow: 0 0 5px var(--hud-alert);
    }

    .hud-btn.back {
        border-color: var(--hud-dim);
        color: var(--hud-dim);
        margin-right: auto;
    }

    .hud-btn.back:hover {
        color: #fff;
        border-color: #fff;
    }

    .hud-alert-box {
        border-left: 4px solid var(--hud-primary);
        background: linear-gradient(90deg, rgba(0, 243, 255, 0.1), transparent);
        padding: 15px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        color: var(--hud-primary);
        font-weight: bold;
        animation: slideIn 0.5s ease-out;
    }

    .hud-alert-box.error {
        border-left-color: var(--hud-alert);
        background: linear-gradient(90deg, rgba(255, 42, 109, 0.1), transparent);
        color: var(--hud-alert);
    }

    @keyframes slideIn {
        from {
            transform: translateX(-20px);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @media (max-width: 900px) {
        .hud-main-grid {
            grid-template-columns: 1fr;
        }

        .hud-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .hud-meta {
            text-align: left;
        }
    }

    .lightbox-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 5, 10, 0.95);
        z-index: 10000;
        display: none;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(10px);
    }

    .lightbox-modal.active {
        display: flex;
    }

    .lightbox-content {
        border: 1px solid var(--hud-primary);
        padding: 5px;
        background: rgba(0, 0, 0, 0.8);
        box-shadow: 0 0 30px rgba(0, 243, 255, 0.2);
    }

    .lightbox-image {
        max-width: 90vw;
        max-height: 80vh;
        display: block;
    }

    .lightbox-caption {
        padding: 10px;
        text-align: center;
        color: var(--hud-primary);
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: bold;
    }
        .data-value.editable.error-field {
        border: 1px solid var(--hud-alert) !important;
        background: rgba(255, 0, 0, 0.1) !important;
        box-shadow: 0 0 10px var(--hud-alert) !important;
    }

    .error-message-popup {
        position: absolute;
        background: var(--hud-alert);
        color: white;
        padding: 5px 10px;
        font-size: 0.7rem;
        border-radius: 4px;
        z-index: 1000;
        bottom: 100%;
        left: 0;
        margin-bottom: 5px;
        white-space: nowrap;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
    }

    .error-message-popup::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 10px;
        border-width: 5px;
        border-style: solid;
        border-color: var(--hud-alert) transparent transparent transparent;
    }
</style>
</head>

<body>

    <div class="hud-wrapper">

        <?php if (!empty($message)): ?>
        <div class="hud-alert-box <?= $messageType === "error"
                ? "error"
                : "" ?>">
            <i class="fas fa-<?= $messageType === "error"
                    ? "exclamation-triangle"
                    : "check-circle" ?>" style="font-size: 1.2rem;"></i>
            <div>
                <div style="font-size:0.7rem; opacity:0.7;">SYSTEM NOTIFICATION</div>
                <?= htmlspecialchars($message) ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="hud-header">
            <div class="hud-title">
                <i class="fas fa-edit"></i> EDIT_MODE_ACTIVE
            </div>
            <div class="hud-meta">
                <div>PURCHASE_ID: <span>#<?= htmlspecialchars(
                    (string) $purchase_id,
                ) ?></span></div>
                <div>DB_INDEX: <span><?= hudVal($r["id"] ?? "") ?></span></div>
                <div>USER: <span><?= htmlspecialchars(
                    $user_data["username"] ?? "UNKNOWN",
                ) ?></span></div>
            </div>
        </div>

        <form method="POST" id="hudEditForm">
            <?= CsrfProtection::tokenField() ?>

            <div class="hud-main-grid">

                <div style="display: flex; flex-direction: column;">

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-camera"></i> VISUAL_INTEL (READ-ONLY)</div>

                        <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px;">PRIMARY_TARGET
                            (person_photo)</div>
                        <button type="button" class="img-display" aria-label="Open Visual Intelligence" onclick="<?= !empty($r["person_photo"])
                                ? "openLightbox(this.querySelector('img').src, 'SUBJECT PHOTO')"
                                : "" ?>">
                            <?php if (!empty($r["person_photo"])): ?>
                            <img src="data:image/jpeg;base64,<?= htmlspecialchars(
                                    (string) $r["person_photo"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" alt="Target">
                            <div class="img-overlay"></div>
                            <?php else: ?>
                            <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                            <?php endif; ?>
                        </button>

                        <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px; margin-top: 15px;">
                            PROFILE_AVATAR (profile_image)</div>
                        <button type="button" class="img-display" aria-label="Open Profile Avatar" onclick="<?= !empty($r["profile_image"])
                                ? "openLightbox(this.querySelector('img').src, 'PROFILE AVATAR')"
                                : "" ?>">
                            <?php if (!empty($r["profile_image"])): ?>
                            <img src="data:image/jpeg;base64,<?= htmlspecialchars(
                                    (string) $r["profile_image"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" alt="Profile">
                            <div class="img-overlay"></div>
                            <?php else: ?>
                            <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                            <?php endif; ?>
                        </button>

                        <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px; margin-top: 15px;">
                            DOCUMENT_SCAN (id_card)</div>
                        <button type="button" class="img-display" aria-label="Open ID Card Scan" onclick="<?= !empty($r["id_card_file"])
                                ? "openLightbox(this.querySelector('img').src, 'ID CARD SCAN')"
                                : "" ?>">
                            <?php if (!empty($r["id_card_file"])): ?>
                            <img src="data:image/jpeg;base64,<?= htmlspecialchars(
                                    (string) $r["id_card_file"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" alt="ID Card">
                            <div class="img-overlay"></div>
                            <?php else: ?>
                            <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                            <?php endif; ?>
                        </button>

                        <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px; margin-top: 15px;">
                            DRIVING_LICENSE (driving_license)</div>
                        <button type="button" class="img-display" aria-label="Open Driving License" onclick="<?= !empty($r["driving_license_image"])
                                ? "openLightbox(this.querySelector('img').src, 'DRIVING LICENSE IMAGE')"
                                : "" ?>">
                            <?php if (!empty($r["driving_license_image"])): ?>
                            <img src="data:image/jpeg;base64,<?= htmlspecialchars(
                                    (string) $r["driving_license_image"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" alt="Driving License">
                            <div class="img-overlay"></div>
                            <?php else: ?>
                            <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                            <?php endif; ?>
                        </button>

                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-microchip"></i> TECH_METADATA (READ-ONLY)</div>
                        <div class="data-grid-2">
                            <div class="data-row">
                                <span class="data-label">SUBMITTED_BY</span>
                                <span class="data-value"><?= hudVal(
                                $r["submitted_by"] ?? "",
                            ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">SUBSCRIPTION</span>
                                <span class="data-value"><?= hudVal(
                                $r["subscription"] ?? "",
                            ) ?></span>
                            </div>

                            <div class="data-row">
                                <span class="data-label">POINTS (points)</span>
                                <span class="data-value"><?= hudVal(
                                $r["points"] ?? "",
                            ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">STATUS</span>
                                <span class="data-value" style="color: <?= ($r["status"] ?? "") ===
                                "approved"
                                    ? "var(--hud-primary)"
                                    : "var(--hud-alert)" ?>;">
                                    <?= strtoupper(hudVal($r["status"] ?? "")) ?>
                                </span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">FILES_STATUS</span>
                                <span class="data-value">
                                    <?= hudVal($r["files_upload_status"] ?? "") ?>
                                </span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">APPROVED_BY</span>
                                <span class="data-value"><?= hudVal(
                                $r["approved_by"] ?? "",
                            ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">ADDED_BY</span>
                                <span class="data-value"><?= hudVal(
                                $r["added_by"] ?? "",
                            ) ?></span>
                            </div>
                        </div>
                    </div>

                </div>

                <div style="display: flex; flex-direction: column;">

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-id-card"></i> Personal Identity (CLICK TO EDIT)</div>

                        <div class="data-row">
                            <span class="data-label"><i class="fas fa-user" style="margin-right:4px;"></i> Full Name</span>
                            <span class="data-value editable" data-field="name" data-type="text"
                                style="font-size: 1.2rem; color: var(--hud-primary);"><?= hudVal(
                                $r["n"] ?? "",
                            ) ?></span>
                        </div>

                        <div class="data-grid-2" style="margin-top: 10px;">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-user-tag" style="margin-right:4px;"></i> Username</span>
                                <span class="data-value editable" data-field="username" data-type="text">
                                    <?= hudVal($r["u"] ?? "") ?>
                                </span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-key" style="margin-right:4px;"></i> ID Number (Access Code)</span>
                                <span class="data-value editable" data-field="id_number" data-type="numeric">
                                    <?php
                                $access_code =
                                    $r["a"] ?? ($r["access_code"] ?? "");
                                if (!empty($access_code)) {
                                    $decrypted = SensitiveDataService::decrypt(
                                        $access_code,
                                    );
                                    echo hudVal($decrypted ?: "");
                                } else {
                                    echo hudVal("");
                                }
                                ?>
                                </span>
                            </div>
                        </div>

                        <div class="data-grid-3" style="margin-top: 10px;">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-calendar-alt" style="margin-right:4px;"></i> Date of Birth</span>
                                <span class="data-value editable" data-field="birth_date" data-type="date"><?= hudVal(
                                    $r["birth_date"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-hourglass-half" style="margin-right:4px;"></i> Age</span>
                                <span class="data-value editable" data-field="age" data-type="number"><?= hudVal(
                                    $r["age"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-flag" style="margin-right:4px;"></i> Nationality</span>
                                <span class="data-value editable" data-field="nationality" data-type="text"><?= hudVal(
                                    $r["nationality"] ?? "",
                                ) ?></span>
                            </div>
                        </div>

                        <div class="data-row" style="margin-top: 10px;">
                            <span class="data-label"><i class="fas fa-globe" style="margin-right:4px;"></i> Country (from phone)</span>
                            <div class="data-value">
                                <?php
                                $phone = $r["t"] ?? "";
                                $phoneClean = preg_replace('/\D/', '', (string) $phone) ?? '';
                                $flagMap = require_once __DIR__ . '/includes/table_country_flags.php';
                                $flagFile = 'stock.png';
                                if ($phoneClean !== '') {
                                    $sortedCodes = array_keys($flagMap);
                                    usort($sortedCodes, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
                                    foreach ($sortedCodes as $code) {
                                        if (str_starts_with($phoneClean, (string) $code)) {
                                            $candidate = $flagMap[$code];
                                            if (is_file(__DIR__ . '/../id/' . $candidate)) {
                                                $flagFile = $candidate;
                                            }
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <img src="../id/<?= htmlspecialchars($flagFile) ?>" alt="" width="40" height="30" style="border: 1px solid var(--hud-dim);">
                                    <span><?= htmlspecialchars($flagFile === 'stock.png' ? 'Unknown' : str_replace('.png', '', $flagFile)) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-heartbeat"></i> Physical Characteristics (CLICK TO EDIT)</div>

                        <div class="data-grid-3">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-ruler-vertical" style="margin-right:4px;"></i> Height</span>
                                <span class="data-value editable" data-field="height" data-type="number"><?= hudVal(
                                    $r["height"] ?? "",
                                ) ?> CM</span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-weight" style="margin-right:4px;"></i> Weight</span>
                                <span class="data-value editable" data-field="weight" data-type="number"><?= hudVal(
                                    $r["weight"] ?? "",
                                ) ?> KG</span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-tint" style="margin-right:4px;"></i> Blood Type</span>
                                <span class="data-value editable" data-field="blood_type" data-type="select"
                                    data-options='<?= json_encode([
                                    "A+",
                                    "A-",
                                    "B+",
                                    "B-",
                                    "AB+",
                                    "AB-",
                                    "O+",
                                    "O-",
                                ]) ?>'><?= hudVal(
    $r["blood_type"] ?? "",
) ?></span>
                            </div>
                        </div>

                        <div class="data-grid-2" style="margin-top: 10px;">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-palette" style="margin-right:4px;"></i> Skin Color</span>
                                <span class="data-value editable" data-field="skin_color" data-type="select"
                                    data-options='<?= json_encode([
                                    "Porcelain",
                                    SKIN_VERY_FAIR,
                                    "Fair",
                                    "Light",
                                    SKIN_LIGHT_MEDIUM,
                                    "Medium",
                                    "Olive",
                                    "Tan",
                                    "Deep",
                                    SKIN_VERY_DEEP,
                                ]) ?>'><?= hudVal(
    $r["skin_color"] ?? "",
) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-users"></i> Family Status (CLICK TO EDIT)</div>

                        <div class="data-grid-2">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-ring" style="margin-right:4px;"></i> Marital Status</span>
                                <span class="data-value editable" data-field="marital_status" data-type="select"
                                    data-options='<?= json_encode([
                                    "single",
                                    "married",
                                    "divorced",
                                    "widowed",
                                ]) ?>'><?= hudVal(
    $r["marital_status"] ?? "",
) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-child" style="margin-right:4px;"></i> Children Count</span>
                                <span class="data-value editable" data-field="children_count" data-type="number"><?= hudVal(
                                    $r["children_count"] ?? "",
                                ) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-file-contract"></i> Identification Documents (CLICK TO EDIT)</div>

                        <div class="data-grid-2">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-file-contract" style="margin-right:4px;"></i> Birth Certificate</span>
                                <span class="data-value editable" data-field="birth_cert" data-type="text"><?= hudVal(
                                    $r["birth_cert"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-certificate" style="margin-right:4px;"></i> Birth Cert. No.</span>
                                <span class="data-value editable" data-field="birth_certificate_number" data-type="text"><?= hudVal(
                                    $r["birth_certificate_number"] ?? "",
                                ) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-satellite-dish"></i> Contact Information (CLICK TO EDIT)</div>

                        <div class="data-grid-2">
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-envelope" style="margin-right:4px;"></i> Email</span>
                                <span class="data-value editable" data-field="email" data-type="email"><?= hudVal(
                                    $r["e"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label"><i class="fas fa-phone" style="margin-right:4px;"></i> Phone</span>
                                <span class="data-value editable" data-field="phone" data-type="tel"><?= hudVal(
                                    $r["t"] ?? "",
                                ) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-map-marked-alt"></i> Address Information (CLICK TO EDIT)</div>

                        <div class="data-grid-3">
                            <div class="data-row">
                                <span class="data-label">CITY</span>
                                <span class="data-value editable" data-field="city" data-type="text"><?= hudVal(
                                    $r["city"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">DISTRICT</span>
                                <span class="data-value editable" data-field="district" data-type="text"><?= hudVal(
                                    $r["district"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">POSTAL_CODE</span>
                                <span class="data-value editable" data-field="postal_code" data-type="text"><?= hudVal(
                                    $r["postal_code"] ?? "",
                                ) ?></span>
                            </div>
                        </div>

                        <div class="data-grid-3" style="margin-top: 10px;">
                            <div class="data-row">
                                <span class="data-label">STREET</span>
                                <span class="data-value editable" data-field="street" data-type="text"><?= hudVal(
                                    $r["street"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">BUILDING_NO</span>
                                <span class="data-value editable" data-field="building_number" data-type="text"><?= hudVal(
                                    $r["building_number"] ?? "",
                                ) ?></span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">APARTMENT_NO</span>
                                <span class="data-value editable" data-field="apartment_number" data-type="text"><?= hudVal(
                                    $r["apartment_number"] ?? "",
                                ) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="hud-panel">
                        <div class="hud-section-header"><i class="fas fa-network-wired"></i> Financial & Relations</div>
                        <div class="data-row" style="margin-bottom: 20px;">
                            <span class="data-label" style="color: var(--hud-primary);">
                                <i class="fas fa-users" style="margin-right: 5px;"></i> Relatives (CLICK TO EDIT)
                            </span>
                            <span class="data-value editable" data-field="relatives" data-type="text">
                                <?= hudVal($r["relatives"] ?? "") ?>
                            </span>
                            <span class="data-label" style="color: var(--hud-primary); font-size: 0.85rem;">
                                <i class="fas fa-car" style="margin-right: 5px;"></i> Vehicle Intel (CLICK TO EDIT)
                            </span>
                            <span class="data-value editable" data-field="personal_car_number" data-type="text"
                                style="font-size: 1.5rem; letter-spacing: 4px; font-family: 'Courier New', monospace; font-weight: bold; text-transform: uppercase; color: var(--hud-text);">
                                <?= hudVal($r["personal_car_number"] ?? "") ?>
                            </span>
                        </div>

                        <div>
                            <span class="data-label" style="color: var(--hud-primary);">Bank Information</span>
                            <div style="border-left: 2px solid var(--hud-dim); padding-left: 10px; margin-top: 5px;">
                                <?php
                            $bankAccountsData = $r["bank_accounts"] ?? "";
                            $bank_cards = [];
                            if ($bankAccountsData) {
                                $decrypted = SensitiveDataService::decrypt(
                                    $bankAccountsData,
                                );
                                if ($decrypted) {
                                    $bank_cards =
                                        json_decode($decrypted, true) ?? [];
                                }
                            }
                            if (!empty($bank_cards) && is_array($bank_cards)) {
                                foreach ($bank_cards as $card) {
                                    $bank = htmlspecialchars(
                                        $card["bank"] ?? "UNKNOWN_BANK",
                                    );
                                    $number = htmlspecialchars(
                                        $card["number"] ?? "####",
                                    );
                                    echo '<div style="margin-bottom: 5px;">';
                                    echo '<span style="color:var(--hud-secondary); font-weight:bold;">[' .
                                        $bank .
                                        "]</span> " .
                                        $number;
                                    // Expand bank details if available
                                    $extra = [];
                                    if (!empty($card["name"])) {
                                        $extra[] =
                                            "HOLDER: " .
                                            htmlspecialchars($card["name"]);
                                    }
                                    if (!empty($card["expiry"])) {
                                        $extra[] =
                                            "EXP: " .
                                            htmlspecialchars($card["expiry"]);
                                    }
                                    if (!empty($card["cvv"])) {
                                        $extra[] = "CVV: ***";
                                    }
                                    if (!empty($extra)) {
                                        echo '<div style="font-size:0.8em; opacity:0.7; padding-left:10px;">' .
                                            implode(" | ", $extra) .
                                            "</div>";
                                    }
                                    echo "</div>";
                                }
                            } else {
                                echo '<span class="hud-null">NO FINANCIAL RECORDS</span>';
                            }
                            ?>
                            </div>
                            <div class="data-row" style="margin-top: 10px;">
                                <span class="data-label">SOCIAL_MEDIA_LINKS (CLICK TO EDIT)</span>
                                <span class="data-value editable" data-field="social_media" data-type="textarea"
                                    style="font-family: monospace; font-size: 0.8rem;">
                                    <?php
                                $social_media = $r["social_media"] ?? "";
                                // Handle both JSON array and string formats
                                if (
                                    is_string($social_media) &&
                                    !empty($social_media)
                                ) {
                                    $decoded = json_decode($social_media, true);
                                    if (
                                        json_last_error() === JSON_ERROR_NONE &&
                                        is_array($decoded)
                                    ) {
                                        echo hudVal(implode("\n", $decoded));
                                    } else {
                                        echo hudVal($social_media);
                                    }
                                } elseif (
                                    is_array($social_media) &&
                                    !empty($social_media)
                                ) {
                                    echo hudVal(implode("\n", $social_media));
                                } else {
                                    echo '<span class="hud-null">NO DATA</span>';
                                }
                                ?>
                                </span>
                            </div>
                            <div class="data-row" style="margin-top: 10px;">
                                <span
                                    class="data-label">-------------------------------------------------------------</span>
                                <span class="data-label">DATES (SUBMISSION / APPROVAL / UPDATE)</span>
                                <div class="data-value" style="font-size: 0.85rem;">
                                    <div class="data-grid-3" style="margin-top: 10px;">
                                        <div class="data-row">
                                            <span class="data-label">SUBMISSION_DATE</span>
                                            <span class="data-value"><?= hudVal(
                                            $r["submission_date"] ?? "",
                                        ) ?></span>
                                        </div>
                                        <div class="data-row">
                                            <span class="data-label">APPROVAL_DATE</span>
                                            <span class="data-value"><?= hudVal(
                                            $r["approval_date"] ?? "",
                                        ) ?></span>
                                        </div>
                                        <div class="data-row">
                                            <span class="data-label">CREATED_AT</span>
                                            <span class="data-value"><?= hudVal(
                                            $r["created_at"] ?? "",
                                        ) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hud-actions">
                <a href="view_purchase?id=<?= $purchase_id ?>" class="hud-btn back">
                    <i class="fas fa-chevron-left"></i> ABORT / RETURN
                </a>

                <button type="submit" name="save_changes" class="hud-btn save">
                    <i class="fas fa-save"></i> COMMIT_CHANGES
                </button>
            </div>

        </form>

    </div>

    <div id="lightboxModal" class="lightbox-modal">
        <div class="lightbox-content">
            <img id="lightboxImage" class="lightbox-image" src="" alt="Lightbox">
            <div id="lightboxCaption" class="lightbox-caption"></div>
        </div>
        <button type="button"
            style="position: absolute; top: 20px; right: 20px; font-size: 2rem; color: var(--hud-primary); cursor: pointer; background:none; border:none; padding:0; line-height:1;"
            onclick="closeLightbox()" aria-label="Close Lightbox">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <script>
    // Lightbox Functionality
    function openLightbox(src, cap) {
        document.getElementById('lightboxImage').src = src;
        document.getElementById('lightboxCaption').textContent = cap;
        document.getElementById('lightboxModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        document.getElementById('lightboxModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    document.getElementById('lightboxModal').addEventListener('click', function(e) {
        if (e.target === this) closeLightbox();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeLightbox();
    });

    // Client-side validation with field limits
    const fieldLimits = {
        username: { max: 50, type: 'alphanumeric', min: 3 },
        name: { max: 255, type: 'name', min: 2 },
        email: { max: 255, type: 'email' },
        phone: { max: 20, type: 'phone' },
        id_number: { max: 16, type: 'numeric', exact: 16 },
        birth_cert: { max: 50, type: 'alphanumeric' },
        birth_certificate_number: { max: 30, type: 'alphanumeric' },
        nationality: { max: 50, type: 'name' },
        marital_status: { max: 20, type: 'enum', values: ['single', 'married', 'divorced', 'widowed'] },
        birth_date: { type: 'date' },
        age: { max: 3, type: 'number', min: 0, max_val: 150 },
        blood_type: { max: 3, type: 'enum', values: ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] },
        height: { max: 3, type: 'number', min: 50, max_val: 250 },
        weight: { max: 3, type: 'number', min: 20, max_val: 300 },
        skin_color: { max: 20, type: 'enum', values: ['Porcelain', '<?= SKIN_VERY_FAIR ?>', 'Fair', 'Light', '<?= SKIN_LIGHT_MEDIUM ?>', 'Medium', 'Olive', 'Tan', 'Deep', '<?= SKIN_VERY_DEEP ?>'] },
        city: { max: 100, type: 'name' },
        district: { max: 100, type: 'name' },
        street: { max: 200, type: 'address' },
        building_number: { max: 10, type: 'alphanumeric' },
        apartment_number: { max: 10, type: 'alphanumeric' },
        postal_code: { max: 20, type: 'alphanumeric' },
        relatives: { max: 500, type: 'text' },
        children_count: { max: 2, type: 'number', min: 0, max_val: 50 },
        personal_car_number: { max: 17, type: 'alphanumeric' },
        social_media: { max: 1000, type: 'text' }
    };

    function validateField(field, value) {
        const rules = fieldLimits[field];
        if (!rules) return { valid: true };

        // XSS Protection - Strip HTML tags
        value = value.toString().replace(/<[^>]*>/g, '');

        // Remove JavaScript event handlers and protocols
        value = value.replace(/on\w+\s*=\s*["']?[^"']*["']?/gi, '');
        value = value.replace(/javascript\s*:/gi, '');

        switch (rules.type) {
            case 'numeric':
                value = value.replace(/[^0-9]/g, '');
                if (rules.exact && value.length !== rules.exact) {
                    return { valid: false, message: `Must be exactly ${rules.exact} digits` };
                }
                break;
            case 'alphanumeric':
                value = value.replace(/[^a-zA-Z0-9]/g, '');
                break;
            case 'name':
                value = value.replace(/[^a-zA-Z\s\-'\.]/g, '');
                break;
            case 'email':
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    return { valid: false, message: 'Invalid email format' };
                }
                break;
            case 'phone':
                value = value.replace(/[^0-9+\-\s\(\)]/g, '');
                break;
            case 'address':
                value = value.replace(/[^a-zA-Z0-9\s\-\.\,\#]/g, '');
                break;
            case 'enum':
                if (!rules.values.includes(value)) {
                    return { valid: false, message: `Must be one of: ${rules.values.join(', ')}` };
                }
                break;
            case 'date':
                const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
                if (!dateRegex.test(value) || isNaN(Date.parse(value))) {
                    return { valid: false, message: 'Invalid date format (YYYY-MM-DD)' };
                }
                break;
            case 'number':
                const num = parseInt(value);
                if (isNaN(num)) {
                    return { valid: false, message: 'Must be a number' };
                }
                if (rules.min !== undefined && num < rules.min) {
                    return { valid: false, message: `Value must be at least ${rules.min}` };
                }
                if (rules.max_val !== undefined && num > rules.max_val) {
                    return { valid: false, message: `Value must not exceed ${rules.max_val}` };
                }
                value = num.toString();
                break;
            case 'text':
                value = value.replace(/[<>"']/g, '');
                break;
        }

        // Length validation - only for string types, not numeric values
        if (rules.type !== 'number') {
            if (rules.max && value.length > rules.max) {
                return { valid: false, message: `Maximum ${rules.max} characters` };
            }
            if (rules.min && value.length < rules.min) {
                return { valid: false, message: `Minimum ${rules.min} characters` };
            }
        }

        return { valid: true, cleanedValue: value };
    }

    // Form submission validation
    document.getElementById('hudEditForm').addEventListener('submit', function(e) {
        const errors = [];

        // Ensure any active editing field is saved before submission
        const activeInput = this.querySelector('.data-value.editing input, .data-value.editing textarea, .data-value.editing select');
        if (activeInput) {
            // Trigger blur to save the current edit
            activeInput.blur();
        }

        // Validate all hidden inputs (edited fields)
        const hiddenInputs = this.querySelectorAll('input[type="hidden"]');
        hiddenInputs.forEach(input => {
            const validation = validateField(input.name, input.value);
            if (!validation.valid) {
                errors.push(`${input.name}: ${validation.message}`);
            } else if (validation.cleanedValue !== undefined) {
                input.value = validation.cleanedValue;
            }
        });

        if (errors.length > 0) {
            e.preventDefault();
            alert('Validation errors:\n' + errors.join('\n'));
            return false;
        }
    });

    // Inline Edit Functionality
    document.querySelectorAll('.data-value.editable').forEach(elem => {
        elem.addEventListener('click', function() {
            if (this.classList.contains('editing')) return;

            const field = this.dataset.field;
            const type = this.dataset.type;
            const currentValue = this.textContent.trim().replace(' CM', '').replace(' KG', '');

            this.classList.add('editing');
            const originalContent = this.innerHTML;

            let input;
            if (type === 'select') {
                input = document.createElement('select');
                input.name = field;
                const options = JSON.parse(this.dataset.options || '[]');
                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt;
                    option.textContent = opt;
                    if (opt === currentValue) option.selected = true;
                    input.appendChild(option);
                });
            } else if (type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 3;
                input.name = field;
                input.value = currentValue === 'N/A' ? '' : currentValue;

                // Add validation attributes
                const rules = fieldLimits[field];
                if (rules && rules.max) input.maxLength = rules.max;
            } else {
                input = document.createElement('input');

                // Handle numeric type - use text input with numeric restrictions
                if (type === 'numeric') {
                    input.type = 'text';
                    input.inputMode = 'numeric';
                    input.pattern = '[0-9]*';
                } else {
                    input.type = type;
                }

                input.name = field;
                input.value = currentValue === 'N/A' ? '' : currentValue;

                // Add HTML5 validation attributes
                const rules = fieldLimits[field];
                if (rules) {
                    if (rules.max) input.maxLength = rules.max;

                    // Fixed: Only set minLength if it's less than or equal to current maxLength (avoid IndexSizeError)
                    // and only if the type supports it.
                    if (rules.min && (type === 'text' || type === 'email' || type === 'password' || type === 'tel' || type === 'url')) {
                        // Some inputs might have default maxLength from browser, ensure we don't exceed it
                        const safeMaxLength = rules.max || input.maxLength || 524288;
                        if (rules.min <= safeMaxLength) {
                            input.minLength = rules.min;
                        }
                    }

                    if (rules.type === 'number') {
                        input.min = rules.min || 0;
                        input.max = rules.max_val || 999;
                    }
                    if (rules.type === 'email') input.pattern = '[^\\s@]+@[^\\s@]+\\.[^\\s@]+';
                    if (rules.type === 'numeric' && rules.exact) {
                        input.maxLength = rules.exact;
                        input.minLength = rules.exact;
                        input.pattern = '[0-9]{' + rules.exact + '}';
                    }
                }

                // Prevent non-numeric input for numeric and number fields
                if (type === 'numeric' || type === 'number') {
                    input.addEventListener('input', function(e) {
                        this.value = this.value.replace(/[^0-9]/g, '');
                    });
                    input.addEventListener('keydown', function(e) {
                        // Allow: backspace, delete, tab, escape, enter
                        if ([8, 9, 27, 13].indexOf(e.keyCode) !== -1 ||
                            // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                            (e.ctrlKey || e.metaKey) && [65, 67, 86, 88].indexOf(e.keyCode) !== -1) {
                            return;
                        }
                        // Ensure that it is a number and stop the keypress
                        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                            e.preventDefault();
                        }
                    });
                }
            }

            this.innerHTML = '';
            this.appendChild(input);
            input.focus();

            let isSaving = false;

            const saveEdit = () => {
                if (isSaving) return;

                const newValue = input.value;

                // Validate the field before saving
                const validation = validateField(field, newValue);
                if (!validation.valid) {
                    // Visual Error Handling: Keep editing mode, highlight the field
                    this.classList.add('error-field');

                    // Remove existing popup if any
                    const existingPopup = this.querySelector('.error-message-popup');
                    if (existingPopup) existingPopup.remove();

                    const popup = document.createElement('div');
                    popup.className = 'error-message-popup';
                    popup.textContent = validation.message;
                    this.appendChild(popup);

                    input.focus();
                    return;
                }

                isSaving = true;
                const finalValue = validation.cleanedValue || newValue;
                this.classList.remove('editing', 'error-field');

                // Remove popup if exists
                const popup = this.querySelector('.error-message-popup');
                if (popup) popup.remove();

                // Remove the input first to avoid DOM conflicts
                if (input.parentNode === this) {
                    this.removeChild(input);
                }

                // Create or update hidden form input for submission
                let hiddenInput = document.querySelector(`input[name="${field}"]`);
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = field;
                    document.getElementById('hudEditForm').appendChild(hiddenInput);
                }
                hiddenInput.value = finalValue;

                if (finalValue && finalValue !== 'N/A') {
                    this.innerHTML = finalValue;
                    if (field === 'height') this.innerHTML += ' CM';
                    if (field === 'weight') this.innerHTML += ' KG';
                } else {
                    this.innerHTML = '<span class="hud-null">N/A</span>';
                }
            };

            input.addEventListener('blur', saveEdit);
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && type !== 'textarea') {
                    e.preventDefault();
                    saveEdit();
                }
                if (e.key === 'Escape') {
                    if (isSaving) return; // Prevent if already saving
                    isSaving = true;

                    // Remove the input first to avoid DOM conflicts
                    if (input.parentNode === this) {
                        this.removeChild(input);
                    }

                    // Remove any hidden input for this field since user cancelled
                    const hiddenInput = document.querySelector(`input[name="${field}"]`);
                    if (hiddenInput) {
                        hiddenInput.remove();
                    }

                    this.innerHTML = originalContent;
                    this.classList.remove('editing');
                }
            });
        });
    });
    </script>

    <?php PageController::end($base_path); ?>

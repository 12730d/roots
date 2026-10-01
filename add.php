<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Services\ValidationService;
use ROOTS\Services\SensitiveDataService;
use ROOTS\Security\CsrfProtection;
use ROOTS\Exceptions\RegistrationException;
use ROOTS\Exceptions\DatabaseException;

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
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
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

$isBlockedUser = false;
try {
    Session::start();
    if (Session::isLoggedIn() && !empty($_SESSION["user_id"])) {
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
} catch (Exception $e) {
    appLogError("Error checking user ban status: " . $e->getMessage());
}

// Get database connection first (without rendering layout)
try {
    $con = Database::getConnection();
} catch (Exception $e) {
    appLogError("Database connection failed: " . $e->getMessage());
    $con = null;
}

// Pending Request Check - MUST happen before any output
if (isset($_SESSION["username"]) && isset($con)) {
    try {
        $current_username = $_SESSION["username"];
        $check_pending =
            "SELECT id FROM pending_records WHERE submitted_by = ? AND status = 'pending' LIMIT 1";
        $stmt = mysqli_prepare($con, $check_pending);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $current_username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $pending_record = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if ($pending_record) {
                $_SESSION[
                    "message"
                ] = "SYSTEM HALTED: Pending request detected (ID: {$pending_record["id"]}). Clearance denied until review.";
                $_SESSION["messageType"] = "warning";
                header("Location: pending_requests");
                exit();
            }
        }
    } catch (Exception $e) {
        appLogError("Error checking pending: " . $e->getMessage());
    }
}

// Initialize PageController after checks
$pageData = PageController::setup(
    "Data Uplink Terminal",
    "./",
    [],
    ["require_login" => true, "render_layout" => true, "show_navbar" => true],
);

$user_data = $pageData["user"];

// System Constants
define("TIMEZONE", "UTC");
define("DATETIME_FORMAT", "Y-m-d H:i:s");
define("JPEG_MIME_TYPE", "image/jpeg");

//-- عايز اضيف نظام حمايه صارم مثل ما طبقته على هذا الملف add.php وا edit_purchase.php  اريد تطبيق كل شي على هذا الملفات ايضا ايضا view_purchase.php ----//--
// Custom exception classes (Local fallback if namespaced ones are not used specifically)
// Redefining them here is not needed if we use the imported ones, but keeping for compatibility if logic depends on them
class TransactionException extends RuntimeException {}

// --- HELPER FUNCTIONS ---

/**
 * Securely validate and format date input
 *
 * @param string $date_input The date string to validate
 * @param string $min_date Minimum allowed date (default: 1900-01-01)
 * @param string $max_date Maximum allowed date (default: today)
 * @return string|null Validated date in Y-m-d format or null if invalid
 */
function validateSecureDate(
    $date_input,
    $min_date = "1900-01-01",
    $max_date = null,
) {
    if (empty($date_input)) {
        return null;
    }

    // Set max_date to today if not specified
    if ($max_date === null) {
        $max_date = date("Y-m-d");
    }

    $timestamp = strtotime($date_input);
    $min_timestamp = strtotime($min_date);
    $max_timestamp = strtotime($max_date);

    // Validate: date is parseable and within range
    if (
        $timestamp === false ||
        $timestamp < $min_timestamp ||
        $timestamp > $max_timestamp
    ) {
        return null;
    }

    // Return validated and formatted date
    return date("Y-m-d", $timestamp);
}

/**
 * Server-side Luhn algorithm validation for card numbers
 */
function validateLuhnServer(string $cardNumber): bool
{
    $digits = preg_replace("/\D/", "", $cardNumber) ?? '';
    if (strlen($digits) < 13 || strlen($digits) > 19) {
        return false;
    }

    $sum = 0;
    $isEven = false;

    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $digit = (int) $digits[$i];

        if ($isEven) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }

        $sum += $digit;
        $isEven = !$isEven;
    }

    return $sum % 10 === 0;
}

/**
 * @param array<mixed> $arr
 * @return array<mixed>
 */
function refValues(array $arr): array
{
    $refs = [];
    foreach ($arr as $key => $value) {
        $refs[$key] = &$arr[$key];
    }
    return $refs;
}

/**
 * Securely compress and validate image data
 *
 * @param string $imageData The raw image data
 * @param int $maxWidth Maximum width in pixels
 * @param int $maxHeight Maximum height in pixels
 * @param int $quality JPEG quality (0-100)
 * @return string|null The compressed image data or null on failure
 */
function compressImage(
    string $imageData,
    int $maxWidth = 1000,
    int $maxHeight = 1000,
    int $quality = 80,
): ?string {
    // Basic entropy check or size check
    if (strlen($imageData) > 5 * 1024 * 1024) {
        // 5MB limit
        throw new RegistrationException(
            "SYSTEM_ERROR: File payload exceeds maximum density limits.",
        );
    }

    if (strlen($imageData) < 50000) {
        // Small images don't need compression
        return $imageData;
    }

    $image = @imagecreatefromstring($imageData);
    if (!$image) {
        // Not a valid image or unsupported format
        return null;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
    $newWidth = (int) ($width * $ratio);
    $newHeight = (int) ($height * $ratio);

    $newImage = imagecreatetruecolor(max(1, $newWidth), max(1, $newHeight));
    if (function_exists("imagesetinterpolation")) {
        imagesetinterpolation($newImage, IMG_BILINEAR_FIXED);
    }

    imagealphablending($newImage, false);
    imagesavealpha($newImage, true);
    imagecopyresampled(
        $newImage,
        $image,
        0,
        0,
        0,
        0,
        $newWidth,
        $newHeight,
        $width,
        $height,
    );

    ob_start();
    imagejpeg($newImage, null, $quality);
    $compressedData = ob_get_contents();
    ob_end_clean();

    imagedestroy($image);
    imagedestroy($newImage);

    return $compressedData !== false ? $compressedData : null;
}

date_default_timezone_set(TIMEZONE);

// Initialize Message
$message = "";
/** @var string $messageType Can be 'error' or 'success' */
$messageType = "error"; // Default to error, can be set to 'success' in successful flows

// --- FORM SUBMISSION HANDLER ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        // Enforce CSRF protection
        CsrfProtection::requireToken();

        if (!($con instanceof \mysqli)) {
            throw new TransactionException("Database connection not available");
        }

        if (!mysqli_begin_transaction($con)) {
            throw new TransactionException("Transaction init failed");
        }

        // Validate Required
        $required_fields = ["username", "name"];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new RegistrationException(
                    "CRITICAL ERROR: Mandatory node '$field' not detected.",
                );
            }
        }

        // Validate and Sanitize Data using ValidationService
        $data = [];

        // Required fields with enhanced validation

        $data["username"] = ValidationService::sanitizeString(
            trim($_POST["username"] ?? ""),

            30,
        );

        if (
            strlen($data["username"]) < 3 ||
            !preg_match('/^[a-zA-Z0-9_@.-]+$/', $data["username"])
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Username must be 3-30 characters, letters, numbers, _ @ . - only.",
            );
        }

        $data["name"] = ValidationService::sanitizeString(
            $_POST["name"] ?? "",
            80,
        );

        if (
            strlen($data["name"]) < 2 ||
            !preg_match('/^[a-zA-Z\s\'-]+$/', $data["name"])
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Name must be 2-80 characters, letters, spaces, hyphens and apostrophes only.",
            );
        }

        // Optional fields with enhanced validation

        $data["email"] = !empty($_POST["email"])
            ? ValidationService::validateEmail(trim($_POST["email"]), "email")
            : "";

        if (!empty($data["email"]) && strlen($data["email"]) > 80) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Email exceeds maximum length of 80 characters.",
            );
        }

        $data["phone"] = !empty($_POST["phone"])
            ? ValidationService::validatePhone(trim($_POST["phone"]), "phone")
            : "";

        if (
            !empty($data["phone"]) &&
            !preg_match('/^[+]?[0-9\s\-\(\)]{7,18}$/', $data["phone"])
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Phone must be 7-18 characters, numbers, spaces, +, -, () only.",
            );
        }

        $data["birth_cert"] = ValidationService::sanitizeString(
            trim($_POST["birth_cert"] ?? ""),
            25,
        );
        if (!empty($data["birth_cert"]) && strlen($data["birth_cert"]) < 5) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Birth certificate must be 5-25 characters.",
            );
        }

        $data["nationality"] = ValidationService::sanitizeString(
            trim($_POST["nationality"] ?? ""),
            100,
        );

        $data["relatives"] = ValidationService::sanitizeString(
            trim($_POST["relatives"] ?? ""),
            200,
        );
        $data["blood_type"] = !empty($_POST["blood_type"])
            ? ValidationService::validateEnum(
                trim($_POST["blood_type"]),
                ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"],
                "blood_type",
            )
            : "";
        $data["city"] = ValidationService::sanitizeString(
            trim($_POST["city"] ?? ""),
            40,
        );
        if (
            !empty($data["city"]) &&
            (strlen($data["city"]) < 2 ||
                !preg_match('/^[a-zA-Z\s\'-]+$/', $data["city"]))
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: City must be 2-40 characters, letters, spaces, hyphens and apostrophes only.",
            );
        }

        $data["district"] = ValidationService::sanitizeString(
            trim($_POST["district"] ?? ""),
            40,
        );
        if (
            !empty($data["district"]) &&
            (strlen($data["district"]) < 2 ||
                !preg_match('/^[a-zA-Z0-9\s\'-]+$/', $data["district"]))
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: District must be 2-40 characters, letters, numbers, spaces, hyphens and apostrophes only.",
            );
        }

        $data["street"] = ValidationService::sanitizeString(
            trim($_POST["street"] ?? ""),
            80,
        );
        if (!empty($data["street"]) && strlen($data["street"]) < 3) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Street must be 3-80 characters.",
            );
        }

        $data["building_number"] = ValidationService::sanitizeString(
            trim($_POST["building_number"] ?? ""),
            8,
        );

        $data["apartment_number"] = ValidationService::sanitizeString(
            trim($_POST["apartment_number"] ?? ""),
            8,
        );

        $data["postal_code"] = ValidationService::sanitizeString(
            trim($_POST["postal_code"] ?? ""),
            12,
        );
        if (!empty($data["postal_code"]) && strlen($data["postal_code"]) < 3) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Postal code must be 3-12 characters.",
            );
        }
        $data["marital_status"] = !empty($_POST["marital_status"])
            ? ValidationService::validateEnum(
                strtolower(trim($_POST["marital_status"])),
                ["single", "married", "divorced", "widowed"],
                "marital_status",
            )
            : "";
        $data["children_count"] = ValidationService::validateInt(
            $_POST["children_count"] ?? 0,
            "children_count",
            0,
            20,
        );

        $data["id_number"] = ValidationService::sanitizeString(
            trim($_POST["id_number"] ?? ""),
            30,
        );
        if (
            !empty($data["id_number"]) &&
            (strlen($data["id_number"]) < 8 ||
                !preg_match('/^[a-zA-Z0-9-]+$/', $data["id_number"]))
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: ID number must be 8-30 characters, letters, numbers and hyphens only.",
            );
        }
        $data["birth_certificate_number"] = $data["id_number"]; // Copy as per original logic
        $skinColorRaw = trim($_POST["skin_color"] ?? "");
        if ($skinColorRaw === "Olive/Tan") {
            $skinColorRaw = "Olive";
        }
        $data["skin_color"] = !empty($skinColorRaw)
            ? ValidationService::validateEnum(
                $skinColorRaw,
                [
                    "Porcelain",
                    "Very Fair",
                    "Fair",
                    "Light",
                    "Light Medium",
                    "Medium",
                    "Olive",
                    "Tan",
                    "Deep",
                    "Very Deep",
                ],
                "skin_color",
            )
            : "";
        $data["subscription"] = ValidationService::sanitizeString(
            trim($_POST["subscription"] ?? "Basic"),
            20,
        );
        $data["personal_car_number"] = ValidationService::sanitizeString(
            trim($_POST["personal_car_number"] ?? ""),
            20,
        );

        if (
            !empty($data["personal_car_number"]) &&
            (strlen($data["personal_car_number"]) < 3 ||
                !preg_match(
                    '/^[a-zA-Z0-9\s-]+$/',
                    $data["personal_car_number"],
                ))
        ) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Car plate must be 3-20 characters, letters, numbers, spaces and hyphens only.",
            );
        }

        // Numeric fields with range validation (Synced with HTML limits)
        $data["height"] = !empty($_POST["height"])
            ? ValidationService::validateInt(
                trim($_POST["height"]),
                "height",
                0,
                300,
            )
            : null;
        $data["weight"] = !empty($_POST["weight"])
            ? ValidationService::validateInt(
                trim($_POST["weight"]),
                "weight",
                0,
                1000,
            )
            : null;
        $data["age"] = !empty($_POST["age"])
            ? ValidationService::validateInt(trim($_POST["age"]), "age", 0, 150)
            : null;

        // Sensitive data encryption
        $data["access_code"] = SensitiveDataService::encrypt(
            $data["id_number"],
        );

        // Specific Formatting with Security Validation
        $data["birth_date"] = validateSecureDate(
            trim($_POST["birth_date"] ?? null),
        );
        if (!empty($_POST["birth_date"]) && $data["birth_date"] === null) {
            throw new RegistrationException(
                "INVALID_SEQUENCE: Date of emergence out of allowed chrono-range.",
            );
        }

        // Address Construction
        $addr_parts = array_filter([
            $data["city"],
            $data["district"],
            $data["street"],
            $data["building_number"] ? "Bldg " . $data["building_number"] : "",
            $data["apartment_number"] ? "Apt " . $data["apartment_number"] : "",
            $data["postal_code"] ? "ZIP " . $data["postal_code"] : "",
        ]);
        $data["address"] = implode(", ", $addr_parts);

        // JSON Fields with validation and encryption
        $socialMediaRaw =
            isset($_POST["social_media"]) && is_array($_POST["social_media"])
                ? array_slice($_POST["social_media"], 0, 5)
                : [];
        $socialMediaArray = array_values(
            array_filter(
                array_map(
                    fn($v) => ValidationService::sanitizeString(trim($v), 150),
                    $socialMediaRaw,
                ),
                fn($v) => $v !== "",
            ),
        );
        $data["social_media"] = json_encode(
            $socialMediaArray,
            JSON_UNESCAPED_UNICODE,
        );

        // Encrypt bank accounts data with enhanced validation
        $bankCardsArray =
            isset($_POST["bank_cards"]) && is_array($_POST["bank_cards"])
                ? $_POST["bank_cards"]
                : [];
        $validatedBankCards = [];
        foreach ($bankCardsArray as $card) {
            if (
                isset($card["number"]) &&
                isset($card["expiry"]) &&
                isset($card["cvv"])
            ) {
                $cardNumber = preg_replace("/\D/", "", trim($card["number"])) ?? '';

                // Server-side Luhn algorithm validation
                if (!validateLuhnServer($cardNumber)) {
                    throw new RegistrationException(
                        "INVALID_SEQUENCE: Invalid card number format.",
                    );
                }

                $expiryDate = trim($card["expiry"]);
                // Validate expiry date
                if (
                    !preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiryDate)
                ) {
                    throw new RegistrationException(
                        "INVALID_SEQUENCE: Invalid expiry date format. Use MM/YY.",
                    );
                }

                $cvv = trim($card["cvv"]);
                // Validate CVV
                if (!preg_match('/^\d{3,4}$/', $cvv)) {
                    throw new RegistrationException(
                        "INVALID_SEQUENCE: CVV must be 3-4 digits.",
                    );
                }

                $validatedBankCards[] = [
                    "number" => ValidationService::sanitizeString(
                        trim($card["number"]),
                        19,
                    ),
                    "expiry" => ValidationService::sanitizeString(
                        $expiryDate,
                        5,
                    ),
                    "cvv" => ValidationService::sanitizeString($cvv, 4),
                ];
            }
        }
        $encodedCards = json_encode($validatedBankCards, JSON_UNESCAPED_UNICODE);
        if ($encodedCards === false) {
            throw new TransactionException("JSON encoding of bank cards failed.");
        }
        $data["bank_accounts"] = SensitiveDataService::encrypt($encodedCards);

        if (!empty($data["id_number"])) {
            $data["birth_certificate_number"] = $data["id_number"];
        }

        // File Handling with Strict Security Checks
        $profile_image = null;
        $profile_meta = null;
        $person_photo = null;
        $person_meta = null;
        $id_card = null;
        $id_meta = null;

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $allowedImageTypes = ["image/jpeg", "image/png", "image/webp"];

        // Profile Image
        if (
            isset($_FILES["profile_image"]) &&
            $_FILES["profile_image"]["error"] === 0
        ) {
            // Security: Verify file was actually uploaded
            if (!is_uploaded_file($_FILES["profile_image"]["tmp_name"])) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: PROFILE_IMG upload verification failed.",
                );
            }

            if ($_FILES["profile_image"]["size"] > 2 * 1024 * 1024) {
                // 2MB limit
                throw new RegistrationException(
                    "SYSTEM_ERROR: PROFILE_IMG exceeds size limit.",
                );
            }
            $mime = $finfo->file($_FILES["profile_image"]["tmp_name"]);
            if (!in_array($mime, $allowedImageTypes, true)) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: PROFILE_IMG format invalid.",
                );
            }
            $raw = file_get_contents($_FILES["profile_image"]["tmp_name"]);
            /** @phpstan-ignore-next-line */
            $profile_image = compressImage($raw, 400, 400, 80);
            if ($profile_image === null) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: PROFILE_IMG processing failed.",
                );
            }
            $profile_meta = json_encode([
                "name" => basename($_FILES["profile_image"]["name"]),
                "size" => strlen($profile_image),
                "type" => $mime,
            ]);
        }

        // Person Photo
        if (
            isset($_FILES["person_photo"]) &&
            $_FILES["person_photo"]["error"] === 0
        ) {
            // Security: Verify file was actually uploaded
            if (!is_uploaded_file($_FILES["person_photo"]["tmp_name"])) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: FULL_BODY_SCAN upload verification failed.",
                );
            }

            if ($_FILES["person_photo"]["size"] > 5 * 1024 * 1024) {
                // 5MB limit
                throw new RegistrationException(
                    "SYSTEM_ERROR: FULL_BODY_SCAN exceeds size limit.",
                );
            }
            $mime = $finfo->file($_FILES["person_photo"]["tmp_name"]);
            if (!in_array($mime, $allowedImageTypes, true)) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: FULL_BODY_SCAN format invalid.",
                );
            }
            $raw = file_get_contents($_FILES["person_photo"]["tmp_name"]);
            /** @phpstan-ignore-next-line */
            $person_photo = compressImage($raw, 600, 800, 85);
            if ($person_photo === null) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: FULL_BODY_SCAN processing failed.",
                );
            }
            $person_meta = json_encode([
                "name" => basename($_FILES["person_photo"]["name"]),
                "size" => strlen($person_photo),
                "type" => $mime,
            ]);
        }

        // ID Card
        if (isset($_FILES["id_card"]) && $_FILES["id_card"]["error"] === 0) {
            // Security: Verify file was actually uploaded
            if (!is_uploaded_file($_FILES["id_card"]["tmp_name"])) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: ID_DOC_SCAN upload verification failed.",
                );
            }

            if ($_FILES["id_card"]["size"] > 10 * 1024 * 1024) {
                // 10MB limit
                throw new RegistrationException(
                    "SYSTEM_ERROR: ID_DOC_SCAN exceeds size limit.",
                );
            }
            $mime = $finfo->file($_FILES["id_card"]["tmp_name"]);
            $allowedIdTypes = array_merge($allowedImageTypes, [
                "application/pdf",
            ]);
            if (!in_array($mime, $allowedIdTypes, true)) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: ID_DOC_SCAN format invalid.",
                );
            }
            $raw = file_get_contents($_FILES["id_card"]["tmp_name"]);
            if ($raw === false) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: ID_DOC_SCAN read failed.",
                );
            }
            if (strpos($mime, "image/") === 0) {
                $id_card = compressImage($raw, 800, 1200, 90);
                if ($id_card === null) {
                    throw new RegistrationException(
                        "SYSTEM_ERROR: ID_DOC_SCAN processing failed.",
                    );
                }
            } else {
                $id_card = $raw; // Keep PDF
            }
            $id_meta = json_encode([
                "name" => basename($_FILES["id_card"]["name"]),
                "size" => strlen($id_card),
                "type" => $mime,
            ]);
        }

        // Driving License
        $driving_license = null;
        $driving_meta = null;
        if (
            isset($_FILES["driving_license"]) &&
            $_FILES["driving_license"]["error"] === 0
        ) {
            if ($_FILES["driving_license"]["size"] > 5 * 1024 * 1024) {
                // 5MB limit
                throw new RegistrationException(
                    "SYSTEM_ERROR: LICENSE_IMG exceeds size limit.",
                );
            }
            $mime = $finfo->file($_FILES["driving_license"]["tmp_name"]);
            if (!in_array($mime, $allowedImageTypes, true)) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: LICENSE_IMG format invalid.",
                );
            }
            $raw = file_get_contents($_FILES["driving_license"]["tmp_name"]);
            /** @phpstan-ignore-next-line */
            $driving_license = compressImage($raw, 800, 1200, 90);
            if ($driving_license === null) {
                throw new RegistrationException(
                    "SYSTEM_ERROR: LICENSE_IMG processing failed.",
                );
            }
            $driving_meta = json_encode([
                "name" => basename($_FILES["driving_license"]["name"]),
                "size" => strlen($driving_license),
                "type" => $mime,
            ]);
        }

        // DB Insert
        $columns = [
            "u",
            "n",
            "e",
            "t",
            "a",
            "address",
            "birth_cert",
            "nationality",
            "relatives",
            "blood_type",
            "social_media",
            "bank_accounts",
            "profile_image",
            "profile_image_metadata",
            "person_photo",
            "person_photo_metadata",
            "id_card_file",
            "id_card_metadata",
            "city",
            "district",
            "street",
            "building_number",
            "apartment_number",
            "postal_code",
            "birth_certificate_number",
            "marital_status",
            "children_count",
            "birth_date",
            "subscription",
            "points",
            "created_at",
            "updated_at",
            "submitted_by",
            "submission_date",
            "status",
            "added_by",
            "height",
            "weight",
            "age",
            "skin_color",
            "personal_car_number",
            "driving_license_image",
            "driving_license_metadata",
        ];

        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $sql =
            "INSERT INTO pending_records (" .
            implode(", ", $columns) .
            ") VALUES ($placeholders)";

        $stmt = mysqli_prepare($con, $sql);
        if (!$stmt) {
            throw new DatabaseException(
                "Query Prep Failed: " . mysqli_error($con),
            );
        }

        // Values Preparation
        $vals = [];
        $types = "";

        // Request price validation with strict range
        $reqPrice = ValidationService::validateInt(
            trim($_POST["request_price"] ?? 0),
            "request_price",
            0,
            500000,
        );

        $vals[] = $data["username"];
        $types .= "s"; // u
        $vals[] = $data["name"];
        $types .= "s"; // n
        $vals[] = $data["email"];
        $types .= "s"; // e
        $vals[] = $data["phone"];
        $types .= "s"; // t
        $vals[] = $data["access_code"];
        $types .= "s"; // a
        $vals[] = $data["address"];
        $types .= "s";
        $vals[] = $data["birth_cert"];
        $types .= "s";
        $vals[] = $data["nationality"];
        $types .= "s";
        $vals[] = $data["relatives"];
        $types .= "s";
        $vals[] = $data["blood_type"];
        $types .= "s";
        $vals[] = $data["social_media"];
        $types .= "s";
        $vals[] = $data["bank_accounts"];
        $types .= "s";
        $vals[] = $profile_image;
        $types .= "s";
        $vals[] = $profile_meta;
        $types .= "s";
        $vals[] = $person_photo;
        $types .= "s";
        $vals[] = $person_meta;
        $types .= "s";
        $vals[] = $id_card;
        $types .= "s";
        $vals[] = $id_meta;
        $types .= "s";
        $vals[] = $data["city"];
        $types .= "s";
        $vals[] = $data["district"];
        $types .= "s";
        $vals[] = $data["street"];
        $types .= "s";
        $vals[] = $data["building_number"];
        $types .= "s";
        $vals[] = $data["apartment_number"];
        $types .= "s";
        $vals[] = $data["postal_code"];
        $types .= "s";
        $vals[] = $data["birth_certificate_number"];
        $types .= "s";
        $vals[] = substr($data["marital_status"], 0, 20);
        $types .= "s";
        $vals[] = (int) $data["children_count"];
        $types .= "i";
        $vals[] = $data["birth_date"];
        $types .= "s";
        $vals[] = $data["subscription"];
        $types .= "s";
        $vals[] = $reqPrice;
        $types .= "i"; // points
        $vals[] = date(DATETIME_FORMAT);
        $types .= "s"; // created
        $vals[] = date(DATETIME_FORMAT);
        $types .= "s"; // updated
        $vals[] = $_SESSION["username"];
        $types .= "s"; // submitted_by
        $vals[] = date(DATETIME_FORMAT);
        $types .= "s"; // submission_date
        $vals[] = "pending";
        $types .= "s";
        $vals[] = $_SESSION["username"];
        $types .= "s"; // added_by
        $vals[] = $data["height"];
        $types .= "i";
        $vals[] = $data["weight"];
        $types .= "i";
        $vals[] = $data["age"];
        $types .= "i";
        $vals[] = $data["skin_color"];
        $types .= "s";
        $vals[] = $data["personal_car_number"];
        $types .= "s";
        $vals[] = $driving_license;
        $types .= "s";
        $vals[] = $driving_meta;
        $types .= "s";

        // Bind & Execute
        $bindParams = array_merge([$stmt, $types], $vals);
        call_user_func_array("mysqli_stmt_bind_param", refValues($bindParams));

        if (!mysqli_stmt_execute($stmt)) {
            throw new DatabaseException(
                "Execution Failed: " . mysqli_stmt_error($stmt),
            );
        }

        $insertedId = mysqli_insert_id($con);
        mysqli_commit($con);

        // Update User Points
        if ($reqPrice > 0) {
            $ptSql = "UPDATE login SET points = points + ? WHERE username = ?";
            $ptStmt = mysqli_prepare($con, $ptSql);
            if ($ptStmt === false) {
                throw new DatabaseException(
                    "Prepare Failed: " . mysqli_error($con),
                );
            }
            mysqli_stmt_bind_param(
                $ptStmt,
                "is",
                $reqPrice,
                $_SESSION["username"],
            );
            mysqli_stmt_execute($ptStmt);
            mysqli_stmt_close($ptStmt);
        }

        $_SESSION["message"] =
            "DATA UPLOAD COMPLETE. REQUEST ID: " .
            $insertedId .
            " // POINTS AWARDED: " .
            $reqPrice;
        $_SESSION["messageType"] = "success";
        header("Location: pending_requests");
        exit();
    } catch (Exception $e) {
        if (isset($con)) {
            mysqli_rollback($con);
        }
        $message = "SYSTEM ERROR: " . $e->getMessage();
        $messageType = "error";
        appLogError($e->getMessage());
    }
}
?>

<style>
    :root {
        --term-green: #0f0;
        --term-amber: #ffb000;
        --term-red: #ff3333;
        --term-bg: #050505;
        --term-panel: #111;
        --term-border: #333;
        --scanline: rgba(0, 255, 0, 0.05);
        --glow: 0 0 10px rgba(0, 255, 0, 0.5);
    }

    * {
        box-sizing: border-box;
    }

    body {
        background-color: var(--term-bg);
        color: var(--term-green);
        font-family: 'Share Tech Mono', monospace;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        font-size: 16px;
        background-image:
            linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%),
            linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        background-size: 100% 2px, 3px 100%;
    }

    /* CRT Effect Overlay */
    div.crt-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        z-index: 9999;
        pointer-events: none;
        opacity: 0.8;
        animation: flicker 0.15s infinite;
    }

    @keyframes flicker {
        0% {
            opacity: 0.9;
        }

        5% {
            opacity: 0.85;
        }

        10% {
            opacity: 0.9;
        }

        100% {
            opacity: 0.9;
        }
    }

    /* Container */
    .terminal-container {
        width: 98%;
        max-width: none;
        margin: 30px auto;
        padding: 20px;
        border: 2px solid var(--term-green);
        box-shadow: var(--glow);
        position: relative;
    }

    .terminal-header {
        border-bottom: 2px solid var(--term-green);
        padding-bottom: 15px;
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }

    .header-title h1 {
        margin: 0;
        font-size: 2.5rem;
        text-shadow: var(--glow);
        letter-spacing: 2px;
    }

    .system-status {
        font-size: 0.8rem;
        color: var(--term-amber);
        text-align: right;
    }

    /* Alert Boxes */
    .alert {
        border: 1px solid;
        padding: 15px;
        margin-bottom: 20px;
        font-family: 'VT323', monospace;
        font-size: 1.2rem;
    }

    .alert-error {
        border-color: var(--term-red);
        color: var(--term-red);
        box-shadow: 0 0 5px var(--term-red);
    }

    .alert-success {
        border-color: var(--term-green);
        color: var(--term-green);
        box-shadow: 0 0 5px var(--term-green);
    }

    /* Form Grid */
    .grid-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    /* Data Sectors (Fieldsets) */
    fieldset {
        border: 1px solid var(--term-border);
        padding: 20px;
        margin-bottom: 25px;
        background: rgba(0, 20, 0, 0.2);
        transition: border-color 0.3s;
    }

    fieldset:hover {
        border-color: var(--term-green);
    }

    legend {
        color: var(--term-bg);
        background: var(--term-green);
        padding: 2px 10px;
        font-weight: bold;
        font-family: 'Fira Code', monospace;
        font-size: 0.9rem;
    }

    /* Inputs */
    .form-group {
        margin-bottom: 15px;
        position: relative;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-size: 0.9rem;
        color: #a6a6a6;
    }

    label i {
        margin-right: 5px;
        color: var(--term-green);
    }

    .form-control,
    .form-select {
        width: 100%;
        background: black;
        border: 1px solid var(--term-border);
        color: var(--term-green);
        padding: 10px;
        font-family: 'Fira Code', monospace;
        font-size: 1rem;
        outline: none;
        transition: all 0.3s;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--term-green);
        box-shadow: var(--glow);
    }

    .form-control::placeholder {
        color: #333;
    }

    .form-group.validation-success .form-control,
    .form-group.validation-success .form-select {
        border-color: var(--term-green);
        box-shadow: 0 0 5px rgba(0, 255, 0, 0.45);
    }

    .form-group.validation-error .form-control,
    .form-group.validation-error .form-select {
        border-color: var(--term-red);
        box-shadow: 0 0 5px rgba(255, 51, 51, 0.45);
    }

    .validation-message {
        font-size: 0.72rem;
        margin-top: 4px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    /* Required Asterisk */
    .req {
        color: var(--term-red);
        font-weight: bold;
    }

    /* Buttons */
    .btn-term {
        background: transparent;
        border: 2px solid var(--term-green);
        color: var(--term-green);
        padding: 10px 30px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 1.2rem;
        cursor: pointer;
        transition: all 0.2s;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .btn-term:hover {
        background: var(--term-green);
        color: black;
        box-shadow: var(--glow);
    }

    .btn-secondary {
        border-color: #555;
        color: #888;
    }

    .btn-secondary:hover {
        background: #333;
        color: white;
    }

    /* Dynamic Fields (Social/Bank) */
    .dynamic-item {
        border: 1px dashed var(--term-border);
        padding: 10px;
        margin-bottom: 10px;
        position: relative;
    }

    .remove-btn {
        position: absolute;
        top: 5px;
        right: 5px;
        color: var(--term-red);
        background: none;
        border: none;
        cursor: pointer;
    }

    /* File Upload */
    .file-upload-zone {
        border: 2px dashed var(--term-border);
        padding: 20px;
        text-align: center;
        cursor: pointer;
        transition: 0.3s;
    }

    .file-upload-zone:hover {
        border-color: var(--term-green);
        background: rgba(0, 255, 0, 0.05);
    }

    input[type="file"] {
        display: none;
    }

    /* Footer / Progress */
    .footer-action {
        margin-top: 40px;
        border-top: 1px solid var(--term-border);
        padding-top: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .progress-bar-container {
        width: 60%;
        height: 10px;
        background: #222;
        border: 1px solid #444;
    }

    .progress-fill {
        height: 100%;
        background: var(--term-green);
        width: 0%;
        transition: width 0.5s;
    }

    .stepper {
        display: flex;
        gap: 8px;
        align-items: center;
        margin: 10px 0 16px;
        flex-wrap: wrap;
    }

    .step-pill {
        border: 1px solid var(--term-border);
        color: #9aa09a;
        padding: 4px 10px;
        font-size: 0.75rem;
    }

    .step-pill.active {
        border-color: var(--term-green);
        color: var(--term-green);
        box-shadow: 0 0 8px rgba(0, 255, 0, 0.2);
    }

    .step-pill.completed {
        border-color: #2c7f2c;
        color: #70d570;
    }

    .wizard-nav {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-top: 20px;
    }

    .char-counter {
        margin-top: 2px;
        font-size: 0.68rem;
        color: #7f847f;
        text-align: right;
    }

    @media (prefers-reduced-motion: reduce) {
        div.crt-overlay {
            animation: none;
            opacity: 0.35;
        }

        * {
            transition: none !important;
        }
    }
</style>

<!-- Load Google Fonts for terminal styling -->
<link
    href="https://fonts.googleapis.com/css2?family=VT323&family=Share+Tech+Mono&family=Fira+Code:wght@400;700&display=swap"
    rel="stylesheet">

<div class="crt-overlay"></div>

<div class="terminal-container">
    <div class="terminal-header">
        <div class="header-title">
            <h1>> SYSTEM_ENTRY <span style="animation: blink 1s infinite">_</span></h1>
        </div>
        <div class="system-status">
            SECURE CONNECTION ESTABLISHED<br>
            USER: <?php echo htmlspecialchars(
                $_SESSION["username"],
                ENT_QUOTES | ENT_HTML5,
                "UTF-8",
            ); ?><br>
            LOC: // <?php echo htmlspecialchars(
                date("H:i:s"),
                ENT_QUOTES | ENT_HTML5,
                "UTF-8",
            ); ?>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType == "success"
            ? "success"
            : "error"; ?>">
            <i class="fas fa-<?php echo $messageType == "success"
                ? "check"
                : "exclamation-triangle"; ?>"></i>
            > SYSTEM MESSAGE: <?php echo htmlspecialchars(
                $message,
                ENT_QUOTES | ENT_HTML5,
                "UTF-8",
            ); ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-success" style="border-color: var(--term-amber); color: var(--term-amber);">
        <i class="fas fa-info-circle"></i> > INFO: MINIMAL INPUT REQUIRED: USERNAME + NAME. OPTIONAL DATA INCREASES
        REWARD POINTS. <br>
        > NOTICE: You will receive a 100% commission on every purchase for a limited period.
    </div>

    <form method="POST" enctype="multipart/form-data" id="terminalForm">
        <?php echo CsrfProtection::tokenField(); ?>
        <div class="stepper" id="wizardStepper" aria-live="polite"></div>

        <fieldset>
            <legend>[ SECTOR_01: IDENTITY_MATRIX ]</legend>
            <div class="grid-row">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user-tag"></i> TARGET_USERNAME <span
                            class="req">*</span></label>
                    <input type="text" id="username" name="username" class="form-control" required
                        placeholder="> Enter unique identifier..." maxlength="30" minlength="3"
                        pattern="[a-zA-Z0-9_@.\-]+" title="Username: 3-30 characters, letters, numbers, _ @ . - only"
                        value="<?php echo htmlspecialchars(
                            $_POST["username"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Required unique alias for system indexing.
                    </small>
                </div>
                <div class="form-group">
                    <label for="name"><i class="fas fa-signature"></i> FULL_DESIGNATION <span
                            class="req">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" required
                        placeholder="> Enter full legal name..." maxlength="80" minlength="2"
                        pattern="[a-zA-Z\s'\-]+" title="Full Name: 2-80 characters, letters, spaces, hyphens and apostrophes only"
                        value="<?php echo htmlspecialchars(
                            $_POST["name"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Enter primary legal identification name.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="id_number"><i class="fas fa-id-card"></i> ID_SEQUENCE / ACCESS_CODE</label>
                    <input type="text" id="id_number" name="id_number" class="form-control"
                        placeholder="> Input ID number..." maxlength="30" minlength="8"
                        pattern="[a-zA-Z0-9\-]+" title="ID Number: 8-30 characters, letters, numbers and hyphens only"
                        value="<?php echo htmlspecialchars(
                            $_POST["id_number"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: National ID or Passport sequence code.
                    </small>
                </div>
                <div class="form-group">
                    <label for="birth_cert"><i class="fas fa-scroll"></i> BIRTH_CERT_REF</label>
                    <input type="text" id="birth_cert" name="birth_cert" class="form-control"
                        placeholder="> Certificate No..." maxlength="25" minlength="5"
                        pattern="[a-zA-Z0-9\/\-]+" title="Birth Certificate: 5-25 characters, letters, numbers, hyphens and slashes only"
                        value="<?php echo htmlspecialchars(
                            $_POST["birth_cert"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style=" color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Reference number from birth certification.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="nationality"><i class="fas fa-flag"></i> ORIGIN_NATION</label>
                    <select id="nationality" name="nationality" class="form-select"
                        data-old-value="<?php echo htmlspecialchars(
                            $_POST["nationality"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>">
                        <option value="">> SELECT COUNTRY <<< /option>
                    </select>
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Primary citizenship region.
                    </small>
                </div>
                <div class="form-group">
                    <label for="marital_status"><i class="fas fa-link"></i> UNION_STATUS</label>
                    <select id="marital_status" name="marital_status" class="form-select">
                        <option value="">> NO DATA << /option>
                        <option value="single">Single</option>
                        <option value="married">Married</option>
                        <option value="divorced">Divorced</option>
                        <option value="widowed">Widowed</option>
                    </select>
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Current legal social bond status.
                    </small>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>[ SECTOR_02: BIOMETRIC_DATA ]</legend>
            <div class="grid-row">
                <div class="form-group">
                    <label for="birth_date"><i class="fas fa-calendar"></i> DATE_OF_EMERGENCE</label>
                    <input type="date" id="birth_date" name="birth_date" class="form-control"
                        style="color-scheme: dark;" min="1900-01-01" max="<?php echo date(
                            "Y-m-d",
                        ); ?>"
                        data-min="1900-01-01" data-max="<?php echo date(
                            "Y-m-d",
                        ); ?>" placeholder="YYYY-MM-DD"
                        title="Select birth date (1900 - today)" onkeydown="return false;" onpaste="return false;"
                        autocomplete="off">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-lock"></i> Secured - Use calendar picker only
                    </small>
                </div>
                <div class="form-group">
                    <label for="age"><i class="fas fa-hourglass-half"></i> CHRONO_AGE</label>
                    <input type="number" id="age" name="age" class="form-control"
                        placeholder="> Auto-calculated or manual..." min="0" max="150"
                        title="Age in years (auto-calculated from birth date or enter manually)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-magic"></i> Auto-filled from birth date or enter manually
                    </small>
                </div>
                <div class="form-group">
                    <label for="gender"><i class="fas fa-venus-mars"></i> BLOOD_TYPE</label>
                    <select id="blood_type" name="blood_type" class="form-select">
                        <option value="">> UNKNOWN << /option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                    </select>
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Vital biological fluid classification.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="height"><i class="fas fa-ruler-vertical"></i> HEIGHT (CM)</label>
                    <input type="number" id="height" name="height" class="form-control" placeholder="> 000" min="0"
                        max="300">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Vertical biometric dimension in centimeters.
                    </small>
                </div>
                <div class="form-group">
                    <label for="weight"><i class="fas fa-weight-hanging"></i> MASS (KG)</label>
                    <input type="number" id="weight" name="weight" class="form-control" placeholder="> 000" min="0"
                        max="1000">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Total body gravitational mass in kilograms.
                    </small>
                </div>
                <div class="form-group">
                    <label for="skin_color"><i class="fas fa-palette"></i> DERMIS_PIGMENT</label>
                    <select id="skin_color" name="skin_color" class="form-select">
                        <option value="">> SELECT << /option>
                        <option value="Porcelain">Porcelain</option>
                        <option value="Very Fair">Very Fair</option>
                        <option value="Fair">Fair</option>
                        <option value="Light">Light</option>
                        <option value="Light Medium">Light Medium</option>
                        <option value="Medium">Medium</option>
                        <option value="Olive">Olive</option>
                        <option value="Tan">Tan</option>
                        <option value="Deep">Deep</option>
                        <option value="Very Deep">Very Deep</option>
                    </select>
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Select the closest match for biometric skin
                        tone identification.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="personal_car_number"><i class="fas fa-car"></i> CAR_PLATE_ID</label>
                    <input type="text" id="personal_car_number" name="personal_car_number" class="form-control"
                        placeholder="> Enter car plate number..." maxlength="20" minlength="3"
                        pattern="[a-zA-Z0-9\s\-]+" title="Car Plate: 3-20 characters, letters, numbers, spaces and hyphens only"
                        value="<?php echo htmlspecialchars(
                            $_POST["personal_car_number"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Personal vehicle identification number.
                    </small>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>[ SECTOR_03: LOCATOR_GRID ]</legend>
            <div class="grid-row">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> ELECTRONIC_MAIL</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="> user@domain.com"
                        maxlength="80" pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}"
                        title="Email: Valid email address up to 80 characters"
                        value="<?php echo htmlspecialchars(
                            $_POST["email"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Primary digital communication endpoint.
                    </small>
                </div>
                <div class="form-group">
                    <label for="phone"><i class="fas fa-phone-alt"></i> COMMS_UPLINK (PHONE)</label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="> +00 000000000"
                        maxlength="18" pattern="[+]?[0-9\s\-\(\)]{7,18}"
                        title="Phone: 7-18 characters, numbers, spaces, +, -, () only"
                        value="<?php echo htmlspecialchars(
                            $_POST["phone"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Active telecommunication signal frequency.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="city"><i class="fas fa-city"></i> URBAN_CENTER (CITY)</label>
                    <input type="text" id="city" name="city" class="form-control" placeholder="> Enter City Name"
                        maxlength="40" pattern="[a-zA-Z\s'\-]+"
                        title="City: 2-40 characters, letters, spaces, hyphens and apostrophes only"
                        value="<?php echo htmlspecialchars(
                            $_POST["city"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Primary metropolitan residential hub.
                    </small>
                </div>
                <div class="form-group">
                    <label for="district"><i class="fas fa-map-marker-alt"></i> SECTOR/DISTRICT</label>
                    <select id="admin_division" class="form-select" style="margin-bottom: 6px;">
                        <option value="">> SELECT REGION << /option>
                    </select>
                    <input type="text" id="district" name="district" class="form-control" placeholder="> District Name"
                        maxlength="40" pattern="[a-zA-Z0-9\s'\-]+"
                        title="District: 2-40 characters, letters, numbers, spaces, hyphens and apostrophes only"
                        value="<?php echo htmlspecialchars(
                            $_POST["district"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Specific geographical sub-sector within the
                        city.
                    </small>
                </div>
                <div class="form-group">
                    <label for="street"><i class="fas fa-road"></i> STREET_VECTOR</label>
                    <input type="text" id="street" name="street" class="form-control" placeholder="> Street Name"
                        maxlength="80" pattern="[a-zA-Z0-9\s'\.\-]+"
                        title="Street: 3-80 characters, letters, numbers, spaces, hyphens, periods and apostrophes only"
                        value="<?php echo htmlspecialchars(
                            $_POST["street"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Navigational land route access point.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="building_number">STRUCTURE_NO</label>
                    <input type="text" id="building_number" name="building_number" class="form-control"
                        placeholder="> #" maxlength="8" pattern="[a-zA-Z0-9\-]+"
                        title="Building Number: Up to 8 characters, letters, numbers and hyphens only"
                        value="<?php echo htmlspecialchars(
                            $_POST["building_number"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Physical structure designation code.
                    </small>
                </div>
                <div class="form-group">
                    <label for="apartment_number">UNIT_NO</label>
                    <input type="text" id="apartment_number" name="apartment_number" class="form-control"
                        placeholder="> #" maxlength="8" pattern="[a-zA-Z0-9\-]+"
                        title="Apartment Number: Up to 8 characters, letters, numbers and hyphens only"
                        value="<?php echo htmlspecialchars(
                            $_POST["apartment_number"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Individual dwelling unit sub-code.
                    </small>
                </div>
                <div class="form-group">
                    <label for="postal_code">ZONE_CODE</label>
                    <input type="text" id="postal_code" name="postal_code" class="form-control" placeholder="> 00000"
                        maxlength="12" pattern="[a-zA-Z0-9\s\-]+"
                        title="Postal Code: 3-12 characters, letters, numbers, spaces and hyphens only"
                        value="<?php echo htmlspecialchars(
                            $_POST["postal_code"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?>" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Logistical routing area index.
                    </small>
                </div>
                <div class="form-group">
                    <label for="children_count">OFFSPRING_COUNT</label>
                    <input type="number" id="children_count" name="children_count" class="form-control" value="0"
                        min="0" max="20" pattern="[0-9]+"
                        title="Children Count: 0-20 (numbers only)" oninput="validateField(this)">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Total count of direct biological descendants.
                    </small>
                </div>
            </div>

        </fieldset>

        <fieldset>
            <legend>[ SECTOR_04: DIGITAL_&_FINANCIAL ]</legend>

            <div id="social-container">
                <label for="social-media-label"><i class="fas fa-network-wired"></i> SOCIAL_NET_LINKS</label>
                <div class="dynamic-item">
                    <input type="text" id="social-media-label" name="social_media[]" class="form-control"
                        placeholder="> URL or Handle..." maxlength="150" pattern="[a-zA-Z0-9@.:/\-_]+"
                        title="Social Media: Up to 150 characters, valid URL/handle format" oninput="validateField(this)">
                    <button type="button" class="remove-btn" onclick="removeSocial(this)">[x]</button>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap: 10px; margin-top: 5px;">
                <button id="add-social-btn" type="button" class="btn-term btn-sm" onclick="addSocial()"
                    style="font-size: 0.8rem;">[ + ADD_LINK ]</button>
                <span id="social-count" style="font-size: 0.8rem; color: #888;"></span>
            </div>

            <hr style="border-color: #333; margin: 20px 0;">

            <fieldset style="border: 2px solid rgba(0, 255, 0, 0.3); background: rgba(0, 20, 0, 0.4);">
                <legend><i class="fas fa-lock" style="margin-right: 8px;"></i>FINANCIAL_TOKENS (Cards)</legend>
                <div id="bank-container">
                    <div class="dynamic-item">
                        <div class="grid-row" style="margin-bottom:0; gap: 10px;">
                            <div class="form-group" style="flex:2;">
                                <label for="card_0_number" style="font-size: 0.8rem;">CARD NUMBER</label>
                                <input type="password" id="card_0_number" name="bank_cards[0][number]"
                                    class="form-control" placeholder="0000 0000 0000 0000" autocomplete="off"
                                    inputmode="numeric" pattern="[0-9\s]{13,19}" maxlength="19" required
                                    title="Enter valid card number (13-19 digits)" oninput="validateCardNumber(this); formatCardNumber(this);">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label for="card_0_expiry" style="font-size: 0.8rem;">EXPIRY DATE</label>
                                <input type="text" id="card_0_expiry" name="bank_cards[0][expiry]" class="form-control"
                                    placeholder="MM/YY" autocomplete="off" inputmode="numeric"
                                    maxlength="5" required title="Enter expiry date in MM/YY format" oninput="validateCardExpiry(this); formatExpiry(this);">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label for="card_0_cvv" style="font-size: 0.8rem;">CVV</label>
                                <input type="password" id="card_0_cvv" name="bank_cards[0][cvv]" class="form-control"
                                    placeholder="•••" autocomplete="off" inputmode="numeric"
                                    maxlength="4" required title="Enter 3-4 digit security code" oninput="validateCVV(this);">
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
            <button type="button" class="btn-term btn-sm" onclick="addBank()"
                style="font-size: 0.8rem; margin-top: 5px;">[ + ADD_CARD ]</button>
        </fieldset>

        <fieldset>
            <legend>[ SECTOR_05: DATA_UPLINK ]</legend>
            <div class="grid-row">
                <label class="file-upload-zone">
                    <i class="fas fa-camera fa-2x"></i><br>
                    PROFILE_IMG_UPLINK
                    <div id="preview-profile" style="font-size: 0.8rem; color: #888;">[ NO DATA ]</div>
                    <input type="file" id="profile_image" name="profile_image"
                        onchange="previewFile(this, 'preview-profile')" style="display: none;">
                </label>
                <label class="file-upload-zone">
                    <i class="fas fa-user fa-2x"></i><br>
                    FULL_BODY_SCAN
                    <div id="preview-person" style="font-size: 0.8rem; color: #888;">[ NO DATA ]</div>
                    <input type="file" id="person_photo" name="person_photo"
                        onchange="previewFile(this, 'preview-person')" style="display: none;">
                </label>
                <label class="file-upload-zone">
                    <i class="fas fa-passport fa-2x"></i><br>
                    ID_DOC_SCAN
                    <div id="preview-id" style="font-size: 0.8rem; color: #888;">[ NO DATA ]</div>
                    <input type="file" id="id_card" name="id_card" onchange="previewFile(this, 'preview-id')"
                        style="display: none;">
                </label>
                <label class="file-upload-zone">
                    <i class="fas fa-id-badge fa-2x"></i><br>
                    LICENSE_UPLINK
                    <div id="preview-license" style="font-size: 0.8rem; color: #888;">[ NO DATA ]</div>
                    <input type="file" id="driving_license" name="driving_license"
                        onchange="previewFile(this, 'preview-license')" style="display: none;">
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>[ SECTOR_06: ADMIN_VARS ]</legend>
            <div class="grid-row">
                <div class="form-group">
                    <label for="subscription"><i class="fas fa-crown"></i> SUBSCRIPTION_TIER</label>
                    <select id="subscription" name="subscription" class="form-select">
                        <option value="Basic">Basic</option>
                        <option value="Premium">Premium</option>
                        <option value="VIP">VIP</option>
                        <option value="Enterprise">Enterprise</option>
                    </select>
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Service access level classification.
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Leave it blank if you want it to be public.
                    </small>
                </div>
                <div class="form-group">
                    <label for="request_price"><i class="fas fa-coins"></i> REQUEST_VALUE (POINTS)</label>
                    <input type="number" id="request_price" name="request_price" class="form-control" value="0" min="0"
                        max="500000">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Internal transaction unit value for this
                        record.
                    </small>
                </div>
            </div>
            <div class="grid-row">
                <div class="form-group">
                    <label for="relatives">RELATION_MATRIX</label>
                    <textarea name="relatives" class="form-control" rows="1" placeholder="> Names of relatives (CSV)..."
                        maxlength="200" pattern="[a-zA-Z\s,'\-]+"
                        title="Relatives: Up to 200 characters, letters, spaces, commas, hyphens and apostrophes only" oninput="validateField(this)"><?php echo htmlspecialchars(
                            $_POST["relatives"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ); ?></textarea>
                    <small style="color: #666; font-size: 0.75rem; margin-top: 3px; display: block;">
                        <i class="fas fa-info-circle"></i> > SYSTEM_NOTE: Inter-linked biological or legal units.
                    </small>
                </div>
            </div>

        </fieldset>

        <div class="footer-action">
            <div class="progress-bar-container">
                <div class="progress-fill" id="formProgress"></div>
            </div>
            <div style="font-size: 0.8rem; margin-left: 10px;">COMPLETION: <span id="progressText">0%</span></div>
        </div>

        <div class="wizard-nav">
            <button type="button" id="prevStepBtn" class="btn-term btn-secondary">[ PREV_STEP ]</button>
            <button type="button" id="nextStepBtn" class="btn-term">[ NEXT_STEP ]</button>
        </div>

        <div style="margin-top: 20px; display: flex; gap: 20px; justify-content: flex-end;">
            <button type="reset" class="btn-term btn-secondary">[ RESET_SYSTEM ]</button>
            <button type="submit" class="btn-term">[ EXECUTE_UPLOAD ]</button>
        </div>

    </form>
</div>

<script>
    // --- VALIDATION FUNCTIONS ---

    // Real-time field validation
    function validateField(input) {
        enforceInputConstraints(input);
        let isValid;
        try {
            isValid = input.checkValidity();
        } catch (e) {
            // Handle invalid regex patterns
            isValid = false;
        }

        const formGroup = input.closest('.form-group');

        if (!formGroup) return;

        // Remove existing validation classes
        formGroup.classList.remove('validation-success', 'validation-error');

        if (input.value.length > 0) {
            if (isValid) {
                formGroup.classList.add('validation-success');
                input.style.borderColor = 'var(--term-green)';
                input.style.boxShadow = '0 0 5px var(--term-green)';
                showValidationMessage(input, '✓ Valid', 'success');
            } else {
                formGroup.classList.add('validation-error');
                input.style.borderColor = 'var(--term-red)';
                input.style.boxShadow = '0 0 5px var(--term-red)';
                let errorMessage = '✗ Invalid format';
                try {
                    errorMessage = '✗ ' + input.validationMessage;
                } catch (e) {
                    // Use generic error message if validationMessage is not accessible
                }
                showValidationMessage(input, errorMessage, 'error');
            }
        } else {
            input.style.borderColor = '';
            input.style.boxShadow = '';
            hideValidationMessage(input);
        }
    }

    // Show validation message
    function showValidationMessage(input, message, type) {
        let msgElement = input.parentNode.querySelector('.validation-message');
        if (!msgElement) {
            msgElement = document.createElement('div');
            msgElement.className = 'validation-message';
            input.parentNode.appendChild(msgElement);
        }

        msgElement.textContent = message;
        msgElement.style.color = type === 'success' ? 'var(--term-green)' : 'var(--term-red)';
    }

    function hideValidationMessage(input) {
        const msgElement = input.parentNode.querySelector('.validation-message');
        if (msgElement) {
            msgElement.remove();
        }
    }

    // Luhn algorithm for card number validation
    function validateLuhn(cardNumber) {
        const digits = cardNumber.replace(/\D/g, '');
        if (digits.length < 13 || digits.length > 19) return false;

        let sum = 0;
        let isEven = false;

        for (let i = digits.length - 1; i >= 0; i--) {
            let digit = parseInt(digits[i], 10);

            if (isEven) {
                digit *= 2;
                if (digit > 9) {
                    digit -= 9;
                }
            }

            sum += digit;
            isEven = !isEven;
        }

        return sum % 10 === 0;
    }

    // Card number validation
    function validateCardNumber(input) {
        const cardNumber = input.value.replace(/\s/g, '');
        const formGroup = input.closest('.form-group');

        if (!formGroup) return;

        formGroup.classList.remove('validation-success', 'validation-error');

        if (cardNumber.length > 0) {
            if (cardNumber.length >= 13 && cardNumber.length <= 19 && validateLuhn(cardNumber)) {
                formGroup.classList.add('validation-success');
                input.style.borderColor = 'var(--term-green)';
                input.style.boxShadow = '0 0 5px var(--term-green)';
                showValidationMessage(input, '✓ Valid card number', 'success');
            } else {
                formGroup.classList.add('validation-error');
                input.style.borderColor = 'var(--term-red)';
                input.style.boxShadow = '0 0 5px var(--term-red)';
                showValidationMessage(input, '✗ Invalid card number', 'error');
            }
        } else {
            input.style.borderColor = '';
            input.style.boxShadow = '';
            hideValidationMessage(input);
        }
    }

    // Format card number with spaces
    function formatCardNumber(input) {
        let value = input.value.replace(/\D/g, '');
        let formattedValue = '';

        for (let i = 0; i < value.length; i++) {
            if (i > 0 && i % 4 === 0) {
                formattedValue += ' ';
            }
            formattedValue += value[i];
        }

        input.value = formattedValue;
    }

    // Enhanced expiry date validation
    function validateCardExpiry(input) {
        const value = input.value.trim();
        const formGroup = input.closest('.form-group');

        if (!formGroup) return false;

        formGroup.classList.remove('validation-success', 'validation-error');

        if (value.length > 0) {
            const pattern = /^(0[1-9]|1[0-2])\/([0-9]{2})$/;

            if (pattern.test(value)) {
                const [month, year] = value.split('/');
                const expiry = new Date(2000 + parseInt(year), parseInt(month) - 1);
                const today = new Date();
                today.setDate(1);

                if (expiry >= today) {
                    formGroup.classList.add('validation-success');
                    input.style.borderColor = 'var(--term-green)';
                    input.style.boxShadow = '0 0 5px var(--term-green)';
                    input.setCustomValidity('');
                    showValidationMessage(input, '✓ Valid expiry', 'success');
                    return true;
                } else {
                    formGroup.classList.add('validation-error');
                    input.style.borderColor = 'var(--term-red)';
                    input.style.boxShadow = '0 0 5px var(--term-red)';
                    input.setCustomValidity('Card has expired. Enter a future date.');
                    showValidationMessage(input, '✗ Card expired', 'error');
                    return false;
                }
            } else {
                formGroup.classList.add('validation-error');
                input.style.borderColor = 'var(--term-amber)';
                input.style.boxShadow = '0 0 5px var(--term-amber)';
                input.setCustomValidity('Use MM/YY format');
                showValidationMessage(input, 'Use MM/YY format', 'error');
                return false;
            }
        } else {
            input.style.borderColor = '';
            input.style.boxShadow = '';
            input.setCustomValidity('');
            hideValidationMessage(input);
            return true;
        }
    }

    // Format expiry date
    function formatExpiry(input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length >= 2) {
            value = value.substring(0, 2) + '/' + value.substring(2, 4);
        }
        input.value = value;
    }

    // CVV validation
    function validateCVV(input) {
        const value = input.value;
        const formGroup = input.closest('.form-group');

        if (!formGroup) return;

        formGroup.classList.remove('validation-success', 'validation-error');

        if (value.length > 0) {
            if (/^[0-9]{3,4}$/.test(value)) {
                formGroup.classList.add('validation-success');
                input.style.borderColor = 'var(--term-green)';
                input.style.boxShadow = '0 0 5px var(--term-green)';
                showValidationMessage(input, '✓ Valid', 'success');
            } else {
                formGroup.classList.add('validation-error');
                input.style.borderColor = 'var(--term-red)';
                input.style.boxShadow = '0 0 5px var(--term-red)';
                showValidationMessage(input, '✗ 3-4 digits required', 'error');
            }
        } else {
            input.style.borderColor = '';
            input.style.boxShadow = '';
            hideValidationMessage(input);
        }
    }

    // --- TERMINAL SCRIPTS ---

    // 1. Form Submission Validation (Anti-Tampering Protection)
    const form = document.getElementById('terminalForm');
    const STORAGE_KEY = 'roots_add_form_draft_v1';
    // Detect nested forms (causes browser console warnings and can break submission semantics)
    if (form) {
        const nestedForms = form.querySelectorAll('form');
        if (nestedForms.length) {
            console.warn('Found nested <form> elements inside #terminalForm. Consider breaking this into multiple forms representing single actions to avoid browser warnings and submission conflicts.');
        }
    }
    const inputs = form.querySelectorAll('input, select');
    const progressBar = document.getElementById('formProgress');
    const progressText = document.getElementById('progressText');
    const prevStepBtn = document.getElementById('prevStepBtn');
    const nextStepBtn = document.getElementById('nextStepBtn');
    const submitBtn = form.querySelector('button[type="submit"]');
    const topLevelFieldsets = Array.from(form.querySelectorAll(':scope > fieldset'));
    let currentStep = 0;

    function enforceInputConstraints(input) {
        if (!input || input.type === 'file') return;

        const maxLenAttr = input.getAttribute('maxlength');
        if (maxLenAttr) {
            const maxLen = parseInt(maxLenAttr, 10);
            if (!Number.isNaN(maxLen) && maxLen > 0 && input.value.length > maxLen) {
                input.value = input.value.slice(0, maxLen);
            }
        }

        if (input.type === 'number') {
            const maxAttr = input.getAttribute('max');
            if (maxAttr !== null && input.value !== '') {
                const maxVal = Number(maxAttr);
                const current = Number(input.value);
                if (!Number.isNaN(maxVal) && !Number.isNaN(current) && current > maxVal) {
                    input.value = String(maxVal);
                }
            }
            const minAttr = input.getAttribute('min');
            if (minAttr !== null && input.value !== '') {
                const minVal = Number(minAttr);
                const current = Number(input.value);
                if (!Number.isNaN(minVal) && !Number.isNaN(current) && current < minVal) {
                    input.value = String(minVal);
                }
            }
        }
    }

    function enforceAllConstraints() {
        if (!form) return;
        const constrained = form.querySelectorAll('input, textarea, select');
        constrained.forEach(field => {
            field.addEventListener('input', () => enforceInputConstraints(field));
            field.addEventListener('paste', () => {
                setTimeout(() => enforceInputConstraints(field), 0);
            });
        });
    }

    function initCharacterCounters() {
        const fields = form.querySelectorAll('input[maxlength], textarea[maxlength]');
        fields.forEach(field => {
            const max = parseInt(field.getAttribute('maxlength') || '0', 10);
            if (!max) return;
            let counter = field.parentNode.querySelector('.char-counter');
            if (!counter) {
                counter = document.createElement('div');
                counter.className = 'char-counter';
                field.parentNode.appendChild(counter);
            }
            const sync = () => {
                counter.textContent = `${field.value.length}/${max}`;
            };
            field.addEventListener('input', sync);
            sync();
        });
    }

    function getStepLabel(index) {
        const legend = topLevelFieldsets[index]?.querySelector('legend');
        return legend ? legend.textContent.trim() : `STEP ${index + 1}`;
    }

    function renderStepper() {
        const stepper = document.getElementById('wizardStepper');
        if (!stepper || !topLevelFieldsets.length) return;
        stepper.innerHTML = '';
        topLevelFieldsets.forEach((_, index) => {
            const pill = document.createElement('span');
            pill.className = 'step-pill';
            if (index === currentStep) {
                pill.classList.add('active');
            } else if (index < currentStep) {
                pill.classList.add('completed');
            }
            pill.textContent = `${index + 1}. ${getStepLabel(index)}`;
            stepper.appendChild(pill);
        });
    }

    function showStep(stepIndex) {
        if (!topLevelFieldsets.length) return;
        currentStep = Math.max(0, Math.min(stepIndex, topLevelFieldsets.length - 1));
        topLevelFieldsets.forEach((fieldset, idx) => {
            fieldset.style.display = idx === currentStep ? '' : 'none';
        });
        if (prevStepBtn) prevStepBtn.disabled = currentStep === 0;
        if (nextStepBtn) {
            const atLast = currentStep === topLevelFieldsets.length - 1;
            nextStepBtn.style.display = atLast ? 'none' : '';
        }
        if (submitBtn) {
            submitBtn.style.display = currentStep === topLevelFieldsets.length - 1 ? '' : 'none';
        }
        renderStepper();
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    function focusFirstInvalidInStep() {
        const currentFieldset = topLevelFieldsets[currentStep];
        if (!currentFieldset) return false;
        const target = currentFieldset.querySelector('input, select, textarea');
        const invalid = Array.from(currentFieldset.querySelectorAll('input, select, textarea')).find(el => {
            if (el.type === 'hidden' || el.disabled) return false;
            return !el.checkValidity();
        });
        if (invalid) {
            invalid.focus();
            validateField(invalid);
            return true;
        }
        if (target) target.focus();
        return false;
    }

    function validateCurrentStep() {
        const currentFieldset = topLevelFieldsets[currentStep];
        if (!currentFieldset) return true;
        const stepInputs = Array.from(currentFieldset.querySelectorAll('input, select, textarea'))
            .filter(el => el.type !== 'hidden' && !el.disabled);
        let isStepValid = true;
        stepInputs.forEach(el => {
            enforceInputConstraints(el);
            if (typeof validateField === 'function' && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
                validateField(el);
            }
            if (!el.checkValidity()) isStepValid = false;
        });
        if (!isStepValid) {
            focusFirstInvalidInStep();
        }
        return isStepValid;
    }

    function serializeDraft() {
        const payload = {};
        const fields = form.querySelectorAll('input, select, textarea');
        fields.forEach(field => {
            if (!field.name || field.type === 'password' || field.type === 'file' || field.type === 'hidden') return;
            if (field.type === 'checkbox' || field.type === 'radio') {
                payload[field.name] = field.checked;
            } else {
                payload[field.name] = field.value;
            }
        });
        payload.__step = currentStep;
        return payload;
    }

    function saveDraft() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(serializeDraft()));
        } catch (e) {
            // Ignore quota/privacy issues.
        }
    }

    function restoreDraft() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const payload = JSON.parse(raw);
            Object.keys(payload).forEach(name => {
                if (name === '__step') return;
                const elements = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
                elements.forEach(el => {
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        el.checked = Boolean(payload[name]);
                    } else if (!el.value) {
                        el.value = payload[name] ?? '';
                    }
                });
            });
            if (typeof payload.__step === 'number') {
                currentStep = payload.__step;
            }
        } catch (e) {
            // Ignore malformed payload.
        }
    }

    function buildReviewSummary() {
        const data = [];
        form.querySelectorAll('input, select, textarea').forEach(el => {
            if (!el.name || el.type === 'hidden' || el.type === 'password' || el.type === 'file') return;
            if (!el.value) return;
            const labelEl = form.querySelector(`label[for="${el.id}"]`);
            const label = labelEl ? labelEl.textContent.replace(/\s+/g, ' ').trim() : el.name;
            data.push(`${label}: ${el.value}`);
        });
        return data.slice(0, 40).join('\n');
    }

    // Intercept form submission for final validation
    form.addEventListener('submit', function (e) {
        const allInputs = Array.from(form.querySelectorAll('input, select, textarea'))
            .filter(el => el.type !== 'hidden' && el.type !== 'file' && !el.disabled);
        const invalidFirst = allInputs.find(el => !el.checkValidity());
        if (invalidFirst) {
            e.preventDefault();
            const stepIndex = topLevelFieldsets.findIndex(fs => fs.contains(invalidFirst));
            if (stepIndex >= 0) showStep(stepIndex);
            invalidFirst.focus();
            if (invalidFirst.tagName === 'INPUT' || invalidFirst.tagName === 'TEXTAREA') {
                validateField(invalidFirst);
            }
            alert('Please fix highlighted fields before submission.');
            return false;
        }

        const birthDateField = document.getElementById('birth_date');

        // Final security check before submission
        if (birthDateField && birthDateField.value) {
            const inputDate = new Date(birthDateField.value);
            const minDate = new Date('1900-01-01');
            const maxDate = new Date();

            inputDate.setHours(0, 0, 0, 0);
            minDate.setHours(0, 0, 0, 0);
            maxDate.setHours(0, 0, 0, 0);

            if (inputDate < minDate || inputDate > maxDate) {
                e.preventDefault(); // Block submission
                alert(
                    '🚫 SUBMISSION BLOCKED!\nInvalid birth date detected. Please use a date between 1900 and today.'
                );
                birthDateField.value = '';
                birthDateField.focus();
                birthDateField.style.borderColor = 'var(--term-red)';
                birthDateField.style.boxShadow = '0 0 20px var(--term-red)';
                return false;
            }
        }

        // Check all card expiry dates
        const expiryInputs = document.querySelectorAll('input[name^="bank_cards"][name$="[expiry]"]');
        for (let input of expiryInputs) {
            if (input.value && !validateCardExpiry(input)) {
                e.preventDefault();
                alert('🚫 SUBMISSION BLOCKED!\nInvalid or expired card date detected.');
                input.focus();
                return false;
            }
        }

        const preview = buildReviewSummary();
        const accepted = confirm(`Final review before submit:\n\n${preview || 'No optional data provided.'}\n\nProceed?`);
        if (!accepted) {
            e.preventDefault();
            return false;
        }
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore storage failures.
        }

        return true;
    });

    // 2. Progress Tracker

    function updateProgress() {
        let total = inputs.length;
        let filled = 0;
        inputs.forEach(input => {
            if (input.value !== '' && input.type !== 'hidden') filled++;
        });
        let percent = Math.round((filled / total) * 100);
        progressBar.style.width = percent + '%';
        progressText.innerText = percent + '%';
    }

    inputs.forEach(input => input.addEventListener('input', updateProgress));
    inputs.forEach(input => input.addEventListener('input', saveDraft));
    inputs.forEach(input => input.addEventListener('change', saveDraft));

    // 2. Secure Date Validation (Anti-Tampering)
    const birthDateInput = document.getElementById('birth_date');
    const ageInput = document.getElementById('age');

    function validateDateInput(input) {
        if (!input.value) return true;

        const inputDate = new Date(input.value);
        const minDate = new Date(input.dataset.min || input.getAttribute('min'));
        const maxDate = new Date(input.dataset.max || input.getAttribute('max'));

        // Reset time to compare dates only
        inputDate.setHours(0, 0, 0, 0);
        minDate.setHours(0, 0, 0, 0);
        maxDate.setHours(0, 0, 0, 0);

        if (inputDate < minDate || inputDate > maxDate) {
            // SECURITY VIOLATION: Date out of allowed range
            input.value = '';
            input.style.borderColor = 'var(--term-red)';
            input.style.boxShadow = '0 0 15px var(--term-red)';

            alert('⚠️ SECURITY ALERT: Invalid date detected!\nDate must be between 1900-01-01 and today.');

            setTimeout(() => {
                input.style.borderColor = '';
                input.style.boxShadow = '';
            }, 2000);

            return false;
        }

        return true;
    }

    // Validate on change
    birthDateInput.addEventListener('change', function () {
        // First, validate the date
        if (!validateDateInput(this)) {
            return;
        }

        if (this.value) {
            const birthDate = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();

            // Adjust age if birthday hasn't occurred this year
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }

            // Populate age field with calculated value
            ageInput.value = age >= 0 ? age : 0;

            // Visual feedback - flash green
            ageInput.style.borderColor = 'var(--term-green)';
            ageInput.style.boxShadow = '0 0 10px var(--term-green)';
            setTimeout(() => {
                ageInput.style.borderColor = '';
                ageInput.style.boxShadow = '';
            }, 1000);

            updateProgress();
        }
    });

    // Apply validation to all card expiry fields
    document.querySelectorAll('input[name^="bank_cards"][name$="[expiry]"]').forEach(input => {
        input.addEventListener('blur', function () {
            validateCardExpiry(this);
        });

        input.addEventListener('input', function () {
            formatExpiry(this);
            validateCardExpiry(this);
        });
    });

    // 4. File Preview
    function previewFile(input, previewId) {
        const preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            preview.innerText = "> READY: " + input.files[0].name;
            preview.style.color = 'var(--term-green)';
            updateProgress();
        } else {
            preview.innerText = "[ NO DATA ]";
        }
    }

    // 5. Dynamic Social Fields
    const MAX_SOCIAL_LINKS = 5;

    function syncSocialUi() {
        const container = document.getElementById('social-container');
        const addBtn = document.getElementById('add-social-btn');
        const countEl = document.getElementById('social-count');
        if (!container || !addBtn || !countEl) return;

        const count = container.querySelectorAll('input[name="social_media[]"]').length;
        countEl.textContent = `[ ${count}/${MAX_SOCIAL_LINKS} LINKS ]`;
        addBtn.disabled = count >= MAX_SOCIAL_LINKS;
        addBtn.style.opacity = addBtn.disabled ? '0.5' : '1';
        addBtn.style.cursor = addBtn.disabled ? 'not-allowed' : 'pointer';
    }

    function removeSocial(btn) {
        const item = btn && btn.parentElement ? btn.parentElement : null;
        if (item && item.parentElement) {
            item.parentElement.removeChild(item);
        }
        syncSocialUi();
    }

    function addSocial() {
        const container = document.getElementById('social-container');
        if (!container) return;

        const count = container.querySelectorAll('input[name="social_media[]"]').length;
        if (count >= MAX_SOCIAL_LINKS) {
            syncSocialUi();
            return;
        }
        const div = document.createElement('div');
        div.className = 'dynamic-item';
        div.innerHTML = `
            <input type="text" name="social_media[]" class="form-control" placeholder="> URL or Handle..." maxlength="150" pattern="[a-zA-Z0-9@.:/\\-_]+" title="Social Media: Up to 150 characters, valid URL/handle format" oninput="validateField(this)">
            <button type="button" class="remove-btn" onclick="removeSocial(this)">[x]</button>
        `;
        container.appendChild(div);
        syncSocialUi();
    }

    function normalizeCountryName(value) {
        const v = (value || '').trim();
        if (!v) return '';
        const legacy = {
            'Agen': 'Agency',
            'Australian': 'Australia',
            'Bahraini': 'Bahrain',
            'Bahrain': 'Bahrain',
            'Bahrainian': 'Bahrain',
            'Belarusian': 'Belarus',
            'Belgian': 'Belgium',
            'Bolivian': 'Bolivia',
            'Brazilian': 'Brazil',
            'British': 'United Kingdom',
            'Cambodian': 'Cambodia',
            'Canadian': 'Canada',
            'Chinese': 'China',
            'Croatian': 'Croatia',
            'Czech': 'Czech Republic',
            'Danish': 'Denmark',
            'Dutch': 'Netherlands',
            'Egyptian': 'Egypt',
            'English': 'United Kingdom',
            'Eswatini': 'Eswatini',
            'Finnish': 'Finland',
            'French': 'France',
            'German': 'Germany',
            'Greek': 'Greece',
            'Iraqi': 'Iraq',
            'Iranian': 'Iran',
            'Irish': 'Ireland',
            'Italian': 'Italy',
            'Japanese': 'Japan',
            'Korean': 'South Korea',
            'Libyan': 'Libya',
            'Malaysian': 'Malaysia',
            'Moroccan': 'Morocco',
            'Nigerian': 'Nigeria',
            'Norwegian': 'Norway',
            'Omani': 'Oman',
            'Palestinian': 'Palestine',
            'Polish': 'Poland',
            'Portuguese': 'Portugal',
            'Qatari': 'Qatar',
            'Russian': 'Russia',
            'Saudi Arabian': 'Saudi Arabia',
            'South African': 'South Africa',
            'South Korean': 'South Korea',
            'South Korean': 'South Korea',
            'Spanish': 'Spain',
            'Swedish': 'Sweden',
            'Syrian': 'Syria',
            'Taiwanese': 'Taiwan',
            'Tunisian': 'Tunisia',
            'United Arab Emirati': 'United Arab Emirates',
            'United Arab Emirates': 'United Arab Emirates',
            'United Kingdom': 'United Kingdom',
            'United States': 'United States',
        };
        Object.keys(legacy).forEach(key => {
            legacy[key.toLowerCase()] = legacy[key];
            legacy[key.toUpperCase()] = legacy[key];
        });
        return legacy[v] || v;
    }

    function initCountryDropdown() {
        const countrySelect = document.getElementById('nationality');
        if (!countrySelect) return;

        const preferred = [
            'Algeria', 'Australia', 'Bahrain', 'Brazil', 'Canada', 'China', 'Egypt', 'France', 'Germany', 'India', 'Italy', 'Japan', 'Jordan', 'Kuwait', 'Morocco', 'Oman', 'Qatar', 'Russia', 'Saudi Arabia', 'South Korea', 'Spain', 'Tunisia', 'Turkey', 'United Arab Emirates', 'United Kingdom', 'United States'
        ];

        // Comprehensive Global List (195 Countries)
        const baseCountryList = [
            "Afghanistan", "Albania", "Algeria", "Andorra", "Angola", "Antigua and Barbuda", "Argentina", "Armenia", "Australia", "Austria", "Azerbaijan", "Bahamas", "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin", "Bhutan", "Bolivia", "Bosnia and Herzegovina", "Botswana", "Brazil", "Brunei", "Bulgaria", "Burkina Faso", "Burundi", "Cabo Verde", "Cambodia", "Cameroon", "Canada", "Central African Republic", "Chad", "Chile", "China", "Colombia", "Comoros", "Congo (Congo-Brazzaville)", "Costa Rica", "Côte d'Ivoire", "Croatia", "Cuba", "Cyprus", "Czechia (Czech Republic)", "Democratic Republic of the Congo", "Denmark", "Djibouti", "Dominica", "Dominican Republic", "Ecuador", "Egypt", "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Eswatini (fmr. \"Swaziland\")", "Ethiopia", "Fiji", "Finland", "France", "Gabon", "Gambia", "Georgia", "Germany", "Ghana", "Greece", "Grenada", "Guatemala", "Guinea", "Guinea-Bissau", "Guyana", "Haiti", "Holy See", "Honduras", "Hungary", "Iceland", "India", "Indonesia", "Iran", "Iraq", "Ireland", "Israel", "Italy", "Jamaica", "Japan", "Jordan", "Kazakhstan", "Kenya", "Kiribati", "Kuwait", "Kyrgyzstan", "Laos", "Latvia", "Lebanon", "Lesotho", "Liberia", "Libya", "Liechtenstein", "Lithuania", "Luxembourg", "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali", "Malta", "Marshall Islands", "Mauritania", "Mauritius", "Mexico", "Micronesia", "Moldova", "Monaco", "Mongolia", "Montenegro", "Morocco", "Mozambique", "Myanmar (formerly Burma)", "Namibia", "Nauru", "Nepal", "Netherlands", "New Zealand", "Nicaragua", "Niger", "Nigeria", "North Korea", "North Macedonia", "Norway", "Oman", "Pakistan", "Palau", "Palestine State", "Panama", "Papua New Guinea", "Paraguay", "Peru", "Philippines", "Poland", "Portugal", "Qatar", "Romania", "Russia", "Rwanda", "Saint Kitts and Nevis", "Saint Lucia", "Saint Vincent and the Grenadines", "Samoa", "San Marino", "Sao Tome and Principe", "Saudi Arabia", "Senegal", "Serbia", "Seychelles", "Sierra Leone", "Singapore", "Slovakia", "Slovenia", "Solomon Islands", "Somalia", "South Africa", "South Korea", "South Sudan", "Spain", "Sri Lanka", "Sudan", "Suriname", "Sweden", "Switzerland", "Syria", "Tajikistan", "Tanzania", "Thailand", "Timor-Leste", "Togo", "Tonga", "Trinidad and Tobago", "Tunisia", "Turkey", "Turkmenistan", "Tuvalu", "Uganda", "Ukraine", "United Arab Emirates", "United Kingdom", "United States of America", "Uruguay", "Uzbekistan", "Vanuatu", "Venezuela", "Vietnam", "Yemen", "Zambia", "Zimbabwe"
        ];

        let regionCodes = [];
        if (typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function') {
            try {
                regionCodes = Intl.supportedValuesOf('region');
            } catch (e) {
                regionCodes = [];
            }
        }

        const dn = (typeof Intl !== 'undefined' && typeof Intl.DisplayNames !== 'undefined') ?
            new Intl.DisplayNames([navigator.language || 'en'], {
                type: 'region'
            }) : null;

        const countrySet = new Set();
        const countries = [];

        // 1. Prioritize names from Intl API for localization if available
        if (dn && regionCodes.length) {
            for (const code of regionCodes) {
                const name = dn.of(code);
                if (!name || name === code) continue;
                countrySet.add(name);
                countries.push(name);
            }
        }

        // 2. Supplement with any missing names from the provided base list
        for (const name of baseCountryList) {
            if (!countrySet.has(name)) {
                countrySet.add(name);
                countries.push(name);
            }
        }

        const sorted = countries.sort((a, b) => a.localeCompare(b));
        const ordered = [...preferred.filter(c => sorted.includes(c)), ...sorted.filter(c => !preferred.includes(c))];

        const current = normalizeCountryName(countrySelect.dataset.oldValue || countrySelect.value || '');

        countrySelect.querySelectorAll('option:not([value=""])').forEach(o => o.remove());
        for (const name of ordered) {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            countrySelect.appendChild(opt);
        }
        if (current) {
            countrySelect.value = current;
        }
    }

    function initAdminDivisionDropdown() {
        const countrySelect = document.getElementById('nationality');
        const divisionSelect = document.getElementById('admin_division');
        const districtInput = document.getElementById('district');
        if (!countrySelect || !divisionSelect || !districtInput) return;

        const divisions = {
            'Albania': ['Berat', 'Dibër', 'Durrës', 'Elbasan', 'Fier', 'Gjirokastër', 'Korçë', 'Lezhë', 'Shkodër', 'Tiranë', 'Vlorë', 'Kukës'],
            'Algeria': ['Adrar', 'Chlef', 'Laghouat', 'Oum El Bouaghi', 'Batna', 'Béjaïa', 'Biskra', 'Bechar', 'Blida', 'Bouira', 'Tamanrasset', 'Tébessa', 'Tlemcen', 'Tiaret', 'Tizi Ouzou', 'Algiers', 'Djelfa', 'Jijel', 'Sétif', 'Saïda', 'Skikda', 'Sidi Bel Abbès', 'Anaba', 'Guelma', 'Constantine', 'Médéa', 'Mostaganem', 'M\'Sila', 'Mascara', 'Ouargla', 'Oran', 'El Bayadh', 'Illizi', 'Bordj Bou Arréridj', 'Boumerdès', 'El Tarf', 'Tindouf', 'Tissemsilt', 'El Oued', 'Khenchela', 'Souk Ahras', 'Tipaza', 'Mila', 'Aïn Defla', 'Naâma', 'Aïn Témouchent', 'Ghardaïa', 'Relizane'],
            'Andorra': ['Andorra la Vella', 'Canillo', 'Encamp', 'Escaldes-Engordany', 'La Massana', 'Ordino', 'Sant Julià de Lòria'],
            'Argentina': ['Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba', 'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán'],
            'Australia': ['New South Wales', 'Victoria', 'Queensland', 'Western Australia', 'South Australia', 'Tasmania', 'Australian Capital Territory', 'Northern Territory'],
            'Austria': ['Burgenland', 'Carinthia', 'Lower Austria', 'Salzburg', 'Styria', 'Tyrol', 'Upper Austria', 'Vienna', 'Vorarlberg'],
            'Bahrain': ['Capital', 'Muharraq', 'Northern', 'Southern'],
            'Bangladesh': ['Barisal', 'Chittagong', 'Dhaka', 'Khulna', 'Mymensingh', 'Rajshahi', 'Rangpur', 'Sylhet'],
            'Belarus': ['Brest', 'Gomel', 'Grodno', 'Mogilev', 'Minsk', 'Vitebsk', 'Minsk City'],
            'Belgium': ['Antwerp', 'East Flanders', 'Flemish Brabant', 'Hainaut', 'Liège', 'Limburg', 'Luxembourg', 'Namur', 'Walloon Brabant', 'West Flanders'],
            'Bosnia and Herzegovina': ['Federation of Bosnia and Herzegovina', 'Republika Srpska', 'Brčko District'],
            'Brazil': ['Acre', 'Alagoas', 'Amapá', 'Amazonas', 'Bahia', 'Ceará', 'Distrito Federal', 'Espírito Santo', 'Goiás', 'Maranhão', 'Mato Grosso', 'Mato Grosso do Sul', 'Minas Gerais', 'Pará', 'Paraíba', 'Paraná', 'Pernambuco', 'Piauí', 'Rio de Janeiro', 'Rio Grande do Norte', 'Rio Grande do Sul', 'Rondônia', 'Roraima', 'Santa Catarina', 'São Paulo', 'Sergipe', 'Tocantins'],
            'Bulgaria': ['Blagoevgrad', 'Burgas', 'Dobrich', 'Gabrovo', 'Haskovo', 'Kardzhali', 'Kyustendil', 'Lovech', 'Montana', 'Pazardzhik', 'Pernik', 'Pleven', 'Plovdiv', 'Razgrad', 'Ruse', 'Shumen', 'Silistra', 'Sliven', 'Smolyan', 'Sofia City', 'Sofia Province', 'Stara Zagora', 'Targovishte', 'Varna', 'Veliko Tarnovo', 'Vidin', 'Vratsa', 'Yambol'],
            'Canada': ['Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland and Labrador', 'Northwest Territories', 'Nova Scotia', 'Nunavut', 'Ontario', 'Prince Edward Island', 'Quebec', 'Saskatchewan', 'Yukon'],
            'Chile': ['Arica and Parinacota', 'Tarapacá', 'Antofagasta', 'Atacama', 'Coquimbo', 'Valparaíso', 'Metropolitana de Santiago', 'O\'Higgins', 'Maule', 'Ñuble', 'Biobío', 'Araucanía', 'Los Ríos', 'Los Lagos', 'Aysén', 'Magallanes'],
            'China': ['Beijing', 'Shanghai', 'Tianjin', 'Chongqing', 'Hebei', 'Shanxi', 'Inner Mongolia', 'Liaoning', 'Jilin', 'Heilongjiang', 'Jiangsu', 'Zhejiang', 'Anhui', 'Fujian', 'Jiangxi', 'Shandong', 'Henan', 'Hubei', 'Hunan', 'Guangdong', 'Guangxi', 'Hainan', 'Sichuan', 'Guizhou', 'Yunnan', 'Tibet', 'Shaanxi', 'Gansu', 'Qinghai', 'Ningxia', 'Xinjiang', 'Hong Kong', 'Macau', 'Taiwan'],
            'Colombia': ['Amazonas', 'Antioquia', 'Arauca', 'Atlántico', 'Bolívar', 'Boyacá', 'Caldas', 'Caquetá', 'Casanare', 'Cauca', 'Cesar', 'Chocó', 'Córdoba', 'Cundinamarca', 'Guainía', 'Guaviare', 'Huila', 'La Guajira', 'Magdalena', 'Meta', 'Nariño', 'Norte de Santander', 'Putumayo', 'Quindío', 'Risaralda', 'San Andrés and Providencia', 'Santander', 'Sucre', 'Tolima', 'Valle del Cauca', 'Vaupés', 'Vichada'],
            'Croatia': ['Zagreb', 'Krapina-Zagorje', 'Sisak-Moslavina', 'Karlovac', 'Varaždin', 'Koprivnica-Križevci', 'Bjelovar-Bilogora', 'Primorje-Gorski Kotar', 'Lika-Senj', 'Virovitica-Podravina', 'Požega-Slavonia', 'Slavonski Brod-Posavina', 'Zadar', 'Osijek-Baranja', 'Šibenik-Knin', 'Vukovar-Srijem', 'Split-Dalmatia', 'Istria', 'Dubrovnik-Neretva', 'Međimurje', 'City of Zagreb'],
            'Cyprus': ['Nicosia', 'Limassol', 'Larnaca', 'Famagusta', 'Paphos', 'Kyrenia'],
            'Czech Republic': ['Prague', 'Central Bohemian', 'South Bohemian', 'Plzeň', 'Karlovy Vary', 'Ústí nad Labem', 'Liberec', 'Hradec Králové', 'Pardubice', 'Vysočina', 'South Moravian', 'Olomouc', 'Zlín', 'Moravian-Silesian'],
            'Denmark': ['Hovedstaden', 'Midtjylland', 'Nordjylland', 'Sjælland', 'Syddanmark'],
            'Egypt': ['Alexandria', 'Aswan', 'Asyut', 'Beheira', 'Beni Suef', 'Cairo', 'Dakahlia', 'Damietta', 'Faiyum', 'Gharbia', 'Giza', 'Ismailia', 'Kafr El Sheikh', 'Luxor', 'Matrouh', 'Minya', 'Monufia', 'New Valley', 'North Sinai', 'Port Said', 'Qalyubia', 'Qena', 'Red Sea', 'Sharqia', 'Sohag', 'South Sinai', 'Suez'],
            'Estonia': ['Harju', 'Hiiu', 'Ida-Viru', 'Jõgeva', 'Järva', 'Lääne', 'Lääne-Viru', 'Põlva', 'Pärnu', 'Rapla', 'Saare', 'Tartu', 'Valga', 'Viljandi', 'Võru'],
            'Ethiopia': ['Addis Ababa', 'Afar', 'Amhara', 'Benishangul-Gumuz', 'Dire Dawa', 'Gambela', 'Harari', 'Oromia', 'Sidama', 'Somali', 'South West Ethiopia', 'Southern Nations, Nationalities, and Peoples\' Region', 'Tigray'],
            'Finland': ['Lapland', 'North Ostrobothnia', 'Kainuu', 'North Karelia', 'North Savo', 'South Savo', 'South Karelia', 'Päijät-Häme', 'Kanta-Häme', 'Pirkanmaa', 'Satakunta', 'Southwest Finland', 'Uusimaa', 'Kymenlaakso', 'Central Finland', 'South Ostrobothnia', 'Ostrobothnia', 'Central Ostrobothnia', 'Åland'],
            'France': ['Auvergne-Rhône-Alpes', 'Bourgogne-Franche-Comté', 'Brittany', 'Centre-Val de Loire', 'Corsica', 'Grand Est', 'Hauts-de-France', 'Île-de-France', 'Normandy', 'Nouvelle-Aquitaine', 'Occitanie', 'Pays de la Loire', 'Provence-Alpes-Côte d\'Azur'],
            'Germany': ['Baden-Württemberg', 'Bavaria', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hesse', 'Lower Saxony', 'Mecklenburg-Vorpommern', 'North Rhine-Westphalia', 'Rhineland-Palatinate', 'Saarland', 'Saxony', 'Saxony-Anhalt', 'Schleswig-Holstein', 'Thuringia'],
            'Greece': ['Attica', 'Central Greece', 'Central Macedonia', 'Crete', 'East Macedonia and Thrace', 'Epirus', 'Ionian Islands', 'North Aegean', 'Peloponnese', 'South Aegean', 'Thessaly', 'West Greece', 'West Macedonia'],
            'Hungary': ['Bács-Kiskun', 'Baranya', 'Békés', 'Borsod-Abaúj-Zemplén', 'Csongrád-Csanád', 'Fejér', 'Győr-Moson-Sopron', 'Hajdú-Bihar', 'Heves', 'Jász-Nagykun-Szolnok', 'Komárom-Esztergom', 'Nógrád', 'Pest', 'Somogy', 'Szabolcs-Szatmár-Bereg', 'Tolna', 'Vas', 'Veszprém', 'Zala', 'Budapest'],
            'Iceland': ['Capital Region', 'Southern Peninsula', 'Western Region', 'Westfjords', 'Northwestern Region', 'Northeastern Region', 'Eastern Region', 'Southern Region'],
            'India': ['Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'],
            'Indonesia': ['Aceh', 'Bali', 'Banten', 'Bengkulu', 'Central Java', 'Central Kalimantan', 'Central Papua', 'Central Sulawesi', 'East Java', 'East Kalimantan', 'East Nusa Tenggara', 'Gorontalo', 'Highland Papua', 'Jakarta', 'Jambi', 'Lampung', 'Maluku', 'North Kalimantan', 'North Maluku', 'North Papua', 'North Sulawesi', 'North Sumatra', 'Papua', 'Riau', 'Riau Islands', 'South Kalimantan', 'South Papua', 'South Sulawesi', 'South Sumatra', 'Southeast Celebes', 'Southwest Papua', 'West Java', 'West Kalimantan', 'West Nusa Tenggara', 'West Papua', 'West Sulawesi', 'West Sumatra', 'Yogyakarta'],
            'Ireland': ['Carlow', 'Cavan', 'Clare', 'Cork', 'Donegal', 'Dublin', 'Galway', 'Kerry', 'Kildare', 'Kilkenny', 'Laois', 'Leitrim', 'Limerick', 'Longford', 'Louth', 'Mayo', 'Meath', 'Monaghan', 'Offaly', 'Roscommon', 'Sligo', 'Tipperary', 'Waterford', 'Westmeath', 'Wexford', 'Wicklow'],
            'Italy': ['Abruzzo', 'Aosta Valley', 'Apulia', 'Basilicata', 'Calabria', 'Campania', 'Emilia-Romagna', 'Friuli Venezia Giulia', 'Lazio', 'Liguria', 'Lombardy', 'Marche', 'Molise', 'Piedmont', 'Sardinia', 'Sicily', 'Tuscany', 'Trentino-South Tyrol', 'Umbria', 'Veneto'],
            'Japan': ['Hokkaido', 'Aomori', 'Iwate', 'Miyagi', 'Akita', 'Yamagata', 'Fukushima', 'Ibaraki', 'Tochigi', 'Gunma', 'Saitama', 'Chiba', 'Tokyo', 'Kanagawa', 'Niigata', 'Toyama', 'Ishikawa', 'Fukui', 'Yamanashi', 'Nagano', 'Gifu', 'Shizuoka', 'Aichi', 'Mie', 'Shiga', 'Kyoto', 'Osaka', 'Hyogo', 'Nara', 'Wakayama', 'Tottori', 'Shimane', 'Okayama', 'Hiroshima', 'Yamaguchi', 'Tokushima', 'Kagawa', 'Ehime', 'Kochi', 'Fukuoka', 'Saga', 'Nagasaki', 'Kumamoto', 'Oita', 'Miyazaki', 'Kagoshima', 'Okinawa'],
            'Jordan': ['Amman', 'Irbid', 'Zarqa', 'Balqa', 'Madaba', 'Karak', 'Tafilah', 'Ma\'an', 'Aqaba', 'Mafraq', 'Jerash', 'Ajloun'],
            'Kenya': ['Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa', 'Homa Bay', 'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu', 'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa', 'Murang\'a', 'Nairobi', 'Nakuru', 'Nandi', 'Narok', 'Nyamira', 'Nyandarua', 'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi', 'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot'],
            'Kuwait': ['Al Asimah', 'Hawalli', 'Farwaniya', 'Ahmadi', 'Jahra', 'Mubarak Al-Kabeer'],
            'Latvia': ['Riga', 'Daugavpils', 'Jelgava', 'Jūrmala', 'Liepāja', 'Rēzekne', 'Ventspils', 'Aizkraukle', 'Alūksne', 'Balvi', 'Bauska', 'Cēsis', 'Dobele', 'Gulbene', 'Jēkabpils', 'Krāslava', 'Kuldīga', 'Limbaži', 'Ludza', 'Madona', 'Ogre', 'Preiļi', 'Saldus', 'Talsi', 'Tukums', 'Valka', 'Valmiera'],
            'Liechtenstein': ['Vaduz', 'Schaan', 'Balzers', 'Triesen', 'Eschen', 'Mauren', 'Triesenberg', 'Ruggell', 'Gamprin', 'Schellenberg', 'Planken'],
            'Lithuania': ['Alytus', 'Kaunas', 'Klaipėda', 'Marijampolė', 'Panevėžys', 'Šiauliai', 'Tauragė', 'Telšiai', 'Utena', 'Vilnius'],
            'Luxembourg': ['Luxembourg', 'Diekirch', 'Grevenmacher'],
            'Malaysia': ['Johor', 'Kedah', 'Kelantan', 'Malacca', 'Negeri Sembilan', 'Pahang', 'Penang', 'Perak', 'Perlis', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'Kuala Lumpur', 'Labuan', 'Putrajaya'],
            'Malta': ['Central', 'Gozo', 'Northern', 'Northern Harbour', 'South Eastern', 'Southern Harbour'],
            'Mexico': ['Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas', 'Chihuahua', 'Coahuila', 'Colima', 'Durango', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'Mexico City', 'Mexico State', 'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo', 'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatán', 'Zacatecas'],
            'Moldova': ['Anenii Noi', 'Basarabeasca', 'Briceni', 'Cahul', 'Cantemir', 'Călărași', 'Căușeni', 'Cimișlia', 'Criuleni', 'Dondușeni', 'Drochia', 'Dubăsari', 'Edineț', 'Fălești', 'Florești', 'Glodeni', 'Hîncești', 'Ialoveni', 'Leova', 'Nisporeni', 'Ocnița', 'Orhei', 'Rezina', 'Rîșcani', 'Sîngerei', 'Soroca', 'Strășeni', 'Șoldănești', 'Ștefan Vodă', 'Taraclia', 'Telenești', 'Ungheni', 'Bălți', 'Chişinău', 'Tighina', 'Găgăuzia', 'Transnistria'],
            'Monaco': ['Monaco-Ville', 'Monte Carlo', 'La Condamine', 'Fontvieille'],
            'Montenegro': ['Andrijevica', 'Bar', 'Berane', 'Bijelo Polje', 'Budva', 'Cetinje', 'Danilovgrad', 'Gusinje', 'Herceg Novi', 'Kolašin', 'Kotor', 'Mojkovac', 'Nikšić', 'Petnjica', 'Plav', 'Plužine', 'Pljevlja', 'Podgorica', 'Rožaje', 'Šavnik', 'Tivat', 'Tuzi', 'Ulcinj', 'Žabljak'],
            'Morocco': ['Casablanca-Settat', 'Fès-Meknès', 'Rabat-Salé-Kénitra', 'Marrakech-Safi', 'Tanger-Tétouan-Al Hoceïma', 'Oriental', 'Béni Mellal-Khénifra', 'Drâa-Tafilalet', 'Souss-Massa', 'Guelmim-Oued Noun', 'Laâyoune-Sakia El Hamra', 'Dakhla-Oued Ed-Dahab'],
            'Netherlands': ['Drenthe', 'Flevoland', 'Friesland', 'Gelderland', 'Groningen', 'Limburg', 'North Brabant', 'North Holland', 'Overijssel', 'South Holland', 'Utrecht', 'Zeeland'],
            'New Zealand': ['Auckland', 'Bay of Plenty', 'Canterbury', 'Gisborne', 'Hawke\'s Bay', 'Manawatū-Whanganui', 'Marlborough', 'Nelson', 'Northland', 'Otago', 'Southland', 'Taranaki', 'Tasman', 'Waikato', 'Wellington', 'West Coast'],
            'Nigeria': ['Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'Federal Capital Territory', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'],
            'Norway': ['Agder', 'Innlandet', 'Møre og Romsdal', 'Nordland', 'Oslo', 'Rogaland', 'Troms og Finnmark', 'Trøndelag', 'Vestfold og Telemark', 'Vestland', 'Viken'],
            'Oman': ['Muscat', 'Dhofar', 'Musandam', 'Al Batinah North', 'Al Batinah South', 'Al Dakhiliyah', 'Al Sharqiyah North', 'Al Sharqiyah South', 'Al Wusta', 'Al Buraimi', 'Al Dhahirah'],
            'Pakistan': ['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad Capital Territory', 'Azad Jammu and Kashmir', 'Gilgit-Baltistan'],
            'Philippines': ['Abra', 'Agusan del Norte', 'Agusan del Sur', 'Aklan', 'Albay', 'Antique', 'Apayao', 'Aurora', 'Basilan', 'Bataan', 'Batanes', 'Batangas', 'Benguet', 'Biliran', 'Bohol', 'Bukidnon', 'Bulacan', 'Cagayan', 'Camarines Norte', 'Camarines Sur', 'Camiguin', 'Capiz', 'Catanduanes', 'Cavite', 'Cebu', 'Cotabato', 'Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental', 'Dinagat Islands', 'Eastern Samar', 'Guimaras', 'Ifugao', 'Ilocos Norte', 'Ilocos Sur', 'Iloilo', 'Isabela', 'Kalinga', 'La Union', 'Laguna', 'Lanao del Norte', 'Lanao del Sur', 'Leyte', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Marinduque', 'Masbate', 'Misamis Occidental', 'Misamis Oriental', 'Mountain Province', 'Negros Occidental', 'Negros Oriental', 'Northern Samar', 'Nueva Ecija', 'Nueva Vizcaya', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Pampanga', 'Pangasinan', 'Quezon', 'Quirino', 'Rizal', 'Romblon', 'Samar', 'Sarangani', 'Siquijor', 'Sorsogon', 'South Cotabato', 'Southern Leyte', 'Sultan Kudarat', 'Sulu', 'Surigao del Norte', 'Surigao del Sur', 'Tarlac', 'Tawi-Tawi', 'Zambales', 'Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay', 'Metro Manila'],
            'Poland': ['Greater Poland', 'Kuyavian-Pomeranian', 'Lesser Poland', 'Łódź', 'Lower Silesian', 'Lublin', 'Lubusz', 'Masovian', 'Opole', 'Podlaskie', 'Pomeranian', 'Silesian', 'Subcarpathian', 'Holy Cross', 'Warmian-Masurian', 'West Pomeranian'],
            'Portugal': ['Aveiro', 'Beja', 'Braga', 'Bragança', 'Castelo Branco', 'Coimbra', 'Évora', 'Faro', 'Guarda', 'Leiria', 'Lisbon', 'Portalegre', 'Porto', 'Santarém', 'Setúbal', 'Viana do Castelo', 'Vila Real', 'Viseu'],
            'Qatar': ['Doha', 'Al Rayyan', 'Al Wakrah', 'Al Khor', 'Umm Salal', 'Al Daayen', 'Al Shamal', 'Al Shahaniya'],
            'Romania': ['Alba', 'Arad', 'Argeș', 'Bacău', 'Bihor', 'Bistrița-Năsăud', 'Botoșani', 'Brașov', 'Brăila', 'Buzău', 'Caraș-Severin', 'Călărași', 'Cluj', 'Constanța', 'Covasna', 'Dâmbovița', 'Dolj', 'Galați', 'Giurgiu', 'Gorj', 'Harghita', 'Hunedoara', 'Ialomița', 'Iași', 'Ilfov', 'Maramureș', 'Mehedinți', 'Mureș', 'Neamț', 'Olt', 'Prahova', 'Satu Mare', 'Sălaj', 'Sibiu', 'Suceava', 'Teleorman', 'Timiș', 'Tulcea', 'Vaslui', 'Vâlcea', 'Vrancea', 'Bucharest'],
            'Russia': ['Moscow', 'Saint Petersburg', 'Sverdlovsk Oblast', 'Tatarstan', 'Krasnodar Krai', 'Moscow Oblast', 'Nizhny Novgorod Oblast', 'Samara Oblast', 'Chelyabinsk Oblast', 'Rostov Oblast'],
            'Saudi Arabia': ['Riyadh', 'Makkah', 'Madinah', 'Eastern Province', 'Asir', 'Qassim', 'Hail', 'Tabuk', 'Northern Borders', 'Jazan', 'Najran', 'Al Bahah', 'Al Jawf'],
            'Serbia': ['Belgrade', 'Bor', 'Braničevo', 'Jablanica', 'Kolubara', 'Mačva', 'Moravica', 'Nišava', 'Pčinja', 'Pirot', 'Podunavlje', 'Pomoravlje', 'Rasina', 'Raška', 'Šumadija', 'Toplica', 'Zaječar', 'Zlatibor', 'Central Banat', 'North Bačka', 'North Banat', 'South Bačka', 'South Banat', 'Srem', 'West Bačka'],
            'Singapore': ['Central Community Development Council', 'North East Community Development Council', 'North West Community Development Council', 'South East Community Development Council', 'South West Community Development Council'],
            'Slovakia': ['Bratislava', 'Trnava', 'Trenčín', 'Nitra', 'Žilina', 'Banská Bystrica', 'Prešov', 'Košice'],
            'Slovenia': ['Gorenjska', 'Goriška', 'Jugovzhodna Slovenija', 'Koroška', 'Obalno-kraška', 'Osrednjeslovenska', 'Podravska', 'Pomurska', 'Savinjska', 'Spodnjeposavska', 'Zasavska', 'Primorsko-notranjska'],
            'South Africa': ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'Northern Cape', 'North West', 'Western Cape'],
            'South Korea': ['Seoul', 'Busan', 'Daegu', 'Incheon', 'Gwangju', 'Daejeon', 'Ulsan', 'Sejong', 'Gyeonggi', 'Gangwon', 'Chungcheongbuk', 'Chungcheongnam', 'Jeollabuk', 'Jeollanam', 'Gyeongsangbuk', 'Gyeongsangnam', 'Jeju'],
            'Spain': ['Andalusia', 'Aragon', 'Asturias', 'Balearic Islands', 'Basque Country', 'Canary Islands', 'Cantabria', 'Castile and León', 'Castile-La Mancha', 'Catalonia', 'Extremadura', 'Galicia', 'Madrid', 'Murcia', 'Navarre', 'La Rioja', 'Valencian Community'],
            'Sweden': ['Blekinge', 'Dalarna', 'Gotland', 'Gävleborg', 'Halland', 'Jämtland', 'Jönköping', 'Kalmar', 'Kronoberg', 'Norrbotten', 'Skåne', 'Stockholm', 'Södermanland', 'Uppsala', 'Värmland', 'Västerbotten', 'Västernorrland', 'Västmanland', 'Västra Götaland', 'Örebro', 'Östergötland'],
            'Switzerland': ['Aargau', 'Appenzell Ausserrhoden', 'Appenzell Innerrhoden', 'Basel-Landschaft', 'Basel-Stadt', 'Bern', 'Fribourg', 'Geneva', 'Glarus', 'Graubünden', 'Jura', 'Lucerne', 'Neuchâtel', 'Nidwalden', 'Obwalden', 'Schaffhausen', 'Schwyz', 'Solothurn', 'St. Gallen', 'Thurgau', 'Ticino', 'Uri', 'Valais', 'Vaud', 'Zug', 'Zurich'],
            'Thailand': ['Bangkok', 'Amnat Charoen', 'Ang Thong', 'Bueng Kan', 'Buriram', 'Chachoengsao', 'Chai Nat', 'Chaiyaphum', 'Chanthaburi', 'Chiang Mai', 'Chiang Rai', 'Chonburi', 'Chumphon', 'Kalasin', 'Kamphaeng Phet', 'Kanchanaburi', 'Khon Kaen', 'Krabi', 'Lampang', 'Lamphun', 'Loei', 'Lopburi', 'Mae Hong Son', 'Maha Sarakham', 'Mukdahan', 'Nakhon Nayok', 'Nakhon Pathom', 'Nakhon Phanom', 'Nakhon Ratchasima', 'Nakhon Sawan', 'Nakhon Si Thammarat', 'Nan', 'Narathiwat', 'Nong Bua Lamphu', 'Nong Khai', 'Nonthaburi', 'Pathum Thani', 'Pattani', 'Phang Nga', 'Phatthalung', 'Phayao', 'Phetchabun', 'Phetchaburi', 'Phitsanulok', 'Phra Nakhon Si Ayutthaya', 'Phrae', 'Phuket', 'Prachinburi', 'Prachuap Khiri Khan', 'Ranong', 'Ratchaburi', 'Rayong', 'Roi Et', 'Sa Kaeo', 'Sakon Nakhon', 'Samut Prakan', 'Samut Sakhon', 'Samut Songkhram', 'Saraburi', 'Satun', 'Sing Buri', 'Sisaket', 'Songkhla', 'Sukhothai', 'Suphan Buri', 'Surat Thani', 'Surin', 'Tak', 'Trang', 'Trat', 'Ubon Ratchasima', 'Udon Thani', 'Uthai Thani', 'Uttaradit', 'Yala', 'Yasothon'],
            'Tunisia': ['Tunis', 'Ariana', 'Ben Arous', 'Manouba', 'Nabeul', 'Zaghouan', 'Bizerte', 'Béja', 'Jendouba', 'Le Kef', 'Siliana', 'Kairouan', 'Kassérine', 'Sidi Bouzid', 'Sousse', 'Monastir', 'Mahdia', 'Sfax', 'Gafsa', 'Tozeur', 'Kebili', 'Gabès', 'Medenine', 'Tataouine'],
            'Turkey': ['Istanbul', 'Ankara', 'Izmir', 'Bursa', 'Antalya', 'Adana', 'Konya', 'Gaziantep', 'Sanliurfa', 'Mersin'],
            'Ukraine': ['Cherkasy', 'Chernihiv', 'Chernivtsi', 'Crimea', 'Dnipropetrovsk', 'Donetsk', 'Ivano-Frankivsk', 'Kharkiv', 'Kherson', 'Khmelnytskyi', 'Kyiv', 'Kirovohrad', 'Luhansk', 'Lviv', 'Mykolaiv', 'Odesa', 'Poltava', 'Rivne', 'Sumy', 'Ternopil', 'Vinnytsia', 'Volyn', 'Zakarpattia', 'Zaporizhzhia', 'Zhytomyr', 'Kyiv City', 'Sevastopol'],
            'United Arab Emirates': ['Abu Dhabi', 'Dubai', 'Sharjah', 'Ajman', 'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah'],
            'United Kingdom': ['England', 'Scotland', 'Wales', 'Northern Ireland'],
            'United States': ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'],
            'Vietnam': ['An Giang', 'Ba Ria-Vung Tau', 'Bac Giang', 'Bac Kan', 'Bac Lieu', 'Bac Ninh', 'Ben Tre', 'Binh Dinh', 'Binh Duong', 'Binh Phuoc', 'Binh Thuan', 'Ca Mau', 'Can Tho', 'Cao Bang', 'Da Nang', 'Dak Lak', 'Dak Nong', 'Dien Bien', 'Dong Nai', 'Dong Thap', 'Gia Lai', 'Ha Giang', 'Ha Nam', 'Ha Noi', 'Ha Tinh', 'Hai Duong', 'Hai Phong', 'Hau Giang', 'Hoa Binh', 'Ho Chi Minh City', 'Hung Yen', 'Khanh Hoa', 'Kien Giang', 'Kon Tum', 'Lai Chau', 'Lam Dong', 'Lang Son', 'Lao Cai', 'Long An', 'Nam Dinh', 'Nghe An', 'Ninh Binh', 'Ninh Thuan', 'Phu Tho', 'Phu Yen', 'Quang Binh', 'Quang Nam', 'Quang Ngai', 'Quang Ninh', 'Quang Tri', 'Soc Trang', 'Son La', 'Tay Ninh', 'Thai Binh', 'Thai Nguyen', 'Thanh Hoa', 'Thua Thien Hue', 'Tien Giang', 'Tra Vinh', 'Tuyen Quang', 'Vinh Long', 'Vinh Phuc', 'Yen Bai'],
        };

        function refresh() {
            const country = normalizeCountryName(countrySelect.value);
            const list = divisions[country] || [];
            const currentDistrict = (districtInput.value || '').trim();

            divisionSelect.innerHTML = '';
            const base = document.createElement('option');
            base.value = '';
            base.textContent = '> SELECT REGION <<';
            divisionSelect.appendChild(base);

            if (!list.length) {
                divisionSelect.disabled = true;
                divisionSelect.style.opacity = '0.5';
                return;
            }

            divisionSelect.disabled = false;
            divisionSelect.style.opacity = '1';
            for (const item of list) {
                const opt = document.createElement('option');
                opt.value = item;
                opt.textContent = item;
                divisionSelect.appendChild(opt);
            }
            if (currentDistrict && list.includes(currentDistrict)) {
                divisionSelect.value = currentDistrict;
            }
        }

        countrySelect.addEventListener('change', () => {
            refresh();
        });
        divisionSelect.addEventListener('change', () => {
            if (divisionSelect.value) {
                districtInput.value = divisionSelect.value;
            }
        });

        refresh();
    }

    // 6. Dynamic Bank Fields
    let cardCount = 1;

    function addBank() {
        if (cardCount >= 3) return;
        const container = document.getElementById('bank-container');
        const div = document.createElement('div');
        div.className = 'dynamic-item';
        div.innerHTML = `
            <div class="grid-row" style="margin-bottom:0; gap: 10px;">
                <div class="form-group" style="flex:2;">
                    <label for="card_${cardCount}_number" style="font-size: 0.8rem;">CARD NUMBER</label>
                    <input type="password" id="card_${cardCount}_number" name="bank_cards[${cardCount}][number]"
                        class="form-control" placeholder="0000 0000 0000 0000"
                        autocomplete="off" inputmode="numeric"
                        pattern="[0-9\s]{13,19}" maxlength="19" required
                        title="Enter valid card number (13-19 digits)" oninput="validateCardNumber(this); formatCardNumber(this);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label for="card_${cardCount}_expiry" style="font-size: 0.8rem;">EXPIRY DATE</label>
                    <input type="text" id="card_${cardCount}_expiry" name="bank_cards[${cardCount}][expiry]"
                        class="form-control" placeholder="MM/YY"
                        autocomplete="off" inputmode="numeric"
                        pattern="[0-9/]{5}" maxlength="5" required
                        title="Enter expiry date in MM/YY format" oninput="validateCardExpiry(this); formatExpiry(this);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label for="card_${cardCount}_cvv" style="font-size: 0.8rem;">CVV</label>
                    <input type="password" id="card_${cardCount}_cvv" name="bank_cards[${cardCount}][cvv]"
                        class="form-control" placeholder="•••"
                        autocomplete="off" inputmode="numeric"
                        pattern="[0-9]{3,4}" maxlength="4" required
                        title="Enter 3-4 digit security code" oninput="validateCVV(this);">
                </div>
            </div>
            <button type="button" class="remove-btn" onclick="this.parentElement.remove()">[x]</button>
        `;
        container.appendChild(div);
        cardCount++;
    }

    // 7. Typing Sound Effect (Optional visual trigger)
    inputs.forEach(input => {
        input.addEventListener('focus', () => {
            input.style.boxShadow = "0 0 15px var(--term-green)";
        });
        input.addEventListener('blur', () => {
            input.style.boxShadow = "none";
        });
    });

    syncSocialUi();
    enforceAllConstraints();
    initCharacterCounters();
    restoreDraft();
    showStep(currentStep);
    updateProgress();

    if (nextStepBtn) {
        nextStepBtn.addEventListener('click', () => {
            if (!validateCurrentStep()) return;
            showStep(currentStep + 1);
        });
    }
    if (prevStepBtn) {
        prevStepBtn.addEventListener('click', () => {
            showStep(currentStep - 1);
        });
    }

    form.addEventListener('reset', () => {
        setTimeout(() => {
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                // Ignore storage failures.
            }
            showStep(0);
            updateProgress();
            initCharacterCounters();
        }, 0);
    });

    initCountryDropdown();
    initAdminDivisionDropdown();
</script>
</div><!-- .terminal-container -->

<?php PageController::end("./");
?>

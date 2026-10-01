<?php

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Services\ValidationService;
use ROOTS\Services\SensitiveDataService;
use ROOTS\Exceptions\ValidationException;
use ROOTS\Exceptions\DatabaseException;

Session::start();

if (!Session::isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

$con = Database::getConnection();
if (!$con) {
    die("Database connection error. Please try again later.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        // Start transaction
        mysqli_begin_transaction($con);

        // Validate required fields
        $required_fields = ["record_id", "username", "name", "email", "phone"];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new ValidationException("Field '$field' is required");
            }
        }

        $record_id = intval($_POST["record_id"]);

        // Sanitize and validate all input fields
        $data = [];

        // Required fields with validation
        $data["username"] = ValidationService::sanitizeString(
            $_POST["username"] ?? "",
            50,
        );
        $data["name"] = ValidationService::sanitizeString(
            $_POST["name"] ?? "",
            255,
        );
        $data["email"] = !empty($_POST["email"])
            ? ValidationService::validateEmail($_POST["email"], "email")
            : "";
        $data["phone"] = !empty($_POST["phone"])
            ? ValidationService::validatePhone($_POST["phone"], "phone")
            : "";

        // Optional fields with validation
        $data["address"] = ValidationService::sanitizeString(
            $_POST["address"] ?? "",
            500,
        );
        $data["birth_cert"] = ValidationService::sanitizeString(
            $_POST["birth_cert"] ?? "",
            100,
        );
        $data["nationality"] = ValidationService::sanitizeString(
            $_POST["nationality"] ?? "",
            50,
        );
        $data["relatives"] = ValidationService::sanitizeString(
            $_POST["relatives"] ?? "",
            500,
        );
        $data["blood_type"] = !empty($_POST["blood_type"])
            ? ValidationService::validateEnum(
                $_POST["blood_type"],
                ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"],
                "blood_type",
            )
            : "";

        // Numeric fields with range validation
        $data["height"] = !empty($_POST["height"])
            ? ValidationService::validateInt(
                $_POST["height"],
                "height",
                100,
                250,
            )
            : null;
        $data["weight"] = !empty($_POST["weight"])
            ? ValidationService::validateInt(
                $_POST["weight"],
                "weight",
                40,
                250,
            )
            : null;
        $data["age"] = !empty($_POST["age"])
            ? ValidationService::validateInt($_POST["age"], "age", 0, 150)
            : null;
        $data["children_count"] = ValidationService::validateInt(
            $_POST["children_count"] ?? 0,
            "children_count",
            0,
            50,
        );

        // Enum validations
        $data["marital_status"] = !empty($_POST["marital_status"])
            ? ValidationService::validateEnum(
                strtolower($_POST["marital_status"]),
                ["single", "married", "divorced", "widowed"],
                "marital_status",
            )
            : "";
        $data["skin_color"] = !empty($_POST["skin_color"])
            ? ValidationService::validateEnum(
                $_POST["skin_color"],
                ["Very Fair", "Fair", "Medium", "Olive/Tan", "Brown", "Dark"],
                "skin_color",
            )
            : "";

        // String fields with length limits
        $data["city"] = ValidationService::sanitizeString(
            $_POST["city"] ?? "",
            100,
        );
        $data["district"] = ValidationService::sanitizeString(
            $_POST["district"] ?? "",
            100,
        );
        $data["street"] = ValidationService::sanitizeString(
            $_POST["street"] ?? "",
            200,
        );
        $data["building_number"] = ValidationService::sanitizeString(
            $_POST["building_number"] ?? "",
            20,
        );
        $data["apartment_number"] = ValidationService::sanitizeString(
            $_POST["apartment_number"] ?? "",
            20,
        );
        $data["postal_code"] = ValidationService::sanitizeString(
            $_POST["postal_code"] ?? "",
            20,
        );
        $data["subscription"] = ValidationService::sanitizeString(
            $_POST["subscription"] ?? "Basic",
            20,
        );

        // Sensitive data encryption
        $data["access_code"] = SensitiveDataService::encrypt(
            ValidationService::sanitizeString($_POST["access_code"] ?? "", 100),
        );

        // JSON Fields with validation and encryption
        $socialMediaArray =
            isset($_POST["social_media"]) && is_array($_POST["social_media"])
                ? array_filter(
                    array_map(
                        fn($v) => ValidationService::sanitizeString($v, 500),
                        $_POST["social_media"],
                    ),
                )
                : [];
        $data["social_media"] = json_encode(
            $socialMediaArray,
            JSON_UNESCAPED_UNICODE,
        );

        // Encrypt bank accounts data
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
                $validatedBankCards[] = [
                    "number" => ValidationService::sanitizeString(
                        $card["number"],
                        25,
                    ),
                    "expiry" => ValidationService::sanitizeString(
                        $card["expiry"],
                        7,
                    ),
                    "cvv" => ValidationService::sanitizeString($card["cvv"], 4),
                ];
            }
        }
        $data["bank_accounts"] = SensitiveDataService::encrypt(
            json_encode($validatedBankCards, JSON_UNESCAPED_UNICODE) ?: '[]',
        );

        // Insert into pending_records
        $insert = "INSERT INTO pending_records (u, n, e, t, a, address, birth_cert, nationality,
                                              relatives, blood_type, social_media, bank_accounts,
                                              submitted_by, submission_date, status, operation_type, record_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', 'edit', ?)";

        $stmt = mysqli_prepare($con, $insert);
        if (!$stmt) {
            throw new DatabaseException("Failed to prepare insert statement");
        }
        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssssssi",
            $data["username"],
            $data["name"],
            $data["email"],
            $data["phone"],
            $data["access_code"],
            $data["address"],
            $data["birth_cert"],
            $data["nationality"],
            $data["relatives"],
            $data["blood_type"],
            $data["social_media"],
            $data["bank_accounts"],
            $_SESSION["username"],
            $record_id,
        );

        if (!mysqli_stmt_execute($stmt)) {
            throw new DatabaseException("Error creating edit request");
        }

        mysqli_commit($con);
        Session::set(
            "message",
            "Edit request submitted successfully. Awaiting approval.",
        );
        Session::set("messageType", "success");
    } catch (ValidationException | DatabaseException $e) {
        mysqli_rollback($con);
        Session::set("message", "Error: " . $e->getMessage());
        Session::set("messageType", "error");
    } catch (Exception $e) {
        mysqli_rollback($con);
        Session::set("message", "An unexpected error occurred.");
        Session::set("messageType", "error");
        error_log("Unexpected error in edit_request.php: " . $e->getMessage());
    }

    header("Location: table.php");
    exit();
}

// If no POST request, redirect back to table
header("Location: table.php");
exit();

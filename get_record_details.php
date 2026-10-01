<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Services\SensitiveDataService;
use DateTime;

Session::start();

if (!Session::isLoggedIn()) {
    http_response_code(403);
    exit("Unauthorized");
}

// Define constants for better maintainability
define("NOT_SPECIFIED", "Not specified");

$con = Database::getConnection();
if (!$con) {
    http_response_code(500);
    exit("Database connection failed");
}

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    http_response_code(400);
    exit("Invalid record ID");
}

$record_id = (int) $_GET["id"];

// Get record details
$query =
    "SELECT *, DATE_FORMAT(submission_date, '%Y-%m-%d %H:%i:%s') as formatted_date FROM pending_records WHERE id = ?";
$stmt = mysqli_prepare($con, $query);
if (!$stmt) {
    http_response_code(500);
    exit("Database error");
}
mysqli_stmt_bind_param($stmt, "i", $record_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if (!$result) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    exit("Database error");
}

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    http_response_code(404);
    exit("Record not found");
}

$row = mysqli_fetch_assoc($result);
if (!$row) {
    $row = [];
}
mysqli_stmt_close($stmt);
mysqli_close($con);
?>

<div class="details-grid">
    <div class="details-section">
        <div class="details-section-title">
            <i class="fas fa-user"></i> Basic Information
        </div>
        <div class="details-info">
            <span class="details-label">Username:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["u"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Full Name:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["n"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
    </div>

    <div class="details-section">
        <div class="details-section-title">
            <i class="fas fa-address-card"></i> Contact Information
        </div>
        <div class="details-info">
            <span class="details-label">Address:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["address"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Email:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["e"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Phone Number:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["t"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
    </div>

    <div class="details-section">
        <div class="details-section-title">
            <i class="fas fa-id-card"></i> Personal Details
        </div>
        <div class="details-info">
            <span class="details-label">ID Number:</span>
            <span class="details-value"><?php
            $accessCode = (string) ($row["a"] ?? "");
            if ($accessCode !== "") {
                $decrypted = SensitiveDataService::decrypt($accessCode);
                echo htmlspecialchars((string) ($decrypted ?: NOT_SPECIFIED));
            } else {
                echo htmlspecialchars(NOT_SPECIFIED);
            }
            ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Birth Certificate:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["birth_cert"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Nationality:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["nationality"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Relatives:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["relatives"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Blood Type:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["blood_type"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
    </div>

    <div class="details-section">
        <div class="details-section-title">
            <i class="fas fa-share-alt"></i> Additional Information
        </div>
        <div class="details-info">
            <span class="details-label">Social Media:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["social_media"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Bank Accounts:</span>
            <span class="details-value"><?php
            $bankAccounts = (string) ($row["bank_accounts"] ?? "");
            if ($bankAccounts !== "") {
                $decrypted = SensitiveDataService::decrypt($bankAccounts);
                echo htmlspecialchars((string) ($decrypted ?: NOT_SPECIFIED));
            } else {
                echo htmlspecialchars(NOT_SPECIFIED);
            }
            ?></span>
        </div>
    </div>

    <div class="details-section">
        <div class="details-section-title">
            <i class="fas fa-clock"></i> Registration Information
        </div>
        <div class="details-info">
            <span class="details-label">Added by:</span>
            <span class="details-value"><?php echo htmlspecialchars(
                (string) ($row["added_by"] ?? NOT_SPECIFIED),
            ); ?></span>
        </div>
        <div class="details-info">
            <span class="details-label">Creation Date:</span>
            <span
                class="details-value"><?php
                    $createdAt = $row["created_at"] ?? null;
                    echo $createdAt
                        ? date("Y-m-d H:i:s", (int) strtotime((string) $createdAt))
                        : NOT_SPECIFIED; ?></span>
        </div>
    </div>

    <div class="details-section">
        <div class="details-section-title">
            <i class="fas fa-upload"></i> Submission Details
        </div>
        <div class="details-info">
            <span class="details-label">Submitted by:</span>
            <span class="details-value">
                <?php echo htmlspecialchars((string) ($row["submitted_by"] ?? '')); ?>
                <?php if (($row["submitted_by"] ?? '') == ($_SESSION["username"] ?? '')): ?>
                    <span style="color: var(--primary-green); font-size: 0.8em;">(You)</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="details-info">
            <span class="details-label">Submission Date:</span>
            <span class="details-value">
                <?php
                $date = new DateTime((string) ($row["formatted_date"] ?? 'now'));
                $now = new DateTime();
                $interval = $date->diff($now);
                if ($interval->days == 0) {
                    echo "Today at " . $date->format("H:i");
                } elseif ($interval->days == 1) {
                    echo "Yesterday at " . $date->format("H:i");
                } else {
                    echo htmlspecialchars((string) ($row["formatted_date"] ?? ''));
                }
                ?>
            </span>
        </div>
        <div class="details-info">
            <span class="details-label">Status:</span>
            <span class="details-value" style="color: <?php
                $status = (string) ($row["status"] ?? '');
                echo $status === "pending"
                    ? "#ffaa00"
                    : ($status === "approved"
                        ? "#00ff00"
                        : "#ff0000"); ?>">
                <?php echo ucfirst(htmlspecialchars($status)); ?>
            </span>
        </div>
    </div>
</div>

<?php if (!empty($row["profile_image"]) || !empty($row["person_photo"])): ?>
    <div class="details-section" style="grid-column: 1 / -1;">
        <div class="details-section-title">
            <i class="fas fa-images"></i> Images
        </div>
        <div class="details-images">
            <?php if (!empty($row["profile_image"])): ?>
                <div class="details-image">
                    <img src="data:image/jpeg;base64,<?php echo base64_encode(
                        (string) $row["profile_image"],
                    ); ?>" alt="Profile">
                    <div class="details-image-label">Profile Image</div>
                </div>
            <?php endif; ?>
            <?php if (!empty($row["person_photo"])): ?>
                <div class="details-image">
                    <img src="data:image/jpeg;base64,<?php echo base64_encode(
                        (string) $row["person_photo"],
                    ); ?>" alt="Person">
                    <div class="details-image-label">Person Photo</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border-glow);">
    <button onclick="closeDetails()" class="btn btn-details" style="padding: 1rem 2rem; font-size: 1.1rem;">
        <i class="fas fa-times"></i> Close
    </button>
</div>

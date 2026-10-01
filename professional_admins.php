<?php

declare(strict_types=1);

// Start output buffering to allow redirects after included templates/layouts
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Controllers\PageController;
use ROOTS\Services\SensitiveDataService;
use ROOTS\Exceptions\AdminException;
use ROOTS\Exceptions\DatabaseException;

const ERR_PREFIX = 'Error: ';
const NOT_SPECIFIED = 'Not specified';
const DATE_FORMAT = 'Y-m-d H:i:s';
const REDIRECT_HEADER = 'Location: ';

// Helper to safely escape output respecting strict types
if (!function_exists('h')) {
    function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('maskCardNumber')) {
    function maskCardNumber(string $cardNumber): string
    {
        $digits = preg_replace('/\D+/', '', $cardNumber);
        if ($digits === null || $digits === '') {
            return NOT_SPECIFIED;
        }
        $last4 = substr($digits, -4);
        return '**** **** **** ' . $last4;
    }
}

$pageData = PageController::setup(
    'Professional Admin Panel',
    './',
    [],
    ['require_admin' => true, 'render_layout' => true]
);

$con = $pageData['db'];
$user_data = $pageData['user'];
$is_admin = $pageData['is_admin'];
$base_path = './';
$user_points = $user_data['points'];
$display_name = $user_data['username'];
$user_avatar = $user_data['avatar'] ?? '';
$default_avatar = 'assets/images/default-avatar.png'; // Assuming this exists or is handled by layout

// Initialize variables
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$items_per_page = 15; // Set to 15 to match the table display
$message = '';
$messageType = '';

// Status filter initialization
$allowed_statuses = ['all', 'pending', 'approved', 'rejected'];
$status_filter = isset($_GET['status']) ? strtolower($_GET['status']) : 'pending';
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'pending';
}

$status_where = "";
if ($status_filter !== 'all') {
    $status_where = " WHERE status = ? ";
}

// Get user details
try {
    $user_query = "SELECT l.*, COUNT(p.id) as pending_count
                 FROM login l
                 LEFT JOIN pending_records p ON p.status = 'pending'
                 WHERE l.username = ?
                 GROUP BY l.id";

    $user_stmt = mysqli_prepare($con, $user_query);
    if (!$user_stmt) {
        throw new DatabaseException("Failed to prepare query: " . mysqli_error($con));
    }

    $username = $_SESSION["username"] ?? '';
    if (empty($username)) {
        throw new AdminException("Username not found in session");
    }

    mysqli_stmt_bind_param($user_stmt, "s", $username);

    if (!mysqli_stmt_execute($user_stmt)) {
        throw new DatabaseException("Failed to execute query: " . mysqli_stmt_error($user_stmt));
    }

    $user_result = mysqli_stmt_get_result($user_stmt);
    if (!$user_result) {
        throw new DatabaseException("Failed to get results: " . mysqli_error($con));
    }

    $user_data = mysqli_fetch_assoc($user_result);
    if (!$user_data) {
        throw new AdminException("User data not found in database for: " . $username);
    }

} catch (AdminException | DatabaseException | Exception $e) {
    die("An error occurred while fetching user data. Please try again later.");
} finally {
    if (isset($user_stmt)) {
        mysqli_stmt_close($user_stmt);
    }
}

// Initialize pagination variables with default values
$items_per_page = (int) $items_per_page;
$total_items = 0;
$total_pages = 1;

// Get total count of pending records
try {
    $count_query = "SELECT COUNT(*) as total FROM pending_records";
    if ($status_filter !== 'all') {
        $count_query .= " WHERE status = ?";
    }

    $count_stmt = mysqli_prepare($con, $count_query);
    if (!$count_stmt) {
        throw new DatabaseException("Failed to prepare count query");
    }
    if ($status_filter !== 'all') {
        mysqli_stmt_bind_param($count_stmt, "s", $status_filter);
    }
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);

    if (!$count_result) {
        throw new DatabaseException("Failed to run count query: " . mysqli_error($con));
    }

    $count_data = mysqli_fetch_assoc($count_result);
    if (!$count_data) {
        throw new DatabaseException("Failed to fetch count data");
    }

    $total_items = (int) $count_data['total'];
    $total_pages = max(1, ceil($total_items / $items_per_page));

} catch (DatabaseException | Exception $e) {
    // Use default values already set above
    $total_items = 0;
    $total_pages = 1;
}

// Ensure variables are set before logging

// Ensure page is within valid range
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $items_per_page;

// Sort direction (quick toggle): 'desc' for newest first, 'asc' for oldest first
$sort = isset($_GET['sort']) ? strtolower(trim($_GET['sort'])) : 'desc';
if (!in_array($sort, ['asc', 'desc'], true)) {
    $sort = 'desc';
}
$order_dir = $sort === 'asc' ? 'ASC' : 'DESC';

// Debug: Check database connection
if (!$con) {
    error_log("professional_admins.php: database connection failed");
    die("An internal error occurred. Please try again later.");
}

// Debug: Check if pending_records table exists
$table_check = mysqli_query($con, "SHOW TABLES LIKE 'pending_records'");
if (!$table_check || $table_check === true || mysqli_num_rows($table_check) == 0) {
    error_log("professional_admins.php: pending_records table does not exist");
    die("An internal error occurred. Please try again later.");
}

// Get paginated pending records with user details
$stmt = null;
try {
    $query = "SELECT
                  p.*,
                  l.id as user_id,
                  l.username,
                  l.subscription,
                  l.expiry_date,
                  l.email as user_email,
                  p.points as user_points
              FROM pending_records p
              LEFT JOIN login l ON p.submitted_by = l.username COLLATE utf8mb4_unicode_ci";

    if ($status_filter !== 'all') {
        $query .= " WHERE p.status = ?";
    }

    $query .= " ORDER BY COALESCE(p.created_at, p.submission_date) $order_dir, p.id $order_dir
              LIMIT ? OFFSET ?";

    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        throw new DatabaseException("Failed to prepare query: " . mysqli_error($con));
    }

    if ($status_filter !== 'all') {
        mysqli_stmt_bind_param($stmt, "sii", $status_filter, $items_per_page, $offset);
    } else {
        mysqli_stmt_bind_param($stmt, "ii", $items_per_page, $offset);
    }

    if (!mysqli_stmt_execute($stmt)) {
        throw new DatabaseException("Failed to execute query: " . mysqli_stmt_error($stmt));
    }

    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        throw new DatabaseException("Failed to fetch results: " . mysqli_error($con));
    }

    // Debug: Check number of rows returned
    $num_rows = mysqli_num_rows($result);

    if ($num_rows === 0) {
        $show_no_records = true;
    }

} catch (DatabaseException | Exception $e) {
    error_log("professional_admins.php list query failed: " . $e->getMessage());
    die("An internal error occurred. Please try again later.");
} finally {
    // This block will always run, whether there was an exception or not
    if (isset($stmt) && $stmt !== false) {
        mysqli_stmt_close($stmt);
        $stmt = null;
    }
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['message'] = "Security validation failed. Please try again.";
        $_SESSION['messageType'] = 'error';
        header(REDIRECT_HEADER . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST['action']) && isset($_POST['record_id'])) {
        $action = (string) ($_POST['action'] ?? '');
        $record_id = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
        if (!in_array($action, ['approve', 'reject'], true) || $record_id === false || $record_id < 1) {
            $_SESSION['message'] = "Invalid request data.";
            $_SESSION['messageType'] = 'error';
            header(REDIRECT_HEADER . 'professional_admins.php');
            exit();
        }

        if ($action === 'approve') {
            // Begin transaction with validation
            if (!mysqli_begin_transaction($con)) {
                die("An error occurred while processing the request. Please try again later.");
            }

            try {
                if (!function_exists('compressImage')) {
                    /**
                     * @param string $imageData
                     * @param int $maxWidth
                     * @param int $maxHeight
                     * @param int $quality
                     * @return string|null
                     */
                    function compressImage(string $imageData, int $maxWidth = 1000, int $maxHeight = 1000, int $quality = 80): ?string
                    {
                        if (empty($imageData)) {
                            return null;
                        }

                        // Create image from string
                        $image = imagecreatefromstring($imageData);
                        if (!$image) {
                            return null;
                        }

                        // Get original dimensions
                        $width = imagesx($image);
                        $height = imagesy($image);

                        // Calculate new dimensions
                        $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
                        $newWidth = max(1, (int) ($width * $ratio));
                        $newHeight = max(1, (int) ($height * $ratio));

                        // Create new image
                        $newImage = imagecreatetruecolor($newWidth, $newHeight);
                        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                        // Start output buffering
                        ob_start();
                        // Save the image with quality
                        imagejpeg($newImage, null, $quality);
                        $compressedImage = ob_get_contents();
                        ob_end_clean();

                        // Free memory
                        imagedestroy($image);
                        imagedestroy($newImage);

                        return $compressedImage !== false ? $compressedImage : null;
                    }
                }

                // Get the pending record
                $get_record = "SELECT * FROM pending_records WHERE id = ?";
                $get_stmt = mysqli_prepare($con, $get_record);
                if (!$get_stmt) {
                    throw new DatabaseException("Failed to prepare get record query");
                }
                mysqli_stmt_bind_param($get_stmt, "i", $record_id);
                mysqli_stmt_execute($get_stmt);
                $result = mysqli_stmt_get_result($get_stmt);
                if (!$result) {
                    throw new DatabaseException("Failed to execute get record query");
                }
                $record = mysqli_fetch_assoc($result);
                mysqli_stmt_close($get_stmt);

                if (!$record) {
                    throw new AdminException("Record not found");
                }

                // Get the submitter's username and points from pending_records
                $get_submitter = "SELECT submitted_by, points FROM pending_records WHERE id = ?";
                $submitter_stmt = mysqli_prepare($con, $get_submitter);
                if (!$submitter_stmt) {
                    throw new DatabaseException("Failed to prepare submitter query");
                }
                mysqli_stmt_bind_param($submitter_stmt, "i", $record_id);
                mysqli_stmt_execute($submitter_stmt);
                $submitter_result = mysqli_stmt_get_result($submitter_stmt);
                if (!$submitter_result) {
                    throw new DatabaseException("Failed to execute submitter query");
                }
                $submitter_data = mysqli_fetch_assoc($submitter_result);
                $submitter_username = isset($submitter_data['submitted_by']) ? $submitter_data['submitted_by'] : null;
                $request_points = isset($submitter_data['points']) ? (int) $submitter_data['points'] : 0;
                mysqli_stmt_close($submitter_stmt);

                // Compress images before insertion
                $compressed_profile = compressImage((string) $record['profile_image']);
                $compressed_person = compressImage((string) $record['person_photo']);
                $compressed_id_card = compressImage((string) $record['id_card_file']);

                $compressed_license = compressImage((string) $record['driving_license_image']);

                // Insert into main search table with all new fields including points in single query
                $insert = "INSERT INTO search (u, n, e, t, a, address, birth_cert, nationality, relatives,
                          blood_type, social_media, bank_accounts, profile_image, profile_image_metadata, person_photo, person_photo_metadata,
                          id_card_file, id_card_metadata, city, district, street, building_number, apartment_number, postal_code,
                          birth_certificate_number, marital_status, children_count, birth_date,
                          subscription, points, created_at, updated_at, submitted_by, submission_date, status, added_by,
                          height, weight, age, skin_color, personal_car_number, driving_license_image, driving_license_metadata)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                // Decrypt bank accounts before storing in search table
                $decryptedBankAccounts = '';
                if (!empty($record['bank_accounts'])) {
                    $decryptedBankAccounts = SensitiveDataService::decrypt((string) $record['bank_accounts']);
                }

                // Decrypt access code before storing in search table
                $decryptedAccessCode = '';
                if (!empty($record['access_code'])) {
                    $decryptedAccessCode = SensitiveDataService::decrypt((string) $record['access_code']);
                }

                $insert_stmt = mysqli_prepare($con, $insert);
                if (!$insert_stmt) {
                    throw new DatabaseException("Failed to prepare database statement.");
                }

                $now = date(DATE_FORMAT);
                $approved_status = 'approved';

                $birthDate = $record['birth_date'] ?? null;
                if (is_string($birthDate)) {
                    $birthDate = trim($birthDate);
                    if ($birthDate === '') {
                        $birthDate = null;
                    } else {
                        $dt = DateTime::createFromFormat('Y-m-d', $birthDate);
                        if ($dt instanceof DateTime) {
                            $birthDate = $dt->format('Y-m-d');
                        } else {
                            $ts = strtotime($birthDate);
                            $birthDate = ($ts === false) ? null : date('Y-m-d', $ts);
                        }
                    }
                }

                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "ssssssssssssssssssssssssssssiissiiisssss", // 45 params
                    $record['u'],
                    $record['n'],
                    $record['e'],
                    $record['t'],
                    $decryptedAccessCode, // Use decrypted access code
                    $record['address'],
                    $record['birth_cert'],
                    $record['nationality'],
                    $record['relatives'],
                    $record['blood_type'],
                    $record['social_media'],
                    $decryptedBankAccounts, // Use decrypted bank accounts
                    $compressed_profile,
                    $record['profile_image_metadata'],
                    $compressed_person,
                    $record['person_photo_metadata'],
                    $compressed_id_card,
                    $record['id_card_metadata'],
                    $record['city'],
                    $record['district'],
                    $record['street'],
                    $record['building_number'],
                    $record['apartment_number'],
                    $record['postal_code'],
                    $record['birth_certificate_number'],
                    $record['marital_status'],
                    $record['children_count'],
                    $birthDate,
                    $record['subscription'],
                    $request_points,
                    $now, // created_at
                    $now, // updated_at
                    $submitter_username, // submitted_by
                    $now, // submission_date
                    $approved_status, // status
                    $_SESSION['username'], // added_by
                    $record['height'],
                    $record['weight'],
                    $record['age'],
                    $record['skin_color'],
                    $record['personal_car_number'],
                    $compressed_license,
                    $record['driving_license_metadata']
                );

                if (!mysqli_stmt_execute($insert_stmt)) {
                    throw new DatabaseException("Error finalizing record approval.");
                }
                mysqli_stmt_close($insert_stmt);

                // Submitter info already retrieved above

                // Update user points by adding the request price/points
                $update_user = "UPDATE login SET
                    points = points + ?,
                    expiry_date = DATE_ADD(expiry_date, INTERVAL 1 DAY)
                    WHERE username = ?";
                $user_stmt = mysqli_prepare($con, $update_user);
                if (!$user_stmt) {
                    throw new DatabaseException("Failed to prepare user update query");
                }
                mysqli_stmt_bind_param($user_stmt, "is", $request_points, $submitter_username);

                if (!mysqli_stmt_execute($user_stmt)) {
                    throw new DatabaseException("Failed to update user credit.");
                }
                mysqli_stmt_close($user_stmt);

                // Update status in pending_records
                $update = "UPDATE pending_records SET status = 'approved', approved_by = ?, approval_date = NOW() WHERE id = ?";
                $update_stmt = mysqli_prepare($con, $update);
                if (!$update_stmt) {
                    throw new DatabaseException("Failed to prepare approve query");
                }
                mysqli_stmt_bind_param($update_stmt, "si", $_SESSION['username'], $record_id);

                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new DatabaseException("Failed to update queue status.");
                }
                mysqli_stmt_close($update_stmt);

                mysqli_commit($con);
                $_SESSION['message'] = "Record approved successfully. User gained " . number_format($request_points) . " points and 1-day extension. All data saved in single query.";
                $_SESSION['messageType'] = 'success';
                header(REDIRECT_HEADER . $_SERVER['PHP_SELF']);
                exit();

            } catch (AdminException | DatabaseException | Exception $e) {
                mysqli_rollback($con);
                error_log("professional_admins.php approve failed: " . $e->getMessage());
                $_SESSION['message'] = "An internal error occurred while processing approval.";
                $_SESSION['messageType'] = 'error';
                header(REDIRECT_HEADER . 'professional_admins');
                exit();
            }

        } elseif ($action === 'reject') {
            // Begin transaction with validation
            if (!mysqli_begin_transaction($con)) {
                die("An error occurred while processing the rejection request. Please try again later.");
            }

            try {
                // Update status in pending_records instead of deleting
                $update = "UPDATE pending_records SET status = 'rejected', approved_by = ?, approval_date = NOW() WHERE id = ?";
                $update_stmt = mysqli_prepare($con, $update);
                if (!$update_stmt) {
                    throw new DatabaseException("Failed to prepare reject query");
                }
                mysqli_stmt_bind_param($update_stmt, "si", $_SESSION['username'], $record_id);

                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new DatabaseException("Error updating record to rejected");
                }
                mysqli_stmt_close($update_stmt);

                mysqli_commit($con);
                $_SESSION['message'] = "Record marked as rejected.";
                $_SESSION['messageType'] = 'success';
                header(REDIRECT_HEADER . $_SERVER['PHP_SELF']);
                exit();
            } catch (DatabaseException | Exception $e) {
                mysqli_rollback($con);
                error_log("professional_admins.php reject failed: " . $e->getMessage());
                $_SESSION['message'] = "An internal error occurred while processing rejection.";
                $_SESSION['messageType'] = 'error';
                header(REDIRECT_HEADER . 'professional_admins');
                exit();
            }
        }
    }
}

// Use the already fetched data for pagination
// No need to duplicate queries - we already have $total_items and $total_pages from above

// Get message from session if exists
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Admins</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #00ff00;
            --dark-green: #006400;
            --background-black: #050505;
            --border-glow: rgba(0, 255, 0, 0.15);
            --text-gray: #888888;
            --card-bg: #0c0c0c;
            --hover-bg: #111111;
            --section-bg: rgba(0, 255, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--background-black);
            color: var(--primary-green);
            line-height: 1.6;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .container {
            width: 100%;
            margin: 0 auto;
            padding: 0.5rem;
            font-size: 0.9em;
            flex: 1;
            overflow-x: hidden;
        }

        /* Header Sections */
        .header-section {
            display: flex;
            align-items: center;
            height: 100%;
            gap: 1rem;
            flex: 1;
        }

        /* Logo/Brand */
        .header-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-right: 1rem;
            border-right: 1px solid rgba(0, 255, 0, 0.1);
        }

        .header-logo {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-green), #00cc66);
            border-radius: 6px;
            color: #fff;
            font-size: 1rem;
            box-shadow: 0 2px 6px rgba(0, 255, 0, 0.2);
            transition: all 0.2s ease;
        }

        .header-title {
            font-size: 1rem;
            font-weight: 600;
            background: linear-gradient(90deg, #fff, var(--primary-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: 0.3px;
            white-space: nowrap;
            margin: 0 0.5rem;
        }

        /* User Info Section */
        .user-info-section {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: fit-content;
            flex-shrink: 0;
            position: relative;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
            background: rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(0, 255, 0, 0.1);
            height: 36px;
            font-size: 0.9em;
        }

        .user-info-section:hover {
            background: rgba(0, 255, 0, 0.08);
            border-color: rgba(0, 255, 0, 0.15);
        }

        /* Stats Section */
        .stats-section {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            min-width: fit-content;
            padding: 0 0.5rem;
            flex-shrink: 0;
            height: 100%;
            position: relative;
        }

        .stats-section::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 1px;
            height: 60%;
            background: linear-gradient(to bottom,
                    transparent,
                    rgba(0, 255, 0, 0.2),
                    transparent);
        }

        /* Navigation Section */
        .navigation-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: fit-content;
            flex-shrink: 0;
            margin-left: 0.5rem;
        }

        .nav-divider {
            width: 1px;
            height: 24px;
            background: rgba(0, 255, 0, 0.1);
            margin: 0 0.5rem;
        }

        .user-role {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            background: rgba(0, 255, 0, 0.08);
            padding: 0.1rem 0.6rem;
            border-radius: 12px;
            border: 1px solid rgba(0, 255, 0, 0.1);
        }

        .user-role i {
            font-size: 0.6rem;
        }

        /* Notifications */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            font-weight: 700;
            border: 2px solid rgba(15, 20, 25, 0.9);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        .user-profile {
            position: relative;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem;
            background: var(--card-bg);
            border: 1px solid var(--border-glow);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-profile:hover {
            background: var(--hover-bg);
            border-color: var(--primary-green);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--dark-green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
        }

        .user-info {
            line-height: 1.3;
        }

        .user-name {
            font-weight: 600;
            color: var(--primary-green);
        }

        .user-role {
            font-size: 0.85rem;
            color: var(--text-gray);
        }

        .user-details {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
        }

        .user-stat {
            padding: 0.25rem 0.75rem;
            background: var(--section-bg);
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-stat i {
            color: var(--primary-green);
        }

        /* Main Table Styles */
        .table-container {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-glow);
            overflow: hidden;
            margin: 2rem 0;
            box-shadow: 0 8px 32px rgba(0, 255, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
            margin: 0;
        }

        .data-table th {
            background: linear-gradient(135deg, var(--dark-green), #004d00);
            color: white;
            padding: 1.5rem 1rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--primary-green);
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0, 255, 0, 0.2);
        }

        /* Full Row Display */
        .full-row {
            transition: all 0.3s ease;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.95), rgba(20, 20, 20, 0.95));
            border: 1px solid rgba(0, 255, 0, 0.1);
        }

        .full-row:hover {
            background: linear-gradient(135deg, rgba(20, 30, 20, 0.95), rgba(30, 40, 30, 0.95));
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 255, 0, 0.1);
            border-color: rgba(0, 255, 0, 0.3);
        }

        .full-row.expanded {
            background: linear-gradient(135deg, rgba(0, 40, 0, 0.95), rgba(0, 60, 0, 0.95));
            border-left: 4px solid var(--primary-green);
            border-right: 1px solid var(--primary-green);
            border-top: 1px solid var(--primary-green);
        }

        .full-row.expanded:hover {
            background: linear-gradient(135deg, rgba(0, 50, 0, 0.95), rgba(0, 70, 0, 0.95));
            transform: none;
            box-shadow: 0 4px 20px rgba(0, 255, 0, 0.2);
        }

        .full-row td {
            padding: 1.5rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid rgba(0, 255, 0, 0.05);
            text-align: center;
        }

        /* User Info in Row */
        .user-row-cell {
            text-align: left;
            min-width: 350px;
            padding: 1rem;
        }

        .user-main-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar-small {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-icon-small {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background-color: rgba(0, 255, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            font-size: 0.9rem;
        }

        .username {
            font-weight: 500;
            color: var(--primary-green);
        }

        .email-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-green);
        }

        .truncate-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .subscription-cell {
            text-align: center;
        }

        .subscription-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background-color: rgba(0, 255, 0, 0.1);
            color: var(--primary-green);
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid rgba(0, 255, 0, 0.2);
        }

        .expiry-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-gray);
            font-size: 0.85rem;
        }

        .actions-cell {
            text-align: center;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }

        .action-buttons button {
            width: 28px;
            height: 28px;
            border: none;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.8rem;
        }

        .btn-approve {
            background-color: rgba(0, 255, 0, 0.1);
            color: var(--primary-green);
            border: 1px solid rgba(0, 255, 0, 0.2);
        }

        .btn-approve:hover {
            background-color: rgba(0, 255, 0, 0.2);
            border-color: var(--primary-green);
        }

        .btn-reject {
            background-color: rgba(255, 68, 68, 0.1);
            color: #ff4444;
            border: 1px solid rgba(255, 68, 68, 0.2);
        }

        .btn-reject:hover {
            background-color: rgba(255, 68, 68, 0.2);
            border-color: #ff4444;
        }

        .btn-view {
            background-color: rgba(0, 255, 0, 0.1);
            color: var(--primary-green);
            border: 1px solid rgba(0, 255, 0, 0.2);
        }

        .btn-view:hover {
            background-color: rgba(0, 255, 0, 0.2);
            border-color: var(--primary-green);
        }

        .user-avatar-row {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--dark-green);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 3px solid var(--primary-green);
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 255, 0, 0.3);
        }

        .user-avatar-row img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-avatar-row i {
            font-size: 1.5rem;
            color: white;
        }

        .user-info-row {
            flex: 1;
            text-align: left;
        }

        .user-name-row {
            color: var(--primary-green);
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .user-details-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .user-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem;
            background: rgba(0, 255, 0, 0.05);
            border-radius: 6px;
            border: 1px solid rgba(0, 255, 0, 0.1);
        }

        .user-info-item i {
            color: var(--primary-green);
            width: 16px;
            text-align: center;
            font-size: 0.8rem;
        }

        .user-info-label {
            color: var(--text-gray);
            font-weight: 500;
            min-width: 60px;
        }

        .user-info-value {
            color: var(--primary-green);
            font-weight: 500;
            flex: 1;
        }

        /* Contact Cell */
        .contact-row-cell {
            min-width: 250px;
            text-align: center;
        }

        .contact-info-row {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .contact-item-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(0, 255, 0, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(0, 255, 0, 0.1);
            justify-content: center;
        }

        .contact-item-row i {
            color: var(--primary-green);
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .contact-text-row {
            color: var(--primary-green);
            font-weight: 500;
            font-size: 0.95rem;
        }

        /* Date Cell */
        .date-row-cell {
            min-width: 180px;
            text-align: center;
        }

        .date-info-row {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
        }

        .date-item-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 255, 0, 0.05);
            border-radius: 6px;
            border: 1px solid rgba(0, 255, 0, 0.1);
        }

        .date-item-row i {
            color: var(--primary-green);
            font-size: 0.9rem;
        }

        .date-text-row {
            color: var(--primary-green);
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Actions Cell */
        .actions-row-cell {
            min-width: 200px;
            text-align: center;
        }

        .actions-row-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .expand-indicator {
            background: linear-gradient(135deg, var(--section-bg), rgba(0, 255, 0, 0.1));
            color: var(--primary-green);
            border: 1px solid var(--primary-green);
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .expand-indicator:hover {
            background: var(--primary-green);
            color: var(--background-black);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 255, 0, 0.3);
        }

        .expand-indicator i {
            transition: transform 0.3s ease;
        }

        .full-row.expanded .expand-indicator i {
            transform: rotate(180deg);
        }

        .data-table tbody tr.details-row {
            background: linear-gradient(135deg, rgba(15, 25, 15, 0.98), rgba(20, 30, 20, 0.98));
        }

        .data-table tbody tr.details-row td {
            padding: 0.5rem;
            border: none;
            border-bottom: 1px solid rgba(0, 255, 0, 0.1);
        }

        .data-table tbody tr.details-row td .details-container {
            margin: 0;
            border-radius: 4px;
            padding: 0.5rem;
            background: rgba(0, 20, 0, 0.3);
        }

        .details-row.expanded {
            display: table-row !important;
            animation: slideDown 0.25s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .details-row.closing {
            animation: slideUp 0.3s ease-in;
        }

        @keyframes slideUp {
            from {
                opacity: 1;
                transform: translateY(0);
            }

            to {
                opacity: 0;
                transform: translateY(-20px);
            }
        }

        .details-row td {
            padding: 0;
            border: none;
            background: transparent;
        }

        /* Ensure details row spans all columns */
        .details-row[style*="display: table-row"] td {
            display: table-cell !important;
            width: 100% !important;
        }

        .details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .details-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--primary-green);
        }

        .btn-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-gray);
            font-size: 1.2rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .btn-close:hover {
            background-color: rgba(0, 255, 0, 0.1);
            color: var(--primary-green);
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .details-section {
            background: transparent;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            border: none;
            transition: none;
        }

        .details-section:hover {
            transform: none;
            box-shadow: none;
            border-color: transparent;
        }

        .details-section h4 {
            margin: 0 0 0.5rem 0;
            padding-bottom: 0.25rem;
            border-bottom: none;
            /* إزالة الخط للفصل العمودي */
            color: var(--primary-green);
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .details-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.3rem;
        }

        .details-info-item {
            display: grid;
            grid-template-columns: auto 1fr;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.5rem;
            background: rgba(0, 255, 0, 0.02);
            border-radius: 4px;
            border: 1px solid rgba(0, 255, 0, 0.08);
            min-height: 32px;
            transition: all 0.2s ease;
        }

        .details-info-item:hover {
            background: rgba(0, 255, 0, 0.05);
            border-color: rgba(0, 255, 0, 0.2);
        }

        .details-info-item i {
            color: var(--primary-green);
            font-size: 0.75rem;
            opacity: 0.8;
            width: 16px;
            text-align: center;
        }

        .details-label {
            color: var(--text-gray);
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 0.3rem;
            white-space: nowrap;
            display: inline-block;
        }

        .details-value {
            color: var(--primary-green);
            font-size: 0.75rem;
            font-weight: 400;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
        }

        /* Show full text on hover */
        .details-info-item:hover .details-value {
            white-space: normal;
            word-break: break-word;
        }

        .images-section {
            grid-column: 1 / -1;
            margin-top: 0.5rem;
        }

        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .image-preview-container {
            background: rgba(10, 10, 10, 0.6);
            border-radius: 10px;
            padding: 0.75rem;
            text-align: center;
            border: 1px solid rgba(0, 100, 0, 0.25);
            transition: all 0.25s ease;
        }

        .image-preview-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 80, 0, 0.3);
            border-color: rgba(0, 150, 0, 0.5);
        }

        .image-preview {
            width: 120px;
            height: 120px;
            margin: 0 auto 0.6rem;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            border: 1px solid var(--border-glow);
            transition: all 0.25s ease;
            position: relative;
        }

        .image-preview:hover {
            border-color: var(--primary-green);
            transform: scale(1.015);
            box-shadow: 0 4px 16px rgba(0, 255, 0, 0.25);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .btn-image-modal {
            width: 100%;
            height: 100%;
            padding: 0;
            border: none;
            background: none;
            cursor: pointer;
            display: block;
            outline: none;
        }

        .btn-image-modal:focus {
            outline: 2px solid var(--primary-green);
            outline-offset: -2px;
        }

        .no-image {
            width: 100%;
            height: 100%;
            background: rgba(0, 255, 0, 0.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #00ff00;
            font-size: 0.9rem;
            gap: 0.5rem;
            border: 2px dashed rgba(0, 255, 0, 0.3);
            border-radius: 8px;
        }

        .no-image i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #00ff00;
        }

        .no-image span {
            color: #00ff00;
            font-weight: 500;
        }

        .image-label {
            color: var(--text-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .actions-section {
            grid-column: 1 / -1;
            margin-top: 1rem;
        }

        .final-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
            justify-content: center;
        }

        .btn-approve {
            background: linear-gradient(135deg, var(--primary-green), #00cc66);
            color: var(--background-black);
            border: 1px solid var(--primary-green);
            min-width: 150px;
            padding: 1rem 2rem;
            font-weight: 600;
        }

        .btn-approve:hover {
            background: linear-gradient(135deg, #00cc66, var(--primary-green));
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 255, 0, 0.3);
        }

        .btn-reject {
            background: linear-gradient(135deg, #ff4444, #cc0000);
            color: white;
            border: 1px solid #ff4444;
            min-width: 150px;
            padding: 1rem 2rem;
            font-weight: 600;
        }

        .btn-reject:hover {
            background: linear-gradient(135deg, #cc0000, #ff4444);
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(255, 68, 68, 0.3);
        }

        /* Search Filter */
        .search-filter {
            display: flex;
            gap: 0.5rem;
            margin: 0.5rem 0;
            padding: 0.5rem;
            background: var(--card-bg);
            border: 1px solid var(--border-glow);
            border-radius: 6px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            font-size: 0.9em;
        }

        .sort-control {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: transparent;
        }

        .sort-control label {
            color: var(--text-gray);
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .sort-select {
            background: var(--section-bg);
            border: 1px solid var(--border-glow);
            color: var(--primary-green);
            padding: 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.3s ease;
            min-width: 150px;
            padding: 0.45rem 0.6rem;
            border-radius: 6px;
            font-size: 0.9rem;
            outline: none;
        }

        .search-input {
            display: flex;
            align-items: center;
            background: var(--section-bg);
            border: 1px solid var(--border-glow);
            border-radius: 6px;
            overflow: hidden;
            min-width: 300px;
            flex: 1;
        }

        .search-input i {
            padding: 0.75rem;
            color: var(--primary-green);
            background: rgba(0, 255, 0, 0.1);
        }

        .search-input input {
            background: transparent;
            border: none;
            padding: 0.75rem;
            color: var(--primary-green);
            flex: 1;
            font-size: 1rem;
        }

        .search-input input:focus {
            outline: none;
        }

        .search-input input::placeholder {
            color: var(--text-gray);
        }

        /* Image Modal */
        .image-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .image-modal.active {
            display: flex;
        }

        .image-modal-content {
            position: relative;
            max-width: 600px;
            max-height: 600px;
            width: 90vw;
            height: auto;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .data-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 255, 0, 0.15);
            border-color: var(--border-glow);
        }

        .data-box-icon {
            width: 40px;
            height: 40px;
            background: rgba(0, 255, 0, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: var(--primary-green);
            font-size: 1.2rem;
        }

        .data-box-content {
            flex: 1;
        }

        .data-box-label {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .data-box-value {
            color: var(--text-color);
            word-break: break-word;
            font-size: 0.9rem;
        }

        /* Status Badge Styles */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: capitalize;
            display: inline-block;
        }

        .status-badge.pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .status-badge.completed,
        .status-badge.approved {
            background: rgba(0, 255, 0, 0.1);
            color: var(--primary-green);
            border: 1px solid rgba(0, 255, 0, 0.2);
        }

        .status-badge.failed,
        .status-badge.rejected {
            background: rgba(255, 68, 68, 0.1);
            color: #ff4444;
            border: 1px solid rgba(255, 68, 68, 0.2);
        }

        /* Additional table styles */
        .username-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-left: 0.5rem;
        }

        .username {
            font-weight: 600;
            color: var(--primary-green);
        }

        .real-name {
            color: var(--text-gray);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .user-city {
            color: var(--text-gray);
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .user-city i {
            color: var(--primary-green);
            opacity: 0.7;
        }

        .marital-status {
            color: var(--text-gray);
            font-size: 0.75rem;
            display: block;
            margin-top: 0.25rem;
        }

        .marital-status i {
            color: var(--primary-green);
            opacity: 0.7;
        }

        .birth-date {
            color: var(--text-gray);
            font-size: 0.75rem;
            display: block;
            margin-top: 0.25rem;
        }

        .birth-date i {
            color: var(--primary-green);
            opacity: 0.7;
        }

        /* Format for new boxes */
        .new-data-boxes-section {
            margin: 1.5rem 0;
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
        }

        .new-data-boxes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        .new-data-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .new-data-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 255, 0, 0.15);
            border-color: var(--border-glow);
        }

        .new-data-box-icon {
            width: 40px;
            height: 40px;
            background: rgba(0, 255, 0, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: var(--primary-green);
            font-size: 1.2rem;
        }

        .new-data-box-content {
            flex: 1;
        }

        .new-data-box-label {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .new-data-box-value {}

        .image-modal-content {
            position: relative;
            max-width: 600px;
            max-height: 600px;
            width: 90vw;
            height: auto;
            background: var(--card-bg);
            border: 1px solid var(--border-glow);
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 20px 60px rgba(0, 255, 0, 0.2);
            display: flex;
            flex-direction: column;
        }

        .image-modal-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            width: 100%;
        }

        .image-modal img {
            max-width: 100%;
            max-height: calc(100% - 3rem);
            width: auto;
            height: auto;
            object-fit: contain;
            border-radius: 8px;
            display: block;
            margin-bottom: 1rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .image-modal-title {
            color: var(--primary-green);
            font-size: 1rem;
            font-weight: 500;
            text-align: center;
            margin-top: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .image-modal-close {
            position: absolute;
            top: -15px;
            right: -15px;
            width: 40px;
            height: 40px;
            background: var(--primary-green);
            color: var(--background-black);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 255, 0, 0.3);
            transition: all 0.3s ease;
            z-index: 10;
        }

        .image-modal-close:hover {
            background: #00ff00;
            transform: scale(1.1);
        }

        @media (max-width: 640px) {
            .image-modal-content {
                max-width: 90vw;
                max-height: 80vh;
                padding: 0.75rem;
            }

            .image-modal img {
                max-height: calc(80vh - 4rem);
            }

            .image-modal-title {
                font-size: 0.9rem;
            }
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin: 2rem 0;
            padding: 1.5rem;
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-glow);
            box-shadow: 0 8px 32px rgba(0, 255, 0, 0.1);
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 0.3rem 0.7rem;
            background: linear-gradient(135deg, var(--section-bg), rgba(0, 255, 0, 0.05));
            border: 1px solid var(--border-glow);
            color: var(--primary-green);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 500;
            min-width: 32px;
            height: 32px;
            justify-content: center;
            font-size: 0.85em;
        }

        .pagination-btn:hover {
            background: var(--primary-green);
            color: var(--background-black);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 255, 0, 0.2);
        }

        .pagination-btn.active {
            background: linear-gradient(135deg, var(--primary-green), #00cc66);
            color: var(--background-black);
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(0, 255, 0, 0.3);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Alert Messages */
        .alert {
            position: fixed;
            top: 2rem;
            right: 2rem;
            padding: 1rem 2rem;
            border-radius: 8px;
            background: rgba(12, 12, 12, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-glow);
            color: var(--primary-green);
            z-index: 1000;
            /* transform: translateX(150%); */
            /* animation: slideIn 0.3s forwards; */
            max-width: 400px;
        }

        @keyframes slideIn {
            to {
                transform: translateX(0);
            }
        }

        .alert-success {
            border-color: var(--primary-green);
            background: rgba(0, 100, 0, 0.1);
        }

        .alert-error {
            border-color: #ff0000;
            color: #ff6666;
            background: rgba(100, 0, 0, 0.1);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-glow);
            box-shadow: 0 8px 32px rgba(0, 255, 0, 0.1);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--text-gray);
            opacity: 0.6;
        }

        .empty-state p {
            color: var(--text-gray);
            font-size: 1.2rem;
            font-weight: 500;
        }

        /* Row Number */
        .row-number {
            background: linear-gradient(135deg, var(--primary-green), #00ff00);
            color: var(--background-black);
            font-weight: bold;
            text-align: center;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0, 255, 0, 0.3);
            font-size: 1rem;
            border: none;
            cursor: pointer;
            outline: none;
            transition: all 0.2s ease;
        }

        .row-number:focus {
            outline: 2px solid #fff;
            outline-offset: 2px;
            box-shadow: 0 0 0 4px rgba(0, 255, 0, 0.4);
        }

        .row-number:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 255, 0, 0.5);
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .details-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 0.5rem;
            }

            .user-info-section,
            .stats-section,
            .navigation-section {
                flex-shrink: 0;
                min-width: 0;
            }

            .stats-section {
                gap: 0.5rem;
                padding: 0 0.2rem;
            }

            .table-container {
                overflow-x: auto;
            }

            .data-table {
                min-width: 1000px;
            }

            .user-details-row {
                grid-template-columns: 1fr;
            }

            .details-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .images-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .final-actions {
                flex-direction: column;
                align-items: center;
            }

            .final-actions .btn {
                width: 100%;
                max-width: 300px;
            }

            .search-input {
                min-width: 100%;
            }
        }

        /* Performance overrides: reduce visual effects to speed up rendering */
        :root {
            --border-glow: rgba(0, 0, 0, 0);
            /* neutralize glow */
        }

        /* Disable transitions and animations globally */
        * {
            transition: none !important;
            animation: none !important;
        }

        /* Remove heavy shadows and filters */
        .btn,
        .data-table,
        .details-section,
        .details-container,
        .images-section,
        .user-profile,
        .user-avatar,
        .stat-item,
        .container {
            box-shadow: none !important;
            filter: none !important;
        }

        /* Disable backdrop blur effects */
        .header-logo,
        .btn,
        .details-section,
        .images-section {
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        /* Simplify decorative gradients/backgrounds */
        .container,
        .data-table,
        .details-container,
        .details-section,
        .images-section,
        .full-row {
            background-image: none !important;
            background: #0a0a0a !important;
            /* keeps theme dark but simpler */
        }

        /* Reduce hover transforms */
        .btn:hover,
        .user-profile:hover {}

        /* Neutralize pulse animation keyframes (if referenced) */
        @keyframes pulse {
            from {
                transform: none;
            }

            to {
                transform: none;
            }
        }

        /* Bank Cards Styling */
        .bank-cards {
            grid-column: 1 / -1;
            padding: 1rem;
            background: rgba(30, 30, 30, 0.7);
            border-radius: 8px;
            border: 1px solid rgba(0, 255, 0, 0.1);
        }

        .bank-cards-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            width: 100%;
        }

        .bank-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            width: 100%;
        }

        .bank-card {
            background: rgba(40, 40, 40, 0.8);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(0, 255, 0, 0.1);
            transition: all 0.3s ease;
        }

        .bank-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            border-color: rgba(0, 255, 0, 0.3);
        }

        .bank-card-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .bank-card-header i {
            color: #4CAF50;
            font-size: 1.2rem;
        }

        .bank-name {
            font-weight: 600;
            color: #4CAF50;
        }

        .bank-card-number {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            letter-spacing: 1px;
            margin: 0.5rem 0;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 4px;
            text-align: center;
            color: #fff;
        }

        .bank-card-details {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .card-detail {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
        }

        .detail-label {
            color: #888;
        }

        .detail-value {
            color: #fff;
            font-weight: 500;
        }

        /* Editable Points Section */
        .editable-points {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(0, 255, 0, 0.05);
            border: 1px solid rgba(0, 255, 0, 0.2);
            border-radius: 6px;
            padding: 0.25rem 0.5rem;
        }

        .points-input {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(0, 255, 0, 0.3);
            color: #00ff00;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            width: 80px;
            text-align: center;
            font-size: 0.9rem;
        }

        .points-input:focus {
            outline: none;
            border-color: #00ff00;
            box-shadow: 0 0 5px rgba(0, 255, 0, 0.3);
        }

        .points-unit {
            color: #888;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .btn-save-points {
            background: rgba(0, 255, 0, 0.1);
            border: 1px solid rgba(0, 255, 0, 0.3);
            color: #00ff00;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8rem;
        }

        .btn-save-points:hover {
            background: rgba(0, 255, 0, 0.2);
            border-color: rgba(0, 255, 0, 0.5);
        }

        .btn-save-points i {
            font-size: 0.8rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Search and Filter Section -->
        <div class="search-filter">
            <div class="search-input">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search requests...">
            </div>
            <button class="btn btn-secondary" onclick="resetFilters()">
                <i class="fas fa-undo"></i> Reset
            </button>
            <div class="sort-control">
                <label for="statusSelect"><i class="fas fa-filter"></i> Status:</label>
                <select id="statusSelect" class="sort-select" onchange="applyStatusFilter(this.value)">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Only
                    </option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved Only
                    </option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected Only
                    </option>
                </select>
            </div>
            <div class="sort-control">
                <label for="sortSelect"><i class="fas fa-sort"></i> Sort:</label>
                <select id="sortSelect" class="sort-select" onchange="applySort(this.value)">
                    <option value="desc" <?php echo $sort === 'desc' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="asc" <?php echo $sort === 'asc' ? 'selected' : ''; ?>>Oldest first</option>
                </select>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo h($messageType ?: 'info'); ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($show_no_records) && $show_no_records): ?>
            <div class="no-records">
                <i class="fas fa-info-circle"></i>
                <p>No pending requests currently</p>
            </div>
        <?php elseif (mysqli_num_rows($result) > 0): ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">No</th>
                            <th style="width: 100px;">ID</th>
                            <th>User Info</th>
                            <th>Status & Info</th>
                            <th>Dates</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $counter = ($page - 1) * $items_per_page + 1;
                        while ($row = mysqli_fetch_assoc($result)):
                            $submissionDate = new DateTime((string) ($row['created_at'] ?: $row['submission_date']));
                            ?>
                            <tr class="full-row" id="row-<?php echo $row['id']; ?>">
                                <td class="text-center">
                                    <button type="button" class="row-number"
                                        onclick="toggleRowExpansion(<?php echo $row['id']; ?>, event);"
                                        aria-label="Toggle row details">
                                        <?php echo $counter++; ?>
                                    </button>
                                </td>
                                <td class="text-center">
                                    <span class="user-id"><?php echo h($row['id']); ?></span>
                                </td>
                                <td>
                                    <div class="user-main-info">
                                        <?php if ($row['profile_image']): ?>
                                            <img src="data:image/jpeg;base64,<?php echo base64_encode((string) $row['profile_image']); ?>"
                                                alt="User profile" class="user-avatar-small">
                                        <?php else: ?>
                                            <i class="fas fa-user user-icon-small"></i>
                                        <?php endif; ?>
                                        <div class="username-info">
                                            <span class="username"><?php echo h($row['username'] ?: NOT_SPECIFIED); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="subscription-cell">
                                    <span
                                        class="subscription-badge"><?php echo h($row['subscription'] ?: NOT_SPECIFIED); ?></span>
                                    <span class="status-badge <?php echo strtolower((string) $row['status']); ?>">
                                        <?php echo h(ucfirst((string) $row['status'])); ?>
                                    </span>
                                </td>
                                <td class="expiry-cell">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo h($row['created_at'] ? date('Y-m-d', strtotime((string) $row['created_at']) ?: null) : NOT_SPECIFIED); ?></span>
                                </td>
                                <td class="actions-cell">
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <div class="action-buttons">
                                            <button class="btn-approve" onclick="approveRecord(<?php echo $row['id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn-reject" onclick="rejectRecord(<?php echo $row['id']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="actions-completed">
                                            <i
                                                class="fas <?php echo $row['status'] === 'approved' ? 'fa-check-double' : 'fa-ban'; ?>"></i>
                                            <?php echo h(ucfirst((string) $row['status'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <!-- Details Row (Hidden by default) -->
                            <tr id="details-row-<?php echo $row['id']; ?>" class="details-row" style="display: none;">
                                <td colspan="6">
                                    <div class="details-container">
                                        <div class="details-header">
                                            <h3>Request Details</h3>
                                            <button class="btn-close"
                                                onclick="toggleRowExpansion(<?php echo $row['id']; ?>, event)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>

                                        <div class="details-grid">
                                            <!-- User Information -->
                                            <!-- Personal Information Section -->
                                            <div class="details-section">
                                                <h4>
                                                    <i class="fas fa-user"></i>
                                                    Personal Information
                                                </h4>
                                                <div class="details-info-grid">
                                                    <div class="details-info-item">
                                                        <i class="fas fa-id-badge"></i>
                                                        <span class="details-label">ID:</span>
                                                        <span class="details-value"><?php echo h($row['id']); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-id-badge"></i>
                                                        <span class="details-label">Username:</span>
                                                        <span
                                                            class="details-value"><?php echo h(isset($row['u']) ? $row['u'] : NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-user"></i>
                                                        <span class="details-label">Name:</span>
                                                        <span
                                                            class="details-value"><?php echo h(isset($row['n']) && $row['n'] ? $row['n'] : NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-envelope"></i>
                                                        <span class="details-label">Email:</span>
                                                        <span
                                                            class="details-value"><?php echo h(isset($row['e']) && $row['e'] ? $row['e'] : NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-phone"></i>
                                                        <span class="details-label">Phone Number:</span>
                                                        <span
                                                            class="details-value"><?php echo h(isset($row['t']) && $row['t'] ? $row['t'] : NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-key"></i>
                                                        <span class="details-label">Access Code:</span>
                                                        <span class="details-value"><?php
                                                        $accessCode = $row['a'] ?? '';
                                                        echo h($accessCode ? 'Stored securely' : NOT_SPECIFIED);
                                                        ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-flag"></i>
                                                        <span class="details-label">Nationality:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['nationality'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-heart"></i>
                                                        <span class="details-label">Marital Status:</span>
                                                        <span class="details-value"><?php
                                                        $marital_status = $row['marital_status'] ?: NOT_SPECIFIED;
                                                        $status_translations = [
                                                            'single' => 'Single',
                                                            'married' => 'Married',
                                                            'divorced' => 'Divorced',
                                                            'widowed' => 'Widowed'
                                                        ];
                                                        echo h($status_translations[(string) $marital_status] ?? $marital_status);
                                                        ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-baby"></i>
                                                        <span class="details-label">Children:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['children_count'] ?: '0'); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-users"></i>
                                                        <span class="details-label">Relatives:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['relatives'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-star"></i>
                                                        <span class="details-label">Points:</span>
                                                        <?php if ($user_data['role'] === 'admin'): ?>
                                                            <div class="editable-points">
                                                                <input type="number" id="points-<?php echo $row['id']; ?>"
                                                                    value="<?php echo h($row['points'] ?? 0); ?>" min="1"
                                                                    max="500000" class="points-input"
                                                                    data-record-id="<?php echo $row['id']; ?>"
                                                                    onblur="updatePoints(<?php echo $row['id']; ?>)">
                                                                <span class="points-unit">points</span>
                                                                <button class="btn-save-points"
                                                                    onclick="updatePoints(<?php echo $row['id']; ?>)">
                                                                    <i class="fas fa-save"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="details-value"><?php echo h($row['points'] ?? 0); ?>
                                                                points</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-archive"></i>
                                                        <span class="details-label">Archived by:</span>
                                                        <span class="details-value">
                                                            <?php
                                                            if (!empty($row['archived_by_users'])) {
                                                                $archived_by = json_decode((string) $row['archived_by_users'], true);
                                                                if (is_array($archived_by)) {
                                                                    echo h(implode(', ', $archived_by));
                                                                } else {
                                                                    echo 'Not archived';
                                                                }
                                                            } else {
                                                                echo 'Not archived';
                                                            }
                                                            ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Physical Attributes Section -->
                                            <div class="details-section">
                                                <h4>
                                                    <i class="fas fa-user-check"></i>
                                                    Physical Attributes
                                                </h4>
                                                <div class="details-info-grid">
                                                    <div class="details-info-item">
                                                        <i class="fas fa-ruler-vertical"></i>
                                                        <span class="details-label">Height:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['height'] ? $row['height'] . ' cm' : NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-weight"></i>
                                                        <span class="details-label">Weight:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['weight'] ? $row['weight'] . ' kg' : NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-birthday-cake"></i>
                                                        <span class="details-label">Age:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['age'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-tint"></i>
                                                        <span class="details-label">Blood Type:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['blood_type'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-palette"></i>
                                                        <span class="details-label">Skin Color:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['skin_color'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Address & Location Section -->
                                            <div class="details-section">
                                                <h4>
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    Address & Location
                                                </h4>
                                                <div class="details-info-grid">
                                                    <div class="details-info-item">
                                                        <i class="fas fa-map-pin"></i>
                                                        <span class="details-label">Full Address:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['address'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-city"></i>
                                                        <span class="details-label">City:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['city'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-location-arrow"></i>
                                                        <span class="details-label">District:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['district'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-road"></i>
                                                        <span class="details-label">Street:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['street'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-building"></i>
                                                        <span class="details-label">Building:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['building_number'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-door-open"></i>
                                                        <span class="details-label">Apartment:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['apartment_number'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-mail-bulk"></i>
                                                        <span class="details-label">Postal Code:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['postal_code'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Documents & Vehicle Section -->
                                            <div class="details-section">
                                                <h4>
                                                    <i class="fas fa-file-alt"></i>
                                                    Documents & Vehicle
                                                </h4>
                                                <div class="details-info-grid">
                                                    <div class="details-info-item">
                                                        <i class="fas fa-id-card"></i>
                                                        <span class="details-label">Birth Certificate:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['birth_cert'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-certificate"></i>
                                                        <span class="details-label">Certificate No:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['birth_certificate_number'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-calendar"></i>
                                                        <span class="details-label">Birth Date:</span>
                                                        <span
                                                            class="details-value"><?php echo $row['birth_date'] ? h(date('Y-m-d', strtotime((string) $row['birth_date']) ?: null)) : NOT_SPECIFIED; ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-car"></i>
                                                        <span class="details-label">Car Number:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['personal_car_number'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <div class="details-info-item">
                                                        <i class="fas fa-info-circle"></i>
                                                        <span class="details-label">License Info:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['driving_license_metadata'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Financial & Social Section -->
                                            <div class="details-section">
                                                <h4>
                                                    <i class="fas fa-wallet"></i>
                                                    Financial & Social
                                                </h4>
                                                <div class="details-info-grid">
                                                    <div class="details-info-item">
                                                        <i class="fas fa-hashtag"></i>
                                                        <span class="details-label">Social Media:</span>
                                                        <span
                                                            class="details-value"><?php echo h($row['social_media'] ?: NOT_SPECIFIED); ?></span>
                                                    </div>
                                                    <?php
                                                    $bankAccountsData = $row['bank_accounts'] ?? '';
                                                    $bank_cards = [];
                                                    if ($bankAccountsData) {
                                                        $decrypted = SensitiveDataService::decrypt((string) $bankAccountsData);
                                                        if ($decrypted) {
                                                            $bank_cards = json_decode($decrypted, true) ?? [];
                                                        }
                                                    }
                                                    if (!empty($bank_cards) && is_array($bank_cards)) {
                                                        echo '<div class="details-info-item bank-cards">';
                                                        echo '    <i class="fas fa-credit-card"></i>';
                                                        echo '    <div class="bank-cards-container">';
                                                        echo '        <span class="details-label">Bank Cards:</span>';
                                                        echo '        <div class="bank-cards-grid">';

                                                        foreach ($bank_cards as $card) {
                                                            $maskedCard = maskCardNumber((string) ($card['number'] ?? ''));
                                                            echo '        <div class="bank-card">';
                                                            echo '            <div class="bank-card-header">';
                                                            echo '                <i class="fas fa-credit-card"></i>';
                                                            echo '                <span class="bank-name">' . h($card['bank'] ?? 'Bank not specified') . '</span>';
                                                            echo '            </div>';
                                                            echo '            <div class="bank-card-number">';
                                                            echo '                <span class="card-number">' . h($maskedCard) . '</span>';
                                                            echo '            </div>';
                                                            echo '            <div class="bank-card-details">';
                                                            if (!empty($card['name'])) {
                                                                echo '            <div class="card-detail">';
                                                                echo '                <span class="detail-label">Card Holder:</span>';
                                                                echo '                <span class="detail-value">' . h($card['name']) . '</span>';
                                                                echo '            </div>';
                                                            }
                                                            if (!empty($card['expiry'])) {
                                                                echo '            <div class="card-detail">';
                                                                echo '                <span class="detail-label">Expiry Date:</span>';
                                                                echo '                <span class="detail-value">' . h($card['expiry']) . '</span>';
                                                                echo '            </div>';
                                                            }
                                                            echo '            </div>';
                                                            echo '        </div>';
                                                        }

                                                        echo '        </div>';
                                                        echo '    </div>';
                                                        echo '</div>';
                                                    } else {
                                                        echo '<div class="details-info-item">';
                                                        echo '    <i class="fas fa-university"></i>';
                                                        echo '    <span class="details-label">Bank Cards:</span>';
                                                        echo '    <span class="details-value">No bank cards found</span>';
                                                        echo '</div>';
                                                    }
                                                    ?>
                                                </div>

                                                <!-- Additional Information Section -->
                                                <div class="details-section">
                                                    <h4>
                                                        <i class="fas fa-info-circle"></i>
                                                        Additional Information
                                                    </h4>
                                                    <div class="details-info-grid">
                                                        <div class="details-info-item">
                                                            <i class="fas fa-clock"></i>
                                                            <span class="details-label">Creation Date:</span>
                                                            <span
                                                                class="details-value"><?php echo $row['created_at'] ? h(date(DATE_FORMAT, strtotime((string) $row['created_at']) ?: null)) : NOT_SPECIFIED; ?></span>
                                                        </div>
                                                        <div class="details-info-item">
                                                            <i class="fas fa-edit"></i>
                                                            <span class="details-label">Last Update:</span>
                                                            <span
                                                                class="details-value"><?php echo $row['updated_at'] ? h(date(DATE_FORMAT, strtotime((string) $row['updated_at']) ?: null)) : NOT_SPECIFIED; ?></span>
                                                        </div>
                                                        <div class="details-info-item">
                                                            <i class="fas fa-user-plus"></i>
                                                            <span class="details-label">Submitted By:</span>
                                                            <span
                                                                class="details-value"><?php echo h($row['submitted_by'] ?: NOT_SPECIFIED); ?></span>
                                                        </div>
                                                        <div class="details-info-item">
                                                            <i class="fas fa-calendar-check"></i>
                                                            <span class="details-label">Submission Date:</span>
                                                            <span
                                                                class="details-value"><?php echo $row['submission_date'] ? h(date(DATE_FORMAT, strtotime((string) $row['submission_date']) ?: null)) : NOT_SPECIFIED; ?></span>
                                                        </div>
                                                        <div class="details-info-item">
                                                            <i class="fas fa-file-upload"></i>
                                                            <span class="details-label">Files Upload Status:</span>
                                                            <span
                                                                class="details-value status-badge <?php echo strtolower((string) ($row['files_upload_status'] ?: 'pending')); ?>">
                                                                <?php
                                                                $status = $row['files_upload_status'] ?: 'pending';
                                                                $status_translations = [
                                                                    'pending' => 'Pending',
                                                                    'completed' => 'Completed',
                                                                    'failed' => 'Failed'
                                                                ];
                                                                echo h($status_translations[(string) $status] ?? $status);
                                                                ?>
                                                            </span>
                                                        </div>
                                                        <div class="details-info-item">
                                                            <i class="fas fa-info"></i>
                                                            <span class="details-label">Request Status:</span>
                                                            <span
                                                                class="details-value status-badge <?php echo strtolower((string) $row['status']); ?>">
                                                                <?php
                                                                $status = $row['status'];
                                                                $status_translations = [
                                                                    'pending' => 'Pending',
                                                                    'approved' => 'Approved',
                                                                    'rejected' => 'Rejected'
                                                                ];
                                                                echo h($status_translations[(string) $status] ?? $status);
                                                                ?>
                                                            </span>
                                                        </div>
                                                        <?php if ($row['approved_by']): ?>
                                                            <div class="details-info-item">
                                                                <i class="fas fa-user-check"></i>
                                                                <span class="details-label">Approved By:</span>
                                                                <span
                                                                    class="details-value"><?php echo h($row['approved_by']); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($row['approval_date']): ?>
                                                            <div class="details-info-item">
                                                                <i class="fas fa-calendar-check"></i>
                                                                <span class="details-label">Approval Date:</span>
                                                                <span
                                                                    class="details-value"><?php echo h(date(DATE_FORMAT, strtotime((string) $row['approval_date']) ?: null)); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- Images Section -->
                                                <div class="details-section images-section">
                                                    <h4>
                                                        <i class="fas fa-images"></i>
                                                        Attached Images
                                                    </h4>
                                                    <div class="images-grid">
                                                        <div class="image-preview-container">
                                                            <div class="image-preview">
                                                                <?php if ($row['profile_image']): ?>
                                                                    <button type="button" class="btn-image-modal"
                                                                        onclick="openImageModal('data:image/jpeg;base64,<?php echo base64_encode((string) $row['profile_image']); ?>', 'Profile')">
                                            <img src="data:image/jpeg;base64,<?php echo base64_encode((string) $row['profile_image']); ?>"
                                                                            alt="Profile">
                                                                    </button>
                                                                <?php else: ?>
                                                                    <div class="no-image">
                                                                        <i class="fas fa-user"></i>
                                                                        <span>No image</span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="image-label">Profile Image</div>
                                                        </div>

                                                        <div class="image-preview-container">
                                                            <div class="image-preview">
                                                                <?php if ($row['person_photo']): ?>
                                                                    <button type="button" class="btn-image-modal"
                                                                        onclick="openImageModal('data:image/jpeg;base64,<?php echo base64_encode($row['person_photo']); ?>', 'Person')">
                                                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($row['person_photo']); ?>"
                                                                            alt="Person">
                                                                    </button>
                                                                <?php else: ?>
                                                                    <div class="no-image">
                                                                        <i class="fas fa-camera"></i>
                                                                        <span>No image</span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="image-label">Person Photo</div>
                                                        </div>

                                                        <div class="image-preview-container">
                                                            <div class="image-preview">
                                                                <?php if ($row['id_card_file']): ?>
                                                                    <button type="button" class="btn-image-modal"
                                                                        onclick="openImageModal('data:image/jpeg;base64,<?php echo base64_encode($row['id_card_file']); ?>', 'ID Card')">
                                                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($row['id_card_file']); ?>"
                                                                            alt="ID Card">
                                                                    </button>
                                                                <?php else: ?>
                                                                    <div class="no-image">
                                                                        <i class="fas fa-id-card"></i>
                                                                        <span>No image</span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="image-label">ID Card File</div>
                                                        </div>

                                                        <!-- Driving License Image -->
                                                        <?php if ($row['driving_license_image']): ?>
                                                            <div class="image-preview-container">
                                                                <div class="image-preview">
                                                                    <button type="button" class="btn-image-modal"
                                                                        onclick="openImageModal('data:image/jpeg;base64,<?php echo base64_encode($row['driving_license_image']); ?>', 'Driving License')">
                                                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($row['driving_license_image']); ?>"
                                                                            alt="Driving License">
                                                                    </button>
                                                                </div>
                                                                <div class="image-label">Driving License</div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <!-- Actions Section -->
                                                <div class="details-section actions-section">
                                                    <h4>
                                                        <i class="fas fa-cogs"></i>
                                                        Available Actions
                                                    </h4>
                                                    <div class="final-actions">
                                                        <button class="btn btn-approve"
                                                            onclick="approveRecord(<?php echo $row['id']; ?>)">
                                                            <i class="fas fa-check"></i>
                                                            Approve Request
                                                        </button>
                                                        <button class="btn btn-reject"
                                                            onclick="rejectRecord(<?php echo $row['id']; ?>)">
                                                            <i class="fas fa-times"></i>
                                                            Reject Request
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1" class="pagination-btn">
                            <i class="fas fa-angle-double-right"></i> First
                        </a>
                        <a href="?page=<?php echo $page - 1; ?>" class="pagination-btn">
                            <i class="fas fa-angle-right"></i> Previous
                        </a>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <a href="?page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="pagination-btn">
                            <i class="fas fa-angle-left"></i> Next
                        </a>
                        <a href="?page=<?php echo $total_pages; ?>" class="pagination-btn">
                            <i class="fas fa-angle-double-left"></i> Last
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <p>No records found matching the current filter:
                    <strong><?php echo h($status_filter); ?></strong>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <div class="image-modal-content">
            <button class="image-modal-close" onclick="closeImageModal()">
                <i class="fas fa-times"></i>
            </button>
            <div class="image-modal-body">
                <img id="modalImage" src="" alt="Full view">
                <div id="modalTitle" class="image-modal-title"></div>
            </div>
        </div>
    </div>

    <script>
        // Global variable for tracking expanded rows
        let expandedRows = new Set();

        // CSRF token from session for dynamic POST forms
        const csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>';

        // Cooldown to prevent double triggering
        let toggleCooldown = false;

        // Toggle row expansion
        function toggleRowExpansion(recordId, event) {
            if (toggleCooldown) return;
            toggleCooldown = true;
            setTimeout(() => { toggleCooldown = false; }, 300);

            // Ignore clicks inside dropdowns
            if (
                event &&
                (event.target.closest('.dropdown') ||
                    event.target.closest('.dropdown-menu') ||
                    event.target.closest('.dropdown-toggle') ||
                    event.target.closest('[data-toggle="dropdown"]'))
            ) {
                return;
            }

            if (event && event.stopPropagation) event.stopPropagation();

            const mainRow = document.getElementById(`row-${recordId}`);
            const detailsRow = document.getElementById(`details-row-${recordId}`);

            if (!mainRow || !detailsRow) {
                console.error('Could not find main row or details row for recordId:', recordId);
                return;
            }

            const isExpanded = expandedRows.has(recordId);

            // Close all other expanded rows
            expandedRows.forEach(id => {
                if (id !== recordId) {
                    const otherMainRow = document.getElementById(`row-${id}`);
                    const otherDetailsRow = document.getElementById(`details-row-${id}`);
                    if (otherDetailsRow) {
                        otherDetailsRow.style.display = 'none';
                        otherDetailsRow.classList.remove('expanded');
                    }
                    if (otherMainRow) {
                        otherMainRow.classList.remove('expanded');
                    }
                }
            });

            // Toggle this row
            if (isExpanded) {
                detailsRow.style.display = 'none';
                detailsRow.classList.remove('expanded');
                mainRow.classList.remove('expanded');
                expandedRows.delete(recordId);
            } else {
                // Row opened (debug suppressed)
                detailsRow.style.display = 'table-row';
                detailsRow.classList.add('expanded');
                mainRow.classList.add('expanded');
                expandedRows.add(recordId);

                // Smooth scroll into view
                setTimeout(() => {
                    const tableContainer = mainRow.closest('.table-container') || document.querySelector('.table-container');
                    if (tableContainer) {
                        const rect = detailsRow.getBoundingClientRect();
                        const containerRect = tableContainer.getBoundingClientRect();
                        if (rect.bottom > containerRect.bottom || rect.top < containerRect.top) {
                            detailsRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    }
                }, 100);
            }
        }

        // Image modal controls
        function openImageModal(imageSrc, title) {
            const modal = document.getElementById('imageModal');
            const modalImage = document.getElementById('modalImage');
            const modalTitle = document.getElementById('modalTitle');
            modalImage.src = imageSrc;
            modalTitle.textContent = title;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        document.getElementById('imageModal').addEventListener('click', function (e) {
            if (e.target === this) closeImageModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeImageModal();
                expandedRows.forEach(recordId => {
                    const detailsRow = document.getElementById(`details-row-${recordId}`);
                    const mainRow = document.getElementById(`row-${recordId}`);
                    if (detailsRow) detailsRow.style.display = 'none';
                    if (mainRow) mainRow.classList.remove('expanded');
                });
                expandedRows.clear();
            }
        });

        // Approval and rejection
        function approveRecord(recordId) {
            if (confirm('Are you sure you want to approve this request? It will be added to the main database.')) {
                const buttons = document.querySelectorAll(`button[onclick="approveRecord(${recordId})"]`);
                buttons.forEach(button => {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    setTimeout(() => submitApprovalForm(recordId, 'approve'), 100);
                });
            }
        }

        function rejectRecord(recordId) {
            if (confirm('Are you sure you want to reject this request?')) {
                const buttons = document.querySelectorAll(`button[onclick="rejectRecord(${recordId})"]`);
                buttons.forEach(button => {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                });
                submitApprovalForm(recordId, 'reject');
            }
        }

        function submitApprovalForm(recordId, action) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
            <input type="hidden" name="record_id" value="${recordId}">
            <input type="hidden" name="action" value="${action}">
            <input type="hidden" name="csrf_token" value="${csrfToken}">
        `;
            document.body.appendChild(form);
            form.submit();
        }

        // Navigation
        function logout() {
            if (confirm('Would you like to log out?')) window.location.href = '../logout.php';
        }

        function goHome() {
            window.location.href = '../index.php';
        }

        // Sorting
        function applySort(dir) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('sort', dir === 'asc' ? 'asc' : 'desc');
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
            } catch {
                const base = window.location.pathname;
                const params = new URLSearchParams(window.location.search);
                params.set('sort', dir === 'asc' ? 'asc' : 'desc');
                params.set('page', '1');
                window.location.href = `${base}?${params.toString()}`;
            }
        }

        // Status Filter
        function applyStatusFilter(status) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('status', status);
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
            } catch {
                const base = window.location.pathname;
                const params = new URLSearchParams(window.location.search);
                params.set('status', status);
                params.set('page', '1');
                window.location.href = `${base}?${params.toString()}`;
            }
        }

        // Search
        document.getElementById('searchInput').addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.full-row');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const shouldShow = !searchTerm || text.includes(searchTerm);
                row.style.display = shouldShow ? '' : 'none';

                const recordId = row.id.replace('row-', '');
                const detailsRow = document.getElementById(`details-row-${recordId}`);
                if (!shouldShow && detailsRow) {
                    detailsRow.style.display = 'none';
                    expandedRows.delete(parseInt(recordId));
                }
            });
        });

        // Reset filters
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.querySelectorAll('.full-row').forEach(row => (row.style.display = ''));
            expandedRows.forEach(recordId => {
                const detailsRow = document.getElementById(`details-row-${recordId}`);
                if (detailsRow) detailsRow.style.display = 'table-row';
            });
        }

        // Alerts
        function showAlert(message, type) {
            document.querySelectorAll('.alert').forEach(a => a.remove());
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.textContent = message;
            document.body.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.style.transition = 'all 0.5s ease';
                alertDiv.style.opacity = '0';
                alertDiv.style.transform = 'translateX(150%)';
                setTimeout(() => alertDiv.remove(), 500);
            }, 5000);
        }

        // Update points via AJAX
        function updatePoints(recordId) {
            const pointsInput = document.getElementById(`points-${recordId}`);
            const newPoints = parseInt(pointsInput.value);
            if (isNaN(newPoints) || newPoints < 1 || newPoints > 500000) {
                showAlert('Please enter a valid value between 1 and 500,000', 'error');
                pointsInput.focus();
                return;
            }

            const saveBtn = pointsInput.parentNode.querySelector('.btn-save-points');
            const originalIcon = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            saveBtn.disabled = true;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'update_points', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    saveBtn.innerHTML = originalIcon;
                    saveBtn.disabled = false;
                    if (xhr.status === 200) {
                        let response;
                        try {
                            response = JSON.parse(xhr.responseText);
                        } catch (e) {
                            showAlert('Invalid server response', 'error');
                            pointsInput.value = pointsInput.getAttribute('data-original-value') || 0;
                            return;
                        }
                        if (response.success) {
                            showAlert('Points updated successfully', 'success');
                            pointsInput.setAttribute('data-original-value', newPoints);
                        } else {
                            showAlert('Error updating points: ' + response.message, 'error');
                            pointsInput.value = pointsInput.getAttribute('data-original-value') || 0;
                        }
                    } else {
                        showAlert('Error communicating with server', 'error');
                        pointsInput.value = pointsInput.getAttribute('data-original-value') || 0;
                    }
                }
            };
            xhr.send(
                `record_id=${encodeURIComponent(recordId)}&points=${encodeURIComponent(newPoints)}&csrf_token=${encodeURIComponent(csrfToken)}`
            );
        }

        // Auto-hide alerts and initialize
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'all 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateX(150%)';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Professional Admins page initialized (debug suppressed)
        });

        // Keyboard shortcut: Ctrl+F focuses search
        document.addEventListener('keydown', e => {
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
        });
    </script>

</body>

</html>

<?php

// Close database connection
mysqli_close($con);

// End output buffering
ob_end_flush();

<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/csrf.php';

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
$isOnionService =
    (isset($_SERVER["HTTP_HOST"]) &&
        preg_match('/\.onion$/i', $_SERVER["HTTP_HOST"])) ||
    (isset($_SERVER["SERVER_NAME"]) &&
        preg_match('/\.onion$/i', $_SERVER["SERVER_NAME"]));

// ============================================================================
// SECURITY HEADERS
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
}

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
// ADMIN AUTHENTICATION CHECK
// ============================================================================
$resources = PageController::setup('Admin - Password Upload', '/', ['css/search_table.css', 'css/admin_upload.css'], ['render_layout' => false]);

$con = $resources['db'] ?? Database::getConnection();
if (!$con) {
    error_log("Database connection failed in admin_password_upload.php");
    die("A database error occurred. Please try again later.");
}

$is_authenticated = $resources['is_authenticated'] ?? false;
if (!$is_authenticated) {
    header('Location: login');
    exit;
}

// Check if user is admin
$user_subscription = $resources['user']['subscription'] ?? ($_SESSION['subscription'] ?? 'free');
if (strtolower($user_subscription) !== 'admin') {
    header('Location: dashboard');
    exit;
}

// ============================================================================
// UPLOAD PROCESSING
// ============================================================================
$uploadMessage = '';
$uploadError = '';
$previewData = [];
$showPreview = false;
$uploadStats = null;

const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const MAX_RECORDS = 10000;
const BATCH_SIZE = 1000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $uploadError = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {

        // Preview mode
        if (isset($_POST['action']) && $_POST['action'] === 'preview') {
            if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] === UPLOAD_ERR_NO_FILE) {
                $uploadError = 'Please select a JSON file to upload.';
            } else {
                $file = $_FILES['json_file'];

                // Validate file
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadError = 'File upload error. Please try again.';
                } elseif ($file['size'] > MAX_FILE_SIZE) {
                    $uploadError = 'File too large. Maximum size is 5MB.';
                } elseif (!preg_match('/\.json$/i', $file['name'])) {
                    $uploadError = 'Invalid file type. Only JSON files are allowed.';
                } else {
                    // Read and parse JSON
                    $jsonContent = file_get_contents($file['tmp_name']);
                    if ($jsonContent === false) {
                        $uploadError = 'Failed to read uploaded file.';
                    } else {
                        $data = json_decode($jsonContent, true);
                        if ($data === null) {
                            $uploadError = 'Invalid JSON format: ' . json_last_error_msg();
                        } elseif (!is_array($data)) {
                            $uploadError = 'JSON must be an array of objects.';
                        } elseif (count($data) > MAX_RECORDS) {
                            $uploadError = 'Too many records. Maximum is ' . number_format(MAX_RECORDS) . ' records.';
                        } else {
                            // Validate structure of first few records
                            $validRecords = 0;
                            $invalidRecords = 0;
                            foreach ($data as $index => $record) {
                                if (!is_array($record)) {
                                    $invalidRecords++;
                                    continue;
                                }
                                if (empty($record['email']) || empty($record['password'])) {
                                    $invalidRecords++;
                                    continue;
                                }
                                $validRecords++;
                                if (count($previewData) < 10) {
                                    $previewData[] = [
                                        'email' => substr($record['email'], 0, 50),
                                        'username' => substr($record['username'] ?? 'N/A', 0, 30),
                                        'source' => substr($record['source'] ?? 'unknown', 0, 50),
                                        'leak_date' => $record['leak_date'] ?? null
                                    ];
                                }
                            }
                            if ($validRecords === 0) {
                                $uploadError = 'No valid records found in JSON file.';
                            } else {
                                $showPreview = true;
                                $_SESSION['password_upload_data'] = $data;
                                $uploadMessage = "Found $validRecords valid records" . ($invalidRecords > 0 ? " ($invalidRecords invalid)" : "");
                            }
                        }
                    }
                }
            }
        }

        // Upload/Import mode
        elseif (isset($_POST['action']) && $_POST['action'] === 'upload') {
            if (!isset($_SESSION['password_upload_data'])) {
                $uploadError = 'No data to upload. Please upload a file first.';
            } else {
                $data = $_SESSION['password_upload_data'];
                $stats = ['added' => 0, 'updated' => 0, 'failed' => 0, 'total' => count($data)];

                // Process in batches
                $con->autocommit(false);

                try {
                    $insertQuery = "INSERT INTO password_leaks (email, username, password_hash, source, leak_date)
                                   VALUES (?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE
                                   username = VALUES(username),
                                   password_hash = VALUES(password_hash),
                                   leak_date = VALUES(leak_date)";

                    $stmt = mysqli_prepare($con, $insertQuery);

                    if (!$stmt) {
                        throw new \ROOTS\Exceptions\DatabaseException(
                            "Failed to prepare statement: " . mysqli_error($con),
                        );
                    }

                    foreach ($data as $record) {
                        if (!is_array($record) || empty($record['email']) || empty($record['password'])) {
                            $stats['failed']++;
                            continue;
                        }

                        $email = strtolower(trim($record['email']));
                        $password = $record['password'];
                        $username = isset($record['username']) ? trim($record['username']) : null;
                        $source = isset($record['source']) ? trim($record['source']) : 'unknown';
                        $leakDate = null;

                        // Validate and parse leak_date
                        if (!empty($record['leak_date'])) {
                            $parsedDate = strtotime($record['leak_date']);
                            if ($parsedDate !== false) {
                                $leakDate = date('Y-m-d', $parsedDate);
                            }
                        }

                        // Hash the password (sha256 for fast lookup, not for security)
                        $passwordHash = hash('sha256', $password);

                        mysqli_stmt_bind_param($stmt, "sssss", $email, $username, $passwordHash, $source, $leakDate);

                        if (mysqli_stmt_execute($stmt)) {
                            $affectedRows = mysqli_stmt_affected_rows($stmt);
                            if ($affectedRows === 1) {
                                $stats['added']++;
                            } elseif ($affectedRows === 2) {
                                $stats['updated']++;
                            } else {
                                $stats['failed']++;
                            }
                        } else {
                            $stats['failed']++;
                            error_log("Failed to insert password record: " . mysqli_stmt_error($stmt));
                        }
                    }

                    mysqli_stmt_close($stmt);
                    $con->commit();
                    $uploadStats = $stats;
                    $uploadMessage = 'Upload completed successfully!';

                    // Clear session data
                    unset($_SESSION['password_upload_data']);

                } catch (Exception $e) {
                    $con->rollback();
                    $uploadError = 'Upload failed: ' . $e->getMessage();
                    error_log("Password upload error: " . $e->getMessage());
                }

                $con->autocommit(true);
            }
        }
    }
}

// Get current stats
$countQuery = "SELECT COUNT(*) as total FROM password_leaks";
$countResult = mysqli_query($con, $countQuery);
$totalRecords = 0;
if ($countResult instanceof mysqli_result && ($row = mysqli_fetch_assoc($countResult))) {
    $totalRecords = (int) $row['total'];
}

$sourceQuery = "SELECT source, COUNT(*) as count FROM password_leaks GROUP BY source ORDER BY count DESC LIMIT 10";
$sourceResult = mysqli_query($con, $sourceQuery);
$sources = [];
if ($sourceResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($sourceResult)) {
        $sources[] = $row;
    }
}

use ROOTS\Layout\MasterLayout;
$layout = MasterLayout::createDefault();
$layout->renderPageStart('Admin - Password Upload', '/', ['css/search_table.css', 'css/admin_upload.css']);
?>

<div class="admin-upload-container">
    <h2 class="page-title">
        <i class="fas fa-upload"></i> Password Database Upload
    </h2>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalRecords) ?></div>
            <div class="stat-label">Total Records</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(count($sources)) ?></div>
            <div class="stat-label">Unique Sources</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(MAX_RECORDS) ?></div>
            <div class="stat-label">Max Per Upload</div>
        </div>
    </div>

    <!-- Sources Breakdown -->
    <?php if (!empty($sources)): ?>
    <div class="sources-section">
        <h4><i class="fas fa-database"></i> Records by Source</h4>
        <div class="sources-list">
            <?php foreach ($sources as $source): ?>
            <div class="source-item">
                <span class="source-name"><?= htmlspecialchars((string) ($source['source'] ?? '')) ?></span>
                <span class="source-count"><?= number_format((int) ($source['count'] ?? 0)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upload Form -->
    <?php if (!$showPreview): ?>
    <div class="upload-form-section">
        <h3><i class="fas fa-file-upload"></i> Upload JSON File</h3>

        <?php if ($uploadError): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($uploadError) ?>
        </div>
        <?php endif; ?>

        <?php if ($uploadMessage): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> <?= htmlspecialchars($uploadMessage) ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="preview">

            <div class="form-group">
                <label for="json_file">
                    <i class="fas fa-file-code"></i> Select JSON File
                </label>
                <input type="file"
                       name="json_file"
                       id="json_file"
                       accept=".json,application/json"
                       required
                       class="form-control">
                <small class="form-text">
                    Maximum file size: 5MB. Maximum records: <?= number_format(MAX_RECORDS) ?>
                </small>
            </div>

            <div class="json-format-help">
                <h5><i class="fas fa-code"></i> Expected JSON Format:</h5>
                <pre><code>[
  {
    "email": "user@example.com",
    "password": "plaintext_password",
    "source": "breach_name",
    "username": "optional_username",
    "leak_date": "2023-01-15"
  },
  ...
]</code></pre>
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-eye"></i> Preview & Validate
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Preview Section -->
    <?php if ($showPreview): ?>
    <div class="preview-section">
        <h3><i class="fas fa-table"></i> Data Preview (First <?= count($previewData) ?> records)</h3>

        <?php if ($uploadMessage): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> <?= htmlspecialchars($uploadMessage) ?>
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table preview-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Username</th>
                        <th>Source</th>
                        <th>Leak Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewData as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><?= htmlspecialchars($row['source']) ?></td>
                        <td><?= $row['leak_date'] ? htmlspecialchars($row['leak_date']) : 'Not specified' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($previewData) < count($_SESSION['password_upload_data'] ?? [])): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Showing first <?= count($previewData) ?> of <?= number_format(count($_SESSION['password_upload_data'] ?? [])) ?> records.
        </div>
        <?php endif; ?>

        <div class="preview-actions">
            <form method="POST" class="confirm-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="upload">

                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-check"></i> Confirm & Upload to Database
                </button>
            </form>

            <a href="admin_password_upload" class="btn btn-secondary btn-lg">
                <i class="fas fa-times"></i> Cancel & Start Over
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upload Results -->
    <?php if ($uploadStats): ?>
    <div class="results-section">
        <h3><i class="fas fa-chart-bar"></i> Upload Results</h3>

        <div class="results-grid">
            <div class="result-card success">
                <div class="result-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="result-value"><?= number_format($uploadStats['added']) ?></div>
                <div class="result-label">New Records Added</div>
            </div>

            <div class="result-card warning">
                <div class="result-icon"><i class="fas fa-sync-alt"></i></div>
                <div class="result-value"><?= number_format($uploadStats['updated']) ?></div>
                <div class="result-label">Existing Updated</div>
            </div>

            <div class="result-card danger">
                <div class="result-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="result-value"><?= number_format($uploadStats['failed']) ?></div>
                <div class="result-label">Failed</div>
            </div>

            <div class="result-card info">
                <div class="result-icon"><i class="fas fa-database"></i></div>
                <div class="result-value"><?= number_format($uploadStats['total']) ?></div>
                <div class="result-label">Total Processed</div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="admin_password_upload" class="btn btn-primary">
                <i class="fas fa-upload"></i> Upload Another File
            </a>
            <a href="password_db" class="btn btn-secondary">
                <i class="fas fa-search"></i> View Password Database
            </a>
        </div>
    </div>
    <?php endif; ?>

</div>

<style>
.admin-upload-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.page-title {
    color: var(--bc-text);
    margin-bottom: 30px;
    border-bottom: 2px solid var(--bc-accent);
    padding-bottom: 10px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--bc-border);
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: var(--bc-accent);
}

.stat-label {
    color: var(--bc-text-muted);
    margin-top: 5px;
}

.sources-section {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.sources-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
    margin-top: 15px;
}

.source-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
}

.source-count {
    color: var(--bc-accent);
    font-weight: bold;
}

.upload-form-section,
.preview-section,
.results-section {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--bc-text);
}

.form-control {
    width: 100%;
    padding: 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--bc-border);
    border-radius: 4px;
    color: var(--bc-text);
}

.json-format-help {
    background: rgba(0, 0, 0, 0.2);
    padding: 15px;
    border-radius: 4px;
    margin: 20px 0;
}

.json-format-help pre {
    margin: 10px 0 0;
    padding: 10px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 4px;
    overflow-x: auto;
}

.preview-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
}

.preview-table th,
.preview-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--bc-border);
}

.preview-table th {
    background: rgba(46, 204, 113, 0.1);
    color: var(--bc-accent);
}

.preview-actions {
    display: flex;
    gap: 15px;
    margin-top: 30px;
    flex-wrap: wrap;
}

.results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.result-card {
    padding: 20px;
    border-radius: 8px;
    text-align: center;
}

.result-card.success { background: rgba(46, 204, 113, 0.1); border: 1px solid rgba(46, 204, 113, 0.3); }
.result-card.warning { background: rgba(241, 196, 15, 0.1); border: 1px solid rgba(241, 196, 15, 0.3); }
.result-card.danger { background: rgba(231, 76, 60, 0.1); border: 1px solid rgba(231, 76, 60, 0.3); }
.result-card.info { background: rgba(52, 152, 219, 0.1); border: 1px solid rgba(52, 152, 219, 0.3); }

.result-icon {
    font-size: 2rem;
    margin-bottom: 10px;
}

.result-value {
    font-size: 2rem;
    font-weight: bold;
}

.action-buttons {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}

.btn {
    padding: 12px 24px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--bc-accent);
    color: #fff;
}

.btn-success {
    background: #27ae60;
    color: #fff;
}

.btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: var(--bc-text);
    border: 1px solid var(--bc-border);
}

.alert {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.alert-danger {
    background: rgba(231, 76, 60, 0.1);
    border: 1px solid rgba(231, 76, 60, 0.3);
    color: #e74c3c;
}

.alert-info {
    background: rgba(52, 152, 219, 0.1);
    border: 1px solid rgba(52, 152, 219, 0.3);
    color: #3498db;
}
</style>

<?php
// End page rendering
PageController::end('/', ['js/search_table.js']);
?>

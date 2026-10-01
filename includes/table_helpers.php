<?php

declare(strict_types=1);

use ROOTS\Layout\LayoutHelpers;

/** Escape output for HTML attributes and text. */
function table_h(mixed $value): string
{
    if ($value === null || is_bool($value)) {
        return LayoutHelpers::h($value === null ? null : ($value ? '1' : '0'));
    }
    if (is_int($value) || is_float($value)) {
        return LayoutHelpers::h((string) $value);
    }
    return LayoutHelpers::h(is_string($value) ? $value : (string) $value);
}

/**
 * Validate search query (whitelist charset + length). Returns null when invalid.
 */
function table_validate_search_term(string $raw): ?string
{
    $sanitized = trim($raw);
    if ($sanitized === '' || strlen($sanitized) > 100) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9+\-@_.\s]+$/', $sanitized)) {
        return null;
    }
    return $sanitized;
}

/**
 * @return list<int|string>
 */
function table_flag_codes_sorted(): array
{
    static $sortedCodes = null;
    if ($sortedCodes === null) {
        /** @var array<int|string, string> $flagMap */
        $flagMap = require __DIR__ . '/table_country_flags.php';
        $sortedCodes = array_keys($flagMap);
        usort(
            $sortedCodes,
            static fn (int|string $a, int|string $b): int => strlen((string) $b) <=> strlen((string) $a),
        );
    }
    return $sortedCodes;
}

/**
 * Resolve a safe relative flag image path from a phone number.
 */
function table_resolve_flag_src(?string $phone): string
{
    static $flagMap = null;
    static $allowedFiles = null;
    static $fileExistsCache = [];

    if ($flagMap === null) {
        $flagMap = require __DIR__ . '/table_country_flags.php';
        $allowedFiles = array_unique(array_merge(array_values($flagMap), ['stock.png']));
    }

    $phoneClean = preg_replace('/\D/', '', (string) $phone) ?? '';
    $flagFile = 'stock.png';

    if ($phoneClean !== '') {
        foreach (table_flag_codes_sorted() as $code) {
            if (str_starts_with($phoneClean, (string) $code)) {
                $flagFile = table_validate_flag_candidate($flagMap[$code], $allowedFiles, $fileExistsCache);
                break;
            }
        }
    }

    $flagFile = basename($flagFile);
    if (!in_array($flagFile, $allowedFiles, true)) {
        $flagFile = 'stock.png';
    }

    return 'id/' . $flagFile;
}

/**
 * Internal helper to validate if a flag candidate is allowed and exists.
 * @param array<string> $allowedFiles
 * @param array<string, bool> $cache
 */
function table_validate_flag_candidate(string $candidate, array $allowedFiles, array &$cache): string
{
    if (!in_array($candidate, $allowedFiles, true)) {
        return 'stock.png';
    }

    if (!isset($cache[$candidate])) {
        $cache[$candidate] = is_file(__DIR__ . '/../id/' . $candidate);
    }

    return $cache[$candidate] ? $candidate : 'stock.png';
}

/**
 * @param array<string, mixed> $row
 */
function table_field_has_data(array $row, string $key, bool $treatZeroAsEmpty = false): bool
{
    if (!array_key_exists($key, $row)) {
        return false;
    }
    $value = $row[$key];
    if ($value === null || $value === '') {
        return false;
    }
    if ($treatZeroAsEmpty && (string) $value === '0') {
        return false;
    }
    return true;
}

function table_render_locked_cell(bool $hasData): void
{
    $state = $hasData ? 'has-data' : 'no-data';
    $icon = $hasData ? 'check' : 'exclamation';
    ?>
    <td class="locked-cell">
        <div class="locked-content">
            <i class="fas fa-lock lock-icon <?= $state ?>">
                <span class="lock-badge <?= $icon ?>">
                    <i class="fas fa-<?= $icon ?>"></i>
                </span>
            </i>
        </div>
        <div class="unlock-tooltip">
            <i class="fas fa-shopping-cart"></i> Purchase this row<br>
            to unlock all columns<br>
            and read them anytime
        </div>
    </td>
    <?php
}

/**
 * @return array{rows: list<array<string, mixed>>, total_records: int, total_pages: int, page: int}|null
 */
function table_fetch_search_page(mysqli $con, string $searchTerm, int $page, int $recordsPerPage = 30): ?array
{
    $page = max(1, $page);
    $recordsPerPage = max(1, min(100, $recordsPerPage));
    $offset = ($page - 1) * $recordsPerPage;
    $searchParam = '%' . $searchTerm . '%';

    // Optimization: Use individual column checks instead of CONCAT for better index performance
    $countQuery = 'SELECT COUNT(*) AS total FROM search WHERE id LIKE ? OR u LIKE ? OR n LIKE ? OR e LIKE ? OR t LIKE ?';
    $countStmt = mysqli_prepare($con, $countQuery);
    if (!$countStmt) {
        error_log('table.php: count prepare failed: ' . mysqli_error($con));
        return null;
    }
    mysqli_stmt_bind_param($countStmt, 'sssss', $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    if (!mysqli_stmt_execute($countStmt)) {
        mysqli_stmt_close($countStmt);
        return null;
    }
    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = $countResult ? mysqli_fetch_assoc($countResult) : ['total' => 0];
    mysqli_stmt_close($countStmt);

    $totalRecords = (int) ($countRow['total'] ?? 0);
    $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $recordsPerPage) : 0;
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $recordsPerPage;
    }

    $dataQuery = 'SELECT id, u, n, e, t, profile_image, person_photo,
        a, birth_cert, nationality, relatives, blood_type, social_media, bank_accounts,
        city, district, street, building_number, apartment_number, postal_code,
        birth_certificate_number, birth_date, marital_status, children_count, id_card_file,
        points
        FROM search
        WHERE id LIKE ? OR u LIKE ? OR n LIKE ? OR e LIKE ? OR t LIKE ?
        ORDER BY id DESC LIMIT ?, ?';

    $dataStmt = mysqli_prepare($con, $dataQuery);
    if (!$dataStmt) {
        error_log('table.php: data prepare failed: ' . mysqli_error($con));
        return null;
    }
    mysqli_stmt_bind_param($dataStmt, 'sssssii', $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $offset, $recordsPerPage);
    if (!mysqli_stmt_execute($dataStmt)) {
        mysqli_stmt_close($dataStmt);
        return null;
    }
    $result = mysqli_stmt_get_result($dataStmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($dataStmt);

    return [
        'rows' => $rows,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

/**
 * @param array<string, mixed> $items
 */
function table_render_result_row(array $items): void
{
    $flagSrc = table_resolve_flag_src(isset($items['t']) ? (string) $items['t'] : null);
    $recordId = (int) ($items['id'] ?? 0);
    $points = isset($items['points']) ? (string) $items['points'] : '0';
    ?>
    <tr class="data-row" tabindex="0" aria-selected="false">
        <td class="text-center purchase-column">
            <div class="purchase-button-container">
                <?php if ($recordId > 0): ?>
                <button type="button" class="btn btn-sm btn-success purchase-btn purchase-btn-compact"
                    data-id="<?= table_h((string) $recordId) ?>"
                    data-points="<?= table_h($points) ?>"
                    title="Purchase price: <?= table_h($points) ?> points">
                    <i class="fas fa-shopping-cart" aria-hidden="true"></i>
                </button>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </div>
        </td>
        <td class="text-center">
            <div class="flag-container">
                <img src="<?= table_h($flagSrc) ?>" alt="" class="flag-image" width="40" height="30" loading="lazy" decoding="async">
            </div>
        </td>
        <?php table_render_locked_cell(table_field_has_data($items, 'person_photo')); ?>
        <td><?= table_h($items['u'] ?? '') ?></td>
        <td><?= table_h($items['n'] ?? '') ?></td>
        <td><?= table_h($items['e'] ?? '') ?></td>
        <td><?= table_h($items['t'] ?? '') ?></td>
        <td><?= table_field_has_data($items, 'a') ? table_h((string) $items['a']) : '-' ?></td>
        <?php
    table_render_locked_cell(table_field_has_data($items, 'birth_cert'));
    table_render_locked_cell(table_field_has_data($items, 'nationality'));
    table_render_locked_cell(table_field_has_data($items, 'relatives'));
    table_render_locked_cell(table_field_has_data($items, 'blood_type'));
    table_render_locked_cell(table_field_has_data($items, 'social_media'));
    table_render_locked_cell(table_field_has_data($items, 'bank_accounts'));
    table_render_locked_cell(table_field_has_data($items, 'city'));
    table_render_locked_cell(table_field_has_data($items, 'district'));
    table_render_locked_cell(table_field_has_data($items, 'street'));
    table_render_locked_cell(table_field_has_data($items, 'building_number'));
    table_render_locked_cell(table_field_has_data($items, 'apartment_number'));
    table_render_locked_cell(table_field_has_data($items, 'postal_code'));
    table_render_locked_cell(table_field_has_data($items, 'birth_certificate_number'));
    table_render_locked_cell(table_field_has_data($items, 'birth_date'));
    table_render_locked_cell(table_field_has_data($items, 'marital_status'));
    table_render_locked_cell(
        array_key_exists('children_count', $items) && $items['children_count'] !== null && $items['children_count'] !== '',
    );
    table_render_locked_cell(table_field_has_data($items, 'id_card_file'));
    ?>
    </tr>
    <?php
}

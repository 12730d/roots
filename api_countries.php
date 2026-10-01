<?php
// api_countries.php - API endpoint for fetching countries data

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;

const JSON_HEADER = "Content-Type: application/json";
const HEADER_CONN_CLOSE = 'Connection: close';
const HEADER_CONTENT_LENGTH = 'Content-Length: ';

// Use PageController for proper session handling (without rendering HTML layout)
$pageData = PageController::setup("API_Countries", "./", [], ["require_auth" => true, "render_layout" => false]);

$username = (string) ($pageData["user"]["username"] ?? "");

// Simplified Auth Check
if (empty($username)) {
    http_response_code(403);
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    $response = json_encode(["success" => false, "error" => "Unauthorized"]);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        echo $response;
    }
    exit();
}

try {
    $db = Database::getConnection();

    $limit = isset($_GET["limit"]) ? (int) $_GET["limit"] : 100;
    if ($limit > 200) {
        $limit = 200;
    }
    if ($limit <= 0) {
        $limit = 100;
    }

    $query = "SELECT rank_position, country_name, population_2025, land_area_km2, density_per_km2
              FROM countries
              ORDER BY rank_position ASC
              LIMIT ?";

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new \ROOTS\Exceptions\DatabaseException("Failed to prepare statement");
    }
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $countries = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Format numbers for convenience
            $row["population_formatted"] = number_format((int) ($row["population_2025"] ?? 0));
            $row["land_area_formatted"] =
                number_format((float) ($row["land_area_km2"] ?? 0) / 1000, 1) . "k"; // in thousands
            if (($row["land_area_km2"] ?? 0) < 1000) {
                $row["land_area_formatted"] = number_format((float) ($row["land_area_km2"] ?? 0));
            }
            $countries[] = $row;
        }
    }

    $response = json_encode([
        "success" => true,
        "data" => $countries,
        "count" => count($countries),
    ]);
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        // Cache for 1 hour to improve performance
        header("Cache-Control: max-age=3600");
        echo $response;
    }
} catch (Exception $e) {
    $response = json_encode([
        "success" => false,
        "error" => "DATABASE_ERROR: " . $e->getMessage(),
    ]);
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        echo $response;
    }
}

PageController::end("./");

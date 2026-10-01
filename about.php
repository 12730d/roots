<?php
declare(strict_types=1);

// About / registration guide — plan detail cards rendered from shared catalog.
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/plan-catalog.php';

$year = date('Y');

require_once __DIR__ . '/about.view.php';
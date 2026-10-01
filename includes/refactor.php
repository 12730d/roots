<?php
$lines = file('/var/www/html/includes/plan-catalog.php');
$start = array_slice($lines, 0, 188);
$end = array_slice($lines, 671);

$middle = <<<PHP
\$planMeta = [];
\$planSpecGroups = [];
\$planGuideExtras = [];
\$planCatalogDisplay = [];

require_once __DIR__ . '/plans/free.php';
require_once __DIR__ . '/plans/basic.php';
require_once __DIR__ . '/plans/pro.php';
require_once __DIR__ . '/plans/premium.php';

PHP;

file_put_contents('/var/www/html/includes/plan-catalog.php', implode("", $start) . $middle . implode("", $end));
unlink(__FILE__);

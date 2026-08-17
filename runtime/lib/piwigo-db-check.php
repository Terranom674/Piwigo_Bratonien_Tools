<?php
declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: piwigo-db-check.php MAP_FILE PIWIGO_ROOT\n");
    exit(2);
}

$mapFile = $argv[1];
$piwigoRoot = rtrim($argv[2], '/') . '/';
$databaseConfig = $piwigoRoot . 'local/config/database.inc.php';

if (!is_file($databaseConfig)) {
    exit(1);
}

$mapping = json_decode((string) file_get_contents($mapFile), true, 512, JSON_THROW_ON_ERROR);
$expected = [];
foreach ($mapping as $source => $target) {
    if (str_starts_with((string) $source, 'share:') && !str_contains((string) $source, '/')) {
        $expected[] = basename((string) $target);
    }
}
if ($expected === []) {
    exit(1);
}

$conf = [];
$prefixeTable = '';
require $databaseConfig;

$database = new mysqli(
    (string) $conf['db_host'],
    (string) $conf['db_user'],
    (string) $conf['db_password'],
    (string) $conf['db_base']
);
$database->set_charset('utf8mb4');
$table = $database->real_escape_string((string) $prefixeTable) . 'categories';
$result = $database->query(
    'SELECT dir FROM `' . $table . '` WHERE site_id = 1 AND dir IS NOT NULL'
);
$existing = [];
while ($row = $result->fetch_assoc()) {
    $existing[(string) $row['dir']] = true;
}

foreach ($expected as $directory) {
    if (!isset($existing[$directory])) {
        exit(1);
    }
}
exit(0);

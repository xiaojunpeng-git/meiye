<?php
declare(strict_types=1);

/** Initializes an empty local inventory test database without dropping data. */
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->env->set('cache.driver', 'file');
$app->env->set('CACHE_DRIVER', 'file');
$app->env->set('PHP_CACHE_DRIVER', 'file');
$app->env->set('database.type', 'mysql');
$app->env->set('DATABASE_TYPE', 'mysql');
$app->env->set('database.hostname', getenv('DB_HOST') ?: 'mysql');
$app->env->set('DATABASE_HOSTNAME', getenv('DB_HOST') ?: 'mysql');
$app->env->set('database.hostport', getenv('DB_PORT') ?: '3306');
$app->env->set('DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306');
$app->env->set('database.database', getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730');
$app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730');
$databaseUsername = getenv('DB_USERNAME');
if ($databaseUsername !== false && $databaseUsername !== '') {
    $app->env->set('database.username', $databaseUsername);
    $app->env->set('DATABASE_USERNAME', $databaseUsername);
}
$databasePassword = getenv('DB_PASSWORD');
if ($databasePassword !== false) {
    $app->env->set('database.password', $databasePassword);
    $app->env->set('DATABASE_PASSWORD', $databasePassword);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'inventory_manual_test_bootstrap_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');

function splitInventorySql(string $sql): array
{
    // Upgrade comments may legitimately include a semicolon. Strip complete
    // MySQL -- comment lines before statement splitting so a comment never
    // gets accidentally joined to the following SET/DDL command.
    $sql = preg_replace('/^\\s*--[^\\r\\n]*(?:\\r?\\n|$)/m', '', $sql) ?? $sql;
    $statements = [];
    $buffer = '';
    $quote = '';
    $escaped = false;
    $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $buffer .= $character;
        if ($quote !== '') {
            if ($escaped) { $escaped = false; continue; }
            if ($character === '\\') { $escaped = true; continue; }
            if ($character === $quote) $quote = '';
            continue;
        }
        if ($character === "'" || $character === '"' || $character === '`') { $quote = $character; continue; }
        if ($character === ';') {
            $statement = trim(substr($buffer, 0, -1));
            if ($statement !== '') $statements[] = $statement;
            $buffer = '';
        }
    }
    $tail = trim($buffer);
    if ($tail !== '') $statements[] = $tail;
    return $statements;
}

function executeInventorySqlFile(string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('inventory_test_sql_read_failed:' . $path);
    foreach (splitInventorySql($sql) as $statement) Db::execute($statement);
}

$fixture = getenv('INVENTORY_TEST_FIXTURE') ?: dirname($backend) . '/tests/inventory/sql/manual-inbound-fixture.sql';
$files = [
    $fixture,
    $backend . '/database/upgrades/2026-07-29-库存耗材批次完成合同/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-29-库存统一查询批次事实/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-30-库存V3盘点批次事实/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-30-库存V3请货事实/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-30-库存V3批次调拨事实/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-30-库存V3院装事实/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-28-统一查询自定义字段/02-正式升级.sql',
    $backend . '/database/upgrades/2026-07-30-库存V3统一查询命令/02-正式升级.sql',
];
foreach ($files as $file) executeInventorySqlFile($file);
if (!Db::query("SHOW TABLES LIKE 'eb_system_store'") || !Db::query("SHOW TABLES LIKE 'eb_inventory_batch_movement_fact'") || !Db::query("SHOW TABLES LIKE 'eb_inventory_stock_count_document'")) {
    throw new RuntimeException('inventory_test_bootstrap_schema_missing');
}
echo "INVENTORY_MANUAL_TEST_BOOTSTRAP_OK database=" . (string)Config::get('database.connections.mysql.database') . "\n";

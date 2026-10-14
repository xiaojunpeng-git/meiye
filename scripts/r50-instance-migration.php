<?php
/** CLI-only R50 migration runner. Exact instance identity and checksummed SQL;
 * never copy customer data, grant roles, or overwrite assignment settings.
 * Deployment authorization and existing recovery-point checks precede this CLI.
 */
if (PHP_SAPI !== 'cli') exit(1);
$instance = $argv[1] ?? '';
$mode = $argv[2] ?? 'preflight';
$targets = ['rh' => ['rh.cc3798.com', 'ruihao', '3306'], '008' => ['008.cc3798.com', 'mohe_008', '3307']];
if (!isset($targets[$instance]) || !in_array($mode, ['preflight', 'migrate', 'verify'], true)) throw new RuntimeException('Invalid target or mode');
[$domain, $database, $port] = $targets[$instance];
$root = '/www/wwwroot/' . $domain;
chdir($root);
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
$cfg = config('database.connections.mysql');
if ($cfg['database'] !== $database || (string)$cfg['hostport'] !== $port || !in_array($cfg['hostname'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Instance database mismatch');
$pdo = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$files = [
    '2026-10-14-岗位已授权数据目录显示/02-正式升级.sql' => 'f8337a1e332d46448d78203577df147303b1db2d1eb126f8a266b180fcf34505',
    '2026-10-14-无店员工手艺人设置/02-正式升级.sql' => '2f85ddc06e44530438b482affc164b886670dc8ade5257bea87a7bda51b1bc86',
];
$read = static function () use ($pdo): array {
    return [
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'columns' => $pdo->query("SHOW COLUMNS FROM eb_employee WHERE Field IN ('default_craftsman_performance_type','craftsman_setting_version')")->fetchAll(PDO::FETCH_ASSOC),
        'menus' => $pdo->query("SELECT id,unique_auth,is_show,is_del,menu_path FROM eb_system_menus WHERE unique_auth IN ('admin-report-sale','admin-report-other-reports') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        'upgrade_count' => (int)$pdo->query("SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key='20261014-employee-craftsman-default'")->fetchColumn(),
    ];
};
$before = $read();
if ($mode === 'migrate') {
    // DDL auto-commits in MySQL. Both migrations are repeatable; on failure stop
    // before code deployment and retain any added column, never drop user data.
    foreach ($files as $file => $sha) {
        $path = __DIR__ . '/payload/database/upgrades/' . $file;
        if (hash_file('sha256', $path) !== $sha) throw new RuntimeException('Migration fingerprint mismatch');
        $statement = $pdo->query(file_get_contents($path));
        do { if ($statement->columnCount()) $statement->fetchAll(PDO::FETCH_ASSOC); } while ($statement->nextRowset());
        $statement->closeCursor();
    }
}
$after = $read();
if ($mode !== 'preflight') {
    $columns = array_column($after['columns'], null, 'Field');
    if (count($columns) !== 2 || $columns['default_craftsman_performance_type']['Type'] !== 'varchar(32)'
        || $columns['craftsman_setting_version']['Default'] !== '1' || $after['upgrade_count'] !== 1) throw new RuntimeException('Migration verification failed');
    foreach ($after['menus'] as $row) {
        if (!(int)$row['is_del'] && !(int)$row['is_show']) throw new RuntimeException('Directory still hidden');
    }
}
echo json_encode(['instance' => $instance, 'mode' => $mode, 'before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

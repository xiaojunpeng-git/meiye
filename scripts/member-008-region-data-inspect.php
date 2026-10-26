<?php
declare(strict_types=1);

/** Read-only 008 address audit. Credentials and unrelated customer data never
 * leave this host; existing store fields remain authoritative and unmodified. */
$root = '/www/wwwroot/008.cc3798.com';
$env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$config = array_change_key_case($env['DATABASE'] ?? $env['database'] ?? []);
if (($config['database'] ?? '') !== 'mohe_008' || (int)($config['hostport'] ?? 0) !== 3307
    || !in_array($config['hostname'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Wrong instance');
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=3307;dbname=mohe_008;charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('START TRANSACTION READ ONLY');
$rows = $pdo->query('SELECT id,name,province,city,area,street,address,detailed_address,is_show,is_del FROM eb_system_store ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$dictionary = $pdo->query('SELECT id,name,parent_id,level FROM eb_city_area WHERE level <= 3 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$output = ['instance' => '008', 'database' => 'mohe_008', 'port' => 3307, 'mode' => 'READ ONLY',
    'stores' => $rows, 'dictionary' => $dictionary,
    'dictionaryCountAllLevels' => (int)$pdo->query('SELECT COUNT(*) FROM eb_city_area')->fetchColumn(),
    'legacyDictionaryCount' => (int)$pdo->query('SELECT COUNT(*) FROM eb_system_city')->fetchColumn(),
    'tableEngines' => $pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_system_store','eb_city_area') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC),
    'latestUpgrade' => $pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC),
    'backend' => []];
foreach (['app/controller/api/v1/store/Store.php', 'app/services/store/SystemStoreServices.php', 'app/services/store/StoreSelectableRegionServices.php'] as $file) $output['backend'][$file] = hash_file('sha256', $root . '/' . $file);
$pdo->exec('ROLLBACK');
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;

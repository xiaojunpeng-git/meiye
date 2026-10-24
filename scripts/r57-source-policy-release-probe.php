<?php
/** Read-only instance inspection. Never emit credentials or reservation/member data. */
if (PHP_SAPI !== 'cli') exit(1);
$site = $argv[1] ?? '';
$sites = ['rh' => ['rh.cc3798.com', 'ruihao', '3306'], '008' => ['008.cc3798.com', 'mohe_008', '3307'], '010' => ['010.cc3798.com', 'mohe_010_app', '3307']];
if (!isset($sites[$site])) throw new RuntimeException('Explicit instance required');
[$domain, $database, $port] = $sites[$site];
$root = '/www/wwwroot/' . $domain;
$ini = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$cfg = array_change_key_case($ini['DATABASE'] ?? $ini['database'] ?? []);
if ($cfg['database'] !== $database || (string)$cfg['hostport'] !== $port || $cfg['prefix'] !== 'eb_') throw new RuntimeException('Instance mismatch');
$output = ['site' => $site, 'database' => $database, 'static' => []];
foreach (['public/view_mobile_v3', 'public/view_admin', 'public/view_cashier_v3'] as $relative) {
    if (!is_dir($root . '/' . $relative)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $relative, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $output['static'][substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
    }
}
ksort($output['static']);
$pdo = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
try {
    $output['columns'] = $pdo->query("SELECT COLUMN_NAME,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_member_commerce_settings' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $output['settings'] = $pdo->query('SELECT * FROM eb_member_commerce_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
} finally { $pdo->rollBack(); }
$output['serverReadOnly'] = true;
echo gzencode(json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

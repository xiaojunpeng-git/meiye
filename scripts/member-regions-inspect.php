<?php
/** Read-only per-instance release snapshot; credentials stay on their own host. */
$s = json_decode(base64_decode(getenv('REGION_PROBE') ?: ''), true);
$allowed = [
    'rh' => ['rh.cc3798.com', 'ruihao', 3306],
    '008' => ['008.cc3798.com', 'mohe_008', 3307],
    '010' => ['010.cc3798.com', 'mohe_010_app', 3307],
];
if (!isset($allowed[$s['key'] ?? ''])) throw new RuntimeException('Explicit instance required');
[$domain, $database, $port] = $allowed[$s['key']];
$root = '/www/wwwroot/' . $domain;
$hash = static function ($f) {return is_file($f) ? hash_file('sha256', $f) : null;};
$env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$c = array_change_key_case($env['DATABASE'] ?? $env['database'] ?? []);
if (($c['database'] ?? '') !== $database || (int)($c['hostport'] ?? 0) !== $port
    || !in_array($c['hostname'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Instance mismatch');
$pdo = new PDO('mysql:host=' . $c['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('START TRANSACTION READ ONLY');
$out = ['instance' => $s['key'], 'database' => ['name' => $database, 'port' => $port, 'version' => $pdo->query('SELECT VERSION()')->fetchColumn()],
    'environment' => $hash($root . '/.env'), 'front' => [], 'files' => [], 'digests' => []];
foreach (['eb_diy', 'eb_system_config', 'eb_system_store'] as $table) {
    // Hash configuration and complete address records remotely, never export rows or secrets.
    if ($pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn()) {
        $out['digests'][$table] = hash('sha256', json_encode($pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE));
    }
}
$out['latestUpgrade'] = $pdo->query("SHOW TABLES LIKE 'eb_database_upgrade_log'")->fetchColumn()
    ? $pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) : null;
$entries = ['member' => $root . '/public/index.html', 'admin' => $root . '/public/system.html', 'cashier' => $root . '/public/view_cashier_v3/index.html'];
if ($s['key'] === 'rh') $entries['cashier_preview'] = $root . '-8080/public/view_cashier_v3/index.html';
foreach ($entries as $key => $f) {
    $assets = [];
    if (is_file($f)) {
        preg_match_all('~(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css)(?:\?[^"\x27\s>]*)?)~', file_get_contents($f), $matches);
        foreach (array_unique($matches[1]) as $ref) {
            if (preg_match('~^(?:https?:)?//~', $ref)) continue;
            $public = $key === 'cashier_preview' ? $root . '-8080/public' : $root . '/public';
            $url = explode('?', $ref)[0];
            $assets[$ref] = $hash($url[0] === '/' ? $public . $url : dirname($f) . '/' . $url);
        }
    }
    $out['front'][$key] = ['path' => $f, 'index' => $hash($f), 'assets' => $assets];
}
foreach ($s['files'] as $f) {
    if (strpos($f, '..') !== false || strpos($f, '/') === 0) throw new RuntimeException('Unsafe relative path');
    $out['files'][$f] = $hash($root . '/' . $f);
}
$pdo->exec('ROLLBACK');
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

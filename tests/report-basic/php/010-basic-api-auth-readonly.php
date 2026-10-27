<?php
declare(strict_types=1);

/** 仅核对 010 基础报表 API 的函数权限登记，防止菜单可见但中间件提前拦截查询。 */
$root = '/www/wwwroot/010.cc3798.com';
$ini = array_change_key_case(parse_ini_file($root . '/.env', true, INI_SCANNER_RAW) ?: [], CASE_LOWER);
$cfg = array_change_key_case($ini['database'] ?? [], CASE_LOWER);
if (($cfg['database'] ?? '') !== 'mohe_010_app' || (int)($cfg['hostport'] ?? 0) !== 3307) {
    throw new RuntimeException('010_INSTANCE_MISMATCH');
}
$db = new PDO('mysql:host=' . $cfg['hostname'] . ';port=3307;dbname=mohe_010_app;charset=utf8mb4',
    $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('SET SESSION TRANSACTION READ ONLY');
$rows = $db->query("SELECT id,unique_auth,api_url,methods FROM eb_system_menus
    WHERE is_del=0 AND type=2 AND api_url LIKE '%report/basic/%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['site' => '010', 'read_only' => true, 'registered_rules' => $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;

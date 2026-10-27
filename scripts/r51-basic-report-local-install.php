<?php
/** 仅安装本地开发所需审计表和新菜单，不导入007客户记录，不能用于010或任何远程实例。 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root . '/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
$config = config('database.connections.mysql');
// 本机隔离开发库沿用ruihao库名，必须同时有显式本地开关、容器标记和本地服务名。
if (getenv('R51_LOCAL_INSTALL') !== '1' || !is_file('/.dockerenv')
    || !in_array($config['hostname'], ['mohe-mysql', 'mysql'], true)
    || in_array($config['database'], ['mohe_010_app', 'mohe_008', 'linlin'], true)) {
    throw new RuntimeException('R51 installer is local-only; no customer import is allowed');
}
$directory = $root . '/database/upgrades/2026-10-23-基础报表/';
if ((string)Db::query('SELECT DATABASE() AS name')[0]['name'] !== (string)$config['database']) throw new RuntimeException('Local connection database mismatch');
$pdo = Db::connect()->getPdo();
$pdo->exec(file_get_contents($directory . '01-刷单登记审计.sql'));
$before = Db::name('system_menus')->where('unique_auth', 'not like', 'admin-report-basic%')->order('id')->select()->toArray();
Db::transaction(function () use ($pdo, $directory) {
    $pdo->exec(file_get_contents($directory . '02-平台菜单.sql'));
    (new \app\services\organization\StorePermissionCatalogServices())->initializeBasicReportNavigation();
});
$after = Db::name('system_menus')->where('unique_auth', 'not like', 'admin-report-basic%')->order('id')->select()->toArray();
if ($before !== $after) throw new RuntimeException('Existing menus unexpectedly changed');
echo json_encode(['local_only' => true, 'historical_business_rows_imported' => 0,
    'existing_menus_unchanged' => true, 'new_menu_count' => Db::name('system_menus')->whereLike('unique_auth', 'admin-report-basic%')->count(),
    'migration' => 'audit_table_and_new_menus_only']), PHP_EOL;

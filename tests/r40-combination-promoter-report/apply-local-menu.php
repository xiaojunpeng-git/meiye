<?php
declare(strict_types=1);

/** 仅本地开发库注册报表菜单以供真人页面测试；正式实例须单独授权迁移。 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only local mysql/ruihao is allowed');
}
$sql = file_get_contents('/var/www/html/database/upgrades/20261104_r40_combination_promoter_report.sql');
think\facade\Db::execute($sql);
echo 'local menu count ' . think\facade\Db::name('system_menus')->where('unique_auth', 'marketing-store_combination-promoter_report')->where('is_del', 0)->count() . "\n";

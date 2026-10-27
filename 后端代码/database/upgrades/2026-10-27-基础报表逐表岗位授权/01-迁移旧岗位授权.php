<?php
declare(strict_types=1);

/** 逐实例显式迁移已有门店岗位的基础报表授权；先备份目录、岗位及审计，再使用 --apply。 */
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required after instance backup and authorization');
}
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();
$changed = (new \app\services\organization\StorePermissionCatalogServices())->migrateBasicReportLeafGrants();
echo "PASS basic report leaf grants migrated; changed roles={$changed}\n";

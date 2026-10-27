<?php
declare(strict_types=1);

/** 逐实例显式安装门店端基础报表能力；不修改已有岗位规则或门店范围。 */
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required after instance backup and authorization');
}
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();
(new \app\services\organization\StorePermissionCatalogServices())->initializeBasicReportPermission();
echo "PASS basic report permission installed; position grants unchanged\n";

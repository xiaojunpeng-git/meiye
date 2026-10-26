<?php
declare(strict_types=1);

// Explicit per-instance metadata install; never grants this financial action
// to existing positions and never changes historical orders/card balances.
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required after instance backup and authorization');
}
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();
(new \app\services\organization\StorePermissionCatalogServices())->initializeCardToBalancePermission();
echo "PASS card_to_balance metadata installed; position grants unchanged\n";

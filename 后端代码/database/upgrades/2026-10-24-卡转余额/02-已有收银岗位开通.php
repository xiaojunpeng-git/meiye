<?php
declare(strict_types=1);

// Separate PM-approved grant migration: back up this instance's channel rules
// first. Only existing active store-V3 cashier positions inherit this new leaf.
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required after instance backup and authorization');
}
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/'); $app->env->load($root . '/.env'); $app->initialize();
$catalog = new \app\services\organization\StorePermissionCatalogServices();
$catalog->initializeCardToBalancePermission();
$count = $catalog->grantCardToBalanceToExistingCashierPositions();
echo "PASS existing cashier positions granted: {$count}; other channels and non-cashier positions untouched\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$servicePath = $root . '/后端代码/app/services/cashier/v3/config/CashierV3BusinessConfigServices.php';
$adminControllerPath = $root . '/后端代码/app/controller/admin/v1/product/CashierV3BusinessConfig.php';
$cashierControllerPath = $root . '/后端代码/app/controller/cashier/v3/BusinessConfig.php';
$adminRoutePath = $root . '/后端代码/route/admin.php';
$cashierRoutePath = $root . '/后端代码/route/cashier-v3.php';
$migrationPath = $root . '/后端代码/database/upgrades/2026-08-06-收银V3来源与记账配置/02-正式升级.sql';

$files = [
    $servicePath, $adminControllerPath, $cashierControllerPath,
    $adminRoutePath, $cashierRoutePath, $migrationPath,
];
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FAIL missing file: {$file}\n");
        exit(1);
    }
}

$service = file_get_contents($servicePath);
$adminRoutes = file_get_contents($adminRoutePath);
$cashierRoutes = file_get_contents($cashierRoutePath);
$migration = file_get_contents($migrationPath);

$canonical = [
    'unionpay', 'wechat', 'alipay', 'dianping_voucher',
    'douyin_voucher', 'partner_collection', 'other_collection',
];
foreach ($canonical as $code) {
    assertContains("'{$code}'", $service, "service canonical code {$code}");
    assertContains("('{$code}'", $migration, "migration canonical code {$code}");
}

assertContains('resolveSourceSnapshot', $service, 'checkout source snapshot resolver');
assertContains('resolveAccountingMethodSnapshot', $service, 'checkout payment snapshot resolver');
assertContains('expectedVersion', $service, 'optimistic resource version');
assertContains('idempotencyKey', $service, 'idempotency contract');
assertContains("(int)\$parent['parent_id'] !== 0", $service, 'maximum two source levels');
assertContains('不能停用最后一个有效二级来源', $service, 'required-secondary invariant');
assertContains('至少保留一种启用的记账收款方式', $service, 'active accounting invariant');
assertContains('系统记账代码不可修改', $service, 'canonical accounting code is immutable');
assertContains('已建立的来源不能变更层级或上级', $service, 'source hierarchy identity is immutable');
assertContains('uk_idempotency_key', $migration, 'database idempotency uniqueness');
assertContains('before_snapshot_json', $migration, 'immutable before audit');
assertContains('after_snapshot_json', $migration, 'immutable after audit');
assertNotContains('DELETE FROM `eb_cash_source`', $migration, 'legacy sources are never deleted');
assertNotContains('UPDATE `eb_cash_source`', $migration, 'legacy sources are never rewritten');

$adminApiContracts = [
    "Route::get('business-config/sources'",
    "Route::post('business-config/sources'",
    "Route::put('business-config/sources/:id'",
    "Route::get('business-config/accounting-methods'",
    "Route::put('business-config/accounting-methods/:code'",
    "Route::post('business-config/accounting-methods/restore-defaults'",
];
foreach ($adminApiContracts as $route) {
    assertContains($route, $adminRoutes, "admin route {$route}");
}
assertContains("Route::get('business-config/checkout-catalog'", $cashierRoutes, 'cashier read-only config route');
assertNotContains("Route::delete('business-config/sources", $adminRoutes, 'sources have no physical delete endpoint');

echo "PASS cashier v3 business source/accounting config contract\n";

function assertContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL {$label}: missing {$needle}\n");
        exit(1);
    }
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL {$label}: unexpected {$needle}\n");
        exit(1);
    }
}

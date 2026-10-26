<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$projectionFile = $root . '/后端代码/app/services/product/inventory/InventoryExpiryRiskProjectionServices.php';
$storeFile = $root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php';
$platformFile = $root . '/后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php';
require_once $projectionFile;

use app\services\product\inventory\InventoryExpiryRiskProjectionServices;

$failed = 0;
function expiryRiskAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

$rows = [
    ['expire_date' => '2026-08-04'],
    ['expire_date' => '2026-08-05'],
    ['expire_date' => '2026-09-04'],
    ['expire_date' => '2026-09-05'],
    ['expire_date' => '2026-10-04'],
    ['expire_date' => '2026-10-05'],
    ['expire_date' => '2026-11-03'],
    ['expire_date' => '2026-11-04'],
    ['expire_date' => ''],
    ['expire_date' => '2026-02-30'],
];
$result = (new InventoryExpiryRiskProjectionServices())->project($rows, '2026-08-05');
$buckets = [];
foreach ($result['expiry_risk_buckets'] as $bucket) $buckets[$bucket['code']] = $bucket;

expiryRiskAssert('expiry projection exposes every stable bucket in display order',
    array_keys($buckets) === ['OVERDUE', 'WITHIN_30', 'DAYS_31_60', 'DAYS_61_90', 'SAFE_OVER_90', 'UNKNOWN']);
expiryRiskAssert('expiry projection assigns exact 0, 30, 31, 60, 61, 90 and 91 day boundaries',
    $buckets['OVERDUE']['count'] === 1
    && $buckets['WITHIN_30']['count'] === 2
    && $buckets['DAYS_31_60']['count'] === 2
    && $buckets['DAYS_61_90']['count'] === 2
    && $buckets['SAFE_OVER_90']['count'] === 1);
expiryRiskAssert('missing and malformed expiry dates stay visible in the unknown bucket',
    $buckets['UNKNOWN']['count'] === 2 && $buckets['UNKNOWN']['label'] === '未设置到期日');
expiryRiskAssert('legacy expiring count is exactly the three future zero-to-ninety-day buckets',
    $result['expiring_90_batch_count'] === 6
    && $result['expiring_90_batch_count'] === $buckets['WITHIN_30']['count'] + $buckets['DAYS_31_60']['count'] + $buckets['DAYS_61_90']['count']);

$invalidCutoffRejected = false;
try {
    (new InventoryExpiryRiskProjectionServices())->project([], '2026-02-30');
} catch (InvalidArgumentException $exception) {
    $invalidCutoffRejected = $exception->getMessage() === 'inventory_expiry_cutoff_date_invalid';
}
expiryRiskAssert('invalid projection cutoff fails closed instead of silently moving a reporting date', $invalidCutoffRejected);

$store = (string)file_get_contents($storeFile);
$platform = (string)file_get_contents($platformFile);
expiryRiskAssert('store and headquarters dashboards reuse one settled-batch expiry projection',
    strpos($store, '(new InventoryExpiryRiskProjectionServices())->project($rows, $cutoffDate)') !== false
    && strpos($platform, '(new InventoryExpiryRiskProjectionServices())->project($rows, $cutoffDate)') !== false
    && strpos($store, "'queryCutoffDate' => \$cutoffDate ?: date('Y-m-d')") !== false
    && strpos($platform, "'queryCutoffDate' => \$cutoffDate") !== false
    && strpos($store, "'expiry_risk_buckets' => \$expiryRisk['expiry_risk_buckets']") !== false
    && strpos($platform, "'expiry_risk_buckets' => \$expiryRisk['expiry_risk_buckets']") !== false);

echo "INVENTORY_EXPIRY_RISK_PROJECTION_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

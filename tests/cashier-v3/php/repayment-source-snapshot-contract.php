<?php
declare(strict_types=1);

// Local contract only: exercise the source projection without opening a database connection.
$root = dirname(__DIR__, 3);
$salesPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php';
$rechargePath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php';
$migrationPath = $root . '/后端代码/database/upgrades/2026-10-03-收银V3补交来源快照/02-正式升级.sql';
require_once $salesPath;
require_once $rechargePath;

function contractAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$source = [
    'primarySourceId' => 11, 'primarySourceNameSnapshot' => 'A老客首护',
    'secondarySourceId' => 23, 'secondarySourceNameSnapshot' => '门店回访',
    'displayNameSnapshot' => 'A老客首护 / 门店回访',
];
$expected = [
    'business_source_primary_id' => 11,
    'business_source_primary_name_snapshot' => 'A老客首护',
    'business_source_secondary_id' => 23,
    'business_source_secondary_name_snapshot' => '门店回访',
    'business_source_label_snapshot' => 'A老客首护 / 门店回访',
];
foreach ([
    app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices::class,
    app\services\cashier\v3\settlement\CashierV3RechargeDebtRepaymentServices::class,
] as $class) {
    $method = new ReflectionMethod($class, 'sourceSnapshotColumns');
    if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    contractAssert($method->invoke(null, $source) === $expected, $class . ': selected source mismatch');
    contractAssert($method->invoke(null, []) === [
        'business_source_primary_id' => 0,
        'business_source_primary_name_snapshot' => '',
        'business_source_secondary_id' => 0,
        'business_source_secondary_name_snapshot' => '',
        'business_source_label_snapshot' => '',
    ], $class . ': empty historical source mismatch');
}

$salesCode = (string)file_get_contents($salesPath);
$rechargeCode = (string)file_get_contents($rechargePath);
$migration = (string)file_get_contents($migrationPath);
contractAssert(strpos($salesCode, "'businessSource' => is_array(\$snapshot['source']") !== false, 'recharge adapter must pass the frozen source');
contractAssert(substr_count($salesCode, 'foreach ($sourceColumns as $column => $value)') === 2, 'both adapter replay paths must compare source');
contractAssert(strpos($salesCode, "'businessSourcePrimaryId'=>(int)(\$businessSource['primarySourceId']") !== false, 'sales repayment facts must use the same source');
contractAssert(strpos($rechargeCode, "'businessSourcePrimaryId' => \$source['business_source_primary_id']") !== false, 'recharge repayment facts must use the stored source');
contractAssert(strpos($rechargeCode, "'businessSource'=>self::sourceSnapshotColumns(\$source)") !== false, 'recharge domain input must retain source for fingerprinting');
contractAssert(substr_count($migration, 'ADD COLUMN `business_source_') === 10, 'migration must add five source fields on each repayment table');

echo "repayment source snapshot contract: OK\n";

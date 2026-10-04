<?php
declare(strict_types=1);

// Local contract only: exercise the source projection without opening a database connection.
$root = dirname(__DIR__, 3);
$salesPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php';
$rechargePath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php';
$migrationPath = $root . '/后端代码/database/upgrades/2026-10-03-收银V3补交来源快照/02-正式升级.sql';
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3CommandException.php';
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

// 原单来源只作为默认值；历史停用来源保留快照，重选时按有效目录重新解析。
$originalSource = new ReflectionMethod(app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices::class, 'sourceFromOriginalSalesOrder');
if (PHP_VERSION_ID < 80100) $originalSource->setAccessible(true);
$order = [
    'business_source_primary_id' => 11,
    'business_source_primary_name_snapshot' => 'A老客首护',
    'business_source_secondary_id' => 23,
    'business_source_secondary_name_snapshot' => '门店回访',
    'business_source_label_snapshot' => 'A老客首护 / 门店回访',
];
contractAssert($originalSource->invoke(null, $order) === $source, 'sales repayment must inherit exact original order source');
// 历史原单无来源时不伪装成当前工具栏来源，操作员必须在弹窗选择。
contractAssert($originalSource->invoke(null, []) === [], 'missing original order source must remain blank');

$salesCode = (string)file_get_contents($salesPath);
$rechargeCode = (string)file_get_contents($rechargePath);
$migration = (string)file_get_contents($migrationPath);
contractAssert(strpos($salesCode, "'businessSource' => is_array(\$snapshot['source']") !== false, 'recharge adapter must pass the frozen source');
contractAssert(substr_count($salesCode, 'foreach ($sourceColumns as $column => $value)') === 2, 'both adapter replay paths must compare source');
contractAssert(strpos($salesCode, "'businessSourcePrimaryId'=>(int)(\$businessSource['primarySourceId']") !== false, 'sales repayment facts must use the same source');
contractAssert(strpos($salesCode, '$originalSource = self::sourceFromOriginalSalesOrder((array)$locked[\'order\'])') !== false, 'sales repayment must read original default source');
contractAssert(strpos($salesCode, "throw self::failure('debt_repayment_business_source_required')") !== false, 'sales repayment must require a selected source');
contractAssert(strpos($salesCode, 'resolveSourceSnapshot(') !== false, 'sales repayment must resolve selected source in transaction');
contractAssert(strpos($rechargeCode, "'businessSourcePrimaryId' => \$source['business_source_primary_id']") !== false, 'recharge repayment facts must use the stored source');
contractAssert(strpos($rechargeCode, "'businessSource'=>self::sourceSnapshotColumns(\$source)") !== false, 'recharge domain input must retain source for fingerprinting');
contractAssert(strpos($rechargeCode, "throw self::failure('recharge_repayment_business_source_required')") !== false, 'recharge repayment must require a selected source');
contractAssert(strpos($rechargeCode, 'resolveSourceSnapshot(') !== false, 'recharge repayment must resolve selected source in transaction');
contractAssert(strpos($rechargeCode, "'business_source_primary_id' => (int)(\$recharge['business_source_primary_id']") !== false, 'recharge repayment must read the locked original recharge source');
contractAssert(substr_count($migration, 'ADD COLUMN `business_source_') === 10, 'migration must add five source fields on each repayment table');

echo "repayment source snapshot contract: OK\n";

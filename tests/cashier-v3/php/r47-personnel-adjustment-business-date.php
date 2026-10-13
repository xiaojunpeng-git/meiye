<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3CommandException.php';
require_once $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;

$source = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php'
);
$migration = (string)file_get_contents(
    $root . '/后端代码/database/upgrades/2026-10-13-人员调整事实业务日期修复/01-执行.php'
);
$reflection = new ReflectionClass(CashierV3OrderLifecycleServices::class);
$service = $reflection->newInstanceWithoutConstructor();
$businessDate = $reflection->getMethod('personnelAdjustmentBusinessDate');

$invalidDateRejected = false;
try {
    $businessDate->invoke($service, ['businessDate' => '2026-02-30']);
} catch (CashierV3CommandException $error) {
    $invalidDateRejected = true;
}

$checks = [
    // A historical correction belongs to the original order date even though
    // its operation/event timestamps continue to record when it was edited.
    'original_order_date_is_accepted' => $businessDate->invoke($service, [
        'businessDate' => '2026-10-01',
    ]) === '2026-10-01',
    'invalid_order_date_is_rejected' => $invalidDateRejected,
    'sales_order_source_exposes_business_date' => strpos(
        $source,
        "'businessDate' => (string)\$row['business_date']"
    ) !== false,
    'adjusted_performance_uses_original_business_date' => strpos(
        $source,
        "\$row['business_date'] = \$businessDate; \$row['occurred_at'] = \$now;"
    ) !== false,
    'attribution_adjustment_uses_original_business_date' => strpos(
        $source,
        "'business_date' => \$businessDate, 'operator_id' => \$operator->operatorId()"
    ) !== false,
    'personnel_reversal_can_share_original_business_date' => strpos(
        $source,
        "\$row['business_date'] = \$businessDate !== '' ? \$businessDate : date('Y-m-d', \$now);"
    ) !== false,
    // Historical correction must remain read-only by default, preserve immutable
    // performance facts through signed reversal, and repair both attribution tables.
    'migration_defaults_to_dry_run' => strpos($migration, "\$mode = \$argv[1] ?? '--dry-run';") !== false,
    'migration_uses_append_only_performance_repair' => strpos(
        $migration,
        "\$reversal['reversal_of'] = \$oldFactId;"
    ) !== false && strpos($migration, "\$forward['business_date'] = (string)\$source['source_business_date'];") !== false,
    'migration_covers_guide_and_sales_manager' => strpos($migration, 'R47_TABLE_GUIDE') !== false
        && strpos($migration, 'R47_TABLE_MANAGER') !== false,
    'migration_verifies_database_identity' => substr_count(
        $migration,
        'database identity mismatch'
    ) === 2,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed = true;
}

if ($failed) exit(1);
echo "R47_PERSONNEL_ADJUSTMENT_BUSINESS_DATE=PASS\n";

<?php

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/CustomerLifecycleFactServices.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-10-顾客生命周期与首次疗程归因/02-正式升级.sql');
$backfill = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-28-客户来源生命周期事实回填/01-正式升级.sql');
$repository = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/fact/ThinkPhpCashierV3CheckoutFactRepository.php');
$completion = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php');
$debt = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php');
$directGift = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3DirectGiftIssuanceServices.php');
$report = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');

$checks = [
    'immutable lifecycle fact has tenant natural-key idempotency' => strpos($migration, 'UNIQUE KEY `uk_tenant_natural`') !== false,
    'projection has one row per tenant member' => strpos($migration, 'UNIQUE KEY `uk_tenant_member`') !== false,
    'checkout records settled and debt-pending course paths' => strpos($service, "'first_course_completed'") !== false && strpos($service, "'course_pending_settlement'") !== false,
    'cashier snapshot and workspace settlements are accepted by lifecycle checkout writer' => strpos($service, "'cashier_snapshot', 'cashier_workspace'") !== false,
    'historical backfill is limited to immutable V3 card sale facts and rerunnable' => strpos($backfill, "source_type = 'card'") !== false
        && strpos($backfill, 'INSERT IGNORE INTO eb_cashier_v3_customer_lifecycle_fact') !== false
        && strpos($backfill, 'Legacy eb_store_order') !== false,
    'upgrade card is excluded from first-course attribution using immutable settlement authority' => strpos($service, "Db::name('cashier_v3_card_operation_settlement')") !== false
        && strpos($service, "'card_upgrade', 'project_upgrade'") !== false
        && strpos($service, 'customer_lifecycle_upgrade_settlement_invalid') !== false,
    'independent gifts write only gift authority and cannot invoke lifecycle attribution' => strpos($directGift, "private const FACT_TABLE = 'cashier_v3_gift_fact'") !== false
        && strpos($directGift, "'event_type' => 'gift.issued'") !== false
        && strpos($directGift, 'CustomerLifecycleFactServices') === false,
    'care completion drives lifecycle visits' => strpos($completion, 'recordServiceCompletionInTx') !== false && strpos($service, "'service_completed'") !== false,
    'debt settlement promotes original frozen source' => strpos($debt, 'recordDebtCompletionInTx') !== false && strpos($service, "'settled_from_debt'") !== false,
    'guest requires tenant-scoped post-sale referrer' => strpos($service, "where('tenant_id', \$tenantId)->where('member_id', \$referrer)->where('lifecycle_stage', 'post_sale')") !== false,
    'guide wins source-type conflict without removing salesperson facts' => strpos($service, "=== 'guide'") !== false && strpos($repository, 'CustomerLifecycleFactServices') !== false,
    'year zero falls back to the selected report date year' => strpos($report, '$requested > 0 ? $requested') !== false,
    'natural-year tiers remain inside the V3 reporting coverage period' => strpos($report, "max(\$year . '-01-01', self::COVERAGE_START)") !== false
        && strpos($report, "\$annualStart, \$year . '-12-31'") !== false,
    'converted customers retain a pending-conversion history label' => strpos($report, "'conversion_history'") !== false && strpos($report, "'has_pending_conversion'") !== false,
];
$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);

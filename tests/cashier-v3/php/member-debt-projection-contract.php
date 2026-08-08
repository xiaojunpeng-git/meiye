<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$reader = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3MemberDebtProjectionServices.php';
$summary = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierMemberSummaryServices.php';
$module = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php';
$memberModule = $root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php';
$frontend = $root . '/前端代码/cashier-v3/src/layouts/CashierShell.vue';
foreach ([$reader, $summary, $module, $memberModule, $frontend] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing: {$file}\n");
        exit(1);
    }
}
$readerSource = file_get_contents($reader);
$summarySource = file_get_contents($summary);
$moduleSource = file_get_contents($module);
$memberModuleSource = file_get_contents($memberModule);
$frontendSource = file_get_contents($frontend);
foreach (['cashier_v3_recharge_debt_authority', 'cashier_v3_debt_authority', 'cashier_v3_sales_order', 'store_debt', 'store_order', "'充值欠款'", 'outstandingDebtAmount', 'sales_order_no_snapshot'] as $needle) {
    if (strpos($readerSource, $needle) === false) {
        fwrite(STDERR, "missing reader contract: {$needle}\n");
        exit(1);
    }
}
if (strpos($summarySource, 'CashierV3MemberDebtProjectionServices') === false
    || strpos($summarySource, 'amountForMember($memberId, $storeId)') === false
    || strpos($readerSource, 'amountForMember(int $memberId, int $storeId)') === false
    || strpos($readerSource, "->where('d.store_id', \$storeId)") === false
    || strpos($readerSource, "return 'v3_sale';") === false
    || strpos($readerSource, "return 'legacy_sale';") === false
    || strpos($readerSource, "return 'recharge';") === false
    || strpos($moduleSource, "registerProjection('open-member-debt-repayment'") === false
    || strpos($moduleSource, "'kind' => 'member'") === false
    || strpos($moduleSource, 'synchronizeProjectionVersion') === false
    || strpos($moduleSource, "'kind' => 'debt_record'") === false
    || strpos($moduleSource, "'recordVersion'") === false
    || strpos($frontendSource, 'result?.data?.result') === false
    || strpos($frontendSource, 'envelope?.data?.debtSnapshot') === false
    || strpos($frontendSource, 'rechargeSession.value = null') === false
    || strpos($frontendSource, "selectorEntry: 'cashier',\n      silent: true") === false) {
    fwrite(STDERR, "member debt projection wiring missing\n");
    exit(1);
}
foreach (['CashierV3MemberDebtProjectionServices', "(string)(\$payload['tab'] ?? '') === 'debt'", "'debtRecords'", "'open-debt-settlements'"] as $needle) {
    if (strpos($memberModuleSource, $needle) === false) {
        fwrite(STDERR, "member detail debt tab wiring missing: {$needle}\n");
        exit(1);
    }
}
if (strpos($frontendSource, "scope === 'debt-record' && action === 'open-debt-settlements'") === false
    || strpos($frontendSource, 'return openMemberDebt(currentMemberId)') === false) {
    fwrite(STDERR, "member detail debt repayment action wiring missing\n");
    exit(1);
}
echo "MEMBER_DEBT_PROJECTION_CONTRACT_OK\n";

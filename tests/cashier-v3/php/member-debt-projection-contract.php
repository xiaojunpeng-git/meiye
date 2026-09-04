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
    || strpos($summarySource, 'amountForMember($memberId)') === false
    || strpos($readerSource, 'amountForMember(int $memberId)') === false
    || strpos($readerSource, "->where('d.store_id', \$storeId)") !== false
    || strpos($readerSource, "'recharge_member_id'") === false
    || strpos($readerSource, "return 'v3_sale';") === false
    || strpos($readerSource, "return 'legacy_sale';") === false
    || strpos($readerSource, "return 'recharge';") === false
    || strpos($moduleSource, "registerProjection('open-member-debt-repayment'") === false
    || strpos($frontendSource, 'result?.data?.result') === false
    || strpos($frontendSource, 'envelope?.data?.debtSnapshot') === false
    || strpos($frontendSource, 'rechargeSession.value = null') === false
    || strpos($frontendSource, 'function applyAuthoritativeDebtSnapshot(snapshot)') === false
    || strpos($frontendSource, 'applyAuthoritativeDebtSnapshot(snapshot)') === false
    || strpos($frontendSource, "selectorEntry: 'cashier',\n      silent: true") === false) {
    fwrite(STDERR, "member debt projection wiring missing\n");
    exit(1);
}
$projectionStart = strpos($moduleSource, "registerProjection('open-member-debt-repayment'");
$projectionEnd = strpos($moduleSource, "if (\$handlers->hasCommand('prepare-debt-repayment')", $projectionStart);
$debtProjection = $projectionStart === false || $projectionEnd === false
    ? ''
    : substr($moduleSource, $projectionStart, $projectionEnd - $projectionStart);
if ($debtProjection === ''
    || strpos($debtProjection, '$memberDebtProjection->read(') === false
    || strpos($debtProjection, "'data' => ['debtSnapshot' => \$snapshot]") === false
    || strpos($debtProjection, 'synchronizeProjectionVersion') !== false
    || strpos($debtProjection, "'versions' =>") !== false
    || strpos($debtProjection, 'CashierV3CheckoutWorkspaceIdentity::id') !== false) {
    fwrite(STDERR, "member debt projection must read the latest debt snapshot without version synchronization\n");
    exit(1);
}
if (strpos($memberModuleSource, 'CashierV3CashierMemberSummaryServices') === false
    || strpos($memberModuleSource, '$cashierMemberSummaries->read(') === false
    || strpos($memberModuleSource, '$member = array_merge($member,') === false) {
    fwrite(STDERR, "cashier member selection does not return the authoritative financial summary\n");
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

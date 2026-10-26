<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php');

// Historical card value closes the sale equation on full void only; it must
// never become a cash refund or a reversed payment fact.
$checks = [
    'full_void_only' => strpos($source, "\$action === 'void-sales-order'\n            ? \$this->lockedNonCashSettlement") !== false,
    'committed_draft_is_tenant_and_request_scoped' => strpos($source, "->where('tenant_id', \$scope->tenantId())\n            ->where('request_id', (string)\$source['checkoutRequestId'])\n            ->where('draft_status', 'committed')->lock(true)") !== false,
    'old_card_cannot_mix_with_cash_or_balance' => strpos($source, 'count($drafts) !== 1 || count($legacy) !== 1') !== false
        && strpos($source, '$cashCollected !== 0 || $balancePaid !== 0') !== false,
    'historical_value_only_extends_economic_reversal' => strpos($source, '+ $entitlementCreditCents + $nonCashSettlement + $couponTender;') !== false
        && strpos($source, "'cashRefundCents' => \$cashRefund, 'cashReversalCents' => \$cashReversal") !== false,
    'historical_other_collection_remains_cash' => strpos($source, "!isset(\$collectedDraftIds[(string)\$draft['payment_draft_id']])") !== false,
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo $failed === 0 ? "OLD_CARD_VOID_CONTRACT=PASS\n" : "OLD_CARD_VOID_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);

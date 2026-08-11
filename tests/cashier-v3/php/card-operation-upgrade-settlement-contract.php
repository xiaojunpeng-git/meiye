<?php

$root = dirname(__DIR__, 3);
$files = [
    'settlement' => $root . '/后端代码/app/services/cashier/v3/card/CashierV3CardOperationCheckoutSettlementServices.php',
    'salesPlan' => $root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php',
    'submission' => $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php',
    'paymentPlan' => $root . '/后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php',
    'paymentWriter' => $root . '/后端代码/app/services/cashier/v3/settlement/payment/ThinkPhpCashierV3PaymentCollectionAuthorityWriter.php',
    'workspace' => $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php',
    'entitlementProjection' => $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php',
    'resourceDiscovery' => $root . '/后端代码/app/services/cashier/v3/card/CashierV3CardOperationResourceDiscovery.php',
    'catalog' => $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php',
    'issuance' => $root . '/后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php',
    'manifest' => $root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php',
    'facts' => $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php',
    'migration' => $root . '/后端代码/database/upgrades/2026-08-11-收银V3卡操作权益余额结算/02-正式升级.sql',
];
$failed = 0;
function upgradeContract(bool $ok, string $name): void {
    global $failed;
    if (!$ok) { $failed++; fwrite(STDERR, "FAIL: {$name}\n"); return; }
    echo "PASS: {$name}\n";
}
$source = [];
foreach ($files as $key => $file) {
    $source[$key] = is_file($file) ? (string)file_get_contents($file) : '';
}
upgradeContract(strpos($source['salesPlan'], "request['sales_amount_cents'] + \$credit['amountCents']") !== false,
    'formal sale amount includes immutable entitlement credit');
upgradeContract(strpos($source['paymentPlan'], 'public function entitlementCreditCents(): int') !== false
    && strpos($source['paymentWriter'], '$plan->entitlementCreditCents()') !== false
    && strpos($source['paymentWriter'], '+ $entitlementCreditCents') !== false,
    'payment writer reconciles target price with cash plus entitlement credit');
upgradeContract(strpos($source['submission'], 'creditForCheckoutInTx') !== false
    && strpos($source['submission'], "['operationType'] ?? '') === 'project_upgrade'") !== false,
    'checkout resolves credit server-side and project upgrade does not complete service');
upgradeContract(strpos($source['settlement'], "'card_status' => 'upgraded'") !== false,
    'source card reaches dedicated upgraded terminal state');
upgradeContract(strpos($source['settlement'], "\$operationUpdate['card_status_after'] = 'upgraded'") !== false,
    'settled operation audit snapshot records the upgraded source-card state');
upgradeContract(strpos($source['entitlementProjection'], "if ((string)\$state['card_status'] === 'upgraded')") !== false
    && strpos($source['entitlementProjection'], "// 原订单已被标记为升级来源") !== false,
    'upgraded source card is excluded before entitlement resource version synchronization');
upgradeContract(strpos($source['resourceDiscovery'], "\$sourceProjectAccessMode = \$type === CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT") !== false
    && strpos($source['resourceDiscovery'], "'accessMode' => \$sourceProjectAccessMode") !== false,
    'project upgrade reads the source project until normal checkout settles it, while replacement mutates immediately');
upgradeContract(strpos($source['settlement'], "(int)(\$oldOrder['uid'] ?? 0) !== (int)\$operation['origin_member_id']") !== false
    && strpos($source['settlement'], "(int)(\$oldOrder['uid'] ?? 0) !== (int)\$operation['member_id_before']") === false,
    'a transferred card upgrade validates the immutable original-order owner, not the current holder');
upgradeContract(strpos($source['settlement'], "'is_writeoff' => 0") !== false
    && strpos($source['settlement'], "'oid' => (int)\$operation['origin_order_id']") !== false
    && strpos($source['settlement'], "->update(['cart_id' => self::json([(string)\$targetDetailId])])") !== false,
    'project upgrade keeps the new right on its source card while preserving the new sales-order projection');
upgradeContract(strpos($source['settlement'], 'cashier_v3_card_operation_settlement') !== false
    && strpos($source['migration'], '`entitlement_credit_cents`') !== false,
    'reversal-ready settlement authority is persisted');
upgradeContract(strpos($source['workspace'], "\$line['cardOperationUpgrade'] = \$cardOperationUpgrade") !== false,
    'workspace exposes only the controlled upgrade binding');
upgradeContract(strpos($source['workspace'], '&& (!$hasSalespeople || $hasServiceObject || $hasCraftsmen || $hasExperience)') !== false
    && strpos($source['workspace'], 'applySalespeopleToAllSaleLinesInTx') !== false,
    'upgrade line allows salesperson assignment while keeping other line edits locked');
upgradeContract(strpos($source['catalog'], "'sourceCardName'") !== false
    && strpos($source['catalog'], "'sourceCardNo'") !== false,
    'upgrade binding carries immutable source card display snapshots');
upgradeContract(strpos($source['settlement'], "'event_type' => 'card.operation.settled'") !== false
    && strpos($source['manifest'], "'card.operation.settled',") !== false
    && strpos($source['manifest'], "'aggregate_type' => 'card_operation'") !== false
    && strpos($source['manifest'], "'card.operation.settled' => []") !== false,
    'upgrade settlement event is registered in the normal checkout event contract');
upgradeContract(strpos($source['settlement'], "['editing', 'ready_for_submit']") !== false
    && strpos($source['settlement'], 'if ($previous && !in_array') !== false
    && strpos($source['settlement'], "->where('checkout_request_id', \$existingRequestId)") !== false,
    'a failed unsubmitted checkout binding can move to a fresh checkout request');
upgradeContract(strpos($source['facts'], '$plannedById = [];') !== false
    && strpos($source['facts'], "\$plan->lines()") !== false
    && strpos($source['facts'], "\$planned['sale_amount_cents']") !== false,
    'facts verify the formal upgrade sale line instead of the pre-credit draft line');
upgradeContract(strpos($source['facts'], '$entitlementCredit = $sale - (int)$request[\'sales_amount_cents\'];') !== false
    && strpos($source['facts'], '$collected + $balance + $debt + $entitlementCredit !== $sale') !== false,
    'facts balance formal upgrade sales with the separate old-entitlement credit');
upgradeContract(strpos($source['workspace'], 'synchronizeUpgradeCouponSnapshot') === false
    && strpos($source['workspace'], '=== $unitPriceCents + $couponDiscountCents') !== false
    && strpos($source['catalog'], '!== $delta - $couponDiscount') !== false
    && strpos($source['settlement'], '!== (int)$operation[\'settlement_delta_cents\'] - $couponDiscount') !== false,
    'an upgrade coupon lowers only the sale receivable and never rewrites the immutable old-right credit');
upgradeContract(strpos($source['issuance'], '$couponDiscount = (int)($salesLine[\'coupon_discount_cents\'] ?? 0)') !== false
    && strpos($source['issuance'], "!== (int)(\$operation['target_price_cents'] ?? -2) - \$couponDiscount") !== false,
    'card issuance validates the target sale line after an upgrade coupon');
exit($failed === 0 ? 0 : 1);

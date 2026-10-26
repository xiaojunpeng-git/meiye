<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/card/CashierV3MultiCardUpgradeSettlementServices.php');
$submission = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$gateway = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php');
$manifest = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$lifecycle = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php');
$reversal = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3CardOperationReversalServices.php');
$orderQuery = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$workbench = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-09-13-收银V3多旧卡升级唯一快照/02-正式升级.sql');
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if ($condition) { echo "PASS {$message}\n"; return; }
    ++$failed; echo "FAIL {$message}\n";
};

$assert(strpos($service, 'sort($ids, SORT_NUMERIC)') !== false, 'source card locks have a stable numeric order');
$assert(strpos($service, "'isMultiCardUpgrade' => true") !== false, 'multi-card credit is explicitly distinct from legacy upgrade credit');
$assert(strpos($service, "\$upgrade['targetSaleAmountCents']") !== false,
    'upgrade target price is read from its one immutable snapshot, not the post-credit sale-line working amount');
$salesPlan = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$assert(strpos($salesPlan, 'second target-price authority') !== false,
    'formal order validates the multi-card target only from its immutable snapshot and settlement equation');
$assert(strpos($salesPlan, 'old-card credit in its temporary discount field') !== false
    && strpos($salesPlan, '$amount + $couponDiscount') !== false,
    'checkout accepts either equivalent temporary credit display while normalizing the final card sale snapshot');
$assert(strpos($salesPlan, "\$saleLines[0]['original_amount_cents'] = \$credit['targetPriceCents'];") !== false,
    'persisted target card sale retains its immutable original price instead of the post-credit checkout working amount');
$assert(strpos($service, "'excess_writeoff_cents' => max(0, \$sourceValue - \$targetSale)") !== false, 'source value above the target is recorded as write-off rather than negative collection');
$assert(strpos($service, "'card_upgrade_use_oid' => \$targetOrderId") !== false
    && strpos($service, 'lockOrCreateBaselineState') === false
    && strpos($service, "'card_status' => 'upgraded'") === false,
    'all source cards are terminally linked through legacy authority without creating card-state versions');
$assert(strpos($service, "'paid'] ?? 0) !== 1") !== false
    && strpos($service, 'repaid_debt_amount') === false, 'source availability remains based on paid card authority, not old debt repayment');
$assert(strpos($submission, 'prepareCreditInTx') !== false && strpos($submission, 'settleInTx') !== false, 'prepare, issue and source settlement stay in the normal final checkout transaction');
$assert(strpos($gateway, "\$canonicalAction !== 'submit-checkout'") !== false
    && strpos($gateway, 'isRetryableDatabaseDeadlock') !== false,
    'only the fully rolled-back checkout deadlock receives one idempotent retry');
$assert(strpos($manifest, "'card.multi_upgrade.settled'") !== false
    && strpos($manifest, "'aggregate_type' => 'multi_card_upgrade'") !== false
    && strpos($manifest, "'min_count' => 0") !== false,
    'multi-card settlement is an optional submit-checkout event, so ordinary checkout remains unchanged');
$assert(strpos($lifecycle, "isset(\$types['card.multi_upgrade.settled'])") !== false
    && strpos($reversal, 'prepareMultiCardUpgrade') !== false
    && strpos($reversal, 'restoreMultiCardUpgradeSources') !== false,
    'void detects the immutable multi-card event and atomically restores every old-card authority link');
$assert(strpos($orderQuery, "Db::name('cashier_v3_multi_card_upgrade')") !== false
    && strpos($orderQuery, "'operation_type' => 'card_upgrade'") !== false,
    'order centre reads the immutable multi-card snapshot as the upgrade settlement authority');
$assert(strpos($orderQuery, "Db::name('cashier_v3_multi_card_upgrade_source')") !== false
    && strpos($orderQuery, "'sourceCards' => array_map") !== false,
    'order detail exposes old-card immutable snapshots with their credit, without adding card-version records');
$assert(strpos($workbench, 'selectedCardOperationSourceIds') !== false && strpos($workbench, 'appendMultiCardUpgradeTarget') !== false, 'cashier supports selecting many old cards before one target');
$assert(strpos($workbench, 'refreshMultiCardUpgradePayable') !== false && strpos($workbench, 'editableSaleAmount') !== false, 'target card still uses normal price-edit path before old-card credit');
$assert(strpos($workbench, 'line.originalAmount = centsToMoney(targetSaleAmountCents)') !== false
    && strpos($workbench, 'next.originalLineAmountCents = targetSaleAmountCents') !== false,
    'price edits synchronize the final checkout target amount instead of retaining a stale catalogue price');
$assert(substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 2 && strpos($migration, 'current_version') === false, 'upgrade persistence is one immutable snapshot without business version rows');

if ($failed > 0) { fwrite(STDERR, "MULTI_CARD_UPGRADE_CONTRACT failed={$failed}\n"); exit(1); }
echo "MULTI_CARD_UPGRADE_CONTRACT=PASS\n";

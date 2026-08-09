<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php';
$verified = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutVerifiedSourceSet.php';
$facts = $root . '/后端代码/app/services/cashier/v3/fact/ThinkPhpCashierV3CheckoutFactRepository.php';
$cashierModule = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php';
$commandGateway = $root . '/后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php';
$contextPolicies = $root . '/后端代码/app/services/cashier/v3/registry/CashierV3ContextPolicyRegistry.php';
$cashierShell = $root . '/前端代码/cashier-v3/src/layouts/CashierShell.vue';
$cashierWorkbench = $root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue';
require $root . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;

$source = (string)file_get_contents($service);
foreach (['cashier_v3_debt_authority','cashier_v3_debt_item_personnel_authority','cashier_v3_debt_repayment_draft','cashier_v3_debt_repayment_collection','debt.repaid','paymentFacts','performanceFacts','proportionalCumulativeAllocation','debt_repayment_v3_authority_missing','debt_repayment_header_item_amount_drift','debt_repayment_personnel_authority_missing','debt_repayment_selected_salesperson_allocation'] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('missing contract: ' . $needle);
}
foreach (['resolveRepaymentSalespeople(', 'verifyRepaymentSalespeopleSnapshot(', "'salespeople_snapshot_json'", "'allocationWeightDenominator'=>100"] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('selected repayment salesperson authority missing: ' . $needle);
}
if (strpos($source, 'debt_repayment_frozen_line_salesperson') !== false) throw new RuntimeException('repayment performance must not reuse original sales-line personnel');
$snapshotMigration = $root . '/后端代码/database/upgrades/2026-08-06-收银V3补交销售人统一快照/02-正式升级.sql';
if (!is_file($snapshotMigration) || substr_count((string)file_get_contents($snapshotMigration), "salespeople_snapshot_json") < 2) {
    throw new RuntimeException('repayment salesperson snapshot migration missing');
}
foreach (['item_name_snapshot,debt_amount_cents', 'debt_repayment_personnel_legacy_line_mismatch', '$usedLines'] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('legacy debt line recovery contract missing: ' . $needle);
}
foreach (['field(\'real_name,nickname,phone\')', "?:('会员#'.\$memberId)", "where('store_id',\$operator->storeId())->lock(true)->value('request_version')"] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('repayment replay/dimension guard missing: ' . $needle);
}
if (strpos($source, "(string)(\$row['status'] ?? '') !== 'succeeded'") === false) throw new RuntimeException('query terminal status guard missing');
$projection = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
foreach (['readLatestSucceededDebtRepayment','debt_repayment_completed',"businessType' => 'debt_repayment'"] as $needle) if (strpos($projection,$needle)===false) throw new RuntimeException('success projection missing: '.$needle);
foreach ([
    "\$compositionProjection['primaryActionLabel'] = '确认还款'",
    "if ((\$step['key'] ?? '') === 'final')",
    "\$step['label'] = '确认还款'",
] as $needle) if (strpos($projection,$needle)===false) throw new RuntimeException('debt repayment composition projection missing: '.$needle);
if (strpos($source, 'StoreDebtServices') !== false || strpos($source, 'store_debt_repay') !== false) throw new RuntimeException('legacy debt path is forbidden');
if (strpos((string)file_get_contents($verified), "'debt_record' => 65") === false) throw new RuntimeException('debt source kind missing');
if (strpos((string)file_get_contents($facts), '$isSalesDebtRepayment') === false) throw new RuntimeException('debt repayment fact event mapping missing');
$cashierModuleSource = (string)file_get_contents($cashierModule);
foreach ([
    'registerDebtRepaymentPolicies($dispatcher)',
    "'prepare-debt-repayment',\n            ['cashier_workspace', 'debt_record']",
    "'submit-debt-repayment',\n            ['cashier_workspace', 'checkout_request']",
] as $needle) {
    if (strpos($cashierModuleSource, $needle) === false) throw new RuntimeException('production debt repayment context policy missing: ' . $needle);
}
$cashierShellSource = (string)file_get_contents($cashierShell);
if (strpos($cashierShellSource, "createCashierV3CommandId('CHECKOUT_PREPARE')") === false) throw new RuntimeException('canonical debt repayment preparation key missing');
if (strpos($cashierShellSource, "createCashierV3CommandId('DEBT_REPAY_PREPARE')") !== false) throw new RuntimeException('non-canonical debt repayment preparation key is forbidden');
if (strpos($cashierShellSource, "code: 'DEBT_REPAYMENT_DRAFT_RESUMED'") === false) throw new RuntimeException('existing debt repayment draft recovery missing');
foreach ([
    'async function openDebtRepaymentCheckout(preparationRequestId, debtRecordId)',
    "await router.push({ name: 'cashier-v3-cashier' })",
    'await openDebtRepaymentCheckout(currentCheckout.preparationRequestId, payload.debtRecordId)',
    'await openDebtRepaymentCheckout(preparationRequestId, payload.debtRecordId)',
] as $needle) if (strpos($cashierShellSource, $needle) === false) throw new RuntimeException('cross-route debt repayment checkout handoff missing: ' . $needle);
$cashierWorkbenchSource = (string)file_get_contents($cashierWorkbench);
if (strpos($cashierWorkbenchSource, "if (!isCompleteCheckoutPreparation(snapshot, detail.preparationRequestId))") === false) throw new RuntimeException('debt repayment checkout projection reload guard missing');
if (strpos($cashierWorkbenchSource, "if (snapshot.businessType === 'debt_repayment') return false") !== false) throw new RuntimeException('persisted debt repayment checkout recovery must not be blocked');
if (strpos($cashierWorkbenchSource, "businessType === 'debt_repayment' ? { primaryActionLabel: '确认还款' } : {}") === false) throw new RuntimeException('debt repayment checkout composition contract missing');
if (strpos($cashierWorkbenchSource, 'approvedPayload.commandContexts = draftContexts') === false) throw new RuntimeException('checkout draft must not submit persisted source contexts from the browser');
if (strpos($cashierWorkbenchSource, 'const isEditingPaymentDraft') === false) throw new RuntimeException('balanced external payment draft recovery missing');
$commandGatewaySource = (string)file_get_contents($commandGateway);
if (strpos($commandGatewaySource, 'if ($clientExtra && (') === false) throw new RuntimeException('server-derived checkout sources must support source-free follow-up commands');
$contextPolicySource = (string)file_get_contents($contextPolicies);
if (strpos($contextPolicySource, "['service_order', 'hang_order', 'reservation', 'room', 'debt_record', 'checkout_request']") === false) throw new RuntimeException('debt record follow-up context is not allowed for locked repayment submission');
$compositionContract = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3EntitlementDraftContract.js');
if (strpos($compositionContract, 'options.primaryActionLabel || defaultPrimaryActionLabel') === false) throw new RuntimeException('checkout composition label override missing');

$items = [
    ['id'=>11,'debt_amount'=>'3.00'],
    ['id'=>12,'debt_amount'=>'7.00'],
];
$a = CashierV3DebtRepaymentServices::proportionalCumulativeAllocation(500, $items);
$b = CashierV3DebtRepaymentServices::proportionalCumulativeAllocation(1000, $items);
if ($a !== [11=>150,12=>350] || $b !== [11=>300,12=>700]) throw new RuntimeException('proportional cumulative allocation mismatch');
$tie = CashierV3DebtRepaymentServices::proportionalCumulativeAllocation(1, [['id'=>2,'debt_amount'=>'1.00'],['id'=>1,'debt_amount'=>'1.00']]);
if ($tie !== [1=>1,2=>0]) throw new RuntimeException('stable remainder tie mismatch');
echo "SALES_DEBT_REPAYMENT_CONTRACT_OK\n";

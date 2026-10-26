<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php';
require_once $root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeOrderLifecycleServices.php';
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeOrderLifecycleServices.php');
$debtReversal = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeDebtReversalServices.php');
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php');
$provider = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeOrderLifecycleVersionProvider.php');
$backendManifest = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$frontendManifest = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3ActionManifest.js');
$bridge = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js');
$checks = [
    'uses append-only lifecycle operation authority' => strpos($service, 'CashierV3OrderLifecycleServices::OPERATION_TABLE') !== false && strpos($service, "'source_type' => 'recharge'") !== false,
    'uses specified principal and bonus atomic debit' => strpos($service, 'deductBenGive(') !== false && strpos($service, "'user_recharge_refund'") !== false && strpos($service, "'user_recharge_void'") !== false,
    'cash refund amount remains separate from balance component debits' => strpos($service, "'cashRefundCents'") !== false && strpos($service, "'principalDebitCents'") !== false && strpos($service, "'bonusDebitCents'") !== false,
    'cash-only refund skips balance reversal facts and balance version touch' => strpos($service, "if ((int)\$input['principalDebitCents'] + (int)\$input['bonusDebitCents'] > 0)") !== false
        && strpos($service, "'touchedRoles' => \$touched") !== false
        && strpos($module, "'touched' => (array)(\$result['touchedRoles'] ?? ['recharge_order'])") !== false,
    'recharge policy touches member balance only for balance debit or void' => strpos($module, "\$touchesBalance = \$action === 'void-recharge-order'") !== false
        && strpos($module, "['principalRefundAmount', 'bonusRefundAmount']") !== false
        && strpos($module, "'required_touched_roles' => \$touchedRoles") !== false
        && strpos($module, "}, [], ['recharge_order', 'member_balance'], ['recharge_order', 'member_balance']);") !== false,
    'server discovery marks member balance read for cash-only refund' => strpos($service, "'accessMode' => \$touchesBalance ? 'mutate' : 'read'") !== false
        && strpos($service, "\$action = (string)(\$scope['action'] ?? '')") !== false,
    'uses V3 balance snapshots before writing a balance reversal fact' => strpos($service, 'CashierV3MemberBalanceProvider') !== false && strpos($service, 'deductBalanceInTx') !== false && strpos($service, "'accountVersionAfter'") !== false,
    'reversal events advance after recharge completion' => strpos($service, "'aggregate_version' => 2 +") !== false,
    'allocates each performance measure separately with exact cents' => strpos($service, '$performanceGroups') !== false && strpos($service, 'allocateBySource') !== false && strpos($service, 'bcmul') !== false,
    'void is excluded from cash refund statistics' => strpos($service, '\'amount_cents\' => $input[\'cashRefundCents\']') !== false,
    'refund keeps downstream debt and gift business intact' => strpos($service, "\$action === 'void-recharge-order' ? \$debtReversal->prepare") !== false
        && strpos($service, "if (\$action === 'void-recharge-order') {") !== false
        && strpos($service, "\$cash + \$principal + \$bonus <= 0") !== false,
    'v3-only with dedicated debt and gift reversal authorities' => strpos($service, 'recharge_lifecycle_not_v3_authority') !== false
        && strpos($service, 'recharge_lifecycle_gift_reversal_not_available') === false
        && strpos($service, 'CashierV3RechargeDebtReversalServices') !== false
        && strpos($service, 'CashierV3RechargeGiftReversalServices') !== false
        && strpos($debtReversal, 'recharge_void_debt_repayment_exists') !== false,
    'facts are reversed without changing recharge master order' => strpos($service, 'fact_direction') !== false && strpos($service, "Db::name('user_recharge')->where('id', \$id)->where('store_id'") !== false && strpos($service, '->update(') === false,
    'actions are registered with recharge and member balance resources' => strpos($module, "['refund-recharge-order', 'void-recharge-order']") !== false
        && strpos($module, "['recharge_order', 'member_balance']") !== false && strpos($service, 'public function discover') !== false,
    'recharge version provider and manifests are active' => strpos($provider, "public const KIND = 'recharge_order'") !== false
        && strpos($backendManifest, "'recharge.refunded'") !== false && strpos($backendManifest, "'recharge.voided'") !== false
        && strpos($frontendManifest, "'refund-recharge-order'") !== false && strpos($frontendManifest, "'void-recharge-order'") !== false,
    'browser command carries authoritative recharge and balance contexts' => strpos($bridge, "buildCommandContext('recharge_order', payload.rechargeId)") !== false
        && strpos($bridge, "buildCommandContext('member_balance', payload.memberId)") !== false,
];
$failed = 0;
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed++; }

$allocation = new ReflectionMethod(\app\services\cashier\v3\order\CashierV3RechargeOrderLifecycleServices::class, 'allocateBySource');
$serviceInstance = new \app\services\cashier\v3\order\CashierV3RechargeOrderLifecycleServices();
$paymentSplit = $allocation->invoke($serviceInstance, [
    ['fact_id' => 'payment-a', 'amount_cents' => 333],
    ['fact_id' => 'payment-b', 'amount_cents' => 333],
    ['fact_id' => 'payment-c', 'amount_cents' => 334],
], 1, 1000);
$actualPerformanceSplit = $allocation->invoke($serviceInstance, [
    ['fact_id' => 'actual-performance', 'amount_cents' => 700],
], 1, 1000);
$salespeopleSplit = $allocation->invoke($serviceInstance, [
    ['fact_id' => 'salesperson-a', 'amount_cents' => 500],
    ['fact_id' => 'salesperson-b', 'amount_cents' => 500],
], 1, 1000);
$allocationOk = array_sum($paymentSplit) === 1
    && array_sum($actualPerformanceSplit) === 1
    && array_sum($salespeopleSplit) === 1
    && max($paymentSplit) <= 1
    && max($actualPerformanceSplit) <= 1
    && max($salespeopleSplit) <= 1;
echo ($allocationOk ? 'PASS ' : 'FAIL ') . 'one-cent refund is independently allocated to payment, actual-performance and salesperson facts' . PHP_EOL;
if (!$allocationOk) $failed++;
echo $failed === 0 ? "RECHARGE_ORDER_LIFECYCLE_CONTRACT=PASS\n" : "RECHARGE_ORDER_LIFECYCLE_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);

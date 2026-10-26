<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\member\CashierV3RechargeCheckoutModule;
use app\services\cashier\v3\member\CashierV3RechargeModule;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3RechargeDebtRepaymentServices;

// Balance creation and debt clearance require a real receipt. Check each
// server-side allowlist; hiding a browser option alone is not protection.
foreach ([
    [CashierV3RechargeModule::class, 'PAYMENT_METHODS'],
    [CashierV3RechargeCheckoutModule::class, 'METHODS'],
    [CashierV3DebtRepaymentServices::class, 'PAYMENT_METHODS'],
    [CashierV3RechargeDebtRepaymentServices::class, 'PAYMENT_METHODS'],
] as [$class, $constant]) {
    $methods = (new ReflectionClass($class))->getReflectionConstant($constant)->getValue();
    if (in_array('other_collection', $methods, true) || !in_array('wechat', $methods, true)) {
        fwrite(STDERR, "FAIL {$class} non-cash payment boundary\n");
        exit(1);
    }
    echo "PASS {$class} only accepts real collection methods\n";
}

$root = dirname(__DIR__, 3);
foreach ([
    '前端代码/cashier-v3/src/components/member/RechargeOverlay.vue',
    '前端代码/cashier-v3/src/composables/useRechargeCheckout.js',
] as $file) {
    if (strpos((string)file_get_contents($root . '/' . $file), 'other_collection') !== false) {
        fwrite(STDERR, "FAIL {$file} still offers non-cash payment\n");
        exit(1);
    }
    echo "PASS {$file} hides non-cash payment\n";
}
$debtSource = (string)file_get_contents($root . '/前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue');
$selectableMethods = substr($debtSource, strpos($debtSource, 'const paymentMethods = ['),
    strpos($debtSource, 'const historicalPaymentMethods') - strpos($debtSource, 'const paymentMethods = ['));
if (strpos($selectableMethods, 'other_collection') !== false
    || strpos($debtSource, "{ code: 'other_collection', label: '其他收款' }") === false) {
    fwrite(STDERR, "FAIL debt repayment selection or historical label\n");
    exit(1);
}
echo "PASS debt repayment hides non-cash tender and retains historical label\n";

// Both historical and new orders use the committed tender draft as the
// refund restriction. Candidate UI is advisory; transaction-time snapshots
// must recheck before producing a refund fact, while full void remains separate.
$refund = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php');
$orderCenter = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');
foreach ([
    'committed other-collection draft is authoritative' => strpos($refund, "->where('d.payment_method', 'other_collection')->where('d.draft_status', 'committed')") !== false,
    'card and document snapshots check original tender' => strpos($refund, '$otherCollection = $this->usesOtherCollection($tenantId, $salesOrderId);') !== false
        && strpos($refund, '$otherCollection = $this->usesOtherCollection($tenantId, (string)$line[\'order_id\']);') !== false,
    'transaction rejects unavailable target' => strpos($refund, "? \$this->cardSnapshot(\$holderId, \$memberId, \$scope->tenantId(), true)") !== false
        && strpos($refund, "!\$snapshot['canRefund']") !== false,
    'document choice is disabled with reason' => strpos($orderCenter, ':disabled="!line.canRefund || importedCardRefundUncertain"') !== false
        && strpos($orderCenter, '{{ line.unavailableReason }}') !== false,
] as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    echo "PASS {$label}\n";
}

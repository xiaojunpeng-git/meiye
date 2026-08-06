<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $file) use ($root): string {
    $content = file_get_contents($root . '/' . $file);
    if (!is_string($content)) throw new RuntimeException('missing: ' . $file);
    return $content;
};
$source = $read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBusinessSourceSelectionServices.php');
$salesPlan = $read('后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$paymentPlan = $read('后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php');
$projection = $read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$businessConfigProjection = $read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBusinessConfigProjectionServices.php');
$rechargeCheckout = $read('后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php');
$recharge = $read('后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php');

$checks = [
    'source has isolated selection version' => str_contains($source, "'selection_version'"),
    'source final settlement locks configuration' => str_contains($source, 'lockResolvedForSettlementInTx'),
    'sales order has business source snapshots' => str_contains($salesPlan, 'business_source_label_snapshot'),
    'payment plan snapshots configured accounting name' => str_contains($paymentPlan, 'resolveAccountingMethodSnapshot'),
    'recharge checkout reads configured accounting names and rechecks submit' => str_contains($rechargeCheckout, 'accountingMethodMap') && str_contains($rechargeCheckout, 'resolveAccountingMethodSnapshot'),
    'recharge authority snapshots configured accounting name' => str_contains($recharge, 'paymentMethodNameSnapshot'),
    'editing checkout delegates business configuration projection' => str_contains($projection, 'businessConfigProjection->apply'),
    'business configuration projection exposes configured accounting names' => str_contains($businessConfigProjection, 'applyAccountingMethodNames'),
];
foreach ($checks as $label => $ok) {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
echo "cashier business-source/accounting contract: PASS\n";

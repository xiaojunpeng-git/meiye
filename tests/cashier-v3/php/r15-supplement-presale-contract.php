<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentIdFactory;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;

// Test the fact builder itself: the selection snapshot must survive settlement.
$factsMethod = new ReflectionMethod(CashierV3DebtRepaymentServices::class, 'performanceFacts');
$service = new CashierV3DebtRepaymentServices();
$person = [
    'employeeId' => 1261, 'name' => '沈立华', 'employeeTypeCodeSnapshot' => 'internal',
    'employeeTypeAuthorityVersion' => 1, 'allocationWeight' => 100,
    'amountCents' => 9800, 'sequence' => 1, 'isPreSale' => true,
];
$facts = $factsMethod->invoke($service, [$person], 'DRP-r15', 9800, new CashierV3DebtRepaymentIdFactory('r15-test-secret'));
if (($facts[0]['roleSnapshot'] ?? null) !== 'salesperson:presale'
    || ($facts[0]['amountCents'] ?? null) !== 9800) {
    throw new RuntimeException('presale_selection_lost_during_repayment');
}
$person['isPreSale'] = false;
$postSale = $factsMethod->invoke($service, [$person], 'DRP-r15-post', 9800, new CashierV3DebtRepaymentIdFactory('r15-test-secret'));
if (($postSale[0]['roleSnapshot'] ?? null) !== 'salesperson:postsale') {
    throw new RuntimeException('postsale_selection_misclassified');
}

// The list renders the current fact's amount, not the payment total or an old snapshot.
$labelMethod = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'supplementSalespersonLabel');
$presale = ['employee_name_snapshot' => '沈立华', 'role_snapshot' => 'salesperson:presale',
    'amount_cents' => 9800, 'allocation_weight_numerator' => 100];
if ($labelMethod->invoke(null, $presale) !== '沈立华(售前,¥98)') {
    throw new RuntimeException('presale_amount_label_invalid');
}
$presale['role_snapshot'] = 'salesperson';
if ($labelMethod->invoke(null, $presale) !== '沈立华(¥98)') {
    throw new RuntimeException('legacy_role_amount_missing_or_guessed_as_presale');
}
$presale['role_snapshot'] = 'salesperson:postsale';
$presale['allocation_weight_numerator'] = 50;
if ($labelMethod->invoke(null, $presale) !== '沈立华(¥98)') {
    throw new RuntimeException('postsale_amount_replaced_by_weight');
}
echo "R15_SUPPLEMENT_PRESALE_CONTRACT_OK\n";

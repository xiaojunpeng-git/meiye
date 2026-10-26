<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/report/StoreReportPartnerCategorySnapshotServices.php';
require_once $root . '/后端代码/app/services/report/StoreReportPaymentSaleAllocationFactServices.php';

use app\services\report\StoreReportPaymentSaleAllocationFactServices;

function paymentAllocationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sales = [
    ['fact_id' => 'sale-1000', 'sale_amount_cents' => 100000],
    ['fact_id' => 'sale-208', 'sale_amount_cents' => 20800],
];
$unionpay = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(120800, $sales);
paymentAllocationAssert($unionpay === ['sale-1000' => 100000, 'sale-208' => 20800], 'UnionPay is split by item amount');
paymentAllocationAssert(array_sum($unionpay) === 120800, 'UnionPay allocation conserves the payment total');

$mixedUnionpay = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(700, [
    ['fact_id' => 'sale-a', 'sale_amount_cents' => 1000],
    ['fact_id' => 'sale-b', 'sale_amount_cents' => 1000],
]);
$mixedWechat = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(1300, [
    ['fact_id' => 'sale-a', 'sale_amount_cents' => 1000],
    ['fact_id' => 'sale-b', 'sale_amount_cents' => 1000],
]);
paymentAllocationAssert($mixedUnionpay === ['sale-a' => 350, 'sale-b' => 350], 'each payment method is allocated independently');
paymentAllocationAssert($mixedWechat === ['sale-a' => 650, 'sale-b' => 650], 'second payment method conserves its own total');

$remainder = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(2, [
    ['fact_id' => 'sale-a', 'sale_amount_cents' => 1],
    ['fact_id' => 'sale-b', 'sale_amount_cents' => 2],
]);
paymentAllocationAssert($remainder === ['sale-a' => 1, 'sale-b' => 1], 'cent remainder uses stable largest-remainder order');

$reversal = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(-120800, [
    ['fact_id' => 'sale-1000', 'sale_amount_cents' => -100000],
    ['fact_id' => 'sale-208', 'sale_amount_cents' => -20800],
]);
paymentAllocationAssert($reversal === ['sale-1000' => -100000, 'sale-208' => -20800], 'reversal preserves the source-line allocation relationship');

$debt = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(180000, [
    ['fact_id' => 'sale-debt', 'sale_amount_cents' => 100000, 'debt_amount_cents' => 20000],
    ['fact_id' => 'sale-paid', 'sale_amount_cents' => 100000, 'debt_amount_cents' => 0],
]);
paymentAllocationAssert($debt === ['sale-debt' => 80000, 'sale-paid' => 100000], 'line debt is excluded before payment allocation');

$repository = file_get_contents($root . '/后端代码/app/services/cashier/v3/fact/ThinkPhpCashierV3CheckoutFactRepository.php');
$report = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
paymentAllocationAssert(strpos($repository, 'StoreReportPaymentSaleAllocationFactServices') !== false, 'checkout persists payment allocation facts in its transaction');
paymentAllocationAssert(strpos($report, "cashier_v3_payment_sale_allocation_fact") !== false, 'member report reads payment allocation facts');
paymentAllocationAssert(strpos($report, '$paymentByOrder') === false, 'member report does not repeat order-level payments');

echo "PASS payment sale allocation fact contract\n";

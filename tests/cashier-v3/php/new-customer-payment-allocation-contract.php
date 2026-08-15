<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$report = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$debt = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php');
$allocation = (string)file_get_contents($root . '/后端代码/app/services/report/StoreReportPaymentSaleAllocationFactServices.php');

foreach ([
    "cashier_v3_payment_sale_allocation_fact allocation",
    "allocation.sale_fact_id,payment.source_document_type,SUM(allocation.amount_cents)",
    "\$payments[\$salesFact]",
    "source_document_type']==='debt_repayment'",
] as $needle) {
    if (strpos($report, $needle) === false) throw new RuntimeException('new customer payment allocation contract missing: ' . $needle);
}
foreach ([
    'persistOriginalSalePaymentAllocationsInTx',
    'StoreReportPaymentSaleAllocationFactServices',
    'debt_repayment_payment_allocation_total_invalid',
    "whereIn('source_line_id', array_keys(\$lineBases))",
] as $needle) {
    if (strpos($debt, $needle) === false) throw new RuntimeException('debt repayment allocation contract missing: ' . $needle);
}
if (strpos($allocation, 'persistDebtRepaymentInTx') === false) {
    throw new RuntimeException('payment allocation writer is missing debt repayment support');
}

require_once $root . '/后端代码/app/services/report/StoreReportPartnerCategorySnapshotServices.php';
require_once $root . '/后端代码/app/services/report/StoreReportPaymentSaleAllocationFactServices.php';

use app\services\report\StoreReportPaymentSaleAllocationFactServices;

$shares = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(120800, [
    ['fact_id' => 'sale-a', 'sale_amount_cents' => 100000],
    ['fact_id' => 'sale-b', 'sale_amount_cents' => 20800],
]);
if ($shares !== ['sale-a' => 100000, 'sale-b' => 20800] || array_sum($shares) !== 120800) {
    throw new RuntimeException('two-line payment must remain allocated to its sale facts');
}
$clearing = StoreReportPaymentSaleAllocationFactServices::allocatePaymentToSales(500, [
    ['fact_id' => 'sale-a', 'sale_amount_cents' => 300],
    ['fact_id' => 'sale-b', 'sale_amount_cents' => 700],
]);
if ($clearing !== ['sale-a' => 150, 'sale-b' => 350] || array_sum($clearing) !== 500) {
    throw new RuntimeException('debt clearing must use the current debt-line allocation bases');
}

echo "PASS new customer payment allocation contract\n";

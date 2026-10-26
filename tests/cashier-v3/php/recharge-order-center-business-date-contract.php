<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$query = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php'
);

$start = strpos($query, 'private function readRechargeBusinessDates');
$end = strpos($query, 'private function rechargeFactKey', $start);
$method = $start === false || $end === false ? '' : substr($query, $start, $end - $start);
$passed = strpos($method, "->where('pf.fact_direction', 'forward')") !== false
    && strpos($method, 'MAX(pf.business_date) AS business_date') !== false;

echo ($passed ? 'PASS ' : 'FAIL ')
    . 'void reversal facts cannot overwrite the recharge order business-date snapshot' . PHP_EOL;

$salesStart = strpos($query, 'private function readRechargeSalespeople');
$salesEnd = strpos($query, 'private function readRechargeBusinessDates', $salesStart);
$salesMethod = $salesStart === false || $salesEnd === false ? '' : substr($query, $salesStart, $salesEnd - $salesStart);
$salesPassed = strpos($salesMethod, '不能抹掉原充值单的销售人审计痕迹') !== false
    && strpos($salesMethod, '$reversed =') === false
    && strpos($salesMethod, "->where('fact_direction', 'forward')") !== false;

echo ($salesPassed ? 'PASS ' : 'FAIL ')
    . 'void recharge keeps original salesperson snapshot visible for audit' . PHP_EOL;
exit($passed && $salesPassed ? 0 : 1);

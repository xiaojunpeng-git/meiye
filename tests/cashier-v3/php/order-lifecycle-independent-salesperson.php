<?php

require dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php';

use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;

// 订单中心调整的现金业绩基数只允许取订单行一次；独立岗位与普通岗位
// 各自分配这份基数，不能先把旧人员事实相加再作为下一次调整的基数。
$service = new CashierV3OrderLifecycleServices();
$allocate = new ReflectionMethod($service, 'adjustedSalespersonAmounts');
if (PHP_VERSION_ID < 80100) $allocate->setAccessible(true);
$independent = [
    ['positionId' => 67, 'performanceIndependent' => true, 'allocationWeight' => 100],
    ['positionId' => 69, 'performanceIndependent' => false, 'allocationWeight' => 100],
];
if ($allocate->invoke($service, 999900, $independent) !== [999900, 999900]) {
    throw new RuntimeException('独立店长和普通销售人必须各获本组100%');
}
$normal = [
    ['positionId' => 69, 'performanceIndependent' => false, 'allocationWeight' => 50],
    ['positionId' => 69, 'performanceIndependent' => false, 'allocationWeight' => 50],
];
if ($allocate->invoke($service, 19800, $normal) !== [9900, 9900]) {
    throw new RuntimeException('同一普通岗位组仍须合计100%');
}
$independent[0]['performanceAmountManual'] = true;
$independent[0]['performanceAmountCents'] = 50000;
if ($allocate->invoke($service, 999900, $independent) !== [50000, 999900]) {
    throw new RuntimeException('手工金额只能覆盖被编辑员工，不得改写另一岗位组');
}

echo "PASS order-lifecycle-independent-salesperson\n";

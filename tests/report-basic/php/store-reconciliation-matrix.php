<?php

declare(strict_types=1);

require __DIR__ . '/../../../后端代码/app/services/report/StoreReconciliationMatrix.php';

use app\services\report\StoreReconciliationMatrix;

$stores = [['id' => 2, 'name' => '门店乙'], ['id' => 1, 'name' => '门店甲']];
$methods = [['code' => 'scan', 'name' => '扫码'], ['code' => 'brush', 'name' => '刷单']];
$result = StoreReconciliationMatrix::build($stores, $methods, [
    ['store_id' => 1, 'method_code' => 'scan', 'amount_cents' => 14550],
    ['store_id' => 1, 'method_code' => 'scan', 'amount_cents' => -50],
    ['store_id' => 2, 'method_code' => 'scan', 'amount_cents' => 300],
    ['store_id' => 2, 'method_code' => 'brush', 'amount_cents' => 32600],
]);
if ($result['stores'] !== $stores || $result['rows'][0]['total_cents'] !== 14800
    || $result['rows'][0]['stores'] !== [2 => 300, 1 => 14500]
    || $result['rows'][1]['stores'] !== [2 => 32600, 1 => 0]) {
    throw new RuntimeException('矩阵金额、顺序或零值错误');
}
$days = StoreReconciliationMatrix::days('2024-02');
if (count($days) !== 29 || $days[28]['name'] !== '2024-02-29') throw new RuntimeException('闰年日期列错误');
try {
    StoreReconciliationMatrix::days('2024-13');
    throw new RuntimeException('无效月份未被拒绝');
} catch (InvalidArgumentException $expected) {
}
foreach ([
    [['store_id' => 3, 'method_code' => 'scan', 'amount_cents' => 1]],
    [['store_id' => 1, 'method_code' => 'unknown', 'amount_cents' => 1]],
    [['store_id' => 1, 'method_code' => 'scan', 'amount_cents' => 1.1]],
] as $invalid) {
    try {
        StoreReconciliationMatrix::build($stores, $methods, $invalid);
        throw new RuntimeException('无效事实未被拒绝');
    } catch (InvalidArgumentException $expected) {
        // 所有不在授权目录内的事实与非整数分都必须失败。
    }
}
try {
    StoreReconciliationMatrix::build($stores, $methods, [
        ['store_id' => 1, 'method_code' => 'scan', 'amount_cents' => PHP_INT_MAX],
        ['store_id' => 1, 'method_code' => 'scan', 'amount_cents' => 1],
    ]);
    throw new RuntimeException('金额溢出未被拒绝');
} catch (OverflowException $expected) {
    // 防止整数溢出后被 PHP 转成浮点数。
}
echo "门店对账矩阵测试通过\n";

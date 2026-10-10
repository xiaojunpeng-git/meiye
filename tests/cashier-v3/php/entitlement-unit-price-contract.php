<?php
declare(strict_types=1);

// 不连接数据库的真实算法测试：只读展示价截断小数，不能影响权益尾差或可用金额。
$backend = getenv('MEIYE_TEST_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
$path = $backend . '/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php';
require_once $path;
$class = new ReflectionClass(\app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices::class);
$service = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('purchaseUnitPrice');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$cases = [
    ['2980.00', 10, false, '298'],
    ['6980.00', 30, false, '232'],
    ['100.00', 3, false, '33'],
    ['199.99', 2, false, '99'],
    ['0.00', 10, false, '0'],
    ['0.99', 1, false, '0'],
    [null, 10, false, null],
    ['100.00', 0, false, null],
    ['100.00', -1, false, null],
    ['100.00', 10, true, null],
    ['-1.00', 10, false, null],
    ['bad', 10, false, null],
];
foreach ($cases as $case) {
    [$amount, $times, $unlimited, $expected] = $case;
    $actual = $method->invoke($service, $amount, $times, $unlimited);
    if ($actual !== $expected) {
        throw new RuntimeException('unit price mismatch: ' . json_encode([$case, $actual]));
    }
}
// 展示价不能替代最后一次扣款：6980/30 的常规整数部分232，末次仍承接尾差252。
require_once $backend . '/app/services/cashier/v3/cashier/CashierV3EntitlementActualAmountAllocator.php';
$allocator = \app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator::class;
if ($allocator::allocate('6980.00', 30, 0, 1) !== '232.00'
    || $allocator::allocate('6980.00', 30, 29, 1) !== '252.00'
    || bcadd($allocator::allocate('6980.00', 30, 0, 29), $allocator::allocate('6980.00', 30, 29, 1), 2) !== '6980.00') {
    throw new RuntimeException('existing final-tail allocation invariant changed');
}
$source = file_get_contents($path);
if (strpos($source, "\$project['unitPrice'] = \$this->purchaseUnitPrice(") === false
    || strpos($source, "'unitPrice' => \$this->purchaseUnitPrice(") === false
    || strpos($source, "(int)\$project['totalPurchaseTimes']") === false
    || strpos($source, 'return bcdiv($purchaseAmount, (string)$purchaseTimes, 0);') === false) {
    throw new RuntimeException('card/project display projection wiring missing');
}
echo 'PASS entitlement unit price: ' . count($cases) . " cases, original purchase count, integer truncation; final-tail invariant intact\n";

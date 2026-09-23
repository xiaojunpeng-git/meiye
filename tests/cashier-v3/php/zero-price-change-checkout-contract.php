<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$moreActionPath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierMoreActionServices.php';
$kernelPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php';
$salesOrderPath = $root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php';
$craftsmenPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php';
$preparationPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php';

$moreActionSource = (string)file_get_contents($moreActionPath);
if (strpos(
    $moreActionSource,
    '$lineAmountCents !== 0 && $lineAmountCents < $minimumLineAmountCents'
) === false) {
    throw new RuntimeException('改价后端未仅对精确 0 元放开成本下限');
}
$kernelSource = (string)file_get_contents($kernelPath);
$salesOrderSource = (string)file_get_contents($salesOrderPath);
foreach ([$kernelSource, $salesOrderSource] as $source) {
    if (strpos($source, '$positiveSaleBelowCost = $sale !== 0') === false) {
        throw new RuntimeException('零元改价口径未贯穿结账锁定和销售订单持久化');
    }
}

require_once $craftsmenPath;
require_once $preparationPath;

$reflection = new ReflectionClass(
    app\services\cashier\v3\settlement\CashierV3CheckoutPreparationServices::class
);
$service = $reflection->newInstanceWithoutConstructor();
$saleSnapshotLine = $reflection->getMethod('saleSnapshotLine');
if (PHP_VERSION_ID < 80100) {
    $saleSnapshotLine->setAccessible(true);
}

// 零元改价仍必须进入结账的不可变销售行，并冻结原价、折扣与改价审计。
$snapshot = $saleSnapshotLine->invoke($service, [
    'kindCode' => 'project',
    'lineId' => 'zero-price-change-line',
    'productId' => 101,
    'skuId' => 201,
    'productVersion' => 3,
    'quantity' => 1,
    'originalLineAmountCents' => 100,
    'lineAmountCents' => 0,
    'configuredCostCents' => 80,
    'priceChangeReason' => '本地零元改价结账回归',
    'priceChangedBy' => 9,
    'priceChangedByNameSnapshot' => '测试操作员',
    'priceChangedAt' => 1789574400,
    'nameSnapshot' => '背部spa',
    'skuUnique' => 'ZERO-PRICE-CHANGE',
    'categoryIdSnapshot' => 12,
    'categoryNameSnapshot' => '生美/卡项',
    'craftsmen' => [],
    'salespeople' => [],
]);

$expected = [
    'originalAmountCents' => 100,
    'discountAmountCents' => 100,
    'saleAmountCents' => 0,
    'debtAmountCents' => 0,
    'configuredCostCents' => 80,
    'priceChangeReason' => '本地零元改价结账回归',
    'priceChangedBy' => 9,
    'priceChangedByNameSnapshot' => '测试操作员',
    'priceChangedAt' => 1789574400,
];
foreach ($expected as $field => $value) {
    if (($snapshot[$field] ?? null) !== $value) {
        throw new RuntimeException("zero-price checkout snapshot mismatch: {$field}");
    }
}

echo "PASS zero-price-change-checkout-contract\n";

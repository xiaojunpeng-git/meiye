<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';

use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;

function zeroReceivableSaleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

$method = new ReflectionMethod(CashierV3CheckoutFactPlanV1::class, 'normalizeSpecific');
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}

$sale = [
    'sourceType' => 'project',
    'itemId' => 'zero-price-project',
    'itemCodeSnapshot' => 'ZERO-PRICE-PROJECT',
    'itemNameSnapshot' => '本地零元结账回归项目',
    'categoryIdSnapshot' => '',
    'categoryNameSnapshot' => '',
    'quantity' => 1,
    'friendCountsAsCustomer' => 0,
    'isPresale' => 0,
    'inventoryOutboundRequired' => 0,
    'originalAmountCents' => 0,
    'discountAmountCents' => 0,
    'couponUserId' => 0,
    'couponNameSnapshot' => '',
    'couponDiscountCents' => 0,
    'saleAmountCents' => 0,
    'debtAmountCents' => 0,
];

$forward = $method->invoke(null, 'sale', $sale, CashierV3CheckoutFactPlanV1::DIRECTION_FORWARD);
$reversal = $method->invoke(null, 'sale', $sale, CashierV3CheckoutFactPlanV1::DIRECTION_REVERSAL);

zeroReceivableSaleAssert(
    ($forward['original_amount_cents'] ?? null) === 0
        && ($forward['sale_amount_cents'] ?? null) === 0
        && ($reversal['original_amount_cents'] ?? null) === 0
        && ($reversal['sale_amount_cents'] ?? null) === 0,
    '零元销售事实及其作废反向事实必须允许零金额并保持可追溯'
);

echo "PASS zero-receivable-sale-fact-contract\n";

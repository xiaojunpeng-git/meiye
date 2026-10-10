<?php
/** Sales-list coupon display contract; reads source and projects a synthetic line only. */

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

$passed = 0;
$failed = 0;
function salesCouponCheck(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

$service = (new ReflectionClass(CashierV3SalesOrderQueryServices::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(CashierV3SalesOrderQueryServices::class, 'mapAuthorityLine');
$method->setAccessible(true);
$line = [
    'order_line_id' => 'coupon-line', 'item_type' => 'product', 'item_name_snapshot' => '测试商品',
    'quantity' => 3, 'original_amount_cents' => 1200000, 'discount_amount_cents' => 112000,
    'coupon_discount_cents' => 100000, 'sale_amount_cents' => 1088000,
];
$item = $method->invoke($service, $line, []);
salesCouponCheck('用券前金额保留改价优惠但加回优惠券，成交额不变',
    $item['amountBeforeCoupon'] === 11880.0
    && $item['couponDiscountAmount'] === 1000.0
    && $item['payableAmount'] === 10880.0);
$newTenderLine = $line;
$newTenderLine['discount_amount_cents'] = 12000;
$newTenderLine['coupon_discount_cents'] = 0;
$newTenderLine['coupon_payment_cents'] = 100000;
$newTenderLine['sale_amount_cents'] = 1188000;
$newTender = $method->invoke($service, $newTenderLine, []);
salesCouponCheck('新单券作为抵付保留用券前购买金额',
    $newTender['amountBeforeCoupon'] === 11880.0
    && $newTender['couponDiscountAmount'] === 1000.0
    && $newTender['payableAmount'] === 10880.0);
$line['coupon_discount_cents'] = 0;
$line['discount_amount_cents'] = 0;
$line['sale_amount_cents'] = 1200000;
$withoutCoupon = $method->invoke($service, $line, []);
salesCouponCheck('无优惠券时用券前金额等于成交额',
    $withoutCoupon['amountBeforeCoupon'] === $withoutCoupon['payableAmount']);

$backend = $root . '/后端代码/app/services/cashier/v3/order/';
$query = (string)file_get_contents($backend . 'CashierV3SalesOrderQueryServices.php');
$contract = (string)file_get_contents($backend . 'CashierV3OrderCenterUnifiedQueryContract.php');
$provider = (string)file_get_contents($backend . 'AbstractCashierV3OrderCenterUnifiedQueryProvider.php');
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');
salesCouponCheck('优惠券列在欠款右侧且表格整单只显示一次',
    strpos($view, "'debt_amount', 'coupon_discount_amount', 'actual_received_amount'") !== false
    && strpos($view, '{{ itemMoney(record.couponDiscountAmount ?? record.coupon_discount_amount) }}') !== false
    && strpos($contract, "['debt_amount', '欠款', 'amount'], ['coupon_discount_amount', '优惠券', 'amount']") !== false);
salesCouponCheck('列表与导出使用相同的后端用券前明细金额',
    strpos($view, "item.businessTag === '权益' ? item.entitlementAmount : item.amountBeforeCoupon") !== false
    && strpos($provider, "'entitlementAmount' : 'amountBeforeCoupon'") !== false
    && strpos($query, "'amountBeforeCoupon' => \$this->moneyFromCents((int)\$line['sale_amount_cents'] + (int)(\$line['coupon_discount_cents'] ?? 0))") !== false);
salesCouponCheck('优惠券金额取冻结销售明细快照，旧折扣与新抵付分别计入',
    strpos($query, "\$couponDiscountCents += \$coupon;") !== false
    && strpos($query, "\$couponPaymentCents += max(0, (int)(\$line['coupon_payment_cents'] ?? 0));") !== false
    && strpos($query, "'couponDiscountAmount' => \$settlementEquationValid ? \$this->moneyFromCents(\$couponDiscountCents + \$couponPaymentCents) : null") !== false
    && strpos($provider, "'coupon_discount_amount' => ['couponDiscountAmount']") !== false);

echo "ORDER_CENTER_SALES_COUPON_AMOUNT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

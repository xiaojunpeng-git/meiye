<?php
/** 订单券名展示仅投影冻结销售事实，不读取或改写会员当前优惠券。 */
require_once dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
$service = (new ReflectionClass(CashierV3SalesOrderQueryServices::class))->newInstanceWithoutConstructor();
$line = ['order_line_id'=>'coupon-line', 'item_type'=>'product', 'item_name_snapshot'=>'测试商品',
    'quantity'=>1, 'original_amount_cents'=>20000, 'discount_amount_cents'=>0,
    'coupon_discount_cents'=>0, 'coupon_payment_cents'=>10000, 'sale_amount_cents'=>20000,
    'coupon_name_snapshot'=>'原订单赠送券'];
function checkCoupon($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$method = new ReflectionMethod($service, 'mapAuthorityLine'); $method->setAccessible(true);
$item = $method->invoke($service, $line, []);
checkCoupon($item['couponName'] === '原订单赠送券' && $item['couponDiscountAmount'] === 100.0, '正式明细返回冻结券名和抵付金额');
$legacy = new ReflectionMethod($service, 'mapLine'); $legacy->setAccessible(true);
$item = $legacy->invoke($service, [], ['authority'=>$line]);
checkCoupon($item['couponName'] === '原订单赠送券' && $item['couponDiscountAmount'] === 100.0, '兼容明细返回同一冻结券名和抵付金额');
$line['coupon_discount_cents']=10000; $line['coupon_payment_cents']=0;
$item = $legacy->invoke($service, [], ['authority'=>$line]);
checkCoupon($item['couponName'] === '原订单赠送券' && $item['couponDiscountAmount'] === 100.0, '旧折扣券保留原金额和券名');
$item = $legacy->invoke($service, [], []);
checkCoupon($item['couponName'] === '' && $item['couponDiscountAmount'] === null, '缺少权威快照不编造券名或金额');

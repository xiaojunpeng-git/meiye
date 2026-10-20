<?php
declare(strict_types=1);

// Local MySQL 5.7 rollback integration. It never calls a payment provider.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local only');

use app\services\member\MemberCommerceBenefitsServices;
use app\services\member\MemberCommerceServices;
use app\services\member\MemberCommerceScope;
use mohe\services\SystemConfigService;
use think\facade\Db;

function ok(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$uid = 913081;
$storeId = 118;
$productId = 625196;
$service = new MemberCommerceServices();
$failed = false;
Db::startTrans();
try {
    foreach ([
        'integral_ratio_status' => 1,
        'integral_ratio' => 1,
        'integral_max_type' => 1,
        'integral_max_num' => 100,
        'integral_max_rate' => 0,
    ] as $key => $value) {
        Db::name('system_config')->where('menu_name', $key)
            ->update(['value' => json_encode($value)]);
    }
    Db::name('user')->where('uid', $uid)->update(['integral' => 1000]);
    SystemConfigService::clear();

    $now = time();
    $issueId = (int)Db::name('store_coupon_issue')->insertGetId([
        'coupon_issue_type' => 0, 'relation_id' => 0, 'category' => 1, 'type' => 0,
        'coupon_type' => 1, 'coupon_title' => '本地整元优惠券', 'start_time' => $now - 60,
        'end_time' => $now + 86400, 'total_count' => 1, 'remain_count' => 0,
        'status' => 1, 'is_del' => 0, 'add_time' => $now, 'coupon_price' => '66.00',
        'use_min_price' => '100.00', 'applicable_type' => 1, 'use_mode' => 0,
    ]);
    $couponId = (int)Db::name('store_coupon_user')->insertGetId([
        'cid' => $issueId, 'uid' => $uid, 'coupon_title' => '本地整元优惠券',
        'coupon_price' => '66.00', 'use_min_price' => '100.00', 'add_time' => $now,
        'start_time' => $now - 60, 'end_time' => $now + 86400,
        'use_time' => 0, 'type' => 'send', 'status' => 0, 'is_fail' => 0,
    ]);

    $input = ['store_id' => $storeId, 'items' => [['product_id' => $productId, 'quantity' => 1]],
        'salespeople' => [], 'coupon_id' => $couponId, 'use_integral' => 1,
        'payment_mode' => 'fuyou', 'idempotency_key' => 'round6-benefit-' . bin2hex(random_bytes(10))];
    $quote = $service->quote($uid, $input);
    ok((int)$quote['grossAmountCents'] === 56600, 'gross amount must remain server priced');
    ok((int)$quote['couponDiscountCents'] === 6600, 'coupon deduction missing');
    ok((int)$quote['integralUsed'] === 100 && (int)$quote['integralDiscountCents'] === 10000,
        'integral deduction must use configured whole-yuan amount');
    ok((int)$quote['amountCents'] === 40000, 'payable amount must include coupon and points');
    $input['fingerprint'] = $quote['fingerprint'];
    $order = $service->create($uid, $input, 'h5');
    $service->paid($order['id'], 40000, 'ROUND6-LOCAL', 'wechat');
    $order = $service->detail($uid, $order['id']);
    ok($order['status'] === 'paid' && (int)$order['amountCents'] === 40000,
        'confirmed external purchase must settle');
    ok((int)Db::name('store_coupon_user')->where('id', $couponId)->value('status') === 1,
        'coupon must be consumed in shared settlement');
    ok((int)Db::name('user')->where('uid', $uid)->value('integral') === 900,
        'points must be consumed exactly once');
    $commerce = Db::name('member_commerce_order')->where('public_id', $order['id'])->find();
    $benefit = Db::name(MemberCommerceBenefitsServices::TABLE)->where('commerce_id', $commerce['id'])->find();
    ok($benefit && $benefit['status'] === 'consumed' && $benefit['sales_order_id'] === $commerce['sales_order_id'],
        'benefit audit must link the formal sales order');
    $saleLine = Db::name('cashier_v3_sales_order_line')->where('order_id', $commerce['sales_order_id'])->find();
    ok((int)$saleLine['coupon_payment_cents'] === 6600, 'store order must expose coupon tender');
    ok((int)$saleLine['discount_amount_cents'] >= 10000, 'store order must retain point price discount');
    $benefitSnapshot = json_decode((string)$benefit['snapshot_json'], true);
    $pointLine = (array)($benefitSnapshot['integralAllocations'][0] ?? []);
    ok((int)($pointLine['discountCents'] ?? 0) === 10000
        && (string)($pointLine['salesOrderLineId'] ?? '') === (string)$saleLine['order_line_id'],
        'point deduction must remain attached to the exact store sales line');

    $benefits = new MemberCommerceBenefitsServices();
    $header = Db::name('cashier_v3_sales_order')->where('order_id', $commerce['sales_order_id'])->find();
    $scope = MemberCommerceScope::create($uid, $storeId);
    $reversalPrepared = (new \app\services\cashier\v3\order\CashierV3SalesOrderReversalServices())
        ->prepare(['sourceId' => $header['order_id'], 'storeId' => $storeId, 'memberId' => $uid,
            'checkoutRequestId' => $header['checkout_request_id'], 'amountCents' => 46600],
            'void-sales-order', [], $scope['data']);
    ok((int)$reversalPrepared['memberBenefit']['integral_used'] === 100,
        'shared full-void preparation must lock the point fact');
    $prepared = $benefits->prepareVoidInTx((string)$commerce['sales_order_id'], $uid);
    $benefits->restoreVoidInTx($prepared, (string)$commerce['sales_order_id'], $uid);
    ok((int)Db::name('user')->where('uid', $uid)->value('integral') === 1000,
        'whole void must restore points');

    $couponId2 = (int)Db::name('store_coupon_user')->insertGetId([
        'cid' => $issueId, 'uid' => $uid, 'coupon_title' => '本地整元优惠券',
        'coupon_price' => '66.00', 'use_min_price' => '100.00', 'add_time' => $now,
        'start_time' => $now - 60, 'end_time' => $now + 86400,
        'use_time' => 0, 'type' => 'send', 'status' => 0, 'is_fail' => 0,
    ]);
    $cancelInput = $input;
    $cancelInput['coupon_id'] = $couponId2;
    $cancelInput['idempotency_key'] = 'round6-cancel-' . bin2hex(random_bytes(10));
    $cancelQuote = $service->quote($uid, $cancelInput);
    $cancelInput['fingerprint'] = $cancelQuote['fingerprint'];
    $pendingOrder = $service->create($uid, $cancelInput, 'h5');
    $pendingCommerce = Db::name('member_commerce_order')->where('public_id', $pendingOrder['id'])->find();
    ok(Db::name(MemberCommerceBenefitsServices::TABLE)->where('commerce_id', $pendingCommerce['id'])
        ->value('status') === 'reserved', 'pending order must reserve coupon and points');
    $service->cancel($uid, $pendingOrder['id']);
    ok(Db::name(MemberCommerceBenefitsServices::TABLE)->where('commerce_id', $pendingCommerce['id'])
        ->value('status') === 'released', 'cancellation must release benefit reservation');
    ok((int)Db::name('store_coupon_user')->where('id', $couponId2)->value('status') === 0
        && (int)Db::name('user')->where('uid', $uid)->value('integral') === 1000,
        'cancellation must not consume coupon or points');

    // The member flow must reuse cashier V3's existing order-threshold coupon
    // authority instead of implementing a second coupon consumer.
    $orderIssueId = (int)Db::name('store_coupon_issue')->insertGetId([
        'coupon_issue_type' => 0, 'relation_id' => 0, 'category' => 1, 'type' => 0,
        'coupon_type' => 1, 'coupon_title' => '本地整单门槛券', 'start_time' => $now - 60,
        'end_time' => $now + 86400, 'total_count' => 1, 'remain_count' => 0,
        'status' => 1, 'is_del' => 0, 'add_time' => $now, 'coupon_price' => '66.00',
        'use_min_price' => '0.00', 'applicable_type' => 1, 'use_mode' => 1,
        'order_tiers_json' => json_encode([['min_amount_cents' => 10000, 'max_count' => 1]]),
    ]);
    $orderCouponId = (int)Db::name('store_coupon_user')->insertGetId([
        'cid' => $orderIssueId, 'uid' => $uid, 'coupon_title' => '本地整单门槛券',
        'coupon_price' => '66.00', 'use_min_price' => '0.00', 'add_time' => $now,
        'start_time' => $now - 60, 'end_time' => $now + 86400,
        'use_time' => 0, 'type' => 'send', 'status' => 0, 'is_fail' => 0,
    ]);
    $orderCouponInput = $input;
    $orderCouponInput['coupon_id'] = $orderCouponId;
    $orderCouponInput['use_integral'] = 0;
    $orderCouponInput['idempotency_key'] = 'round6-order-coupon-' . bin2hex(random_bytes(8));
    $orderCouponQuote = $service->quote($uid, $orderCouponInput);
    ok((int)$orderCouponQuote['couponDiscountCents'] === 6600,
        'order-threshold coupon must be quoted through the shared rule');
    $orderCouponInput['fingerprint'] = $orderCouponQuote['fingerprint'];
    $orderCouponOrder = $service->create($uid, $orderCouponInput, 'h5');
    $service->paid($orderCouponOrder['id'], (int)$orderCouponQuote['amountCents'],
        'ROUND6-ORDER-COUPON', 'wechat');
    $orderCouponCommerce = Db::name('member_commerce_order')
        ->where('public_id', $orderCouponOrder['id'])->find();
    $orderCouponFact = Db::name('cashier_v3_checkout_order_coupon')
        ->where('coupon_user_id', $orderCouponId)->find();
    ok($orderCouponFact && $orderCouponFact['status'] === 'consumed'
        && $orderCouponFact['order_id'] === $orderCouponCommerce['sales_order_id']
        && (string)$orderCouponFact['order_line_id'] !== '',
        'order-threshold coupon must be consumed by cashier V3 and bound to its sales line');
    $orderCouponBenefit = $benefits->prepareVoidInTx((string)$orderCouponCommerce['sales_order_id'], $uid);
    $benefits->restoreVoidInTx($orderCouponBenefit, (string)$orderCouponCommerce['sales_order_id'], $uid);
    ok(Db::name(MemberCommerceBenefitsServices::TABLE)->where('id', $orderCouponBenefit['id'])
        ->value('status') === 'refunded',
        'coupon-only full void must advance the member benefit audit state');

    $recharge = $service->quote($uid, ['store_id' => $storeId, 'kind' => 'recharge',
        'price' => '33', 'coupon_id' => $couponId, 'use_integral' => 1]);
    ok((int)$recharge['amountCents'] === 3300 && !(bool)$recharge['integralEnabled']
        && $recharge['coupons'] === [], 'recharge must retain its original no-benefit flow');

    echo "PASS round6 member coupon and integral settlement\n";
} catch (Throwable $e) {
    $failed = true;
    $detail = method_exists($e, 'getDetail') ? json_encode($e->getDetail(), JSON_UNESCAPED_UNICODE) : '';
    echo 'FAIL ' . get_class($e) . ' ' . $e->getMessage() . ' ' . $detail . ' ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    Db::rollback();
    SystemConfigService::clear();
}
if ($failed) exit(1);

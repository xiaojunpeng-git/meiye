<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/** Final-transaction CAS consumption of coupons frozen on checkout lines. */
final class CashierV3CheckoutCouponSettlementServices
{
    /** @return array<string,mixed> */
    public function consumeInTx(
        CashierV3SalesOrderPlanV1 $plan,
        array $salesResult,
        CashierV3DataScopeContext $scope,
        int $now
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutCoupon.consume');
        $orderId = (string)($salesResult['orderId'] ?? '');
        if ($orderId === '' || $orderId !== (string)$plan->header()['order_id']) {
            throw self::failure('checkout_coupon_sales_order_mismatch');
        }
        $consumed = [];
        foreach ($plan->lines() as $line) {
            $couponId = (int)($line['coupon_user_id'] ?? 0);
            $discount = (int)($line['coupon_discount_cents'] ?? 0);
            if ($couponId === 0) continue;
            if (isset($consumed[$couponId])) throw self::failure('checkout_coupon_duplicate');
            $coupon = (array)Db::name('store_coupon_user')->where('id', $couponId)->lock(true)->find();
            if (!$coupon
                || (int)($coupon['uid'] ?? 0) !== (int)$plan->header()['member_id']
                || (int)($coupon['status'] ?? -1) !== 0
                || (int)($coupon['is_fail'] ?? -1) !== 0
                || (int)($coupon['use_time'] ?? -1) !== 0
                || (int)($coupon['start_time'] ?? 0) > $now
                || ((int)($coupon['end_time'] ?? 0) > 0 && (int)$coupon['end_time'] < $now)
                || trim((string)($coupon['coupon_title'] ?? '')) !== trim((string)$line['coupon_name_snapshot'])
                || $this->moneyCents($coupon['use_min_price'] ?? '0') > (int)$line['original_amount_cents']
                || $discount <= 0
                || $discount !== (int)($line['discount_amount_cents'] ?? -1)) {
                throw self::failure('checkout_coupon_frozen_authority_changed');
            }
            $updated = Db::name('store_coupon_user')->where('id', $couponId)
                ->where('uid', (int)$plan->header()['member_id'])
                ->where('status', 0)->where('is_fail', 0)->where('use_time', 0)
                ->update(['status' => 1, 'use_time' => $now]);
            if ((int)$updated !== 1) throw self::failure('checkout_coupon_consume_race');
            $consumed[$couponId] = [
                'couponUserId' => $couponId,
                'couponName' => (string)$line['coupon_name_snapshot'],
                'couponDiscountCents' => $discount,
                'salesOrderId' => $orderId,
                'salesOrderLineId' => (string)$line['order_line_id'],
            ];
        }
        return ['consumedCount' => count($consumed), 'coupons' => array_values($consumed)];
    }

    private function moneyCents($value): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            throw self::failure('checkout_coupon_money_invalid');
        }
        [$whole, $decimal] = array_pad(explode('.', $raw, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($decimal, 2, '0');
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_VERSION_CONFLICT,
            '优惠券状态已经变化，请重新选择后结账。',
            CashierV3ResultCode::STATUS_CONFLICT,
            ['reason' => $reason]
        );
    }
}

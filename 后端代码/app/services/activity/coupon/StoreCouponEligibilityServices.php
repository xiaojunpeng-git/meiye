<?php
declare(strict_types=1);
namespace app\services\activity\coupon;

use think\facade\Db;
use think\exception\ValidateException;

/** Authority for issuance identity and recipient eligibility, shared by all coupon entry points. */
final class StoreCouponEligibilityServices
{
    /** Freeze the business store once. Unknown store stays unknown rather than widening scope. */
    public function issuance(int $uid, int $businessStoreId = 0, bool $selfClaim = false): array
    {
        $storeId = $selfClaim
            ? (int)Db::name('user')->where('uid', $uid)->value('belong_store_id')
            : $businessStoreId;
        return ['original_recipient_uid' => $uid, 'issuance_store_id' => max(0, $storeId)];
    }

    /** Serialize first-use checks with other new-customer coupon checkouts. */
    public function lockMember(int $uid): void
    {
        if ($uid > 0) Db::name('user')->where('uid', $uid)->lock(true)->find();
    }

    /**
     * Consumption is instance-wide, not current-store-only. Forward facts remain
     * history after refund/void. Exclude this checkout's own newly persisted sale.
     * Legacy paid service suborders also count as consumption; pure recharge does not.
     * Recharge and gift projections are not purchases. Locking reads avoid stale
     * repeatable-read snapshots after waiting for the member lock.
     */
    public function hasConsumed(int $uid, string $excludeOrderId = '', bool $lock = false): bool
    {
        $sales = Db::name('cashier_v3_sales_order')->where('member_id', $uid)->where('order_direction', 'forward');
        if ($excludeOrderId !== '') $sales->where('order_id', '<>', $excludeOrderId);
        if ($lock) $sales->lock(true);
        if ($sales->field('id')->find()) return true;
        $services = Db::name('cashier_v3_entitlement_service_fact')->where('member_id', $uid);
        if ($excludeOrderId !== '') {
            $request = Db::name('cashier_v3_sales_order')->where('order_id', $excludeOrderId)->value('checkout_request_id');
            if ($request) $services->where('checkout_request_id', '<>', $request);
        }
        if ($lock) $services->lock(true);
        if ($services->field('id')->find()) return true;
        $legacy = Db::name('store_order')->where('uid', $uid)->where('paid', 1)->whereIn('order_type', [0, 2])
            ->whereNotIn('channel_type', ['cashier_v3', 'cashier_v3_recharge_gift', 'cashier_v3_direct_gift'])
            ->where('type', 'not in', [7, 8]);
        if ($lock) $legacy->lock(true);
        return (bool)$legacy->field('id')->find();
    }

    /** Read-only selection and final transaction use exactly the same predicates. */
    public function reasons(array $coupon, int $uid, int $storeId, string $excludeOrderId = '', bool $lock = false): array
    {
        if ($lock && (int)($coupon['new_user_only'] ?? 0) === 1) $this->lockMember($uid);
        return StoreCouponEligibilityRule::reasons($coupon, $uid, $storeId,
            (int)($coupon['new_user_only'] ?? 0) === 1 && $this->hasConsumed($uid, $excludeOrderId, $lock));
    }

    /** Reject at the server boundary even if the browser sends a hidden/disabled coupon ID. */
    public function assertUsable(array $coupon, int $uid, int $storeId, string $excludeOrderId = ''): void
    {
        $reasons = $this->reasons($coupon, $uid, $storeId, $excludeOrderId, true);
        if ($reasons) throw new ValidateException(implode('；', $reasons));
    }
}

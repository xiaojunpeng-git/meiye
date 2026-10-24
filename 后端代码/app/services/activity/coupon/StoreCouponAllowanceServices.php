<?php
declare(strict_types=1);

namespace app\services\activity\coupon;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Independent non-cash allowance. All writes share the checkout/void transaction. */
final class StoreCouponAllowanceServices
{
    public const TYPE = 3;
    public const ACCOUNT = 'coupon_allowance_account';
    public const MOVEMENT = 'coupon_allowance_movement';

    /** Read-only lazy initialization: browsing coupons must never write accounts. */
    public function remainingByCouponIds(array $ids): array
    {
        if (!$ids) return [];
        return Db::name(self::ACCOUNT)->whereIn('coupon_user_id', $ids)
            ->column('remaining_cents', 'coupon_user_id');
    }

    /** New allowance amounts are whole yuan even though the ledger stores cents. */
    public static function wholeCents($value): int
    {
        $raw = (string)$value;
        if (preg_match('/^[1-9][0-9]{0,8}(?:\.0{1,2})?$/D', $raw) !== 1) {
            throw self::failure('多次抵用券总额度必须是正整数元。');
        }
        return (int)$raw * 100;
    }

    /** Validate scope as well as balance; a stale or forged draft grants no rights. */
    public function consumeInTx(int $couponId, int $amount, array $identity, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('couponAllowance.consume');
        $coupon = (array)Db::name('store_coupon_user')->where('id', $couponId)->lock(true)->find();
        $issue = (array)Db::name('store_coupon_issue')->where('id', (int)($coupon['cid'] ?? 0))->find();
        if (!$coupon || (int)($issue['coupon_type'] ?? 0) !== self::TYPE
            || (int)($issue['use_mode'] ?? 0) !== StoreCouponOrderRule::DETAIL
            || (int)$coupon['uid'] !== (int)$identity['member_id']
            || (int)$coupon['status'] !== 0 || (int)$coupon['is_fail'] !== 0
            || (int)$coupon['start_time'] > $now
            || ((int)$coupon['end_time'] > 0 && (int)$coupon['end_time'] < $now)
            || !$this->allowsStore($issue, (int)$identity['store_id'])
            || !$this->allowsProduct($issue, (int)($identity['product_id'] ?? 0))) {
            throw self::failure('该多次抵用券当前不可使用，请重新选择。');
        }
        $total = self::wholeCents($coupon['coupon_price']);
        $account = (array)Db::name(self::ACCOUNT)->where('coupon_user_id', $couponId)->lock(true)->find();
        // The claim row lock serializes first use, subsequent uses, and reversal.
        if (!$account) {
            Db::name(self::ACCOUNT)->insert(['coupon_user_id' => $couponId, 'member_id' => (int)$coupon['uid'],
                'total_cents' => $total, 'remaining_cents' => $total, 'version' => 1]);
            $account = ['member_id' => (int)$coupon['uid'], 'total_cents' => $total,
                'remaining_cents' => $total, 'version' => 1];
        }
        if ((int)$account['member_id'] !== (int)$coupon['uid'] || (int)$account['total_cents'] !== $total) {
            throw self::failure('多次抵用券额度来源不一致，请核对后重试。');
        }
        $after = self::afterUse((int)$account['remaining_cents'], $amount);
        $this->writeMovement($couponId, $account, $identity, -$amount, $after, null, $now);
        Db::name('store_coupon_user')->where('id', $couponId)
            ->update(['status' => $after === 0 ? 1 : 0, 'use_time' => $now]);
    }

    /** Integer conservation shared with tests; never clamp an excessive command. */
    public static function afterUse(int $remaining, int $amount): int
    {
        if ($remaining < 0 || $remaining % 100 !== 0 || $amount <= 0 || $amount % 100 !== 0 || $amount > $remaining) {
            throw self::failure('本次抵用金额须为正整数元，且不能超过券剩余额度。');
        }
        return $remaining - $amount;
    }

    /** Whole-order void restores the exact original debit, not the coupon face. */
    public function restoreInTx(int $couponId, int $amount, array $identity, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('couponAllowance.restore');
        $coupon = (array)Db::name('store_coupon_user')->where('id', $couponId)->lock(true)->find();
        $account = (array)Db::name(self::ACCOUNT)->where('coupon_user_id', $couponId)->lock(true)->find();
        $debit = (array)Db::name(self::MOVEMENT)->where('coupon_user_id', $couponId)
            ->where('tenant_id', (string)$identity['tenant_id'])->where('order_id', (string)$identity['order_id'])
            ->where('order_line_id', (string)$identity['order_line_id'])->where('delta_cents', '<', 0)
            ->lock(true)->find();
        if (!$coupon || !$account || !$debit || (int)$coupon['uid'] !== (int)$identity['member_id']
            || (int)$account['member_id'] !== (int)$identity['member_id']
            || (int)$debit['member_id'] !== (int)$identity['member_id']
            || (int)$debit['store_id'] !== (int)$identity['store_id']
            || (int)$debit['delta_cents'] !== -$amount || $amount <= 0 || $amount % 100 !== 0
            || Db::name(self::MOVEMENT)->where('reversal_of', (int)$debit['id'])->find()) {
            throw self::failure('多次抵用券原始使用流水不一致，不能重复恢复额度。');
        }
        $after = (int)$account['remaining_cents'] + $amount;
        if ($after > (int)$account['total_cents']) throw self::failure('多次抵用券恢复额度超过原始总额。');
        $this->writeMovement($couponId, $account, $identity, $amount, $after, (int)$debit['id'], $now);
        // Expiry/revocation flags and dates are intentionally not renewed by void.
        // Keep a use timestamp: generic legacy transfer/recover paths must not revive it.
        Db::name('store_coupon_user')->where('id', $couponId)->update([
            'status' => (int)$coupon['status'] > 1 ? (int)$coupon['status'] : 0, 'use_time' => $now]);
    }

    /** Version CAS plus immutable event identity protects rollback and duplicate delivery. */
    private function writeMovement(int $couponId, array $account, array $identity, int $delta,
        int $after, ?int $reversalOf, int $now): void
    {
        foreach (['tenant_id', 'order_id', 'order_line_id'] as $field) {
            if (!isset($identity[$field]) || (string)$identity[$field] === '') throw self::failure('多次抵用券缺少结账来源，操作已回滚。');
        }
        $key = hash('sha256', json_encode([$identity['tenant_id'], $identity['order_id'],
            $identity['order_line_id'], $reversalOf === null ? 'use' : 'void']));
        if (Db::name(self::MOVEMENT)->where('coupon_user_id', $couponId)->where('event_key', $key)->find()) {
            throw self::failure('多次抵用券流水已存在，请刷新订单核对结果。');
        }
        $updated = Db::name(self::ACCOUNT)->where('coupon_user_id', $couponId)
            ->where('version', (int)$account['version'])->where('remaining_cents', (int)$account['remaining_cents'])
            ->update(['remaining_cents' => $after, 'version' => (int)$account['version'] + 1]);
        if ((int)$updated !== 1) throw self::failure('多次抵用券额度已经变化，请重新选择。');
        Db::name(self::MOVEMENT)->insert(['coupon_user_id' => $couponId, 'member_id' => (int)$account['member_id'],
            'tenant_id' => (string)$identity['tenant_id'], 'store_id' => (int)$identity['store_id'],
            'order_id' => (string)$identity['order_id'], 'order_line_id' => (string)$identity['order_line_id'],
            'operation_id' => (string)($identity['operation_id'] ?? ''), 'event_key' => $key,
            'delta_cents' => $delta, 'before_cents' => (int)$account['remaining_cents'], 'after_cents' => $after,
            'reversal_of' => $reversalOf, 'created_at' => $now]);
    }

    /** Store restrictions remain authoritative even when the browser reuses a stale selector. */
    private function allowsStore(array $issue, int $storeId): bool
    {
        if ((int)($issue['coupon_issue_type'] ?? 0) === 1 && (int)($issue['relation_id'] ?? 0) !== $storeId) return false;
        $type = (int)($issue['applicable_type'] ?? 1);
        if ($type === 0) return false;
        if ($type !== 2) return true;
        $raw = trim((string)($issue['applicable_store_id'] ?? ''));
        $ids = json_decode($raw, true);
        if (!is_array($ids)) $ids = preg_split('/\s*,\s*/', trim($raw, "[] \t\n\r\0\x0B"));
        return in_array($storeId, array_map('intval', $ids ?: []), true);
    }

    /** Match the canonical product (including a store copy's platform parent).
     * Selector identity is only a hint; final checkout supplies its verified item.
     */
    public function allowsProduct(array $issue, int $productId): bool
    {
        $type = (int)($issue['type'] ?? 0);
        if ($type === 0) return true;
        if ($productId <= 0) return false;
        $product = (array)Db::name('store_product')->where('id', $productId)
            ->field('id,pid,cate_id,store_cate_id,brand_id')->find();
        if (!$product) return false;
        if ($type === 2) {
            $ids = array_map('intval', explode(',', (string)($issue['product_id'] ?? '')));
            return in_array($productId, $ids, true)
                || ((int)$product['pid'] > 0 && in_array((int)$product['pid'], $ids, true));
        }
        if ($type === 1) {
            $root = (int)($issue['category_id'] ?? 0);
            if ($root <= 0) return false;
            $rows = app()->make(\app\services\product\category\StoreProductCategoryServices::class)->getAllById($root);
            $ids = array_merge([$root], array_map('intval', array_column($rows, 'id')));
            return (bool)array_intersect($ids, array_map('intval', explode(',',
                (string)$product['cate_id'] . ',' . (string)$product['store_cate_id'])));
        }
        if ($type === 3) {
            $root = (int)($issue['brand_id'] ?? 0);
            if ($root <= 0) return false;
            $rows = app()->make(\app\services\product\brand\StoreBrandServices::class)->getAllById($root);
            return in_array((int)$product['brand_id'], array_merge([$root], array_map('intval', array_column($rows, 'id'))), true);
        }
        return false;
    }

    private static function failure(string $message): CashierV3CommandException
    {
        return CashierV3CommandException::versionConflict($message, ['reason' => 'coupon_allowance_authority_changed']);
    }
}

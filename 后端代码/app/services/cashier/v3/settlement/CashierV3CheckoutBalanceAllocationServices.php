<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/**
 * Freeze how new-order balance tender belongs to sale lines. Coupon tender
 * belongs to its chosen line first; balance uses each remaining collectible
 * line amount. The final line takes the tail up to capacity, then stable
 * earlier lines take any excess so no refundable line becomes negative.
 */
final class CashierV3CheckoutBalanceAllocationServices
{
    public const TABLE = 'cashier_v3_order_balance_allocation';

    public function freezeInTx(CashierV3SalesOrderPlanV1 $plan, int $balanceCents, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutBalanceAllocation.freeze');
        if ($balanceCents === 0) return;
        $header = $plan->header();
        $lines = array_values($plan->lines());
        $weights = [];
        foreach ($lines as $line) {
            $weight = (int)$line['sale_amount_cents'] - (int)$line['debt_amount_cents']
                - (int)$line['coupon_payment_cents'];
            if ($weight < 0) throw new \RuntimeException('balance_line_capacity_invalid');
            $weights[] = $weight;
        }
        $shares = self::allocateShares($weights, $balanceCents);
        foreach ($lines as $index => $line) {
            $identity = ['tenant_id' => (string)$header['tenant_id'],
                'order_line_id' => (string)$line['order_line_id']];
            $existing = (array)Db::name(self::TABLE)->where($identity)->lock(true)->find();
            if ($existing) {
                if ((int)$existing['amount_cents'] !== $shares[$index]
                    || (string)$existing['order_id'] !== (string)$header['order_id']) {
                    throw new \RuntimeException('balance_allocation_replay_conflict');
                }
                continue;
            }
            Db::name(self::TABLE)->insert($identity + [
                'store_id' => (int)$header['store_id'], 'member_id' => (int)$header['member_id'],
                'request_id' => (string)$header['checkout_request_id'],
                'order_id' => (string)$header['order_id'], 'amount_cents' => $shares[$index],
                'created_at' => $now,
            ]);
        }
    }

    /** Allocate exact cents in stable sale-line order without exceeding any line's collectible capacity. */
    public static function allocateShares(array $weights, int $balanceCents): array
    {
        $total = array_sum($weights);
        if ($balanceCents < 0 || $total <= 0 || $balanceCents > $total
            || array_filter($weights, static function ($weight): bool { return !is_int($weight) || $weight < 0; })) {
            throw new \RuntimeException('balance_allocation_total_invalid');
        }
        $shares = [];
        $allocated = 0;
        foreach ($weights as $index => $weight) {
            $shares[$index] = (int)bcdiv(bcmul((string)$balanceCents, (string)$weight, 0), (string)$total, 0);
            $allocated += $shares[$index];
        }
        // The ordinary tail belongs to the stable last line. Capacity-aware
        // backward overflow is the product-approved exception for many small
        // lines, where naive floor-and-tail can exceed that line's value.
        $tail = $balanceCents - $allocated;
        for ($index = count($weights) - 1; $index >= 0 && $tail > 0; $index--) {
            $addition = min($tail, $weights[$index] - $shares[$index]);
            $shares[$index] += $addition;
            $tail -= $addition;
        }
        if ($tail !== 0 || array_sum($shares) !== $balanceCents) {
            throw new \RuntimeException('balance_allocation_tail_invalid');
        }
        return $shares;
    }
}

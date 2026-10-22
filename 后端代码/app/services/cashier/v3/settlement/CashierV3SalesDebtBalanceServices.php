<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Immutable sales-debt tender shares. No wallet writes or cash facts are inferred here. */
final class CashierV3SalesDebtBalanceServices
{
    public const TABLE = 'cashier_v3_debt_repayment_item_settlement';
    public const METHOD = 'member_balance';

    /** Collection rows describe settlement, while payment facts describe new cash only. */
    public static function balanceCents(string $repaymentId): int
    {
        return (int)Db::name('cashier_v3_debt_repayment_collection')->where('repayment_id', $repaymentId)
            ->where('payment_method', self::METHOD)->where('collection_status', 'succeeded')->sum('amount_cents');
    }

    /** Batched reporting lookup avoids querying each repayment while preserving legacy zero-balance rows. */
    public static function balancesByIds(array $repaymentIds): array
    {
        if ($repaymentIds === []) return [];
        $rows = Db::name('cashier_v3_debt_repayment_collection')->whereIn('repayment_id', $repaymentIds)
            ->where('payment_method', self::METHOD)->where('collection_status', 'succeeded')
            ->fieldRaw('repayment_id,SUM(amount_cents) amount_cents')->group('repayment_id')->select()->toArray();
        return array_map('intval', array_column($rows, 'amount_cents', 'repayment_id'));
    }

    /** Largest-remainder whole-yuan allocation, stable by input order and exactly conserving total. */
    public static function allocate(int $cents, array $weights): array
    {
        if ($cents < 0 || $cents % 100 !== 0 || !$weights || min($weights) < 0 || array_sum($weights) <= 0) {
            throw new \InvalidArgumentException('sales_debt_whole_yuan_allocation_invalid');
        }
        $total = array_sum($weights); $yuan = intdiv($cents, 100); $out = []; $remainders = [];
        $positions = array_flip(array_keys($weights));
        foreach ($weights as $key => $weight) {
            // Money limits can overflow native multiplication; BCMath keeps weights exact.
            $product = bcmul((string)$yuan, (string)$weight, 0);
            $out[$key] = (int)bcdiv($product, (string)$total, 0) * 100;
            $remainders[$key] = (int)bcmod($product, (string)$total);
        }
        $keys = array_keys($weights);
        usort($keys, static fn($a, $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($positions[$a] <=> $positions[$b]));
        $left = intdiv($cents - array_sum($out), 100);
        for ($i = 0; $i < $left; $i++) $out[$keys[$i]] += 100;
        return $out;
    }

    /** Freeze the exact increment per debt item; future void never reapportions other repayments. */
    public static function freezeInTx(string $tenantId, string $repaymentId, array $debt, array $items, array $targets, array $personnel, int $balanceCents): array
    {
        CashierV3TransactionGuard::assertInTransaction('salesDebtBalance.freeze');
        $weights = [];
        foreach ($items as $item) {
            $before = (int)bcmul((string)$item['repaid_debt'], '100', 0);
            $delta = (int)$targets[(int)$item['id']] - $before;
            if ($delta < 0 || $delta % 100 !== 0) throw new \RuntimeException('sales_debt_item_non_integer_increment');
            $weights[(int)$item['id']] = $delta;
        }
        $balances = self::allocate($balanceCents, $weights);
        $issuedComponents = (new \app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices())
            ->issuedCardComponentsForDebtItems($items);
        $cashAmounts = [];
        foreach ($items as $item) {
            $id = (int)$item['id'];
            if ($balances[$id] > $weights[$id]) throw new \RuntimeException('sales_debt_balance_item_exceeds_settlement');
            $lineId = (string)($personnel[$id]['authority']['order_line_id'] ?? '');
            $legacyOrderId = (int)$debt['order_id'];
            if ($balances[$id] > 0 && $lineId === '' && (int)$item['product_type'] === 5) {
                // Imported debt IDs may point at a placeholder order rather than the issued card.
                // Require the existing exact issuance mapping so later refunds cannot cash out this tender.
                $orders = array_values(array_unique(array_column($issuedComponents[$id] ?? [], 'oid')));
                if (count($orders) !== 1 || (int)$orders[0] <= 0) throw new \RuntimeException('sales_debt_balance_card_origin_unresolved');
                $legacyOrderId = (int)$orders[0];
            }
            $inserted = Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId, 'repayment_id' => $repaymentId, 'debt_item_id' => $id,
                'order_line_id' => $lineId,
                'legacy_order_id' => $legacyOrderId, 'cart_info_id' => (int)($item['cart_info_id'] ?? 0),
                'amount_cents' => $weights[$id], 'balance_amount_cents' => $balances[$id],
            ]);
            if ((int)$inserted !== 1) throw new \RuntimeException('sales_debt_item_settlement_write_failed');
            $cashAmounts[$id] = $weights[$id] - $balances[$id];
        }
        return $cashAmounts;
    }

    /** Only active repayments reduce cash refundability; voided rows stay immutable audit evidence. */
    public static function nonCashForSource(string $tenantId, string $lineId, int $legacyOrderId = 0): int
    {
        if ($lineId === '' && $legacyOrderId <= 0) return 0;
        $q = Db::name(self::TABLE)->alias('s')->join('cashier_v3_debt_repayment r', 'r.repayment_id=s.repayment_id AND r.tenant_id=s.tenant_id')
            ->where('s.tenant_id', $tenantId)->where('r.status', 'succeeded');
        $q->where(static function ($query) use ($lineId, $legacyOrderId): void {
            if ($lineId !== '') $query->where('s.order_line_id', $lineId);
            if ($legacyOrderId > 0) $query->whereOr(static function ($legacy) use ($legacyOrderId): void {
                $legacy->where('s.order_line_id', '')->where('s.legacy_order_id', $legacyOrderId);
            });
        });
        return (int)$q->sum('s.balance_amount_cents');
    }
}

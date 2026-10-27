<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;

/** Pure member/report read arithmetic. Historical cents remain observable,
 * never become a writeoff/refund authority or a new snapshot/version marker.
 * Formal sale/rule amounts keep their existing priority and whole-yuan path.
 */
final class CashierV3CardReadAmountServices
{
    /** Read one persisted component's remaining value; do not mutate any input.
     * The caller still applies actual card debt/shared-quota guards afterwards.
     * This method neither queries a database nor authorizes monetary writes.
     */
    public function remainingForComponent(
        array $cart,
        array $snapshot,
        array $saleAllocations,
        ?array $ruleAuthority,
        int $totalTimes,
        int $remainingTimes
    ): string {
        $legacy = is_array($snapshot['rh_source'] ?? null) ? $snapshot['rh_source'] : [];
        $raw = array_key_exists('source_line_paid_amount', $legacy)
            ? $legacy['source_line_paid_amount'] : ($cart['pay_price'] ?? '0');
        $hasSale = isset($saleAllocations[(int)($cart['id'] ?? 0)]);
        $hasRule = !CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($snapshot)
            && is_array($ruleAuthority);
        // Only a legacy amount with no higher-priority formal authority takes
        // this read-only cent path. Malformed/negative values never become zero.
        if (!$hasSale && !$hasRule) {
            $cents = self::historicalCents($raw);
            if ($cents % 100 !== 0) {
                return self::remainingHistoricalCents($cents, $totalTimes, $remainingTimes, $snapshot);
            }
        }
        $authority = (new CashierV3CardComponentPurchaseAmountAuthority())->resolve(
            $cart, $snapshot, $saleAllocations, $ruleAuthority
        );
        $allocation = $authority['allocationSnapshot'];
        // Preserve the same current refund state as the established read path;
        // it is not part of the immutable original purchase amount authority.
        $allocation['r26RefundedCents'] = (int)($snapshot['r26RefundedCents'] ?? 0);
        $allocation['r26RefundRemovedTimes'] = (int)($snapshot['r26RefundRemovedTimes'] ?? 0);
        return CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
            self::money((int)$authority['purchaseAmountCents']),
            $totalTimes, $totalTimes - $remainingTimes, $allocation
        );
    }

    /** Exact stored money only: no floats, scientific notation or silent
     * rounding. Bounds prevent integer overflow before converting to cents.
     */
    private static function historicalCents($raw): int
    {
        if (!is_string($raw) && !is_int($raw)) throw new \InvalidArgumentException('historical card amount must be exact money');
        $raw = trim((string)$raw);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw))
            throw new \InvalidArgumentException('historical card amount is invalid');
        $cents = bcmul($raw, '100', 0);
        if (bccomp($cents, (string)PHP_INT_MAX, 0) > 0) throw new \InvalidArgumentException('historical card amount exceeds range');
        return (int)$cents;
    }

    /** Refund-removed uses are not service consumption. Allocate in integer
     * cents with the existing final-use remainder rule, then subtract refunds.
     * This is observation of old money, never normalization of a new write.
     */
    private static function remainingHistoricalCents(int $cents, int $total, int $remaining, array $snapshot): string
    {
        if ($total <= 0 || $remaining < 0 || $remaining > $total)
            throw new \InvalidArgumentException('historical card quota is invalid');
        $removed = $snapshot['r26RefundRemovedTimes'] ?? 0;
        $refunded = $snapshot['r26RefundedCents'] ?? 0;
        $reportedConsumed = $total - $remaining;
        if (!is_int($removed) || $removed < 0 || $removed > $reportedConsumed
            || !is_int($refunded) || $refunded < 0)
            throw new \InvalidArgumentException('historical card refund or quota is invalid');
        $amount = CashierV3EntitlementActualAmountAllocator::remainingCents(
            self::money($cents), $total, $reportedConsumed - $removed
        );
        $afterRefund = bcsub($amount, self::money($refunded), 2);
        if (bccomp($afterRefund, '0', 2) < 0) throw new \InvalidArgumentException('refund exceeds historical card value');
        return $afterRefund;
    }

    private static function money(int $cents): string
    {
        if ($cents < 0) throw new \InvalidArgumentException('negative read authority amount');
        return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

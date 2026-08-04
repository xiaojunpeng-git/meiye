<?php

namespace app\services\cashier\v3\cashier;

/**
 * 权益项目“实际购买金额”的分摊器。
 *
 * 这里处理的是购买金额按权益次数的展示／草稿分摊，不是核销后台计价。
 * 使用累计四舍五入差额，保证全部次数的分摊金额严格回到原购买金额。
 */
final class CashierV3EntitlementActualAmountAllocator
{
    public const CALCULATION_VERSION = 'cumulative-half-up-cent-v2';

    public static function allocate(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity
    ): string {
        self::assertInputs($purchaseAmount, $totalPurchaseTimes, $consumedTimes, $quantity);
        $start = self::cumulativeCents($purchaseAmount, $totalPurchaseTimes, $consumedTimes);
        $end = self::cumulativeCents(
            $purchaseAmount,
            $totalPurchaseTimes,
            $consumedTimes + $quantity
        );
        return self::centsToMoney(bcsub($end, $start, 0));
    }

    public static function remaining(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes
    ): string {
        return self::allocate(
            $purchaseAmount,
            $totalPurchaseTimes,
            $consumedTimes,
            $totalPurchaseTimes - $consumedTimes
        );
    }

    private static function cumulativeCents(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $completedTimes
    ): string {
        if ($completedTimes <= 0) {
            return '0';
        }
        $totalCents = self::moneyToCents($purchaseAmount);
        if ($completedTimes >= $totalPurchaseTimes) {
            return $totalCents;
        }
        $divisor = (string)$totalPurchaseTimes;
        $numerator = bcmul($totalCents, (string)$completedTimes, 0);
        $whole = bcdiv($numerator, $divisor, 0);
        $remainder = bcmod($numerator, $divisor);
        if (bccomp(bcmul($remainder, '2', 0), $divisor, 0) >= 0) {
            $whole = bcadd($whole, '1', 0);
        }
        return $whole;
    }

    private static function moneyToCents(string $amount): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $amount) !== 1) {
            throw new \InvalidArgumentException('purchase amount must be nonnegative money');
        }
        $parts = explode('.', $amount, 2);
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        $cents = ltrim($parts[0] . $fraction, '0');
        return $cents === '' ? '0' : $cents;
    }

    private static function centsToMoney(string $cents): string
    {
        if (preg_match('/^[0-9]+$/', $cents) !== 1) {
            throw new \InvalidArgumentException('allocated cents must be nonnegative');
        }
        $normalized = ltrim($cents, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $padded = str_pad($normalized, 3, '0', STR_PAD_LEFT);
        return substr($padded, 0, -2) . '.' . substr($padded, -2);
    }

    private static function assertInputs(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity
    ): void {
        self::moneyToCents($purchaseAmount);
        if ($totalPurchaseTimes <= 0
            || $consumedTimes < 0
            || $quantity < 0
            || $consumedTimes > $totalPurchaseTimes
            || $quantity > $totalPurchaseTimes - $consumedTimes) {
            throw new \InvalidArgumentException('entitlement allocation times are invalid');
        }
    }
}

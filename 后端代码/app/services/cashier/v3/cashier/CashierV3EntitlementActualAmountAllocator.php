<?php

namespace app\services\cashier\v3\cashier;

/**
 * 权益项目“实际购买金额”的分摊器。
 *
 * 这里处理的是购买金额按权益次数的展示／草稿分摊，不是核销后台计价。
 * 购买金额必须是整数元。前 N-1 次按整除向下取整，最后一次承担尾差，
 * 保证全部次数严格回到原购买金额且收银链路不产生小数金额。
 */
final class CashierV3EntitlementActualAmountAllocator
{
    public const CALCULATION_VERSION = 'whole-yuan-floor-final-remainder-v1';

    public static function allocate(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity
    ): string {
        self::assertInputs($purchaseAmount, $totalPurchaseTimes, $consumedTimes, $quantity);
        $start = self::cumulativeYuan($purchaseAmount, $totalPurchaseTimes, $consumedTimes);
        $end = self::cumulativeYuan(
            $purchaseAmount,
            $totalPurchaseTimes,
            $consumedTimes + $quantity
        );
        return self::yuanToMoney(bcsub($end, $start, 0));
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

    private static function cumulativeYuan(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $completedTimes
    ): string {
        if ($completedTimes <= 0) {
            return '0';
        }
        $totalYuan = self::moneyToWholeYuan($purchaseAmount);
        if ($completedTimes >= $totalPurchaseTimes) {
            return $totalYuan;
        }
        $regularAmount = bcdiv($totalYuan, (string)$totalPurchaseTimes, 0);
        return bcmul($regularAmount, (string)$completedTimes, 0);
    }

    private static function moneyToWholeYuan(string $amount): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.0{1,2})?$/D', $amount) !== 1) {
            throw new \InvalidArgumentException('purchase amount must be nonnegative whole yuan');
        }
        $whole = ltrim(explode('.', $amount, 2)[0], '0');
        return $whole === '' ? '0' : $whole;
    }

    private static function yuanToMoney(string $yuan): string
    {
        if (preg_match('/^[0-9]+$/D', $yuan) !== 1) {
            throw new \InvalidArgumentException('allocated yuan must be nonnegative');
        }
        $normalized = ltrim($yuan, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        return $normalized . '.00';
    }

    private static function assertInputs(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity
    ): void {
        self::moneyToWholeYuan($purchaseAmount);
        if ($totalPurchaseTimes <= 0
            || $consumedTimes < 0
            || $quantity < 0
            || $consumedTimes > $totalPurchaseTimes
            || $quantity > $totalPurchaseTimes - $consumedTimes) {
            throw new \InvalidArgumentException('entitlement allocation times are invalid');
        }
    }
}

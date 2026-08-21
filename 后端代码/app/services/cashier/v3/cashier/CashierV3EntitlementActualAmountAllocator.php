<?php

namespace app\services\cashier\v3\cashier;

/**
 * 权益项目“实际购买金额”的分摊器。
 *
 * 这里处理的是购买金额按权益次数的展示／草稿分摊，不是核销后台计价。
 * 普通历史卡项继续使用整元分摊；项目替换/升级生成的权益使用分级分摊。
 */
final class CashierV3EntitlementActualAmountAllocator
{
    public const CALCULATION_VERSION = 'whole-yuan-floor-final-remainder-v1';

    /** cart_info 中明确标记的操作生成权益才允许使用分级金额。 */
    private const CENT_CAPABLE_SOURCE_TYPES = [
        'cashier_v3_project_replacement',
        'cashier_v3_project_upgrade',
    ];

    public static function isCentCapableSnapshot(array $snapshot): bool
    {
        $sourceType = (string)($snapshot['sourceType'] ?? '');
        $version = (string)($snapshot['amountCalculationVersion'] ?? '');
        return in_array($sourceType, self::CENT_CAPABLE_SOURCE_TYPES, true)
            || str_starts_with($version, 'operation-cent-');
    }

    public static function allocateForSnapshot(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity,
        array $snapshot
    ): string {
        return self::isCentCapableSnapshot($snapshot)
            ? self::allocateCents($purchaseAmount, $totalPurchaseTimes, $consumedTimes, $quantity)
            : self::allocate($purchaseAmount, $totalPurchaseTimes, $consumedTimes, $quantity);
    }

    public static function remainingForSnapshot(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        array $snapshot
    ): string {
        return self::isCentCapableSnapshot($snapshot)
            ? self::remainingCents($purchaseAmount, $totalPurchaseTimes, $consumedTimes)
            : self::remaining($purchaseAmount, $totalPurchaseTimes, $consumedTimes);
    }

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

    public static function allocateCents(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity
    ): string {
        self::assertCentInputs($purchaseAmount, $totalPurchaseTimes, $consumedTimes, $quantity);
        $start = self::cumulativeCents($purchaseAmount, $totalPurchaseTimes, $consumedTimes);
        $end = self::cumulativeCents(
            $purchaseAmount,
            $totalPurchaseTimes,
            $consumedTimes + $quantity
        );
        return self::centsToMoney(bcsub($end, $start, 0));
    }

    public static function remainingCents(
        string $purchaseAmount,
        int $totalPurchaseTimes,
        int $consumedTimes
    ): string {
        return self::allocateCents(
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
        $regularAmount = bcdiv($totalCents, (string)$totalPurchaseTimes, 0);
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

    private static function moneyToCents(string $amount): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw new \InvalidArgumentException('purchase amount must be nonnegative money');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        return ltrim($whole . str_pad($fraction, 2, '0'), '0') ?: '0';
    }

    private static function centsToMoney(string $cents): string
    {
        if (preg_match('/^[0-9]+$/D', $cents) !== 1) {
            throw new \InvalidArgumentException('allocated cents must be nonnegative');
        }
        $normalized = ltrim($cents, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if (strlen($normalized) === 1) {
            return '0.0' . $normalized;
        }
        if (strlen($normalized) === 2) {
            return '0.' . $normalized;
        }
        return substr($normalized, 0, -2) . '.' . substr($normalized, -2);
    }

    private static function assertCentInputs(
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

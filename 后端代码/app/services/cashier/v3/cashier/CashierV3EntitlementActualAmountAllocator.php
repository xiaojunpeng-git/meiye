<?php

namespace app\services\cashier\v3\cashier;

/**
 * 权益项目“实际购买金额”的分摊器。
 *
 * 这里处理的是购买金额按权益次数的展示／草稿分摊，不是核销后台计价。
 * 普通卡项及新替换权益使用整元分摊；旧操作权益按冻结版本保留分级分摊。
 */
final class CashierV3EntitlementActualAmountAllocator
{
    public const CALCULATION_VERSION = 'whole-yuan-floor-final-remainder-v1';

    /** cart_info 中明确标记的操作生成权益才允许使用分级金额。 */
    private const CENT_CAPABLE_SOURCE_TYPES = [
        'cashier_v3_project_replacement',
        'cashier_v3_project_upgrade',
    ];

    /** 操作生成的项目金额独立于整卡共享次数池，与是否保留分精度无关。 */
    public static function usesIndependentAmountSnapshot(array $snapshot): bool
    {
        return in_array((string)($snapshot['sourceType'] ?? ''), self::CENT_CAPABLE_SOURCE_TYPES, true)
            || self::isCentCapableSnapshot($snapshot);
    }

    public static function isCentCapableSnapshot(array $snapshot): bool
    {
        // 新替换权益按整元分摊，尾差归末次；旧操作快照保持原金额口径，
        // 只在再次替换时由操作审计记录实际发生的不足一元扣减。
        if (($snapshot['amountCalculationVersion'] ?? '') === self::CALCULATION_VERSION) {
            return false;
        }
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

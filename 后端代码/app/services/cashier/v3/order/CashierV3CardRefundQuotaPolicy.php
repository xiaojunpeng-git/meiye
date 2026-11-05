<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

/**
 * 折算退款后的有限次数卡可用额度，不修改原销售及已核销事实。
 * 输入是原项目或整卡实际成交分、原总次数、累计服务已用次数和累计权益扣减分；
 * 权益扣减与手填退款现金分别记账，现金不能直接成为超过余值的权益扣减。
 * 服务已用次数不含此前退款移除的次数，否则连续部分退款会重复扣次。
 * 原单次分摊保持不变；凑不够一次的尾额不能核销，但卡未作废时仍可退款。
 */
final class CashierV3CardRefundQuotaPolicy
{
    /** 权益读侧统一取前后差额；手填退款现金和赠金可以超过权益，不能拿现金反推负权益。 */
    public const RIGHTS_ADJUSTMENT_FIELDS = 'remaining_before_cents,remaining_after_cents,closed_remaining_cents';

    /** 新扣减只保存整数元；历史不足一元的尾值留在原金额审计，不生成新的小数扣减。 */
    public static function rightsReductionCents(int $manualRefundCents, int $remainingCents): int
    {
        if ($manualRefundCents < 0 || $manualRefundCents % 100 !== 0 || $remainingCents < 0) {
            throw new \InvalidArgumentException('card refund rights reduction is invalid');
        }
        return min($manualRefundCents, intdiv($remainingCents, 100) * 100);
    }

    /** Read the persisted rights delta, excluding void-closed rights and manual cash. */
    public static function returnedRightsCents(array $adjustment): int
    {
        foreach (explode(',', self::RIGHTS_ADJUSTMENT_FIELDS) as $field) {
            if (!array_key_exists($field, $adjustment)) {
                throw new \InvalidArgumentException('card refund rights adjustment is incomplete');
            }
        }
        $before = (int)$adjustment['remaining_before_cents'];
        $after = (int)$adjustment['remaining_after_cents'];
        $closed = (int)$adjustment['closed_remaining_cents'];
        if ($before < 0 || $after < 0 || $closed < 0 || $after + $closed > $before) {
            throw new \InvalidArgumentException('card refund rights adjustment is inconsistent');
        }
        return $before - $after - $closed;
    }

    /**
     * 共享次数卡从整卡成交额、共用池与有效退款事实还原当前余额。
     * 退款移除的次数不是服务消耗；两者混算会让连续退款重复扣次。
     * @return array{remainingValueCents:int,usableTimes:int,usableValueCents:int,unusableTailCents:int}
     */
    public static function sharedPool(string $purchaseAmount, int $totalTimes, int $remainingTimes,
        int $returnedCents, int $removedTimes): array
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $purchaseAmount) !== 1
            || $remainingTimes < 0 || $removedTimes < 0 || $remainingTimes + $removedTimes > $totalTimes) {
            throw new \InvalidArgumentException('shared card refund source is invalid');
        }
        $purchaseCents = (int)bcmul($purchaseAmount, '100', 0);
        $quota = self::afterReturn($purchaseCents, $totalTimes,
            $totalTimes - $remainingTimes - $removedTimes, $returnedCents, $purchaseCents % 100 === 0);
        if ($quota['usableTimes'] !== $remainingTimes) {
            throw new \InvalidArgumentException('shared card refund pool does not match its value');
        }
        return $quota;
    }

    /**
     * @return array{remainingValueCents:int,usableTimes:int,usableValueCents:int,unusableTailCents:int}
     */
    public static function afterReturn(
        int $purchaseCents,
        int $totalTimes,
        int $usedTimes,
        int $returnedCents,
        bool $wholeYuanAllocation = true
    ): array {
        if ($purchaseCents <= 0 || $totalTimes <= 0 || $totalTimes > 1000000
            || $usedTimes < 0 || $usedTimes > $totalTimes || $returnedCents < 0
            || ($wholeYuanAllocation && $purchaseCents % 100 !== 0)) {
            throw new \InvalidArgumentException('card refund quota input is invalid');
        }
        $regularCents = $wholeYuanAllocation
            ? intdiv(intdiv($purchaseCents, 100), $totalTimes) * 100
            : intdiv($purchaseCents, $totalTimes);
        if ($regularCents <= 0) {
            // 零单价次数不能靠金额推断该保留几次，交由人工核对而非免费放行。
            throw new \InvalidArgumentException('card refund quota unit amount is unavailable');
        }
        $consumedCents = self::cumulativeCents($purchaseCents, $totalTimes, $usedTimes, $regularCents);
        $originalRemainingCents = $purchaseCents - $consumedCents;
        if ($returnedCents > $originalRemainingCents) {
            throw new \InvalidArgumentException('card refund exceeds unused value');
        }
        $remainingValueCents = $originalRemainingCents - $returnedCents;
        $low = 0;
        $high = $totalTimes - $usedTimes;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $needed = self::cumulativeCents($purchaseCents, $totalTimes, $usedTimes + $middle, $regularCents)
                - $consumedCents;
            if ($needed <= $remainingValueCents) $low = $middle;
            else $high = $middle - 1;
        }
        $usableValueCents = self::cumulativeCents($purchaseCents, $totalTimes, $usedTimes + $low, $regularCents)
            - $consumedCents;
        return [
            'remainingValueCents' => $remainingValueCents,
            'usableTimes' => $low,
            'usableValueCents' => $usableValueCents,
            'unusableTailCents' => $remainingValueCents - $usableValueCents,
        ];
    }

    /** 前几次用原常规单价，所有原始分摊尾差只属于最后一次。 */
    private static function cumulativeCents(int $purchaseCents, int $totalTimes, int $times, int $regularCents): int
    {
        if ($times <= 0) return 0;
        return $times >= $totalTimes ? $purchaseCents : $times * $regularCents;
    }
}

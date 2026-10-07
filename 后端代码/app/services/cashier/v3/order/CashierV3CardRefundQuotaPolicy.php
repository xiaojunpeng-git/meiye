<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

/**
 * 折算退款后的有限次数卡可用额度，不修改原销售及已核销事实。
 * 输入是原项目或整卡实际成交分、原总次数、累计服务已用次数和累计退款分；
 * 服务已用次数不含此前退款移除的次数，否则连续部分退款会重复扣次。
 * 原单次分摊保持不变；凑不够一次的尾额不能核销，但卡未作废时仍可退款。
 */
final class CashierV3CardRefundQuotaPolicy
{
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

<?php

namespace app\services\order;

/**
 * 核销金额整数分摊。
 *
 * 金额以「元」的整数值记录：历史/来源小数直接向 0 截断；
 * 前 N-1 次核销取整除基数，除不尽的尾差全部归最后一次。
 */
final class WriteoffIntegerAmount
{
    /** 将金额向 0 截断为非负整数。 */
    public static function truncate($amount): int
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return 0;
        }
        return max(0, (int)$amount);
    }

    /**
     * 计算本次核销金额。
     *
     * @param mixed $totalAmount 该权益总金额（允许旧 decimal，计算前直接截断）
     * @param int $totalTimes 总核销次数
     * @param int $alreadyTimes 本次前已生效的核销次数
     * @param int $currentTimes 本次核销次数
     */
    public static function allocate($totalAmount, int $totalTimes, int $alreadyTimes, int $currentTimes): int
    {
        $total = self::truncate($totalAmount);
        if ($totalTimes <= 0 || $currentTimes <= 0) {
            return 0;
        }

        $start = min(max($alreadyTimes, 0), $totalTimes);
        $end = min($start + $currentTimes, $totalTimes);
        $allocatedTimes = max($end - $start, 0);
        if ($allocatedTimes === 0) {
            return 0;
        }

        $base = intdiv($total, $totalTimes);
        $amount = $base * $allocatedTimes;
        if ($end === $totalTimes) {
            $amount += $total % $totalTimes;
        }
        return $amount;
    }
}

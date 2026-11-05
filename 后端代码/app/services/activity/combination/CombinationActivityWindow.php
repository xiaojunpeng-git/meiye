<?php
declare(strict_types=1);

namespace app\services\activity\combination;

/**
 * 公开展示窗口与交易资格分开：已上架的预告可浏览和分享，不能提前购买。
 * 只投影活动本身的时间／状态，不写事实；交易仍走原有 validCombinationOne 校验。
 */
class CombinationActivityWindow
{
    public static function present(array $activity, int $now): array
    {
        $start = (int)($activity['start_time'] ?? 0);
        $stop = (int)($activity['stop_time'] ?? 0);
        if ((int)($activity['is_del'] ?? 0) !== 0 || (int)($activity['is_show'] ?? 1) !== 1
            || $stop <= 0 || $stop < $start) {
            $status = 'unavailable';
        } elseif ($now > $stop) {
            $status = 'ended';
        } elseif ($now < $start) {
            $status = 'upcoming';
        } else {
            // 与现有交易查询一致：开始秒可买，结束秒仍在原有闭区间内。
            $status = 'running';
        }
        return ['activity_status' => $status, 'start_time' => $start, 'stop_time' => $stop, 'server_time' => $now];
    }
}

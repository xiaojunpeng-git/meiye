<?php
declare(strict_types=1);
namespace app\services\store\roster;

/** 三端共享的纯排班规则。使用半开时段，结束时刻可接下一单；未排班不增加限制。 */
final class StaffRosterRules
{
    public static function range(string $date, string $start, string $end): array
    {
        $a = strtotime($date . ' ' . ($start ?: '00:00'));
        $b = $end === '' ? strtotime($date . ' +1 day') : strtotime($date . ' ' . $end);
        if ($b <= $a) $b += 86400; // 已有跨午夜班次仍按原时段读取。
        return [(int)$a, (int)$b];
    }

    public static function overlaps(int $a, int $b, int $c, int $d): bool { return $a < $d && $b > $c; }

    /** rows 包含同一员工的各门店记录；休息跨店生效，其他门店的上班时段不能重叠预约。 */
    public static function allows(array $rows, int $storeId, int $start, int $end): bool
    {
        $works = [];
        foreach ($rows as $row) {
            [$a, $b] = self::range((string)$row['schedule_date'], (string)$row['start_time'], (string)$row['end_time']);
            if ((int)$row['schedule_type'] === 0) {
                if (self::overlaps($start, $end, $a, $b)) return false;
                continue;
            }
            if ((int)$row['store_id'] !== $storeId) {
                if (self::overlaps($start, $end, $a, $b)) return false;
                continue;
            }
            // 只用预约覆盖日期的上班约束；相邻日记录供跨午夜休息/班次重叠检查。
            $dayStart = strtotime((string)$row['schedule_date']);
            if ($start >= $dayStart && $start < $dayStart + 86400 || self::overlaps($start, $end, $a, $b)) $works[] = [$a, $b];
            foreach ((array)(json_decode((string)($row['breaks_snapshot'] ?? ''), true) ?: []) as $rest) {
                [$c, $d] = self::range((string)$row['schedule_date'], (string)($rest['start'] ?? $rest['start_time'] ?? ''), (string)($rest['end'] ?? $rest['end_time'] ?? ''));
                if (self::overlaps($start, $end, $c, $d)) return false;
            }
        }
        if (!$works) return true;
        foreach ($works as [$a, $b]) if ($start >= $a && $end <= $b) return true;
        return false;
    }

    /** 将一天的不可预约区间交给既有预约选时组件；与提交校验读取同一份排班。 */
    public static function disabled(array $rows, int $storeId, string $date): array
    {
        $from = (int)strtotime($date); $to = (int)strtotime($date . ' +1 day');
        $points = [$from, $to];
        foreach ($rows as $row) {
            [$a, $b] = self::range((string)$row['schedule_date'], (string)$row['start_time'], (string)$row['end_time']);
            foreach ([$a, $b] as $p) if ($p > $from && $p < $to) $points[] = $p;
            foreach ((array)(json_decode((string)($row['breaks_snapshot'] ?? ''), true) ?: []) as $rest) {
                foreach (self::range((string)$row['schedule_date'], (string)($rest['start'] ?? $rest['start_time'] ?? ''), (string)($rest['end'] ?? $rest['end_time'] ?? '')) as $p) if ($p > $from && $p < $to) $points[] = $p;
            }
        }
        $points = array_values(array_unique($points)); sort($points);
        $slots = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            if (!self::allows($rows, $storeId, $points[$i], $points[$i + 1])) {
                $slots[] = [date('Y-m-d H:i:s', $points[$i]), date('Y-m-d H:i:s', $points[$i + 1])];
            }
        }
        return $slots;
    }
}

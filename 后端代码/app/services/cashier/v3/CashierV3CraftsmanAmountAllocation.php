<?php
namespace app\services\cashier\v3;

/** 收银核销和改单共用的金额分配；输入必须是各入口已校验的当前岗位与整数比例。 */
final class CashierV3CraftsmanAmountAllocation
{
    /** 返回 staffId => 整元分值；各独立岗位有完整基数，手填金额不参与自动尾差。 */
    public static function amounts(int $baseCents, array $rows): array
    {
        $amounts = []; $groups = [];
        foreach ($rows as $row) {
            $id = (int)$row['staffId'];
            if (!empty($row['performanceAmountManual'])) {
                $amounts[$id] = max(0, (int)($row['performanceAmountCents'] ?? 0));
                continue;
            }
            $key = !empty($row['performanceIndependent']) ? (string)($row['allocationGroupKey'] ?? 'normal') : 'normal';
            $groups[$key][] = $row;
        }
        $baseYuan = intdiv(max(0, $baseCents), 100);
        foreach ($groups as $group) {
            $total = array_sum(array_map(static fn(array $row): int => max(0, (int)($row['laborWeight'] ?? 0)), $group));
            $target = intdiv($baseYuan * $total + 50, 100); $allocated = 0;
            foreach ($group as $index => $row) {
                $yuan = $index === count($group) - 1 ? $target - $allocated : intdiv($baseYuan * max(0, (int)($row['laborWeight'] ?? 0)), 100);
                $amounts[(int)$row['staffId']] = $yuan * 100; $allocated += $yuan;
            }
        }
        return $amounts;
    }
}

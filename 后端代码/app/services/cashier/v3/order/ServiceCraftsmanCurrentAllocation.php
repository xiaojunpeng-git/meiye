<?php
declare(strict_types=1);
namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;

/** 当前岗位分组的调整草稿与最终写入共用计算；rows 的岗位必须来自服务端任职查询。 */
final class ServiceCraftsmanCurrentAllocation
{
    public static function calculate(array $rows, int $baseCents): array
    {
        $groups = [];
        foreach ($rows as $i => $row) {
            $key = !empty($row['performanceIndependent']) && (int)($row['positionId'] ?? 0) > 0
                ? 'independent:' . (int)$row['positionId'] : 'normal';
            $rows[$i]['allocationGroupKey'] = $key;
            $groups[$key][] = $i;
        }
        foreach ($groups as $indices) {
            foreach ($indices as $offset => $i) {
                $raw = $rows[$i]['laborWeight'] ?? null;
                if ($raw === null) $raw = intdiv(100, count($indices)) + ($offset < 100 % count($indices) ? 1 : 0);
                if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$raw) || (int)$raw > 100) {
                    throw CashierV3CommandException::invalidContext('手艺人业绩比例必须为0至100的整数，请刷新后重试。');
                }
                $rows[$i]['laborWeight'] = (int)$raw;
            }
        }
        // 适配改单字段后直接调用收银权威分配器，不维护第二套金额计算。
        $inputs = [];
        foreach ($rows as $i => $row) {
            $inputs[] = array_merge($row, ['staffId' => $i + 1,
                'performanceAmountManual' => !empty($row['allocationAmountManual']),
                'performanceAmountCents' => (int)($row['allocationAmountCents'] ?? 0)]);
        }
        $amounts = \app\services\cashier\v3\CashierV3CraftsmanAmountAllocation::amounts($baseCents, $inputs);
        foreach ($rows as $i => &$row) $row['allocationAmountCents'] = $amounts[$i + 1];
        unset($row);
        return $rows;
    }
}

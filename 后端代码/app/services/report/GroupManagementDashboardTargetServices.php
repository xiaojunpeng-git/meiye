<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\target\TargetSchemeServices;
use app\services\target\TargetSchemeReportServices;
use think\facade\Db;

/** 集团看板目标的兼容投影，唯一目标来源是生效中的新目标方案。 */
final class GroupManagementDashboardTargetServices
{
    public function readYear(array $context, int $year): array
    {
        if ($year < 2000 || $year > 2100) throw new \InvalidArgumentException('目标年度无效');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? [])))));
        $names = $ids ? Db::name('system_store')->whereIn('id', $ids)->where('is_del', 0)->column('name', 'id') : [];
        $months = [];
        for ($month = 1; $month <= 12; $month++) $months[] = sprintf('%04d-%02d', $year, $month);
        $bound = (new TargetSchemeServices())->effective($context, 'group_dashboard', $months);
        $values = [];
        foreach ($bound['lines'] as $line) {
            if ($line['metric_code'] !== 'actual_performance' || $line['scope_type'] !== 'store') continue;
            $values[(int)$line['store_id']][(int)substr($line['month_key'], 5, 2)] = $line;
        }
        $rows = [];
        foreach ($names as $id => $name) {
            $cells = []; $total = 0;
            for ($month = 1; $month <= 12; $month++) {
                $line = $values[$id][$month] ?? null;
                $amount = $line ? (int)$line['target_value'] : 0;
                $total += $amount;
                $cells[] = ['month' => $month, 'target_amount_cents' => $line ? $amount : '',
                    'expected_version' => $line ? (int)$line['version'] : 0,
                    // 多月份可来自不同方案，单元格身份使用自身行的方案，不能用汇总的混合方案 ID 0。
                    'subject_key' => sprintf('scheme:%d:store:%d:month:%02d', (int)($line['scheme_id'] ?? 0), $id, $month)];
            }
            $rows[] = ['store_id' => (int)$id, 'store_name' => (string)$name,
                'months' => $cells, 'annual_target_cents' => $total];
        }
        return ['year' => $year, 'scheme_id' => (int)$bound['scheme_id'], 'stores' => $rows,
            'source_explanation' => '目标取自已关联集团管理看板的生效目标方案，按门店和月份汇总。'];
    }

    public function yearTargets(array $context, int $year): array
    {
        return $this->readYear($context, $year);
    }

    /** 旧抽屉保存入口只返回明确指引，不再产生另一套目标值。 */
    public function saveMonthly(array $context, array $input): array
    {
        throw new \InvalidArgumentException('请在目标方案中设置并关联集团管理看板');
    }

    public function save(array $context, array $input): array
    {
        return $this->saveMonthly($context, $input);
    }

    public function totals(array $context, int $year, array $months): array
    {
        return (new TargetSchemeReportServices())->groupTotals($context, $year, $months);
    }
}

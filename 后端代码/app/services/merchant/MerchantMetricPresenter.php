<?php
namespace app\services\merchant;

use app\services\BaseServices;
use app\services\metric\MetricDictionaryServices;

/**
 * 商家端指标展示统一转换（编码 + 字典展示名）
 * detail_api = 数值明细（未核实前为 null）；tooltip_api = 口径说明
 */
class MerchantMetricPresenter extends BaseServices
{
    /**
     * @param array $rows AgentOrderServices::homeStatics 原始行
     * @return array
     */
    public function presentHomeStatics(array $rows): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string)($row['metric_code'] ?? '');
            if ($code === '' && !empty($row['title'])) {
                $code = $this->guessCodeByTitle((string)$row['title']);
            }
            $def = $code !== '' ? $dict->getByCode($code) : null;
            $out[] = [
                'metric_code' => $code,
                'title' => (string)($def['name'] ?? $row['title'] ?? ''),
                'number' => $row['number'] ?? null,
                'growth_rate' => $row['growth_rate'] ?? 0,
                'source' => (string)($def['source'] ?? ''),
                'formula' => (string)($def['formula'] ?? ''),
                // 数值明细未建设：禁止误用字典说明当明细
                'detail_api' => null,
                'detail_developing' => true,
                'tooltip_api' => $code !== '' ? ('metric/dictionary/' . $code) : null,
            ];
        }
        return $out;
    }

    /**
     * 个人业绩页口径（与 SatffYejiServices::staffInfo / pages/admin/yeji/staff 一致）
     * 指标：销售业绩、劳动业绩、客数、指定客、提成、项目数（不含实发工资，由配置控制且默认隐藏）
     */
    public function presentStaffYejiInfo(array $info): array
    {
        $num = static function ($v) {
            if ($v === null || $v === '') {
                return 0;
            }
            return is_numeric($v) ? 0 + $v : $v;
        };
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $defs = [
            ['metric_code' => 'staff_sales_yeji', 'title' => '销售业绩', 'key' => 'moneyYeji'],
            ['metric_code' => 'staff_labor_yeji', 'title' => '劳动业绩', 'key' => 'optionYeji'],
            ['metric_code' => 'staff_service_num', 'title' => '客数', 'key' => 'service_num'],
            ['metric_code' => 'staff_designated_num', 'title' => '指定客', 'key' => 'service_zd'],
            ['metric_code' => 'staff_commission', 'title' => '提成', 'key' => 'service_commission'],
            ['metric_code' => 'staff_project_num', 'title' => '项目数', 'key' => 'project_num'],
        ];
        $out = [];
        foreach ($defs as $def) {
            $code = $def['metric_code'];
            $dictItem = $dict->getByCode($code);
            $out[] = [
                'metric_code' => $code,
                'title' => (string)($dictItem['name'] ?? $def['title']),
                'number' => $num($info[$def['key']] ?? 0),
                'developing' => false,
                'detail_api' => null,
                'detail_developing' => true,
                'tooltip_api' => 'metric/dictionary/' . $code,
                'source' => (string)($dictItem['source'] ?? 'SatffYejiServices::staffInfo'),
                'formula' => (string)($dictItem['formula'] ?? ''),
                'time_field' => (string)($dictItem['time_field'] ?? 'staff_yeji.created_time'),
            ];
        }
        return $out;
    }

    protected function guessCodeByTitle(string $title): string
    {
        $map = [
            '现金业绩' => 'cash_performance',
            '实收业绩' => 'actual_performance',
            '实际业绩' => 'actual_performance',
            '消耗金额' => 'consume_amount',
            '客户消耗金额' => 'consume_amount',
        ];
        return $map[$title] ?? '';
    }
}

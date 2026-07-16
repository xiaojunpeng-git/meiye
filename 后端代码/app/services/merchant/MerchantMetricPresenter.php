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
            $detailApi = null;
            $detailDeveloping = true;
            if ($code === 'cash_performance') {
                $detailApi = '/pages/merchant/metric/cash';
                $detailDeveloping = false;
            } elseif ($code === 'actual_performance') {
                $detailApi = '/pages/merchant/metric/actual';
                $detailDeveloping = false;
            } elseif ($code === 'consume_amount') {
                $detailApi = '/pages/merchant/metric/consume';
                $detailDeveloping = false;
            }
            $out[] = [
                'metric_code' => $code,
                'title' => (string)($def['name'] ?? $row['title'] ?? ''),
                'number' => $row['number'] ?? null,
                'growth_rate' => $row['growth_rate'] ?? 0,
                // 现金/实收/消耗：同口径明细页；禁止粗跳 yeji/store
                'detail_api' => $detailApi,
                'detail_developing' => $detailDeveloping,
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
            ['metric_code' => 'staff_sales_yeji', 'title' => '销售业绩', 'key' => 'moneyYeji', 'sum_type' => 1],
            ['metric_code' => 'staff_labor_yeji', 'title' => '劳动业绩', 'key' => 'optionYeji', 'sum_type' => 2],
            ['metric_code' => 'staff_service_num', 'title' => '客数', 'key' => 'service_num', 'sum_type' => 2],
            ['metric_code' => 'staff_designated_num', 'title' => '指定客', 'key' => 'service_zd', 'sum_type' => 2],
            ['metric_code' => 'staff_commission', 'title' => '提成', 'key' => 'service_commission', 'sum_type' => 2],
            ['metric_code' => 'staff_project_num', 'title' => '项目数', 'key' => 'project_num', 'sum_type' => 2],
        ];
        $out = [];
        foreach ($defs as $def) {
            $code = $def['metric_code'];
            $dictItem = $dict->getByCode($code);
            $sumType = (int)$def['sum_type'];
            $out[] = [
                'metric_code' => $code,
                'title' => (string)($dictItem['name'] ?? $def['title']),
                'number' => $num($info[$def['key']] ?? 0),
                'developing' => false,
                // 明细：商家本人业绩页（销售 sum_type=1 / 劳动侧 sum_type=2）
                'detail_api' => '/pages/merchant/yeji/self?sum_type=' . $sumType,
                'detail_developing' => false,
                'tooltip_api' => 'metric/dictionary/' . $code,
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
            '消耗业绩' => 'consume_amount',
            '客户消耗金额' => 'consume_amount',
            '耗卡业绩' => 'card_consume_performance',
            '耗卡' => 'card_consume_performance',
            '预约客户数' => 'reservation_customer',
            '预约客户' => 'reservation_customer',
            '服务客次' => 'service_visit',
            '服务次数' => 'service_visit',
        ];
        return $map[$title] ?? '';
    }
}

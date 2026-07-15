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

<?php
namespace app\services\statistics;

use app\model\store\SystemStore;
use app\services\merchant\MerchantCustomerMetricServices;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\report\ReportServices;
use think\facade\Db;

/**
 * 平台十指标的过渡适配器：六项金额只读注册指标，四项客数暂沿用原出口。
 * 门店 Vue3 与商家端使用相同 Reader；不将旧业务表金额补进事实结果。
 * 原服务仍供旧门店接口使用，不能在本轮静默改变其响应契约。
 */
class PlatformBusinessDashboardServices extends BusinessDashboardServices
{
    public const VERSION = 'platform-business-dashboard-facts-money-v1';
    public const MONEY_CODES = [
        'cash_performance' => 'cash_performance',
        'actual_performance' => 'actual_performance',
        'consume_amount' => 'consume_amount',
        'refund_amount' => 'refund_performance',
        'recharge_amount' => 'recharge_amount',
        'balance_deduction_amount' => 'balance_deduction_amount',
    ];

    /** 子类测试可注入 Reader；运行时始终使用注册白名单，不接受客户端表达式。 */
    protected function reader(): RegisteredMetricReadServices { return new RegisteredMetricReadServices(); }

    /** 已经后端裁剪的范围仍须验证，空范围绝不能退化成全店查询。 */
    private function context(array $stores, int $start, int $end): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $stores), static function ($id) { return $id > 0; })));
        if (!$stores || $start <= 0 || $end < $start) {
            throw new \InvalidArgumentException('请选择有效门店和统计时间');
        }
        return [$stores, ['start' => date('Y-m-d', $start), 'end' => date('Y-m-d', $end)]];
    }

    /** 保留分精度到展示边界；特别保留退款负值，不做逐店归零或浮点累计。 */
    private function yuan(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function collectStoreMetricMaps(array $storeIds, int $startTs, int $endTs, string $timeRangeStr): array
    {
        [$stores, $range] = $this->context($storeIds, $startTs, $endTs);
        $maps = [];
        foreach (self::MONEY_CODES as $code => $registered) {
            $maps[$code] = [];
            foreach ($this->reader()->storeTotals($registered, '0', $stores, $range) as $row) {
                $maps[$code][(int)$row['store_id']] = $this->yuan((int)$row['metric_value']);
            }
        }
        return $maps + $this->customerMaps($stores, $startTs, $endTs);
    }

    /** 四项客数的旧出口集中在这里，避免金额查询旁路回到旧 homeStatics。 */
    private function customerMaps(array $stores, int $startTs, int $endTs): array
    {
        // 产品经理明确分步迁移；这四项不能伪称 V3 事实，也不改动旧历史。
        $report = app()->make(ReportServices::class);
        $maps['new_profile_count'] = $this->mapNewProfileCountByStores($stores, $startTs, $endTs);
        $maps['casual_customer_count'] = $report->countSourceOrderByStores($stores, 0, [$startTs, $endTs]);
        $maps['new_customer_count'] = $report->countSourceOrderByStores($stores, 1, [$startTs, $endTs]);
        $maps['reservation_customer'] = app()->make(MerchantCustomerMetricServices::class)->mapReservationCustomerByStores($stores, $startTs, $endTs);
        return $maps;
    }

    public function overview(array $storeIds, int $startTs, int $endTs, string $timeRangeStr): array
    {
        // 一次读事务下，概览金额不累加已经格式化的逐店数值。
        return Db::transaction(function () use ($storeIds, $startTs, $endTs, $timeRangeStr) {
            [$stores, $range] = $this->context($storeIds, $startTs, $endTs);
            $maps = $this->customerMaps($stores, $startTs, $endTs);
            $cards = [];
            foreach (self::METRIC_CODES as $code) {
                $value = isset(self::MONEY_CODES[$code])
                    ? $this->yuan($this->reader()->summary(self::MONEY_CODES[$code], '0', $stores, $range))
                    : array_sum($maps[$code]);
                $cards[] = $this->buildCard($code, $value);
            }
            return ['cards' => $cards] + $this->metadata();
        });
    }

    public function trend(array $storeIds, string $metric, int $startTs, int $endTs): array
    {
        [$stores, $range] = $this->context($storeIds, $startTs, $endTs);
        if (!isset(self::MONEY_CODES[$metric])) {
            return parent::trend($stores, $metric, $startTs, $endTs) + $this->metadata();
        }
        $days = [];
        for ($time = strtotime($range['start']); $time <= strtotime($range['end']); $time = strtotime('+1 day', $time)) {
            $days[date('Y-m-d', $time)] = '0.00';
        }
        foreach ($this->reader()->dailyTotals(self::MONEY_CODES[$metric], '0', $stores, $range) as $row) {
            $days[$row['business_date']] = $this->yuan((int)$row['metric_value']);
        }
        return ['metric_code' => $metric, 'unit' => '元', 'xAxis' => array_keys($days), 'series' => array_values($days)] + $this->metadata();
    }

    /** 门店排行复用原稳定排序/展示，只替换金额 map 的权威来源。 */
    public function storeRanking(array $storeIds, int $startTs, int $endTs, string $timeRangeStr, string $sortBy = 'cash_performance', string $sortOrder = 'desc'): array
    {
        return parent::storeRanking($storeIds, $startTs, $endTs, $timeRangeStr, $sortBy, $sortOrder) + $this->metadata();
    }

    /** 明细、全量导出与卡片都由同一注册指标读取；导出绝不只取当前页。 */
    public function moneyMetricDetail(array $storeIds, string $metric, int $startTs, int $endTs, string $timeRangeStr, int $page, int $limit): array
    {
        return $this->moneyResult($storeIds, $metric, $startTs, $endTs, $page, $limit, false);
    }

    public function exportMoneyDetail(array $storeIds, string $metric, int $startTs, int $endTs): array
    {
        return $this->moneyResult($storeIds, $metric, $startTs, $endTs, 1, 100, true);
    }

    private function moneyResult(array $storeIds, string $metric, int $startTs, int $endTs, int $page, int $limit, bool $export): array
    {
        [$stores, $range] = $this->context($storeIds, $startTs, $endTs);
        if (!isset(self::MONEY_CODES[$metric])) throw new \InvalidArgumentException('不支持的金额指标');
        if ($page < 1 || $limit < 1 || $limit > 100) throw new \InvalidArgumentException('无效分页');
        return Db::transaction(function () use ($stores, $range, $metric, $page, $limit, $export) {
            $reader = $this->reader();
            $registered = self::MONEY_CODES[$metric];
            $result = $reader->detailPage($registered, '0', $stores, $range, $page, $limit);
            $totalRows = (int)$result['total'];
            if ($export && $totalRows > 10000) throw new \InvalidArgumentException('明细超过10000条，请缩小时间或门店范围后导出');
            $rows = $result['rows'];
            if ($export) {
                for ($next = 2; count($rows) < $totalRows; ++$next) {
                    $batch = $reader->detailPage($registered, '0', $stores, $range, $next, 100)['rows'];
                    if (!$batch) throw new \RuntimeException('导出记录未完整读取，请重新查询');
                    $rows = array_merge($rows, $batch);
                }
            }
            $names = SystemStore::whereIn('id', $stores)->column('name', 'id');
            $list = [];
            foreach ($rows as $row) {
                $sid = (int)$row['store_id'];
                if (!in_array($sid, $stores, true)) throw new \LogicException('指标明细越权');
                $list[] = [
                    'id' => (string)($row['fact_id'] ?? $row['id'] ?? ''),
                    'order_id' => (string)($row['order_no_snapshot'] ?? $row['document_no_snapshot'] ?? $row['business_no'] ?? ''),
                    'real_name' => (string)($row['member_name_snapshot'] ?? ''),
                    'store_name' => (string)($row['store_name_snapshot'] ?? $names[$sid] ?? ''),
                    'store_id' => $sid,
                    'amount' => $this->yuan((int)$row['metric_value']),
                    'amount_cents' => (int)$row['metric_value'],
                    'biz_time_text' => (string)$row['business_date'],
                ];
            }
            $card = $this->buildCard($metric, 0);
            return ['metric_code' => $metric, 'name' => $card['name'], 'unit' => '元',
                'total' => $this->yuan($reader->summary($registered, '0', $stores, $range)),
                'list' => $list, 'list_count' => $totalRows, 'breakdown' => [],
                'columns' => [
                    ['title' => '业务日期', 'key' => 'biz_time_text', 'minWidth' => 120],
                    ['title' => '业务单号', 'key' => 'order_id', 'minWidth' => 180],
                    ['title' => '会员', 'key' => 'real_name', 'minWidth' => 100],
                    ['title' => '门店', 'key' => 'store_name', 'minWidth' => 120],
                    ['title' => '金额（元）', 'key' => 'amount', 'minWidth' => 120, 'value_type' => 'money'],
                ], 'page' => $page, 'page_size' => $limit] + $this->metadata();
        });
    }

    protected function buildCard(string $code, $value): array
    {
        $card = parent::buildCard($code, $value);
        $money = isset(self::MONEY_CODES[$code]);
        $card['source_label'] = $money ? '统一事实口径' : '原客数口径';
        if ($money) $card['metric_version'] = MetricDefinitionRegistry::get(self::MONEY_CODES[$code])['metric_version'];
        if ($money) {
            $card['value'] = (string)$value;
            // 不沿用旧字典的“分成后业绩”等技术旧说明误导用户。
            $notes = [
                'cash_performance' => '按成功实际记账收款统计；余额支付不重复计入。',
                'actual_performance' => '现金业绩减去成功现金退款；不扣除合作方分成，允许为负数。',
                'consume_amount' => '项目实际完成服务后产生的消耗业绩。',
                'refund_amount' => '成功退回现金的金额，按退款成功业务日期统计。',
                'recharge_amount' => '充值成功产生的本金增加额，不含赠金。',
                'balance_deduction_amount' => '订单支付扣减的储值本金与赠金，不含调账或退款返还。',
            ];
            $card['tooltip'] = ['summary' => $notes[$code], 'note' => '只统计已接入统一事实的业务；未迁入的旧业务金额不混加。'];
        }
        return $card;
    }

    private function metadata(): array
    {
        return ['metric_version' => self::VERSION, 'queried_at' => date('Y-m-d H:i:s'),
            'coverage_start' => MetricDefinitionRegistry::COVERAGE_START,
            'money_source' => 'registered_facts', 'customer_source' => 'legacy_customer_metrics',
            'source_notice' => '金额：统一事实口径，起点' . MetricDefinitionRegistry::COVERAGE_START . '（未接入的旧业务不混加）；新建档、散客、新客、预约：原客数口径，历史迁移尚未完成。'];
    }
}

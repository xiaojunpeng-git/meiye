<?php

namespace app\services\cashier\v3\dashboard;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\metric\MetricDictionaryServices;
use think\facade\Db;

/**
 * V3 经营看板只读模型。
 *
 * 不读取旧订单报表、staff_yeji 或 homeStatics。当前没有日聚合表，因此所有
 * 结果均从成功终态的 V3 不可变事实／V3 欠款权威记录实时读取；后续聚合表接入
 * 时必须与本类的同一指标口径逐项对账，不能在页面另写一套公式。
 */
final class CashierV3BusinessDashboardReadModel
{
    public const CONTRACT_VERSION = 'cashier-v3-business-dashboard-v1';
    public const METRIC_VERSION = 'cashier-v3-facts-v1';

    /** @var string[] */
    public const METRICS = [
        'sales_amount', 'cash_performance', 'actual_performance',
        'balance_deduction', 'recharge_amount', 'debt_amount',
        'service_count', 'consumption_performance', 'labor_performance',
    ];

    /** @return array<string,mixed> */
    public function initial(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        return $this->dashboard([], $operator, $scope);
    }

    /** @return array<string,mixed> */
    public function dashboard(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $range = $this->range($payload);
        $this->assertScope($operator, $scope);
        $selected = $this->metric($payload['metricCode'] ?? $payload['metric_code'] ?? 'cash_performance');
        $sortBy = $this->metric($payload['sortBy'] ?? $payload['sort_by'] ?? $selected);
        $sortOrder = strtolower((string)($payload['sortOrder'] ?? $payload['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $cards = [];
        foreach (self::METRICS as $code) {
            $definition = $this->definition($code);
            $rawValue = $this->totalCents($code, $range, $operator, $scope);
            $cards[] = [
                'metricCode' => $code,
                'name' => $definition['name'],
                'description' => $definition['summary'],
                'value' => $definition['unit'] === '元' ? $this->centsToMoney($rawValue) : $rawValue,
                'valueCents' => $definition['unit'] === '元' ? $rawValue : null,
                'unit' => $definition['unit'],
                'valueType' => $definition['unit'] === '元' ? 'money' : 'count',
            ];
        }
        return [
            'availability' => ['contractVersion' => self::CONTRACT_VERSION, 'status' => 'active', 'reasonCode' => '', 'dataLoaded' => true, 'businessFactsIncluded' => true],
            'mode' => 'store',
            'scope' => [
                'dateRange' => ['start' => $range['start'], 'end' => $range['end']],
                'organization' => null,
                'store' => ['id' => $operator->storeId(), 'name' => $this->storeName($operator->storeId())],
                'forcedRangeLabel' => '当前登录门店：' . $this->storeName($operator->storeId()),
            ],
            'cards' => $cards,
            'selectedMetricCode' => $selected,
            'trend' => ['metricCode' => $selected, 'points' => $this->trend($selected, $range, $operator, $scope), 'isLoading' => false],
            'ranking' => $this->ranking($sortBy, $sortOrder, $range, $operator, $scope),
            'metricVersion' => self::METRIC_VERSION,
            'dataAsOf' => date('Y-m-d H:i:s'),
            // 当前读取事实而非滞后的聚合表，结果与可见事实同一快照，无待追平队列。
            'aggregationCaughtUp' => true,
            'coverageStart' => $range['start'],
        ];
    }

    /** @return array<string,mixed> */
    public function detail(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $range = $this->range($payload);
        $this->assertScope($operator, $scope);
        $metric = $this->metric($payload['metricCode'] ?? $payload['metric_code'] ?? 'cash_performance');
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(100, max(10, (int)($payload['pageSize'] ?? $payload['page_size'] ?? 20)));
        return $this->detailResult($range, $metric, $page, $pageSize, $operator, $scope);
    }

    /** @return array<string,mixed> */
    private function detailResult(array $range, string $metric, int $page, int $pageSize, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        if ($metric === 'debt_amount') {
            // 欠款尚未纳入本轮注册指标，继续使用原有权威读模型。
            $source = $this->source($metric, $range, $operator, $scope);
            $total = (int)(clone $source['query'])->count();
            $rows = (clone $source['query'])->order($source['time'], 'desc')->order('id', 'desc')
                ->page($page, $pageSize)->select()->toArray();
        } else {
            $pageData = (new \app\services\query\metric\RegisteredMetricReadServices())->detailPage(
                \app\services\query\metric\MetricDefinitionRegistry::canonical($metric),
                $operator->tenantId(), [$operator->storeId()], $range, $page, $pageSize
            );
            $total = (int)$pageData['total'];
            $rows = $pageData['rows'];
        }
        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->detailRecord($metric, $row);
        }
        return [
            'metricCode' => $metric,
            'metricName' => $this->definition($metric)['name'],
            'columns' => [
                ['key' => 'businessDate', 'label' => '业务日期'], ['key' => 'businessNo', 'label' => '来源单号'],
                ['key' => 'memberName', 'label' => '会员'], ['key' => 'operatorName', 'label' => '操作人'],
                ['key' => 'amount', 'label' => $this->definition($metric)['name']], ['key' => 'status', 'label' => '状态'],
            ],
            'records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
            'dataAsOf' => date('Y-m-d H:i:s'), 'metricVersion' => self::METRIC_VERSION,
        ];
    }

    private function totalCents(string $metric, array $range, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): int
    {
        if ($metric !== 'debt_amount') {
            return (new \app\services\query\metric\RegisteredMetricReadServices())->summary(
                \app\services\query\metric\MetricDefinitionRegistry::canonical($metric),
                $operator->tenantId(), [$operator->storeId()], $range
            );
        }
        $source = $this->source($metric, $range, $operator, $scope);
        $amount = $source['amount'];
        $sql = "COALESCE(SUM({$amount}),0) AS amount";
        // ThinkORM's value('amount') replaces the aggregate select with the physical
        // column name. Read the aggregate row so the AS amount alias is preserved.
        $row = (clone $source['query'])->fieldRaw($sql)->find();
        return (int)($row['amount'] ?? 0);
    }

    /** @return array<int,array<string,mixed>> */
    private function trend(string $metric, array $range, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        if ($metric !== 'debt_amount') {
            $canonical = \app\services\query\metric\MetricDefinitionRegistry::canonical($metric);
            $contract = \app\services\query\metric\MetricDefinitionRegistry::get($canonical);
            $rows = (new \app\services\query\metric\RegisteredMetricReadServices())->dailyStoreTotals(
                $canonical, $operator->tenantId(), [$operator->storeId()], $range
            );
            $map = [];
            foreach ($rows as $row) {
                $map[(string)$row['business_date']] = (int)$row['amount_cents'];
            }
            $points = [];
            for ($date = $range['start']; $date <= $range['end']; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
                $value = $map[$date] ?? 0;
                $isMoney = $contract['storage_unit'] === 'fen';
                $points[] = [
                    'id' => $date,
                    'label' => substr($date, 5),
                    'value' => $isMoney ? $this->centsToMoney($value) : $value,
                    'valueCents' => $isMoney ? $value : null,
                    'unit' => $isMoney ? '元' : '次',
                ];
            }
            return $points;
        }
        $source = $this->source($metric, $range, $operator, $scope);
        $amount = $source['amount'];
        $value = "SUM({$amount})";
        $dateColumn = $source['date'];
        $rows = (clone $source['query'])->fieldRaw("{$dateColumn} AS day, COALESCE({$value},0) AS amount")
            ->group($dateColumn)->orderRaw($dateColumn . ' ASC')->select()->toArray();
        $map = [];
        foreach ($rows as $row) $map[(string)$row['day']] = (int)$row['amount'];
        $points = [];
        for ($date = $range['start']; $date <= $range['end']; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
            $value = $map[$date] ?? 0;
            $points[] = ['id' => $date, 'label' => substr($date, 5), 'value' => $this->definition($metric)['unit'] === '元' ? $this->centsToMoney($value) : $value, 'valueCents' => $this->definition($metric)['unit'] === '元' ? $value : null, 'unit' => $this->definition($metric)['unit']];
        }
        return $points;
    }

    /** @return array<string,mixed> */
    private function ranking(string $metric, string $order, array $range, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        // 欠款仍走原有权威读模型，不将本轮冻结指标强行送入注册表。
        $canonical = $metric === 'debt_amount' ? null : \app\services\query\metric\MetricDefinitionRegistry::canonical($metric);
        if ($canonical !== null) {
            $ranking = (new \app\services\query\metric\RegisteredMetricReadServices())->defaultRanking(
                $canonical, $operator->tenantId(), [$operator->storeId()], $range, 50, $order
            );
            $records = [];
            foreach ($ranking['rows'] as $row) {
                $records[] = [
                    'id' => (int)$row['entity_id'], 'name' => (string)$row['entity_name'],
                    'amount' => $this->definition($metric)['unit'] === '元' ? $this->centsToMoney((int)$row['metric_value']) : (int)$row['metric_value'],
                    'amountCents' => $this->definition($metric)['unit'] === '元' ? (int)$row['metric_value'] : null,
                ];
            }
            return [
                'dimension' => (string)$ranking['dimension'], 'sortBy' => $metric, 'sortOrder' => $order,
                'sortOptions' => array_map(function (string $code): array { return ['value' => $code, 'label' => $this->definition($code)['name']]; }, self::METRICS),
                'columns' => [['key' => 'name', 'label' => '人员'], ['key' => 'amount', 'label' => $this->definition($metric)['name'], 'type' => $this->definition($metric)['unit'] === '元' ? 'money' : 'count']],
                'records' => $records, 'isLoading' => false,
            ];
        }
        $source = $this->source($metric, $range, $operator, $scope);
        $amount = $source['amount'];
        $value = "SUM({$amount})";
        $operator = $metric === 'debt_amount'
            ? "0 AS operator_id, '' AS operator_name"
            : 'operator_id AS operator_id, MAX(operator_name_snapshot) AS operator_name';
        $rows = (clone $source['query'])->fieldRaw("{$operator}, COALESCE({$value},0) AS amount")
            ->group('operator_id')->order('amount ' . $order)->limit(50)->select()->toArray();
        $records = [];
        foreach ($rows as $row) {
            $raw = (int)$row['amount'];
            $records[] = ['id' => (int)$row['operator_id'], 'name' => (string)$row['operator_name'], 'amount' => $this->definition($metric)['unit'] === '元' ? $this->centsToMoney($raw) : $raw, 'amountCents' => $this->definition($metric)['unit'] === '元' ? $raw : null];
        }
        return [
            'dimension' => 'operator', 'sortBy' => $metric, 'sortOrder' => $order,
            'sortOptions' => array_map(function (string $code): array { return ['value' => $code, 'label' => $this->definition($code)['name']]; }, self::METRICS),
            'columns' => [['key' => 'name', 'label' => '操作人'], ['key' => 'amount', 'label' => $this->definition($metric)['name'], 'type' => $this->definition($metric)['unit'] === '元' ? 'money' : 'count']],
            'records' => $records, 'isLoading' => false,
        ];
    }

    /** @return array{query:mixed,amount:string,time:string,date:string} */
    private function source(string $metric, array $range, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $tenantId = $operator->tenantId();
        $storeId = $operator->storeId();
        if ($metric === 'debt_amount') {
            $query = Db::name('cashier_v3_recharge_debt_authority')->alias('d')
                ->join('store_debt sd', 'sd.id=d.debt_id')->where('d.tenant_id', $tenantId)->where('d.store_id', $storeId)
                ->whereBetween('sd.add_time', [strtotime($range['start']), strtotime($range['end'] . ' 23:59:59')])
                ->field("d.id, d.debt_no AS business_no, d.member_id, d.created_at AS occurred_at, d.created_at AS settled_at, d.created_at AS recorded_at, d.store_id, 0 AS operator_id, '' AS operator_name_snapshot, sd.total_debt * 100 AS debt_amount");
            return ['query' => $query, 'amount' => 'sd.total_debt * 100', 'time' => 'occurred_at', 'date' => 'DATE(FROM_UNIXTIME(d.created_at))'];
        }
        throw new \LogicException('未注册指标不得使用门店运营看板旧取数通道。');
    }

    private function detailRecord(string $metric, array $row): array
    {
        // Registered metric detail rows arrive with one already-calculated
        // value.  The dashboard only formats it and must not reinterpret a
        // source expression or field list.
        $raw = $metric === 'debt_amount' ? (int)($row['debt_amount'] ?? 0) : (int)($row['metric_value'] ?? 0);
        return [
            'id' => (string)($row['fact_id'] ?? $row['service_fact_id'] ?? $row['id'] ?? ''),
            'businessDate' => (string)($row['business_date'] ?? date('Y-m-d', (int)($row['occurred_at'] ?? 0))),
            'businessNo' => (string)($row['order_no_snapshot'] ?? $row['document_no_snapshot'] ?? $row['business_no'] ?? ''),
            'memberName' => (string)($row['member_name_snapshot'] ?? ''), 'operatorName' => (string)($row['operator_name_snapshot'] ?? ''),
            'amount' => $this->definition($metric)['unit'] === '元' ? $this->centsToMoney($raw) : $raw,
            'amountCents' => $this->definition($metric)['unit'] === '元' ? $raw : null,
            'status' => (string)($row['status'] ?? $row['service_status'] ?? '有效'),
        ];
    }

    /** @return array{start:string,end:string} */
    private function range(array $payload): array
    {
        $start = trim((string)($payload['startDate'] ?? $payload['start_date'] ?? '')) ?: date('Y-m-01');
        $end = trim((string)($payload['endDate'] ?? $payload['end_date'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end || strtotime($end) - strtotime($start) > 366 * 86400) {
            throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '统计日期范围无效，请选择不超过 366 天的开始和结束日期。');
        }
        return compact('start', 'end');
    }

    private function metric($value): string
    {
        $metric = trim((string)$value);
        return in_array($metric, self::METRICS, true) ? $metric : 'cash_performance';
    }

    private function assertScope(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if (!$scope->allowsStore($operator->storeId())) {
            throw new CashierV3CommandException(CashierV3ResultCode::PERMISSION_DENIED, '当前账号无权查看该门店的经营数据。');
        }
    }

    /** @return array{name:string,summary:string,unit:string} */
    private function definition(string $code): array
    {
        if ($code === 'debt_amount') {
            return ['name' => '欠款', 'summary' => 'V3 充值产生并已建立权威债权的欠款金额。', 'unit' => '元'];
        }
        $canonical = \app\services\query\metric\MetricDefinitionRegistry::canonical($code);
        $contract = \app\services\query\metric\MetricDefinitionRegistry::get($canonical);
        $definition = (new MetricDictionaryServices())->getTooltip($canonical);
        if (($definition['user_ready'] ?? false) !== true) {
            throw new \RuntimeException('DASHBOARD_METRIC_NOT_REGISTERED');
        }
        return [
            'name' => (string)$definition['name'],
            'summary' => (string)$definition['summary'],
            'unit' => $contract['storage_unit'] === 'fen' ? '元' : '次',
        ];
    }

    private function storeName(int $storeId): string
    {
        return (string)(Db::name('system_store')->where('id', $storeId)->value('name') ?: '当前门店');
    }

    private function centsToMoney(int $cents): string { return \app\services\query\metric\MetricMoneyFormatter::integerYuan($cents); }
}

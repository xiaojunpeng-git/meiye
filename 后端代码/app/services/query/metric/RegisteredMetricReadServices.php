<?php

namespace app\services\query\metric;

use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\report\StoreReportNormalDataScopeServices;
use app\services\report\StoreReportParticipantScopeServices;
use app\services\report\StoreUnifiedReportOrganizationDimensionServices;
use think\facade\Db;

/** Executes only server-registered metric contracts. No metric-code branch is allowed here. */
final class RegisteredMetricReadServices
{
    private $queryFactory;
    private $normalFacts;
    private $normalServices;

    public function __construct(?callable $queryFactory = null, ?callable $normalFacts = null, ?callable $normalServices = null)
    {
        $this->queryFactory = $queryFactory ?: static function (string $table) { return Db::name($table); };
        $this->normalFacts = $normalFacts ?: static function ($query, string $tenantField, string $orderField): void {
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, $tenantField, $orderField);
        };
        $this->normalServices = $normalServices ?: static function ($query, string $table): void {
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, $table);
        };
    }

    public function summary(string $metricCode, string $tenantId, array $stores, array $range): int
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        $handlers = [
            'cash_positive' => function () use ($tenantId, $stores, $range): int { return $this->cashSummary($tenantId, $stores, $range, 'positive'); },
            'cash_refund' => function () use ($tenantId, $stores, $range): int { return $this->cashSummary($tenantId, $stores, $range, 'refund'); },
            'derived_subtract' => function () use ($contract, $tenantId, $stores, $range): int { return $this->derivedSummary($contract, $tenantId, $stores, $range); },
            'fact_sum' => function () use ($contract, $tenantId, $stores, $range): int { return $this->factSummary($contract['source'], $tenantId, $stores, $range); },
            'personnel_fact_sum' => function () use ($contract, $tenantId, $stores, $range): int { return $this->factSummary($contract['source'], $tenantId, $stores, $range); },
            'distinct_member' => function () use ($contract, $tenantId, $stores, $range): int { return $this->distinctSummary($contract['source'], $tenantId, $stores, $range); },
        ];
        $handler = $handlers[$contract['reader_strategy']] ?? null;
        if (!$handler) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $handler();
    }

    /** @return array<int,array{store_id:int,business_date:string,amount_cents:int}> */
    public function dailyStoreTotals(string $metricCode, string $tenantId, array $stores, array $range): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        $handlers = [
            'cash_positive' => function () use ($tenantId, $stores, $range): array { return $this->cashGrouped($tenantId, $stores, $range, 'positive'); },
            'cash_refund' => function () use ($tenantId, $stores, $range): array { return $this->cashGrouped($tenantId, $stores, $range, 'refund'); },
            'derived_subtract' => function () use ($contract, $tenantId, $stores, $range): array { return $this->derivedGrouped($contract, $tenantId, $stores, $range); },
            'fact_sum' => function () use ($contract, $tenantId, $stores, $range): array { return $this->factGrouped($contract['source'], $tenantId, $stores, $range); },
            'personnel_fact_sum' => function () use ($contract, $tenantId, $stores, $range): array { return $this->factGrouped($contract['source'], $tenantId, $stores, $range); },
            'distinct_member' => function () use ($contract, $tenantId, $stores, $range): array { return $this->distinctGrouped($contract['source'], $tenantId, $stores, $range); },
        ];
        $handler = $handlers[$contract['reader_strategy']] ?? null;
        if (!$handler) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $handler();
    }

    /**
     * Registered daily metric result for a multi-store presentation.  The
     * reader, rather than a dashboard, owns the cross-store aggregation.
     *
     * @return array<int,array{business_date:string,metric_value:int}>
     */
    public function dailyTotals(string $metricCode, string $tenantId, array $stores, array $range): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        // A distinct population cannot be calculated by adding each store's
        // distinct count: the same member may visit more than one store on a
        // day. Keep the cross-store distinct operation in the registered
        // reader, alongside the summary definition.
        if (($contract['reader_strategy'] ?? null) === 'distinct_member') {
            return $this->distinctDailyTotals($contract['source'], $tenantId, $stores, $range);
        }
        $byDay = [];
        foreach ($this->dailyStoreTotals($metricCode, $tenantId, $stores, $range) as $point) {
            $day = (string)($point['business_date'] ?? '');
            if ($day === '') $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $byDay[$day] = $this->add((int)($byDay[$day] ?? 0), $this->integer($point['amount_cents'] ?? null));
        }
        ksort($byDay, SORT_STRING);
        $out = [];
        foreach ($byDay as $day => $value) $out[] = ['business_date' => $day, 'metric_value' => $value];
        return $out;
    }

    /** @return array<int,array{store_id:int,metric_value:int}> */
    public function storeTotals(string $metricCode, string $tenantId, array $stores, array $range): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        // This is deliberately not implemented by adding daily distinct
        // counts. A member may appear on more than one day in a period.
        if (($contract['reader_strategy'] ?? null) === 'distinct_member') {
            return $this->distinctStoreTotals($contract['source'], $tenantId, $stores, $range);
        }
        $totals = [];
        foreach ($this->dailyStoreTotals($metricCode, $tenantId, $stores, $range) as $point) {
            $storeId = $this->integer($point['store_id'] ?? null);
            if (!in_array($storeId, $stores, true)) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $totals[$storeId] = $this->add((int)($totals[$storeId] ?? 0), $this->integer($point['amount_cents'] ?? null));
        }
        $out = [];
        foreach ($stores as $storeId) $out[] = ['store_id' => $storeId, 'metric_value' => (int)($totals[$storeId] ?? 0)];
        return $out;
    }

    /**
     * Re-groups already-authorized store totals for a report presentation.
     * The consumer supplies labels/membership only; all metric addition stays
     * inside the registered Reader and the map cannot introduce a store that
     * is outside the resolved report scope.
     *
     * @param array<int,string|int> $storeGroupKeys keyed by store id
     * @return array<int,array{group_key:string,metric_value:int,store_count:int}>
     */
    public function groupedStoreTotals(string $metricCode, string $tenantId, array $stores, array $range, array $storeGroupKeys): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $allowed = array_fill_keys($stores, true);
        $groups = [];
        foreach ($storeGroupKeys as $storeId => $groupKey) {
            $id = $this->integer($storeId);
            $key = trim((string)$groupKey);
            if (!isset($allowed[$id]) || $key === '') $this->fail('METRIC_SOURCE_SCOPE_INVALID');
            if (isset($groups[$id]) && $groups[$id] !== $key) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
            $groups[$id] = $key;
        }
        foreach ($stores as $storeId) if (!isset($groups[$storeId])) $this->fail('METRIC_SOURCE_SCOPE_INVALID');

        $out = [];
        foreach ($this->storeTotals($metricCode, $tenantId, $stores, $range) as $row) {
            $storeId = $this->integer($row['store_id'] ?? null);
            $key = $groups[$storeId] ?? null;
            if (!is_string($key) || $key === '') $this->fail('METRIC_SOURCE_RESULT_INVALID');
            if (!isset($out[$key])) $out[$key] = ['group_key' => $key, 'metric_value' => 0, 'store_count' => 0];
            $out[$key]['metric_value'] = $this->add($out[$key]['metric_value'], $this->integer($row['metric_value'] ?? null));
            ++$out[$key]['store_count'];
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /** @return array<string,int> */
    public function periodTotals(string $metricCode, string $tenantId, array $stores, array $range, string $granularity): array
    {
        if (!in_array($granularity, ['day', 'month', 'year'], true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        // A period distinct is one population operation over the requested
        // period. It must never be reconstructed by adding day-level counts.
        if (($contract['reader_strategy'] ?? null) === 'distinct_member') {
            return $this->distinctPeriodTotals($contract['source'], $tenantId, $stores, $range, $granularity);
        }
        $out = [];
        foreach ($this->dailyTotals($metricCode, $tenantId, $stores, $range) as $point) {
            $date = (string)($point['business_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $key = $granularity === 'day' ? $date : ($granularity === 'month' ? substr($date, 0, 7) : substr($date, 0, 4));
            $out[$key] = $this->add((int)($out[$key] ?? 0), $this->integer($point['metric_value'] ?? null));
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function personnelTotals(string $metricCode, string $tenantId, array $stores, array $range, array $pairs): array
    {
        $contract = MetricDefinitionRegistry::get($metricCode);
        if ($contract['reader_strategy'] !== 'personnel_fact_sum') $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return (new PersonnelPerformanceReadServices($this->queryFactory, $this->normalFacts))->totals($tenantId, $stores, $range, $contract['metric_code'], $pairs);
    }

    public function personnelTotal(string $metricCode, string $tenantId, array $stores, array $range, array $pairs): int
    {
        $total = 0;
        foreach ($this->personnelTotals($metricCode, $tenantId, $stores, $range, $pairs) as $point) {
            $total = $this->add($total, $this->integer($point['amount_cents'] ?? null));
        }
        return $total;
    }

    /** @return array<int,array{employee_id:int,employee_name:string,amount_cents:int}> */
    public function personnelRanking(string $metricCode, string $tenantId, array $stores, array $range, int $limit = 20, string $order = 'desc'): array
    {
        $rows = $this->dimensionRanking($metricCode, 'employee', $tenantId, $stores, $range, $limit, $order);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'employee_id' => $row['entity_id'],
                'employee_name' => $row['entity_name'],
                'amount_cents' => $row['metric_value'],
            ];
        }
        return $out;
    }

    /** @return array<int,array{entity_id:int,entity_name:string,metric_value:int}> */
    public function dimensionRanking(string $metricCode, string $dimension, string $tenantId, array $stores, array $range, int $limit = 20, string $order = 'desc'): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        $dimensionContract = $contract['dimensions'][$dimension] ?? ($contract['source']['dimensions'][$dimension] ?? null);
        if (!is_array($dimensionContract) || $limit < 1 || $limit > 100 || !in_array($order, ['asc', 'desc'], true)) {
            $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        }
        $handlers = [
            'cash_positive' => function () use ($tenantId, $stores, $range, $dimensionContract, $limit, $order): array {
                return $this->cashDimensionRanking($tenantId, $stores, $range, 'positive', $dimensionContract, $limit, $order);
            },
            'cash_refund' => function () use ($tenantId, $stores, $range, $dimensionContract, $limit, $order): array {
                return $this->cashDimensionRanking($tenantId, $stores, $range, 'refund', $dimensionContract, $limit, $order);
            },
            'derived_subtract' => function () use ($tenantId, $stores, $range, $dimensionContract, $limit, $order): array {
                return $this->cashDimensionRanking($tenantId, $stores, $range, 'net', $dimensionContract, $limit, $order);
            },
            'fact_sum' => function () use ($contract, $tenantId, $stores, $range, $dimensionContract, $limit, $order): array {
                return $this->factDimensionRanking($contract['source'], $tenantId, $stores, $range, $dimensionContract, $limit, $order);
            },
            'personnel_fact_sum' => function () use ($contract, $tenantId, $stores, $range, $dimensionContract, $limit, $order): array {
                return $this->factDimensionRanking($contract['source'], $tenantId, $stores, $range, $dimensionContract, $limit, $order);
            },
        ];
        $handler = $handlers[$contract['reader_strategy']] ?? null;
        if (!$handler) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $handler();
    }

    /** @return array{dimension:string,rows:array<int,array{entity_id:int,entity_name:string,metric_value:int}>} */
    public function defaultRanking(string $metricCode, string $tenantId, array $stores, array $range, int $limit = 20, string $order = 'desc'): array
    {
        $contract = MetricDefinitionRegistry::get($metricCode);
        $dimension = $contract['default_ranking_dimension'] ?? null;
        if (!is_string($dimension) || $dimension === '') $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return [
            'dimension' => $dimension,
            'rows' => $this->dimensionRanking($metricCode, $dimension, $tenantId, $stores, $range, $limit, $order),
        ];
    }

    public function storeNames(array $stores): array
    {
        if (!$stores || count($stores) > 10000) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        foreach ($stores as $id) if (!is_int($id) || $id <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        $names = call_user_func($this->queryFactory, 'system_store')->whereIn('id', $stores)->column('name', 'id');
        $result = [];
        foreach ($stores as $id) {
            $name = $names[$id] ?? null;
            if (!is_string($name) || $name === '') $this->fail('METRIC_STORE_LABEL_UNAVAILABLE');
            $result[$id] = $name;
        }
        return $result;
    }


    /** Registered detail page; cash uses the same sales/recharge populations as summary. */
    public function detailPage(string $metricCode, string $tenantId, array $stores, array $range, int $page, int $pageSize): array
    {
        $this->assertScope($tenantId, $stores, $range);
        if ($page < 1 || $pageSize < 1 || $pageSize > 100 || $page * $pageSize > 10000) $this->fail('METRIC_QUERY_PAGE_INVALID');
        $contract = MetricDefinitionRegistry::get($metricCode);
        $handlers = [
            'cash_positive' => function () use ($tenantId, $stores, $range, $page, $pageSize): array { return $this->cashDetailPage($tenantId, $stores, $range, 'positive', $page, $pageSize); },
            'cash_refund' => function () use ($tenantId, $stores, $range, $page, $pageSize): array { return $this->cashDetailPage($tenantId, $stores, $range, 'refund', $page, $pageSize); },
            'derived_subtract' => function () use ($tenantId, $stores, $range, $page, $pageSize): array { return $this->cashDetailPage($tenantId, $stores, $range, 'net', $page, $pageSize); },
            'fact_sum' => function () use ($contract, $tenantId, $stores, $range, $page, $pageSize): array { return $this->factDetailPage($contract['source'], $tenantId, $stores, $range, $page, $pageSize); },
            'personnel_fact_sum' => function () use ($contract, $tenantId, $stores, $range, $page, $pageSize): array { return $this->factDetailPage($contract['source'], $tenantId, $stores, $range, $page, $pageSize); },
        ];
        $handler = $handlers[$contract['reader_strategy']] ?? null;
        if (!$handler) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $handler();
    }

    /**
     * Fixed personnel-detail projection for report consumers. The caller may
     * select one already-authorized employee, but never receives a query to
     * alter the registered source, status guard or metric formula.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function personnelDetailPage(string $metricCode, string $tenantId, array $stores, array $range, int $employeeId, int $page, int $pageSize): array
    {
        $this->assertScope($tenantId, $stores, $range);
        if ($employeeId <= 0 || $page < 1 || $pageSize < 1 || $pageSize > 100 || $page * $pageSize > 10000) {
            $this->fail('METRIC_QUERY_PAGE_INVALID');
        }
        $contract = MetricDefinitionRegistry::get($metricCode);
        if (($contract['reader_strategy'] ?? null) !== 'personnel_fact_sum') $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $query = $this->factQuery($contract['source'], $tenantId, $stores, $range)->where('p.employee_id', $employeeId);
        $metricExpression = (string)$contract['source']['amount'];
        return [
            'rows' => (clone $query)->fieldRaw('p.id,p.fact_id,p.store_id,p.store_name_snapshot,p.organization_id,p.organization_path_snapshot,p.business_date,p.order_no_snapshot,p.member_name_snapshot,p.source_line_id,p.employee_id,p.employee_name_snapshot,p.fact_direction,' . $metricExpression . ' metric_value,p.labor_fee_amount_cents,p.project_count_half_units,p.rule_name_snapshot')
                ->order('p.business_date', 'desc')->order('p.id', 'desc')->page($page, $pageSize)->select()->toArray(),
            'total' => (int)(clone $query)->count('p.id'),
        ];
    }

    /**
     * Registered day/person projection for report tables.  It intentionally
     * returns an already-aggregated metric value; a report may decorate it
     * with organization labels but may not reopen a performance-fact query.
     *
     * @return array<int,array<string,mixed>>
     */
    public function personnelDailyTotals(string $metricCode, string $tenantId, array $stores, array $range, array $employeeIds = []): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        if (($contract['reader_strategy'] ?? null) !== 'personnel_fact_sum') $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $employeeIds = $this->employeeIds($employeeIds);
        $query = $this->factQuery($contract['source'], $tenantId, $stores, $range)->where('p.employee_id', '>', 0);
        if ($employeeIds !== []) $query->whereIn('p.employee_id', $employeeIds);
        $metricExpression = (string)$contract['source']['amount'];
        $rows = $query->fieldRaw('p.store_id,p.store_name_snapshot store_name,p.organization_id,p.organization_path_snapshot,p.business_date,p.employee_id,p.employee_name_snapshot employee_name,COALESCE(SUM(' . $metricExpression . '),0) metric_value,COALESCE(SUM(p.labor_fee_amount_cents),0) labor_fee_amount_cents,MAX(p.rule_name_snapshot) rule_name_snapshot')
            ->group('p.store_id,p.store_name_snapshot,p.organization_id,p.organization_path_snapshot,p.business_date,p.employee_id,p.employee_name_snapshot')
            ->order('p.employee_name_snapshot', 'asc')->order('p.business_date', 'asc')->limit(10001)->select()->toArray();
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        foreach ($rows as &$row) {
            $row['store_id'] = $this->integer($row['store_id'] ?? null);
            $row['employee_id'] = $this->integer($row['employee_id'] ?? null);
            $row['metric_value'] = $this->integer($row['metric_value'] ?? null);
            // Compatibility field for established read-only consumers.  It is
            // a projection of the registered metric value, never a raw fact
            // amount selected or recomputed by a page.
            $row['amount_cents'] = $row['metric_value'];
            $row['labor_fee_amount_cents'] = $this->integer($row['labor_fee_amount_cents'] ?? null);
        }
        unset($row);
        return $rows;
    }

    /**
     * Registered person-by-day report matrix.  Consumers may decorate a row
     * with current organization labels and format it, but do not sum metric
     * facts into day or total columns themselves.
     *
     * @return array{records:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function personnelDayMatrix(string $metricCode, string $tenantId, array $stores, array $range, array $employeeIds = []): array
    {
        $records = [];
        $summary = ['day_metric_values' => [], 'total_metric_value' => 0, 'day_labor_values' => [], 'total_labor_value' => 0];
        foreach ($this->personnelDailyTotals($metricCode, $tenantId, $stores, $range, $employeeIds) as $row) {
            $storeId = $this->integer($row['store_id'] ?? null);
            $employeeId = $this->integer($row['employee_id'] ?? null);
            $day = (int)substr((string)($row['business_date'] ?? ''), -2);
            if ($storeId <= 0 || $employeeId <= 0 || $day < 1 || $day > 31) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $key = $storeId . ':' . $employeeId;
            if (!isset($records[$key])) {
                $records[$key] = [
                    'store_id' => $storeId,
                    'store_name' => (string)($row['store_name'] ?? ''),
                    // Some valid V3 personnel facts predate an organization
                    // snapshot. The report resolver then uses the authorized
                    // store path, so this is optional metadata rather than a
                    // metric-source validity condition.
                    'organization_id' => (string)($row['organization_id'] ?? ''),
                    'organization_path_snapshot' => (string)($row['organization_path_snapshot'] ?? ''),
                    // This matrix spans multiple business days. Organization
                    // reporting dimensions are current-state labels, so the
                    // queried range end is only a valid projection date, not
                    // a fact date or a metric filter.
                    'business_date' => $range['end'],
                    'employee_id' => $employeeId,
                    'employee_name' => (string)($row['employee_name'] ?? ''),
                    'day_metric_values' => [], 'total_metric_value' => 0,
                    'day_labor_values' => [], 'total_labor_value' => 0,
                ];
            }
            $metricValue = $this->integer($row['metric_value'] ?? null);
            $laborValue = $this->integer($row['labor_fee_amount_cents'] ?? null);
            $records[$key]['day_metric_values'][$day] = $this->add((int)($records[$key]['day_metric_values'][$day] ?? 0), $metricValue);
            $records[$key]['total_metric_value'] = $this->add((int)$records[$key]['total_metric_value'], $metricValue);
            $records[$key]['day_labor_values'][$day] = $this->add((int)($records[$key]['day_labor_values'][$day] ?? 0), $laborValue);
            $records[$key]['total_labor_value'] = $this->add((int)$records[$key]['total_labor_value'], $laborValue);
            $summary['day_metric_values'][$day] = $this->add((int)($summary['day_metric_values'][$day] ?? 0), $metricValue);
            $summary['total_metric_value'] = $this->add((int)$summary['total_metric_value'], $metricValue);
            $summary['day_labor_values'][$day] = $this->add((int)($summary['day_labor_values'][$day] ?? 0), $laborValue);
            $summary['total_labor_value'] = $this->add((int)$summary['total_labor_value'], $laborValue);
        }
        return ['records' => array_values($records), 'summary' => $summary];
    }

    /**
     * Fixed personnel-detail result including its registered-metric totals.
     * A page may only format these values; it cannot re-sum fact amounts.
     *
     * @return array{rows:array<int,array<string,mixed>>,total_metric_value:int,total_labor_value:int}
     */
    public function personnelDetailResult(string $metricCode, string $tenantId, array $stores, array $range, array $employeeIds = [], int $dayOfMonth = 0): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        if (($contract['reader_strategy'] ?? null) !== 'personnel_fact_sum') $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($dayOfMonth < 0 || $dayOfMonth > 31) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        $employeeIds = $this->employeeIds($employeeIds);
        $query = $this->factQuery($contract['source'], $tenantId, $stores, $range)->where('p.employee_id', '>', 0);
        if ($employeeIds !== []) $query->whereIn('p.employee_id', $employeeIds);
        if ($dayOfMonth > 0) $query->whereRaw('DAY(p.business_date)=?', [$dayOfMonth]);
        $metricExpression = (string)$contract['source']['amount'];
        $rows = $query->fieldRaw('p.id,p.fact_id,p.store_id,p.store_name_snapshot,p.organization_id,p.organization_path_snapshot,p.business_date,p.order_id,p.order_no_snapshot,p.member_name_snapshot,p.source_line_id,p.employee_id,p.employee_name_snapshot,p.fact_direction,' . $metricExpression . ' metric_value,p.labor_fee_amount_cents,p.project_count_half_units,p.rule_name_snapshot')
            ->order('p.business_date', 'asc')->order('p.id', 'asc')->limit(10001)->select()->toArray();
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $totalMetric = 0;
        $totalLabor = 0;
        foreach ($rows as &$row) {
            $row['metric_value'] = $this->integer($row['metric_value'] ?? null);
            $row['labor_fee_amount_cents'] = $this->integer($row['labor_fee_amount_cents'] ?? null);
            $totalMetric = $this->add($totalMetric, $row['metric_value']);
            $totalLabor = $this->add($totalLabor, $row['labor_fee_amount_cents']);
        }
        unset($row);
        return ['rows' => $rows, 'total_metric_value' => $totalMetric, 'total_labor_value' => $totalLabor];
    }

    /** @return array{member_count:int,project_count:int} */
    public function personnelFactCounts(string $metricCode, string $tenantId, array $stores, array $range, int $employeeId): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $contract = MetricDefinitionRegistry::get($metricCode);
        if (($contract['reader_strategy'] ?? null) !== 'personnel_fact_sum' || $employeeId <= 0) {
            $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        }
        $row = $this->factQuery($contract['source'], $tenantId, $stores, $range)->where('p.employee_id', $employeeId)
            ->fieldRaw('COUNT(DISTINCT CASE WHEN p.member_id > 0 THEN p.member_id END) member_count,COUNT(DISTINCT NULLIF(p.source_line_id,\'\')) project_count')->find() ?: [];
        return [
            'member_count' => $this->integer($row['member_count'] ?? 0),
            'project_count' => $this->integer($row['project_count'] ?? 0),
        ];
    }

    /**
     * Registered category projection used by dashboards, drilldowns and exports.
     * The metric registration selects the projection strategy; consumers never
     * choose a fact table, amount expression or performance type.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoryRows(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds = [], array $filters = []): array
    {
        return $this->categoryRowsWithParticipant($metricCode, $tenantId, $stores, $range, $categoryIds, $filters, 0);
    }

    /** @return array<int,array<string,mixed>> */
    private function categoryRowsWithParticipant(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds, array $filters, int $participantEmployeeId): array
    {
        $this->assertScope($tenantId, $stores, $range);
        if ($categoryIds !== [] && array_keys($categoryIds) !== range(0, count($categoryIds) - 1)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        foreach ($categoryIds as $categoryId) if (!is_int($categoryId) || $categoryId <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        if (count(array_unique($categoryIds)) !== count($categoryIds)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        $filters = $this->normalizeCategoryFilters($filters);
        if ($participantEmployeeId > 0) $filters['participant_employee_id'] = $participantEmployeeId;

        $contract = MetricDefinitionRegistry::get($metricCode);
        $projection = $contract['category_reader'] ?? ($contract['source']['category_reader'] ?? null);
        if (!is_array($projection) || !is_string($projection['strategy'] ?? null)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $handlers = [
            'cash_sale_allocation' => function () use ($projection, $tenantId, $stores, $range, $categoryIds, $filters): array {
                return $this->cashCategoryRows($tenantId, $stores, $range, $categoryIds, (string)($projection['mode'] ?? ''), $filters);
            },
            'completed_service_performance' => function () use ($contract, $tenantId, $stores, $range, $categoryIds, $filters): array {
                return $this->completedServicePerformanceCategoryRows($contract['source'], $tenantId, $stores, $range, $categoryIds, $filters);
            },
            'completed_service_quantity' => function () use ($tenantId, $stores, $range, $categoryIds, $filters): array {
                return $this->completedServiceQuantityCategoryRows($tenantId, $stores, $range, $categoryIds, $filters);
            },
            'sale_completed_allocation' => function () use ($tenantId, $stores, $range, $categoryIds, $filters): array {
                return $this->completedSaleCategoryRows($tenantId, $stores, $range, $categoryIds, $filters);
            },
        ];
        $handler = $handlers[$projection['strategy']] ?? null;
        if (!$handler) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $handler();
    }

    /**
     * Registered category-filtered total.  Category filtering changes the
     * population, not the metric formula, so the aggregation belongs to the
     * reader rather than a dashboard/controller consumer.
     */
    public function categorySummary(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds = [], array $filters = []): int
    {
        $contract = MetricDefinitionRegistry::get($metricCode);
        $field = ($contract['storage_unit'] ?? null) === 'count' ? 'quantity' : 'amount_cents';
        return $this->categoryAggregate($this->categoryRows($metricCode, $tenantId, $stores, $range, $categoryIds, $filters), $field);
    }

    /**
     * Registered category population reduced to report-ready store/day
     * buckets. A report may resolve its display-only organization labels, but
     * cannot reopen facts or sum source rows into a metric.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoryReportBuckets(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds = [], array $filters = []): array
    {
        return $this->categoryReportBucketsWithParticipant($metricCode, $tenantId, $stores, $range, $categoryIds, $filters, 0);
    }

    /**
     * Server-only participant variant. The authenticated report-scope
     * resolver supplies the employee identity; it is deliberately not a
     * client-provided category filter.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoryReportBucketsForParticipant(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds, array $filters, int $participantEmployeeId): array
    {
        if ($participantEmployeeId <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        return $this->categoryReportBucketsWithParticipant($metricCode, $tenantId, $stores, $range, $categoryIds, $filters, $participantEmployeeId);
    }

    /** @return array<int,array<string,mixed>> */
    private function categoryReportBucketsWithParticipant(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds, array $filters, int $participantEmployeeId): array
    {
        $contract = MetricDefinitionRegistry::get($metricCode);
        $field = ($contract['storage_unit'] ?? null) === 'count' ? 'quantity' : 'amount_cents';
        $buckets = [];
        $storeTotals = [];
        $dimensions = new StoreUnifiedReportOrganizationDimensionServices();
        foreach ($this->categoryRowsWithParticipant($metricCode, $tenantId, $stores, $range, $categoryIds, $filters, $participantEmployeeId) as $row) {
            $storeId = $this->integer($row['store_id'] ?? null);
            $day = (string)($row['business_date'] ?? '');
            if (!in_array($storeId, $stores, true) || $day < $range['start'] || $day > $range['end']) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $categoryId = $this->integer($row['category_id'] ?? $row['project_category_id_snapshot'] ?? 0);
            $categoryPath = (string)($row['category_path'] ?? $row['category_path_snapshot'] ?? $row['project_category_path_snapshot'] ?? '');
            // Organization dimensions are a server-owned report projection.
            // Resolve them before grouping so consumers receive one final
            // display bucket instead of re-adding business metric values.
            $dimensions->project(
                $row,
                (string)($row['organization_id'] ?? ''),
                (string)($row['organization_path_snapshot'] ?? ''),
                $day
            );
            $companyId = (string)($row['company_dimension_id'] ?? '');
            $managerId = (string)($row['city_manager_dimension_id'] ?? '');
            $storeKey = implode('|', [$storeId, $companyId, $managerId]);
            $key = implode('|', [$storeKey, $categoryId, $categoryPath]);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'store_id' => $storeId, 'business_date' => $day,
                    'store_name_snapshot' => (string)($row['store_name_snapshot'] ?? $row['store_name'] ?? ''),
                    'organization_id' => (int)($row['organization_id'] ?? 0),
                    'organization_path_snapshot' => (string)($row['organization_path_snapshot'] ?? ''),
                    'company_dimension_id' => $companyId,
                    'company' => (string)($row['company'] ?? ''),
                    'city_manager_dimension_id' => $managerId,
                    'city_manager' => (string)($row['city_manager'] ?? ''),
                    'category_id' => $categoryId, 'category_path' => $categoryPath,
                    'metric_value' => 0,
                ];
            }
            $amount = $this->integer($row[$field] ?? null);
            $buckets[$key]['metric_value'] = $this->add($buckets[$key]['metric_value'], $amount);
            $storeTotals[$storeKey] = $this->add((int)($storeTotals[$storeKey] ?? 0), $amount);
        }
        foreach ($buckets as &$bucket) {
            $storeKey = implode('|', [(int)$bucket['store_id'], (string)$bucket['company_dimension_id'], (string)$bucket['city_manager_dimension_id']]);
            $bucket['store_metric_value'] = (int)($storeTotals[$storeKey] ?? 0);
        }
        unset($bucket);
        ksort($buckets, SORT_STRING);
        return array_values($buckets);
    }

    /** @return array<int,array{store_id:int,business_date:string,amount_cents:int}> */
    public function categoryDailyStoreTotals(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds = [], array $filters = []): array
    {
        $contract = MetricDefinitionRegistry::get($metricCode);
        $field = ($contract['storage_unit'] ?? null) === 'count' ? 'quantity' : 'amount_cents';
        $rows = $this->categoryRows($metricCode, $tenantId, $stores, $range, $categoryIds, $filters);
        $grouped = [];
        foreach ($rows as $row) {
            $storeId = $this->integer($row['store_id'] ?? null);
            $day = $row['business_date'] ?? null;
            if (!in_array($storeId, $stores, true) || !is_string($day) || $day < $range['start'] || $day > $range['end']) {
                $this->fail('METRIC_SOURCE_RESULT_INVALID');
            }
            $key = $storeId . ':' . $day;
            if (!isset($grouped[$key])) $grouped[$key] = ['store_id' => $storeId, 'business_date' => $day, 'amount_cents' => 0];
            $grouped[$key]['amount_cents'] = $this->add($grouped[$key]['amount_cents'], $this->integer($row[$field] ?? null));
        }
        ksort($grouped, SORT_STRING);
        return array_values($grouped);
    }

    /** @return array<int,array{business_date:string,metric_value:int}> */
    public function categoryDailyTotals(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds = [], array $filters = []): array
    {
        $byDay = [];
        foreach ($this->categoryDailyStoreTotals($metricCode, $tenantId, $stores, $range, $categoryIds, $filters) as $point) {
            $day = (string)($point['business_date'] ?? '');
            if ($day === '') $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $byDay[$day] = $this->add((int)($byDay[$day] ?? 0), $this->integer($point['amount_cents'] ?? null));
        }
        ksort($byDay, SORT_STRING);
        $out = [];
        foreach ($byDay as $day => $value) $out[] = ['business_date' => $day, 'metric_value' => $value];
        return $out;
    }

    /** @return array<int,array{store_id:int,amount_cents:int}> */
    public function categoryStoreTotals(string $metricCode, string $tenantId, array $stores, array $range, array $categoryIds = [], array $filters = []): array
    {
        $out = [];
        foreach ($this->categoryDailyStoreTotals($metricCode, $tenantId, $stores, $range, $categoryIds, $filters) as $row) {
            $storeId = (int)$row['store_id'];
            if (!isset($out[$storeId])) $out[$storeId] = ['store_id' => $storeId, 'amount_cents' => 0];
            $out[$storeId]['amount_cents'] = $this->add($out[$storeId]['amount_cents'], (int)$row['amount_cents']);
        }
        ksort($out, SORT_NUMERIC);
        return array_values($out);
    }

    /**
     * Registered category-card projection.  The dashboard provides only its
     * active taxonomy labels; category membership, cash amount and item
     * ranking are all calculated from one registered category population.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoryDashboardCards(string $metricCode, string $tenantId, array $stores, array $range, array $roots, array $children, array $categoryIds = []): array
    {
        $rows = array_values(array_filter(
            $this->categoryRows($metricCode, $tenantId, $stores, $range, $categoryIds),
            static function (array $row): bool { return (int)($row['amount_cents'] ?? 0) > 0; }
        ));
        $total = $this->categoryAggregate($rows, 'amount_cents');
        $cards = [];
        $categorized = 0;
        foreach ($roots as $root) {
            $rootId = $this->integer($root['id'] ?? null);
            $name = trim((string)($root['name'] ?? ''));
            if ($rootId <= 0 || $name === '') $this->fail('METRIC_SOURCE_SCOPE_INVALID');
            $ids = array_fill_keys($this->categoryDescendants($rootId, $children), true);
            $matched = array_values(array_filter($rows, static function (array $row) use ($ids): bool {
                return isset($ids[(int)($row['category_id'] ?? 0)]);
            }));
            $amount = $this->categoryAggregate($matched, 'amount_cents');
            $categorized = $this->add($categorized, $amount);
            $cards[] = [
                'category_id' => $rootId, 'name' => $name, 'cash_performance_cents' => $amount,
                'share' => $total === 0 ? null : round($amount / $total * 100, 1),
                'drilldown' => ['metric_code' => $metricCode, 'category_id' => $rootId],
                'project_rankings' => $this->categoryTop($matched, 'item_name', '项目'),
                'product_rankings' => $this->categoryTop(array_values(array_filter($matched, static function (array $row): bool {
                    return (string)($row['product_type_snapshot'] ?? '') !== 'project';
                })), 'item_name', '产品'),
                'source_explanation' => '当前启用一级商品分类及全部下级分类的现金业绩；卡项按卡内项目分类和分摊金额归入。',
            ];
        }
        $unclassified = $this->add($total, -$categorized);
        if ($unclassified !== 0) {
            $classified = [];
            foreach ($roots as $root) foreach ($this->categoryDescendants($this->integer($root['id'] ?? null), $children) as $id) $classified[$id] = true;
            $matched = array_values(array_filter($rows, static function (array $row) use ($classified): bool {
                $categoryId = (int)($row['category_id'] ?? 0);
                return $categoryId <= 0 || !isset($classified[$categoryId]);
            }));
            $cards[] = [
                'category_id' => 0, 'name' => '未分类', 'cash_performance_cents' => $unclassified,
                'share' => $total === 0 ? null : round($unclassified / $total * 100, 1),
                'drilldown' => ['metric_code' => $metricCode],
                'project_rankings' => $this->categoryTop($matched, 'item_name', '项目'),
                'product_rankings' => $this->categoryTop(array_values(array_filter($matched, static function (array $row): bool {
                    return (string)($row['product_type_snapshot'] ?? '') !== 'project';
                })), 'item_name', '产品'),
                'source_explanation' => '未能匹配当前启用一级商品分类的现金业绩；保留在独立未分类卡中，确保分类合计与现金业绩一致。',
            ];
        }
        return $cards;
    }

    private function categoryAggregate(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) $total = $this->add($total, $this->integer($row[$field] ?? null));
        return $total;
    }

    /** @return array<int,int> */
    private function categoryDescendants(int $root, array $children): array
    {
        $out = []; $queue = [$root];
        while ($queue !== []) {
            $id = (int)array_shift($queue);
            if ($id <= 0 || isset($out[$id])) continue;
            $out[$id] = $id;
            foreach ((array)($children[$id] ?? []) as $child) $queue[] = (int)$child;
        }
        return array_values($out);
    }

    /** @return array<int,array{name:string,amount_cents:int,type:string}> */
    private function categoryTop(array $rows, string $field, string $type): array
    {
        $amounts = [];
        foreach ($rows as $row) {
            $name = trim((string)($row[$field] ?? ''));
            if ($name !== '') $amounts[$name] = $this->add((int)($amounts[$name] ?? 0), (int)($row['amount_cents'] ?? 0));
        }
        arsort($amounts, SORT_NUMERIC);
        $out = [];
        foreach (array_slice($amounts, 0, 5, true) as $name => $amount) $out[] = ['name' => $name, 'amount_cents' => $amount, 'type' => $type];
        return $out;
    }

    /** @return array<int,int> */
    private function employeeIds(array $employeeIds): array
    {
        $out = [];
        foreach ($employeeIds as $employeeId) {
            if (!is_int($employeeId) || $employeeId <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
            $out[$employeeId] = $employeeId;
        }
        if (count($out) > 1000) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        return array_values($out);
    }

    /** Registered category totals keyed by source line and frozen category. */
    public function sourceLineCategoryTotals(string $metricCode, string $tenantId, array $stores, array $range, array $sourceLineIds, array $filters = []): array
    {
        $this->assertScope($tenantId, $stores, $range);
        $sourceLineIds = $this->sourceLineIds($sourceLineIds);
        $filters = $this->normalizeCategoryFilters($filters);
        $contract = MetricDefinitionRegistry::get($metricCode);
        $projection = $contract['category_reader'] ?? ($contract['source']['category_reader'] ?? null);
        $handlers = [
            'completed_service_performance' => function () use ($contract, $tenantId, $stores, $range, $sourceLineIds, $filters): array {
                return $this->completedServicePerformanceSourceLineCategoryTotals($contract['source'], $tenantId, $stores, $range, $sourceLineIds, $filters);
            },
            'sale_completed_allocation' => function () use ($tenantId, $stores, $range, $sourceLineIds, $filters): array {
                return $this->completedSaleSourceLineCategoryTotals($tenantId, $stores, $range, $sourceLineIds, $filters);
            },
        ];
        $handler = $handlers[$projection['strategy'] ?? ''] ?? null;
        if (!$handler) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $handler();
    }

    /** @return array<string,int> */
    private function completedServicePerformanceSourceLineCategoryTotals(array $source, string $tenantId, array $stores, array $range, array $sourceLineIds, array $filters): array
    {
        $query = call_user_func($this->queryFactory, $source['table'])->alias('p')
            ->join('cashier_v3_entitlement_service_fact sv', "sv.tenant_id=p.tenant_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status='completed'")
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)->whereIn('p.source_line_id', $sourceLineIds)
            ->whereBetween('p.business_date', [$range['start'], $range['end']]);
        foreach ($source['filters'] as $field => $value) $query->where('p.' . $field, $value);
        call_user_func($this->normalServices, $query, 'sv');
        $this->applyServiceFilters($query, $filters, 'p', 'sv');
        $rows = $query->fieldRaw('p.source_line_id,sv.project_category_id_snapshot category_id,COALESCE(NULLIF(sv.project_category_path_snapshot,\'\'),sv.project_category_name_snapshot) category_path,COALESCE(SUM(' . $source['amount'] . '),0) amount_cents')
            ->group('p.source_line_id,sv.project_category_id_snapshot,sv.project_category_path_snapshot,sv.project_category_name_snapshot')->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $line = (string)($row['source_line_id'] ?? '');
            $categoryId = $this->integer($row['category_id'] ?? null);
            if (!in_array($line, $sourceLineIds, true) || $categoryId < 0) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $key = $line . '|' . $categoryId;
            if (isset($out[$key])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $out[$key] = $this->integer($row['amount_cents'] ?? null);
        }
        return $out;
    }

    /** @return array<string,int> */
    private function completedSaleSourceLineCategoryTotals(string $tenantId, array $stores, array $range, array $sourceLineIds, array $filters): array
    {
        $out = [];
        foreach ($this->completedSaleCategoryRows($tenantId, $stores, $range, [], $filters, $sourceLineIds) as $row) {
            $line = (string)($row['source_line_id'] ?? '');
            $categoryId = $this->integer($row['category_id'] ?? null);
            if (!in_array($line, $sourceLineIds, true) || $categoryId < 0) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $key = $line . '|' . $categoryId;
            $out[$key] = $this->add((int)($out[$key] ?? 0), $this->integer($row['amount_cents'] ?? null));
        }
        return $out;
    }

    private function cashSummary(string $tenantId, array $stores, array $range, string $mode): int
    {
        $expression = $this->cashExpression($mode);
        $sale = $this->aggregate($this->saleCashQuery($tenantId, $stores, $range), $expression);
        $recharge = $this->aggregate($this->rechargeCashQuery($tenantId, $stores, $range), $expression);
        return $this->add($sale, $recharge);
    }

    private function derivedSummary(array $contract, string $tenantId, array $stores, array $range): int
    {
        $derivation = $contract['derivation'] ?? null;
        if (!is_array($derivation) || ($derivation['operator'] ?? null) !== 'subtract') $this->fail('METRIC_SOURCE_TYPE_INVALID');
        $left = $this->summary((string)($derivation['left_metric'] ?? ''), $tenantId, $stores, $range);
        $right = $this->summary((string)($derivation['right_metric'] ?? ''), $tenantId, $stores, $range);
        return $this->add($left, -$right);
    }

    /** @return array<int,array{store_id:int,business_date:string,amount_cents:int}> */
    private function derivedGrouped(array $contract, string $tenantId, array $stores, array $range): array
    {
        $derivation = $contract['derivation'] ?? null;
        if (!is_array($derivation) || ($derivation['operator'] ?? null) !== 'subtract') $this->fail('METRIC_SOURCE_TYPE_INVALID');
        $left = $this->dailyStoreTotals((string)($derivation['left_metric'] ?? ''), $tenantId, $stores, $range);
        $right = $this->dailyStoreTotals((string)($derivation['right_metric'] ?? ''), $tenantId, $stores, $range);
        foreach ($right as &$point) $point['amount_cents'] = -(int)$point['amount_cents'];
        unset($point);
        return $this->mergePoints($left, $right);
    }

    private function cashDetailPage(string $tenantId, array $stores, array $range, string $mode, int $page, int $pageSize): array
    {
        $sale = $this->saleCashQuery($tenantId, $stores, $range);
        $recharge = $this->rechargeCashQuery($tenantId, $stores, $range);
        $this->applyCashDirection($sale, $mode, 'p.amount_cents');
        $this->applyCashDirection($recharge, $mode, 'p.amount_cents');

        $saleTotal = (int)(clone $sale)->count('p.id');
        $rechargeTotal = (int)(clone $recharge)->count('p.id');
        $fetch = $page * $pageSize;
        $amount = $mode === 'refund' ? '-p.amount_cents' : 'p.amount_cents';

        $saleRows = (clone $sale)->fieldRaw(
            "p.id,CONCAT('sale:',p.id) detail_sort_key,p.allocation_fact_id fact_id,p.store_id,p.member_id,p.order_id,p.order_no_snapshot,p.business_date,p.occurred_at,p.settled_at,p.recorded_at,p.status,{$amount} metric_value,s.member_name_snapshot,s.operator_id,s.operator_name_snapshot"
        )->order('p.settled_at', 'desc')->order('p.id', 'desc')->limit($fetch)->select()->toArray();
        $rechargeRows = (clone $recharge)->fieldRaw(
            "p.id,CONCAT('recharge:',p.id) detail_sort_key,p.fact_id,p.store_id,p.member_id,p.order_id,p.order_no_snapshot,p.business_date,p.occurred_at,p.settled_at,p.recorded_at,p.status,{$amount} metric_value,p.member_name_snapshot,p.operator_id,p.operator_name_snapshot"
        )->order('p.settled_at', 'desc')->order('p.id', 'desc')->limit($fetch)->select()->toArray();

        $rows = array_merge($saleRows, $rechargeRows);
        usort($rows, static function (array $left, array $right): int {
            $time = ((int)($right['settled_at'] ?? 0)) <=> ((int)($left['settled_at'] ?? 0));
            if ($time !== 0) return $time;
            $id = ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0));
            if ($id !== 0) return $id;
            return strcmp((string)($right['detail_sort_key'] ?? ''), (string)($left['detail_sort_key'] ?? ''));
        });
        $offset = ($page - 1) * $pageSize;
        $pageRows = array_slice($rows, $offset, $pageSize);
        foreach ($pageRows as &$row) unset($row['detail_sort_key']);
        unset($row);
        return [
            'rows' => $pageRows,
            'total' => $this->add($saleTotal, $rechargeTotal),
        ];
    }

    private function factDetailPage(array $source, string $tenantId, array $stores, array $range, int $page, int $pageSize): array
    {
        $query = $this->factQuery($source, $tenantId, $stores, $range);
        return [
            'rows' => (clone $query)->fieldRaw('p.*,' . $source['amount'] . ' metric_value')->order('p.settled_at', 'desc')->order('p.id', 'desc')->page($page, $pageSize)->select()->toArray(),
            'total' => (int)(clone $query)->count('p.id'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function cashCategoryRows(string $tenantId, array $stores, array $range, array $categoryIds, string $mode, array $filters): array
    {
        if (!in_array($mode, ['positive', 'refund', 'net'], true)) $this->fail('METRIC_SOURCE_TYPE_INVALID');
        $base = $this->saleCashQuery($tenantId, $stores, $range);
        $this->applyParticipantScope($base, $filters, 'order');
        $this->applyCashDirection($base, $mode, 'p.amount_cents');

        $direct = clone $base;
        $direct->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->where('s.source_type', '<>', 'card');
        $this->applySaleRelationFilters($direct, $filters, 'd');
        if ($categoryIds !== []) $direct->whereIn('d.category_id_snapshot', $categoryIds);
        $amount = $mode === 'refund' ? '-p.amount_cents' : 'p.amount_cents';
        $rows = $direct->fieldRaw("p.id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,{$amount} amount_cents,p.organization_id,s.organization_path_snapshot,s.store_name_snapshot store_name,s.business_source_primary_id,s.business_source_label_snapshot source_label,d.item_id,d.item_name_snapshot item_name,d.product_type_snapshot,d.category_id_snapshot category_id,d.category_path_snapshot category_path")
            ->select()->toArray();

        $cardBase = (clone $base)->where('s.source_type', 'card');
        $this->applyPeopleFilters($cardBase, $filters, 's');
        if (isset($filters['is_experience'])) $cardBase->whereExists(function ($sub) use ($filters): void {
            $sub->name('cashier_v3_report_sale_dimension_fact')->alias('metric_experience')
                ->whereRaw('metric_experience.tenant_id=s.tenant_id AND metric_experience.sale_fact_id=s.fact_id')
                ->where('metric_experience.is_experience', $filters['is_experience']);
        });
        $cards = $cardBase
            ->fieldRaw("p.id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,{$amount} amount_cents,p.organization_id,s.organization_path_snapshot,s.store_name_snapshot store_name,s.business_source_primary_id,s.business_source_label_snapshot source_label,s.fact_id sale_fact_id")
            ->select()->toArray();

        $populationFilters = $filters;
        unset($populationFilters['participant_employee_id']);
        if ($categoryIds === [] && $populationFilters === []) {
            $recharge = $this->rechargeCashQuery($tenantId, $stores, $range);
            $this->applyParticipantScope($recharge, $filters, 'order');
            $this->applyCashDirection($recharge, $mode, 'p.amount_cents');
            $rows = array_merge($rows, $recharge->fieldRaw(
                "CONCAT('recharge-payment:',p.fact_id) id,p.fact_id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,{$amount} amount_cents,p.organization_id,p.organization_path_snapshot,p.store_name_snapshot store_name,p.business_source_primary_id,p.business_source_label_snapshot source_label,0 item_id,CASE WHEN p.source_document_type='recharge' THEN '充值' ELSE '充值欠款补交' END item_name,'recharge' product_type_snapshot,0 category_id,'' category_path"
            )->select()->toArray());
        }
        if ($cards === []) return $rows;

        $saleIds = array_values(array_unique(array_filter(array_column($cards, 'sale_fact_id'))));
        $itemsBySale = [];
        if ($saleIds !== []) {
            foreach (call_user_func($this->queryFactory, 'cashier_v3_card_sale_category_allocation_fact')
                ->where('tenant_id', $tenantId)->whereIn('sale_fact_id', $saleIds)->where('status', 'effective')
                ->field('id,sale_fact_id,component_product_id,category_name_snapshot,category_id_snapshot,category_path_snapshot,partner_name_snapshot,product_type_snapshot,component_count,sale_amount_cents,configured_amount_cents')
                ->order('id', 'asc')->select()->toArray() as $item) {
                $itemsBySale[(string)$item['sale_fact_id']][] = $item;
            }
        }
        $allowed = array_fill_keys($categoryIds, true);
        foreach ($cards as $payment) {
            $items = $itemsBySale[(string)$payment['sale_fact_id']] ?? [];
            if ($items === []) {
                if ($allowed === [] && !isset($filters['category_path']) && !isset($filters['product_type']) && !isset($filters['partner_name'])) $rows[] = array_merge($payment, [
                    'item_id' => 0, 'item_name' => '卡项（分类待补齐）', 'product_type_snapshot' => 'card_unclassified',
                    'category_id' => 0, 'category_path' => '', 'classification_coverage' => 'missing_card_components',
                ]);
                continue;
            }
            foreach ($this->allocate((int)$payment['amount_cents'], $items) as $allocation) {
                $item = $allocation['item'];
                $categoryId = (int)($item['category_id_snapshot'] ?? 0);
                if ($allowed !== [] && !isset($allowed[$categoryId])) continue;
                if (!$this->categorySnapshotMatches($item, $filters)) continue;
                $rows[] = array_merge($payment, [
                    'amount_cents' => $allocation['amount_cents'], 'item_id' => (string)$item['component_product_id'],
                    'item_name' => (string)$item['category_name_snapshot'], 'product_type_snapshot' => 'card_component',
                    'category_id' => $categoryId, 'category_path' => (string)$item['category_path_snapshot'],
                ]);
            }
        }
        return $rows;
    }

    /**
     * Category projection for the registered sales amount.  A direct sale
     * keeps its frozen sales dimension; a card sale is expanded only by its
     * immutable component allocation.  This is intentionally distinct from
     * cash performance: a sale belongs to its completion business date and
     * never gains later recharge/payment rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function completedSaleCategoryRows(string $tenantId, array $stores, array $range, array $categoryIds, array $filters, array $sourceLineIds = []): array
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_sale_fact')->alias('s')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->leftJoin('cashier_v3_card_sale_category_allocation_fact c', "c.tenant_id=s.tenant_id AND c.sale_fact_id=s.fact_id AND c.status='effective'")
            ->where('s.tenant_id', $tenantId)->whereIn('s.store_id', $stores)
            ->whereBetween('s.business_date', [$range['start'], $range['end']])->where('s.status', 'effective');
        call_user_func($this->normalFacts, $query, 's.tenant_id', 's.order_id');
        $this->applyParticipantScope($query, $filters, 'order');
        if ($sourceLineIds !== []) $query->whereIn('s.source_line_id', $sourceLineIds);
        if ($categoryIds !== []) {
            $query->whereRaw('COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ')', $categoryIds);
        }
        $this->applySaleAllocationFilters($query, $filters);
        return $query->fieldRaw("s.fact_id,s.store_id,s.member_id,s.order_id,s.source_line_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,COALESCE(c.sale_amount_cents,s.sale_amount_cents) amount_cents,COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) category_id,COALESCE(c.category_path_snapshot,d.category_path_snapshot,'') category_path,COALESCE(c.product_type_snapshot,d.product_type_snapshot,'') product_type_snapshot,COALESCE(c.partner_name_snapshot,d.partner_name_snapshot,'') partner_name_snapshot,d.is_experience")
            ->select()->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function completedServicePerformanceCategoryRows(array $source, string $tenantId, array $stores, array $range, array $categoryIds, array $filters): array
    {
        $query = call_user_func($this->queryFactory, $source['table'])->alias('p')
            ->join('cashier_v3_entitlement_service_fact sv', "sv.tenant_id=p.tenant_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status='completed'")
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']]);
        foreach ($source['filters'] as $field => $value) $query->where('p.' . $field, $value);
        call_user_func($this->normalServices, $query, 'sv');
        $this->applyParticipantScope($query, $filters, 'checkout:p');
        if ($categoryIds !== []) $query->whereIn('sv.project_category_id_snapshot', $categoryIds);
        $this->applyServiceFilters($query, $filters, 'p', 'sv');
        return $query->fieldRaw("p.fact_id,p.store_id,p.member_id,p.order_id,p.checkout_request_id,p.source_line_id,p.business_date,p.amount_cents,p.organization_id,p.organization_path_snapshot,p.store_name_snapshot,sv.project_category_id_snapshot category_id,sv.project_category_id_snapshot,sv.project_category_path_snapshot,COALESCE(NULLIF(sv.project_category_path_snapshot,''),sv.project_category_name_snapshot) category_path_snapshot")
            ->select()->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function completedServiceQuantityCategoryRows(string $tenantId, array $stores, array $range, array $categoryIds, array $filters): array
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_entitlement_service_fact')->alias('s')
            ->where('s.tenant_id', $tenantId)->whereIn('s.store_id', $stores)
            ->whereBetween('s.business_date', [$range['start'], $range['end']])->where('s.service_status', 'completed');
        call_user_func($this->normalServices, $query, 's');
        $this->applyParticipantScope($query, $filters, 'checkout:s');
        if ($categoryIds !== []) $query->whereIn('s.project_category_id_snapshot', $categoryIds);
        $this->applyServiceFilters($query, $filters, 's', 's');
        return $query->fieldRaw("s.service_fact_id,s.store_id,s.member_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,s.project_id,s.project_name_snapshot,s.project_category_id_snapshot,s.project_category_path_snapshot,s.source_line_id,s.quantity,(SELECT MAX(sf.business_source_primary_id) FROM eb_cashier_v3_sale_fact sf WHERE sf.tenant_id=s.tenant_id AND sf.checkout_request_id=s.checkout_request_id AND sf.source_line_id=s.source_line_id AND sf.fact_direction='forward' AND sf.status='effective') business_source_primary_id")
            ->select()->toArray();
    }

    private function normalizeCategoryFilters(array $filters): array
    {
        $allowed = ['category_path', 'product_type', 'partner_name', 'salesperson_id', 'guide_id', 'sales_manager_id', 'is_experience'];
        foreach ($filters as $key => $value) if (!is_string($key) || !in_array($key, $allowed, true)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        $out = [];
        foreach (['category_path', 'product_type', 'partner_name'] as $key) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                if (strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
                $out[$key] = $value;
            }
        }
        foreach (['salesperson_id', 'guide_id', 'sales_manager_id'] as $key) {
            $value = (int)($filters[$key] ?? 0);
            if ($value > 0) $out[$key] = $value;
        }
        if (array_key_exists('is_experience', $filters) && $filters['is_experience'] !== '' && $filters['is_experience'] !== null) {
            $value = (int)$filters['is_experience'];
            if (!in_array($value, [0, 1], true)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
            $out['is_experience'] = $value;
        }
        return $out;
    }

    private function sourceLineIds(array $sourceLineIds): array
    {
        if ($sourceLineIds === [] || array_keys($sourceLineIds) !== range(0, count($sourceLineIds) - 1) || count($sourceLineIds) > 10000) {
            $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        }
        foreach ($sourceLineIds as $line) {
            if (!is_string($line) || $line === '' || strlen($line) > 128 || preg_match('/[\x00-\x1f\x7f]/', $line)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        }
        return array_values(array_unique($sourceLineIds));
    }

    private function applySaleRelationFilters($query, array $filters, string $dimensionAlias): void
    {
        if (isset($filters['category_path'])) $query->whereLike($dimensionAlias . '.category_path_snapshot', $filters['category_path'] . '%');
        if (isset($filters['product_type'])) $query->where($dimensionAlias . '.product_type_snapshot', $filters['product_type']);
        if (isset($filters['partner_name'])) $query->where($dimensionAlias . '.partner_name_snapshot', $filters['partner_name']);
        if (isset($filters['is_experience'])) $query->where($dimensionAlias . '.is_experience', $filters['is_experience']);
        $this->applyPeopleFilters($query, $filters, 's');
    }

    /**
     * The completed-sale category reader can receive its immutable category
     * from either the direct-sale snapshot or a card component allocation.
     * Keep that coalescing rule in the reader instead of teaching reports how
     * to filter either physical representation.
     */
    private function applySaleAllocationFilters($query, array $filters): void
    {
        if (isset($filters['category_path'])) $query->whereRaw('COALESCE(c.category_path_snapshot,d.category_path_snapshot,\'\') LIKE ?', [$filters['category_path'] . '%']);
        if (isset($filters['product_type'])) $query->whereRaw('COALESCE(c.product_type_snapshot,d.product_type_snapshot,\'\')=?', [$filters['product_type']]);
        if (isset($filters['partner_name'])) $query->whereRaw('COALESCE(c.partner_name_snapshot,d.partner_name_snapshot,\'\')=?', [$filters['partner_name']]);
        if (isset($filters['is_experience'])) $query->where('d.is_experience', $filters['is_experience']);
        $this->applyPeopleFilters($query, $filters, 's');
    }

    private function applyPeopleFilters($query, array $filters, string $saleAlias): void
    {
        if (isset($filters['salesperson_id'])) $query->whereExists(function ($sub) use ($filters, $saleAlias): void {
            $sub->name('cashier_v3_performance_fact')->alias('metric_salesperson')
                ->whereRaw("metric_salesperson.tenant_id={$saleAlias}.tenant_id AND metric_salesperson.store_id={$saleAlias}.store_id AND metric_salesperson.source_line_id={$saleAlias}.source_line_id")
                ->where('metric_salesperson.employee_id', $filters['salesperson_id'])->where('metric_salesperson.performance_type', 'sales_performance_allocated')->where('metric_salesperson.status', 'effective');
        });
        if (isset($filters['guide_id'])) $query->whereExists(function ($sub) use ($filters, $saleAlias): void {
            $sub->name('cashier_v3_customer_guide_round_fact')->alias('metric_guide')
                ->whereRaw("metric_guide.tenant_id={$saleAlias}.tenant_id AND metric_guide.store_id={$saleAlias}.store_id AND metric_guide.order_id={$saleAlias}.order_id")
                ->where('metric_guide.guide_employee_id', $filters['guide_id'])->where('metric_guide.status', 'effective');
        });
        if (isset($filters['sales_manager_id'])) $query->whereExists(function ($sub) use ($filters, $saleAlias): void {
            $sub->name('cashier_v3_sales_manager_fact')->alias('metric_manager')
                ->whereRaw("metric_manager.tenant_id={$saleAlias}.tenant_id AND metric_manager.store_id={$saleAlias}.store_id AND metric_manager.order_id={$saleAlias}.order_id")
                ->where('metric_manager.sales_manager_employee_id', $filters['sales_manager_id'])->where('metric_manager.status', 'effective');
        });
    }

    /**
     * A report service resolves the authenticated person's data scope and
     * supplies this server-only narrowing guard. It cannot expand the store
     * scope already asserted by the registered reader.
     */
    private function applyParticipantScope($query, array $filters, string $relation): void
    {
        $employeeId = (int)($filters['participant_employee_id'] ?? 0);
        if ($employeeId <= 0) return;
        $scope = new StoreReportParticipantScopeServices();
        if ($relation === 'order') {
            $scope->applyOrder($query, 'p.order_id', $employeeId);
            return;
        }
        if (strpos($relation, 'checkout:') === 0) {
            $alias = substr($relation, strlen('checkout:'));
            if (!in_array($alias, ['p', 's'], true)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
            $scope->applyCheckout($query, $alias . '.checkout_request_id', $employeeId);
            return;
        }
        $this->fail('METRIC_SOURCE_SCOPE_INVALID');
    }

    private function applyServiceFilters($query, array $filters, string $factAlias, string $serviceAlias): void
    {
        if (isset($filters['category_path'])) $query->whereLike($serviceAlias . '.project_category_path_snapshot', $filters['category_path'] . '%');
        if (isset($filters['product_type']) && $filters['product_type'] !== 'project') $query->whereRaw('1=0');
        if (isset($filters['is_experience'])) $query->where($serviceAlias . '.is_experience', $filters['is_experience']);
        if (isset($filters['partner_name'])) $query->where(function ($partner) use ($filters, $factAlias): void {
            $partner->whereExists(function ($sub) use ($filters, $factAlias): void {
                $sub->name('cashier_v3_report_sale_dimension_fact')->alias('metric_partner')
                    ->whereRaw("metric_partner.tenant_id={$factAlias}.tenant_id AND metric_partner.store_id={$factAlias}.store_id AND metric_partner.source_line_id={$factAlias}.source_line_id")
                    ->where('metric_partner.partner_name_snapshot', $filters['partner_name']);
            })->whereExists(function ($card) use ($filters, $factAlias): void {
                $card->name('cashier_v3_card_sale_category_allocation_fact')->alias('metric_card_partner')
                    ->whereRaw("metric_card_partner.tenant_id={$factAlias}.tenant_id AND metric_card_partner.store_id={$factAlias}.store_id AND metric_card_partner.source_line_id={$factAlias}.source_line_id")
                    ->where('metric_card_partner.status', 'effective')->where('metric_card_partner.partner_name_snapshot', $filters['partner_name']);
            }, 'OR');
        });
        $this->applyPeopleFilters($query, $filters, $factAlias);
    }

    private function categorySnapshotMatches(array $item, array $filters): bool
    {
        if (isset($filters['category_path']) && strpos((string)($item['category_path_snapshot'] ?? ''), $filters['category_path']) !== 0) return false;
        if (isset($filters['product_type']) && (string)($item['product_type_snapshot'] ?? '') !== $filters['product_type']) return false;
        if (isset($filters['partner_name']) && (string)($item['partner_name_snapshot'] ?? '') !== $filters['partner_name']) return false;
        return true;
    }

    private function applyCashDirection($query, string $mode, string $field): void
    {
        if ($mode === 'positive') $query->where($field, '>', 0);
        elseif ($mode === 'refund') $query->where($field, '<', 0);
        elseif ($mode !== 'net') $this->fail('METRIC_SOURCE_TYPE_INVALID');
    }

    /** @return array<int,array{item:array<string,mixed>,amount_cents:int}> */
    private function allocate(int $amount, array $items): array
    {
        if ($items === []) return [];
        $totalWeight = 0; $weights = [];
        foreach ($items as $item) {
            $weight = max(0, (int)($item['configured_amount_cents'] ?? $item['sale_amount_cents'] ?? 0));
            $weights[] = $weight; $totalWeight = $this->add($totalWeight, $weight);
        }
        if ($totalWeight <= 0) { $totalWeight = count($items); $weights = array_fill(0, count($items), 1); }
        $lastEligible = -1;
        foreach ($weights as $index => $weight) if ($weight > 0) $lastEligible = $index;
        $assigned = 0; $out = [];
        foreach ($items as $index => $item) {
            $product = $amount * $weights[$index];
            if (!is_int($product)) $this->fail('METRIC_SOURCE_AMOUNT_INVALID');
            $part = $index === $lastEligible ? $amount - $assigned : intdiv($product, $totalWeight);
            $assigned = $this->add($assigned, $part);
            $out[] = ['item' => $item, 'amount_cents' => $part];
        }
        return $out;
    }


    private function cashGrouped(string $tenantId, array $stores, array $range, string $mode): array
    {
        $expression = $this->cashExpression($mode);
        $sale = $this->grouped($this->saleCashQuery($tenantId, $stores, $range), $expression, $stores, $range);
        $recharge = $this->grouped($this->rechargeCashQuery($tenantId, $stores, $range), $expression, $stores, $range);
        return $this->mergePoints($sale, $recharge);
    }

    private function cashDimensionRanking(string $tenantId, array $stores, array $range, string $mode, array $dimension, int $limit, string $order): array
    {
        // Dimensions are selected only from the registered contract. Sale
        // allocations and recharge payments retain their snapshots differently.
        $dimensionKey = (string)($dimension['id'] ?? '') . '|' . (string)($dimension['name'] ?? '');
        $sources = [
            'operator_id|operator_name_snapshot' => [
                'sale' => ['join' => 'payment', 'id' => 'payment.operator_id', 'name' => 'payment.operator_name_snapshot'],
                'recharge' => ['id' => 'p.operator_id', 'name' => 'p.operator_name_snapshot'],
            ],
            'member_id|member_name_snapshot' => [
                // The allocation is a payment allocation, while membership is
                // authoritative on its referenced sale fact. Do not require an
                // accidental duplicate member id on the allocation row.
                'sale' => ['id' => 's.member_id', 'name' => 's.member_name_snapshot'],
                'recharge' => ['id' => 'p.member_id', 'name' => 'p.member_name_snapshot'],
            ],
        ];
        if (!isset($sources[$dimensionKey])) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $expression = $this->cashExpression($mode);
        $sale = $this->cashDimensionRows($this->saleCashQuery($tenantId, $stores, $range), $expression, $sources[$dimensionKey]['sale']);
        $recharge = $this->cashDimensionRows($this->rechargeCashQuery($tenantId, $stores, $range), $expression, $sources[$dimensionKey]['recharge']);
        $merged = [];
        foreach (array_merge($sale, $recharge) as $row) {
            $id = $this->integer($row['entity_id'] ?? null);
            $name = trim((string)($row['entity_name'] ?? ''));
            if ($id <= 0 || $name === '') $this->fail('METRIC_SOURCE_RESULT_INVALID');
            if (!isset($merged[$id])) $merged[$id] = ['entity_id' => $id, 'entity_name' => $name, 'metric_value' => 0];
            if ($merged[$id]['entity_name'] !== $name) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $merged[$id]['metric_value'] = $this->add($merged[$id]['metric_value'], $this->integer($row['metric_value'] ?? null));
        }
        $rows = array_values($merged);
        usort($rows, static function (array $left, array $right) use ($order): int {
            $comparison = $order === 'asc'
                ? $left['metric_value'] <=> $right['metric_value']
                : $right['metric_value'] <=> $left['metric_value'];
            return $comparison ?: ($left['entity_id'] <=> $right['entity_id']);
        });
        return array_slice($rows, 0, $limit);
    }

    private function cashDimensionRows($query, string $expression, array $fields): array
    {
        if (($fields['join'] ?? null) === 'payment') {
            $query->join('cashier_v3_payment_fact payment', 'payment.tenant_id=p.tenant_id AND payment.fact_id=p.payment_fact_id');
        }
        $id = $fields['id'] ?? null; $name = $fields['name'] ?? null;
        if (!is_string($id) || !is_string($name) || !preg_match('/^[a-z_]+\.[a-z_]+$/D', $id) || !preg_match('/^[a-z_]+\.[a-z_]+$/D', $name)) {
            $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        }
        return $query->where($id, '>', 0)
            ->fieldRaw($id . ' entity_id,MAX(' . $name . ') entity_name,COALESCE(SUM(' . $expression . '),0) metric_value')
            ->group($id)->select()->toArray();
    }

    private function cashExpression(string $mode): string
    {
        $expressions = [
            'positive' => 'CASE WHEN p.amount_cents > 0 THEN p.amount_cents ELSE 0 END',
            'refund' => 'CASE WHEN p.amount_cents < 0 THEN -p.amount_cents ELSE 0 END',
            'net' => 'p.amount_cents',
        ];
        if (!isset($expressions[$mode])) $this->fail('METRIC_SOURCE_TYPE_INVALID');
        return $expressions[$mode];
    }

    private function factSummary(array $source, string $tenantId, array $stores, array $range): int
    {
        return $this->aggregate($this->factQuery($source, $tenantId, $stores, $range), $source['amount']);
    }

    private function factGrouped(array $source, string $tenantId, array $stores, array $range): array
    {
        return $this->grouped($this->factQuery($source, $tenantId, $stores, $range), $source['amount'], $stores, $range);
    }

    private function factDimensionRanking(array $source, string $tenantId, array $stores, array $range, array $dimension, int $limit, string $order): array
    {
        $idField = (string)$dimension['id'];
        $nameField = (string)$dimension['name'];
        $query=$this->factQuery($source, $tenantId, $stores, $range);
        $this->dimensionSourceFilters($query,$dimension);
        $rows = $query->where('p.' . $idField, '>', 0)
            ->fieldRaw('p.' . $idField . ' entity_id,MAX(p.' . $nameField . ') entity_name,COALESCE(SUM(' . $source['amount'] . '),0) metric_value')
            ->group('p.' . $idField)->orderRaw('metric_value ' . strtoupper($order) . ',entity_id ASC')
            ->limit($limit)->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $id = $this->integer($row['entity_id'] ?? null);
            $name = trim((string)($row['entity_name'] ?? ''));
            if ($id <= 0 || $name === '') $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $out[] = ['entity_id' => $id, 'entity_name' => $name, 'metric_value' => $this->integer($row['metric_value'] ?? null)];
        }
        return $out;
    }

    /**
     * A dimension may declare only a frozen source-type split owned by its
     * metric registration.  It is intentionally not a caller-supplied filter:
     * the query path cannot turn it into arbitrary fact-table access.
     */
    private function dimensionSourceFilters($query,array $dimension): void
    {
        $filters=$dimension['analysis_source_filters']??[];
        if (!is_array($filters)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        foreach ($filters as $field=>$value) {
            if ($field!=='source_type' || !is_string($value)
                || !in_array($value,['project','product'],true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
            $query->where('p.'.$field,$value);
        }
    }

    private function distinctSummary(array $source, string $tenantId, array $stores, array $range): int
    {
        $query = $this->factQuery($source, $tenantId, $stores, $range);
        $row = $query->fieldRaw('COUNT(DISTINCT ' . $source['distinct'] . ') amount_cents')->find() ?: [];
        return $this->integer($row['amount_cents'] ?? 0);
    }

    private function distinctGrouped(array $source, string $tenantId, array $stores, array $range): array
    {
        return $this->grouped($this->factQuery($source, $tenantId, $stores, $range), 'DISTINCT ' . $source['distinct'], $stores, $range, true);
    }

    /** @return array<int,array{business_date:string,metric_value:int}> */
    private function distinctDailyTotals(array $source, string $tenantId, array $stores, array $range): array
    {
        $rows = $this->factQuery($source, $tenantId, $stores, $range)
            ->fieldRaw('p.business_date,COUNT(DISTINCT ' . $source['distinct'] . ') metric_value')
            ->group('p.business_date')->order('p.business_date', 'asc')->limit(10001)->select()->toArray();
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $out = [];
        foreach ($rows as $row) {
            $day = $row['business_date'] ?? null;
            if (!is_string($day) || $day < $range['start'] || $day > $range['end'] || isset($out[$day])) {
                $this->fail('METRIC_SOURCE_RESULT_INVALID');
            }
            $out[$day] = ['business_date' => $day, 'metric_value' => $this->integer($row['metric_value'] ?? null)];
        }
        return array_values($out);
    }

    /** @return array<int,array{store_id:int,metric_value:int}> */
    private function distinctStoreTotals(array $source, string $tenantId, array $stores, array $range): array
    {
        $rows = $this->factQuery($source, $tenantId, $stores, $range)
            ->fieldRaw('p.store_id,COUNT(DISTINCT ' . $source['distinct'] . ') metric_value')
            ->group('p.store_id')->order('p.store_id', 'asc')->limit(10001)->select()->toArray();
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $totals = [];
        foreach ($rows as $row) {
            $storeId = $this->integer($row['store_id'] ?? null);
            if (!in_array($storeId, $stores, true) || isset($totals[$storeId])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $totals[$storeId] = $this->integer($row['metric_value'] ?? null);
        }
        $out = [];
        foreach ($stores as $storeId) $out[] = ['store_id' => $storeId, 'metric_value' => (int)($totals[$storeId] ?? 0)];
        return $out;
    }

    /** @return array<string,int> */
    private function distinctPeriodTotals(array $source, string $tenantId, array $stores, array $range, string $granularity): array
    {
        if ($granularity === 'day') {
            $out = [];
            foreach ($this->distinctDailyTotals($source, $tenantId, $stores, $range) as $row) {
                $out[$row['business_date']] = $row['metric_value'];
            }
            return $out;
        }
        $period = $granularity === 'month' ? "DATE_FORMAT(p.business_date,'%Y-%m')" : "DATE_FORMAT(p.business_date,'%Y')";
        $rows = $this->factQuery($source, $tenantId, $stores, $range)
            ->fieldRaw($period . ' period_key,COUNT(DISTINCT ' . $source['distinct'] . ') metric_value')
            ->group($period)->order('period_key', 'asc')->limit(10001)->select()->toArray();
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $out = [];
        $pattern = $granularity === 'month' ? '/^\\d{4}-\\d{2}$/D' : '/^\\d{4}$/D';
        foreach ($rows as $row) {
            $key = (string)($row['period_key'] ?? '');
            if (!preg_match($pattern, $key) || isset($out[$key])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $out[$key] = $this->integer($row['metric_value'] ?? null);
        }
        return $out;
    }

    private function aggregate($query, string $expression): int
    {
        $row = $query->fieldRaw('COALESCE(SUM(' . $expression . '),0) amount_cents')->find() ?: [];
        return $this->integer($row['amount_cents'] ?? 0);
    }

    private function grouped($query, string $expression, array $stores, array $range, bool $distinct = false): array
    {
        $aggregate = $distinct ? 'COUNT(' . $expression . ')' : 'COALESCE(SUM(' . $expression . '),0)';
        $rows = $query->fieldRaw('p.store_id,p.business_date,' . $aggregate . ' amount_cents')
            ->group('p.store_id,p.business_date')->order('p.store_id', 'asc')->order('p.business_date', 'asc')
            ->limit(10001)->select()->toArray();
        if (count($rows) > 10000) $this->fail('METRIC_GROUP_OUTPUT_TOO_LARGE');
        $out = []; $seen = [];
        foreach ($rows as $row) {
            $id = $this->integer($row['store_id'] ?? null); $day = $row['business_date'] ?? null;
            $key = $id . ':' . $day;
            if (!in_array($id, $stores, true) || !is_string($day) || $day < $range['start'] || $day > $range['end'] || isset($seen[$key])) {
                $this->fail('METRIC_SOURCE_RESULT_INVALID');
            }
            $seen[$key] = true;
            $out[] = ['store_id' => $id, 'business_date' => $day, 'amount_cents' => $this->integer($row['amount_cents'] ?? null)];
        }
        return $out;
    }

    private function mergePoints(array ...$branches): array
    {
        $merged = [];
        foreach ($branches as $branch) foreach ($branch as $point) {
            $key = $point['store_id'] . ':' . $point['business_date'];
            if (!isset($merged[$key])) $merged[$key] = $point;
            else $merged[$key]['amount_cents'] = $this->add($merged[$key]['amount_cents'], $point['amount_cents']);
        }
        ksort($merged, SORT_STRING);
        return array_values($merged);
    }

    private function factQuery(array $source, string $tenantId, array $stores, array $range)
    {
        $table = $source['table'];
        $query = call_user_func($this->queryFactory, $table)->alias('p')
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']]);
        foreach ($source['filters'] as $field => $value) $query->where('p.' . $field, $value);
        foreach (($source['where_gt'] ?? []) as $field => $value) $query->where('p.' . $field, '>', $value);
        $normal = [
            'facts' => function () use ($query): void { call_user_func($this->normalFacts, $query, 'p.tenant_id', 'p.order_id'); },
            'services' => function () use ($query): void { call_user_func($this->normalServices, $query, 'p'); },
        ];
        $guard = $normal[$source['normal_scope']] ?? null;
        if (!$guard) $this->fail('METRIC_SOURCE_TYPE_INVALID');
        $guard();
        if (($source['requires_completed_service'] ?? false) === true) {
            $query->whereExists(function ($service) {
                $service->name('cashier_v3_entitlement_service_fact')->whereRaw(
                    "tenant_id=p.tenant_id AND checkout_request_id=p.checkout_request_id AND source_line_id=p.source_line_id AND service_status='completed'"
                );
            });
        }
        return $query;
    }

    private function saleCashQuery(string $tenantId, array $stores, array $range)
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=p.tenant_id AND original.allocation_fact_id=p.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,p.sale_fact_id)')
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective');
        call_user_func($this->normalFacts, $query, 'p.tenant_id', 'p.order_id');
        return $query;
    }

    private function rechargeCashQuery(string $tenantId, array $stores, array $range)
    {
        $query = call_user_func($this->queryFactory, 'cashier_v3_payment_fact')->alias('p')
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)->where('p.status', 'effective')
            ->where('p.fact_type', 'payment_collected')->whereIn('p.source_document_type', ['recharge', 'recharge_debt_repayment'])
            ->whereIn('p.payment_method', CashierV3CheckoutFactPlanV1::paymentMethods())
            ->whereBetween('p.business_date', [$range['start'], $range['end']]);
        $query->where(function ($kind): void {
            $kind->where('p.source_document_type', 'recharge')->whereOr(function ($supplement): void {
                $supplement->where('p.source_document_type', 'recharge_debt_repayment')->whereExists(function ($repayment): void {
                    $repayment->name('cashier_v3_recharge_debt_repayment')->alias('cash_repayment')
                        ->whereRaw('cash_repayment.tenant_id=p.tenant_id AND cash_repayment.repayment_id=p.order_id AND cash_repayment.store_id=p.store_id AND cash_repayment.member_id=p.member_id')
                        ->where('cash_repayment.status', 'succeeded');
                });
            });
        });
        // Forward and signed reversal facts are both retained. Positive, refund and
        // net readers choose their own expression from this same fact population;
        // balance-only restoration never creates a negative payment fact.
        return $query;
    }

    private function assertScope(string $tenantId, array $stores, array $range): void
    {
        if ($tenantId === '' || strlen($tenantId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $tenantId)
            || !$stores || count($stores) > 10000 || array_keys($stores) !== range(0, count($stores) - 1)) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        foreach ($stores as $store) if (!is_int($store) || $store <= 0) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        if (count(array_unique($stores)) !== count($stores) || count($range) !== 2 || !isset($range['start'], $range['end'])) $this->fail('METRIC_SOURCE_SCOPE_INVALID');
        foreach (['start', 'end'] as $key) {
            $date = is_string($range[$key]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $range[$key], new \DateTimeZone('Asia/Shanghai')) : false;
            if (!$date || $date->format('Y-m-d') !== $range[$key]) $this->fail('METRIC_SOURCE_RANGE_INVALID');
        }
        if ($range['start'] > $range['end']) $this->fail('METRIC_SOURCE_RANGE_INVALID');
    }

    private function integer($value): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) || (string)(int)$value !== $value) $this->fail('METRIC_SOURCE_AMOUNT_INVALID');
        return (int)$value;
    }

    private function add(int $left, int $right): int
    {
        $sum = $left + $right;
        if (!is_int($sum)) $this->fail('METRIC_SOURCE_AMOUNT_INVALID');
        return $sum;
    }

    private function fail(string $code): void
    {
        throw new MetricQueryContractException($code, '当前指标查询条件或来源结果不合法。');
    }
}

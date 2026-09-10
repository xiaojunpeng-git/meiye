<?php

declare(strict_types=1);

namespace app\services\mobile\warehouse;

use app\services\metric\MetricDictionaryServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use app\services\mobile\merchant\MobileMerchantAnalyticsEntryPolicy;
use app\services\mobile\merchant\MobileMerchantAnalyticsEntryScopeServices;
use app\services\mobile\protocol\MobileApiException;
use app\services\report\StoreUnifiedReportOrganizationDimensionServices;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

/** Read-only mobile regional performance projection. */
final class MobileWarehouseServices
{
    public const METRIC_VERSION = 'mobile-warehouse-unified-v1';

    private $hierarchy;
    private $entryScopes;
    private $dictionary;

    public function __construct(
        MobileWarehouseHierarchyProjector $hierarchy,
        MobileMerchantAnalyticsEntryScopeServices $entryScopes,
        MetricDictionaryServices $dictionary
    ) {
        $this->hierarchy = $hierarchy;
        $this->entryScopes = $entryScopes;
        $this->dictionary = $dictionary;
    }

    public function overview(array $merchant, array $input): array
    {
        $this->assertWarehouseFeature((int)$merchant['employeeId']);
        $entry = $this->entryScopes->resolve($merchant);
        if ((string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL) {
            return ['warehouse' => ['entry' => $this->entryProjection($entry)]];
        }
        $validStoreIds = array_values(array_map('intval', (array)$entry['authorizedStoreIds']));
        $stores = Db::name('system_store')->whereIn('id', $validStoreIds)
            ->where('is_del', 0)->field('id,name')->select()->toArray();
        $bindings = Db::name('organization_store')->whereIn('store_id', $validStoreIds)
            ->field('org_id,store_id')->select()->toArray();
        $organizations = Db::name('organization')->where('is_del', 0)
            ->field('id,pid,name')->select()->toArray();

        $nodeType = trim((string)($input['nodeType'] ?? ''));
        $nodeId = (int)($input['nodeId'] ?? 0);
        if ((string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_STORE) {
            $nodeType = 'store';
            $nodeId = (int)$entry['entryNodeId'];
        } elseif ($nodeType === '' && $nodeId === 0) {
            $nodeType = (string)$entry['entryNodeType'];
            $nodeId = (int)$entry['entryNodeId'];
        }

        try {
            $hierarchy = $this->hierarchy->project(
                $organizations,
                $stores,
                $bindings,
                $validStoreIds,
                $nodeType,
                $nodeId,
                (string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_ORGANIZATION
                    ? (int)$entry['entryNodeId']
                    : null
            );
        } catch (InvalidArgumentException $exception) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', $exception->getMessage());
        }

        $period = $this->period($input);
        $scopeStoreIds = array_values(array_map('intval', (array)($hierarchy['currentNode']['_storeIds'] ?? [])));
        $metrics = $this->summaryMetrics($scopeStoreIds, $period);
        $trend = $this->trendProjection(
            $scopeStoreIds,
            $period,
            $this->trendMetricCode((string)($input['trendMetricCode'] ?? 'cash_performance'))
        );
        foreach ($hierarchy['rows'] as &$row) {
            $rowStoreIds = array_values(array_map('intval', (array)($row['_storeIds'] ?? [])));
            $row['metrics'] = $this->metricValues($this->summaryMetrics($rowStoreIds, $period));
            unset($row['_storeIds']);
        }
        unset($row);
        unset($hierarchy['currentNode']['_storeIds']);

        $showOrganizationRanking = (bool)$entry['showOrganizationRanking'];
        $rankingCode = $this->rankingCode((string)($input['rankingCode'] ?? ''), $showOrganizationRanking);
        $rankingOrder = $this->rankingOrder((string)($input['rankingOrder'] ?? 'desc'));
        $rankingRows = $rankingCode === 'organization'
            ? $this->organizationRanking($hierarchy['rows'], $rankingOrder)
            : $this->factRanking($rankingCode, $scopeStoreIds, $period, $rankingOrder);
        $rankingCatalog = $this->rankingCatalog($showOrganizationRanking);
        $selectedRankingAvailable = true;
        foreach ($rankingCatalog as $rankingDefinition) {
            if ((string)$rankingDefinition['code'] === $rankingCode) {
                $selectedRankingAvailable = (bool)$rankingDefinition['available'];
                break;
            }
        }

        return [
            'warehouse' => [
                'period' => $period,
                'scope' => [
                    'mode' => (string)$entry['entryType'],
                    'authorizedStoreCount' => count($validStoreIds),
                    'label' => (string)$hierarchy['currentNode']['name'],
                ],
                'entry' => $this->entryProjection($entry),
                'currentNode' => $hierarchy['currentNode'],
                'breadcrumbs' => $hierarchy['breadcrumbs'],
                'hierarchyRows' => $hierarchy['rows'],
                'organizationTree' => $hierarchy['organizationTree'],
                'summaryMetrics' => $metrics,
                'trend' => $trend,
                'rankingRows' => $rankingRows,
                'rankingCatalog' => $rankingCatalog,
                'selectedRankingCode' => $rankingCode,
                'selectedRankingOrder' => $rankingOrder,
                'selectedRankingAvailable' => $selectedRankingAvailable,
                'selectedMetricCode' => 'cash_performance',
                'metric_version' => self::METRIC_VERSION,
                'data_as_of' => time(),
                'aggregation_caught_up' => true,
                'availabilityMessage' => $selectedRankingAvailable
                    ? '当前直接读取收银 V3 不可变事实，日聚合接入后将使用同一口径对账切换。'
                    : '当前排行暂不可用。',
            ],
        ];
    }

    /** No query is executed: expose the existing warehouse permission contract to AI. */
    public function aiReportContext(array $merchant): array
    {
        $this->assertWarehouseFeature((int)$merchant['employeeId']);
        $entry=$this->entryScopes->resolve($merchant);
        $personal=(string)$entry['entryType']===MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL;
        $stores=$personal?[]:array_values(array_unique(array_filter(array_map('intval',(array)$entry['authorizedStoreIds']))));
        sort($stores);
        $canUse=in_array('MOHE_AI_USE',(array)($merchant['availableActions']??[]),true);
        return [
            'terminal'=>'merchant','account_id'=>(int)$merchant['accountId'],
            'scope_mode'=>$personal?'self_participant':($stores?'stores':'none'),
            'store_ids'=>$stores,'permission_version'=>hash('sha256',json_encode([$merchant['permissionVersion'],$entry,$canUse])),
            'can_use'=>$canUse && $stores!==[],'can_configure'=>false,
            'report_capability_code'=>'group_management_dashboard',
        ];
    }

    /** Read-only V3 performance drill-down for one employee in the current server scope. */
    public function employeePerformance(array $merchant, array $input): array
    {
        $this->assertWarehouseFeature((int)$merchant['employeeId']);
        $entry = $this->entryScopes->resolve($merchant);
        $storeIds = array_values(array_unique(array_map('intval', (array)$entry['authorizedStoreIds'])));
        if ($storeIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可查看的门店。');
        }
        $employeeId = (int)($input['employeeId'] ?? 0);
        if ($employeeId <= 0) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效员工。', 'employeeId');
        }
        if ((string)$entry['entryType'] === MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL
            && $employeeId !== (int)$merchant['employeeId']) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '个人数据权限只能查看本人员工业绩。');
        }

        $period = $this->period($input);
        $metricReader = new \app\services\query\metric\RegisteredMetricReadServices();
        $metricRange = ['start' => $period['startDate'], 'end' => $period['endDate']];
        $pairs = [];
        foreach ($storeIds as $storeId) $pairs[] = ['store_id' => (int)$storeId, 'employee_id' => $employeeId];
        $metricAmounts = [];
        foreach (['staff_sales_yeji', 'staff_labor_yeji'] as $metricCode) {
            $metricAmounts[$metricCode] = $metricReader->personnelTotal($metricCode, '0', $storeIds, $metricRange, $pairs);
        }

        $identityRows = array_merge(
            $metricReader->personnelDailyTotals('staff_sales_yeji', '0', $storeIds, $metricRange, [$employeeId]),
            $metricReader->personnelDailyTotals('staff_labor_yeji', '0', $storeIds, $metricRange, [$employeeId])
        );
        $pointCustomer = 0.0;
        $pointName = '';
        foreach ($this->designatedCustomerRanking($storeIds, $period, 'desc', 0) as $row) {
            if ((int)($row['entityId'] ?? 0) === $employeeId) {
                $pointCustomer = (float)($row['rankingValue'] ?? 0);
                $pointName = trim((string)($row['name'] ?? ''));
                break;
            }
        }
        $employeeName = '';
        foreach ($identityRows as $identity) {
            $employeeName = trim((string)($identity['employee_name'] ?? ''));
            if ($employeeName !== '') break;
        }
        if ($employeeName === '') {
            $employeeName = $pointName;
        }
        if ($employeeName === '') {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '该员工不在当前数据权限范围内。');
        }

        $serviceCounts = $metricReader->personnelFactCounts('staff_labor_yeji', '0', $storeIds, $metricRange, $employeeId);
        $detailMode = trim((string)($input['detailMode'] ?? 'cash')) === 'labor' ? 'labor' : 'cash';
        $detailMetricCode = $detailMode === 'labor' ? 'staff_labor_yeji' : 'staff_sales_yeji';
        $page = max(1, (int)($input['page'] ?? 1));
        $pageSize = min(20, max(1, (int)($input['pageSize'] ?? 20)));
        $detailPage = $metricReader->personnelDetailPage(
            $detailMetricCode, '0', $storeIds, $metricRange, $employeeId, $page, $pageSize
        );
        $total = (int)$detailPage['total'];
        $detailRows = $detailPage['rows'];
        $details = [];
        foreach ($detailRows as $row) {
            $amount = (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)($row['metric_value'] ?? 0));
            $details[] = [
                'factId' => (string)($row['fact_id'] ?? ''),
                'businessDate' => (string)($row['business_date'] ?? ''),
                'orderNo' => (string)($row['order_no_snapshot'] ?? ''),
                'storeName' => (string)($row['store_name_snapshot'] ?? ''),
                'memberName' => (string)($row['member_name_snapshot'] ?? ''),
                'sourceLineId' => (string)($row['source_line_id'] ?? ''),
                'ruleName' => (string)($row['rule_name_snapshot'] ?? ''),
                'value' => $amount,
                // Keep the mobile warehouse amount format consistent with the
                // overview and ranking cards: whole yuan with no thousands
                // separator. The source fact remains cents.
                'displayValue' => (string)$amount,
            ];
        }

        return [
            'employeePerformance' => [
                'employee' => ['id' => $employeeId, 'name' => $employeeName],
                'period' => $period,
                'metrics' => [
                    ['code' => 'staff_sales_yeji', 'name' => \app\services\query\metric\MetricSemanticCatalog::names(['staff_sales_yeji'])['staff_sales_yeji'], 'value' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($metricAmounts['staff_sales_yeji']), 'unit' => 'amount'],
                    ['code' => 'staff_labor_yeji', 'name' => \app\services\query\metric\MetricSemanticCatalog::names(['staff_labor_yeji'])['staff_labor_yeji'], 'value' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($metricAmounts['staff_labor_yeji']), 'unit' => 'amount'],
                    ['code' => 'designated_customer', 'name' => '点客', 'value' => $pointCustomer, 'unit' => 'count'],
                    ['code' => 'service_members', 'name' => '服务会员', 'value' => (int)($serviceCounts['member_count'] ?? 0), 'unit' => 'count'],
                    ['code' => 'project_count', 'name' => '项目数', 'value' => (int)($serviceCounts['project_count'] ?? 0), 'unit' => 'count'],
                ],
                'detailMode' => $detailMode,
                'details' => $details,
                'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'hasMore' => $page * $pageSize < $total],
                'metric_version' => self::METRIC_VERSION,
                'data_as_of' => time(),
                'aggregation_caught_up' => true,
            ],
        ];
    }

    /**
     * Shared read-only projection for mobile merchant summary pages.
     *
     * The homepage must use the exact same immutable V3 facts, period parser,
     * trend builder and metric dictionary as the warehouse page.  Keeping
     * these small public adapters here avoids introducing a second set of
     * dashboard SQL while still allowing the homepage to be available to an
     * employee who has customer access but not the full warehouse feature.
     *
     * @param int[] $storeIds
     * @return array<string,mixed>
     */
    public function dashboardProjection(array $storeIds, array $input = []): array
    {
        $storeIds = $this->positiveIds($storeIds);
        $period = $this->periodForDashboard($input);
        $projection = $this->registeredStoreProjection($storeIds, $period);
        $summary = $this->summaryMetrics($storeIds, $period);
        $trend = [];
        // The merchant homepage requests the comparison chart through
        // dashboardTrendComparison. Avoid a third (cash) trend scan while
        // retaining the original trend for warehouse callers.
        if (($input['includeTrend'] ?? true) !== false) {
            $trend = $this->trendProjection(
                $storeIds,
                $period,
                $this->trendMetricCode((string)($input['trendMetricCode'] ?? 'cash_performance'))
            );
        }

        $ranking = [];
        $storeNames = $storeIds === [] ? [] : Db::name('system_store')
            ->whereIn('id', $storeIds)->where('is_del', 0)->column('name', 'id');
        foreach ($storeIds as $storeId) {
            $amountCents = (int)($projection['cash_performance'][$storeId] ?? 0);
            $ranking[] = [
                'entityId' => $storeId,
                'entityType' => 'store',
                'entityName' => (string)($storeNames[$storeId] ?? ('门店#' . $storeId)),
                'rankingValue' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($amountCents),
                'displayValue' => number_format((int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($amountCents), 0, '.', ','),
                'metricCode' => 'cash_performance',
            ];
        }
        usort($ranking, static function (array $left, array $right): int {
            if ((int)$left['rankingValue'] === (int)$right['rankingValue']) {
                return (int)$left['entityId'] <=> (int)$right['entityId'];
            }
            return (int)$right['rankingValue'] <=> (int)$left['rankingValue'];
        });

        return [
            'period' => $period,
            'summaryMetrics' => $summary,
            'trend' => $trend,
            'rankingRows' => array_slice($ranking, 0, 5),
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => time(),
            // The current projection reads the immutable facts directly. A
            // future daily aggregate may replace it only after reconciliation.
            'aggregation_caught_up' => true,
        ];
    }

    /**
     * Build the two homepage trend series without recomputing the full
     * summary projection for each metric.  The homepage summary remains
     * sourced from dashboardProjection; this adapter only removes duplicate
     * fact scans from the comparison chart.
     *
     * @return array{actual:array<string,mixed>,consume:array<string,mixed>}
     */
    public function dashboardTrendComparison(array $storeIds, array $input = []): array
    {
        $period = $this->periodForDashboard($input);
        return [
            'actual' => $this->trendProjection($this->positiveIds($storeIds), $period, 'actual_performance'),
            'consume' => $this->trendProjection($this->positiveIds($storeIds), $period, 'consume_amount'),
        ];
    }

    /** Build the homepage ranking using the same scoped V3 fact projection. */
    public function dashboardRanking(array $storeIds, array $input, string $dimension): array
    {
        $storeIds = $this->positiveIds($storeIds);
        if ($storeIds === []) return [];
        $period = $this->periodForDashboard($input);
        if ($dimension === 'store') {
            $projection = $this->registeredStoreProjection($storeIds, $period);
            $names = Db::name('system_store')->whereIn('id', $storeIds)->where('is_del', 0)->column('name', 'id');
            $rows = [];
            foreach ($storeIds as $storeId) {
                $cents = (int)($projection['cash_performance'][$storeId] ?? 0);
                $rows[] = ['entityType' => 'store', 'entityId' => $storeId, 'entityName' => (string)($names[$storeId] ?? ('门店#' . $storeId)), 'rankingCents' => $cents, 'rankingValue' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($cents), 'storeCount' => 1];
            }
            usort($rows, [$this, 'compareDashboardRanking']);
            return $this->formatDashboardRanking(array_slice($rows, 0, 5), 'store');
        }
        // Aggregate each authorized store into the configured reporting
        // dimension. The previous fallback always returned store rows even
        // when the homepage title said “分公司/经理排行”, which made the
        // visible scope and the value semantics disagree.
        $dimensionService = new StoreUnifiedReportOrganizationDimensionServices();
        $dimensionCode = $dimension === 'company' ? 'company' : 'city_manager';
        $entityType = $dimension === 'company' ? 'branch' : 'manager';
        $storeGroupKeys = [];
        $groupLabels = [];
        foreach ($storeIds as $storeId) {
            $resolved = $dimensionService->resolve($dimensionCode, '', '', (string)$period['endDate'], (int)$storeId);
            $id = (int)($resolved['id'] ?? 0);
            $name = trim((string)($resolved['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;
            $storeGroupKeys[$storeId] = (string)$id;
            $groupLabels[(string)$id] = $name;
        }
        // A partially configured hierarchy has no sound aggregate. Keep the
        // documented store-level fallback rather than silently omitting stores.
        if (count($storeGroupKeys) === count($storeIds)) {
            $reader = new \app\services\query\metric\RegisteredMetricReadServices();
            $range = ['start' => $period['startDate'], 'end' => $period['endDate']];
            $rows = [];
            foreach ($reader->groupedStoreTotals('cash_performance', '0', $storeIds, $range, $storeGroupKeys) as $group) {
                $groupId = (string)$group['group_key'];
                $cents = (int)$group['metric_value'];
                $rows[] = [
                    'entityType' => $entityType, 'entityId' => (int)$groupId,
                    'entityName' => (string)($groupLabels[$groupId] ?? ''),
                    'rankingCents' => $cents,
                    'rankingValue' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($cents),
                    'storeCount' => (int)$group['store_count'],
                ];
            }
            usort($rows, [$this, 'compareDashboardRanking']);
            return $this->formatDashboardRanking(array_slice($rows, 0, 5), $entityType);
        }

        // Keep the page usable while a reporting-dimension migration is not
        // configured yet; this is an explicit store-level fallback.
        $fallback = (array)($this->dashboardProjection($storeIds, $input)['rankingRows'] ?? []);
        return $this->formatDashboardRanking(array_slice($fallback, 0, 5), 'store');
    }

    private function compareDashboardRanking(array $left, array $right): int
    {
        if ((int)$left['rankingValue'] === (int)$right['rankingValue']) return (int)$left['entityId'] <=> (int)$right['entityId'];
        return (int)$right['rankingValue'] <=> (int)$left['rankingValue'];
    }

    private function formatDashboardRanking(array $rows, string $entityType): array
    {
        foreach ($rows as &$row) {
            $row['entityType'] = $entityType; $row['name'] = (string)($row['entityName'] ?? '');
            $row['displayValue'] = number_format((int)$row['rankingValue'], 0, '.', ','); $row['metricCode'] = 'cash_performance'; $row['unit'] = 'amount'; $row['hasChildren'] = false;
        }
        unset($row); return $rows;
    }

    /** Normalize homepage period modes while retaining the warehouse contract. */
    public function periodForDashboard(array $input): array
    {
        $mode = trim((string)($input['periodMode'] ?? 'month'));
        if ($mode === 'year') {
            $year = trim((string)($input['year'] ?? date('Y')));
            if (!preg_match('/^\d{4}$/D', $year)) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效年份。', 'year');
            }
            $input['periodMode'] = 'custom';
            $input['startDate'] = $year . '-01-01';
            $input['endDate'] = $year . '-12-31';
        }
        return $this->period($input);
    }

    /** @param mixed[] $values @return int[] */
    private function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function assertWarehouseFeature(int $employeeId): void
    {
        $row = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('rules')->find();
        $rules = is_array($row)
            ? array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($row['rules'] ?? ''))))))
            : [];
        if (!in_array(MobileMerchantCapabilityCatalog::RULE_WAREHOUSE, $rules, true)) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前账号没有数仓查看权限。');
        }
    }

    private function period(array $input): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $mode = trim((string)($input['periodMode'] ?? 'month'));
        if ($mode === '') {
            $mode = 'month';
        }
        $today = new DateTimeImmutable('today', $timezone);
        if ($mode === 'today') {
            $start = $today;
            $end = $today;
            $label = '今天';
            $month = $start->format('Y-m');
        } elseif ($mode === 'yesterday') {
            $start = $today->modify('-1 day');
            $end = $start;
            $label = '昨天';
            $month = $start->format('Y-m');
        } elseif ($mode === 'last_month') {
            $start = $today->modify('first day of last month');
            $end = $start->modify('last day of this month');
            $label = '上月';
            $month = $start->format('Y-m');
        } elseif ($mode === 'custom') {
            $start = $this->dateFromInput((string)($input['startDate'] ?? ''), 'startDate', $timezone);
            $end = $this->dateFromInput((string)($input['endDate'] ?? ''), 'endDate', $timezone);
            if ($start > $end) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '开始日期不能晚于结束日期。', 'startDate');
            }
            $label = $start->format('n月j日') . '-' . $end->format('n月j日');
            $month = $start->format('Y-m');
        } else {
            $month = trim((string)($input['month'] ?? ''));
            if ($month === '') {
                $month = $today->format('Y-m');
            }
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效月份。', 'month');
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $timezone);
            if (!$start) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效月份。', 'month');
            }
            $end = $start->modify('last day of this month');
            $label = $start->format('Y年n月');
            $mode = 'month';
        }
        return [
            'mode' => $mode,
            'month' => $month,
            'label' => $label,
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'timezone' => 'Asia/Shanghai',
        ];
    }

    private function dateFromInput(string $date, string $field, DateTimeZone $timezone): DateTimeImmutable
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效日期。', $field);
        }
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (!$value || $value->format('Y-m-d') !== $date) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效日期。', $field);
        }
        return $value;
    }

    private function registeredStoreProjection(array $storeIds, array $period): array
    {
        // Both callers consume cash rankings only. Overview values are read
        // separately by summaryMetrics; do not run unused metric queries.
        $projection = ['cash_performance' => []];
        if ($storeIds === []) {
            return $projection;
        }
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $range = ['start' => $period['startDate'], 'end' => $period['endDate']];
        foreach ($reader->storeTotals('cash_performance', '0', $storeIds, $range) as $row) {
            $projection['cash_performance'][(int)$row['store_id']] = (int)$row['metric_value'];
        }
        return $projection;
    }

    /**
     * Builds a read-only, permission-scoped comparison series.  The client is
     * deliberately given finished points only: it must never aggregate facts
     * or derive same-period comparisons itself.
     */
    private function trendProjection(array $storeIds, array $period, string $metricCode): array
    {
        $calendar = $this->trendCalendar($period);
        $current = $this->trendAmounts($storeIds, $calendar['currentStart'], $calendar['currentEnd'], $metricCode, $calendar['granularity']);
        $yearOnYear = $this->trendAmounts($storeIds, $calendar['yoyStart'], $calendar['yoyEnd'], $metricCode, $calendar['granularity']);
        $monthOnMonth = $this->trendAmounts($storeIds, $calendar['momStart'], $calendar['momEnd'], $metricCode, $calendar['granularity']);
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $cursor = new DateTimeImmutable($calendar['currentStart'], $timezone);
        $end = new DateTimeImmutable($calendar['currentEnd'], $timezone);
        $points = [];
        while ($cursor <= $end) {
            $currentKey = $this->trendKey($cursor, $calendar['granularity']);
            $yoyCursor = $cursor->modify('-1 year');
            $momCursor = $calendar['granularity'] === 'day'
                ? $cursor->modify('-1 month')
                : $cursor->modify('-1 year');
            $yoyKey = $this->trendKey($yoyCursor, $calendar['granularity']);
            $momKey = $this->trendKey($momCursor, $calendar['granularity']);
            $currentCents = $cursor > $today ? null : (int)($current[$currentKey] ?? 0);
            $yoyCents = (int)($yearOnYear[$yoyKey] ?? 0);
            $momCents = (int)($monthOnMonth[$momKey] ?? 0);
            $points[] = [
                'key' => $currentKey,
                'label' => $calendar['granularity'] === 'day'
                    ? $cursor->format('j')
                    : ($calendar['granularity'] === 'month' ? $cursor->format('n月') : $cursor->format('Y年')),
                // Future periods are intentionally gaps, not zeroes.
                'current' => $currentCents === null ? null : (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($currentCents),
                'yoy' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($yoyCents),
                'mom' => (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($momCents),
                'currentCents' => $currentCents,
                'yoyCents' => $yoyCents,
                'momCents' => $momCents,
            ];
            $cursor = $calendar['granularity'] === 'day'
                ? $cursor->modify('+1 day')
                : ($calendar['granularity'] === 'month' ? $cursor->modify('+1 month') : $cursor->modify('+1 year'));
        }

        return [
            'metricCode' => $metricCode,
            'metricName' => $this->trendMetricName($metricCode),
            'granularity' => $calendar['granularity'],
            'points' => $points,
            'periods' => [
                'current' => ['label' => '本期', 'range' => $calendar['currentLabel']],
                'yoy' => ['label' => '同比', 'range' => $calendar['yoyLabel']],
                'mom' => ['label' => '环比', 'range' => $calendar['momLabel']],
            ],
        ];
    }

    private function trendMetricCode(string $code): string
    {
        return in_array($code, ['cash_performance', 'actual_performance', 'consume_amount', 'refund_performance'], true)
            ? $code
            : 'cash_performance';
    }

    private function trendMetricName(string $code): string
    {
        $definition = $this->dictionary->getTooltip($this->trendMetricCode($code));
        if (($definition['user_ready'] ?? false) !== true) throw new \RuntimeException('MOBILE_METRIC_DICTIONARY_NOT_READY');
        return (string)$definition['name'];
    }

    /** @return array<string,string> */
    private function trendCalendar(array $period): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $start = new DateTimeImmutable((string)$period['startDate'], $timezone);
        $end = new DateTimeImmutable((string)$period['endDate'], $timezone);
        if ($start->format('Y-m') === $end->format('Y-m')) {
            $currentStart = $end->modify('first day of this month');
            $currentEnd = $end->modify('last day of this month');
            $yoyStart = $currentStart->modify('-1 year');
            $yoyEnd = $currentEnd->modify('-1 year');
            $momStart = $currentStart->modify('-1 month');
            $momEnd = $currentEnd->modify('-1 month');
            return [
                'granularity' => 'day',
                'currentStart' => $currentStart->format('Y-m-d'), 'currentEnd' => $currentEnd->format('Y-m-d'),
                'yoyStart' => $yoyStart->format('Y-m-d'), 'yoyEnd' => $yoyEnd->format('Y-m-d'),
                'momStart' => $momStart->format('Y-m-d'), 'momEnd' => $momEnd->format('Y-m-d'),
                'currentLabel' => $currentStart->format('Y年n月'),
                'yoyLabel' => $yoyStart->format('Y年n月'),
                'momLabel' => $momStart->format('Y年n月'),
            ];
        }

        if ($start->format('Y') === $end->format('Y')) {
            $year = $end->format('Y');
            $currentStart = new DateTimeImmutable($year . '-01-01', $timezone);
            $currentEnd = new DateTimeImmutable($year . '-12-31', $timezone);
            $yoyStart = $currentStart->modify('-1 year');
            $yoyEnd = $currentEnd->modify('-1 year');
            $momStart = $currentStart->modify('-1 year');
            $momEnd = $currentEnd->modify('-1 year');
            return [
                'granularity' => 'month',
                'currentStart' => $currentStart->format('Y-m-d'), 'currentEnd' => $currentEnd->format('Y-m-d'),
                'yoyStart' => $yoyStart->format('Y-m-d'), 'yoyEnd' => $yoyEnd->format('Y-m-d'),
                'momStart' => $momStart->format('Y-m-d'), 'momEnd' => $momEnd->format('Y-m-d'),
                'currentLabel' => $year . '年',
                'yoyLabel' => $yoyStart->format('Y年'),
                'momLabel' => $momStart->format('Y年'),
            ];
        }

        // A range spanning calendar years is intentionally a year chart.
        // Each point is a full calendar year, never a client-side roll-up.
        $currentStart = new DateTimeImmutable($start->format('Y') . '-01-01', $timezone);
        $currentEnd = new DateTimeImmutable($end->format('Y') . '-12-31', $timezone);
        $yoyStart = $currentStart->modify('-1 year');
        $yoyEnd = $currentEnd->modify('-1 year');
        $momStart = $currentStart->modify('-1 year');
        $momEnd = $currentEnd->modify('-1 year');
        return [
            'granularity' => 'year',
            'currentStart' => $currentStart->format('Y-m-d'), 'currentEnd' => $currentEnd->format('Y-m-d'),
            'yoyStart' => $yoyStart->format('Y-m-d'), 'yoyEnd' => $yoyEnd->format('Y-m-d'),
            'momStart' => $momStart->format('Y-m-d'), 'momEnd' => $momEnd->format('Y-m-d'),
            'currentLabel' => $currentStart->format('Y年') . '-' . $currentEnd->format('Y年'),
            'yoyLabel' => $yoyStart->format('Y年') . '-' . $yoyEnd->format('Y年'),
            'momLabel' => $momStart->format('Y年') . '-' . $momEnd->format('Y年'),
        ];
    }

    private function trendKey(DateTimeImmutable $date, string $granularity): string
    {
        if ($granularity === 'day') {
            return $date->format('Y-m-d');
        }
        return $granularity === 'month' ? $date->format('Y-m') : $date->format('Y');
    }

    /** @return array<string,int> values stay in cents until the final response projection. */
    private function trendAmounts(array $storeIds, string $startDate, string $endDate, string $metricCode, string $granularity): array
    {
        if ($storeIds === []) {
            return [];
        }
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        return $reader->periodTotals($metricCode, '0', $storeIds, ['start' => $startDate, 'end' => $endDate], $granularity);
    }

    private function summaryMetrics(array $storeIds, array $period): array
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $range = ['start' => $period['startDate'], 'end' => $period['endDate']];
        $items = [];
        $visibleMetrics = [
            'cash_performance' => 'cash_performance',
            'actual_performance' => 'actual_performance',
            'consume_amount' => 'consume_amount',
            'refund_performance' => 'refund_performance',
            'visit_customer' => 'customer_active',
        ];
        foreach ($visibleMetrics as $code => $metricCode) {
            $definition = $this->dictionary->getTooltip($metricCode);
            if (($definition['user_ready'] ?? false) !== true) throw new \RuntimeException('MOBILE_METRIC_DICTIONARY_NOT_READY');
            $value = $storeIds === [] ? 0 : $reader->summary($metricCode, '0', $storeIds, $range);
            $isCount = $code === 'visit_customer';
            $items[] = [
                'code' => $code,
				'name' => (string)$definition['name'],
                'value' => $isCount ? $value : (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($value),
                'valueCents' => $isCount ? null : $value,
                'displayValue' => $isCount
                    ? number_format($value, 0, '.', ',')
                    : number_format((int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($value), 0, '.', ','),
                'unit' => $isCount ? 'count' : 'amount',
                'available' => true,
                'unavailableReason' => '',
            ];
        }
        return $items;
    }

    private function metricValues(array $metrics): array
    {
        $values = [];
        foreach ($metrics as $metric) {
            $values[] = [
                'code' => (string)$metric['code'],
                'value' => $metric['value'],
                'displayValue' => (string)$metric['displayValue'],
                'available' => (bool)$metric['available'],
            ];
        }
        return $values;
    }

    private function rankingCatalog(bool $showOrganizationRanking): array
    {
        $sales = $this->dictionary->getTooltip('staff_sales_yeji');
        $labor = $this->dictionary->getTooltip('staff_labor_yeji');
        $projects = $this->dictionary->getTooltip('completed_service_item_count');
        $items = [
            [
                'code' => 'organization',
                'name' => '组织排行',
                'available' => true,
                'scopeRule' => 'CURRENT_ORGANIZATION_DESCENDANT_STORES',
            ],
            [
                'code' => 'staff_cash_performance',
                'name' => '员工'.(string)$sales['name'],
                'available' => true,
                'metricCode' => 'staff_sales_yeji',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'staff_labor_performance',
                'name' => '员工'.(string)$labor['name'],
                'available' => true,
                'metricCode' => 'staff_labor_yeji',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'staff_designated_customer',
                'name' => '员工点客',
                'available' => true,
                'metricCode' => 'staff_designated_num',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'project_count',
                'name' => (string)$projects['name'].'排行',
                'available' => true,
                'metricCode' => 'service_project_count',
                'metricDictionaryReady' => true,
                'groupDimension' => 'project',
            ],
        ];
        return $showOrganizationRanking ? $items : array_values(array_filter($items, static function (array $item): bool {
            return (string)$item['code'] !== 'organization';
        }));
    }

    private function rankingCode(string $code, bool $showOrganizationRanking): string
    {
        $allowed = ['organization', 'staff_cash_performance', 'staff_labor_performance', 'staff_designated_customer', 'project_count'];
        if (!$showOrganizationRanking) {
            $allowed = array_values(array_filter($allowed, static function (string $item): bool { return $item !== 'organization'; }));
        }
        return in_array($code, $allowed, true) ? $code : ($showOrganizationRanking ? 'organization' : 'staff_cash_performance');
    }

    /** @return array<string,mixed> */
    private function entryProjection(array $entry): array
    {
        $personal = (string)($entry['entryType'] ?? '') === MobileMerchantAnalyticsEntryPolicy::TYPE_PERSONAL;
        return [
            'entryType' => (string)($entry['entryType'] ?? ''),
            'entryNodeType' => (string)($entry['entryNodeType'] ?? ''),
            'entryNodeId' => (int)($entry['entryNodeId'] ?? 0),
            'employeeId' => $personal ? (int)($entry['employeeId'] ?? 0) : 0,
            'destination' => $personal ? 'employee-performance' : 'warehouse',
            'showOrganizationRanking' => (bool)($entry['showOrganizationRanking'] ?? false),
            'organizationPickerEnabled' => (bool)($entry['organizationPickerEnabled'] ?? false),
        ];
    }

    private function rankingOrder(string $order): string
    {
        return strtolower(trim($order)) === 'asc' ? 'asc' : 'desc';
    }

    private function organizationRanking(array $rows, string $rankingOrder): array
    {
        usort($rows, static function (array $left, array $right) use ($rankingOrder): int {
            $leftValue = self::metricAmount($left, 'cash_performance');
            $rightValue = self::metricAmount($right, 'cash_performance');
            if ($leftValue === $rightValue) {
                return (int)($left['entityId'] ?? 0) <=> (int)($right['entityId'] ?? 0);
            }
            return $rankingOrder === 'asc'
                ? $leftValue <=> $rightValue
                : $rightValue <=> $leftValue;
        });
        return array_slice($rows, 0, 10);
    }

    private static function metricAmount(array $row, string $code): int
    {
        foreach ((array)($row['metrics'] ?? []) as $metric) {
            if ((string)($metric['code'] ?? '') === $code) {
                return (int)str_replace(',', '', (string)($metric['displayValue'] ?? $metric['value'] ?? 0));
            }
        }
        return 0;
    }

    private function factRanking(string $rankingCode, array $storeIds, array $period, string $rankingOrder): array
    {
        if ($storeIds === []) {
            return [];
        }
        if ($rankingCode === 'staff_designated_customer') {
            return $this->designatedCustomerRanking($storeIds, $period, $rankingOrder);
        }
        $bindings = [
            'project_count' => ['metric' => 'completed_service_item_count', 'dimension' => 'project', 'entity' => 'project', 'money' => false],
            'staff_cash_performance' => ['metric' => 'staff_sales_yeji', 'dimension' => 'employee', 'entity' => 'employee', 'money' => true],
            'staff_labor_performance' => ['metric' => 'staff_labor_yeji', 'dimension' => 'employee', 'entity' => 'employee', 'money' => true],
        ];
        $binding = $bindings[$rankingCode] ?? null;
        if (!is_array($binding)) return [];
        $registered = (new \app\services\query\metric\RegisteredMetricReadServices())->dimensionRanking(
            $binding['metric'], $binding['dimension'], '0', $storeIds,
            ['start' => $period['startDate'], 'end' => $period['endDate']], 10, $rankingOrder
        );
        $rows = [];
        foreach ($registered as $row) {
            $rows[] = ['entity_id' => $row['entity_id'], 'entity_name' => $row['entity_name'], 'ranking_value' => $row['metric_value']];
        }
        return $this->rankingRows($rows, $binding['entity'], $binding['money']);
    }

    /**
     * "员工点客" has the same customer-counting rule as the legacy service
     * report, but reads only the V3 immutable service fact and its frozen
     * craftsman snapshot.  A member is counted once per day; a friend/guest
     * service line is counted separately.  All point-customer craftsmen in a
     * group split one customer in hundredths, with the final remainder given
     * to the largest staff id for deterministic results.
     */
    private function designatedCustomerRanking(array $storeIds, array $period, string $rankingOrder, int $limit = 10): array
    {
        $facts = Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
            ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
            ->where('service_status', 'completed')
            ->field('service_fact_id,business_date,member_id,service_object,craftsmen_snapshot_json')
            ->select()->toArray();

        // groupKey => [staff id => immutable employee identity]
        $groups = [];
        foreach ($facts as $fact) {
            $pointCraftsmen = $this->pointCraftsmenFromSnapshot($fact['craftsmen_snapshot_json'] ?? null);
            if ($pointCraftsmen === []) {
                continue;
            }

            $day = (string)($fact['business_date'] ?? '');
            $memberId = (int)($fact['member_id'] ?? 0);
            $serviceObject = trim((string)($fact['service_object'] ?? ''));
            $isFriendOrGuest = $memberId <= 0 || $serviceObject === 'friend' || $serviceObject === '朋友';
            $groupKey = $isFriendOrGuest
                ? 'g_' . $day . '_' . (string)($fact['service_fact_id'] ?? '')
                : 'm_' . $day . '_' . $memberId;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            foreach ($pointCraftsmen as $staffId => $identity) {
                $groups[$groupKey][$staffId] = $identity;
            }
        }

        // employee id => immutable display identity plus scaled customer count
        $employees = [];
        foreach ($groups as $group) {
            ksort($group, SORT_NUMERIC);
            $staffIds = array_keys($group);
            $count = count($staffIds);
            if ($count === 0) {
                continue;
            }
            $base = intdiv(100, $count);
            $remainder = 100 - $base * $count;
            foreach ($staffIds as $index => $staffId) {
                $identity = $group[$staffId];
                $employeeId = (int)$identity['employeeId'];
                if (!isset($employees[$employeeId])) {
                    $employees[$employeeId] = ['name' => (string)$identity['name'], 'hundredths' => 0];
                }
                $employees[$employeeId]['hundredths'] += $base + ($index === $count - 1 ? $remainder : 0);
            }
        }

        $rows = [];
        foreach ($employees as $employeeId => $employee) {
            $rows[] = [
                'entity_id' => (int)$employeeId,
                'entity_name' => (string)$employee['name'],
                'ranking_value' => (int)$employee['hundredths'],
            ];
        }
        usort($rows, static function (array $left, array $right) use ($rankingOrder): int {
            $leftValue = (int)$left['ranking_value'];
            $rightValue = (int)$right['ranking_value'];
            if ($leftValue === $rightValue) {
                return (int)$left['entity_id'] <=> (int)$right['entity_id'];
            }
            return $rankingOrder === 'asc' ? $leftValue <=> $rightValue : $rightValue <=> $leftValue;
        });
        return $this->fractionalCustomerRankingRows($limit > 0 ? array_slice($rows, 0, $limit) : $rows);
    }

    /**
     * Reads only frozen V3 service-row snapshots.  The entitlement completion
     * stream has a historical snake_case snapshot shape while the sales stream
     * uses the canonical camelCase shape; both explicitly carry the point
     * flag.  A pre-fix snapshot without that flag is intentionally ignored.
     *
     * @return array<int,array{employeeId:int,name:string}>
     */
    private function pointCraftsmenFromSnapshot($json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hasPointFlag = array_key_exists('isPointCustomer', $row) || array_key_exists('is_point_customer', $row);
            $isPointCustomer = !empty($row['isPointCustomer']) || !empty($row['is_point_customer']);
            if (!$hasPointFlag || !$isPointCustomer) {
                continue;
            }
            $staffId = (int)($row['staffId'] ?? $row['staff_id'] ?? 0);
            $employeeId = (int)($row['employeeId'] ?? $row['employee_id'] ?? 0);
            if ($staffId <= 0 || $employeeId <= 0) {
                continue;
            }
            $result[$staffId] = [
                'employeeId' => $employeeId,
                'name' => trim((string)($row['name'] ?? $row['staff_name_snapshot'] ?? '')),
            ];
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function fractionalCustomerRankingRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $hundredths = (int)($row['ranking_value'] ?? 0);
            $value = $hundredths / 100;
            $display = number_format($value, 2, '.', '');
            $display = rtrim(rtrim($display, '0'), '.');
            $result[] = [
                'entityType' => 'employee',
                'entityId' => (int)($row['entity_id'] ?? 0),
                'name' => trim((string)($row['entity_name'] ?? '')) ?: '未命名员工',
                'rankingValue' => $value,
                'displayValue' => $display === '' ? '0' : $display,
                'unit' => 'count',
                'hasChildren' => false,
            ];
        }
        return $result;
    }

    private function rankingRows(array $rows, string $entityType, bool $money): array
    {
        $result = [];
        foreach ($rows as $row) {
            $raw = (int)($row['ranking_value'] ?? 0);
            $value = $money ? (int)\app\services\query\metric\MetricMoneyFormatter::integerYuan($raw) : $raw;
            $result[] = [
                'entityType' => $entityType,
                'entityId' => (int)($row['entity_id'] ?? 0),
                'name' => trim((string)($row['entity_name'] ?? '')) ?: ($entityType === 'project' ? '未命名项目' : '未命名员工'),
                'rankingValue' => $value,
                'displayValue' => number_format($value, 0, '.', ','),
                'unit' => $money ? 'amount' : 'count',
                'hasChildren' => false,
            ];
        }
        return $result;
    }
}

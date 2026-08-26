<?php

declare(strict_types=1);

namespace app\services\mobile\warehouse;

use app\services\metric\MetricDictionaryServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use app\services\mobile\protocol\MobileApiException;
use app\services\organization\EmployeeDataScopeServices;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

/** Read-only mobile regional performance projection. */
final class MobileWarehouseServices
{
    public const METRIC_VERSION = 'mobile-warehouse-unified-v1';

    private $hierarchy;
    private $scopes;
    private $dictionary;

    public function __construct(
        MobileWarehouseHierarchyProjector $hierarchy,
        EmployeeDataScopeServices $scopes,
        MetricDictionaryServices $dictionary
    ) {
        $this->hierarchy = $hierarchy;
        $this->scopes = $scopes;
        $this->dictionary = $dictionary;
    }

    public function overview(array $merchant, array $input): array
    {
        $this->assertWarehouseFeature((int)$merchant['employeeId']);
        $allowedStoreIds = $this->allowedStoreIds($merchant);
        if ($allowedStoreIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可查看的门店。');
        }

        $stores = Db::name('system_store')->whereIn('id', $allowedStoreIds)
            ->where('is_del', 0)->field('id,name')->select()->toArray();
        $validStoreIds = array_values(array_unique(array_map('intval', array_column($stores, 'id'))));
        if ($validStoreIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有有效门店。');
        }
        $bindings = Db::name('organization_store')->whereIn('store_id', $validStoreIds)
            ->field('org_id,store_id')->select()->toArray();
        $organizations = Db::name('organization')->where('is_del', 0)
            ->field('id,pid,name')->select()->toArray();

        try {
            $hierarchy = $this->hierarchy->project(
                $organizations,
                $stores,
                $bindings,
                $validStoreIds,
                trim((string)($input['nodeType'] ?? '')),
                (int)($input['nodeId'] ?? 0),
                $this->preferredRootOrganizationId((int)$merchant['employeeId'], $validStoreIds)
            );
        } catch (InvalidArgumentException $exception) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', $exception->getMessage());
        }

        $period = $this->period((string)($input['month'] ?? ''));
        $factProjection = $this->factProjection($validStoreIds, $period);
        $scopeStoreIds = array_values(array_map('intval', (array)($hierarchy['currentNode']['_storeIds'] ?? [])));
        $metrics = $this->summaryMetrics($scopeStoreIds, $factProjection);
        foreach ($hierarchy['rows'] as &$row) {
            $rowStoreIds = array_values(array_map('intval', (array)($row['_storeIds'] ?? [])));
            $row['metrics'] = $this->metricValues($this->summaryMetrics($rowStoreIds, $factProjection));
            unset($row['_storeIds']);
        }
        unset($row);
        unset($hierarchy['currentNode']['_storeIds']);

        $rankingCode = $this->rankingCode((string)($input['rankingCode'] ?? 'organization'));
        $currentNode = (array)($hierarchy['currentNode'] ?? []);
        $currentOrganizationId = (string)($currentNode['entityType'] ?? '') === 'organization'
            ? (int)($currentNode['entityId'] ?? 0) : 0;
        $permissionRanking = $this->permissionRankingSpec($merchant, (string)$period['endDate'], $currentOrganizationId);
        $rankingRows = $rankingCode === 'organization'
            ? $this->dashboardRanking($scopeStoreIds, $input, $permissionRanking['dimension'])
            : $this->factRanking($rankingCode, $scopeStoreIds, $period);
        $rankingCatalog = $this->rankingCatalog();
        if ($rankingCode === 'organization') {
            $rankingCatalog[0]['name'] = '组织现金业绩';
            $rankingCatalog[0]['scopeRule'] = $permissionRanking['scopeRule'];
        }
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
                    'mode' => count($validStoreIds) > 1 ? 'organization' : 'store',
                    'authorizedStoreCount' => count($validStoreIds),
                    'label' => (string)$hierarchy['currentNode']['name'],
                ],
                'currentNode' => $hierarchy['currentNode'],
                'breadcrumbs' => $hierarchy['breadcrumbs'],
                'summaryMetrics' => $metrics,
                'rankingRows' => $rankingRows,
                'rankingCatalog' => $rankingCatalog,
                'selectedRankingCode' => $rankingCode,
                'selectedRankingAvailable' => $selectedRankingAvailable,
                'selectedMetricCode' => 'cash_performance',
                'metric_version' => self::METRIC_VERSION,
                'data_as_of' => time(),
                'aggregation_caught_up' => true,
                'availabilityMessage' => $selectedRankingAvailable
                    ? '当前直接读取收银 V3 不可变事实，日聚合接入后将使用同一口径对账切换。'
                    : '统一服务事实尚未保存点客标记，员工点客暂不展示旧口径数据。',
            ],
        ];
    }

    /** @return array{dimension:string,code:string,name:string,scopeRule:string} */
    public function permissionRankingSpec(array $merchant, string $asOfDate, int $currentOrganizationId = 0): array
    {
        if ($currentOrganizationId > 0) {
            $currentDimension = (string)(Db::name('cashier_v3_report_organization_dimension')
                ->where('tenant_id', '0')->where('organization_id', (string)$currentOrganizationId)
                ->where('enabled', 1)->where('valid_from', '<=', $asOfDate)
                ->where(function ($builder) use ($asOfDate): void {
                    $builder->whereNull('valid_to')->whereOr('valid_to', '>=', $asOfDate);
                })->order('valid_from', 'desc')->order('id', 'desc')->value('dimension_code') ?: '');
            if ($currentDimension === '') {
                $parentId = (int)(Db::name('organization')->where('id', $currentOrganizationId)
                    ->where('is_del', 0)->value('pid') ?: 0);
                if ($parentId > 0) {
                    $parentDimension = (string)(Db::name('cashier_v3_report_organization_dimension')
                        ->where('tenant_id', '0')->where('organization_id', (string)$parentId)
                        ->where('enabled', 1)->where('valid_from', '<=', $asOfDate)
                        ->where(function ($builder) use ($asOfDate): void {
                            $builder->whereNull('valid_to')->whereOr('valid_to', '>=', $asOfDate);
                        })->order('valid_from', 'desc')->order('id', 'desc')->value('dimension_code') ?: '');
                    if ($parentDimension === 'company') $currentDimension = 'city_manager';
                    if ($parentDimension === 'city_manager') $currentDimension = 'store';
                }
            }
            if ($currentDimension === 'city_manager') {
                return ['dimension' => 'store', 'code' => 'store_cash_performance', 'name' => '门店现金业绩排行', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_STORE'];
            }
            if ($currentDimension === 'company') {
                return ['dimension' => 'city_manager', 'code' => 'manager_cash_performance', 'name' => '经理现金业绩排行', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_MANAGER'];
            }
        }
        $scopeRows = Db::name('employee_data_scope')
            ->where('employee_id', (int)($merchant['employeeId'] ?? 0))
            ->where('status', 1)->where('is_del', 0)
            ->field('scope_mode,org_ids')->select()->toArray();
        $orgIds = [];
        $hasStoreScope = false;
        foreach ($scopeRows as $row) {
            $mode = (string)($row['scope_mode'] ?? 'personal');
            if ($mode === 'org') {
                $decoded = json_decode((string)($row['org_ids'] ?? '[]'), true);
                foreach (is_array($decoded) ? $decoded : [] as $id) {
                    $id = (int)$id;
                    if ($id > 0) $orgIds[$id] = $id;
                }
            } elseif (in_array($mode, ['store', 'store_self'], true)) {
                $hasStoreScope = true;
            }
        }
        $dimensionByOrg = [];
        if ($orgIds !== []) {
            $query = Db::name('cashier_v3_report_organization_dimension')
                ->where('tenant_id', '0')->whereIn('organization_id', array_values($orgIds))
                ->where('enabled', 1)->where('valid_from', '<=', $asOfDate)
                ->where(function ($builder) use ($asOfDate): void {
                    $builder->whereNull('valid_to')->whereOr('valid_to', '>=', $asOfDate);
                })->field('organization_id,dimension_code')->order('valid_from', 'desc')->order('id', 'desc');
            foreach ($query->select()->toArray() as $row) {
                $id = (int)($row['organization_id'] ?? 0);
                if ($id > 0 && !isset($dimensionByOrg[$id])) $dimensionByOrg[$id] = (string)$row['dimension_code'];
            }
        }
        $dimension = 'company';
        if ($hasStoreScope && $orgIds === []) {
            $dimension = 'store';
        } else {
            foreach ($dimensionByOrg as $code) {
                if ($code === 'city_manager') { $dimension = 'store'; break; }
                if ($code === 'company') $dimension = 'city_manager';
            }
        }
        if ($dimension === 'store') return ['dimension' => 'store', 'code' => 'store_cash_performance', 'name' => '门店现金业绩排行', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_STORE'];
        if ($dimension === 'city_manager') return ['dimension' => 'city_manager', 'code' => 'manager_cash_performance', 'name' => '经理现金业绩排行', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_MANAGER'];
        return ['dimension' => 'company', 'code' => 'branch_cash_performance', 'name' => '分公司现金业绩排行', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_BRANCH'];
    }

    /**
     * Shared read-only projection for mobile merchant summary pages.
     *
     * The homepage uses the same immutable V3 facts, period parser, trend
     * builder and metric dictionary as the warehouse page. It does not own a
     * parallel dashboard SQL model.
     *
     * @param int[] $storeIds
     * @return array<string,mixed>
     */
    public function dashboardProjection(array $storeIds, array $input = []): array
    {
        $storeIds = $this->positiveIds($storeIds);
        $period = $this->periodForDashboard($input);
        $projection = $this->factProjection($storeIds, $period);
        $summary = $this->summaryMetrics($storeIds, $projection);
        $trend = $this->trendProjection(
            $storeIds,
            $period,
            $this->trendMetricCode((string)($input['trendMetricCode'] ?? 'cash_performance'))
        );

        $ranking = [];
        $storeNames = $storeIds === [] ? [] : Db::name('system_store')
            ->whereIn('id', $storeIds)->where('is_del', 0)->column('name', 'id');
        foreach ($storeIds as $storeId) {
            $amountCents = (int)($projection['cash_performance'][$storeId] ?? 0);
            $ranking[] = [
                'entityId' => $storeId,
                'entityType' => 'store',
                'entityName' => (string)($storeNames[$storeId] ?? ('门店#' . $storeId)),
                'rankingValue' => intdiv($amountCents, 100),
                'displayValue' => number_format(intdiv($amountCents, 100), 0, '.', ','),
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
            'aggregation_caught_up' => true,
        ];
    }

    /**
     * Build the homepage ranking at the permission-selected hierarchy level.
     * The caller supplies only the already-resolved authorized stores and a
     * server-selected dimension; the client cannot widen either scope.
     *
     * @param int[] $storeIds
     * @return array<int,array<string,mixed>>
     */
    public function dashboardRanking(array $storeIds, array $input, string $dimension): array
    {
        $storeIds = $this->positiveIds($storeIds);
        if ($storeIds === []) return [];
        $period = $this->periodForDashboard($input);
        $projection = $this->factProjection($storeIds, $period);
        if ($dimension === 'store') {
            $names = Db::name('system_store')->whereIn('id', $storeIds)
                ->where('is_del', 0)->column('name', 'id');
            $rows = [];
            foreach ($storeIds as $storeId) {
                $amountCents = (int)($projection['cash_performance'][$storeId] ?? 0);
                $rows[] = [
                    'entityType' => 'store', 'entityId' => $storeId,
                    'entityName' => (string)($names[$storeId] ?? ('门店#' . $storeId)),
                    'rankingCents' => $amountCents,
                    'rankingValue' => intdiv($amountCents, 100),
                    'storeCount' => 1,
                ];
            }
            usort($rows, [$this, 'compareDashboardRanking']);
            return $this->formatDashboardRanking(array_slice($rows, 0, 5), 'store');
        }

        $dimensions = $this->organizationDimensions($dimension, $storeIds, (string)$period['endDate']);
        $grouped = [];
        foreach ($dimensions as $row) {
            $id = (int)($row['dimension_id'] ?? 0);
            $storeId = (int)($row['store_id'] ?? 0);
            if ($id <= 0 || $storeId <= 0) continue;
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'entityType' => $dimension === 'company' ? 'branch' : 'manager',
                    'entityId' => $id,
                    'entityName' => (string)($row['dimension_name'] ?? ''),
                    'rankingCents' => 0,
                    'rankingValue' => 0,
                    'storeCount' => 0,
                ];
            }
            $grouped[$id]['rankingCents'] += (int)($projection['cash_performance'][$storeId] ?? 0);
            $grouped[$id]['storeCount']++;
        }
        foreach ($grouped as &$group) {
            $group['rankingValue'] = intdiv((int)$group['rankingCents'], 100);
        }
        unset($group);
        $rows = array_values($grouped);
        usort($rows, [$this, 'compareDashboardRanking']);
        return $this->formatDashboardRanking(array_slice($rows, 0, 5), $dimension === 'company' ? 'branch' : 'manager');
    }

    private function compareDashboardRanking(array $left, array $right): int
    {
        if ((int)$left['rankingValue'] === (int)$right['rankingValue']) {
            return (int)$left['entityId'] <=> (int)$right['entityId'];
        }
        return (int)$right['rankingValue'] <=> (int)$left['rankingValue'];
    }

    private function formatDashboardRanking(array $rows, string $entityType): array
    {
        foreach ($rows as &$row) {
            $row['entityType'] = $entityType;
            $row['name'] = (string)($row['entityName'] ?? '');
            $row['displayValue'] = number_format((int)$row['rankingValue'], 0, '.', ',');
            $row['metricCode'] = 'cash_performance';
            $row['unit'] = 'amount';
            $row['hasChildren'] = false;
            $row['metrics'] = [[
                'code' => 'cash_performance',
                'value' => (int)$row['rankingValue'],
                'displayValue' => (string)$row['displayValue'],
                'available' => true,
            ]];
        }
        unset($row);
        return $rows;
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
            return [
                'month' => $year . '-01',
                'label' => $year . '年',
                'startDate' => $year . '-01-01',
                'endDate' => $year . '-12-31',
                'timezone' => 'Asia/Shanghai',
            ];
        }
        return $this->period((string)($input['month'] ?? ''));
    }

    private function trendProjection(array $storeIds, array $period, string $metricCode): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $start = new DateTimeImmutable((string)$period['startDate'], $timezone);
        $end = new DateTimeImmutable((string)$period['endDate'], $timezone);
        $monthly = $start->format('Y-m-d') === $start->format('Y-01-01') && $end->format('Y-m-d') === $end->format('Y-12-31');
        $granularity = $monthly ? 'month' : 'day';
        $currentStart = $monthly ? $start : $start->modify('first day of this month');
        $currentEnd = $monthly ? $end : $end->modify('last day of this month');
        $current = $this->trendAmounts($storeIds, $currentStart->format('Y-m-d'), $currentEnd->format('Y-m-d'), $metricCode, $granularity);
        $today = new DateTimeImmutable('today', $timezone);
        $points = [];
        for ($cursor = $currentStart; $cursor <= $currentEnd; $cursor = $cursor->modify($monthly ? '+1 month' : '+1 day')) {
            $key = $monthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $points[] = [
                'key' => $key,
                'label' => $monthly ? $cursor->format('n月') : $cursor->format('j'),
                'current' => $cursor > $today ? null : (int)($current[$key] ?? 0),
                'yoy' => 0,
                'mom' => 0,
            ];
        }
        return [
            'metricCode' => $metricCode,
            'metricName' => $this->trendMetricName($metricCode),
            'granularity' => $granularity,
            'points' => $points,
            'periods' => ['current' => ['label' => '本期', 'range' => $currentStart->format('Y年n月')]],
        ];
    }

    private function trendMetricCode(string $code): string
    {
        return in_array($code, ['cash_performance', 'actual_performance', 'consume_amount', 'refund_performance'], true) ? $code : 'cash_performance';
    }

    private function trendMetricName(string $code): string
    {
        return [
            'cash_performance' => '现金业绩',
            'actual_performance' => '实际业绩',
            'consume_amount' => '消耗业绩',
            'refund_performance' => '退款金额',
        ][$code] ?? '现金业绩';
    }

    /** @return array<string,int> */
    private function trendAmounts(array $storeIds, string $startDate, string $endDate, string $metricCode, string $granularity = 'day'): array
    {
        if ($storeIds === []) return [];
        if ($metricCode === 'cash_performance') {
            $rows = Db::name('cashier_v3_payment_fact')->where('tenant_id', '0')->whereIn('store_id', $storeIds)->whereBetween('business_date', [$startDate, $endDate])->where('status', 'effective')->fieldRaw('business_date,COALESCE(SUM(amount_cents),0) AS amount_cents')->group('business_date')->select()->toArray();
        } elseif ($metricCode === 'refund_performance') {
            $rows = Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id', '0')->whereIn('store_id', $storeIds)->whereBetween('business_date', [$startDate, $endDate])->where('operation_type', 'refund')->where('status', 'succeeded')->fieldRaw('business_date,COALESCE(SUM(cash_refund_cents),0) AS amount_cents')->group('business_date')->select()->toArray();
        } elseif ($metricCode === 'actual_performance') {
            $cash = $this->trendAmounts($storeIds, $startDate, $endDate, 'cash_performance', $granularity);
            $refund = $this->trendAmounts($storeIds, $startDate, $endDate, 'refund_performance', $granularity);
            $result = [];
            foreach (array_unique(array_merge(array_keys($cash), array_keys($refund))) as $date) $result[$date] = (int)($cash[$date] ?? 0) - (int)($refund[$date] ?? 0);
            return $result;
        } else {
            $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')->whereIn('store_id', $storeIds)->whereBetween('business_date', [$startDate, $endDate])->where('status', 'effective')->where('performance_type', 'consumption_performance_recorded')->fieldRaw('business_date,COALESCE(SUM(amount_cents),0) AS amount_cents')->group('business_date')->select()->toArray();
        }
        $result = [];
        foreach ($rows as $row) {
            $date = (string)($row['business_date'] ?? '');
            $key = $granularity === 'month' ? substr($date, 0, 7) : $date;
            $result[$key] = (int)($result[$key] ?? 0) + intdiv((int)($row['amount_cents'] ?? 0), 100);
        }
        return $result;
    }

    /** Resolve each store to the nearest configured reporting dimension. */
    private function organizationDimensions(string $type, array $stores, string $date): array
    {
        $configs = Db::name('cashier_v3_report_organization_dimension')
            ->where('tenant_id', '0')->where('dimension_code', $type)->where('enabled', 1)
            ->where('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->whereOr('valid_to', '>=', $date);
            })->field('organization_id,organization_name_snapshot,display_order')
            ->order('display_order', 'asc')->order('id', 'asc')->select()->toArray();
        if ($configs === []) return [];
        $configured = [];
        foreach ($configs as $config) {
            $configured[(int)$config['organization_id']] = [
                'name' => (string)$config['organization_name_snapshot'],
                'sort' => (int)$config['display_order'],
            ];
        }
        // Prefer the explicit store-to-dimension binding.  It is the
        // authoritative assignment for reporting and avoids losing a manager
        // row when an organization path contains legacy or partial links.
        $directStoreDimensions = [];
        $storeIds = $this->positiveIds($stores);
        if ($storeIds !== []) {
            $bindings = Db::name('organization_store')->whereIn('store_id', $storeIds)
                ->whereIn('org_id', array_keys($configured))->field('org_id,store_id')->select()->toArray();
            foreach ($bindings as $binding) {
                $storeId = (int)($binding['store_id'] ?? 0);
                $organizationId = (int)($binding['org_id'] ?? 0);
                if ($storeId > 0 && isset($configured[$organizationId]) && !isset($directStoreDimensions[$storeId])) {
                    $directStoreDimensions[$storeId] = $organizationId;
                }
            }
        }
        $organizationIds = [];
        $pathsByStore = [];
        foreach ($stores as $storeId) {
            $storeId = (int)$storeId;
            $path = $this->storeOrganizationPath($storeId);
            $pathsByStore[$storeId] = $path;
            foreach ($path as $organizationId) {
                $organizationIds[(int)$organizationId] = (int)$organizationId;
            }
        }
        // A few legacy organizations do not have a snapshot row yet. Keep
        // them in the same configured company tree using their current name.
        $companyIds = [];
        foreach (Db::name('cashier_v3_report_organization_dimension')
            ->where('tenant_id', '0')->where('dimension_code', 'company')->where('enabled', 1)
            ->where('valid_from', '<=', $date)->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->whereOr('valid_to', '>=', $date);
            })->column('organization_id') as $companyId) {
            $companyIds[(int)$companyId] = true;
        }
        if ($organizationIds !== []) {
            foreach (Db::name('organization')->whereIn('id', array_values($organizationIds))
                ->where('is_del', 0)->field('id,pid,name')->select()->toArray() as $organization) {
                $organizationId = (int)$organization['id'];
                if ($type === 'city_manager' && !isset($configured[$organizationId])
                    && isset($companyIds[(int)$organization['pid']])) {
                    $configured[$organizationId] = [
                        'name' => trim((string)$organization['name']) ?: ('组织 ' . $organizationId),
                        'sort' => $organizationId,
                    ];
                }
            }
        }
        $rows = [];
        foreach ($stores as $storeId) {
            $storeId = (int)$storeId;
            $matchId = (int)($directStoreDimensions[$storeId] ?? 0);
            if ($matchId <= 0) {
                $path = $pathsByStore[$storeId] ?? [];
                foreach (array_reverse($path) as $organizationId) {
                    if (isset($configured[(int)$organizationId])) {
                        $matchId = (int)$organizationId;
                        break;
                    }
                }
            }
            if ($matchId <= 0) continue;
            $rows[] = [
                'dimension_id' => $matchId,
                'dimension_name' => $configured[$matchId]['name'],
                'sort_order' => $configured[$matchId]['sort'],
                'store_id' => (int)$storeId,
            ];
        }
        return $rows;
    }

    /** @return string[] root-to-leaf organization ids */
    private function storeOrganizationPath(int $storeId): array
    {
        $organizationId = (int)Db::name('organization_store')
            ->where('store_id', $storeId)->value('org_id');
        $path = [];
        $seen = [];
        for ($guard = 0; $organizationId > 0 && $guard < 64; $guard++) {
            if (isset($seen[$organizationId])) break;
            $seen[$organizationId] = true;
            $node = Db::name('organization')->where('id', $organizationId)
                ->where('is_del', 0)->field('id,pid')->find();
            if (!is_array($node)) break;
            array_unshift($path, (string)$node['id']);
            $organizationId = (int)($node['pid'] ?? 0);
        }
        return $path;
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

    private function allowedStoreIds(array $merchant): array
    {
        $employeeId = (int)$merchant['employeeId'];
        $currentStoreId = (int)$merchant['storeId'];
        $dataScopeIds = $this->scopes->resolveEffectiveStoreIds($employeeId, 0);
        $dataScopeIds = is_array($dataScopeIds) && $dataScopeIds !== [] ? $dataScopeIds : [$currentStoreId];

        $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('scope_mode,store_ids,org_ids')->find();
        if (!is_array($auth)) {
            return [];
        }
        $mode = (string)($auth['scope_mode'] ?? '');
        if ($mode === 'all') {
            $mobileIds = $dataScopeIds;
        } elseif ($mode === 'store') {
            $mobileIds = json_decode((string)($auth['store_ids'] ?? '[]'), true) ?: [];
        } elseif ($mode === 'org') {
            $mobileIds = [];
            foreach ((array)(json_decode((string)($auth['org_ids'] ?? '[]'), true) ?: []) as $orgId) {
                $mobileIds = array_merge($mobileIds, $this->scopes->expandOrgToStoreIds((int)$orgId));
            }
        } else {
            $mobileIds = [];
        }
        $dataScopeIds = array_values(array_unique(array_filter(array_map('intval', $dataScopeIds))));
        $mobileIds = array_values(array_unique(array_filter(array_map('intval', $mobileIds))));
        $allowed = array_values(array_intersect($dataScopeIds, $mobileIds));
        sort($allowed, SORT_NUMERIC);
        return $allowed;
    }

    private function period(string $month): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $month = trim($month);
        if ($month === '') {
            $month = (new DateTimeImmutable('now', $timezone))->format('Y-m');
        }
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效月份。', 'month');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $timezone);
        if (!$start) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择有效月份。', 'month');
        }
        return [
            'month' => $month,
            'label' => $start->format('Y年n月'),
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $start->modify('last day of this month')->format('Y-m-d'),
            'timezone' => 'Asia/Shanghai',
        ];
    }

    private function preferredRootOrganizationId(int $employeeId, array $allowedStoreIds): int
    {
        $rows = Db::name('employee_data_scope')->where('employee_id', $employeeId)
            ->where('scope_mode', EmployeeDataScopeServices::MODE_ORG)
            ->where('status', 1)->where('is_del', 0)->field('org_ids')->select()->toArray();
        $candidates = [];
        foreach ($rows as $row) {
            foreach ((array)(json_decode((string)($row['org_ids'] ?? '[]'), true) ?: []) as $orgId) {
                $orgId = (int)$orgId;
                if ($orgId > 0) {
                    $candidates[$orgId] = $orgId;
                }
            }
        }
        if (count($candidates) !== 1) {
            return 0;
        }
        $candidate = (int)reset($candidates);
        $subtreeStoreIds = $this->scopes->expandOrgToStoreIds($candidate);
        return array_diff($allowedStoreIds, $subtreeStoreIds) === [] ? $candidate : 0;
    }

    private function factProjection(array $storeIds, array $period): array
    {
        $projection = [
            'cash_performance' => [],
            'actual_performance' => [],
            'consume_amount' => [],
            'refund_performance' => [],
            'visit_members' => [],
        ];
        if ($storeIds === []) {
            return $projection;
        }
        $payments = Db::name('cashier_v3_payment_fact')
            ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
            ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
            ->where('status', 'effective')
            ->fieldRaw('store_id,COALESCE(SUM(amount_cents),0) AS amount_cents')
            ->group('store_id')->select()->toArray();
        foreach ($payments as $row) {
            $projection['cash_performance'][(int)$row['store_id']] = (int)$row['amount_cents'];
        }
        $performance = Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
            ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
            ->where('status', 'effective')
            ->whereIn('performance_type', ['actual_performance_recorded', 'consumption_performance_recorded'])
            ->fieldRaw('store_id,performance_type,COALESCE(SUM(amount_cents),0) AS amount_cents')
            ->group('store_id,performance_type')->select()->toArray();
        foreach ($performance as $row) {
            $code = (string)$row['performance_type'] === 'actual_performance_recorded'
                ? 'actual_performance'
                : 'consume_amount';
            $projection[$code][(int)$row['store_id']] = (int)$row['amount_cents'];
        }
        $refunds = Db::name('cashier_v3_order_lifecycle_operation')
            ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
            ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
            ->where('operation_type', 'refund')->where('status', 'succeeded')
            ->fieldRaw('store_id,COALESCE(SUM(cash_refund_cents),0) AS amount_cents')
            ->group('store_id')->select()->toArray();
        foreach ($refunds as $row) {
            $projection['refund_performance'][(int)$row['store_id']] = (int)$row['amount_cents'];
        }
        foreach ($storeIds as $storeId) {
            $storeId = (int)$storeId;
            $projection['actual_performance'][$storeId] =
                (int)($projection['cash_performance'][$storeId] ?? 0)
                - (int)($projection['refund_performance'][$storeId] ?? 0);
        }
        $visits = Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
            ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
            ->where('service_status', 'completed')->where('member_id', '>', 0)
            ->field('store_id,member_id')->group('store_id,member_id')->select()->toArray();
        foreach ($visits as $row) {
            $projection['visit_members'][(int)$row['store_id']][(int)$row['member_id']] = true;
        }
        return $projection;
    }

    private function summaryMetrics(array $storeIds, array $projection): array
    {
        $amounts = ['cash_performance' => 0, 'refund_performance' => 0, 'actual_performance' => 0, 'consume_amount' => 0];
        $visitMembers = [];
        foreach ($storeIds as $storeId) {
            foreach (array_keys($amounts) as $code) {
                $amounts[$code] += (int)($projection[$code][$storeId] ?? 0);
            }
            foreach (array_keys((array)($projection['visit_members'][$storeId] ?? [])) as $memberId) {
                $visitMembers[(int)$memberId] = true;
            }
        }
        $values = $amounts;
        $values['visit_customer'] = count($visitMembers);
        $items = [];
        foreach (['cash_performance', 'refund_performance', 'actual_performance', 'consume_amount', 'visit_customer'] as $code) {
            $definition = $this->dictionary->getByCode($code);
            $value = (int)$values[$code];
            $isCount = $code === 'visit_customer';
            $items[] = [
                'code' => $code,
                'name' => (string)($definition['name'] ?? ($code === 'refund_performance' ? '退款金额' : $code)),
                'value' => $isCount ? $value : intdiv($value, 100),
                'displayValue' => $isCount
                    ? number_format($value, 0, '.', ',')
                    : number_format(intdiv($value, 100), 0, '.', ','),
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

    private function rankingCatalog(): array
    {
        return [
            [
                'code' => 'organization',
                'name' => '组织排行',
                'available' => true,
                'scopeRule' => 'CURRENT_ORGANIZATION_DESCENDANT_STORES',
            ],
            [
                'code' => 'staff_cash_performance',
                'name' => '员工现金业绩',
                'available' => true,
                'metricCode' => 'cash_performance',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'staff_labor_performance',
                'name' => '员工劳动业绩',
                'available' => true,
                'metricCode' => 'staff_labor_yeji',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'staff_designated_customer',
                'name' => '员工点客',
                'available' => false,
                'metricCode' => 'staff_designated_num',
                'groupDimension' => 'employee',
            ],
            [
                'code' => 'project_count',
                'name' => '项目数排行',
                'available' => true,
                'metricCode' => 'service_project_count',
                'metricDictionaryReady' => true,
                'groupDimension' => 'project',
            ],
        ];
    }

    private function rankingCode(string $code): string
    {
        $allowed = ['organization', 'staff_cash_performance', 'staff_labor_performance', 'staff_designated_customer', 'project_count'];
        return in_array($code, $allowed, true) ? $code : 'organization';
    }

    private function factRanking(string $rankingCode, array $storeIds, array $period): array
    {
        if ($storeIds === [] || $rankingCode === 'staff_designated_customer') {
            return [];
        }
        if ($rankingCode === 'project_count') {
            $rows = Db::name('cashier_v3_entitlement_service_fact')
                ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
                ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
                ->where('service_status', 'completed')->where('project_id', '>', 0)
                ->fieldRaw('project_id AS entity_id,MAX(project_name_snapshot) AS entity_name,COALESCE(SUM(quantity),0) AS ranking_value')
                ->group('project_id')->orderRaw('ranking_value DESC,entity_id ASC')->limit(50)->select()->toArray();
            return $this->rankingRows($rows, 'project', false);
        }
        $performanceType = $rankingCode === 'staff_cash_performance'
            ? 'sales_performance_allocated'
            : 'labor_performance_allocated';
        $rows = Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', '0')->whereIn('store_id', $storeIds)
            ->whereBetween('business_date', [$period['startDate'], $period['endDate']])
            ->where('status', 'effective')->where('performance_type', $performanceType)
            ->where('employee_id', '>', 0)
            ->fieldRaw('employee_id AS entity_id,MAX(employee_name_snapshot) AS entity_name,COALESCE(SUM(amount_cents),0) AS ranking_value')
            ->group('employee_id')->orderRaw('ranking_value DESC,entity_id ASC')->limit(50)->select()->toArray();
        return $this->rankingRows($rows, 'employee', true);
    }

    private function rankingRows(array $rows, string $entityType, bool $money): array
    {
        $result = [];
        foreach ($rows as $row) {
            $raw = (int)($row['ranking_value'] ?? 0);
            $value = $money ? intdiv($raw, 100) : $raw;
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

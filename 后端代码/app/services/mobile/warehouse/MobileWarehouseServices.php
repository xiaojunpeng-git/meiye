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
        $rankingRows = $rankingCode === 'organization'
            ? $hierarchy['rows']
            : $this->factRanking($rankingCode, $scopeStoreIds, $period);
        $rankingCatalog = $this->rankingCatalog();
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
        $amounts = ['cash_performance' => 0, 'actual_performance' => 0, 'consume_amount' => 0];
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
        foreach (['cash_performance', 'actual_performance', 'consume_amount', 'visit_customer'] as $code) {
            $definition = $this->dictionary->getByCode($code);
            $value = (int)$values[$code];
            $isCount = $code === 'visit_customer';
            $items[] = [
                'code' => $code,
                'name' => (string)($definition['name'] ?? $code),
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

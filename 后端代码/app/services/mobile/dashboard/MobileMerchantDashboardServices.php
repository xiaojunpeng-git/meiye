<?php

declare(strict_types=1);

namespace app\services\mobile\dashboard;

use app\services\merchant\MerchantBusinessSecondaryMetricServices;
use app\services\merchant\MerchantCustomerMetricServices;
use app\services\mobile\customer\MobileCustomerQueryServices;
use app\services\mobile\warehouse\MobileWarehouseServices;
use app\services\mobile\protocol\MobileApiException;
use think\facade\Db;

/**
 * Mobile merchant unified home projection.
 *
 * This is an adapter only: business values come from the existing V3 fact
 * projection, metric dictionary and unified member query provider. It does
 * not own a parallel SQL/reporting model and it never accepts a client scope.
 */
final class MobileMerchantDashboardServices
{
    public const METRIC_VERSION = 'mobile-merchant-dashboard-v1';

    private $warehouse;
    private $customerMetrics;
    private $customerQuery;
    private $secondaryMetrics;

    public function __construct(
        MobileWarehouseServices $warehouse,
        MerchantCustomerMetricServices $customerMetrics,
        MobileCustomerQueryServices $customerQuery,
        MerchantBusinessSecondaryMetricServices $secondaryMetrics
    ) {
        $this->warehouse = $warehouse;
        $this->customerMetrics = $customerMetrics;
        $this->customerQuery = $customerQuery;
        $this->secondaryMetrics = $secondaryMetrics;
    }

    /** @return array<string,mixed> */
    public function home(array $merchant, array $input): array
    {
        $storeIds = $this->positiveIds((array)($merchant['visibleStoreIds'] ?? []));
        if ($storeIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可查看的门店。');
        }

        $projection = $this->warehouse->dashboardProjection($storeIds, $input);
        $actualProjection = $this->warehouse->dashboardProjection($storeIds, array_merge($input, ['trendMetricCode' => 'actual_performance']));
        $consumeProjection = $this->warehouse->dashboardProjection($storeIds, array_merge($input, ['trendMetricCode' => 'consume_amount']));
        $trend = $this->mergeTrendSeries($actualProjection['trend'] ?? [], $consumeProjection['trend'] ?? []);
        $period = (array)($projection['period'] ?? []);
        $startTs = strtotime((string)($period['startDate'] ?? '') . ' 00:00:00');
        $endTs = strtotime((string)($period['endDate'] ?? '') . ' 23:59:59');

        $overview = $this->overview($projection['summaryMetrics'] ?? []);
        $customer = $this->customer($merchant, $storeIds, $startTs, $endTs);
        $product = $this->product($storeIds, $startTs, $endTs);
        $rankingSpec = $this->rankingSpec($merchant, (string)($period['endDate'] ?? date('Y-m-d')));
        $rankingRows = $this->warehouse->dashboardRanking($storeIds, $input, $rankingSpec['dimension']);
        $ranking = [
            'code' => $rankingSpec['code'],
            'name' => $rankingSpec['name'],
            'rows' => $rankingRows,
            'state' => $rankingRows === [] ? 'empty' : 'ready',
            'source' => $rankingSpec['source'],
            'scopeRule' => $rankingSpec['scopeRule'],
        ];

        $blocks = [
            'overview' => $overview['state'],
            'trend' => empty((array)($trend['points'] ?? [])) ? 'empty' : 'ready',
            'customer' => $customer['state'],
            'product' => $product['state'],
            'ranking' => $ranking['state'],
        ];

        return [
            'overview' => $overview,
            'trend' => [
                'state' => $blocks['trend'],
                'source' => '统一经营事实趋势序列',
                'data' => $trend,
            ],
            'customer' => $customer,
            'product' => $product,
            'ranking' => $ranking,
            'scope' => [
                'mode' => (string)($merchant['dataScopeMode'] ?? ''),
                'storeCount' => count($storeIds),
                'storeIds' => $storeIds,
                'label' => (string)($rankingSpec['scopeLabel'] ?? '当前授权范围'),
            ],
            'period' => $period,
            'blocks' => $blocks,
            'data_as_of' => (int)($projection['data_as_of'] ?? time()),
            'metric_version' => self::METRIC_VERSION . '+' . (string)($projection['metric_version'] ?? MobileWarehouseServices::METRIC_VERSION),
            'aggregation_caught_up' => (bool)($projection['aggregation_caught_up'] ?? false),
        ];
    }

    /**
     * Select the next visible hierarchy from persisted employee data scope.
     * A root organization is ranked by its configured child dimension; a
     * company root drills to city managers; a city-manager root drills to
     * stores. Personal/store scopes stay at store level.
     *
     * @return array{dimension:string,code:string,name:string,source:string,scopeRule:string,scopeLabel:string}
     */
    private function rankingSpec(array $merchant, string $asOfDate): array
    {
        $scopeRows = Db::name('employee_data_scope')
            ->where('employee_id', (int)($merchant['employeeId'] ?? 0))
            ->where('status', 1)->where('is_del', 0)
            ->field('scope_mode,org_ids')->select()->toArray();
        $orgIds = [];
        $hasOrganizationScope = false;
        $hasStoreScope = false;
        foreach ($scopeRows as $row) {
            $mode = (string)($row['scope_mode'] ?? 'personal');
            if ($mode === 'org') {
                $hasOrganizationScope = true;
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
        $scopeLabel = null;
        if ($hasOrganizationScope && $orgIds !== []) {
            $namesById = Db::name('organization')->whereIn('id', array_values($orgIds))
                ->where('is_del', 0)->column('name', 'id');
            $names = [];
            foreach (array_keys($orgIds) as $organizationId) {
                $name = trim((string)($namesById[$organizationId] ?? ''));
                if ($name !== '') $names[] = $name;
            }
            if ($names !== []) $scopeLabel = implode('、', $names);
        }
        if ($dimension === 'store') {
            return ['dimension' => 'store', 'code' => 'store_cash_performance', 'name' => '门店现金业绩排行', 'source' => '统一现金业绩事实按员工授权门店汇总', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_STORE', 'scopeLabel' => (string)($merchant['dataScopeMode'] ?? '') === 'PERSONAL_SELF' ? '个人权限' : ($scopeLabel ?? '门店权限')];
        }
        if ($dimension === 'city_manager') {
            return ['dimension' => 'city_manager', 'code' => 'manager_cash_performance', 'name' => '经理现金业绩排行', 'source' => '统一现金业绩事实按员工授权范围汇总至城市经理统计维度', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_MANAGER', 'scopeLabel' => $scopeLabel ?? '分公司权限'];
        }
        return ['dimension' => 'company', 'code' => 'branch_cash_performance', 'name' => '分公司现金业绩排行', 'source' => '统一现金业绩事实按员工授权范围汇总至分公司统计维度', 'scopeRule' => 'EMPLOYEE_DATA_SCOPE_NEXT_BRANCH', 'scopeLabel' => $scopeLabel ?? '组织权限'];
    }

    /** Merge server-built actual and consume series for the home chart. */
    private function mergeTrendSeries(array $actual, array $consume): array
    {
        $actualPoints = array_values((array)($actual['points'] ?? []));
        $consumePoints = array_values((array)($consume['points'] ?? []));
        $points = [];
        foreach ($actualPoints as $index => $point) {
            $merged = (array)$point;
            $consumePoint = (array)($consumePoints[$index] ?? []);
            $merged['consumeCurrent'] = $consumePoint['current'] ?? null;
            $points[] = $merged;
        }
        return [
            'metricCode' => 'actual_and_consume',
            'metricName' => '实际业绩与消耗业绩',
            'granularity' => (string)($actual['granularity'] ?? 'day'),
            'points' => $points,
            'periods' => (array)($actual['periods'] ?? []),
        ];
    }

    /** @param array<int,array<string,mixed>> $metrics */
    private function overview(array $metrics): array
    {
        $byCode = [];
        foreach ($metrics as $metric) {
            $byCode[(string)($metric['code'] ?? '')] = $metric;
        }
        $items = [];
        foreach ([
            ['cash_performance', '现金业绩', '金额来自销售成功且收款事实', 'amount'],
            ['actual_performance', '实际业绩', '金额来自统一实际业绩事实', 'amount'],
            ['consume_amount', '消耗业绩', '项目核销完成后形成消耗业绩事实', 'amount'],
        ] as $definition) {
            $metric = $byCode[$definition[0]] ?? null;
            $items[] = $this->metricItem(
                $definition[0], $definition[1], $metric === null ? null : ($metric['value'] ?? null),
                $definition[3], $definition[2], $metric === null ? 'unavailable' : 'ready'
            );
        }
        // Personal target is not a store/organization aggregate in the
        // existing target service. Do not fabricate a completion rate here.
        $items[] = $this->metricItem('target_completion_rate', '目标完成率', null, 'percent', '组织目标接口接入后返回', 'unavailable');
        return [
            'state' => 'ready',
            'metrics' => $items,
            'source' => '统一经营事实与指标字典',
        ];
    }

    /** @return array<string,mixed> */
    private function customer(array $merchant, array $storeIds, int $startTs, int $endTs): array
    {
        if (!in_array('CUSTOMER_VIEW', (array)($merchant['availableActions'] ?? []), true)) {
            return ['state' => 'unavailable', 'source' => '当前账号没有客户查看权限', 'metrics' => []];
        }
        $items = [];
        try {
            $page = $this->customerQuery->query($merchant, ['page' => 1, 'limit' => 1]);
            $items[] = $this->metricItem('customer_total', '客户总数', (int)($page['total'] ?? 0), 'count', '统一客户查询结果总数', 'ready');
        } catch (\Throwable $exception) {
            $items[] = $this->metricItem('customer_total', '客户总数', null, 'count', '统一客户查询暂不可用', 'error');
        }
        foreach ([
            ['new_customer', '新客数', '首次现金业绩有效下单客户去重统计', 'newCustomerMetric'],
            ['visit_customer', '服务客户', '周期内完成核销到店客户去重统计', 'visitCustomerMetric'],
        ] as $definition) {
            try {
                $metric = $this->customerMetrics->{$definition[3]}($storeIds, $startTs, $endTs);
                $state = !empty($metric['developing']) ? 'unavailable' : 'ready';
                $items[] = $this->metricItem($definition[0], $definition[1], $metric['number'] ?? null, 'count', $definition[2], $state);
            } catch (\Throwable $exception) {
                $items[] = $this->metricItem($definition[0], $definition[1], null, 'count', $definition[2], 'error');
            }
        }
        $items[] = $this->metricItem('sleeping_customer', '沉睡客户', null, 'count', '沉睡客户口径待客户分析接口提供', 'unavailable');
        return ['state' => 'ready', 'source' => '统一客户事实与客户查询服务', 'metrics' => $items];
    }

    /** @return array<string,mixed> */
    private function product(array $storeIds, int $startTs, int $endTs): array
    {
        $items = [];
        try {
            $metric = $this->secondaryMetrics->productIncomeMetric($storeIds, $startTs, $endTs);
            $items[] = $this->metricItem('product_sales', '商品销售业绩', $metric['number'] ?? null, 'amount', '商品现金有效销售行统一口径', empty($metric['developing']) ? 'ready' : 'unavailable');
        } catch (\Throwable $exception) {
            $items[] = $this->metricItem('product_sales', '商品销售业绩', null, 'amount', '商品销售事实暂不可用', 'error');
        }
        $items[] = $this->metricItem('product_profit', '品项毛利', null, 'amount', '毛利指标尚未接入统一手机聚合出口', 'unavailable');
        $items[] = $this->metricItem('stock_alert', '库存预警', null, 'count', '库存预警指标尚未接入统一手机聚合出口', 'unavailable');
        $items[] = $this->metricItem('expiry_product', '临期商品', null, 'count', '临期指标尚未接入统一手机聚合出口', 'unavailable');
        $state = $items[0]['state'] === 'ready' ? 'ready' : 'unavailable';
        return ['state' => $state, 'source' => '统一商品销售事实；未接入指标不返回猜测值', 'metrics' => $items];
    }

    /** @return array<string,mixed> */
    private function metricItem(string $code, string $name, $value, string $unit, string $source, string $state): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'value' => $value,
            'unit' => $unit,
            'state' => $state,
            'source' => $source,
        ];
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
}

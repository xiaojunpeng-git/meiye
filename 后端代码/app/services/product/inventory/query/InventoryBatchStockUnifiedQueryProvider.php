<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\product\inventory\InventoryStockSummaryTotalsServices;
use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;

/**
 * UQ adapter for the authoritative inventory batch facts.
 * It never accepts tenant, store or location scope from the browser.
 */
final class InventoryBatchStockUnifiedQueryProvider implements UnifiedQueryProvider
{
    /** @var UnifiedQueryExecutionServices */
    private $execution;
    /** @var UnifiedQueryCustomFieldServices */
    private $customFields;
    /** @var UnifiedQueryPreferenceServices */
    private $preferences;
    /** @var InventoryBatchStockQueryProvider */
    private $source;

    public function __construct(
        UnifiedQueryExecutionServices $execution,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryPreferenceServices $preferences,
        ?InventoryBatchStockQueryProvider $source = null
    ) {
        $this->execution = $execution;
        $this->customFields = $customFields;
        $this->preferences = $preferences;
        $this->source = $source ?: new InventoryBatchStockQueryProvider();
    }

    public function pageCode(): string
    {
        return InventoryBatchStockQueryContract::PAGE_CODE;
    }

    public function query(array $context, array $payload): array
    {
        // The first result row is a mandatory report total. Inject these fixed
        // summaries server-side so browser preferences cannot omit or recalculate them.
        $payload = $this->withStockSummaries($payload, $context);
        $payload['pageCode'] = $this->pageCode();
        $definitions = $this->customDefinitions($context, $payload);
        $plan = $this->execution->validatedPlan(
            $this->pageCode(),
            $definitions,
            $payload,
            $context
        );
        $result = $this->execute(
            $context,
            $this->payloadFromPlan($plan),
            (array)$plan['custom_definitions']
        );
        $canViewCost = in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)($context['permissions'] ?? []), true);
        $amount = $canViewCost ? ($result['summaries']['inventory_amount_total:sum'] ?? '0.00') : null;
        return [
            'records' => $result['rows'],
            'total' => (int)$result['pagination']['total'],
            'page' => (int)$result['pagination']['page'],
            'pageSize' => (int)$result['pagination']['limit'],
            'isLoading' => false,
            'querySettings' => $this->preferences->load($context, $this->pageCode()),
            'summaries' => $result['summaries'],
            'stockSummary' => [
                'available_quantity' => (string)($result['summaries']['batch_balance_quantity:sum'] ?? '0'),
                'inventory_amount_cents' => $amount === null ? null : InventoryBatchStockQueryContract::amountToCents((string)$amount),
            ],
            'tableLayout' => InventoryStockSummaryTotalsServices::tableLayout(),
            'groups' => $result['groups'],
            'queryCutoffDate' => $result['queryCutoffDate'],
            'dataAsOf' => $result['dataAsOf'],
            'metricVersion' => InventoryBatchStockQueryContract::SCHEMA_VERSION,
            'aggregationCaughtUp' => true,
            'consistencyFingerprint' => $result['consistencyFingerprint'],
            'security' => $result['security'],
        ];
    }

    /** Adds mandatory full-result totals while retaining optional user summaries. */
    private function withStockSummaries(array $payload, array $context): array
    {
        $summaries = is_array($payload['summaries'] ?? null) ? $payload['summaries'] : [];
        $required = [['fieldKey' => 'batch_balance_quantity', 'operator' => 'sum']];
        if (in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)($context['permissions'] ?? []), true)) {
            $required[] = ['fieldKey' => 'inventory_amount_total', 'operator' => 'sum'];
        }
        $seen = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) continue;
            $field = (string)($summary['fieldKey'] ?? $summary['field_key'] ?? $summary['field'] ?? '');
            $operator = (string)($summary['operator'] ?? $summary['aggregation'] ?? '');
            $seen[$field . ':' . $operator] = true;
        }
        foreach ($required as $summary) {
            $key = $summary['fieldKey'] . ':' . $summary['operator'];
            if (!isset($seen[$key])) $summaries[] = $summary;
        }
        $payload['summaries'] = $summaries;
        return $payload;
    }

    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array {
        if ((string)($plan['page_code'] ?? '') !== $this->pageCode()
            || (string)($plan['query_cutoff_date'] ?? '') !== (string)$context['query_cutoff_date']
            || empty($plan['permission_must_be_injected_before_calculation'])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                '库存导出计划的页面、截止日期或权限顺序不合法。',
                []
            );
        }
        $plan['visible_fields'] = array_values(array_unique(array_map('strval', $fieldKeys)));
        $payload = $this->payloadFromPlan($plan);
        $payload['export'] = ['scope' => $exportScope, 'fields' => $plan['visible_fields']];
        return $this->execute($context, $payload, (array)($plan['custom_definitions'] ?? []));
    }

    private function execute(array $context, array $payload, array $definitions): array
    {
        $scope = $this->dataScope($context);
        // 显式数量筛选必须先读入零结存，再按商品/SKU 总量筛选；否则等于0/全部永远漏零库存。
        // 未使用数量筛选的既有查询保留仅正库存源窗口，权限仍由服务端仓库范围强制裁剪。
        $hasQuantityFilter = $this->hasQuantityRangeTopFilter($payload);
        $rows = $this->source->sourceRows([
            'queryCutoffDate' => (string)$context['query_cutoff_date'],
            'locationIds' => $scope->locationIds(),
            'includeZero' => $hasQuantityFilter,
        ], $scope);
        if ($hasQuantityFilter) {
            // The stock page displays one row per product/SKU. A quantity range
            // must therefore be applied to that total, never to individual
            // batches before the browser adds them again.
            $rows = (new InventoryProductStockSummaryServices())->summarize($rows);
        }
        $allowedLocations = array_fill_keys($scope->locationIds(), true);
        return $this->execution->execute(
            $this->pageCode(),
            $rows,
            $definitions,
            $payload,
            $context,
            static function (array $row) use ($context, $allowedLocations): bool {
                return (string)($row['tenant_id'] ?? '') === (string)$context['tenant_id']
                    && isset($allowedLocations[(int)($row['location_id'] ?? 0)]);
            }
        );
    }

    private function hasQuantityRangeTopFilter(array $payload): bool
    {
        $filters = (array)($payload['topFilterConditions'] ?? []);
        foreach ($filters as $filter) {
            if (is_array($filter)
                && (string)($filter['field_key'] ?? $filter['fieldKey'] ?? $filter['field'] ?? '') === 'available_quantity') {
                return true;
            }
        }
        return false;
    }

    private function dataScope(array $context): InventoryBatchStockDataScope
    {
        $dimension = (array)($context['scope_dimensions'] ?? []);
        $locations = $dimension['location_id'] ?? null;
        if (!is_array($locations) || !$locations) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SCOPE_INVALID',
                '库存查询缺少服务端仓库范围。',
                []
            );
        }
        $locationIds = [];
        foreach ($locations as $locationId) {
            if (!ctype_digit((string)$locationId) || (int)$locationId <= 0) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SCOPE_INVALID',
                    '库存查询仓库范围不合法。',
                    []
                );
            }
            $locationIds[] = (int)$locationId;
        }
        return new InventoryBatchStockDataScope(
            (string)$context['tenant_id'],
            $locationIds,
            (array)($context['permissions'] ?? [])
        );
    }

    private function payloadFromPlan(array $plan): array
    {
        return [
            'page' => (int)($plan['pagination']['page'] ?? 1),
            'limit' => (int)($plan['pagination']['limit'] ?? 20),
            'filters' => (array)($plan['filters'] ?? []),
            'topFilterConditions' => (array)($plan['top_filters'] ?? []),
            'keywordFilters' => (array)($plan['keyword_filters'] ?? []),
            'filterRelation' => (string)($plan['filter_relation'] ?? 'all'),
            'sorts' => (array)($plan['sorts'] ?? []),
            'groupBy' => (array)($plan['groups'] ?? []),
            'summaries' => (array)($plan['summaries'] ?? []),
            'dataScope' => (string)($plan['domain_scope']['data_scope'] ?? 'normal'),
            'businessStatus' => (string)($plan['domain_scope']['business_status'] ?? ''),
            'quickFilters' => (array)($plan['quick_filters'] ?? []),
            'visibleFields' => (array)($plan['visible_fields'] ?? []),
            'queryCutoffDate' => (string)($plan['query_cutoff_date'] ?? ''),
        ];
    }

    private function customDefinitions(array $context, array $payload): array
    {
        $candidate = $payload;
        unset($candidate['fieldVersions'], $candidate['field_versions']);
        $keys = (new UnifiedQueryCustomFieldKeyCollector())->collectFromQuery($candidate);
        if (!$keys) {
            return [];
        }
        $preference = $this->preferences->load($context, $this->pageCode());
        $pinned = (array)($preference['customFieldVersions'] ?? []);
        $visible = [];
        foreach ($this->customFields->listVisible($context, $this->pageCode(), true) as $field) {
            $visible[(string)$field['key']] = $field;
        }
        $definitions = [];
        foreach ($keys as $fieldKey) {
            if (!isset($visible[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                    '查询使用的自定义字段已不可用，请重新设置。',
                    ['field_key' => $fieldKey]
                );
            }
            $definition = $this->customFields->versionDefinition(
                $context,
                $this->pageCode(),
                $fieldKey,
                (int)($pinned[$fieldKey] ?? $visible[$fieldKey]['version'] ?? 0)
            );
            $definition['page_code'] = $this->pageCode();
            $definitions[] = $definition;
        }
        return $definitions;
    }
}

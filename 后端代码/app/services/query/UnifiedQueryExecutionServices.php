<?php

namespace app\services\query;

/**
 * 统一查询有界执行器。
 *
 * 领域提供器先投影白名单 key；本类强制先做数据权限过滤，随后才计算自定义字段，
 * 并让列表、分页、合计、分组和导出共用同一结果集。超过安全窗口时必须由领域
 * 提供器按同一 plan 下推到 QueryBuilder/事实聚合，禁止全量加载。
 */
class UnifiedQueryExecutionServices
{
    public const MAX_SOURCE_ROWS = 10000;
    public const MAX_CUSTOM_FIELDS = 20;
    public const MAX_FILTERS = 20;
    public const MAX_SORTS = 3;
    public const MAX_GROUPS = 3;
    public const MAX_SUMMARIES = 20;
    public const MAX_PAGE_SIZE = 200;

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var StructuredExpressionValidator */
    protected $validator;

    /** @var StructuredExpressionEvaluator */
    protected $evaluator;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        StructuredExpressionValidator $validator,
        StructuredExpressionEvaluator $evaluator
    ) {
        $this->registry = $registry;
        $this->validator = $validator;
        $this->evaluator = $evaluator;
    }

    /**
     * @param callable $dataScopeFilter 服务端权限闭包；返回 true 的行才允许进入计算
     */
    public function execute(
        string $pageCode,
        array $sourceRows,
        array $customDefinitions,
        array $query,
        array $context,
        callable $dataScopeFilter
    ): array {
        $page = $this->registry->page($pageCode);
        $query = $this->normalizeUiQuery(
            $pageCode,
            $query,
            is_array($context['permissions'] ?? null) ? $context['permissions'] : []
        );
        $this->assertQueryShape($query);
        $permissions = is_array($context['permissions'] ?? null)
            ? array_values(array_unique(array_map('strval', $context['permissions'])))
            : [];
        $cutoffDate = (string)($context['query_cutoff_date'] ?? '');
        $this->assertCutoffDate($cutoffDate);
        if (!empty($query['queryCutoffDate'])
            && (string)$query['queryCutoffDate'] !== $cutoffDate) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_CONFLICT',
                '查询截止日期已变化，请刷新后重试。',
                ['expected' => $cutoffDate, 'received' => $query['queryCutoffDate']]
            );
        }
        if (count($sourceRows) > self::MAX_SOURCE_ROWS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE',
                '当前数据量较大，请使用后端分页或汇总后再查询。',
                ['row_count' => count($sourceRows), 'max_rows' => self::MAX_SOURCE_ROWS]
            );
        }

        // 权限过滤是第一项逐行业务处理，禁止在此之前调用表达式执行器。
        $scopeContext = $context;
        $scopeContext['requested_data_scope'] = (string)($query['dataScope'] ?? 'normal');
        $scopeContext['requested_business_status'] = (string)($query['businessStatus'] ?? '');
        $authorizedRows = [];
        foreach ($sourceRows as $row) {
            if (!is_array($row)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SOURCE_ROW_INVALID',
                    '查询数据格式异常，请联系管理员。',
                    []
                );
            }
            if ((bool)$dataScopeFilter($row, $scopeContext)) {
                $authorizedRows[] = $row;
            }
        }
        $this->assertStableRows($authorizedRows, (string)$page['stableRowKey']);
        $definitions = $this->prepareDefinitions(
            $pageCode,
            $customDefinitions,
            $permissions
        );

        $aggregateValues = [];
        foreach ($definitions as $fieldKey => $definition) {
            if (!empty($definition['uses_aggregate'])) {
                $aggregateValues[$fieldKey] = $this->evaluator->evaluate(
                    $definition['expression'],
                    [],
                    ['query_cutoff_date' => $cutoffDate],
                    $authorizedRows
                );
            }
        }
        $calculatedRows = [];
        foreach ($authorizedRows as $row) {
            $calculated = $row;
            foreach ($definitions as $fieldKey => $definition) {
                $calculated[$fieldKey] = array_key_exists($fieldKey, $aggregateValues)
                    ? $aggregateValues[$fieldKey]
                    : $this->evaluator->evaluate(
                        $definition['expression'],
                        $row,
                        ['query_cutoff_date' => $cutoffDate],
                        []
                    );
            }
            $calculatedRows[] = $calculated;
        }
        $fieldIndex = $this->fieldIndex($pageCode, $permissions, $definitions);
        // 快捷筛选也是实际查询条件，不接受 UI 标签、数量或 active 等展示元数据。
        // 与顶部条件一样，它只能在数据权限已经注入、派生字段已经计算之后参与筛选。
        $quickFilters = $this->normalizeFilters(
            $query['quickFilters'] ?? [],
            $fieldIndex
        );
        $filteredRows = $this->applyFilters(
            $calculatedRows,
            $query['filters'] ?? [],
            $fieldIndex,
            (string)($query['filterRelation'] ?? 'all')
        );
        $filteredRows = $this->applyFilters(
            $filteredRows,
            $query['topFilterConditions'] ?? [],
            $fieldIndex,
            'all'
        );
        $filteredRows = $this->applyFilters(
            $filteredRows,
            $quickFilters,
            $fieldIndex,
            'all'
        );
        $filteredRows = $this->applyFilters(
            $filteredRows,
            $query['keywordFilters'] ?? [],
            $fieldIndex,
            'any'
        );
        $sorts = $this->normalizeSorts(
            $query['sorts'] ?? [],
            $fieldIndex,
            (string)$page['stableRowKey']
        );
        $sortedRows = $this->applySorts($filteredRows, $sorts, $fieldIndex);
        $summaries = $this->summaries(
            $sortedRows,
            $query['summaries'] ?? [],
            $fieldIndex
        );
        $groups = $this->groups(
            $sortedRows,
            $query['groupBy'] ?? ($query['groups'] ?? []),
            $query['summaries'] ?? [],
            $fieldIndex
        );

        $pageNumber = max(1, (int)($query['page'] ?? 1));
        $pageSize = (int)($query['limit'] ?? 20);
        if ($pageSize <= 0 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PAGE_SIZE_INVALID',
                '每页条数需在 1 到 ' . self::MAX_PAGE_SIZE . ' 之间。',
                ['limit' => $pageSize]
            );
        }
        $pageRows = array_slice($sortedRows, ($pageNumber - 1) * $pageSize, $pageSize);
        $exportRows = [];
        if (isset($query['export'])) {
            $exportRows = $this->exportRows(
                $sortedRows,
                $pageRows,
                (array)$query['export'],
                $fieldIndex
            );
        }
        $dataAsOf = (int)($context['data_as_of'] ?? time());
        $fingerprint = hash('sha256', UnifiedQueryJson::encode([
            'page_code' => $pageCode,
            'query_cutoff_date' => $cutoffDate,
            'definitions' => array_map(function (array $definition): array {
                return [
                    'field_key' => $definition['field_key'],
                    'version' => $definition['version'],
                    'expression_hash' => $definition['expression_hash'],
                ];
            }, $definitions),
            'filters' => $query['filters'] ?? [],
            'topFilterConditions' => $query['topFilterConditions'] ?? [],
            'quickFilters' => $quickFilters,
            'keywordFilters' => $query['keywordFilters'] ?? [],
            'sorts' => $sorts,
            'groups' => $query['groups'] ?? [],
            'groupBy' => $query['groupBy'] ?? [],
            'summaries' => $query['summaries'] ?? [],
            'filterRelation' => $query['filterRelation'] ?? 'all',
            'data_as_of' => $dataAsOf,
        ]));

        return [
            'rows' => $pageRows,
            'pagination' => [
                'page' => $pageNumber,
                'limit' => $pageSize,
                'total' => count($sortedRows),
                'pages' => (int)ceil(count($sortedRows) / $pageSize),
            ],
            'summaries' => $summaries,
            'groups' => $groups,
            'exportRows' => $exportRows,
            'queryCutoffDate' => $cutoffDate,
            'dataAsOf' => date('Y-m-d H:i:s', $dataAsOf),
            'schemaVersion' => $this->registry->schemaVersion(),
            'consistencyFingerprint' => $fingerprint,
            'security' => [
                'sourceRowCount' => count($sourceRows),
                'authorizedRowCount' => count($authorizedRows),
                'calculatedAfterPermission' => true,
            ],
        ];
    }

    /**
     * 大数据领域提供器可消费的无 SQL 执行计划；字段和操作符均已服务端验证。
     */
    public function validatedPlan(
        string $pageCode,
        array $customDefinitions,
        array $query,
        array $context
    ): array {
        $page = $this->registry->page($pageCode);
        $query = $this->normalizeUiQuery(
            $pageCode,
            $query,
            is_array($context['permissions'] ?? null) ? $context['permissions'] : []
        );
        $this->assertQueryShape($query);
        $permissions = is_array($context['permissions'] ?? null)
            ? array_values(array_unique(array_map('strval', $context['permissions'])))
            : [];
        $cutoffDate = (string)($context['query_cutoff_date'] ?? '');
        $this->assertCutoffDate($cutoffDate);
        if (!empty($query['queryCutoffDate'])
            && (string)$query['queryCutoffDate'] !== $cutoffDate) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_CONFLICT',
                '查询截止日期已变化，请刷新后重试。',
                ['expected' => $cutoffDate, 'received' => $query['queryCutoffDate']]
            );
        }
        $definitions = $this->prepareDefinitions($pageCode, $customDefinitions, $permissions);
        $fieldIndex = $this->fieldIndex($pageCode, $permissions, $definitions);
        $filters = $this->normalizeFilters($query['filters'] ?? [], $fieldIndex);
        $topFilters = $this->normalizeFilters(
            $query['topFilterConditions'] ?? [],
            $fieldIndex
        );
        $keywordFilters = $this->normalizeFilters(
            $query['keywordFilters'] ?? [],
            $fieldIndex
        );
        $quickFilters = $this->normalizeFilters(
            $query['quickFilters'] ?? [],
            $fieldIndex
        );
        $sorts = $this->normalizeSorts(
            $query['sorts'] ?? [],
            $fieldIndex,
            (string)$page['stableRowKey']
        );
        $groups = $this->normalizeGroups(
            $query['groupBy'] ?? ($query['groups'] ?? []),
            $fieldIndex
        );
        $summaries = $this->normalizeSummaries($query['summaries'] ?? [], $fieldIndex);
        $filterRelation = $this->normalizeFilterRelation(
            (string)($query['filterRelation'] ?? 'all')
        );
        $planPage = max(1, (int)($query['page'] ?? 1));
        $planLimit = (int)($query['limit'] ?? 20);
        if ($planLimit <= 0 || $planLimit > self::MAX_PAGE_SIZE) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PAGE_SIZE_INVALID',
                '每页条数需在 1 到 ' . self::MAX_PAGE_SIZE . ' 之间。',
                ['limit' => $planLimit]
            );
        }
        return [
            'page_code' => $pageCode,
            'query_cutoff_date' => $cutoffDate,
            'stable_row_key' => (string)$page['stableRowKey'],
            'filters' => $filters,
            'top_filters' => $topFilters,
            'keyword_filters' => $keywordFilters,
            'filter_relation' => $filterRelation,
            'sorts' => $sorts,
            'groups' => $groups,
            'summaries' => $summaries,
            'pagination' => ['page' => $planPage, 'limit' => $planLimit],
            'custom_definitions' => array_values($definitions),
            'domain_scope' => [
                'data_scope' => (string)($query['dataScope'] ?? 'normal'),
                'business_status' => (string)($query['businessStatus'] ?? ''),
            ],
            // 冻结的任务只保存已白名单化的条件，worker 不能重新解释 UI 展示对象。
            'quick_filters' => $quickFilters,
            'visible_fields' => $query['visibleFields'] ?? [],
            'permission_must_be_injected_before_calculation' => true,
        ];
    }

    /**
     * 前端查询页、导出和 worker 共用的唯一 UI payload 规范化入口。
     */
    public function normalizeUiQuery(
        string $pageCode,
        array $raw,
        array $permissions = []
    ): array {
        $this->registry->page($pageCode);
        $requestedPageCode = (string)($raw['pageCode'] ?? ($raw['page_code'] ?? ''));
        if ($requestedPageCode !== '' && $requestedPageCode !== $pageCode) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PAGE_CONFLICT',
                '查询页面已变化，请刷新后重试。',
                ['expected' => $pageCode, 'received' => $requestedPageCode]
            );
        }
        $uiKeys = [
            'pageCode', 'page_code', 'page', 'pageSize', 'limit', 'keyword',
            'queryCutoffDate',
            'topFilters', 'querySettings', 'filters', 'filterRelation', 'sorts',
            'groups', 'groupBy', 'summaries', 'dataScope', 'businessStatus',
            'quickFilters', 'visibleFields', 'export', 'fieldVersions',
            'keywordFilters', 'topFilterConditions',
        ];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $uiKeys, true)) {
                throw $this->invalidQuery('查询参数不在 UI 白名单中：' . $key);
            }
        }
        if (array_key_exists('querySettings', $raw) && !is_array($raw['querySettings'])) {
            throw $this->invalidQuery('querySettings 必须是结构化对象');
        }
        if (array_key_exists('quickFilters', $raw) && !is_array($raw['quickFilters'])) {
            throw $this->invalidQuery('quickFilters 必须是筛选条件数组');
        }
        $settings = is_array($raw['querySettings'] ?? null) ? $raw['querySettings'] : [];
        $allowedSettingKeys = [
            'visibleFields', 'quickFields', 'filters', 'filterRelation', 'sorts',
            'groupBy', 'summaries', 'queryCutoffDate', 'schemaVersion',
        ];
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, $allowedSettingKeys, true)) {
                throw $this->invalidQuery('querySettings 包含不支持的配置：' . $key);
            }
        }
        if (isset($settings['schemaVersion'])
            && (string)$settings['schemaVersion'] !== $this->registry->schemaVersion()) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SCHEMA_VERSION_CONFLICT',
                '查询结构版本已更新，请刷新页面后重试。',
                [
                    'expected' => $this->registry->schemaVersion(),
                    'received' => (string)$settings['schemaVersion'],
                ]
            );
        }
        $normalized = [
            'page' => (int)($raw['page'] ?? 1),
            'limit' => (int)($raw['limit'] ?? ($raw['pageSize'] ?? 20)),
            'filters' => is_array($raw['filters'] ?? null)
                ? $raw['filters']
                : (is_array($settings['filters'] ?? null) ? $settings['filters'] : []),
            'filterRelation' => (string)($raw['filterRelation']
                ?? ($settings['filterRelation'] ?? 'all')),
            'sorts' => is_array($raw['sorts'] ?? null)
                ? $raw['sorts']
                : (is_array($settings['sorts'] ?? null) ? $settings['sorts'] : []),
            'groupBy' => is_array($raw['groupBy'] ?? null)
                ? $raw['groupBy']
                : (is_array($raw['groups'] ?? null)
                    ? $raw['groups']
                    : (is_array($settings['groupBy'] ?? null) ? $settings['groupBy'] : [])),
            'summaries' => is_array($raw['summaries'] ?? null)
                ? $raw['summaries']
                : (is_array($settings['summaries'] ?? null) ? $settings['summaries'] : []),
            'dataScope' => (string)($raw['dataScope'] ?? 'normal'),
            'businessStatus' => (string)($raw['businessStatus'] ?? ''),
            'quickFilters' => is_array($raw['quickFilters'] ?? null) ? $raw['quickFilters'] : [],
            'visibleFields' => is_array($raw['visibleFields'] ?? null)
                ? $raw['visibleFields']
                : (is_array($settings['visibleFields'] ?? null) ? $settings['visibleFields'] : []),
            'keywordFilters' => is_array($raw['keywordFilters'] ?? null)
                ? $raw['keywordFilters']
                : [],
            'queryCutoffDate' => (string)($raw['queryCutoffDate']
                ?? ($settings['queryCutoffDate'] ?? '')),
            'topFilterConditions' => is_array($raw['topFilterConditions'] ?? null)
                ? $raw['topFilterConditions']
                : [],
        ];
        $topFilters = $raw['topFilters'] ?? [];
        if (!is_array($topFilters)) {
            throw $this->invalidQuery('topFilters 必须是结构化数组');
        }
        if ($topFilters) {
            if ($this->isList($topFilters)) {
                foreach ($topFilters as $filter) {
                    if (!is_array($filter)) {
                        throw $this->invalidQuery('topFilters 项必须是结构化对象');
                    }
                    $normalized['topFilterConditions'][] = $filter;
                }
            } else {
                foreach ($topFilters as $fieldKey => $value) {
                    if ($value === '' || $value === null || $value === []) {
                        continue;
                    }
                    $type = $this->registry->fieldDefinition(
                        $pageCode,
                        (string)$fieldKey
                    )['type'];
                    $normalized['topFilterConditions'][] = [
                        'field' => (string)$fieldKey,
                        'operator' => $type === 'text' ? 'contains' : 'eq',
                        'value' => $value,
                    ];
                }
            }
        }
        $keyword = trim((string)($raw['keyword'] ?? ''));
        if ($keyword !== '') {
            $length = function_exists('mb_strlen')
                ? mb_strlen($keyword, 'UTF-8')
                : strlen($keyword);
            if ($length > 100) {
                throw $this->invalidQuery('关键字不能超过 100 个字符');
            }
            foreach (['member_name', 'phone', 'member_no'] as $fieldKey) {
                $this->registry->assertReadable($pageCode, $fieldKey, $permissions);
                $normalized['keywordFilters'][] = [
                    'field' => $fieldKey,
                    'operator' => 'contains',
                    'value' => $keyword,
                ];
            }
        }
        if (isset($raw['export'])) {
            $normalized['export'] = $raw['export'];
        }
        return $normalized;
    }

    protected function prepareDefinitions(
        string $pageCode,
        array $definitions,
        array $permissions
    ): array {
        if (count($definitions) > self::MAX_CUSTOM_FIELDS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_TOO_MANY_CUSTOM_FIELDS',
                '一次查询最多使用 ' . self::MAX_CUSTOM_FIELDS . ' 个自定义字段。',
                []
            );
        }
        $prepared = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_INVALID',
                    '自定义字段定义无效。',
                    []
                );
            }
            $fieldKey = (string)($definition['field_key'] ?? ($definition['key'] ?? ''));
            $version = (int)($definition['version'] ?? 0);
            $status = (string)($definition['status'] ?? 'active');
            if (!preg_match('/^cf_[a-f0-9]{20,40}$/D', $fieldKey) || $version <= 0
                || $status !== 'active') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                    '查询使用的自定义字段已停用或版本无效。',
                    ['field_key' => $fieldKey, 'version' => $version, 'status' => $status]
                );
            }
            if (($definition['page_code'] ?? $pageCode) !== $pageCode
                || isset($prepared[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_INVALID',
                    '自定义字段不属于当前页面或重复。',
                    ['field_key' => $fieldKey]
                );
            }
            $expression = $definition['expression'] ?? null;
            if (is_string($expression)) {
                $expression = UnifiedQueryJson::decode($expression);
            }
            if (!is_array($expression)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPRESSION_INVALID',
                    '自定义字段规则已损坏，请重新编辑。',
                    ['field_key' => $fieldKey]
                );
            }
            $validated = $this->validator->validate(
                $pageCode,
                $expression,
                (string)($definition['return_type'] ?? ($definition['type'] ?? '')),
                $permissions
            );
            $prepared[$fieldKey] = [
                'field_key' => $fieldKey,
                'label' => (string)($definition['name'] ?? ($definition['label'] ?? $fieldKey)),
                'return_type' => $validated['return_type'],
                'version' => $version,
                'expression' => $validated['expression'],
                'expression_hash' => hash('sha256', UnifiedQueryJson::encode($validated['expression'])),
                'referenced_fields' => $validated['referenced_fields'],
                'uses_aggregate' => $validated['uses_aggregate'],
                'allowed_operations' => $this->customOperations(
                    $validated['return_type'],
                    $validated['uses_aggregate']
                ),
            ];
        }
        return $prepared;
    }

    protected function fieldIndex(string $pageCode, array $permissions, array $definitions): array
    {
        $index = [];
        foreach ($this->registry->fieldsForCapability($pageCode, $permissions) as $field) {
            $index[(string)$field['key']] = [
                'type' => (string)$field['type'],
                'operations' => (array)$field['allowedOperations'],
            ];
        }
        // 稳定键可能是隐藏字段，排序器仍必须可用。
        $page = $this->registry->page($pageCode);
        $stable = (string)$page['stableRowKey'];
        if (!isset($index[$stable])) {
            $field = $this->registry->fieldDefinition($pageCode, $stable);
            $index[$stable] = [
                'type' => (string)$field['type'],
                'operations' => (array)$field['allowedOperations'],
            ];
        }
        foreach ($definitions as $fieldKey => $definition) {
            $index[$fieldKey] = [
                'type' => (string)$definition['return_type'],
                'operations' => (array)$definition['allowed_operations'],
            ];
        }
        return $index;
    }

    protected function applyFilters(
        array $rows,
        array $filters,
        array $fieldIndex,
        string $filterRelation
    ): array
    {
        $filters = $this->normalizeFilters($filters, $fieldIndex);
        $filterRelation = $this->normalizeFilterRelation($filterRelation);
        if (!$filters) {
            return $rows;
        }
        return array_values(array_filter($rows, function (array $row) use (
            $filters,
            $fieldIndex,
            $filterRelation
        ): bool {
            $matched = 0;
            foreach ($filters as $filter) {
                $value = array_key_exists($filter['field_key'], $row)
                    ? $row[$filter['field_key']]
                    : null;
                $conditionMatches = $this->matches(
                    $value,
                    $filter['operator'],
                    $filter['value'],
                    $fieldIndex[$filter['field_key']]['type']
                );
                if ($conditionMatches) {
                    $matched++;
                } elseif ($filterRelation === 'all') {
                    return false;
                }
            }
            return $filterRelation === 'all' || $matched > 0;
        }));
    }

    protected function normalizeFilters(array $filters, array $fieldIndex): array
    {
        if (count($filters) > self::MAX_FILTERS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_TOO_MANY_FILTERS',
                '组合筛选最多支持 ' . self::MAX_FILTERS . ' 项条件。',
                []
            );
        }
        $allowed = [
            'equal', 'not_equal', 'contains', 'starts_with',
            'not_contains',
            'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal',
            'between', 'in', 'is_null', 'is_not_null',
        ];
        $normalized = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                throw $this->invalidQuery('筛选条件必须是结构化对象');
            }
            foreach (array_keys($filter) as $key) {
                if (!in_array($key, [
                    'fieldKey', 'field_key', 'field', 'operator', 'value', 'valueTo',
                ], true)) {
                    throw $this->invalidQuery('筛选条件包含未知配置：' . $key);
                }
            }
            $fieldKey = (string)($filter['fieldKey']
                ?? ($filter['field_key'] ?? ($filter['field'] ?? '')));
            $operator = strtolower((string)($filter['operator'] ?? ''));
            $operatorMap = [
                'eq' => 'equal',
                'neq' => 'not_equal',
                'gt' => 'greater_than',
                'gte' => 'greater_or_equal',
                'lt' => 'less_than',
                'lte' => 'less_or_equal',
            ];
            $operator = $operatorMap[$operator] ?? $operator;
            $this->assertFieldOperation($fieldIndex, $fieldKey, 'filter');
            if (!in_array($operator, $allowed, true)) {
                throw $this->invalidQuery('筛选操作符不在白名单中');
            }
            $value = $filter['value'] ?? null;
            if ($operator === 'between' && !is_array($value)
                && array_key_exists('valueTo', $filter)) {
                $value = [$value, $filter['valueTo']];
            }
            if ($operator === 'between' && (!is_array($value) || count($value) !== 2)) {
                throw $this->invalidQuery('区间筛选必须包含起始值和结束值');
            }
            if ($operator === 'in' && (!is_array($value) || !$value || count($value) > 100)) {
                throw $this->invalidQuery('多选筛选需包含 1 到 100 个值');
            }
            $this->assertFilterType(
                $fieldIndex[$fieldKey]['type'],
                $operator,
                $value
            );
            $normalized[] = [
                'field_key' => $fieldKey,
                'operator' => $operator,
                'value' => $value,
            ];
        }
        return $normalized;
    }

    protected function normalizeSorts(array $sorts, array $fieldIndex, string $stableKey): array
    {
        if (count($sorts) > self::MAX_SORTS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_TOO_MANY_SORTS',
                '数据排序最多支持 ' . self::MAX_SORTS . ' 项。',
                []
            );
        }
        $normalized = [];
        foreach ($sorts as $sort) {
            if (!is_array($sort)) {
                throw $this->invalidQuery('排序必须是结构化对象');
            }
            $fieldKey = (string)($sort['fieldKey'] ?? ($sort['field_key'] ?? ($sort['field'] ?? '')));
            $direction = strtolower((string)($sort['direction'] ?? 'asc'));
            $this->assertFieldOperation($fieldIndex, $fieldKey, 'sort');
            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw $this->invalidQuery('排序方向不合法');
            }
            $normalized[] = ['field_key' => $fieldKey, 'direction' => $direction];
        }
        $hasStable = false;
        foreach ($normalized as $sort) {
            if ($sort['field_key'] === $stableKey) {
                $hasStable = true;
            }
        }
        if (!$hasStable) {
            $normalized[] = ['field_key' => $stableKey, 'direction' => 'asc'];
        }
        return $normalized;
    }

    protected function applySorts(array $rows, array $sorts, array $fieldIndex): array
    {
        usort($rows, function (array $left, array $right) use ($sorts, $fieldIndex): int {
            foreach ($sorts as $sort) {
                $fieldKey = $sort['field_key'];
                $comparison = $this->compare(
                    $left[$fieldKey] ?? null,
                    $right[$fieldKey] ?? null,
                    $fieldIndex[$fieldKey]['type']
                );
                if ($comparison !== 0) {
                    return $sort['direction'] === 'desc' ? -$comparison : $comparison;
                }
            }
            return 0;
        });
        return $rows;
    }

    protected function summaries(array $rows, array $summaries, array $fieldIndex): array
    {
        $summaries = $this->normalizeSummaries($summaries, $fieldIndex);
        $result = [];
        foreach ($summaries as $summary) {
            $key = $summary['field_key'] . ':' . $summary['operator'];
            $result[$key] = $this->summarize(
                $rows,
                $summary['field_key'],
                $summary['operator'],
                $fieldIndex[$summary['field_key']]['type']
            );
        }
        return $result;
    }

    protected function normalizeSummaries(array $summaries, array $fieldIndex): array
    {
        if (count($summaries) > self::MAX_SUMMARIES) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_TOO_MANY_SUMMARIES',
                '合计项最多支持 ' . self::MAX_SUMMARIES . ' 项。',
                []
            );
        }
        $normalized = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                throw $this->invalidQuery('合计项必须是结构化对象');
            }
            $fieldKey = (string)($summary['fieldKey']
                ?? ($summary['field_key'] ?? ($summary['field'] ?? '')));
            $operator = (string)($summary['operator'] ?? ($summary['aggregation'] ?? ''));
            $operatorMap = [
                'sum' => 'sum',
                'avg' => 'average',
                'average' => 'average',
                'min' => 'minimum',
                'minimum' => 'minimum',
                'max' => 'maximum',
                'maximum' => 'maximum',
                'count' => 'count',
            ];
            $operator = $operatorMap[$operator] ?? '';
            $this->assertFieldOperation($fieldIndex, $fieldKey, 'summary');
            if (!in_array($operator, ['sum', 'average', 'minimum', 'maximum', 'count'], true)) {
                throw $this->invalidQuery('合计方式不在白名单中');
            }
            $normalized[] = ['field_key' => $fieldKey, 'operator' => $operator];
        }
        return $normalized;
    }

    protected function groups(
        array $rows,
        array $groups,
        array $summaries,
        array $fieldIndex
    ): array {
        $groups = $this->normalizeGroups($groups, $fieldIndex);
        if (!$groups) {
            return [];
        }
        $buckets = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($groups as $group) {
                $values[$group] = $row[$group] ?? null;
            }
            $bucketKey = UnifiedQueryJson::encode($values);
            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = ['values' => $values, 'rows' => []];
            }
            $buckets[$bucketKey]['rows'][] = $row;
        }
        $result = [];
        foreach ($buckets as $bucket) {
            $result[] = [
                'values' => $bucket['values'],
                'count' => count($bucket['rows']),
                'summaries' => $this->summaries($bucket['rows'], $summaries, $fieldIndex),
            ];
        }
        return $result;
    }

    protected function normalizeGroups(array $groups, array $fieldIndex): array
    {
        if (count($groups) > self::MAX_GROUPS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_TOO_MANY_GROUPS',
                '分组最多支持 ' . self::MAX_GROUPS . ' 项。',
                []
            );
        }
        $normalized = [];
        foreach ($groups as $group) {
            $fieldKey = is_array($group)
                ? (string)($group['fieldKey'] ?? ($group['field_key'] ?? ''))
                : (string)$group;
            $this->assertFieldOperation($fieldIndex, $fieldKey, 'group');
            $normalized[] = $fieldKey;
        }
        return array_values(array_unique($normalized));
    }

    protected function exportRows(
        array $allRows,
        array $pageRows,
        array $export,
        array $fieldIndex
    ): array {
        $scope = (string)($export['scope'] ?? 'query');
        if (!in_array($scope, ['query', 'page'], true)) {
            throw $this->invalidQuery('导出范围不合法');
        }
        $fields = $export['fields'] ?? [];
        if (!is_array($fields) || !$fields || count($fields) > 100) {
            throw $this->invalidQuery('导出字段数量不合法');
        }
        $fields = array_values(array_unique(array_map('strval', $fields)));
        foreach ($fields as $fieldKey) {
            $this->assertFieldOperation($fieldIndex, $fieldKey, 'export');
        }
        $rows = $scope === 'page' ? $pageRows : $allRows;
        $result = [];
        foreach ($rows as $row) {
            $selected = [];
            foreach ($fields as $fieldKey) {
                $selected[$fieldKey] = $row[$fieldKey] ?? null;
            }
            $result[] = $selected;
        }
        return $result;
    }

    protected function summarize(
        array $rows,
        string $fieldKey,
        string $operator,
        string $type
    ) {
        $values = [];
        foreach ($rows as $row) {
            if (array_key_exists($fieldKey, $row) && $row[$fieldKey] !== null) {
                $values[] = $row[$fieldKey];
            }
        }
        if ($operator === 'count') {
            return count($values);
        }
        if (!$values) {
            return null;
        }
        if ($operator === 'minimum' || $operator === 'maximum') {
            $selected = array_shift($values);
            foreach ($values as $value) {
                $comparison = $this->compare($value, $selected, $type);
                if (($operator === 'minimum' && $comparison < 0)
                    || ($operator === 'maximum' && $comparison > 0)) {
                    $selected = $value;
                }
            }
            return $selected;
        }
        if (!in_array($type, ['integer', 'decimal', 'amount'], true)) {
            throw $this->invalidQuery('该字段不支持求和或平均');
        }
        $this->assertBcMath();
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, (string)$value, StructuredExpressionEvaluator::INTERNAL_SCALE);
        }
        if ($operator === 'average') {
            $sum = bcdiv($sum, (string)count($values), StructuredExpressionEvaluator::INTERNAL_SCALE);
        }
        $scale = $type === 'amount' ? 2 : ($type === 'integer' && $operator === 'sum' ? 0 : 6);
        return $this->roundDecimal($sum, $scale);
    }

    protected function matches($actual, string $operator, $expected, string $type): bool
    {
        if ($operator === 'is_null') {
            return $actual === null;
        }
        if ($operator === 'is_not_null') {
            return $actual !== null;
        }
        if ($actual === null) {
            return false;
        }
        if ($operator === 'contains') {
            return strpos($this->lower((string)$actual), $this->lower((string)$expected)) !== false;
        }
        if ($operator === 'not_contains') {
            return strpos($this->lower((string)$actual), $this->lower((string)$expected)) === false;
        }
        if ($operator === 'starts_with') {
            return strpos($this->lower((string)$actual), $this->lower((string)$expected)) === 0;
        }
        if ($operator === 'in') {
            foreach ((array)$expected as $item) {
                if ($this->compare($actual, $item, $type) === 0) {
                    return true;
                }
            }
            return false;
        }
        if ($operator === 'between') {
            return $this->compare($actual, $expected[0], $type) >= 0
                && $this->compare($actual, $expected[1], $type) <= 0;
        }
        $comparison = $this->compare($actual, $expected, $type);
        $map = [
            'equal' => $comparison === 0,
            'not_equal' => $comparison !== 0,
            'greater_than' => $comparison > 0,
            'greater_or_equal' => $comparison >= 0,
            'less_than' => $comparison < 0,
            'less_or_equal' => $comparison <= 0,
        ];
        return $map[$operator] ?? false;
    }

    protected function compare($left, $right, string $type): int
    {
        if ($left === null && $right === null) {
            return 0;
        }
        if ($left === null) {
            return 1;
        }
        if ($right === null) {
            return -1;
        }
        if (in_array($type, ['integer', 'decimal', 'amount'], true)) {
            $this->assertBcMath();
            return bccomp((string)$left, (string)$right, StructuredExpressionEvaluator::INTERNAL_SCALE);
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            return strcmp((string)$left, (string)$right);
        }
        if ($type === 'boolean') {
            return ((int)(bool)$left) <=> ((int)(bool)$right);
        }
        return strcmp($this->lower((string)$left), $this->lower((string)$right));
    }

    protected function customOperations(string $type, bool $aggregate): array
    {
        $operations = ['display', 'filter', 'sort', 'export'];
        if (!$aggregate) {
            $operations[] = 'quick';
        }
        if (!$aggregate && in_array($type, ['text', 'date', 'datetime', 'boolean'], true)) {
            $operations[] = 'group';
        }
        if (in_array($type, ['integer', 'decimal', 'amount'], true)) {
            $operations[] = 'summary';
        }
        return $operations;
    }

    protected function assertFieldOperation(
        array $fieldIndex,
        string $fieldKey,
        string $operation
    ): void {
        if (!isset($fieldIndex[$fieldKey])
            || !in_array($operation, $fieldIndex[$fieldKey]['operations'], true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_OPERATION_FORBIDDEN',
                '该字段不支持当前查询方式，请重新选择。',
                ['field_key' => $fieldKey, 'operation' => $operation]
            );
        }
    }

    protected function assertFilterType(string $type, string $operator, $value): void
    {
        $nullOperators = ['is_null', 'is_not_null'];
        if (in_array($operator, $nullOperators, true)) {
            return;
        }
        $byType = [
            'text' => ['equal', 'not_equal', 'contains', 'not_contains', 'starts_with', 'in'],
            'integer' => [
                'equal', 'not_equal', 'greater_than', 'greater_or_equal',
                'less_than', 'less_or_equal', 'between', 'in',
            ],
            'decimal' => [
                'equal', 'not_equal', 'greater_than', 'greater_or_equal',
                'less_than', 'less_or_equal', 'between', 'in',
            ],
            'amount' => [
                'equal', 'not_equal', 'greater_than', 'greater_or_equal',
                'less_than', 'less_or_equal', 'between', 'in',
            ],
            'date' => [
                'equal', 'not_equal', 'greater_than', 'greater_or_equal',
                'less_than', 'less_or_equal', 'between', 'in',
            ],
            'datetime' => [
                'equal', 'not_equal', 'greater_than', 'greater_or_equal',
                'less_than', 'less_or_equal', 'between', 'in',
            ],
            'boolean' => ['equal', 'not_equal', 'in'],
        ];
        if (!isset($byType[$type]) || !in_array($operator, $byType[$type], true)) {
            throw $this->invalidQuery('字段类型不支持该筛选方式');
        }
        $values = in_array($operator, ['between', 'in'], true) ? (array)$value : [$value];
        foreach ($values as $item) {
            if ($type === 'text') {
                if (!is_scalar($item) || is_bool($item)) {
                    throw $this->invalidQuery('文本筛选值格式不正确');
                }
                $length = function_exists('mb_strlen')
                    ? mb_strlen((string)$item, 'UTF-8')
                    : strlen((string)$item);
                if ($length > 255) {
                    throw $this->invalidQuery('文本筛选值不能超过 255 个字符');
                }
            } elseif (in_array($type, ['integer', 'decimal', 'amount'], true)) {
                $pattern = $type === 'integer'
                    ? '/^-?(?:0|[1-9][0-9]{0,17})$/D'
                    : '/^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,8})?$/D';
                if (!is_int($item) && !is_float($item) && !is_string($item)) {
                    throw $this->invalidQuery('数值筛选值格式不正确');
                }
                if (!preg_match($pattern, trim((string)$item))) {
                    throw $this->invalidQuery('数值筛选值超出精度范围');
                }
            } elseif ($type === 'date' || $type === 'datetime') {
                $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
                $date = \DateTimeImmutable::createFromFormat('!' . $format, (string)$item);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($date === false
                    || ($errors !== false
                        && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
                    || $date->format($format) !== (string)$item) {
                    throw $this->invalidQuery('日期筛选值格式不正确');
                }
            } elseif ($type === 'boolean'
                && !in_array($item, [true, false, 0, 1, '0', '1'], true)) {
                throw $this->invalidQuery('是／否筛选值格式不正确');
            }
        }
    }

    protected function assertStableRows(array $rows, string $stableKey): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (!array_key_exists($stableKey, $row) || (string)$row[$stableKey] === '') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_STABLE_KEY_MISSING',
                    '查询结果缺少稳定标识，已停止分页以避免重复或漏项。',
                    ['stable_key' => $stableKey]
                );
            }
            $value = (string)$row[$stableKey];
            if ($stableKey === 'member_id'
                && (!preg_match('/^[1-9][0-9]*$/D', $value) || (int)$value <= 0)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_STABLE_KEY_INVALID',
                    '会员查询结果缺少有效的内部标识，已停止分页。',
                    ['stable_key' => $stableKey, 'value' => $value]
                );
            }
            if (isset($seen[$value])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_STABLE_KEY_DUPLICATE',
                    '查询结果标识重复，已停止分页以避免重复或漏项。',
                    ['stable_key' => $stableKey, 'value' => $value]
                );
            }
            $seen[$value] = true;
        }
    }

    protected function assertQueryShape(array $query): void
    {
        $allowed = [
            'page', 'limit', 'filters', 'filterRelation', 'sorts', 'groups', 'groupBy',
            'summaries', 'export', 'dataScope', 'businessStatus', 'quickFilters', 'visibleFields',
            'fieldVersions', 'keywordFilters',
            'topFilterConditions',
            'queryCutoffDate',
        ];
        foreach (array_keys($query) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw $this->invalidQuery('查询参数不在白名单中：' . $key);
            }
        }
        foreach (['filters', 'topFilterConditions', 'keywordFilters', 'sorts', 'groups', 'groupBy', 'summaries', 'quickFilters', 'visibleFields'] as $key) {
            if (isset($query[$key]) && !is_array($query[$key])) {
                throw $this->invalidQuery($key . ' 必须是数组');
            }
        }
        if (isset($query['export']) && !is_array($query['export'])) {
            throw $this->invalidQuery('export 必须是结构化对象');
        }
        $this->normalizeFilterRelation((string)($query['filterRelation'] ?? 'all'));
        if (isset($query['dataScope'])
            && !in_array((string)$query['dataScope'], ['normal', 'all'], true)) {
            throw $this->invalidQuery('dataScope 只允许 normal 或 all');
        }
        if (isset($query['businessStatus']) && !is_scalar($query['businessStatus'])) {
            throw $this->invalidQuery('businessStatus 必须是业务状态代码');
        }
    }

    protected function assertCutoffDate(string $date): void
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($value === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $value->format('Y-m-d') !== $date) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_REQUIRED',
                '查询截止日期无效，请刷新后重试。',
                ['query_cutoff_date' => $date]
            );
        }
    }

    protected function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    protected function roundDecimal(string $value, int $scale): string
    {
        $half = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        if (bccomp($value, '0', StructuredExpressionEvaluator::INTERNAL_SCALE) < 0) {
            $half = '-' . $half;
        }
        return bcadd($value, $half, $scale);
    }

    protected function assertBcMath(): void
    {
        if (!function_exists('bcadd')) {
            throw new \RuntimeException('统一查询金额计算需要 ext-bcmath');
        }
    }

    protected function invalidQuery(string $reason): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_REQUEST_INVALID',
            '查询条件不合法，请重新设置。',
            ['reason' => $reason]
        );
    }

    protected function normalizeFilterRelation(string $relation): string
    {
        $relation = strtolower(trim($relation));
        if (!in_array($relation, ['all', 'any'], true)) {
            throw $this->invalidQuery('筛选关系只允许 all 或 any');
        }
        return $relation;
    }

    protected function isList(array $value): bool
    {
        return !$value || array_keys($value) === range(0, count($value) - 1);
    }
}

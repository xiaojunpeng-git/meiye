<?php

namespace app\services\query;

/**
 * 只从统一查询合同中确定的“字段位置”提取自定义字段 key。
 *
 * 绝不能对整份 payload 递归搜字符串：筛选值、关键字和任意文本都可能恰好长得像
 * cf_xxx，但它们不是字段引用。该提取器只读取字段槽位，同时在进入结构化容器前
 * 预扣节点预算，避免在正式计划校验前被深层或超宽请求耗尽栈/CPU。
 */
final class UnifiedQueryCustomFieldKeyCollector
{
    public const MAX_DEPTH = 4;
    // 合同允许显示字段、四组筛选与合计并存；1024 覆盖全部合法上限，
    // 同时在进入超宽请求前 fail-closed。
    public const MAX_NODES = 1024;

    /**
     * 查询页面、导出任务和会员投影使用的 UI 查询合同。
     */
    public function collectFromQuery(array $query): array
    {
        $state = $this->newState();
        $this->reserveRecord($state, $query, 1, '查询参数');

        $settings = [];
        if (array_key_exists('querySettings', $query) && is_array($query['querySettings'])) {
            $settings = $query['querySettings'];
            $this->reserveRecord($state, $settings, 2, '查询设置');
            // querySettings 只允许一层。继续下钻没有合法业务语义，必须在计划校验前
            // 终止，而不是递归扫描未知载荷。
            if (array_key_exists('querySettings', $settings)
                || array_key_exists('query_settings', $settings)) {
                $this->tooComplex('查询设置不能嵌套。', $state, 3);
            }
        }

        $this->collectFieldList(
            $state,
            $this->effectiveArray($query, 'visibleFields', $settings),
            2,
            '显示字段'
        );
        // quickFields 只存在于保存的 querySettings；它是字段配置，不读取其值以外
        // 的任何内容。
        $this->collectFieldList(
            $state,
            is_array($settings['quickFields'] ?? null) ? $settings['quickFields'] : [],
            3,
            '顶部字段'
        );
        $this->collectFilterList(
            $state,
            $this->effectiveArray($query, 'filters', $settings),
            2,
            '组合筛选'
        );
        $this->collectFilterList(
            $state,
            is_array($query['topFilterConditions'] ?? null)
                ? $query['topFilterConditions']
                : [],
            2,
            '顶部条件'
        );
        $this->collectTopFilters($state, $query['topFilters'] ?? [], 2);
        // 快捷筛选与普通／顶部筛选一样可能引用自定义字段；只读取字段槽位，
        // 不能将快捷按钮的 label、count、active 等展示值当作字段引用。
        $this->collectFilterList(
            $state,
            is_array($query['quickFilters'] ?? null) ? $query['quickFilters'] : [],
            2,
            '快捷条件'
        );
        $this->collectFilterList(
            $state,
            is_array($query['keywordFilters'] ?? null) ? $query['keywordFilters'] : [],
            2,
            '关键词条件'
        );
        $this->collectSortList(
            $state,
            $this->effectiveArray($query, 'sorts', $settings),
            2,
            '排序'
        );
        $this->collectGroupList(
            $state,
            $this->effectiveGroups($query, $settings),
            2,
            '分组'
        );
        $this->collectSummaryList(
            $state,
            $this->effectiveArray($query, 'summaries', $settings),
            2,
            '合计'
        );
        if (isset($query['export']) && is_array($query['export'])) {
            $this->reserveRecord($state, $query['export'], 2, '导出配置');
            $this->collectFieldList(
                $state,
                is_array($query['export']['fields'] ?? null) ? $query['export']['fields'] : [],
                3,
                '导出字段'
            );
        }

        return array_keys($state['keys']);
    }

    /**
     * 保存查询设置的专用入口。
     */
    public function collectFromSettings(array $settings): array
    {
        $state = $this->newState();
        $this->reserveRecord($state, $settings, 1, '查询设置');
        if (array_key_exists('querySettings', $settings)
            || array_key_exists('query_settings', $settings)) {
            $this->tooComplex('查询设置不能嵌套。', $state, 2);
        }
        $this->collectFieldList(
            $state,
            is_array($settings['visibleFields'] ?? null) ? $settings['visibleFields'] : [],
            2,
            '显示字段'
        );
        $this->collectFieldList(
            $state,
            is_array($settings['quickFields'] ?? null) ? $settings['quickFields'] : [],
            2,
            '顶部字段'
        );
        $this->collectFilterList(
            $state,
            is_array($settings['filters'] ?? null) ? $settings['filters'] : [],
            2,
            '组合筛选'
        );
        $this->collectSortList(
            $state,
            is_array($settings['sorts'] ?? null) ? $settings['sorts'] : [],
            2,
            '排序'
        );
        $this->collectGroupList(
            $state,
            is_array($settings['groupBy'] ?? null) ? $settings['groupBy'] : [],
            2,
            '分组'
        );
        $this->collectSummaryList(
            $state,
            is_array($settings['summaries'] ?? null) ? $settings['summaries'] : [],
            2,
            '合计'
        );
        return array_keys($state['keys']);
    }

    /**
     * 导出命令外层 fields 也属于字段位置，复用同一预算与正则。
     */
    public function collectFromFieldList(array $fields): array
    {
        $state = $this->newState();
        $this->collectFieldList($state, $fields, 1, '导出字段');
        return array_keys($state['keys']);
    }

    protected function newState(): array
    {
        return ['nodes' => 0, 'keys' => []];
    }

    protected function effectiveArray(array $query, string $key, array $settings): array
    {
        if (is_array($query[$key] ?? null)) {
            return $query[$key];
        }
        return is_array($settings[$key] ?? null) ? $settings[$key] : [];
    }

    protected function effectiveGroups(array $query, array $settings): array
    {
        if (is_array($query['groupBy'] ?? null)) {
            return $query['groupBy'];
        }
        if (is_array($query['groups'] ?? null)) {
            return $query['groups'];
        }
        return is_array($settings['groupBy'] ?? null) ? $settings['groupBy'] : [];
    }

    protected function collectFieldList(array &$state, array $fields, int $depth, string $label): void
    {
        $this->reserveList($state, $fields, $depth, $label);
        foreach ($fields as $fieldKey) {
            $this->addKey($state, $fieldKey);
        }
    }

    protected function collectFilterList(array &$state, array $filters, int $depth, string $label): void
    {
        $this->reserveList($state, $filters, $depth, $label);
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $this->reserveRecord($state, $filter, $depth + 1, $label . '项');
            $this->addRecordFieldKey($state, $filter);
            // 刻意不读取 value/valueTo：它们是数据值，不是字段引用。
        }
    }

    protected function collectTopFilters(array &$state, $filters, int $depth): void
    {
        if (!is_array($filters)) {
            return;
        }
        $this->reserveList($state, $filters, $depth, '顶部筛选');
        if ($this->isList($filters)) {
            foreach ($filters as $filter) {
                if (!is_array($filter)) {
                    continue;
                }
                $this->reserveRecord($state, $filter, $depth + 1, '顶部筛选项');
                $this->addRecordFieldKey($state, $filter);
            }
            return;
        }
        // map 形态的 topFilters 只有 key 是字段名，map value 是用户输入值。
        foreach ($filters as $fieldKey => $ignoredValue) {
            $this->addKey($state, $fieldKey);
        }
    }

    protected function collectSortList(array &$state, array $sorts, int $depth, string $label): void
    {
        $this->reserveList($state, $sorts, $depth, $label);
        foreach ($sorts as $sort) {
            if (!is_array($sort)) {
                continue;
            }
            $this->reserveRecord($state, $sort, $depth + 1, $label . '项');
            $this->addRecordFieldKey($state, $sort);
        }
    }

    protected function collectGroupList(array &$state, array $groups, int $depth, string $label): void
    {
        $this->reserveList($state, $groups, $depth, $label);
        foreach ($groups as $group) {
            if (is_array($group)) {
                $this->reserveRecord($state, $group, $depth + 1, $label . '项');
                $this->addRecordFieldKey($state, $group);
                continue;
            }
            $this->addKey($state, $group);
        }
    }

    protected function collectSummaryList(array &$state, array $summaries, int $depth, string $label): void
    {
        $this->reserveList($state, $summaries, $depth, $label);
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $this->reserveRecord($state, $summary, $depth + 1, $label . '项');
            $this->addRecordFieldKey($state, $summary);
        }
    }

    protected function addRecordFieldKey(array &$state, array $record): void
    {
        foreach (['fieldKey', 'field_key', 'field'] as $key) {
            if (array_key_exists($key, $record)) {
                $this->addKey($state, $record[$key]);
                return;
            }
        }
    }

    protected function addKey(array &$state, $value): void
    {
        if (is_string($value) && preg_match('/^cf_[a-f0-9]{20,40}$/D', $value)) {
            $state['keys'][$value] = true;
        }
    }

    protected function reserveList(array &$state, array $items, int $depth, string $label): void
    {
        // 先用 count 预扣完整容器预算，再遍历；不能靠遍历后才发现超限。
        $this->reserve($state, count($items) + 1, $depth, $label);
    }

    protected function reserveRecord(array &$state, array $record, int $depth, string $label): void
    {
        $this->reserve($state, count($record) + 1, $depth, $label);
    }

    protected function reserve(array &$state, int $count, int $depth, string $label): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->tooComplex($label . '嵌套层级过深。', $state, $depth);
        }
        $state['nodes'] += max(1, $count);
        if ($state['nodes'] > self::MAX_NODES) {
            $this->tooComplex($label . '内容过多。', $state, $depth);
        }
    }

    protected function tooComplex(string $message, array $state, int $depth): void
    {
        throw new UnifiedQueryException(
            'UNIFIED_QUERY_QUERY_TOO_COMPLEX',
            '查询结构过于复杂，请减少条件后重试。',
            [
                'reason' => $message,
                'max_depth' => self::MAX_DEPTH,
                'max_nodes' => self::MAX_NODES,
                'depth' => $depth,
                'nodes' => (int)$state['nodes'],
            ]
        );
    }

    protected function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $ignored) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
    }
}

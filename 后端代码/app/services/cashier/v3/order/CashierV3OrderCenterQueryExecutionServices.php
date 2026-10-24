<?php
namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryExecutionServices;
use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices;

/** 订单人员是一对多关系；隐藏身份列精确匹配 ID，不用姓名或 JSON 子串猜测。 */
final class CashierV3OrderCenterQueryExecutionServices extends UnifiedQueryExecutionServices
{
    /** 查询与导出计划同时去除已隐藏来源条件；不改偏好记录、不改事实，更不扩大门店权限。 */
    public function normalizeUiQuery(string $pageCode, array $raw, array $permissions = []): array
    {
        $policy = (new CashierV3BusinessSourcePolicyServices())->read();
        if (($policy['enabled'] ?? true) === false) $raw = self::withoutBusinessSource($raw);
        return parent::normalizeUiQuery($pageCode, $raw, $permissions);
    }

    /** 兼容旧 UI 与冻结计划字段别名；只删稳定 key=source，不能误删权益、卡号或原单追溯。 */
    public static function withoutBusinessSource(array $query): array
    {
        foreach (['topFilters','topFilterConditions','filters','quickFilters','keywordFilters','sorts','summaries','groups','groupBy','visibleFields','quickFields'] as $group) {
            if (!is_array($query[$group] ?? null)) continue;
            $query[$group] = array_values(array_filter($query[$group], static function ($item): bool {
                $key = is_string($item) ? $item : (is_array($item) ? ($item['field_key'] ?? $item['fieldKey'] ?? $item['field'] ?? $item['key'] ?? '') : '');
                return $key !== 'source';
            }));
        }
        if (is_array($query['querySettings'] ?? null)) $query['querySettings'] = self::withoutBusinessSource($query['querySettings']);
        if (is_array($query['export']['fields'] ?? null)) {
            $query['export']['fields'] = array_values(array_filter($query['export']['fields'], static function ($key): bool { return $key !== 'source'; }));
        }
        return $query;
    }

    protected function fieldIndex(string $pageCode, array $permissions, array $definitions): array
    {
        $index = parent::fieldIndex($pageCode, $permissions, $definitions);
        // 隐藏身份列只继承可读业务字段的筛选权限，不开放显示、导出或自定义表达式。
        foreach (['salesperson','cashier','operator','craftsman','sales_manager','guide','void_operator','store'] as $key) {
            if (isset($index[$key])) $index[$key . '_query_ids'] = ['type'=>'text','operations'=>['filter']];
        }
        return $index;
    }

    protected function matches($actual, string $operator, $expected, string $type): bool
    {
        if (!is_array($actual)) return parent::matches($actual, $operator, $expected, $type);
        if ($operator === 'is_null') return $actual === [];
        if ($operator === 'is_not_null') return $actual !== [];
        // 否定是“没有任何一个人员匹配”，而不是“存在另一个不同人员”。
        $negative = in_array($operator, ['not_equal', 'not_contains'], true);
        $positive = $operator === 'not_equal' ? 'equal' : ($operator === 'not_contains' ? 'contains' : $operator);
        foreach ($actual as $id) {
            if (parent::matches((string)$id, $positive, $expected, $type)) return !$negative;
        }
        return $negative;
    }

    /** 先按统一查询筛出订单，再把销售单展开为页面上的商品明细；其他页签保持原粒度。 */
    protected function exportRows(array $allRows, array $pageRows, array $export, array $fieldIndex): array
    {
        $selectedRows = parent::exportRows($allRows, $pageRows, $export, $fieldIndex);
        $sourceRows = array_values(($export['scope'] ?? 'query') === 'page' ? $pageRows : $allRows);
        $result = [];
        foreach ($selectedRows as $index => $row) {
            $source = $sourceRows[$index];
            if (strpos((string)($source['record_id'] ?? ''), 'sales:') === 0) {
                foreach (CashierV3SalesOrderExportRows::project($source, $row) as $line) $result[] = $line;
                continue;
            }
            // 其余页签仍是一条业务记录一行，兼容其已有多值文本字段。
            foreach ($row as &$value) if (is_array($value)) $value = implode('、', array_map('strval', $value));
            unset($value);
            $result[] = $row;
        }
        return $result;
    }
}

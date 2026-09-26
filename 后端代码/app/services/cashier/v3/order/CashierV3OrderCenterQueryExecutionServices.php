<?php
namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryExecutionServices;

/** 订单人员是一对多关系；隐藏身份列精确匹配 ID，不用姓名或 JSON 子串猜测。 */
final class CashierV3OrderCenterQueryExecutionServices extends UnifiedQueryExecutionServices
{
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

    /** 一单多明细仍导出一行；按原顺序列出各明细值，不能转成一个虚构金额或数组字符串。 */
    protected function exportRows(array $allRows, array $pageRows, array $export, array $fieldIndex): array
    {
        $rows = parent::exportRows($allRows, $pageRows, $export, $fieldIndex);
        foreach ($rows as &$row) foreach ($row as &$value) {
            if (is_array($value)) $value = implode('、', array_map('strval', $value));
        }
        unset($row, $value);
        return $rows;
    }
}

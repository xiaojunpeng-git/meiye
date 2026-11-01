<?php
namespace app\services\query;

/** 只替换聚合的存储方式；AST验证、空值、精度和最终表达式运算仍复用同一执行器。 */
final class StructuredExpressionStreamingEvaluator extends StructuredExpressionEvaluator
{
    private $rows;
    private $checkpoint;
    public function evaluateRows(array $expression, array $context, \IteratorAggregate $rows, ?callable $checkpoint = null)
    {
        $this->rows = $rows; $this->checkpoint = $checkpoint;
        try { return $this->evaluate($expression, [], $context, []); }
        finally { $this->rows = null; $this->checkpoint = null; }
    }
    protected function aggregate(string $operator, array $child, array $context, array $rows, string $resultType)
    {
        $count = 0; $sum = '0'; $selected = null; $visited = 0;
        foreach ($this->rows as $row) {
            if (++$visited % 256 === 0 && $this->checkpoint) call_user_func($this->checkpoint);
            $value = $this->evaluateNode($child, $row, $context, []);
            if ($value === null) continue;
            ++$count;
            if ($operator === 'count') continue;
            if ($operator === 'minimum' || $operator === 'maximum') {
                $cmp = $count === 1 ? 0 : $this->compare($value, $selected);
                if ($count === 1 || ($operator === 'minimum' ? $cmp < 0 : $cmp > 0)) $selected = $value;
            } else $sum = $this->bc('add', $sum, $this->numeric($value), self::INTERNAL_SCALE);
        }
        if ($operator === 'count') return $count;
        if (!$count) return null;
        if ($operator === 'minimum' || $operator === 'maximum') return $selected;
        if ($operator === 'average') $sum = $this->bc('divide', $sum, (string)$count, self::INTERNAL_SCALE);
        return $this->roundDecimal($sum, $resultType === 'amount' ? self::AMOUNT_SCALE : self::DECIMAL_SCALE);
    }
}

<?php

namespace app\services\query;

/**
 * 已验证 AST 的纯 PHP 安全执行器。禁止 eval、动态函数名和 SQL 拼接。
 */
class StructuredExpressionEvaluator
{
    public const INTERNAL_SCALE = 8;
    public const DECIMAL_SCALE = 6;
    public const AMOUNT_SCALE = 2;

    public function evaluate(array $expression, array $row, array $context, array $groupRows = [])
    {
        if (!isset($context['query_cutoff_date'])
            || !$this->validDate((string)$context['query_cutoff_date'], 'Y-m-d')) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_REQUIRED',
                '查询截止日期无效，请刷新后重试。',
                ['query_cutoff_date' => $context['query_cutoff_date'] ?? null]
            );
        }
        $value = $this->evaluateNode($expression, $row, $context, $groupRows, true);
        return $this->normalizeRootValue($value, $this->rootReturnType($expression));
    }

    protected function evaluateNode(
        array $node,
        array $row,
        array $context,
        array $groupRows,
        bool $isRoot = false
    )
    {
        $type = (string)($node['type'] ?? '');
        if ($type === 'field') {
            $key = (string)($node['key'] ?? '');
            return array_key_exists($key, $row) ? $row[$key] : null;
        }
        if ($type === 'context') {
            return $context[(string)($node['key'] ?? '')] ?? null;
        }
        if ($type === 'literal') {
            if (($node['value_type'] ?? '') === 'null') {
                return null;
            }
            if (($node['value_type'] ?? '') === 'boolean') {
                return $node['value'] === true || $node['value'] === 1 || $node['value'] === '1';
            }
            return $node['value'] ?? null;
        }
        if ($type !== 'operator') {
            throw new \LogicException('表达式必须先通过 StructuredExpressionValidator');
        }

        $operator = (string)$node['operator'];
        if ($operator === 'range_bucket') {
            return $this->rangeBucket($node, $row, $context, $groupRows);
        }
        $args = (array)$node['args'];
        if (in_array($operator, ['sum', 'average', 'minimum', 'maximum', 'count'], true)) {
            return $this->aggregate(
                $operator,
                $args[0],
                $context,
                $groupRows,
                $this->nodeType($node, $isRoot)
            );
        }
        if ($operator === 'bucket') {
            return $this->bucket($args, $row, $context, $groupRows);
        }

        $values = [];
        foreach ($args as $arg) {
            $values[] = $this->evaluateNode($arg, $row, $context, $groupRows);
        }
        switch ($operator) {
            case 'add':
                return $this->arithmetic('add', $values[0], $values[1], $this->nodeType($node, $isRoot));
            case 'subtract':
                return $this->arithmetic('subtract', $values[0], $values[1], $this->nodeType($node, $isRoot));
            case 'multiply':
                return $this->arithmetic('multiply', $values[0], $values[1], $this->nodeType($node, $isRoot));
            case 'divide':
                return $this->arithmetic('divide', $values[0], $values[1], $this->nodeType($node, $isRoot));
            case 'date_diff_days':
                if ($values[0] === null || $values[1] === null) {
                    return null;
                }
                return $this->dateDiffDays((string)$values[0], (string)$values[1]);
            case 'equal':
                return $values[0] !== null && $values[1] !== null
                    && $this->compare($values[0], $values[1]) === 0;
            case 'not_equal':
                return $values[0] !== null && $values[1] !== null
                    && $this->compare($values[0], $values[1]) !== 0;
            case 'greater_than':
                return $values[0] !== null && $values[1] !== null
                    && $this->compare($values[0], $values[1]) > 0;
            case 'greater_or_equal':
                return $values[0] !== null && $values[1] !== null
                    && $this->compare($values[0], $values[1]) >= 0;
            case 'less_than':
                return $values[0] !== null && $values[1] !== null
                    && $this->compare($values[0], $values[1]) < 0;
            case 'less_or_equal':
                return $values[0] !== null && $values[1] !== null
                    && $this->compare($values[0], $values[1]) <= 0;
            case 'between':
                return $values[0] !== null && $values[1] !== null && $values[2] !== null
                    && $this->compare($values[0], $values[1]) >= 0
                    && $this->compare($values[0], $values[2]) <= 0;
            case 'if':
                return $values[0] === true ? $values[1] : $values[2];
            case 'is_null':
                return $values[0] === null;
            case 'coalesce':
                foreach ($values as $value) {
                    if ($value !== null) {
                        return $value;
                    }
                }
                return null;
        }
        throw new \LogicException('未知操作符：' . $operator);
    }

    protected function bucket(array $args, array $row, array $context, array $groupRows)
    {
        $value = $this->evaluateNode($args[0], $row, $context, $groupRows);
        if ($value === null) {
            return null;
        }
        $lastIndex = count($args) - 1;
        for ($index = 1; $index < $lastIndex; $index += 2) {
            $boundary = $this->evaluateNode($args[$index], $row, $context, $groupRows);
            if ($this->compare($value, $boundary) <= 0) {
                return $this->evaluateNode($args[$index + 1], $row, $context, $groupRows);
            }
        }
        return $this->evaluateNode($args[$lastIndex], $row, $context, $groupRows);
    }

    protected function rangeBucket(array $node, array $row, array $context, array $groupRows): string
    {
        $value = $this->evaluateNode($node['input'], $row, $context, $groupRows);
        if ($value === null) {
            return (string)$node['null_label'];
        }
        $value = $this->numeric($value);
        foreach ((array)$node['thresholds'] as $index => $threshold) {
            if ($this->bcCompare($value, $this->numeric($threshold), self::INTERNAL_SCALE) <= 0) {
                return (string)$node['labels'][$index];
            }
        }
        return (string)$node['default_label'];
    }

    protected function aggregate(string $operator, array $child, array $context, array $rows, string $resultType)
    {
        if (!$rows) {
            return $operator === 'count' ? 0 : null;
        }
        $values = [];
        foreach ($rows as $row) {
            $value = $this->evaluateNode($child, $row, $context, []);
            if ($value !== null) {
                $values[] = $value;
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
                $comparison = $this->compare($value, $selected);
                if (($operator === 'minimum' && $comparison < 0)
                    || ($operator === 'maximum' && $comparison > 0)) {
                    $selected = $value;
                }
            }
            return $selected;
        }
        $sum = '0';
        foreach ($values as $value) {
            $sum = $this->bc('add', $sum, $this->numeric($value), self::INTERNAL_SCALE);
        }
        if ($operator === 'average') {
            $sum = $this->bc('divide', $sum, (string)count($values), self::INTERNAL_SCALE);
        }
        if ($resultType === 'amount') {
            return $this->roundDecimal($sum, self::AMOUNT_SCALE);
        }
        return $this->roundDecimal($sum, self::DECIMAL_SCALE);
    }

    protected function arithmetic(string $operator, $left, $right, string $resultType)
    {
        if ($left === null || $right === null) {
            return null;
        }
        $left = $this->numeric($left);
        $right = $this->numeric($right);
        if ($operator === 'divide' && $this->bcCompare($right, '0', self::INTERNAL_SCALE) === 0) {
            // 动态除数为零使用统一空值，不抛 500，也不伪造 0。
            return null;
        }
        $value = $this->bc($operator, $left, $right, self::INTERNAL_SCALE);
        if ($resultType === 'amount') {
            return $this->roundDecimal($value, self::AMOUNT_SCALE);
        }
        if ($resultType === 'integer') {
            return (int)$this->roundDecimal($value, 0);
        }
        return $this->roundDecimal($value, self::DECIMAL_SCALE);
    }

    /**
     * 只信任验证器持久化在根节点的结果类型。嵌套节点一律采用内部 decimal 精度，
     * 不允许客户端元数据改变中间舍入规则。
     */
    protected function nodeType(array $node, bool $isRoot): string
    {
        if (!$isRoot) {
            return 'decimal';
        }
        $type = (string)($node['_return_type'] ?? '');
        if (in_array($type, ['amount', 'decimal', 'integer'], true)) {
            return $type;
        }
        return 'decimal';
    }

    protected function rootReturnType(array $expression): string
    {
        $type = (string)($expression['_return_type'] ?? '');
        return in_array($type, ['amount', 'decimal', 'integer'], true) ? $type : '';
    }

    /**
     * 所有根表达式（包含 literal/if/coalesce/bucket/min/max）统一落在声明类型精度，
     * 避免同一字段在列表、筛选、合计、分组与导出出现不同金额口径。
     */
    protected function normalizeRootValue($value, string $returnType)
    {
        if ($value === null || $returnType === '') {
            return $value;
        }
        if ($returnType === 'amount') {
            return $this->roundDecimal($this->numeric($value), self::AMOUNT_SCALE);
        }
        if ($returnType === 'decimal') {
            return $this->roundDecimal($this->numeric($value), self::DECIMAL_SCALE);
        }
        return (int)$this->roundDecimal($this->numeric($value), 0);
    }

    protected function compare($left, $right): int
    {
        if ($left === null || $right === null) {
            return -1;
        }
        if ($this->looksNumeric($left) && $this->looksNumeric($right)) {
            return $this->bcCompare($this->numeric($left), $this->numeric($right), self::INTERNAL_SCALE);
        }
        if ($this->looksDate($left) && $this->looksDate($right)) {
            $leftTime = $this->dateValue((string)$left)->getTimestamp();
            $rightTime = $this->dateValue((string)$right)->getTimestamp();
            return $leftTime < $rightTime ? -1 : ($leftTime > $rightTime ? 1 : 0);
        }
        return strcmp((string)$left, (string)$right);
    }

    protected function dateDiffDays(string $left, string $right): int
    {
        $leftDate = $this->dateValue($left)->setTime(0, 0, 0);
        $rightDate = $this->dateValue($right)->setTime(0, 0, 0);
        $seconds = $leftDate->getTimestamp() - $rightDate->getTimestamp();
        return (int)($seconds / 86400);
    }

    protected function dateValue(string $value): \DateTimeImmutable
    {
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        if (!$this->validDate($value, $format)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_DATE_VALUE_INVALID',
                '数据中的日期格式无效，暂时无法计算该字段。',
                ['value' => $value]
            );
        }
        return \DateTimeImmutable::createFromFormat('!' . $format, $value);
    }

    protected function validDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    protected function looksDate($value): bool
    {
        return is_string($value)
            && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value));
    }

    protected function looksNumeric($value): bool
    {
        return is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', trim($value)));
    }

    protected function numeric($value): string
    {
        if (!$this->looksNumeric($value)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_NUMBER_VALUE_INVALID',
                '数据中的数值格式无效，暂时无法计算该字段。',
                ['value' => $value]
            );
        }
        return trim((string)$value);
    }

    protected function bc(string $operator, string $left, string $right, int $scale): string
    {
        $this->assertBcMath();
        switch ($operator) {
            case 'add':
                return bcadd($left, $right, $scale);
            case 'subtract':
                return bcsub($left, $right, $scale);
            case 'multiply':
                return bcmul($left, $right, $scale);
            case 'divide':
                return bcdiv($left, $right, $scale);
        }
        throw new \LogicException('未知高精度操作');
    }

    protected function bcCompare(string $left, string $right, int $scale): int
    {
        $this->assertBcMath();
        return bccomp($left, $right, $scale);
    }

    protected function roundDecimal(string $value, int $scale): string
    {
        $this->assertBcMath();
        $half = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        if (bccomp($value, '0', self::INTERNAL_SCALE) < 0) {
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
}

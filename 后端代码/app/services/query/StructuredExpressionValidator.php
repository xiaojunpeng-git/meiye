<?php

namespace app\services\query;

/**
 * 受控结构化表达式验证器。
 *
 * 只接受固定 AST；不接收 SQL、PHP、JS、Excel 公式或任何可执行文本。
 */
class StructuredExpressionValidator
{
    public const MAX_NODES = 48;
    public const MAX_DEPTH = 8;
    public const MAX_REFERENCES = 12;
    public const MAX_COMPLEXITY = 100;
    public const MAX_NODE_KEYS = 16;
    public const MAX_RANGE_BUCKET_THRESHOLDS = 16;
    public const MAX_RANGE_BUCKET_LABEL_LENGTH = 64;

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var int */
    protected $nodes = 0;

    /** @var int */
    protected $maxDepth = 0;

    /** @var int */
    protected $complexity = 0;

    /** @var int */
    protected $aggregateCount = 0;

    /** @var array<string,array> */
    protected $references = [];

    /** @var array */
    protected $permissions = [];

    /** @var string */
    protected $pageCode = '';

    public function __construct(UnifiedQueryPageRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @return array{expression:array,return_type:string,referenced_fields:array,node_count:int,depth:int,complexity_score:int,uses_aggregate:bool,required_permissions:array}
     */
    public function validate(
        string $pageCode,
        array $expression,
        string $expectedReturnType,
        array $grantedPermissions = []
    ): array {
        $this->reset($pageCode, $grantedPermissions);
        // 归一化也会递归复制节点；必须先预算，不能等复制完才检查复杂度。
        $normalizationNodes = 0;
        $this->assertNormalizationBudget($expression, 1, $normalizationNodes);
        $normalized = $this->normalizeExpression($expression);
        $declaredReturnType = (string)($normalized['_return_type'] ?? '');
        unset($normalized['_return_type']);
        $actualType = $this->inspect($normalized, 1, true);
        $expectedType = UnifiedQueryPageRegistry::normalizeType($expectedReturnType);
        if ($declaredReturnType !== ''
            && UnifiedQueryPageRegistry::normalizeType($declaredReturnType) !== $expectedType) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_RETURN_TYPE_MISMATCH',
                '计算规则中的结果类型与所选字段类型不一致，请重新选择。',
                ['expected' => $expectedType, 'declared' => $declaredReturnType]
            );
        }
        if (!$this->returnTypeCompatible($actualType, $expectedType) || $actualType === 'null') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_RETURN_TYPE_MISMATCH',
                '计算结果类型与所选字段类型不一致，请调整规则。',
                ['expected' => $expectedType, 'actual' => $actualType]
            );
        }
        if ($this->nodes > self::MAX_NODES
            || $this->maxDepth > self::MAX_DEPTH
            || count($this->references) > self::MAX_REFERENCES
            || $this->complexity > self::MAX_COMPLEXITY
            || $this->aggregateCount > 1) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
                '计算规则过于复杂，请减少条件或计算步骤。',
                [
                    'node_count' => $this->nodes,
                    'depth' => $this->maxDepth,
                    'reference_count' => count($this->references),
                    'complexity_score' => $this->complexity,
                    'aggregate_count' => $this->aggregateCount,
                ]
            );
        }

        $requiredPermissions = [];
        foreach ($this->references as $field) {
            if ((string)$field['permission'] !== '') {
                $requiredPermissions[] = (string)$field['permission'];
            }
        }

        $normalized['_return_type'] = $expectedType;
        return [
            'expression' => $normalized,
            'return_type' => $expectedType,
            'referenced_fields' => array_keys($this->references),
            'node_count' => $this->nodes,
            'depth' => $this->maxDepth,
            'complexity_score' => $this->complexity,
            'uses_aggregate' => $this->aggregateCount > 0,
            'required_permissions' => array_values(array_unique($requiredPermissions)),
        ];
    }

    /**
     * 兼容确认稿首批左右字段配置，立即转换为同一 AST，后续不保留第二套执行逻辑。
     */
    public function normalizeExpression(array $expression): array
    {
        if (($expression['type'] ?? '') === 'binary') {
            $this->assertOnlyKeys($expression, [
                'type', 'operator', 'left', 'right', 'nullMode', 'returnType',
            ]);
            $operator = $this->normalizeOperatorName((string)($expression['operator'] ?? ''));
            if (!in_array($operator, ['add', 'subtract', 'multiply', 'divide', 'date_diff_days'], true)) {
                throw $this->invalidOperator($operator);
            }
            if (!is_array($expression['left'] ?? null) || !is_array($expression['right'] ?? null)) {
                throw $this->invalidNode('左右两侧必须是结构化字段或常量');
            }
            $left = $this->normalizeFrontendNode($expression['left']);
            $right = $this->normalizeFrontendNode($expression['right']);
            if (($expression['nullMode'] ?? 'empty') === 'zero' && $operator !== 'date_diff_days') {
                $zero = ['type' => 'literal', 'value_type' => 'decimal', 'value' => '0'];
                $left = ['type' => 'operator', 'operator' => 'coalesce', 'args' => [$left, $zero]];
                $right = ['type' => 'operator', 'operator' => 'coalesce', 'args' => [$right, $zero]];
            } elseif (!in_array(($expression['nullMode'] ?? 'empty'), ['empty', 'zero'], true)) {
                throw $this->invalidNode('空值处理方式不在白名单中');
            }
            return [
                'type' => 'operator',
                'operator' => $operator,
                'args' => [$left, $right],
                '_return_type' => (string)($expression['returnType'] ?? ''),
            ];
        }
        if (isset($expression['type'])) {
            // _return_type 只允许作为根节点的声明；子节点一律由服务端推导。
            $declaredReturnType = (string)($expression['_return_type'] ?? '');
            unset($expression['_return_type']);
            $normalized = $this->normalizeFrontendNode($expression);
            if ($declaredReturnType !== '') {
                $normalized['_return_type'] = $declaredReturnType;
            }
            return $normalized;
        }
        if (!isset($expression['leftField'], $expression['operator'])) {
            throw $this->invalidNode('缺少结构化表达式类型');
        }
        $operator = $this->normalizeOperatorName((string)$expression['operator']);
        if ($operator === '') {
            throw $this->invalidOperator((string)$expression['operator']);
        }
        $left = ['type' => 'field', 'key' => (string)$expression['leftField']];
        if (($expression['rightMode'] ?? 'field') === 'constant') {
            $literalType = (string)($expression['constantType'] ?? 'decimal');
            $right = [
                'type' => 'literal',
                'value_type' => $literalType,
                'value' => $expression['constantValue'] ?? null,
            ];
        } elseif (($expression['rightMode'] ?? 'field') === 'cutoff_date') {
            $right = ['type' => 'context', 'key' => 'query_cutoff_date'];
        } else {
            $right = ['type' => 'field', 'key' => (string)($expression['rightField'] ?? '')];
        }

        if (($expression['nullMode'] ?? 'empty') === 'zero' && $operator !== 'date_diff_days') {
            $zero = ['type' => 'literal', 'value_type' => 'decimal', 'value' => '0'];
            $left = ['type' => 'operator', 'operator' => 'coalesce', 'args' => [$left, $zero]];
            $right = ['type' => 'operator', 'operator' => 'coalesce', 'args' => [$right, $zero]];
        }

        return [
            'type' => 'operator',
            'operator' => $operator,
            'args' => [$left, $right],
            '_return_type' => (string)($expression['returnType'] ?? ''),
        ];
    }

    protected function normalizeFrontendNode(array $node): array
    {
        $type = (string)($node['type'] ?? '');
        if ($type === 'field') {
            $this->assertOnlyKeys($node, ['type', 'key', 'fieldKey', 'fieldId', 'field']);
            $field = $node['field'] ?? null;
            if (is_array($field)) {
                $field = $field['fieldKey'] ?? ($field['fieldId'] ?? ($field['key'] ?? ($field['id'] ?? '')));
            }
            return [
                'type' => 'field',
                'key' => (string)($node['fieldKey']
                    ?? ($node['fieldId'] ?? ($node['key'] ?? ($field ?? '')))),
            ];
        }
        if ($type === 'literal') {
            $this->assertOnlyKeys($node, ['type', 'value', 'valueType', 'value_type']);
            $value = $node['value'] ?? null;
            $valueType = (string)($node['valueType'] ?? ($node['value_type'] ?? ''));
            if ($valueType === '') {
                $valueType = $this->inferLiteralType($value);
            }
            return [
                'type' => 'literal',
                'value_type' => $valueType,
                'value' => $value,
            ];
        }
        if ($type === 'context') {
            $this->assertOnlyKeys($node, ['type', 'key', 'contextKey', 'name']);
            return [
                'type' => 'context',
                'key' => (string)($node['contextKey'] ?? ($node['key'] ?? ($node['name'] ?? ''))),
            ];
        }
        if ($type === 'operator') {
            $operator = $this->normalizeOperatorName((string)($node['operator'] ?? ''));
            if ($operator === 'range_bucket') {
                return $this->normalizeRangeBucketNode($node);
            }
            $this->assertOnlyKeys($node, ['type', 'operator', 'args', '_return_type']);
            $args = $node['args'] ?? null;
            if (!is_array($args) || !$this->isList($args)) {
                throw $this->invalidNode('操作符参数必须是有序数组');
            }
            $normalizedArgs = [];
            foreach ($args as $arg) {
                if (!is_array($arg)) {
                    throw $this->invalidNode('操作符参数必须是结构化节点');
                }
                $normalizedArgs[] = $this->normalizeFrontendNode($arg);
            }
            $normalized = [
                'type' => 'operator',
                'operator' => $operator,
                'args' => $normalizedArgs,
            ];
            // 任何非根节点的类型标记都不可信，不能参与金额中间精度计算。
            return $normalized;
        }
        throw $this->invalidNode('节点类型不在白名单中');
    }

    protected function normalizeRangeBucketNode(array $node): array
    {
        $this->assertOnlyKeys($node, [
            'type', 'operator', 'input', 'thresholds', 'labels',
            'nullLabel', 'null_label', 'defaultLabel', 'default_label', '_return_type',
        ]);
        if (!is_array($node['input'] ?? null)) {
            throw $this->rangeBucketInvalid('range_bucket.input 必须是结构化节点');
        }
        $thresholds = $node['thresholds'] ?? null;
        $labels = $node['labels'] ?? null;
        if (!is_array($thresholds) || !$this->isNonEmptyList($thresholds)
            || !is_array($labels) || !$this->isNonEmptyList($labels)
            || count($thresholds) !== count($labels)
            || count($thresholds) > self::MAX_RANGE_BUCKET_THRESHOLDS) {
            throw $this->rangeBucketInvalid(
                'range_bucket 的 thresholds/labels 必须等长且包含 1 到 '
                . self::MAX_RANGE_BUCKET_THRESHOLDS . ' 项'
            );
        }
        if (array_key_exists('nullLabel', $node) && array_key_exists('null_label', $node)) {
            throw $this->rangeBucketInvalid('nullLabel 不能重复声明');
        }
        if (array_key_exists('defaultLabel', $node) && array_key_exists('default_label', $node)) {
            throw $this->rangeBucketInvalid('defaultLabel 不能重复声明');
        }
        $hasNullLabel = array_key_exists('nullLabel', $node)
            || array_key_exists('null_label', $node);
        $hasDefaultLabel = array_key_exists('defaultLabel', $node)
            || array_key_exists('default_label', $node);
        if (!$hasNullLabel || !$hasDefaultLabel) {
            throw $this->rangeBucketInvalid('nullLabel 和 defaultLabel 都必须明确提供');
        }

        $normalizedThresholds = [];
        foreach ($thresholds as $threshold) {
            $normalizedThresholds[] = $this->normalizeRangeBucketThreshold($threshold);
        }
        $normalizedLabels = [];
        foreach ($labels as $label) {
            $normalizedLabels[] = $this->normalizeRangeBucketLabel($label);
        }

        return [
            'type' => 'operator',
            'operator' => 'range_bucket',
            'input' => $this->normalizeFrontendNode($node['input']),
            'thresholds' => $normalizedThresholds,
            'labels' => $normalizedLabels,
            'null_label' => $this->normalizeRangeBucketLabel(
                $node['nullLabel'] ?? $node['null_label']
            ),
            'default_label' => $this->normalizeRangeBucketLabel(
                $node['defaultLabel'] ?? $node['default_label']
            ),
        ];
    }

    protected function normalizeRangeBucketThreshold($value): string
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !preg_match(
                '/^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,8})?$/D',
                trim((string)$value)
            )) {
            throw $this->rangeBucketInvalid(
                'range_bucket 阈值必须是最多 18 位整数和 8 位小数的静态数值'
            );
        }
        return trim((string)$value);
    }

    protected function normalizeRangeBucketLabel($value): string
    {
        if (!is_string($value)) {
            throw $this->rangeBucketInvalid('range_bucket 标签必须是文本');
        }
        $label = trim($value);
        if ($label === '' || $this->textLength($label) > self::MAX_RANGE_BUCKET_LABEL_LENGTH) {
            throw $this->rangeBucketInvalid(
                'range_bucket 标签不能为空且不能超过 '
                . self::MAX_RANGE_BUCKET_LABEL_LENGTH . ' 个字符'
            );
        }
        if (preg_match('/^[=+@]/', ltrim($label))) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXCEL_FORMULA_FORBIDDEN',
                '分档标签不能使用 Excel 公式。',
                []
            );
        }
        return $label;
    }

    protected function normalizeOperatorName(string $operator): string
    {
        $map = [
            'date_diff' => 'date_diff_days',
            'avg' => 'average',
            'min' => 'minimum',
            'max' => 'maximum',
            'eq' => 'equal',
            'neq' => 'not_equal',
            'gt' => 'greater_than',
            'gte' => 'greater_or_equal',
            'lt' => 'less_than',
            'lte' => 'less_or_equal',
            'in_range' => 'between',
        ];
        $normalized = strtolower(trim($operator));
        return $map[$normalized] ?? $normalized;
    }

    protected function inferLiteralType($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'decimal';
        }
        $text = trim((string)$value);
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,17})$/D', $text)) {
            return 'integer';
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,17})\.[0-9]{1,8}$/D', $text)) {
            return 'decimal';
        }
        if ($this->validDate($text, 'Y-m-d')) {
            return 'date';
        }
        if ($this->validDate($text, 'Y-m-d H:i:s')) {
            return 'datetime';
        }
        return 'text';
    }

    protected function reset(string $pageCode, array $permissions): void
    {
        $this->registry->page($pageCode);
        $this->pageCode = $pageCode;
        $this->permissions = array_values(array_unique(array_map('strval', $permissions)));
        $this->nodes = 0;
        $this->maxDepth = 0;
        $this->complexity = 0;
        $this->aggregateCount = 0;
        $this->references = [];
    }

    /**
     * 在递归归一化前限制节点数、层级和单节点键数，避免畸形 AST 占用 CPU／内存。
     */
    protected function assertNormalizationBudget(array $node, int $depth, int &$nodes): void
    {
        $nodes++;
        if ($nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
                '计算规则过于复杂，请减少条件或计算步骤。',
                ['node_count' => $nodes, 'depth' => $depth]
            );
        }
        if (count($node) > self::MAX_NODE_KEYS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
                '计算规则包含过多节点属性，请重新编辑。',
                ['node_count' => $nodes, 'depth' => $depth]
            );
        }

        $children = [];
        if (($node['type'] ?? '') === 'binary') {
            foreach (['left', 'right'] as $key) {
                if (array_key_exists($key, $node)) {
                    $children[] = $node[$key];
                }
            }
        } elseif (($node['type'] ?? '') === 'operator') {
            if (strtolower(trim((string)($node['operator'] ?? ''))) === 'range_bucket'
                && array_key_exists('input', $node)) {
                $children = [$node['input']];
            } elseif (is_array($node['args'] ?? null)) {
                $children = $node['args'];
            }
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->assertNormalizationBudget($child, $depth + 1, $nodes);
                continue;
            }
            // 非结构化子项也计入预算；后续由正常 AST 校验给出明确错误。
            $nodes++;
            if ($nodes > self::MAX_NODES) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
                    '计算规则过于复杂，请减少条件或计算步骤。',
                    ['node_count' => $nodes, 'depth' => $depth + 1]
                );
            }
        }
    }

    protected function inspect(array $node, int $depth, bool $isRoot): string
    {
        $this->nodes++;
        $this->maxDepth = max($this->maxDepth, $depth);
        if ($this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
                '计算规则过于复杂，请减少条件或计算步骤。',
                ['node_count' => $this->nodes, 'depth' => $depth]
            );
        }

        $type = (string)($node['type'] ?? '');
        switch ($type) {
            case 'field':
                $this->assertOnlyKeys($node, ['type', 'key']);
                $fieldKey = trim((string)($node['key'] ?? ''));
                $field = $this->registry->assertReadable($this->pageCode, $fieldKey, $this->permissions);
                $this->references[$fieldKey] = $field;
                $this->complexity += 1;
                return (string)$field['type'];

            case 'literal':
                $this->assertOnlyKeys($node, ['type', 'value_type', 'value']);
                $this->complexity += 1;
                return $this->inspectLiteral($node);

            case 'context':
                $this->assertOnlyKeys($node, ['type', 'key']);
                if (($node['key'] ?? '') !== 'query_cutoff_date') {
                    throw new UnifiedQueryException(
                        'UNIFIED_QUERY_CONTEXT_NOT_ALLOWED',
                        '该计算上下文不可用，请重新配置规则。',
                        ['key' => $node['key'] ?? '']
                    );
                }
                $this->complexity += 2;
                return 'date';

            case 'operator':
                return $this->inspectOperator($node, $depth, $isRoot);

            default:
                throw $this->invalidNode('只允许字段、常量、查询截止日期和受控操作符');
        }
    }

    protected function inspectLiteral(array $node): string
    {
        $rawType = strtolower(trim((string)($node['value_type'] ?? '')));
        if ($rawType === 'null') {
            if (($node['value'] ?? null) !== null) {
                throw $this->invalidNode('空值常量的值必须为空');
            }
            return 'null';
        }
        try {
            $type = UnifiedQueryPageRegistry::normalizeType($rawType);
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalidNode('常量类型不在白名单中');
        }
        $value = $node['value'] ?? null;
        if ($value === null) {
            throw $this->invalidNode('常量值不能为空');
        }
        if (in_array($type, ['integer', 'decimal', 'amount'], true)) {
            if (!is_int($value) && !is_float($value) && !is_string($value)) {
                throw $this->invalidNode('数值常量格式不正确');
            }
            $text = trim((string)$value);
            if (!preg_match('/^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,8})?$/D', $text)) {
                throw $this->invalidNode('数值常量最多支持 18 位整数和 8 位小数');
            }
            if ($type === 'integer' && strpos($text, '.') !== false) {
                throw $this->invalidNode('整数常量不能包含小数');
            }
        } elseif ($type === 'boolean') {
            if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
                throw $this->invalidNode('是／否常量格式不正确');
            }
        } elseif ($type === 'date') {
            if (!$this->validDate((string)$value, 'Y-m-d')) {
                throw $this->invalidNode('日期常量必须使用 YYYY-MM-DD');
            }
        } elseif ($type === 'datetime') {
            if (!$this->validDate((string)$value, 'Y-m-d H:i:s')) {
                throw $this->invalidNode('时间常量必须使用 YYYY-MM-DD HH:MM:SS');
            }
        } elseif ($type === 'text') {
            $text = (string)$value;
            if ($this->textLength($text) > 255) {
                throw $this->invalidNode('文本常量不能超过 255 个字符');
            }
            if (preg_match('/^[=+@]/', ltrim($text))) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXCEL_FORMULA_FORBIDDEN',
                    '文本常量不能使用 Excel 公式。',
                    []
                );
            }
        }
        return $type;
    }

    protected function inspectOperator(array $node, int $depth, bool $isRoot): string
    {
        $operator = strtolower(trim((string)($node['operator'] ?? '')));
        if ($operator === 'range_bucket') {
            return $this->inspectRangeBucket($node, $depth);
        }
        $this->assertOnlyKeys($node, ['type', 'operator', 'args', '_return_type']);
        $args = $node['args'] ?? null;
        if (!is_array($args) || !$this->isList($args)) {
            throw $this->invalidNode('操作符参数必须是有序数组');
        }

        $arity = [
            'add' => [2, 2],
            'subtract' => [2, 2],
            'multiply' => [2, 2],
            'divide' => [2, 2],
            'date_diff_days' => [2, 2],
            'equal' => [2, 2],
            'not_equal' => [2, 2],
            'greater_than' => [2, 2],
            'greater_or_equal' => [2, 2],
            'less_than' => [2, 2],
            'less_or_equal' => [2, 2],
            'between' => [3, 3],
            'if' => [3, 3],
            'is_null' => [1, 1],
            'coalesce' => [2, 5],
            // value, upper_1, result_1, ..., upper_n, result_n, fallback
            'bucket' => [4, 26],
            'sum' => [1, 1],
            'average' => [1, 1],
            'minimum' => [1, 1],
            'maximum' => [1, 1],
            'count' => [1, 1],
        ];
        if (!isset($arity[$operator])) {
            throw $this->invalidOperator($operator);
        }
        $argCount = count($args);
        if ($argCount < $arity[$operator][0] || $argCount > $arity[$operator][1]) {
            throw $this->invalidNode('操作符参数数量不正确');
        }
        if ($operator === 'bucket' && $argCount % 2 !== 0) {
            throw $this->invalidNode('分档参数必须由值、上界/结果对和兜底结果组成');
        }

        $aggregate = in_array($operator, ['sum', 'average', 'minimum', 'maximum', 'count'], true);
        if ($aggregate) {
            if (!$isRoot) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_AGGREGATE_POSITION_INVALID',
                    '汇总计算只能作为整条规则，不能嵌套在其他计算中。',
                    ['operator' => $operator]
                );
            }
            $this->aggregateCount++;
            $this->complexity += 25;
        } else {
            $this->complexity += $operator === 'bucket'
                ? 8 + (int)(($argCount - 2) / 2)
                : (in_array($operator, ['if', 'between'], true) ? 8 : 3);
        }

        $types = [];
        foreach ($args as $arg) {
            if (!is_array($arg)) {
                throw $this->invalidNode('操作符参数必须是结构化节点');
            }
            $types[] = $this->inspect($arg, $depth + 1, false);
        }

        if (in_array($operator, ['add', 'subtract'], true)) {
            $this->assertNumericTypes($types, $operator);
            if ($types[0] === 'amount' || $types[1] === 'amount') {
                return 'amount';
            }
            return $types[0] === 'integer' && $types[1] === 'integer' ? 'integer' : 'decimal';
        }
        if ($operator === 'multiply') {
            $this->assertNumericTypes($types, $operator);
            if ($types[0] === 'amount' && $types[1] === 'amount') {
                throw $this->typeError($operator, $types, '金额不能直接与金额相乘');
            }
            return in_array('amount', $types, true) ? 'amount'
                : (($types[0] === 'integer' && $types[1] === 'integer') ? 'integer' : 'decimal');
        }
        if ($operator === 'divide') {
            $this->assertNumericTypes($types, $operator);
            if ($types[1] === 'amount') {
                throw $this->typeError($operator, $types, '除数不能是金额字段');
            }
            if ($this->isLiteralZero($args[1])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_DIVIDE_BY_ZERO',
                    '除数不能为 0，请调整计算规则。',
                    []
                );
            }
            return $types[0] === 'amount' ? 'amount' : 'decimal';
        }
        if ($operator === 'date_diff_days') {
            if (!$this->dateLike($types[0]) || !$this->dateLike($types[1])) {
                throw $this->typeError($operator, $types, '日期差两侧都必须是日期');
            }
            return 'integer';
        }
        if (in_array($operator, [
            'equal', 'not_equal', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal',
        ], true)) {
            if (!$this->typesCompatible($types[0], $types[1])) {
                throw $this->typeError($operator, $types, '比较两侧类型不一致');
            }
            return 'boolean';
        }
        if ($operator === 'between') {
            if (!$this->typesCompatible($types[0], $types[1])
                || !$this->typesCompatible($types[0], $types[2])) {
                throw $this->typeError($operator, $types, '区间值与上下限类型不一致');
            }
            return 'boolean';
        }
        if ($operator === 'if') {
            if ($types[0] !== 'boolean') {
                throw $this->typeError($operator, $types, '如果条件必须返回是／否');
            }
            return $this->unifyTypes($types[1], $types[2], $operator);
        }
        if ($operator === 'is_null') {
            return 'boolean';
        }
        if ($operator === 'coalesce') {
            $result = 'null';
            foreach ($types as $type) {
                $result = $this->unifyTypes($result, $type, $operator);
            }
            return $result;
        }
        if ($operator === 'bucket') {
            if (!in_array($types[0], ['integer', 'decimal', 'amount', 'date', 'datetime'], true)) {
                throw $this->typeError($operator, $types, '分档值只支持数值或日期');
            }
            $resultType = 'null';
            $previousBoundary = null;
            for ($index = 1; $index < $argCount - 1; $index += 2) {
                if (($args[$index]['type'] ?? '') !== 'literal'
                    || !$this->typesCompatible($types[0], $types[$index])) {
                    throw $this->typeError($operator, $types, '分档上界必须是与分档值同类型的常量');
                }
                if ($previousBoundary !== null
                    && $this->compareBucketBoundary(
                        $previousBoundary,
                        $args[$index],
                        $types[0]
                    ) >= 0) {
                    throw new UnifiedQueryException(
                        'UNIFIED_QUERY_BUCKET_BOUNDARIES_INVALID',
                        '分档上界必须严格递增且不能重叠。',
                        []
                    );
                }
                $previousBoundary = $args[$index];
                $resultType = $this->unifyTypes(
                    $resultType,
                    $types[$index + 1],
                    $operator
                );
            }
            return $this->unifyTypes($resultType, $types[$argCount - 1], $operator);
        }
        if ($operator === 'count') {
            return 'integer';
        }
        if (in_array($operator, ['sum', 'average'], true)) {
            $this->assertNumericTypes($types, $operator);
            if ($operator === 'average' && $types[0] !== 'amount') {
                return 'decimal';
            }
            return $types[0];
        }
        if (in_array($operator, ['minimum', 'maximum'], true)) {
            if (!in_array($types[0], ['integer', 'decimal', 'amount', 'date', 'datetime'], true)) {
                throw $this->typeError($operator, $types, '最小值／最大值仅支持数值或日期');
            }
            return $types[0];
        }

        throw $this->invalidOperator($operator);
    }

    protected function inspectRangeBucket(array $node, int $depth): string
    {
        $this->assertOnlyKeys($node, [
            'type', 'operator', 'input', 'thresholds', 'labels',
            'null_label', 'default_label', '_return_type',
        ]);
        if (!is_array($node['input'] ?? null)) {
            throw $this->rangeBucketInvalid('range_bucket.input 必须是结构化节点');
        }
        $thresholds = $node['thresholds'] ?? null;
        $labels = $node['labels'] ?? null;
        if (!is_array($thresholds) || !$this->isNonEmptyList($thresholds)
            || !is_array($labels) || !$this->isNonEmptyList($labels)
            || count($thresholds) !== count($labels)
            || count($thresholds) > self::MAX_RANGE_BUCKET_THRESHOLDS) {
            throw $this->rangeBucketInvalid('range_bucket 档位数量不正确');
        }

        $inputType = $this->inspect($node['input'], $depth + 1, false);
        if (!in_array($inputType, ['integer', 'decimal'], true)) {
            throw $this->typeError(
                'range_bucket',
                [$inputType],
                'range_bucket 输入只支持整数或普通数字'
            );
        }
        $this->complexity += 8 + count($thresholds);

        $previous = null;
        foreach ($thresholds as $threshold) {
            $threshold = $this->normalizeRangeBucketThreshold($threshold);
            if ($previous !== null) {
                if (!function_exists('bccomp')) {
                    throw new \RuntimeException('统一查询金额计算需要 ext-bcmath');
                }
                if (bccomp($previous, $threshold, 8) >= 0) {
                    throw new UnifiedQueryException(
                        'UNIFIED_QUERY_RANGE_BUCKET_THRESHOLDS_INVALID',
                        '分档阈值必须严格递增且不能重复。',
                        []
                    );
                }
            }
            $previous = $threshold;
        }

        $seenLabels = [];
        foreach (array_merge(
            $labels,
            [$node['null_label'] ?? null, $node['default_label'] ?? null]
        ) as $label) {
            $label = $this->normalizeRangeBucketLabel($label);
            $identity = hash('sha256', $label);
            if (isset($seenLabels[$identity])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_RANGE_BUCKET_LABELS_INVALID',
                    '分档标签不能重复。',
                    ['label' => $label]
                );
            }
            $seenLabels[$identity] = true;
        }
        return 'text';
    }

    protected function assertNumericTypes(array $types, string $operator): void
    {
        foreach ($types as $type) {
            if (!in_array($type, ['integer', 'decimal', 'amount'], true)) {
                throw $this->typeError($operator, $types, '该计算只支持数值字段');
            }
        }
    }

    protected function unifyTypes(string $left, string $right, string $operator): string
    {
        if ($left === 'null') {
            return $right;
        }
        if ($right === 'null') {
            return $left;
        }
        if (!$this->typesCompatible($left, $right)) {
            throw $this->typeError($operator, [$left, $right], '分支结果类型不一致');
        }
        if ($left === 'amount' || $right === 'amount') {
            return 'amount';
        }
        if (($left === 'integer' && $right === 'decimal') || ($left === 'decimal' && $right === 'integer')) {
            return 'decimal';
        }
        return $left;
    }

    protected function typesCompatible(string $left, string $right): bool
    {
        if ($left === $right || $left === 'null' || $right === 'null') {
            return true;
        }
        return in_array($left, ['integer', 'decimal', 'amount'], true)
            && in_array($right, ['integer', 'decimal', 'amount'], true);
    }

    protected function returnTypeCompatible(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }
        // 整数计算可以安全扩展为普通数字；金额、日期和文本必须精确匹配。
        return $expected === 'decimal' && $actual === 'integer';
    }

    protected function dateLike(string $type): bool
    {
        return $type === 'date' || $type === 'datetime';
    }

    protected function isLiteralZero(array $node): bool
    {
        if (($node['type'] ?? '') !== 'literal') {
            return false;
        }
        $type = (string)($node['value_type'] ?? '');
        if (!in_array($type, ['integer', 'decimal', 'number', 'amount', 'money'], true)) {
            return false;
        }
        return preg_match('/^-?0+(?:\.0+)?$/D', trim((string)($node['value'] ?? ''))) === 1;
    }

    protected function compareBucketBoundary(array $left, array $right, string $valueType): int
    {
        $leftValue = (string)($left['value'] ?? '');
        $rightValue = (string)($right['value'] ?? '');
        if (in_array($valueType, ['integer', 'decimal', 'amount'], true)) {
            if (!function_exists('bccomp')) {
                throw new \RuntimeException('统一查询金额计算需要 ext-bcmath');
            }
            return bccomp($leftValue, $rightValue, 8);
        }
        return strcmp($leftValue, $rightValue);
    }

    protected function assertOnlyKeys(array $node, array $allowed): void
    {
        foreach (array_keys($node) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPRESSION_KEY_NOT_ALLOWED',
                    '计算规则包含不支持的配置，请重新保存。',
                    ['key' => $key]
                );
            }
        }
    }

    protected function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    protected function isNonEmptyList(array $value): bool
    {
        return $value !== [] && $this->isList($value);
    }

    protected function validDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    protected function invalidNode(string $reason): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_EXPRESSION_INVALID',
            '计算规则不合法，请使用页面提供的计算方式。',
            ['reason' => $reason]
        );
    }

    protected function invalidOperator(string $operator): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_OPERATOR_NOT_ALLOWED',
            '该计算方式不受支持，请重新选择。',
            ['operator' => $operator]
        );
    }

    protected function rangeBucketInvalid(string $reason): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_RANGE_BUCKET_INVALID',
            '分档规则不合法，请重新设置档位。',
            ['reason' => $reason]
        );
    }

    protected function typeError(string $operator, array $types, string $reason): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
            '所选字段类型不能这样计算，请调整规则。',
            ['operator' => $operator, 'types' => $types, 'reason' => $reason]
        );
    }

    protected function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

<?php

/**
 * 统一查询受控 range_bucket 合同。
 */
require '/var/www/html/vendor/autoload.php';
require '/tests/lib/_lib.php';

use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;

date_default_timezone_set('Asia/Shanghai');

function uqRangeCode(callable $callback): string
{
    try {
        $callback();
    } catch (UnifiedQueryException $exception) {
        return $exception->getErrorCode();
    } catch (\Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function uqRangeBase(array $overrides = []): array
{
    return array_merge([
        'type' => 'operator',
        'operator' => 'range_bucket',
        'input' => ['type' => 'field', 'key' => 'stock_days'],
        'thresholds' => ['-1', '30', '45', '60', '90', '180', '365', '730', '1095'],
        'labels' => [
            '已过期',
            '0-30天',
            '31-45天',
            '46-60天',
            '61-90天',
            '91-180天',
            '1年内',
            '1-2年',
            '2-3年',
        ],
        'nullLabel' => '到期日未知',
        'defaultLabel' => '3年以上',
        '_return_type' => 'text',
    ], $overrides);
}

$registry = new UnifiedQueryPageRegistry([
    ['code' => 'inventory_amount', 'name' => '库存金额', 'aliases' => []],
]);
$registry->registerPage('range_bucket_test', '分档合同页', [
    UnifiedQueryPageRegistry::field('stock_days', '库存天数', 'integer', true, false),
    UnifiedQueryPageRegistry::field('decimal_days', '小数天数', 'decimal', false, false),
    UnifiedQueryPageRegistry::field('product_name', '商品名称', 'text', true, true),
    UnifiedQueryPageRegistry::field('expiry_date', '到期日期', 'date', false, false),
    UnifiedQueryPageRegistry::field('inventory_amount', '库存金额', 'amount', false, false),
], 'row_id', [
    'keywordFields' => ['product_name'],
]);
$registry->freeze();
$validator = new StructuredExpressionValidator($registry);
$evaluator = new StructuredExpressionEvaluator();
$execution = new UnifiedQueryExecutionServices($registry, $validator, $evaluator);

try {
    $validated = $validator->validate(
        'range_bucket_test',
        uqRangeBase(['thresholds' => [' -1 ', 30, '45.0', 60, 90, 180, 365, 730, 1095]]),
        'text'
    );
    $expression = $validated['expression'];
    $cases = [
        'null' => null,
        'negative' => -1,
        'zero' => 0,
        'thirty' => 30,
        'thirty_one' => 31,
        'forty_five' => 45,
        'forty_six' => 46,
        'sixty' => 60,
        'sixty_one' => 61,
        'ninety' => 90,
        'ninety_one' => 91,
        'one_eighty' => 180,
        'one_eighty_one' => 181,
        'three_sixty_five' => 365,
        'three_sixty_six' => 366,
        'seven_thirty' => 730,
        'seven_thirty_one' => 731,
        'ten_ninety_five' => 1095,
        'ten_ninety_six' => 1096,
    ];
    $actual = [];
    foreach ($cases as $key => $value) {
        $actual[$key] = $evaluator->evaluate(
            $expression,
            ['stock_days' => $value],
            ['query_cutoff_date' => '2026-07-28']
        );
    }
    $expected = [
        'null' => '到期日未知',
        'negative' => '已过期',
        'zero' => '0-30天',
        'thirty' => '0-30天',
        'thirty_one' => '31-45天',
        'forty_five' => '31-45天',
        'forty_six' => '46-60天',
        'sixty' => '46-60天',
        'sixty_one' => '61-90天',
        'ninety' => '61-90天',
        'ninety_one' => '91-180天',
        'one_eighty' => '91-180天',
        'one_eighty_one' => '1年内',
        'three_sixty_five' => '1年内',
        'three_sixty_six' => '1-2年',
        'seven_thirty' => '1-2年',
        'seven_thirty_one' => '2-3年',
        'ten_ninety_five' => '2-3年',
        'ten_ninety_six' => '3年以上',
    ];
    ok(
        'range_bucket 九个包含上界阈值形成十档且 null 使用独立标签',
        ($expression['operator'] ?? '') === 'range_bucket'
            && ($expression['thresholds'] ?? null) === [
                '-1', '30', '45.0', '60', '90', '180', '365', '730', '1095',
            ]
            && ($expression['labels'] ?? null) === [
                '已过期', '0-30天', '31-45天', '46-60天', '61-90天',
                '91-180天', '1年内', '1-2年', '2-3年',
            ]
            && ($expression['null_label'] ?? '') === '到期日未知'
            && ($expression['default_label'] ?? '') === '3年以上'
            && !isset($expression['nullLabel'], $expression['defaultLabel'])
            && ($validated['referenced_fields'] ?? []) === ['stock_days']
            && (int)($validated['depth'] ?? 0) <= StructuredExpressionValidator::MAX_DEPTH
            && StructuredExpressionValidator::MAX_DEPTH === 8
            && $actual === $expected,
        json_encode(compact('expression', 'actual', 'expected'), JSON_UNESCAPED_UNICODE),
        'UQ-RANGE-01'
    );

    $dateDiffValidated = $validator->validate(
        'range_bucket_test',
        uqRangeBase([
            'input' => [
                'type' => 'operator',
                'operator' => 'date_diff_days',
                'args' => [
                    ['type' => 'field', 'key' => 'expiry_date'],
                    ['type' => 'context', 'key' => 'query_cutoff_date'],
                ],
            ],
        ]),
        'text'
    );
    $dateDiffExpression = $dateDiffValidated['expression'];
    $dateDiffValues = [
        'null' => $evaluator->evaluate(
            $dateDiffExpression,
            ['expiry_date' => null],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'future_one_day' => $evaluator->evaluate(
            $dateDiffExpression,
            ['expiry_date' => '2026-07-29'],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'same_day' => $evaluator->evaluate(
            $dateDiffExpression,
            ['expiry_date' => '2026-07-28'],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'past_one_day' => $evaluator->evaluate(
            $dateDiffExpression,
            ['expiry_date' => '2026-07-27'],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'future_thirty_one_days' => $evaluator->evaluate(
            $dateDiffExpression,
            ['expiry_date' => '2026-08-28'],
            ['query_cutoff_date' => '2026-07-28']
        ),
    ];
    ok(
        'range_bucket 接受 date_diff_days 输入并由后端截止日决定边界与空值档',
        ($dateDiffValidated['referenced_fields'] ?? []) === ['expiry_date']
            && ($dateDiffExpression['input']['operator'] ?? '') === 'date_diff_days'
            && ($dateDiffExpression['input']['args'][0]['key'] ?? '') === 'expiry_date'
            && ($dateDiffExpression['input']['args'][1]['key'] ?? '')
                === 'query_cutoff_date'
            && $dateDiffValues === [
                'null' => '到期日未知',
                'future_one_day' => '0-30天',
                'same_day' => '0-30天',
                'past_one_day' => '已过期',
                'future_thirty_one_days' => '31-45天',
            ],
        json_encode(compact('dateDiffExpression', 'dateDiffValues'), JSON_UNESCAPED_UNICODE),
        'UQ-RANGE-05'
    );

    $textInput = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'input' => ['type' => 'field', 'key' => 'product_name'],
        ]), 'text');
    });
    $dateInput = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'input' => ['type' => 'field', 'key' => 'expiry_date'],
        ]), 'text');
    });
    $amountInput = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'input' => ['type' => 'field', 'key' => 'inventory_amount'],
        ]), 'text');
    });
    $scalarThresholds = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => '0,31',
        ]), 'text');
    });
    $associativeLabels = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'labels' => ['first' => '<0'],
            'thresholds' => ['0'],
        ]), 'text');
    });
    $emptyRanges = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => [],
            'labels' => [],
        ]), 'text');
    });
    $mismatchedRanges = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['0', '31'],
            'labels' => ['<0'],
        ]), 'text');
    });
    $tooManyRanges = uqRangeCode(function () use ($validator): void {
        $thresholds = [];
        $labels = [];
        for ($index = 1; $index <= 17; $index++) {
            $thresholds[] = (string)$index;
            $labels[] = '档' . $index;
        }
        $validator->validate('range_bucket_test', uqRangeBase(compact('thresholds', 'labels')), 'text');
    });
    $unknownKey = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'sql' => 'select 1',
        ]), 'text');
    });
    $dynamicThreshold = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => [[
                'type' => 'field',
                'key' => 'decimal_days',
            ]],
            'labels' => ['动态'],
        ]), 'text');
    });
    $exponentThreshold = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['1e3'],
            'labels' => ['指数'],
        ]), 'text');
    });
    $wideThreshold = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['1234567890123456789'],
            'labels' => ['过宽'],
        ]), 'text');
    });
    $deepInput = ['type' => 'field', 'key' => 'stock_days'];
    for ($depth = 0; $depth < StructuredExpressionValidator::MAX_DEPTH; $depth++) {
        $deepInput = [
            'type' => 'operator',
            'operator' => 'coalesce',
            'args' => [
                $deepInput,
                ['type' => 'literal', 'value_type' => 'integer', 'value' => 0],
            ],
        ];
    }
    $deepInputCode = uqRangeCode(function () use ($validator, $deepInput): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'input' => $deepInput,
        ]), 'text');
    });
    ok(
        'range_bucket 输入类型、数组形状、数量、静态阈值和深度均受控',
        $textInput === 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID'
            && $dateInput === 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID'
            && $amountInput === 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID'
            && $scalarThresholds === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $associativeLabels === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $emptyRanges === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $mismatchedRanges === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $tooManyRanges === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $unknownKey === 'UNIFIED_QUERY_EXPRESSION_KEY_NOT_ALLOWED'
            && $dynamicThreshold === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $exponentThreshold === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $wideThreshold === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $deepInputCode === 'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
        json_encode(compact(
            'textInput',
            'dateInput',
            'amountInput',
            'scalarThresholds',
            'associativeLabels',
            'emptyRanges',
            'mismatchedRanges',
            'tooManyRanges',
            'unknownKey',
            'dynamicThreshold',
            'exponentThreshold',
            'wideThreshold',
            'deepInputCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-RANGE-02'
    );

    $duplicateThreshold = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['0', '0'],
            'labels' => ['负数', '零'],
        ]), 'text');
    });
    $descendingThreshold = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['31', '0'],
            'labels' => ['前档', '后档'],
        ]), 'text');
    });
    $duplicateLabels = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['0', '31'],
            'labels' => ['重复', '重复'],
        ]), 'text');
    });
    $emptyLabel = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['0'],
            'labels' => [''],
        ]), 'text');
    });
    $longLabel = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['0'],
            'labels' => [str_repeat('长', 65)],
        ]), 'text');
    });
    $formulaLabel = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'thresholds' => ['0'],
            'labels' => ['=HYPERLINK("https://invalid")'],
        ]), 'text');
    });
    $emptyNullLabel = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'nullLabel' => '',
        ]), 'text');
    });
    $formulaDefaultLabel = uqRangeCode(function () use ($validator): void {
        $validator->validate('range_bucket_test', uqRangeBase([
            'defaultLabel' => '+1+1',
        ]), 'text');
    });
    ok(
        'range_bucket 阈值严格递增且所有标签受长度、公式和重复约束',
        $duplicateThreshold === 'UNIFIED_QUERY_RANGE_BUCKET_THRESHOLDS_INVALID'
            && $descendingThreshold === 'UNIFIED_QUERY_RANGE_BUCKET_THRESHOLDS_INVALID'
            && $duplicateLabels === 'UNIFIED_QUERY_RANGE_BUCKET_LABELS_INVALID'
            && $emptyLabel === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $longLabel === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $formulaLabel === 'UNIFIED_QUERY_EXCEL_FORMULA_FORBIDDEN'
            && $emptyNullLabel === 'UNIFIED_QUERY_RANGE_BUCKET_INVALID'
            && $formulaDefaultLabel === 'UNIFIED_QUERY_EXCEL_FORMULA_FORBIDDEN',
        json_encode(compact(
            'duplicateThreshold',
            'descendingThreshold',
            'duplicateLabels',
            'emptyLabel',
            'longLabel',
            'formulaLabel',
            'emptyNullLabel',
            'formulaDefaultLabel'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-RANGE-03'
    );

    $fieldKey = 'cf_eeeeeeeeeeeeeeeeeeeeeeee';
    $definition = [
        'field_key' => $fieldKey,
        'name' => '剩余保质期分档',
        'return_type' => 'text',
        'page_code' => 'range_bucket_test',
        'version' => 1,
        'status' => 'active',
        'expression' => $expression,
    ];
    $rows = [
        ['row_id' => 1, 'product_name' => '空值批次', 'stock_days' => null],
        ['row_id' => 2, 'product_name' => '三十一天', 'stock_days' => 31],
        ['row_id' => 3, 'product_name' => '四十五天', 'stock_days' => 45],
        ['row_id' => 4, 'product_name' => '四十六天', 'stock_days' => 46],
    ];
    $result = $execution->execute(
        'range_bucket_test',
        $rows,
        [$definition],
        [
            'page' => 1,
            'pageSize' => 20,
            'filters' => [[
                'field' => $fieldKey,
                'operator' => 'equal',
                'value' => '31-45天',
            ]],
            'groupBy' => [$fieldKey],
            'export' => [
                'scope' => 'query',
                'fields' => ['product_name', $fieldKey],
            ],
        ],
        [
            'permissions' => [],
            'query_cutoff_date' => '2026-07-28',
            'data_as_of' => 1785258000,
        ],
        function (): bool {
            return true;
        }
    );
    $listNames = array_column($result['rows'] ?? [], 'product_name');
    $listBuckets = array_column($result['rows'] ?? [], $fieldKey);
    $exportNames = array_column($result['exportRows'] ?? [], 'product_name');
    $exportBuckets = array_column($result['exportRows'] ?? [], $fieldKey);
    $groupCount = array_sum(array_column($result['groups'] ?? [], 'count'));
    ok(
        'range_bucket 派生值在列表筛选、分页总数、分组和导出中保持一致',
        $listNames === ['三十一天', '四十五天']
            && $listBuckets === ['31-45天', '31-45天']
            && $exportNames === $listNames
            && $exportBuckets === $listBuckets
            && (int)($result['pagination']['total'] ?? 0) === 2
            && $groupCount === 2,
        json_encode([
            'rows' => $result['rows'] ?? [],
            'groups' => $result['groups'] ?? [],
            'exportRows' => $result['exportRows'] ?? [],
            'pagination' => $result['pagination'] ?? [],
        ], JSON_UNESCAPED_UNICODE),
        'UQ-RANGE-04'
    );
} catch (\Throwable $throwable) {
    ok(
        '统一查询 range_bucket 合同未发生未捕获异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . "\n" . $throwable->getTraceAsString()
    );
}

finish('unified-query-range-bucket-contract');

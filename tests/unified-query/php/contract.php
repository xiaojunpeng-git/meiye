<?php

/**
 * 统一查询纯合同门禁。
 *
 * 不替换生产实现；直接执行白名单 registry、AST validator/evaluator 和统一执行器。
 */
require '/var/www/html/vendor/autoload.php';
require '/tests/lib/_lib.php';

use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;

date_default_timezone_set('Asia/Shanghai');

function uqSection(string $title): void
{
    echo "\n== {$title} ==\n";
}

function uqExceptionCode(callable $callable): string
{
    try {
        $callable();
    } catch (UnifiedQueryException $exception) {
        return $exception->getErrorCode();
    } catch (\Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function uqMemberRegistry(): UnifiedQueryPageRegistry
{
    return UnifiedQueryPageRegistry::withDefaults([
        ['code' => 'account_balance', 'name' => '账户余额', 'aliases' => ['会员余额']],
        ['code' => 'debt_amount', 'name' => '欠款金额', 'aliases' => []],
        ['code' => 'total_consumption_amount', 'name' => '总消费金额', 'aliases' => []],
        ['code' => 'visit_count', 'name' => '到店次数', 'aliases' => []],
    ]);
}

function uqLiteral(string $type, $value): array
{
    return ['type' => 'literal', 'value_type' => $type, 'value' => $value];
}

function uqField(string $key): array
{
    return ['type' => 'field', 'key' => $key];
}

class UqCountingEvaluator extends StructuredExpressionEvaluator
{
    /** @var int */
    public $calls = 0;

    /** @var int */
    public $aggregateCalls = 0;

    public function evaluate(array $expression, array $row, array $context, array $groupRows = [])
    {
        $this->calls++;
        if ($groupRows) {
            $this->aggregateCalls++;
        }
        return parent::evaluate($expression, $row, $context, $groupRows);
    }
}

$registry = uqMemberRegistry();
$validator = new StructuredExpressionValidator($registry);
$evaluator = new StructuredExpressionEvaluator();
$execution = new UnifiedQueryExecutionServices($registry, $validator, $evaluator);

try {
    uqSection('AST allow-list and executable text rejection');
    $validatedAmount = $validator->validate('member_list', [
        'type' => 'binary',
        'operator' => 'divide',
        'left' => ['type' => 'field', 'fieldKey' => 'account_balance'],
        'right' => ['type' => 'literal', 'valueType' => 'decimal', 'value' => '3'],
        'nullMode' => 'empty',
        'returnType' => 'amount',
    ], 'amount');
    ok(
        '前端结构化二元规则被归一为固定 AST',
        ($validatedAmount['expression']['operator'] ?? '') === 'divide'
            && ($validatedAmount['return_type'] ?? '') === 'amount'
            && ($validatedAmount['referenced_fields'] ?? []) === ['account_balance'],
        json_encode($validatedAmount, JSON_UNESCAPED_UNICODE),
        'UQ-AST-01'
    );

    $unknownKeyCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'field',
            'key' => 'account_balance',
            'sql' => 'select 1',
        ], 'amount');
    });
    $formulaCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', uqLiteral('text', '=HYPERLINK("https://invalid")'), 'text');
    });
    $uiSqlCode = uqExceptionCode(function () use ($execution): void {
        $execution->normalizeUiQuery('member_list', ['sql' => 'select * from user']);
    });
    ok(
        'SQL 字段与 Excel 公式文本均被后端拒绝',
        $unknownKeyCode === 'UNIFIED_QUERY_EXPRESSION_KEY_NOT_ALLOWED'
            && $formulaCode === 'UNIFIED_QUERY_EXCEL_FORMULA_FORBIDDEN'
            && $uiSqlCode === 'UNIFIED_QUERY_REQUEST_INVALID',
        json_encode([$unknownKeyCode, $formulaCode, $uiSqlCode], JSON_UNESCAPED_UNICODE),
        'UQ-AST-02'
    );

    uqSection('AST type, permission, complexity and aggregate position');
    $typeCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'add',
            'args' => [uqField('member_name'), uqLiteral('integer', 1)],
        ], 'decimal');
    });
    $nestedAggregateCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'add',
            'args' => [[
                'type' => 'operator',
                'operator' => 'sum',
                'args' => [uqField('account_balance')],
            ], uqLiteral('amount', '1')],
        ], 'amount');
    });
    $deep = uqLiteral('integer', 1);
    for ($i = 0; $i < 10; $i++) {
        $deep = [
            'type' => 'operator',
            'operator' => 'coalesce',
            'args' => [$deep, uqLiteral('integer', 0)],
        ];
    }
    $complexCode = uqExceptionCode(function () use ($validator, $deep): void {
        $validator->validate('member_list', $deep, 'integer');
    });

    $secureRegistry = new UnifiedQueryPageRegistry([
        ['code' => 'secure_metric', 'name' => '受限指标', 'aliases' => []],
    ]);
    $secureRegistry->registerPage('secure_page', '受限页', [
        UnifiedQueryPageRegistry::field(
            'secret_amount',
            '受限金额',
            'amount',
            true,
            false,
            [],
            'secret.read'
        ),
    ], 'row_id');
    $secureRegistry->freeze();
    $secureValidator = new StructuredExpressionValidator($secureRegistry);
    $permissionCode = uqExceptionCode(function () use ($secureValidator): void {
        $secureValidator->validate('secure_page', uqField('secret_amount'), 'amount', []);
    });
    ok(
        '类型、嵌套聚合、复杂度和字段权限均 fail-closed',
        $typeCode === 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID'
            && $nestedAggregateCode === 'UNIFIED_QUERY_AGGREGATE_POSITION_INVALID'
            && $complexCode === 'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX'
            && $permissionCode === 'UNIFIED_QUERY_FIELD_FORBIDDEN',
        json_encode([$typeCode, $nestedAggregateCode, $complexCode, $permissionCode], JSON_UNESCAPED_UNICODE),
        'UQ-AST-03'
    );

    uqSection('root precision and untrusted AST metadata');
    $nestedTypeAst = [
        'type' => 'operator',
        'operator' => 'add',
        'args' => [
            uqField('account_balance'),
            [
                'type' => 'operator',
                'operator' => 'divide',
                'args' => [uqLiteral('decimal', '1'), uqLiteral('decimal', '2')],
                '_return_type' => 'integer',
            ],
        ],
        '_return_type' => 'amount',
    ];
    $normalizedNestedType = $validator->validate('member_list', $nestedTypeAst, 'amount');
    $legacyNestedTypeValue = $evaluator->evaluate(
        $nestedTypeAst,
        ['account_balance' => '10.00'],
        ['query_cutoff_date' => '2026-07-28']
    );
    $rootPrecisionExpressions = [
        'literal' => $validator->validate(
            'member_list',
            array_merge(uqLiteral('amount', '1.005'), ['_return_type' => 'amount']),
            'amount'
        )['expression'],
        'conditional' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'if',
            'args' => [
                uqLiteral('boolean', false),
                uqField('account_balance'),
                uqLiteral('amount', '1.005'),
            ],
            '_return_type' => 'amount',
        ], 'amount')['expression'],
        'coalesce' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'coalesce',
            'args' => [uqLiteral('null', null), uqLiteral('amount', '1.005')],
            '_return_type' => 'amount',
        ], 'amount')['expression'],
        'bucket' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'bucket',
            'args' => [
                uqLiteral('integer', 5),
                uqLiteral('integer', 10),
                uqLiteral('amount', '1.005'),
                uqLiteral('amount', '2.005'),
            ],
            '_return_type' => 'amount',
        ], 'amount')['expression'],
    ];
    $rootPrecisionValues = [];
    foreach ($rootPrecisionExpressions as $name => $expression) {
        $rootPrecisionValues[$name] = $evaluator->evaluate(
            $expression,
            ['account_balance' => '10.00'],
            ['query_cutoff_date' => '2026-07-28']
        );
    }
    $hiddenStableKeyCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', uqField('member_id'), 'integer');
    });
    $wideAst = [
        'type' => 'operator',
        'operator' => 'coalesce',
        'args' => array_fill(0, StructuredExpressionValidator::MAX_NODES + 1, uqLiteral('integer', 1)),
    ];
    $normalizationBudgetCode = uqExceptionCode(function () use ($validator, $wideAst): void {
        $validator->validate('member_list', $wideAst, 'integer');
    });
    ok(
        '嵌套类型元数据不能改写金额精度，根结果统一规范且隐藏稳定键/超宽 AST 被拒绝',
        !isset($normalizedNestedType['expression']['args'][1]['_return_type'])
            && $evaluator->evaluate(
                $normalizedNestedType['expression'],
                ['account_balance' => '10.00'],
                ['query_cutoff_date' => '2026-07-28']
            ) === '10.50'
            && $legacyNestedTypeValue === '10.50'
            && $rootPrecisionValues === [
                'literal' => '1.01',
                'conditional' => '1.01',
                'coalesce' => '1.01',
                'bucket' => '1.01',
            ]
            && $hiddenStableKeyCode === 'UNIFIED_QUERY_FIELD_FORBIDDEN'
            && $normalizationBudgetCode === 'UNIFIED_QUERY_EXPRESSION_TOO_COMPLEX',
        json_encode(compact(
            'normalizedNestedType',
            'legacyNestedTypeValue',
            'rootPrecisionValues',
            'hiddenStableKeyCode',
            'normalizationBudgetCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-AST-09'
    );

    $missingDictionary = UnifiedQueryPageRegistry::withDefaults();
    $missingDictionaryCode = uqExceptionCode(function () use ($missingDictionary): void {
        $missingDictionary->assertCustomIdentityAllowed('cf_aaaaaaaaaaaaaaaaaaaa', '普通字段');
    });
    $metricNameCode = uqExceptionCode(function () use ($registry): void {
        $registry->assertCustomIdentityAllowed('cf_bbbbbbbbbbbbbbbbbbbb', '账户余额');
    });
    $metricAliasCode = uqExceptionCode(function () use ($registry): void {
        $registry->assertAliasAllowed('member_list', 'phone', '总消费金额');
    });
    ok(
        '指标字典缺失或系统指标冒名时禁止新增和改名',
        $missingDictionaryCode === 'UNIFIED_QUERY_METRIC_DICTIONARY_REQUIRED'
            && $metricNameCode === 'UNIFIED_QUERY_SYSTEM_METRIC_RESERVED'
            && $metricAliasCode === 'UNIFIED_QUERY_SYSTEM_METRIC_RESERVED',
        json_encode([$missingDictionaryCode, $metricNameCode, $metricAliasCode], JSON_UNESCAPED_UNICODE),
        'UQ-AST-04'
    );

    uqSection('comparison, range, conditional and null operators');
    $operatorExpressions = [
        'comparison' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'gte',
            'args' => [uqField('account_balance'), uqLiteral('amount', '10.00')],
        ], 'boolean')['expression'],
        'between' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'between',
            'args' => [
                uqField('visit_count'),
                uqLiteral('integer', 2),
                uqLiteral('integer', 4),
            ],
        ], 'boolean')['expression'],
        'conditional' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'if',
            'args' => [[
                'type' => 'operator',
                'operator' => 'is_null',
                'args' => [uqField('account_balance')],
            ], uqLiteral('amount', '0.00'), uqField('account_balance')],
        ], 'amount')['expression'],
        'is_null' => $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'is_null',
            'args' => [uqField('account_balance')],
        ], 'boolean')['expression'],
    ];
    $operatorValues = [
        'comparison' => $evaluator->evaluate(
            $operatorExpressions['comparison'],
            ['account_balance' => '12.00'],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'between' => $evaluator->evaluate(
            $operatorExpressions['between'],
            ['visit_count' => 3],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'conditional' => $evaluator->evaluate(
            $operatorExpressions['conditional'],
            ['account_balance' => null],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'is_null' => $evaluator->evaluate(
            $operatorExpressions['is_null'],
            ['account_balance' => null],
            ['query_cutoff_date' => '2026-07-28']
        ),
    ];
    $operatorRejectCodes = [
        'comparison' => uqExceptionCode(function () use ($validator): void {
            $validator->validate('member_list', [
                'type' => 'operator', 'operator' => 'gte',
                'args' => [uqField('member_name'), uqLiteral('integer', 1)],
            ], 'boolean');
        }),
        'between' => uqExceptionCode(function () use ($validator): void {
            $validator->validate('member_list', [
                'type' => 'operator', 'operator' => 'between',
                'args' => [
                    uqField('visit_count'),
                    uqLiteral('integer', 1),
                    uqLiteral('text', 'bad'),
                ],
            ], 'boolean');
        }),
        'conditional' => uqExceptionCode(function () use ($validator): void {
            $validator->validate('member_list', [
                'type' => 'operator', 'operator' => 'if',
                'args' => [
                    uqField('visit_count'),
                    uqLiteral('integer', 1),
                    uqLiteral('integer', 0),
                ],
            ], 'integer');
        }),
        'is_null' => uqExceptionCode(function () use ($validator): void {
            $validator->validate('member_list', [
                'type' => 'operator', 'operator' => 'is_null', 'args' => [],
            ], 'boolean');
        }),
    ];
    ok(
        '比较、between、if 与 is_null 均有受控成功和类型/参数拒绝合同',
        $operatorValues === [
            'comparison' => true,
            'between' => true,
            'conditional' => '0.00',
            'is_null' => true,
        ]
            && $operatorRejectCodes === [
                'comparison' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'between' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'conditional' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'is_null' => 'UNIFIED_QUERY_EXPRESSION_INVALID',
            ],
        json_encode(compact('operatorValues', 'operatorRejectCodes'), JSON_UNESCAPED_UNICODE),
        'UQ-AST-05'
    );

    $aggregateRows = [
        ['account_balance' => '3.00', 'member_name' => '一'],
        ['account_balance' => '9.00', 'member_name' => '二'],
        ['account_balance' => '12.00', 'member_name' => '三'],
    ];
    $aggregateExpected = [
        'sum' => '24.00',
        'average' => '8.00',
        'minimum' => '3.00',
        'maximum' => '12.00',
        'count' => 3,
    ];
    $aggregateActual = [];
    foreach ($aggregateExpected as $operator => $expected) {
        $returnType = $operator === 'count' ? 'integer' : 'amount';
        $child = $operator === 'count' ? uqField('member_name') : uqField('account_balance');
        $expression = $validator->validate('member_list', [
            'type' => 'operator', 'operator' => $operator, 'args' => [$child],
        ], $returnType)['expression'];
        $aggregateActual[$operator] = $evaluator->evaluate(
            $expression,
            [],
            ['query_cutoff_date' => '2026-07-28'],
            $aggregateRows
        );
    }
    $aggregateRejectCodes = [];
    foreach (['sum', 'average', 'minimum', 'maximum'] as $operator) {
        $aggregateRejectCodes[$operator] = uqExceptionCode(function () use ($validator, $operator): void {
            $validator->validate('member_list', [
                'type' => 'operator', 'operator' => $operator,
                'args' => [uqField('member_name')],
            ], 'text');
        });
    }
    $aggregateRejectCodes['count'] = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'operator', 'operator' => 'count',
            'args' => [uqField('member_name'), uqField('phone')],
        ], 'integer');
    });
    ok(
        'sum/avg/min/max/count 均有成功值与不合法类型或参数拒绝合同',
        $aggregateActual === $aggregateExpected
            && $aggregateRejectCodes === [
                'sum' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'average' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'minimum' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'maximum' => 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
                'count' => 'UNIFIED_QUERY_EXPRESSION_INVALID',
            ],
        json_encode(compact('aggregateActual', 'aggregateRejectCodes'), JSON_UNESCAPED_UNICODE),
        'UQ-AST-06'
    );

    uqSection('controlled bucket operator');
    $tenBucketArgs = [uqField('visit_count')];
    for ($bucket = 1; $bucket <= 10; $bucket++) {
        $tenBucketArgs[] = uqLiteral('integer', $bucket * 10);
        $tenBucketArgs[] = uqLiteral('text', '档位' . $bucket);
    }
    $tenBucketArgs[] = uqLiteral('text', '档位11');
    $tenBucket = $validator->validate('member_list', [
        'type' => 'operator',
        'operator' => 'bucket',
        'args' => $tenBucketArgs,
    ], 'text');

    $twelveBucketArgs = [uqField('visit_count')];
    for ($bucket = 1; $bucket <= 12; $bucket++) {
        $twelveBucketArgs[] = uqLiteral('integer', $bucket);
        $twelveBucketArgs[] = uqLiteral('text', '第' . $bucket . '档');
    }
    $twelveBucketArgs[] = uqLiteral('text', '第13档');
    $twelveBucket = $validator->validate('member_list', [
        'type' => 'operator',
        'operator' => 'bucket',
        'args' => $twelveBucketArgs,
    ], 'text');

    $bucketValues = [
        'first_boundary' => $evaluator->evaluate(
            $tenBucket['expression'],
            ['visit_count' => 10],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'next_bucket' => $evaluator->evaluate(
            $tenBucket['expression'],
            ['visit_count' => 11],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'last_boundary' => $evaluator->evaluate(
            $tenBucket['expression'],
            ['visit_count' => 100],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'fallback' => $evaluator->evaluate(
            $tenBucket['expression'],
            ['visit_count' => 101],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'null' => $evaluator->evaluate(
            $tenBucket['expression'],
            ['visit_count' => null],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'twelve_boundary' => $evaluator->evaluate(
            $twelveBucket['expression'],
            ['visit_count' => 12],
            ['query_cutoff_date' => '2026-07-28']
        ),
        'twelve_fallback' => $evaluator->evaluate(
            $twelveBucket['expression'],
            ['visit_count' => 13],
            ['query_cutoff_date' => '2026-07-28']
        ),
    ];
    ok(
        '10档和12档受控分档可执行，边界、兜底与空值口径固定',
        ($tenBucket['return_type'] ?? '') === 'text'
            && ($twelveBucket['return_type'] ?? '') === 'text'
            && $bucketValues === [
                'first_boundary' => '档位1',
                'next_bucket' => '档位2',
                'last_boundary' => '档位10',
                'fallback' => '档位11',
                'null' => null,
                'twelve_boundary' => '第12档',
                'twelve_fallback' => '第13档',
            ],
        json_encode(compact('bucketValues', 'tenBucket', 'twelveBucket'), JSON_UNESCAPED_UNICODE),
        'UQ-AST-07'
    );

    $duplicateBoundaryCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'bucket',
            'args' => [
                uqField('visit_count'),
                uqLiteral('integer', 10), uqLiteral('text', '低'),
                uqLiteral('integer', 10), uqLiteral('text', '高'),
                uqLiteral('text', '兜底'),
            ],
        ], 'text');
    });
    $descendingBoundaryCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'bucket',
            'args' => [
                uqField('visit_count'),
                uqLiteral('integer', 20), uqLiteral('text', '低'),
                uqLiteral('integer', 10), uqLiteral('text', '高'),
                uqLiteral('text', '兜底'),
            ],
        ], 'text');
    });
    $thirteenBucketCode = uqExceptionCode(function () use ($validator): void {
        $args = [uqField('visit_count')];
        for ($bucket = 1; $bucket <= 13; $bucket++) {
            $args[] = uqLiteral('integer', $bucket);
            $args[] = uqLiteral('text', '第' . $bucket . '档');
        }
        $args[] = uqLiteral('text', '第14档');
        $validator->validate('member_list', [
            'type' => 'operator', 'operator' => 'bucket', 'args' => $args,
        ], 'text');
    });
    $mixedResultCode = uqExceptionCode(function () use ($validator): void {
        $validator->validate('member_list', [
            'type' => 'operator',
            'operator' => 'bucket',
            'args' => [
                uqField('visit_count'),
                uqLiteral('integer', 10), uqLiteral('text', '低'),
                uqLiteral('integer', 20), uqLiteral('integer', 2),
                uqLiteral('text', '高'),
            ],
        ], 'text');
    });
    ok(
        '分档上界重复或倒序、超过12档及混合结果类型均被拒绝',
        $duplicateBoundaryCode === 'UNIFIED_QUERY_BUCKET_BOUNDARIES_INVALID'
            && $descendingBoundaryCode === 'UNIFIED_QUERY_BUCKET_BOUNDARIES_INVALID'
            && $thirteenBucketCode === 'UNIFIED_QUERY_EXPRESSION_INVALID'
            && $mixedResultCode === 'UNIFIED_QUERY_EXPRESSION_TYPE_INVALID',
        json_encode(compact(
            'duplicateBoundaryCode',
            'descendingBoundaryCode',
            'thirteenBucketCode',
            'mixedResultCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-AST-08'
    );

    uqSection('null, divide-by-zero, amount precision and cutoff date');
    $amountExpression = $validatedAmount['expression'];
    $third = $evaluator->evaluate(
        $amountExpression,
        ['account_balance' => '10.00'],
        ['query_cutoff_date' => '2026-07-28']
    );
    $dynamicZero = $evaluator->evaluate(
        $amountExpression,
        ['account_balance' => '0.00'],
        ['query_cutoff_date' => '2026-07-28']
    );
    $zeroDivisorExpression = $validator->validate('member_list', [
        'type' => 'operator',
        'operator' => 'divide',
        'args' => [uqField('account_balance'), uqField('visit_count')],
    ], 'amount')['expression'];
    $dynamicZero = $evaluator->evaluate(
        $zeroDivisorExpression,
        ['account_balance' => '9.00', 'visit_count' => 0],
        ['query_cutoff_date' => '2026-07-28']
    );
    $nullExpression = $validator->validate('member_list', [
        'type' => 'binary',
        'operator' => 'add',
        'left' => ['type' => 'field', 'fieldKey' => 'account_balance'],
        'right' => ['type' => 'literal', 'valueType' => 'amount', 'value' => '1.005'],
        'nullMode' => 'zero',
        'returnType' => 'amount',
    ], 'amount')['expression'];
    $nullAsZero = $evaluator->evaluate(
        $nullExpression,
        ['account_balance' => null],
        ['query_cutoff_date' => '2026-07-28']
    );
    ok(
        '金额四舍五入、空值转零和动态除零口径稳定',
        $third === '3.33' && $dynamicZero === null && $nullAsZero === '1.01',
        json_encode(compact('third', 'dynamicZero', 'nullAsZero'), JSON_UNESCAPED_UNICODE),
        'UQ-EVAL-01'
    );

    $dateExpression = $validator->validate('member_list', [
        'type' => 'operator',
        'operator' => 'date_diff_days',
        'args' => [
            ['type' => 'context', 'key' => 'query_cutoff_date'],
            uqLiteral('date', '2026-07-20'),
        ],
    ], 'integer')['expression'];
    $days = $evaluator->evaluate($dateExpression, [], ['query_cutoff_date' => '2026-07-28']);
    $badCutoffCode = uqExceptionCode(function () use ($evaluator, $dateExpression): void {
        $evaluator->evaluate($dateExpression, [], ['query_cutoff_date' => '2026-02-30']);
    });
    ok(
        '历史日期只使用后端查询截止日期且严格校验',
        $days === 8 && $badCutoffCode === 'UNIFIED_QUERY_CUTOFF_DATE_REQUIRED',
        json_encode([$days, $badCutoffCode], JSON_UNESCAPED_UNICODE),
        'UQ-EVAL-02'
    );

    uqSection('permission before calculation');
    $customDefinition = [
        'field_key' => 'cf_aaaaaaaaaaaaaaaaaaaa',
        'name' => '余额三分之一',
        'return_type' => 'amount',
        'page_code' => 'member_list',
        'version' => 1,
        'status' => 'active',
        'expression' => $amountExpression,
    ];
    $permissionRows = [
        [
            'member_id' => 1, 'member_name' => '可见会员', 'phone' => '13100000001',
            'member_no' => 'M001', 'member_status' => '正常', 'member_level' => '金卡',
            'store' => '本店', 'store_id' => 8, 'account_balance' => '12.00', 'visit_count' => 2,
        ],
        [
            'member_id' => 2, 'member_name' => '越权会员', 'phone' => '13100000002',
            'member_no' => 'M002', 'member_status' => '正常', 'member_level' => '金卡',
            'store' => '二店', 'store_id' => 9, 'account_balance' => 'NOT_A_NUMBER', 'visit_count' => 3,
        ],
    ];
    $permissionResult = $execution->execute(
        'member_list',
        $permissionRows,
        [$customDefinition],
        ['page' => 1, 'pageSize' => 20],
        [
            'permissions' => [],
            'query_cutoff_date' => '2026-07-28',
            'data_as_of' => 1785258000,
        ],
        function (array $row): bool {
            return (int)($row['store_id'] ?? 0) === 8;
        }
    );
    ok(
        '越权坏数据未进入计算且授权计数可核对',
        count($permissionResult['rows'] ?? []) === 1
            && ($permissionResult['rows'][0]['cf_aaaaaaaaaaaaaaaaaaaa'] ?? null) === '4.00'
            && (int)($permissionResult['security']['sourceRowCount'] ?? 0) === 2
            && (int)($permissionResult['security']['authorizedRowCount'] ?? 0) === 1
            && ($permissionResult['security']['calculatedAfterPermission'] ?? false) === true,
        json_encode($permissionResult, JSON_UNESCAPED_UNICODE),
        'UQ-PERM-01'
    );

    uqSection('UI normalization and filter groups');
    $rows = [
        ['member_id' => 1, 'member_name' => '林一', 'phone' => '13100000001', 'member_no' => 'M001', 'member_status' => '正常', 'member_level' => '金卡', 'store' => '本店', 'store_id' => 8, 'account_balance' => '3.00', 'visit_count' => 1],
        ['member_id' => 2, 'member_name' => '王二', 'phone' => '13100000002', 'member_no' => 'M002', 'member_status' => '正常', 'member_level' => '金卡', 'store' => '本店', 'store_id' => 8, 'account_balance' => '6.00', 'visit_count' => 2],
        ['member_id' => 3, 'member_name' => '林三', 'phone' => '13100000003', 'member_no' => 'M003', 'member_status' => '正常', 'member_level' => '银卡', 'store' => '本店', 'store_id' => 8, 'account_balance' => '9.00', 'visit_count' => 3],
        ['member_id' => 4, 'member_name' => '赵四', 'phone' => '13100000004', 'member_no' => 'M004', 'member_status' => '已停用', 'member_level' => '银卡', 'store' => '本店', 'store_id' => 8, 'account_balance' => '12.00', 'visit_count' => 4],
        ['member_id' => 5, 'member_name' => '林五', 'phone' => '13100000005', 'member_no' => 'M005', 'member_status' => '正常', 'member_level' => '金卡', 'store' => '本店', 'store_id' => 8, 'account_balance' => '15.00', 'visit_count' => 5],
        ['member_id' => 6, 'member_name' => '孙六', 'phone' => '13100000006', 'member_no' => 'M006', 'member_status' => '正常', 'member_level' => '金卡', 'store' => '本店', 'store_id' => 8, 'account_balance' => '18.00', 'visit_count' => 6],
    ];
    $groupedQuery = $execution->execute(
        'member_list',
        $rows,
        [$customDefinition],
        [
            'pageCode' => 'member_list',
            'page' => 1,
            'pageSize' => 20,
            'queryCutoffDate' => '2026-07-28',
            'topFilters' => [[
                'field' => 'member_level', 'operator' => 'eq', 'value' => '金卡',
            ]],
            'querySettings' => [
                'filters' => [
                    ['field' => 'member_name', 'operator' => 'eq', 'value' => '林一'],
                    ['field' => 'visit_count', 'operator' => 'eq', 'value' => 99],
                ],
                'filterRelation' => 'any',
                'schemaVersion' => UnifiedQueryPageRegistry::SCHEMA_VERSION,
            ],
        ],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    ok(
        '顶部条件始终 AND，组合条件内部才应用 any',
        array_column($groupedQuery['rows'] ?? [], 'member_id') === [1],
        json_encode(array_column($groupedQuery['rows'] ?? [], 'member_id')),
        'UQ-EXEC-01'
    );

    $keywordQuery = $execution->execute(
        'member_list',
        $rows,
        [$customDefinition],
        ['keyword' => '00000003', 'page' => 1, 'pageSize' => 20],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    ok(
        '综合搜索在姓名、手机号、编号中 OR，且与其他条件 AND',
        array_column($keywordQuery['rows'] ?? [], 'member_id') === [3],
        json_encode($keywordQuery['rows'] ?? [], JSON_UNESCAPED_UNICODE),
        'UQ-EXEC-02'
    );

    $quickFilterPayload = [
        'page' => 2,
        'pageSize' => 1,
        'filters' => [[
            'field' => 'member_name', 'operator' => 'contains', 'value' => '林',
        ]],
        // 契约：quickFilters 是受控的筛选条件集合，不是前端快捷按钮的展示对象。
        'quickFilters' => [[
            'field' => 'member_level', 'operator' => 'eq', 'value' => '金卡',
        ]],
        'summaries' => [[
            'field' => 'account_balance', 'aggregation' => 'sum',
        ]],
        'groupBy' => ['member_level'],
        'export' => [
            'scope' => 'query',
            'fields' => ['member_name', 'account_balance'],
        ],
    ];
    $quickFiltered = $execution->execute(
        'member_list',
        $rows,
        [],
        $quickFilterPayload,
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    $quickFilterPlan = $execution->validatedPlan(
        'member_list',
        [],
        $quickFilterPayload,
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28']
    );
    $invalidQuickFilterCode = uqExceptionCode(function () use ($execution, $rows): void {
        $execution->execute(
            'member_list',
            $rows,
            [],
            ['quickFilters' => [[
                'key' => 'gold-members', 'label' => '金卡会员', 'active' => true,
            ]]],
            ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
            function (): bool {
                return true;
            }
        );
    });
    ok(
        '快捷筛选进入列表、分页、合计、分组、导出与冻结计划，展示对象不能冒充条件',
        array_column($quickFiltered['rows'] ?? [], 'member_id') === [5]
            && (int)($quickFiltered['pagination']['total'] ?? 0) === 2
            && array_column($quickFiltered['exportRows'] ?? [], 'member_name') === ['林一', '林五']
            && ($quickFiltered['summaries']['account_balance:sum'] ?? null) === '18.00'
            && array_sum(array_column($quickFiltered['groups'] ?? [], 'count')) === 2
            && ($quickFilterPlan['quick_filters'] ?? []) === [[
                'field_key' => 'member_level', 'operator' => 'equal', 'value' => '金卡',
            ]]
            && $invalidQuickFilterCode === 'UNIFIED_QUERY_REQUEST_INVALID',
        json_encode([
            'rows' => $quickFiltered['rows'] ?? [],
            'pagination' => $quickFiltered['pagination'] ?? [],
            'summaries' => $quickFiltered['summaries'] ?? [],
            'groups' => $quickFiltered['groups'] ?? [],
            'exportRows' => $quickFiltered['exportRows'] ?? [],
            'quickFilters' => $quickFilterPlan['quick_filters'] ?? [],
            'invalidQuickFilterCode' => $invalidQuickFilterCode,
        ], JSON_UNESCAPED_UNICODE),
        'UQ-EXEC-06'
    );

    uqSection('stable pagination and result parity');
    $paged = $execution->execute(
        'member_list',
        $rows,
        [$customDefinition],
        [
            'page' => 2,
            'pageSize' => 2,
            'filters' => [['field' => 'member_status', 'operator' => 'eq', 'value' => '正常']],
            'sorts' => [['field' => 'member_status', 'direction' => 'asc']],
        ],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    $firstPage = $execution->execute(
        'member_list',
        $rows,
        [$customDefinition],
        [
            'page' => 1,
            'pageSize' => 2,
            'filters' => [['field' => 'member_status', 'operator' => 'eq', 'value' => '正常']],
            'sorts' => [['field' => 'member_status', 'direction' => 'asc']],
        ],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    $pageOneIds = array_column($firstPage['rows'] ?? [], 'member_id');
    $pageTwoIds = array_column($paged['rows'] ?? [], 'member_id');
    ok(
        '同值排序自动追加隐藏 member_id 且跨页无重复',
        $pageOneIds === [1, 2]
            && $pageTwoIds === [3, 5]
            && array_intersect($pageOneIds, $pageTwoIds) === [],
        json_encode([$pageOneIds, $pageTwoIds]),
        'UQ-EXEC-03'
    );

    $parity = $execution->execute(
        'member_list',
        $rows,
        [$customDefinition],
        [
            'page' => 2,
            'pageSize' => 2,
            'filters' => [['field' => 'member_status', 'operator' => 'eq', 'value' => '正常']],
            'sorts' => [['field' => 'account_balance', 'direction' => 'asc']],
            'groupBy' => ['member_level'],
            'summaries' => [
                ['field' => 'account_balance', 'aggregation' => 'sum'],
                ['field' => 'cf_aaaaaaaaaaaaaaaaaaaa', 'aggregation' => 'sum'],
            ],
            'export' => [
                'scope' => 'query',
                'fields' => ['member_name', 'account_balance', 'cf_aaaaaaaaaaaaaaaaaaaa'],
            ],
        ],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    $queryExportIds = array_map(function (array $row) use ($rows): int {
        foreach ($rows as $source) {
            if (($source['member_name'] ?? '') === ($row['member_name'] ?? '')) {
                return (int)$source['member_id'];
            }
        }
        return 0;
    }, $parity['exportRows'] ?? []);
    $groupCount = array_sum(array_column($parity['groups'] ?? [], 'count'));
    ok(
        '列表总数、全查询导出、合计与分组共用筛选后结果集',
        (int)($parity['pagination']['total'] ?? 0) === 5
            && count($parity['exportRows'] ?? []) === 5
            && $queryExportIds === [1, 2, 3, 5, 6]
            && ($parity['summaries']['account_balance:sum'] ?? null) === '51.00'
            && ($parity['summaries']['cf_aaaaaaaaaaaaaaaaaaaa:sum'] ?? null) === '17.00'
            && $groupCount === 5,
        json_encode($parity, JSON_UNESCAPED_UNICODE),
        'UQ-EXEC-04'
    );

    $pageExport = $execution->execute(
        'member_list',
        $rows,
        [$customDefinition],
        [
            'page' => 2,
            'pageSize' => 2,
            'filters' => [['field' => 'member_status', 'operator' => 'eq', 'value' => '正常']],
            'sorts' => [['field' => 'account_balance', 'direction' => 'asc']],
            'export' => ['scope' => 'page', 'fields' => ['member_name', 'account_balance']],
        ],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    ok(
        '当前页导出与当页列表逐行一致',
        array_column($pageExport['exportRows'] ?? [], 'member_name')
            === array_column($pageExport['rows'] ?? [], 'member_name'),
        json_encode($pageExport, JSON_UNESCAPED_UNICODE),
        'UQ-EXEC-05'
    );

    $countingEvaluator = new UqCountingEvaluator();
    $countingExecution = new UnifiedQueryExecutionServices(
        $registry,
        $validator,
        $countingEvaluator
    );
    $aggregateDefinition = [
        'field_key' => 'cf_bbbbbbbbbbbbbbbbbbbb',
        'name' => '全量余额',
        'return_type' => 'amount',
        'page_code' => 'member_list',
        'version' => 1,
        'status' => 'active',
        'expression' => [
            'type' => 'operator',
            'operator' => 'sum',
            'args' => [uqField('account_balance')],
        ],
    ];
    $aggregateExecution = $countingExecution->execute(
        'member_list',
        $rows,
        [$customDefinition, $aggregateDefinition],
        [
            'page' => 1,
            'pageSize' => 20,
            'summaries' => [[
                'field' => 'cf_bbbbbbbbbbbbbbbbbbbb',
                'aggregation' => 'sum',
            ]],
            'export' => [
                'scope' => 'query',
                'fields' => ['cf_bbbbbbbbbbbbbbbbbbbb'],
            ],
        ],
        ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
        function (): bool {
            return true;
        }
    );
    $aggregateListValues = array_column(
        $aggregateExecution['rows'] ?? [],
        'cf_bbbbbbbbbbbbbbbbbbbb'
    );
    $aggregateExportValues = array_column(
        $aggregateExecution['exportRows'] ?? [],
        'cf_bbbbbbbbbbbbbbbbbbbb'
    );
    ok(
        '聚合自定义字段每次查询只扫描一次授权集并复用于列表/合计/导出',
        $countingEvaluator->aggregateCalls === 1
            && $countingEvaluator->calls === count($rows) + 1
            && array_values(array_unique($aggregateListValues)) === ['63.00']
            && $aggregateExportValues === $aggregateListValues
            && ($aggregateExecution['summaries']['cf_bbbbbbbbbbbbbbbbbbbb:sum'] ?? '')
                === '378.00',
        json_encode([
            'calls' => $countingEvaluator->calls,
            'aggregateCalls' => $countingEvaluator->aggregateCalls,
            'list' => $aggregateListValues,
            'export' => $aggregateExportValues,
            'summaries' => $aggregateExecution['summaries'] ?? [],
        ], JSON_UNESCAPED_UNICODE),
        'UQ-PERF-02'
    );

    uqSection('malformed values and performance limits');
    $badNumericCode = uqExceptionCode(function () use ($execution, $rows): void {
        $execution->execute(
            'member_list',
            $rows,
            [],
            ['filters' => [['field' => 'account_balance', 'operator' => 'eq', 'value' => 'abc']]],
            ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
            function (): bool {
                return true;
            }
        );
    });
    $badOperatorCode = uqExceptionCode(function () use ($execution, $rows): void {
        $execution->execute(
            'member_list',
            $rows,
            [],
            ['filters' => [['field' => 'account_balance', 'operator' => 'contains', 'value' => '1']]],
            ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
            function (): bool {
                return true;
            }
        );
    });
    $duplicateRows = [$rows[0], $rows[0]];
    $duplicateCode = uqExceptionCode(function () use ($execution, $duplicateRows): void {
        $execution->execute(
            'member_list',
            $duplicateRows,
            [],
            [],
            ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
            function (): bool {
                return true;
            }
        );
    });
    $oversized = [];
    for ($i = 1; $i <= UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1; $i++) {
        $oversized[] = ['member_id' => $i];
    }
    $windowCode = uqExceptionCode(function () use ($execution, $oversized): void {
        $execution->execute(
            'member_list',
            $oversized,
            [],
            [],
            ['permissions' => [], 'query_cutoff_date' => '2026-07-28'],
            function (): bool {
                return true;
            }
        );
    });
    ok(
        '畸形筛选、重复稳定键与超大内存窗口均被拒绝',
        $badNumericCode === 'UNIFIED_QUERY_REQUEST_INVALID'
            && $badOperatorCode === 'UNIFIED_QUERY_REQUEST_INVALID'
            && $duplicateCode === 'UNIFIED_QUERY_STABLE_KEY_DUPLICATE'
            && $windowCode === 'UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE',
        json_encode([$badNumericCode, $badOperatorCode, $duplicateCode, $windowCode], JSON_UNESCAPED_UNICODE),
        'UQ-PERF-01'
    );
} catch (\Throwable $throwable) {
    ok(
        '统一查询纯合同未发生未捕获异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage() . "\n" . $throwable->getTraceAsString()
    );
}

finish('unified-query-contract');

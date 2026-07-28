<?php

namespace app\services\cashier\v3\query;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\metric\MetricDictionaryServices;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryAccessPolicy;
use app\services\query\UnifiedQueryCapabilityServices;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryExportTaskServices;
use app\services\query\UnifiedQueryFieldAliasServices;
use app\services\query\UnifiedQueryFieldReferenceServices;
use app\services\query\UnifiedQueryJson;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\provider\MemberUnifiedQueryProvider;
use think\facade\Db;

/**
 * 统一查询生产模块：唯一对象图、V3 handler 与账号页面并发合同。
 */
final class UnifiedQueryModule
{
    /** @var array<string,object>|null */
    private static $runtime;

    public static function resetForTests(): void
    {
        self::$runtime = null;
    }

    public static function install(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3RootDomainAssembler $assembler
    ): void {
        $runtime = self::runtime();
        self::registerContextPolicies($dispatcher);
        self::registerProjections($dispatcher, $runtime);
        self::registerCommands($dispatcher, $runtime);
    }

    /** @return array<string,object> */
    public static function runtime(): array
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }
        /** @var MetricDictionaryServices $metricDictionary */
        $metricDictionary = app()->make(MetricDictionaryServices::class);
        $definitions = $metricDictionary->getDefinitions();
        if (!$definitions) {
            throw new \LogicException('统一查询启动失败：系统指标字典为空');
        }

        $registry = UnifiedQueryPageRegistry::withDefaults($definitions);
        $access = new UnifiedQueryAccessPolicy();
        $validator = new StructuredExpressionValidator($registry);
        $evaluator = new StructuredExpressionEvaluator();
        $references = new UnifiedQueryFieldReferenceServices();
        $customFields = new UnifiedQueryCustomFieldServices(
            $registry,
            $validator,
            $access,
            $references
        );
        $execution = new UnifiedQueryExecutionServices($registry, $validator, $evaluator);
        $preferences = new UnifiedQueryPreferenceServices(
            $registry,
            $customFields,
            $execution,
            $access,
            $references
        );
        $aliases = new UnifiedQueryFieldAliasServices($registry, $customFields, $access);
        $exports = new UnifiedQueryExportTaskServices(
            $registry,
            $customFields,
            $aliases,
            $references,
            $execution,
            $preferences,
            $access
        );
        $capabilities = new UnifiedQueryCapabilityServices(
            $registry,
            $customFields,
            $aliases,
            $exports,
            $preferences,
            $access
        );
        $contextFactory = new UnifiedQueryContextFactory();
        $memberProvider = new MemberUnifiedQueryProvider($execution, $customFields, $preferences);

        self::$runtime = compact(
            'registry',
            'access',
            'validator',
            'evaluator',
            'references',
            'customFields',
            'execution',
            'preferences',
            'aliases',
            'exports',
            'capabilities',
            'contextFactory',
            'memberProvider'
        );
        self::bindRuntime(self::$runtime);
        return self::$runtime;
    }

    public static function service(string $key)
    {
        $runtime = self::runtime();
        if (!isset($runtime[$key])) {
            throw new \InvalidArgumentException('未知统一查询服务：' . $key);
        }
        return $runtime[$key];
    }

    protected static function registerContextPolicies(CashierV3ActionDispatcher $dispatcher): void
    {
        foreach ([
            'save-member-query-settings',
            'save-unified-query-field-aliases',
            'save-unified-query-custom-field',
            'change-unified-query-custom-field-status',
            'archive-unified-query-custom-field',
            'upgrade-unified-query-field-reference',
            'create-unified-query-export',
        ] as $action) {
            if ($dispatcher->policies()->has($action)) {
                continue;
            }
            $dispatcher->policies()->register(new CashierV3ContextPolicy(
                $action,
                ['query_preference'],
                [],
                function (array $payload): array {
                    $pageCode = self::pageCode($payload);
                    return [
                        'required' => ['query_preference'],
                        'allowed' => ['query_preference'],
                        'identities' => [[
                            'role' => 'query_preference',
                            'kind' => 'query_preference',
                            'id' => $pageCode,
                            'required' => true,
                        ]],
                        'required_read_roles' => ['query_preference'],
                        'required_touched_roles' => ['query_preference'],
                    ];
                },
                ['query_preference']
            ));
        }
    }

    protected static function registerProjections(
        CashierV3ActionDispatcher $dispatcher,
        array $runtime
    ): void {
        $handlers = $dispatcher->handlers();
        if (!$handlers->hasProjection('query-unified-query-capabilities')) {
            $handlers->registerProjection('query-unified-query-capabilities', function (array $scope) use ($dispatcher, $runtime): array {
                return self::translate(function () use ($scope, $dispatcher, $runtime): array {
                    $payload = self::payload($scope);
                    $pageCode = self::pageCode($payload);
                    $context = self::queryContext($runtime, $scope, $payload);
                    $version = self::ensurePreferenceVersion(
                        $dispatcher,
                        $scope['operator_scope'],
                        $scope['data_scope'],
                        $pageCode
                    );
                    $capability = $runtime['capabilities']->build(
                        $context,
                        $pageCode,
                        $version,
                        (int)$context['data_as_of']
                    );
                    return [
                        'data' => ['unifiedQueryCapability' => $capability],
                        'versions' => [[
                            'kind' => 'query_preference',
                            'id' => $pageCode,
                            'version' => $version,
                        ]],
                    ];
                });
            });
        }
        if (!$handlers->hasProjection('query-members')) {
            $handlers->registerProjection('query-members', function (array $scope) use ($runtime): array {
                return self::translate(function () use ($scope, $runtime): array {
                    $payload = self::payload($scope);
                    $payload['pageCode'] = MemberUnifiedQueryProvider::PAGE_CODE;
                    $context = self::queryContext($runtime, $scope, $payload);
                    return [
                        'data' => $runtime['memberProvider']->query($context, $payload),
                    ];
                });
            });
        }
        if (!$handlers->hasProjection('query-unified-query-export-task')) {
            $handlers->registerProjection('query-unified-query-export-task', function (array $scope) use ($runtime): array {
                return self::translate(function () use ($scope, $runtime): array {
                    $payload = self::payload($scope);
                    $payload['pageCode'] = self::pageCode($payload);
                    $context = self::queryContext($runtime, $scope, $payload);
                    $taskNo = trim((string)($payload['taskId'] ?? ($payload['task_id'] ?? '')));
                    if ($taskNo === '') {
                        $taskNo = self::taskNoFromReceipt(
                            trim((string)($payload['originalIdempotencyKey'] ?? '')),
                            $scope['operator_scope']
                        );
                    }
                    $task = $runtime['exports']->findForAccount($context, $taskNo);
                    if (!empty($task['downloadAvailable'])) {
                        $task['downloadUrl'] = '/cashierapi/v3/unified-query/exports/'
                            . rawurlencode($taskNo) . '/download';
                    }
                    return ['data' => ['exportTask' => $task]];
                });
            });
        }
    }

    protected static function registerCommands(
        CashierV3ActionDispatcher $dispatcher,
        array $runtime
    ): void {
        $handlers = $dispatcher->handlers();
        $definitions = [
            'save-member-query-settings' => function (array $context, array $payload) use ($runtime): array {
                return $runtime['preferences']->save($context, $payload);
            },
            'save-unified-query-field-aliases' => function (array $context, array $payload) use ($runtime): array {
                return $runtime['aliases']->save($context, $payload);
            },
            'save-unified-query-custom-field' => function (array $context, array $payload) use ($runtime): array {
                return $runtime['customFields']->save($context, $payload);
            },
            'change-unified-query-custom-field-status' => function (array $context, array $payload) use ($runtime): array {
                return $runtime['customFields']->changeStatus($context, $payload);
            },
            'archive-unified-query-custom-field' => function (array $context, array $payload) use ($runtime): array {
                return $runtime['customFields']->archive($context, $payload);
            },
            'upgrade-unified-query-field-reference' => function (array $context, array $payload) use ($runtime): array {
                return $runtime['preferences']->upgradeReference($context, $payload);
            },
            'create-unified-query-export' => function (array $context, array $payload) use ($runtime): array {
                return ['exportTask' => $runtime['exports']->create($context, $payload)];
            },
        ];
        foreach ($definitions as $action => $business) {
            if ($handlers->hasCommand($action)) {
                continue;
            }
            $handlers->registerCommand($action, function (array $scope) use ($runtime, $business, $action): array {
                return self::translate(function () use ($scope, $runtime, $business, $action): array {
                    $payload = self::payload($scope);
                    $payload['pageCode'] = self::pageCode($payload);
                    $context = self::queryContext($runtime, $scope, $payload);
                    $context['query_preference_version'] = self::lockedPreferenceVersion(
                        $scope,
                        $payload['pageCode']
                    );
                    $data = $business($context, $payload);
                    $taskNo = (string)($data['exportTask']['taskId'] ?? '');
                    return [
                        'data' => $data,
                        'business_no' => $taskNo,
                        'touched' => ['query_preference'],
                        'message' => self::successMessage($action),
                    ];
                });
            });
        }
    }

    protected static function queryContext(array $runtime, array $scope, array $payload): array
    {
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '统一查询缺少可信登录范围，请重新登录后重试。'
            );
        }
        return $runtime['contextFactory']->make($operatorScope, $dataScope, $payload);
    }

    protected static function ensurePreferenceVersion(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $pageCode
    ): int {
        return Db::transaction(function () use ($dispatcher, $operatorScope, $dataScope, $pageCode): int {
            $scope = $dispatcher->scopeResolver()->resolveFor(
                'query_preference',
                $pageCode,
                $operatorScope,
                $dataScope
            );
            return $dispatcher->versionServices()->ensureRegistered(
                $scope,
                'query_preference',
                $pageCode,
                $dataScope
            );
        });
    }

    protected static function lockedPreferenceVersion(array $scope, string $pageCode): int
    {
        foreach ((array)($scope['contexts'] ?? []) as $context) {
            if ((string)($context['kind'] ?? '') === 'query_preference'
                && (string)($context['id'] ?? '') === $pageCode) {
                $version = (int)($context['expected_version'] ?? 0);
                if ($version > 0) {
                    return $version;
                }
            }
        }
        throw new UnifiedQueryException(
            'UNIFIED_QUERY_GATEWAY_VERSION_REQUIRED',
            '统一查询缺少账号页面版本，请刷新后重试。',
            []
        );
    }

    protected static function taskNoFromReceipt(
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope
    ): string {
        if ($idempotencyKey === '') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_NOT_FOUND',
                '缺少导出任务标识，请重新创建导出任务。',
                []
            );
        }
        $receipt = Db::name('cashier_v3_command_receipt')
            ->where('idempotency_key', $idempotencyKey)
            ->where('action', 'create-unified-query-export')
            ->where('operator_id', $operatorScope->operatorId())
            ->where('store_id', $operatorScope->storeId())
            ->where('status', 1)
            ->find();
        if (!$receipt) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_NOT_FOUND',
                '导出任务尚未创建完成，请稍后重试。',
                []
            );
        }
        $result = json_decode((string)$receipt['result_json'], true);
        $taskNo = trim((string)($result['data']['exportTask']['taskId'] ?? ''));
        if (!preg_match('/^uqe_[a-f0-9]{32}$/D', $taskNo)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_NOT_FOUND',
                '导出任务结果不完整，请重新创建。',
                []
            );
        }
        return $taskNo;
    }

    protected static function pageCode(array $payload): string
    {
        $pageCode = trim((string)($payload['pageCode'] ?? ($payload['page_code'] ?? '')));
        if ($pageCode !== MemberUnifiedQueryProvider::PAGE_CODE) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PAGE_NOT_ALLOWED',
                '当前页面尚未开放统一查询。',
                ['page_code' => $pageCode]
            );
        }
        return $pageCode;
    }

    protected static function payload(array $scope): array
    {
        return is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
    }

    protected static function successMessage(string $action): string
    {
        $messages = [
            'save-member-query-settings' => '查询设置已保存。',
            'save-unified-query-field-aliases' => '字段名称已更新。',
            'save-unified-query-custom-field' => '自定义字段已保存。',
            'change-unified-query-custom-field-status' => '字段状态已更新。',
            'archive-unified-query-custom-field' => '自定义字段已删除。',
            'upgrade-unified-query-field-reference' => '已升级到字段最新版本。',
            'create-unified-query-export' => '导出任务已创建。',
        ];
        return $messages[$action] ?? '操作成功。';
    }

    protected static function translate(callable $callback): array
    {
        try {
            return $callback();
        } catch (UnifiedQueryException $exception) {
            $status = strpos($exception->getErrorCode(), 'VERSION_CONFLICT') !== false
                ? CashierV3ResultCode::STATUS_CONFLICT
                : CashierV3ResultCode::STATUS_FAILED;
            throw new CashierV3CommandException(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $status,
                $exception->getDetail()
            );
        }
    }

    protected static function bindRuntime(array $runtime): void
    {
        $app = app();
        if (!is_object($app) || !method_exists($app, 'instance')) {
            return;
        }
        foreach ($runtime as $service) {
            $app->instance(get_class($service), $service);
        }
    }
}

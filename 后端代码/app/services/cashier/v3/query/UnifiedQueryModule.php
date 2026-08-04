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
use app\services\query\UnifiedQueryCommandCoordinator;
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
use app\services\query\UnifiedQueryRuntime;
use app\services\query\provider\MemberUnifiedQueryContextFactory;
use app\services\query\provider\MemberUnifiedQueryProvider;
use app\services\query\provider\StaffUnifiedQueryProvider;
use think\facade\Db;

/**
 * 统一查询生产模块：唯一对象图、V3 handler 与账号页面并发合同。
 */
final class UnifiedQueryModule
{
    private const LEGACY_SAVE_MEMBER_SETTINGS = 'save-member-query-settings';

    /** @var array<string,object>|null */
    private static $runtime;

    public static function resetForTests(): void
    {
        self::$runtime = null;
        UnifiedQueryRuntime::resetForTests();
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
        self::$runtime = UnifiedQueryRuntime::runtime();
        $coreContextFactory = self::$runtime['contextFactory'];
        // 旧收银下载入口没有 pageCode；只在该明确会员适配层补 member_list。
        self::$runtime['contextFactory'] = new MemberUnifiedQueryContextFactory(
            $coreContextFactory
        );
        self::$runtime['memberProvider'] = self::$runtime['providers']->resolve(
            MemberUnifiedQueryProvider::PAGE_CODE
        );
        self::$runtime['staffProvider'] = self::$runtime['providers']->resolve(
            StaffUnifiedQueryProvider::PAGE_CODE
        );
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
            'save-unified-query-settings',
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
        if (!$handlers->hasProjection('query-staff')) {
            $handlers->registerProjection('query-staff', function (array $scope) use ($runtime): array {
                return self::translate(function () use ($scope, $runtime): array {
                    $payload = self::payload($scope);
                    $payload['pageCode'] = StaffUnifiedQueryProvider::PAGE_CODE;
                    $context = self::queryContext($runtime, $scope, $payload);
                    return [
                        'data' => $runtime['staffProvider']->query($context, $payload),
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
        $actions = [
            self::LEGACY_SAVE_MEMBER_SETTINGS,
            UnifiedQueryCommandCoordinator::SAVE_SETTINGS,
            UnifiedQueryCommandCoordinator::SAVE_ALIASES,
            UnifiedQueryCommandCoordinator::SAVE_CUSTOM_FIELD,
            UnifiedQueryCommandCoordinator::CHANGE_CUSTOM_FIELD_STATUS,
            UnifiedQueryCommandCoordinator::ARCHIVE_CUSTOM_FIELD,
            UnifiedQueryCommandCoordinator::UPGRADE_FIELD_REFERENCE,
            UnifiedQueryCommandCoordinator::CREATE_EXPORT,
        ];
        foreach ($actions as $action) {
            if ($handlers->hasCommand($action)) {
                continue;
            }
            $handlers->registerCommand($action, function (array $scope) use ($runtime, $action): array {
                return self::translate(function () use ($scope, $runtime, $action): array {
                    $payload = self::payload($scope);
                    $payload['pageCode'] = self::pageCode($payload);
                    $context = self::queryContext($runtime, $scope, $payload);
                    $context['query_preference_version'] = self::lockedPreferenceVersion(
                        $scope,
                        $payload['pageCode']
                    );
                    $coordinatorAction = $action;
                    if ($action === self::LEGACY_SAVE_MEMBER_SETTINGS) {
                        if ($payload['pageCode'] !== MemberUnifiedQueryProvider::PAGE_CODE) {
                            throw new UnifiedQueryException(
                                'UNIFIED_QUERY_COMMAND_PAGE_MISMATCH',
                                '会员查询设置操作不能用于其他页面。',
                                [
                                    'action' => $action,
                                    'page_code' => $payload['pageCode'],
                                ]
                            );
                        }
                        $coordinatorAction = UnifiedQueryCommandCoordinator::SAVE_SETTINGS;
                    }
                    return $runtime['commandCoordinator']->dispatch(
                        $coordinatorAction,
                        $context,
                        $payload
                    );
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
        self::runtime()['registry']->page($pageCode);
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

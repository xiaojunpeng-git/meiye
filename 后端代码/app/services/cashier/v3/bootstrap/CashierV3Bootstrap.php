<?php
namespace app\services\cashier\v3\bootstrap;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandGatewayServices;
use app\services\cashier\v3\CashierV3DataScopeFactory;
use app\services\cashier\v3\CashierV3PermissionSnapshotServices;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\event\CashierV3ActionActivationGate;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\event\CashierV3EventConsumerRegistry;
use app\services\cashier\v3\event\CashierV3EventOutboxReadinessGuard;
use app\services\cashier\v3\event\CashierV3OutboxServices;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry;
use app\services\cashier\v3\permission\CashierV3SelectorGrantServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\projection\CashierV3RootProjector;
use app\services\cashier\v3\projection\CashierV3StructuredEmptyRootPartitionModule;
use app\services\cashier\v3\readiness\CashierV3TableReadinessGuard;
use app\services\cashier\v3\member\CashierV3MemberModule;
use app\services\cashier\v3\member\CashierV3DirectGiftReconciliationConsumer;
use app\services\cashier\v3\member\CashierV3RechargeModule;
use app\services\cashier\v3\member\CashierV3RechargeCheckoutModule;
use app\services\cashier\v3\member\CashierV3RechargeCheckoutRequestVersionProvider;
use app\services\cashier\v3\settlement\CashierV3RechargeDebtRepaymentServices;
use app\services\cashier\v3\cashier\CashierV3CashierModule;
use app\services\cashier\v3\card\CashierV3CardOperationModule;
use app\services\cashier\v3\hang\CashierV3HangModule;
use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use app\services\cashier\v3\order\CashierV3OrderQueryModule;
use app\services\cashier\v3\dashboard\CashierV3BusinessDashboardModule;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
use app\services\cashier\v3\registry\CashierV3HandlerRegistry;
use app\services\cashier\v3\registry\CashierV3PermissionGuard;
use app\services\cashier\v3\settlement\CashierV3CheckoutRequestVersionProvider;
use app\services\cashier\v3\settlement\CashierV3CheckoutResourcePlanLoader;
use app\services\cashier\v3\settlement\CashierV3CheckoutSourceAuthorityLoader;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResourcePlanRepository;
use app\services\organization\EmployeeDataScopeServices;

/**
 * 唯一生产 Composition Root。
 *
 * Controller 只能从这里取得 Dispatcher；容器解析必须返回同一实例。
 * 禁止 public 测试构建入口旁路；测试图放在 tests/cashier-v3 专用 factory。
 */
class CashierV3Bootstrap
{
    /** @var CashierV3ActionDispatcher|null */
    private static $dispatcher;

    /** @var CashierV3CommandGatewayServices|null */
    private static $gateway;

    /** @var CashierV3OutboxServices|null */
    private static $outbox;

    /** @var array|null */
    private static $lastSelfCheck;

    /** @var bool */
    private static $frozen = false;

    /** @var callable[] */
    private static $moduleInstallers = [];

    public static function resetForTests(): void
    {
        self::$dispatcher = null;
        self::$gateway = null;
        self::$outbox = null;
        self::$lastSelfCheck = null;
        self::$frozen = false;
        self::$moduleInstallers = [];
        UnifiedQueryModule::resetForTests();
        CashierV3ActionManifest::flushCache();
        if (function_exists('app')) {
            try {
                $app = app();
                if (method_exists($app, 'delete')) {
                    $app->delete(CashierV3ActionDispatcher::class);
                    $app->delete(CashierV3CommandGatewayServices::class);
                    $app->delete(CashierV3ActionActivationGate::class);
                    $app->delete(CashierV3EventConsumerRegistry::class);
                    $app->delete(CashierV3OutboxServices::class);
                }
            } catch (\Throwable $e) {
                // 测试容器可能尚未启动
            }
        }
    }

    public static function registerModuleInstaller(callable $installer): void
    {
        if (self::$frozen) {
            throw new \LogicException('CashierV3Bootstrap 已 freeze，禁止注册 module installer');
        }
        self::$moduleInstallers[] = $installer;
    }

    public static function dispatcher(): CashierV3ActionDispatcher
    {
        if (self::$dispatcher !== null) {
            return self::$dispatcher;
        }
        $dispatcher = self::composeProductionGraph();
        $dispatcher->eventConsumerRegistry()->freeze();
        $check = self::selfCheck($dispatcher);
        self::$lastSelfCheck = $check;
        if (empty($check['ok'])) {
            throw new \LogicException(
                'CashierV3Bootstrap selfCheck 失败: ' . implode('; ', (array)($check['problems'] ?? []))
            );
        }
        $dispatcher->freeze();
        self::$frozen = true;
        self::$dispatcher = $dispatcher;
        self::bindContainerSingletons($dispatcher);
        return self::$dispatcher;
    }

    public static function gateway(): CashierV3CommandGatewayServices
    {
        return self::dispatcher()->gateway();
    }

    public static function outbox(): CashierV3OutboxServices
    {
        self::dispatcher();
        if (!(self::$outbox instanceof CashierV3OutboxServices)) {
            throw new \LogicException('CashierV3Bootstrap: Outbox service 未就绪');
        }
        return self::$outbox;
    }

    public static function eventConsumerRegistry(): CashierV3EventConsumerRegistry
    {
        return self::dispatcher()->eventConsumerRegistry();
    }

    public static function actionActivationGate(): CashierV3ActionActivationGate
    {
        return self::dispatcher()->actionActivationGate();
    }

    public static function lastSelfCheck(): array
    {
        if (self::$lastSelfCheck === null) {
            self::dispatcher();
        }
        return self::$lastSelfCheck ?: [];
    }

    public static function isFrozen(): bool
    {
        return self::$frozen;
    }

    /**
     * 生产唯一对象图组装。禁止外部再 new 第二套。
     */
    private static function composeProductionGraph(): CashierV3ActionDispatcher
    {
        $problems = CashierV3ActionManifest::selfCheck();
        $problems = array_merge($problems, CashierV3ResourceKindCatalog::selfCheck());
        if ($problems) {
            throw new \LogicException('CashierV3Bootstrap manifest/kind 自检失败: ' . implode('; ', $problems));
        }

        $employeeDataScope = app()->make(EmployeeDataScopeServices::class);
        if (!$employeeDataScope instanceof EmployeeDataScopeServices) {
            throw new \LogicException('CashierV3Bootstrap: EmployeeDataScopeServices 注入失败');
        }

        $readiness = new CashierV3TableReadinessGuard();
        $featureResolver = new CashierV3FeatureResolver();
        $selectorGrants = new CashierV3SelectorGrantServices();
        $permissionPolicies = new CashierV3PermissionPolicyRegistry($selectorGrants);
        $permissionGuard = new CashierV3PermissionGuard($permissionPolicies);
        $dataScopeFactory = new CashierV3DataScopeFactory($featureResolver, $employeeDataScope);
        $permissionSnapshots = new CashierV3PermissionSnapshotServices($dataScopeFactory);

        $handlers = new CashierV3HandlerRegistry();
        $policies = new CashierV3ContextPolicyRegistry(false);
        $scopeResolver = new CashierV3ScopeResolver();
        $versionServices = new CashierV3ResourceVersionServices($scopeResolver);
        $versionServices->registerProvider(
            CashierV3CheckoutRequestVersionProvider::KIND,
            new CashierV3CheckoutRequestVersionProvider()
        );
        $consumerRegistry = new CashierV3EventConsumerRegistry();
        $versionServices->registerProvider(
            CashierV3RechargeCheckoutRequestVersionProvider::KIND,
            new CashierV3RechargeCheckoutRequestVersionProvider()
        );
        $consumerRegistry->register(
            'cashier_v3.direct_gift.reconcile',
            new CashierV3DirectGiftReconciliationConsumer()
        );
        $activationGate = new CashierV3ActionActivationGate($consumerRegistry);
        $outbox = new CashierV3OutboxServices($consumerRegistry);

        $keyServices = app()->make(\app\services\cashier\v3\CashierV3IdempotencyKeyServices::class);
        $contextServices = app()->make(\app\services\cashier\v3\CashierV3CommandContextServices::class);
        $stateContexts = new CashierV3StateContextServices($keyServices);
        $gateway = new CashierV3CommandGatewayServices(
            $keyServices,
            $contextServices,
            $versionServices,
            $stateContexts,
            $policies
        );
        $gateway->setReadinessGuard($readiness);
        $gateway->setEventServices(
            new CashierV3BusinessEventRecorder(),
            new CashierV3EventOutboxReadinessGuard()
        );
        $gateway->setActionActivationGate($activationGate);
        $gateway->setCheckoutSourceLoader(new CashierV3CheckoutSourceAuthorityLoader());
        $gateway->setCheckoutResourcePlanServices(
            new CashierV3CheckoutResourcePlanLoader(),
            new ThinkPhpCashierV3CheckoutResourcePlanRepository()
        );
        $gateway->bindSharedServices($versionServices, null, $scopeResolver, $policies);
        $gateway->setPermissionServices($permissionGuard, $permissionSnapshots, $dataScopeFactory);
        self::$gateway = $gateway;
        self::$outbox = $outbox;

        $assembler = new CashierV3RootDomainAssembler($versionServices);
        $rootProjector = new CashierV3RootProjector($stateContexts);
        $rootProjector->setAssembler($assembler);

        // C1 工作台 bootstrap／context-switch 投影
        $handlers->registerProjection('open-cashier-workbench', function (array $scope) use ($rootProjector, $stateContexts) {
            $operatorScope = $scope['operator_scope'];
            $dataScope = $scope['data_scope'];
            $stateContextId = (string)($scope['state_context_id'] ?? '');
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $serverEpoch = isset($payload['contextSwitchEpoch']) ? (0 + $payload['contextSwitchEpoch']) : null;
            $clientToken = trim((string)($payload['contextSwitchToken'] ?? $payload['context_switch_token'] ?? ''));
            // 服务端单独签发一次性 token；客户端 nonce 只用于把响应绑定到本次意图。
            $serverToken = '';
            if ($clientToken !== '') {
                try {
                    $serverToken = bin2hex(random_bytes(16));
                } catch (\Throwable $e) {
                    throw new \LogicException('CashierV3Bootstrap: 无法签发 context switch token');
                }
            }
            $issued = [
                'serverIssued' => true,
                'storeId' => $operatorScope->storeId(),
                'operatorId' => $operatorScope->operatorId(),
                'stateContextId' => $stateContextId,
                'visibleStoreIds' => $dataScope->visibleStoreIds(),
                'authorizationMode' => $dataScope->authorizationMode(),
                'permissionVersion' => $dataScope->permissionVersion(),
                // 轻量 bootstrap 尚未返回完整根投影时，也必须带回服务端已解析
                // 的门店端功能码；客户端只把它用于当前会话的页面可见性。
                'features' => array_values($dataScope->grantedFeatures()),
            ];
            $result = [
                'message' => 'ok',
                'data' => [
                    'bootstrap' => $issued,
                    'workbenchReady' => $rootProjector->isReadyForFullRoot(),
                ],
                'return_root_state' => $rootProjector->isReadyForFullRoot(),
            ];
            if ($serverEpoch !== null && $serverToken !== '' && $clientToken !== '') {
                $result['context_switch'] = [
                    'epoch' => $serverEpoch,
                    'token' => $serverToken,
                    'clientToken' => $clientToken,
                    'serverBound' => true,
                ];
            }
            return $result;
        });

        $dispatcher = new CashierV3ActionDispatcher(
            $gateway,
            $handlers,
            $policies,
            $permissionGuard,
            $scopeResolver,
            $stateContexts,
            $dataScopeFactory,
            $activationGate,
            $rootProjector,
            $readiness,
            $versionServices,
            null,
            $featureResolver,
            $permissionPolicies,
            $permissionSnapshots
        );

        foreach (self::$moduleInstallers as $installer) {
            call_user_func($installer, $dispatcher, $assembler);
        }

        // 统一查询先接管 query-members；会员模块随后只补充选择器与建档能力。
        UnifiedQueryModule::install($dispatcher, $assembler);

        // C2 权益购物车与 C5 会员选择共用同一 workspace 草稿；先安装 C2，
        // 再把唯一草稿服务注入会员模块。未齐根分区仍保持 fail-closed。
        $cashierWorkspace = CashierV3CashierModule::install($dispatcher, $assembler);
        CashierV3CardOperationModule::install($dispatcher);
        CashierV3MemberModule::install($dispatcher, $assembler, $cashierWorkspace);
        CashierV3RechargeModule::install($dispatcher);
        CashierV3RechargeDebtRepaymentServices::install($dispatcher);
        CashierV3RechargeCheckoutModule::install($dispatcher);
        CashierV3HangModule::install($dispatcher, $cashierWorkspace, $assembler);
        CashierV3ReservationModule::install($dispatcher, $assembler);

        // C5-O1 只读销售订单查询在生产 composition root 显式接入。
        CashierV3OrderQueryModule::install($dispatcher, $assembler);

        // C4 经营看板读取 V3 事实与权威欠款映射，不回退旧报表聚合。
        CashierV3BusinessDashboardModule::install($dispatcher, $assembler);

        // 已实现的 cashier/orderCenter 继续使用真实 provider；其他尚未激活的
        // 领域只返回显式“未加载／未激活”结构，不将空列表冒充经营事实。
        CashierV3StructuredEmptyRootPartitionModule::install($assembler);

        return $dispatcher;
    }

    private static function bindContainerSingletons(CashierV3ActionDispatcher $dispatcher): void
    {
        $app = app();
        $gateway = $dispatcher->gateway();
        $activationGate = $dispatcher->actionActivationGate();
        $consumerRegistry = $dispatcher->eventConsumerRegistry();
        $outbox = self::$outbox;
        if (!$outbox instanceof CashierV3OutboxServices
            || $outbox->consumerRegistry() !== $consumerRegistry) {
            throw new \LogicException('CashierV3Bootstrap: Outbox 消费者目录对象不一致');
        }
        if (!is_object($app)) {
            throw new \LogicException('CashierV3Bootstrap: 容器不可用，禁止旁路运行');
        }
        if (method_exists($app, 'instance')) {
            $app->instance(CashierV3ActionDispatcher::class, $dispatcher);
            $app->instance(CashierV3CommandGatewayServices::class, $gateway);
            $app->instance(CashierV3ActionActivationGate::class, $activationGate);
            $app->instance(CashierV3EventConsumerRegistry::class, $consumerRegistry);
            $app->instance(CashierV3OutboxServices::class, $outbox);
        } elseif (method_exists($app, 'bindTo')) {
            $app->bindTo(CashierV3ActionDispatcher::class, $dispatcher);
            $app->bindTo(CashierV3CommandGatewayServices::class, $gateway);
            $app->bindTo(CashierV3ActionActivationGate::class, $activationGate);
            $app->bindTo(CashierV3EventConsumerRegistry::class, $consumerRegistry);
            $app->bindTo(CashierV3OutboxServices::class, $outbox);
        } else {
            throw new \LogicException('CashierV3Bootstrap: 容器缺少 instance/bindTo，绑定失败 fail-closed');
        }

        // 绑定后立即校验对象同一性；失败不得继续运行
        $resolvedDispatcher = method_exists($app, 'make')
            ? $app->make(CashierV3ActionDispatcher::class)
            : null;
        $resolvedGateway = method_exists($app, 'make')
            ? $app->make(CashierV3CommandGatewayServices::class)
            : null;
        $resolvedActivationGate = method_exists($app, 'make')
            ? $app->make(CashierV3ActionActivationGate::class)
            : null;
        $resolvedConsumerRegistry = method_exists($app, 'make')
            ? $app->make(CashierV3EventConsumerRegistry::class)
            : null;
        $resolvedOutbox = method_exists($app, 'make')
            ? $app->make(CashierV3OutboxServices::class)
            : null;
        if ($resolvedDispatcher !== $dispatcher
            || $resolvedGateway !== $gateway
            || $resolvedActivationGate !== $activationGate
            || $resolvedConsumerRegistry !== $consumerRegistry
            || $resolvedOutbox !== $outbox) {
            throw new \LogicException(
                'CashierV3Bootstrap: 容器绑定后对象同一性校验失败，已 fail-closed'
            );
        }
    }

    /**
     * @return array{ok:bool,activated:string[],inactive:array,problems:string[]}
     */
    public static function selfCheck(CashierV3ActionDispatcher $dispatcher): array
    {
        $problems = [];
        $problems = array_merge($problems, CashierV3ActionManifest::selfCheck());
        $problems = array_merge($problems, CashierV3ResourceKindCatalog::selfCheck());

        if ($dispatcher->dataScopeFactory() === null) {
            $problems[] = 'data_scope_factory_missing';
        }
        if ($dispatcher->rootProjector() === null || !$dispatcher->rootProjector()->hasAssembler()) {
            $problems[] = 'root_assembler_missing';
        }
        if ($dispatcher->versionServices() === null) {
            $problems[] = 'version_services_missing';
        }
        if (!$dispatcher->eventConsumerRegistry()->isFrozen()) {
            $problems[] = 'event_consumer_registry_not_frozen';
        }
        if ($dispatcher->gateway()->actionActivationGate() !== $dispatcher->actionActivationGate()) {
            $problems[] = 'action_activation_gate_identity_mismatch';
        }
        if ($dispatcher->lockServices() !== null
            && $dispatcher->lockServices()->deferredReason() === '') {
            $problems[] = 'parallel_lock_contract_active';
        }

        $policyActions = $dispatcher->policies()->registeredActions();
        $permissionGuard = $dispatcher->permissionGuard();
        $activated = [];
        $inactive = [];
        $activeMatrix = [];

        foreach (CashierV3ActionManifest::commandActions() as $action => $def) {
            $missing = [];
            $hasCommandHandler = $dispatcher->handlers()->hasCommand($action);
            $activationProblems = [];
            try {
                $activationInspection = $dispatcher->actionActivationGate()->inspect($def, $action);
                $eventContract = $activationInspection['contract'];
                $activationProblems = $activationInspection['problems'];
                if ($eventContract['required_event_types'] && !$dispatcher->gateway()->eventServicesReady()) {
                    $missing[] = 'event_services';
                }
                if ($hasCommandHandler && $activationProblems) {
                    $missing = array_merge($missing, $activationProblems);
                    foreach ($activationProblems as $activationProblem) {
                        $problems[] = sprintf(
                            'command %s activation blocked: %s',
                            $action,
                            $activationProblem
                        );
                    }
                }
            } catch (\Throwable $e) {
                $eventContract = [
                    'required_event_types' => [],
                    'activation_blocked_until_event_contract' => false,
                    'consumers' => [],
                ];
                $missing[] = 'event_contract';
                if ($hasCommandHandler) {
                    $problems[] = sprintf('command %s activation contract invalid', $action);
                }
            }
            $matrix = [
                'action' => $action,
                'canonical' => (string)($def['canonical'] ?? $action),
                'type' => (string)($def['type'] ?? ''),
                'owner' => (string)($def['owner'] ?? ''),
                'handler' => false,
                'context_policy' => false,
                'permission_policy' => false,
                'static_providers' => [],
                'dynamic_roles' => [],
                'dynamic_kinds' => [],
                'dynamic_providers_ok' => true,
                'lock_contract' => 'version_services_lockAndAssert',
                'root_ready' => $dispatcher->rootProjector() && $dispatcher->rootProjector()->isReadyForFullRoot(),
                'root_partition_providers' => $dispatcher->rootProjector()
                    ? $dispatcher->rootProjector()->assemblerPartitionKeys()
                    : [],
                'event_contract' => $eventContract,
                'production_event_contract_ready' => empty($eventContract['activation_blocked_until_event_contract']),
                'event_consumers' => $eventContract['consumers'] ?? [],
                'event_consumers_ready' => !$activationProblems,
            ];
            if (!$hasCommandHandler) {
                $missing[] = 'command_handler';
            } else {
                $matrix['handler'] = true;
            }
            if (!in_array($action, $policyActions, true)) {
                $missing[] = 'context_policy';
            } else {
                $matrix['context_policy'] = true;
                $policy = $dispatcher->policies()->requirePolicy($action);
                $declaredDynamic = $policy->declaredDynamicDependencies();
                $matrix['dynamic_roles'] = $declaredDynamic['roles'];
                $matrix['dynamic_kinds'] = $declaredDynamic['kinds'];
                foreach ($declaredDynamic['kinds'] as $kind) {
                    if (CashierV3ResourceKindCatalog::isDomainOwned($kind)
                        && $dispatcher->scopeResolver()->providerFor($kind) === null) {
                        $missing[] = 'dynamic_provider:' . $kind;
                        $matrix['dynamic_providers_ok'] = false;
                    }
                }
            }
            $policyId = (string)($def['permissionPolicyId'] ?? '');
            if ($policyId === '' || !$permissionGuard->policies()->has($policyId)) {
                $missing[] = 'permission_policy';
            } else {
                $matrix['permission_policy'] = true;
            }
            $requiredKinds = $dispatcher->policies()->has($action)
                ? $dispatcher->policies()->requirePolicy($action)->staticRequiredKinds()
                : [];
            foreach ($requiredKinds as $kind) {
                if (CashierV3ResourceKindCatalog::isDomainOwned($kind)
                    && $dispatcher->scopeResolver()->providerFor($kind) === null) {
                    $missing[] = 'resource_provider:' . $kind;
                } else {
                    $matrix['static_providers'][] = $kind;
                }
            }
            if ($missing) {
                $inactive[$action] = array_values(array_unique($missing));
            } else {
                $activated[] = $action;
            }
            $activeMatrix[$action] = $matrix;
        }

        foreach (CashierV3ActionManifest::projectionActions() as $action => $def) {
            $missing = [];
            $hasProjection = $dispatcher->handlers()->hasProjection($action);
            $matrix = [
                'action' => $action,
                'canonical' => (string)($def['canonical'] ?? $action),
                'type' => (string)($def['type'] ?? ''),
                'owner' => (string)($def['owner'] ?? ''),
                'handler' => false,
                'permission_policy' => false,
                // 从真实注册结果导出，禁止硬编 true
                'projection_provider' => $hasProjection,
                'root_ready' => $dispatcher->rootProjector() && $dispatcher->rootProjector()->isReadyForFullRoot(),
                'root_partition_providers' => $dispatcher->rootProjector()
                    ? $dispatcher->rootProjector()->assemblerPartitionKeys()
                    : [],
                'lock_contract' => 'version_services_lockAndAssert',
            ];
            if (!$hasProjection) {
                $missing[] = 'projection_handler';
            } else {
                $matrix['handler'] = true;
            }
            $policyId = (string)($def['permissionPolicyId'] ?? '');
            if ($policyId === '' || !$permissionGuard->policies()->has($policyId)) {
                $missing[] = 'permission_policy';
            } else {
                $matrix['permission_policy'] = true;
            }
            if (!empty($def['requires_root_state'])) {
                if (!$dispatcher->rootProjector() || !$dispatcher->rootProjector()->isReadyForFullRoot()) {
                    $missing[] = 'root_assembler_ready';
                }
            }
            if ($missing) {
                $inactive[$action] = array_values(array_unique($missing));
            } else {
                $activated[] = $action;
            }
            $activeMatrix[$action] = $matrix;
        }

        $handlerDup = $dispatcher->handlers()->duplicateCheck();
        if ($handlerDup) {
            $problems = array_merge($problems, $handlerDup);
        }

        return [
            'ok' => $problems === [],
            'activated' => $activated,
            'inactive' => $inactive,
            'problems' => $problems,
            'registered_handlers' => $dispatcher->handlers()->registeredActions(),
            'lock_contract' => 'version_services_lockAndAssert',
            'root_full_ready' => $dispatcher->rootProjector()
                && $dispatcher->rootProjector()->isReadyForFullRoot(),
            'active_matrix' => $activeMatrix,
        ];
    }
}

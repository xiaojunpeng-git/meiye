<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

use think\facade\Log;

use app\services\BaseServices;
use app\services\cashier\v3\event\CashierV3ActionActivationGate;
use app\services\cashier\v3\event\CashierV3BusinessEventContractRegistry;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3EventOutboxReadinessGuard;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionAuthorityException;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\readiness\CashierV3TableReadinessGuard;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
use app\services\cashier\v3\settlement\CashierV3CheckoutResourcePlanRepository;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use think\facade\Db;

/**
 * 收银 V3 写命令统一网关。
 *
 * 执行顺序（任何一步失败都 fail-closed，不进入业务）：
 *  1. 表就绪检查——必须发生在触碰任何 state context、回执或版本表之前；
 *  2. 幂等键必须是已登记大写前缀 + 标准 UUID，长度不超过 128；
 *  3. 按 action + 规范化 payload 求解 contexts 执行合同；
 *  4. contexts 结构级严格校验，expectedVersion 必须 > 0；
 *  5. 服务端为每个对象解析 canonical scope（客户端不得提交 scope）；
 *  6. 解析工作台投影上下文；
 *  7. 单事务内：读回执（有则重放，不重做业务）→ 占位插入 → 按固定全序锁定并
 *     校验各对象版本 → 执行业务 → 校验 touched → 推进版本 → 回填回执。
 *
 * 幂等口径：
 * - 只有成功命令落回执。失败命令整事务回滚，回执占位行一并消失，重试可再次执行；
 * - 命中回执时返回第一次已确定的不可变业务结果，不重做业务、不重复推进资源版本；
 * - 重放**默认不返回 state、不签发 stateRevision、不返回 versions**：
 *   第一次成功后权威数据可能又变了，把当时的版本冒充「当前版本」会让前端
 *   拿着过期版本继续提交。调用方确实需要完整根 state 时，由 dispatcher 调用
 *   当前权威投影器重新组合，再签发本工作台的新 revision。
 */
class CashierV3CommandGatewayServices extends BaseServices
{
    public const RECEIPT_TABLE = 'cashier_v3_command_receipt';

    public const RECEIPT_STATUS_SUCCEEDED = 1;

    /**
     * 顶层传输／信封元数据：只在顶层剥离一次，不递归删除业务对象里的同名字段。
     * correlationId／correlation_id／returnCurrentState 不得进入业务 payload 哈希或 policy。
     */
    public const TRANSPORT_FIELDS = [
        'action',
        'command',
        'clientSessionId',
        'stateContextId',
        'correlationId',
        'correlation_id',
        'returnCurrentState',
        'contextSwitchEpoch',
        'contextSwitchToken',
        'context_switch_epoch',
        'context_switch_token',
        // ForceStoreSessionMiddleware injects the trusted session store into
        // every POST. It is transport context, never a command payload field.
        'store_id',
        'storeId',
        'selected_store_id',
        'current_store_id',
    ];

    /** @var CashierV3IdempotencyKeyServices */
    protected $keyServices;

    /** @var CashierV3CommandContextServices */
    protected $contextServices;

    /** @var CashierV3ResourceVersionServices */
    protected $versionServices;

    /** @var CashierV3StateContextServices */
    protected $stateContextServices;

    /** @var CashierV3ContextPolicyRegistry */
    protected $policyRegistry;

    /** @var CashierV3TableReadinessGuard|null */
    protected $readinessGuard;

    /** @var CashierV3ResourceLockServices|null */
    protected $lockServices;

    /** @var \app\services\cashier\v3\registry\CashierV3PermissionGuard|null */
    protected $permissionGuard;

    /** @var CashierV3PermissionSnapshotServices|null */
    protected $permissionSnapshots;

    /** @var CashierV3DataScopeFactory|null */
    protected $dataScopeFactory;

    /**
     * @var callable|null function(string $checkoutRequestId): array sources
     *
     * The loader is read-only. Follow-up checkout sources are discovered
     * before the canonical resource locks, then loaded again after every
     * declared context has been locked. The second read must match exactly.
     */
    protected $checkoutSourceLoader;

    /** @var callable|null function(string $checkoutRequestId): array */
    protected $checkoutResourcePlanLoader;

    /** @var CashierV3CheckoutResourcePlanRepository|null */
    protected $checkoutResourcePlanRepository;

    /** @var CashierV3BusinessEventRecorder|null */
    protected $eventRecorder;

    /** @var CashierV3EventOutboxReadinessGuard|null */
    protected $eventReadinessGuard;

    /** @var CashierV3ActionActivationGate|null */
    protected $actionActivationGate;

    public function __construct(
        CashierV3IdempotencyKeyServices $keyServices,
        CashierV3CommandContextServices $contextServices,
        CashierV3ResourceVersionServices $versionServices,
        CashierV3StateContextServices $stateContextServices,
        CashierV3ContextPolicyRegistry $policyRegistry
    ) {
        $this->keyServices = $keyServices;
        $this->contextServices = $contextServices;
        $this->versionServices = $versionServices;
        $this->stateContextServices = $stateContextServices;
        $this->policyRegistry = $policyRegistry;
    }

    public function setReadinessGuard(CashierV3TableReadinessGuard $guard): void
    {
        $this->readinessGuard = $guard;
    }

    public function setEventServices(
        CashierV3BusinessEventRecorder $recorder,
        CashierV3EventOutboxReadinessGuard $readinessGuard
    ): void {
        $this->eventRecorder = $recorder;
        $this->eventReadinessGuard = $readinessGuard;
    }

    public function setActionActivationGate(CashierV3ActionActivationGate $gate): void
    {
        $this->actionActivationGate = $gate;
    }

    public function actionActivationGate(): ?CashierV3ActionActivationGate
    {
        return $this->actionActivationGate;
    }

    public function eventServicesReady(): bool
    {
        return $this->eventRecorder instanceof CashierV3BusinessEventRecorder
            && $this->eventReadinessGuard instanceof CashierV3EventOutboxReadinessGuard;
    }

    public function setPermissionServices(
        \app\services\cashier\v3\registry\CashierV3PermissionGuard $permissionGuard,
        CashierV3PermissionSnapshotServices $permissionSnapshots,
        CashierV3DataScopeFactory $dataScopeFactory
    ): void {
        $this->permissionGuard = $permissionGuard;
        $this->permissionSnapshots = $permissionSnapshots;
        $this->dataScopeFactory = $dataScopeFactory;
    }

    /**
     * 测试／C2：注入从持久化 checkout_request 反推来源的加载器。
     * loader(string $id): array{sources:array<int,array{kind:string,id:string}>,version:int}
     */
    public function setCheckoutSourceLoader(callable $loader): void
    {
        $this->checkoutSourceLoader = $loader;
    }

    public function setCheckoutResourcePlanServices(
        callable $loader,
        CashierV3CheckoutResourcePlanRepository $repository
    ): void {
        $this->checkoutResourcePlanLoader = $loader;
        $this->checkoutResourcePlanRepository = $repository;
    }

    /**
     * Composition Root 绑定同一对象图实例，禁止 gateway 与 dispatcher 各持一套。
     */
    public function bindSharedServices(
        CashierV3ResourceVersionServices $versionServices,
        ?CashierV3ResourceLockServices $lockServices,
        CashierV3ScopeResolver $scopeResolver,
        CashierV3ContextPolicyRegistry $policies
    ): void {
        $this->versionServices = $versionServices;
        if ($lockServices !== null && $lockServices->deferredReason() === '') {
            throw new \LogicException('禁止绑定未 deferred 的平行 ResourceLockServices');
        }
        $this->lockServices = $lockServices;
        $this->policyRegistry = $policies;
    }

    /**
     * 执行一条写命令。
     *
     * @param string $canonicalAction 规范 action（别名已在 dispatcher 映射）
     * @param array  $command ['idempotencyKey'=>..,'contexts'=>[..]]
     * @param array  $payload 规范化业务参数（与哈希、领域处理器同一份）
     * @param CashierV3OperatorScope $operatorScope 后端强制操作范围
     * @param array  $session ['client_session_id','state_context_id','operator_ip']
     * @param callable $business function(array $scope): array{data:array,message?:string,business_no?:string,touched:string[]}
     * @return array
     */
    public function execute(
        string $canonicalAction,
        array $command,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        array $session,
        callable $business,
        CashierV3DataScopeContext $dataScope = null,
        array $actionDefinition = []
    ): array {
        $this->assertTablesReady();

        $actionDefinition = $this->authoritativeActionDefinition(
            $canonicalAction,
            $actionDefinition
        );
        if ($this->actionActivationGate === null) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '操作激活门禁未就绪，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'action_activation_gate_missing']
            );
        }
        $eventContract = $this->actionActivationGate->assertExecutable(
            $actionDefinition,
            $canonicalAction
        );
        if ($eventContract['required_event_types']) {
            if ($this->eventRecorder === null || $this->eventReadinessGuard === null) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::EVENT_OUTBOX_NOT_READY,
                    '业务事件底座尚未就绪，请联系管理员完成升级后再试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $canonicalAction, 'reason' => 'event_services_missing']
                );
            }
            $this->eventReadinessGuard->assertReadyForAction($actionDefinition);
        }

        $idempotencyKey = $this->keyServices->normalizeIdempotencyKey(
            (string)($command['idempotencyKey'] ?? $command['idempotency_key'] ?? '')
        );

        if (isset($command['context'])) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象版本格式无效，请刷新当前工作台后重试。',
                ['action' => $canonicalAction, 'reason' => 'single_context_not_supported']
            );
        }
        $rawContexts = isset($command['contexts']) && is_array($command['contexts']) ? $command['contexts'] : [];

        // 唯一规范化：policy／permission／handler 共用；原始 payload 不进 handler
        $normalizedPack = CashierV3RequestNormalizer::normalize($canonicalAction, $payload);
        $payload = $normalizedPack['normalized'];

        $stateContext = $this->stateContextServices->resolve(
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            (string)($session['client_session_id'] ?? ''),
            (string)($session['state_context_id'] ?? '')
        );

        $policySession = [
            'store_id' => $operatorScope->storeId(),
            'operator_id' => $operatorScope->operatorId(),
            'state_context_id' => $stateContext['state_context_id'],
            'workspace_id' => sprintf(
                'ws:%d:%d:%s',
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                $stateContext['state_context_id']
            ),
        ];
        $contract = $this->policyRegistry->requirePolicy($canonicalAction)->resolve($payload, $policySession);
        if (!empty($contract['normalized_payload']) && is_array($contract['normalized_payload'])) {
            $payload = $contract['normalized_payload'];
        }
        $contexts = $this->contextServices->validate($rawContexts, $contract);

        $requestHash = $this->hashRequest($canonicalAction, $payload);
        $contextsHash = $this->contextServices->fingerprint($contexts);
        $operatorProfile = is_array($session['operator_profile'] ?? null) ? $session['operator_profile'] : [];
        $correlationId = trim((string)($session['correlation_id'] ?? $command['correlationId'] ?? ''));

        return Db::transaction(function () use (
            $canonicalAction,
            $idempotencyKey,
            $contexts,
            $contract,
            $operatorScope,
            $stateContext,
            $requestHash,
            $contextsHash,
            $session,
            $payload,
            $business,
            $dataScope,
            $actionDefinition,
            $eventContract,
            $operatorProfile,
            $rawContexts,
            $correlationId
        ) {
            // 1) 先按幂等键锁定回执
            $existing = Db::name(self::RECEIPT_TABLE)
                ->where('idempotency_key', $idempotencyKey)
                ->lock(true)
                ->find();

            // 2) 事务内锁定收银账号权威并重建 operatorProfile／DataScope（禁止复用事务外旧快照）
            if ($this->permissionSnapshots === null || $this->permissionGuard === null) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                    '权限服务未就绪，请联系管理员。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $canonicalAction]
                );
            }

            try {
                $permPack = $this->permissionSnapshots->lockAndBuild($operatorScope, $operatorProfile);
            } catch (CashierV3CommandException $permEx) {
                // 已有成功回执：撤权／停用／解绑时不泄露历史业务 data，只返回最小结果
                if ($existing
                    && $permEx->getResultCode() === CashierV3ResultCode::PERMISSION_DENIED) {
                    return [
                        'status' => CashierV3ResultCode::STATUS_FAILED,
                        'code' => CashierV3ResultCode::PERMISSION_DENIED,
                        'message' => '当前账号已无该操作权限，历史结果不可查看。',
                        'data' => [],
                        'business_no' => '',
                        'replay' => true,
                        'permission_denied_on_replay' => true,
                        'deny_reason' => (string)($permEx->getDetail()['reason'] ?? $permEx->getResultCode()),
                        'state_context_id' => $stateContext['state_context_id'],
                        'context_changed' => (bool)$stateContext['context_changed'],
                        'versions' => [],
                        'data_scope' => null,
                        'correlation_id' => $correlationId,
                        'idempotency_key' => $idempotencyKey,
                    ];
                }
                throw $permEx;
            }
            $txnDataScope = $permPack['data_scope'];
            $operatorProfile = $permPack['operator_profile'];

            if ($existing) {
                $replayContextsHash = $contextsHash;
                if (!empty($contract['expand_from_checkout_resource_plan'])) {
                    $replayContextsHash = $this->resourcePlanReplayContextsHash(
                        $existing,
                        $contexts,
                        $canonicalAction
                    );
                }
                return $this->replay(
                    $existing,
                    $canonicalAction,
                    $operatorScope,
                    $requestHash,
                    $replayContextsHash,
                    $stateContext,
                    $txnDataScope,
                    $actionDefinition,
                    $payload,
                    $correlationId
                );
            }

            if ($actionDefinition) {
                $this->permissionGuard->assertAllowed($actionDefinition, $txnDataScope, $payload);
            }

            // 3) Cart mutations and checkout preparation may depend on resources
            // that the client must not enumerate. Discover them from server
            // authorities before any business resource is locked.
            if (!empty($contract['expand_from_server_resource_discovery'])) {
                $expanded = $this->expandFromServerResourceDiscovery(
                    $contexts,
                    $contract,
                    $canonicalAction,
                    $payload,
                    $operatorScope,
                    $txnDataScope,
                    (string)$stateContext['state_context_id']
                );
                $contexts = $expanded['contexts'];
                $contract = $expanded['contract'];
            }

            // Final checkout accepts only the two client-visible contexts;
            // hidden resources come from the immutable server plan.
            if (!empty($contract['expand_from_checkout_resource_plan'])) {
                $expanded = $this->expandFollowUpFromCheckoutResourcePlan(
                    (string)$contract['checkout_request_id'],
                    $contexts,
                    $contract,
                    $canonicalAction,
                    $txnDataScope
                );
                $contexts = $expanded['contexts'];
                $contract = $expanded['contract'];
            } elseif (!empty($contract['expand_from_checkout_request'])) {
                // 其它结账后续仍只从持久化 checkout_request 反推导航来源。
                $expanded = $this->expandFollowUpFromCheckoutRequest(
                    (string)$contract['checkout_request_id'],
                    $contexts,
                    $rawContexts,
                    $canonicalAction,
                    $contract
                );
                $contexts = $expanded['contexts'];
                $contract = $expanded['contract'];
            }

            // 4) 同一事务内：对象归属 + DataScope 授权
            $contexts = $this->versionServices->scopeResolver()->attachScopes(
                $contexts,
                $operatorScope,
                $txnDataScope
            );
            foreach ($contexts as &$ctx) {
                $ctx['data_scope'] = $txnDataScope;
            }
            unset($ctx);
            if (!empty($contract['server_checkout_resource_plan'])) {
                $this->assertCheckoutResourcePlanScopes($contexts, $contract['server_checkout_resource_plan']);
                $contextsHash = $this->effectiveCheckoutResourcePlanContextsHash(
                    $contexts,
                    $contract['server_checkout_resource_plan']
                );
            }

            $now = time();
            $placeholder = [
                'idempotency_key' => $idempotencyKey,
                'action' => $canonicalAction,
                'store_id' => $operatorScope->storeId(),
                'operator_id' => $operatorScope->operatorId(),
                'state_context_id' => $stateContext['state_context_id'],
                'request_hash' => $requestHash,
                'contexts_hash' => $contextsHash,
                'contexts_json' => $this->encodeJson($this->exportContexts($contexts)),
                'status' => 0,
                'result_code' => '',
                'result_message' => '',
                'result_json' => '',
                'business_no' => '',
                'operator_ip' => mb_substr((string)($session['operator_ip'] ?? ''), 0, 64),
                'add_time' => $now,
                'finish_time' => 0,
            ];
            try {
                Db::name(self::RECEIPT_TABLE)->insert($placeholder);
            } catch (\Throwable $exception) {
                $existing = Db::name(self::RECEIPT_TABLE)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lock(true)
                    ->find();
                if (!$existing) {
                    throw $exception;
                }
                return $this->replay(
                    $existing,
                    $canonicalAction,
                    $operatorScope,
                    $requestHash,
                    $contextsHash,
                    $stateContext,
                    $txnDataScope,
                    $actionDefinition,
                    $payload,
                    $correlationId
                );
            }

            $lockedVersions = $this->versionServices->lockAndAssert($contexts);
            if (!empty($contract['server_resource_discovery_recheck_required'])) {
                $contract = $this->revalidateServerResourceDiscovery(
                    $contexts,
                    $contract,
                    $canonicalAction,
                    $payload,
                    $operatorScope,
                    $txnDataScope,
                    (string)$stateContext['state_context_id']
                );
            }
            if (!empty($contract['checkout_resource_plan_recheck_required'])) {
                $contract = $this->revalidateFollowUpCheckoutResourcePlan(
                    (string)$contract['checkout_request_id'],
                    $contract,
                    $canonicalAction
                );
            } elseif (!empty($contract['checkout_source_recheck_required'])) {
                $contract = $this->revalidateFollowUpCheckoutSources(
                    (string)$contract['checkout_request_id'],
                    $contract,
                    $canonicalAction
                );
            }
            // A final checkout handler must advance checkout_request N -> N+1.
            // Consume the immutable plan while the request is still at N; the
            // caller-owned transaction rolls this marker back if any later
            // order, payment, event, fact, version or receipt write fails.
            if (!empty($contract['server_checkout_resource_plan'])) {
                $this->consumeCheckoutResourcePlanInTx(
                    (string)$contract['checkout_request_id'],
                    $contract['server_checkout_resource_plan'],
                    $txnDataScope
                );
            }
            $eventExecution = null;
            if ($this->eventRecorder !== null) {
                $eventExecution = $this->eventRecorder->newExecution(
                    $canonicalAction,
                    $idempotencyKey,
                    $operatorScope,
                    $txnDataScope,
                    (string)$stateContext['state_context_id']
                );
            }
            try {
                $result = $business([
                    'action' => $canonicalAction,
                    'contexts' => $contexts,
                    'locked_versions' => $lockedVersions,
                    'payload' => $payload,
                    'idempotency_key' => $idempotencyKey,
                    'operator_scope' => $operatorScope,
                    'data_scope' => $txnDataScope,
                    'state_context_id' => $stateContext['state_context_id'],
                    'correlation_id' => $correlationId,
                    'checkout_sources' => $contract['server_checkout_sources'] ?? [],
                    'checkout_resource_plan' => $contract['server_checkout_resource_plan'] ?? [],
                    'server_resource_discovery' => $contract['server_resource_discovery'] ?? [],
                    'effective_contexts_hash' => $contextsHash,
                    'event_recorder' => $this->eventRecorder,
                    'event_execution' => $eventExecution,
                    'event_contract' => $eventContract,
                ]);

                $normalized = $this->normalizeBusinessResult(
                    $result,
                    $canonicalAction,
                    $lockedVersions,
                    $contexts,
                    $contract
                );
                if ($eventExecution !== null) {
                    $this->eventRecorder->assertRequiredPersistedInTx($eventExecution, $eventContract);
                }
                $newVersionsInternal = $this->versionServices->bumpTouched(
                    $contexts,
                    $canonicalAction,
                    $normalized['touched_keys'],
                    $lockedVersions
                );
                $newVersions = self::toPublicVersions($newVersionsInternal, $contexts);

                $resultJson = $this->encodeJson([
                    'message' => $normalized['message'],
                    'data' => $normalized['data'],
                    'committed_versions' => $newVersionsInternal,
                ]);

                $affected = Db::name(self::RECEIPT_TABLE)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('status', 0)
                    ->update([
                        'status' => self::RECEIPT_STATUS_SUCCEEDED,
                        'result_code' => '',
                        'result_message' => mb_substr($normalized['message'], 0, 255),
                        'result_json' => $resultJson,
                        'business_no' => mb_substr($normalized['business_no'], 0, 64),
                        'finish_time' => time(),
                    ]);
                if ((int)$affected !== 1) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '本次操作结果写入失败，已回滚，请重试。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['action' => $canonicalAction]
                    );
                }

                return [
                    'status' => CashierV3ResultCode::STATUS_SUCCESS,
                    'code' => '',
                    'message' => $normalized['message'],
                    'data' => $normalized['data'],
                    'business_no' => $normalized['business_no'],
                    'replay' => false,
                    'state_context_id' => $stateContext['state_context_id'],
                    'context_changed' => (bool)$stateContext['context_changed'],
                    'versions' => $newVersions,
                    'idempotency_key' => $idempotencyKey,
                    'data_scope' => $txnDataScope,
                    'correlation_id' => $correlationId,
                ];
            } finally {
                if ($eventExecution instanceof CashierV3BusinessEventExecution) {
                    $eventExecution->close();
                }
            }
        });
    }

    /**
     * Expand client-visible contexts with an exact resource set discovered by
     * server authority. Discovery is read-only and happens before any business
     * resource lock; the same discoverer is called after all locks and must
     * return the identical normalized set.
     *
     * @return array{contexts:array,contract:array}
     */
    protected function expandFromServerResourceDiscovery(
        array $validatedContexts,
        array $baseContract,
        string $canonicalAction,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $stateContextId
    ): array {
        $discoverer = $baseContract['server_resource_discoverer'] ?? null;
        if (!is_callable($discoverer)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '本次操作的资源发现服务尚未就绪，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'server_resource_discoverer_missing']
            );
        }

        $discovery = $this->callServerResourceDiscoverer(
            $discoverer,
            'discover',
            $canonicalAction,
            $payload,
            $validatedContexts,
            $operatorScope,
            $dataScope,
            $stateContextId,
            (bool)($baseContract['allow_empty_server_resource_discovery'] ?? false)
        );
        $resources = $discovery['resources'];

        $contextsByPhysical = [];
        $roleOwners = [];
        foreach ($validatedContexts as $context) {
            $physical = (string)$context['kind'] . ':' . (string)$context['id'];
            $role = trim((string)($context['role'] ?? $context['kind']));
            $context['roles'] = $role !== '' ? [$role] : [];
            $contextsByPhysical[$physical] = $context;
            if ($role !== '') {
                $roleOwners[$role] = $physical;
            }
        }

        $identities = array_values((array)($baseContract['identities'] ?? []));
        $readRoles = array_values((array)($baseContract['required_read_roles'] ?? []));
        $touchedRoles = array_values((array)($baseContract['required_touched_roles'] ?? []));
        foreach ($resources as $resource) {
            $physical = $resource['kind'] . ':' . $resource['id'];
            foreach ($resource['roles'] as $role) {
                if (isset($roleOwners[$role]) && $roleOwners[$role] !== $physical) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作的服务端资源角色发生冲突，请刷新后重试。',
                        [
                            'action' => $canonicalAction,
                            'reason' => 'server_resource_role_conflict',
                            'role' => $role,
                        ]
                    );
                }
                $roleOwners[$role] = $physical;
                $readRoles[] = $role;
                if ($resource['accessMode'] === 'mutate') {
                    $touchedRoles[] = $role;
                }
                $identities[] = [
                    'role' => $role,
                    'kind' => $resource['kind'],
                    'id' => $resource['id'],
                    'required' => true,
                ];
            }

            if (isset($contextsByPhysical[$physical])) {
                if ((int)$contextsByPhysical[$physical]['expected_version'] !== $resource['expectedVersion']) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作的资源版本不一致，请刷新后重试。',
                        [
                            'action' => $canonicalAction,
                            'reason' => 'server_resource_duplicate_version_mismatch',
                            'kind' => $resource['kind'],
                            'id' => $resource['id'],
                        ]
                    );
                }
                $roles = array_values(array_unique(array_merge(
                    (array)($contextsByPhysical[$physical]['roles'] ?? []),
                    $resource['roles']
                )));
                sort($roles, SORT_STRING);
                $contextsByPhysical[$physical]['roles'] = $roles;
                continue;
            }
            $contextsByPhysical[$physical] = [
                'kind' => $resource['kind'],
                'id' => $resource['id'],
                'expected_version' => $resource['expectedVersion'],
                'roles' => $resource['roles'],
                'server_resource_access_mode' => $resource['accessMode'],
                'server_resource_provider_contract_version' => $resource['providerContractVersion'],
                'server_resource_authority_fingerprint' => $resource['authorityFingerprint'],
            ];
        }

        $contexts = $this->contextServices->sortForLocking(array_values($contextsByPhysical));
        $contract = $baseContract;
        $contract['required'] = array_values(array_unique(array_column($contexts, 'kind')));
        $contract['allowed'] = [];
        $contract['identities'] = $identities;
        $contract['required_read_roles'] = array_values(array_unique($readRoles));
        $contract['required_touched_roles'] = array_values(array_unique($touchedRoles));
        $contract['server_resource_discovery'] = $discovery;
        // 无目录资源（普通产品/项目）的命令已经在业务事务内锁定了
        // 权威商品/SKU joined 行，不再为一个空资源计划重复做 discovery
        // recheck；卡项仍保持完整资源重检。
        $contract['server_resource_discovery_recheck_required'] = count($resources) > 0;
        $contract['expand_from_server_resource_discovery'] = false;
        return ['contexts' => $contexts, 'contract' => $contract];
    }

    protected function revalidateServerResourceDiscovery(
        array $lockedContexts,
        array $contract,
        string $canonicalAction,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $stateContextId
    ): array {
        $discoverer = $contract['server_resource_discoverer'] ?? null;
        $before = $contract['server_resource_discovery'] ?? null;
        if (!is_callable($discoverer) || !is_array($before)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的资源校验状态不完整，请刷新后重试。',
                ['action' => $canonicalAction, 'reason' => 'server_resource_recheck_state_missing']
            );
        }
        $after = $this->callServerResourceDiscoverer(
            $discoverer,
            'revalidate',
            $canonicalAction,
            $payload,
            $lockedContexts,
            $operatorScope,
            $dataScope,
            $stateContextId,
            (bool)($contract['allow_empty_server_resource_discovery'] ?? false)
        );
        if (!hash_equals((string)$before['fingerprint'], (string)$after['fingerprint'])) {
            Log::warning('[cashier_v3_resource_discovery_drift] ' . json_encode([
                'action' => $canonicalAction,
                'before' => $before['resources'] ?? [],
                'after' => $after['resources'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            throw CashierV3CommandException::versionConflict(
                '本次操作依赖的商品或权益刚刚发生变化，请刷新后重试。',
                [
                    'action' => $canonicalAction,
                    'reason' => 'server_resource_discovery_drift',
                    'before' => (string)$before['fingerprint'],
                    'after' => (string)$after['fingerprint'],
                ]
            );
        }
        $contract['server_resource_discovery'] = $after;
        $contract['server_resource_discovery_recheck_required'] = false;
        return $contract;
    }

    /** @return array{contractVersion:string,resources:array,fingerprint:string} */
    protected function callServerResourceDiscoverer(
        callable $discoverer,
        string $phase,
        string $canonicalAction,
        array $payload,
        array $contexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $stateContextId,
        bool $allowEmpty = false
    ): array {
        try {
            $raw = call_user_func($discoverer, [
                'phase' => $phase,
                'action' => $canonicalAction,
                'payload' => $payload,
                'contexts' => $contexts,
                'operator_scope' => $operatorScope,
                'data_scope' => $dataScope,
                'state_context_id' => $stateContextId,
            ]);
        } catch (CashierV3CommandException $exception) {
            throw $exception;
        } catch (CashierV3EntitlementCompletionAuthorityException $exception) {
            $reason = $exception->reason();
            $detail = $exception->detail();
            $detail['action'] = $canonicalAction;
            $detail['phase'] = $phase;
            $detail['reason'] = $reason;
            if ($reason === 'authority_service_intent_craftsman_required') {
                throw CashierV3CommandException::invalidContext(
                    '请先为本次服务选择手艺人后再确认完成服务。',
                    $detail
                );
            }
            throw $exception;
        } catch (\Throwable $exception) {
            // Keep the log actionable without persisting a full client payload
            // or member data. Request identity and context metadata are enough
            // to correlate this failure with the immutable checkout draft.
            Log::error('[cashier_v3_resource_discovery] ' . json_encode([
                'action' => $canonicalAction,
                'phase' => $phase,
                'checkoutRequestId' => $this->discoveryCheckoutRequestId($payload),
                'checkoutRequestVersion' => $this->discoveryCheckoutRequestVersion($payload),
                'stateContextId' => $stateContextId,
                'inputContexts' => $this->discoveryContextMetadata($contexts),
                'exceptionClass' => get_class($exception),
                'exceptionMessage' => $exception->getMessage(),
                'exceptionFile' => basename($exception->getFile()),
                'exceptionLine' => $exception->getLine(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '本次操作的资源发现失败，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'action' => $canonicalAction,
                    'phase' => $phase,
                    'reason' => 'server_resource_discovery_failed',
                ]
            );
        }
        if (!is_array($raw) || !isset($raw['resources']) || !is_array($raw['resources'])) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的服务端资源集合不完整，请刷新后重试。',
                ['action' => $canonicalAction, 'phase' => $phase, 'reason' => 'server_resource_pack_invalid']
            );
        }
        $resources = $this->normalizeServerDiscoveredResources(
            $raw['resources'],
            $canonicalAction,
            $phase
        );
        if (!$resources && !$allowEmpty) {
            throw CashierV3CommandException::invalidContext(
                '本次操作没有找到可校验的服务端资源，请刷新后重试。',
                ['action' => $canonicalAction, 'phase' => $phase, 'reason' => 'server_resource_set_empty']
            );
        }
        return [
            'contractVersion' => 'cashier-v3-server-resource-discovery-v1',
            'resources' => $resources,
            'fingerprint' => hash('sha256', $this->encodeJson($resources)),
        ];
    }

    private function discoveryCheckoutRequestId(array $payload): string
    {
        $value = trim((string)($payload['checkoutRequestId'] ?? ''));
        return preg_match('/^CKR-[0-9a-f]{40}$/D', $value) === 1 ? $value : '';
    }

    private function discoveryCheckoutRequestVersion(array $payload): int
    {
        $value = $payload['checkoutRequestVersion'] ?? null;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int)$value;
        }
        return is_int($value) && $value > 0 ? $value : 0;
    }

    private function discoveryContextMetadata(array $contexts): array
    {
        $metadata = [];
        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }
            $metadata[] = [
                'kind' => trim((string)($context['kind'] ?? '')),
                'id' => trim((string)($context['id'] ?? '')),
                'expectedVersion' => (int)($context['expected_version']
                    ?? $context['expectedVersion'] ?? 0),
            ];
        }
        return $metadata;
    }

    /** @return array<int,array> */
    protected function normalizeServerDiscoveredResources(
        array $rawResources,
        string $canonicalAction,
        string $phase
    ): array {
        if (array_keys($rawResources) !== ($rawResources ? range(0, count($rawResources) - 1) : [])
            || count($rawResources) > 10000) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的服务端资源数量无效，请刷新后重试。',
                ['action' => $canonicalAction, 'phase' => $phase, 'reason' => 'server_resource_count_invalid']
            );
        }
        $byPhysical = [];
        $roleOwners = [];
        foreach ($rawResources as $index => $raw) {
            if (!is_array($raw)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的服务端资源格式无效，请刷新后重试。',
                    ['action' => $canonicalAction, 'phase' => $phase, 'index' => $index]
                );
            }
            $kind = trim((string)($raw['kind'] ?? ''));
            $id = trim((string)($raw['id'] ?? ''));
            $version = $raw['expectedVersion'] ?? $raw['expected_version'] ?? $raw['version'] ?? null;
            $accessMode = trim((string)($raw['accessMode'] ?? 'read'));
            $rolesRaw = $raw['roles'] ?? (isset($raw['role']) ? [$raw['role']] : []);
            CashierV3ResourceKindCatalog::assertKnown($kind);
            if ($id === '' || strlen($id) > 64
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $id) !== 1
                || is_bool($version) || is_array($version) || !is_numeric($version)
                || (int)$version <= 0
                || !in_array($accessMode, ['read', 'mutate'], true)
                || !is_array($rolesRaw) || !$rolesRaw) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的服务端资源格式无效，请刷新后重试。',
                    [
                        'action' => $canonicalAction,
                        'phase' => $phase,
                        'index' => $index,
                        'reason' => 'server_resource_row_invalid',
                    ]
                );
            }
            $roles = [];
            foreach ($rolesRaw as $roleValue) {
                $role = trim((string)$roleValue);
                if ($role === '' || strlen($role) > 128
                    || preg_match('/^[A-Za-z0-9_.:-]+$/D', $role) !== 1) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作的服务端资源角色无效，请刷新后重试。',
                        ['action' => $canonicalAction, 'phase' => $phase, 'index' => $index]
                    );
                }
                $roles[$role] = $role;
            }
            $roles = array_values($roles);
            sort($roles, SORT_STRING);
            $physical = $kind . ':' . $id;
            foreach ($roles as $role) {
                if (isset($roleOwners[$role]) && $roleOwners[$role] !== $physical) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作的服务端资源角色重复，请刷新后重试。',
                        ['action' => $canonicalAction, 'phase' => $phase, 'role' => $role]
                    );
                }
                $roleOwners[$role] = $physical;
            }
            $providerContract = trim((string)($raw['providerContractVersion'] ?? 'server-authority-v1'));
            if ($providerContract === '' || strlen($providerContract) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $providerContract) !== 1) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的资源提供器版本无效，请刷新后重试。',
                    ['action' => $canonicalAction, 'phase' => $phase, 'index' => $index]
                );
            }
            $authorityFingerprint = trim((string)($raw['authorityFingerprint'] ?? ''));
            if ($authorityFingerprint === '') {
                $authorityFingerprint = hash('sha256', $kind . '|' . $id . '|' . (int)$version);
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $authorityFingerprint) !== 1) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的资源权威指纹无效，请刷新后重试。',
                    ['action' => $canonicalAction, 'phase' => $phase, 'index' => $index]
                );
            }
            $normalized = [
                'kind' => $kind,
                'id' => $id,
                'expectedVersion' => (int)$version,
                'roles' => $roles,
                'accessMode' => $accessMode,
                'providerContractVersion' => $providerContract,
                'authorityFingerprint' => $authorityFingerprint,
            ];
            if (isset($byPhysical[$physical])) {
                $existing = $byPhysical[$physical];
                foreach (['expectedVersion', 'providerContractVersion', 'authorityFingerprint'] as $field) {
                    if ($existing[$field] !== $normalized[$field]) {
                        throw CashierV3CommandException::invalidContext(
                            '本次操作的同一服务端资源不一致，请刷新后重试。',
                            [
                                'action' => $canonicalAction,
                                'phase' => $phase,
                                'kind' => $kind,
                                'id' => $id,
                                'field' => $field,
                            ]
                        );
                    }
                }
                $existing['roles'] = array_values(array_unique(array_merge($existing['roles'], $roles)));
                sort($existing['roles'], SORT_STRING);
                if ($accessMode === 'mutate') {
                    $existing['accessMode'] = 'mutate';
                }
                $byPhysical[$physical] = $existing;
                continue;
            }
            $byPhysical[$physical] = $normalized;
        }
        $resources = array_values($byPhysical);
        usort($resources, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $left['kind'],
                $left['id'],
                $right['kind'],
                $right['id']
            );
        });
        return $resources;
    }

    /**
     * Expand the two client-visible checkout contexts with one immutable,
     * server-built resource plan. No resource identity or version comes from
     * the client beyond workspace and checkout_request.
     *
     * @return array{contexts:array,contract:array}
     */
    protected function expandFollowUpFromCheckoutResourcePlan(
        string $checkoutRequestId,
        array $validatedContexts,
        array $baseContract,
        string $canonicalAction,
        CashierV3DataScopeContext $dataScope
    ): array {
        $plan = $this->loadCheckoutResourcePlan($checkoutRequestId, $canonicalAction);
        $checkoutContext = null;
        $contextsByPhysical = [];
        $roleOwners = [];
        foreach ($validatedContexts as $context) {
            $key = (string)$context['kind'] . ':' . (string)$context['id'];
            $role = (string)($context['role'] ?? $context['kind']);
            $context['roles'] = [$role];
            $context['resource_plan_access_mode'] = 'mutate';
            $contextsByPhysical[$key] = $context;
            $roleOwners[$role] = $key;
            if ($context['kind'] === 'checkout_request') {
                $checkoutContext = $context;
            }
        }
        if ($checkoutContext === null
            || (string)$checkoutContext['id'] !== $checkoutRequestId
            || (int)$checkoutContext['expected_version'] <= 0) {
            throw CashierV3CommandException::invalidContext(
                '结账请求版本无效，请刷新收银台后重试。',
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_request_context_invalid']
            );
        }

        $boundVersion = (int)($plan['boundRequestVersion'] ?? 0);
        if ((string)($plan['requestId'] ?? '') !== $checkoutRequestId
            || (string)($plan['requestStatus'] ?? '') !== 'ready_for_submit'
            || (int)($plan['requestVersion'] ?? 0) !== $boundVersion
            || $boundVersion !== (int)$checkoutContext['expected_version']) {
            throw CashierV3CommandException::invalidContext(
                '结账内容刚刚发生变化，请重新确认后再收款。',
                [
                    'action' => $canonicalAction,
                    'reason' => 'checkout_resource_plan_bound_version_mismatch',
                    'expectedVersion' => (int)$checkoutContext['expected_version'],
                    'boundRequestVersion' => $boundVersion,
                ]
            );
        }
        if (!hash_equals($dataScope->tenantId(), (string)($plan['tenantId'] ?? $dataScope->tenantId()))
            || $dataScope->forcedStoreId() !== (int)($plan['storeId'] ?? $dataScope->forcedStoreId())) {
            throw CashierV3CommandException::invalidContext(
                '结账资源不属于当前门店，请刷新收银台后重试。',
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_data_scope_mismatch']
            );
        }

        $resources = $plan['resources'] ?? null;
        if (!is_array($resources) || !$resources
            || count($resources) !== (int)($plan['resourceCount'] ?? -1)) {
            throw CashierV3CommandException::invalidContext(
                '结账资源计划不完整，请重新确认后再收款。',
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_resources_invalid']
            );
        }

        $readRoles = array_values((array)($baseContract['required_read_roles'] ?? []));
        $touchedRoles = array_values((array)($baseContract['required_touched_roles'] ?? []));
        $identities = array_values((array)($baseContract['identities'] ?? []));
        foreach ($resources as $index => $resource) {
            if (!is_array($resource)) {
                throw CashierV3CommandException::invalidContext(
                    '结账资源计划不完整，请重新确认后再收款。',
                    ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_resource_invalid', 'index' => $index]
                );
            }
            $kind = trim((string)($resource['kind'] ?? ''));
            $id = trim((string)($resource['id'] ?? ''));
            $expectedVersion = (int)($resource['expectedVersion'] ?? 0);
            $lockOrder = (int)($resource['lockOrder'] ?? 0);
            $scopeType = (string)($resource['scopeType'] ?? '');
            $scopeId = (string)($resource['scopeId'] ?? '');
            $accessMode = (string)($resource['accessMode'] ?? '');
            $roles = $resource['roles'] ?? null;
            CashierV3ResourceKindCatalog::assertKnown($kind);
            if ($id === '' || $expectedVersion <= 0
                || $lockOrder !== CashierV3ResourceKindCatalog::lockOrderOf($kind)
                || $scopeType !== CashierV3ResourceKindCatalog::scopeTypeOf($kind)
                || $scopeId === ''
                || !in_array($accessMode, ['read', 'mutate'], true)
                || !is_array($roles) || !$roles) {
                throw CashierV3CommandException::invalidContext(
                    '结账资源计划不完整，请重新确认后再收款。',
                    ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_resource_contract_invalid', 'index' => $index]
                );
            }
            $physical = $kind . ':' . $id;
            $normalizedRoles = [];
            foreach ($roles as $role) {
                $role = trim((string)$role);
                if ($role === '' || strlen($role) > 128
                    || preg_match('/^[A-Za-z0-9_.:-]+$/D', $role) !== 1) {
                    throw CashierV3CommandException::invalidContext(
                        '结账资源计划角色无效，请重新确认后再收款。',
                        ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_role_invalid', 'index' => $index]
                    );
                }
                if (isset($roleOwners[$role]) && $roleOwners[$role] !== $physical) {
                    throw CashierV3CommandException::invalidContext(
                        '结账资源计划角色冲突，请重新确认后再收款。',
                        ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_role_conflict', 'role' => $role]
                    );
                }
                $roleOwners[$role] = $physical;
                $normalizedRoles[$role] = $role;
                $readRoles[] = $role;
                if ($accessMode === 'mutate') {
                    $touchedRoles[] = $role;
                }
                $identities[] = [
                    'role' => $role,
                    'kind' => $kind,
                    'id' => $id,
                    'required' => true,
                ];
            }
            $normalizedRoles = array_values($normalizedRoles);
            sort($normalizedRoles, SORT_STRING);

            if (isset($contextsByPhysical[$physical])) {
                if ((int)$contextsByPhysical[$physical]['expected_version'] !== $expectedVersion) {
                    throw CashierV3CommandException::invalidContext(
                        '结账请求版本与服务端资源计划不一致，请刷新后重试。',
                        ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_duplicate_version_mismatch']
                    );
                }
                $normalizedRoles = array_values(array_unique(array_merge(
                    (array)($contextsByPhysical[$physical]['roles'] ?? []),
                    $normalizedRoles
                )));
                sort($normalizedRoles, SORT_STRING);
            }
            $contextsByPhysical[$physical] = array_merge(
                $contextsByPhysical[$physical] ?? [],
                [
                    'kind' => $kind,
                    'id' => $id,
                    'expected_version' => $expectedVersion,
                    'roles' => $normalizedRoles,
                    'resource_plan_access_mode' => $accessMode,
                    'resource_plan_scope_type' => $scopeType,
                    'resource_plan_scope_id' => $scopeId,
                    'resource_plan_provider_contract_version' => (string)($resource['providerContractVersion'] ?? ''),
                    'resource_plan_authority_fingerprint' => (string)($resource['authorityFingerprint'] ?? ''),
                    'resource_plan_row_fingerprint' => (string)($resource['rowFingerprint'] ?? ''),
                ]
            );
        }

        $contexts = $this->contextServices->sortForLocking(array_values($contextsByPhysical));
        $planFingerprint = (string)($plan['resourcePlanFingerprint'] ?? '');
        $planContractVersion = (string)($plan['planContractVersion'] ?? '');
        foreach ($contexts as &$context) {
            $context['checkout_resource_plan_fingerprint'] = $planFingerprint;
            $context['checkout_resource_plan_contract_version'] = $planContractVersion;
            $context['checkout_resource_plan_bound_request_version'] = $boundVersion;
        }
        unset($context);

        $contract = $baseContract;
        $contract['required'] = array_values(array_unique(array_column($contexts, 'kind')));
        $contract['allowed'] = [];
        $contract['identities'] = $identities;
        $contract['required_read_roles'] = array_values(array_unique($readRoles));
        $contract['required_touched_roles'] = array_values(array_unique($touchedRoles));
        $contract['server_checkout_resource_plan'] = $plan;
        $contract['checkout_resource_plan_recheck_required'] = true;
        $contract['expand_from_checkout_resource_plan'] = false;
        return ['contexts' => $contexts, 'contract' => $contract];
    }

    protected function loadCheckoutResourcePlan(string $checkoutRequestId, string $canonicalAction): array
    {
        if ($this->checkoutResourcePlanLoader === null
            || !($this->checkoutResourcePlanRepository instanceof CashierV3CheckoutResourcePlanRepository)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账资源计划尚未就绪，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_services_missing']
            );
        }
        try {
            $loaded = call_user_func($this->checkoutResourcePlanLoader, $checkoutRequestId);
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw CashierV3CommandException::invalidContext(
                '结账资源计划校验失败，请重新确认后再收款。',
                [
                    'action' => $canonicalAction,
                    'reason' => $exception->reason(),
                    'contractDetail' => $exception->detail(),
                ]
            );
        }
        if (!is_array($loaded) || !$loaded) {
            throw CashierV3CommandException::invalidContext(
                '结账内容尚未确认或已经变化，请重新确认后再收款。',
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_missing']
            );
        }
        return $loaded;
    }

    protected function assertCheckoutResourcePlanScopes(array $contexts, array $plan): void
    {
        $expected = [];
        foreach ((array)($plan['resources'] ?? []) as $resource) {
            if (is_array($resource)) {
                $expected[(string)$resource['kind'] . ':' . (string)$resource['id']] = [
                    'type' => (string)$resource['scopeType'],
                    'id' => (string)$resource['scopeId'],
                ];
            }
        }
        $seen = [];
        foreach ($contexts as $context) {
            $key = (string)$context['kind'] . ':' . (string)$context['id'];
            if (!isset($expected[$key])) {
                continue;
            }
            $scope = $context['scope'] ?? null;
            if (!($scope instanceof CashierV3ResourceScope)
                || $scope->type() !== $expected[$key]['type']
                || $scope->id() !== $expected[$key]['id']) {
                throw CashierV3CommandException::invalidContext(
                    '结账资源归属刚刚发生变化，请刷新后重试。',
                    ['reason' => 'checkout_resource_plan_scope_drift', 'resource' => $key]
                );
            }
            $seen[$key] = true;
        }
        if (count($seen) !== count($expected)) {
            throw CashierV3CommandException::invalidContext(
                '结账资源计划不完整，请刷新后重试。',
                ['reason' => 'checkout_resource_plan_scope_missing']
            );
        }
    }

    protected function effectiveCheckoutResourcePlanContextsHash(array $contexts, array $plan): string
    {
        $planFingerprint = (string)($plan['resourcePlanFingerprint'] ?? '');
        $planContract = (string)($plan['planContractVersion'] ?? '');
        $boundVersion = (int)($plan['boundRequestVersion'] ?? 0);
        if (preg_match('/^[a-f0-9]{64}$/D', $planFingerprint) !== 1
            || $planContract === '' || $boundVersion <= 0) {
            throw CashierV3CommandException::invalidContext(
                '结账资源计划指纹无效，请重新确认后再收款。',
                ['reason' => 'checkout_resource_plan_fingerprint_invalid']
            );
        }
        return hash(
            'sha256',
            $this->contextServices->fingerprint($contexts)
                . '|' . $planContract . '|' . $planFingerprint . '|' . $boundVersion
        );
    }

    protected function resourcePlanReplayContextsHash(
        array $existing,
        array $clientContexts,
        string $canonicalAction
    ): string {
        $stored = json_decode((string)($existing['contexts_json'] ?? ''), true);
        if (!is_array($stored) || !$stored) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '历史结账结果缺少资源快照，请联系管理员对账。',
                CashierV3ResultCode::STATUS_RESULT_UNKNOWN,
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_receipt_contexts_missing']
            );
        }
        $clientBase = [];
        foreach ($clientContexts as $context) {
            $clientBase[(string)$context['kind']] = [
                'id' => (string)$context['id'],
                'expected_version' => (int)$context['expected_version'],
            ];
        }
        $storedBase = [];
        $parts = [];
        $planFingerprint = '';
        $planContract = '';
        $boundVersion = 0;
        foreach ($stored as $context) {
            if (!is_array($context)) {
                throw CashierV3CommandException::invalidContext(
                    '历史结账资源快照无效，请联系管理员对账。',
                    ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_receipt_context_invalid']
                );
            }
            $kind = (string)($context['kind'] ?? '');
            $id = (string)($context['id'] ?? '');
            $version = (int)($context['expected_version'] ?? 0);
            $scope = (string)($context['scope'] ?? '');
            if ($kind === '' || $id === '' || $version <= 0 || $scope === '') {
                throw CashierV3CommandException::invalidContext(
                    '历史结账资源快照无效，请联系管理员对账。',
                    ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_receipt_context_invalid']
                );
            }
            if (in_array($kind, ['cashier_workspace', 'checkout_request'], true)) {
                $storedBase[$kind] = ['id' => $id, 'expected_version' => $version];
            }
            $parts[] = $scope . '|' . $kind . '|' . $id . '|' . $version;
            $rowPlanFingerprint = (string)($context['checkout_resource_plan_fingerprint'] ?? '');
            $rowPlanContract = (string)($context['checkout_resource_plan_contract_version'] ?? '');
            $rowBoundVersion = (int)($context['checkout_resource_plan_bound_request_version'] ?? 0);
            if ($planFingerprint === '') {
                $planFingerprint = $rowPlanFingerprint;
                $planContract = $rowPlanContract;
                $boundVersion = $rowBoundVersion;
            } elseif (!hash_equals($planFingerprint, $rowPlanFingerprint)
                || $planContract !== $rowPlanContract || $boundVersion !== $rowBoundVersion) {
                throw CashierV3CommandException::invalidContext(
                    '历史结账资源计划不一致，请联系管理员对账。',
                    ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_receipt_metadata_drift']
                );
            }
        }
        if ($clientBase !== $storedBase
            || preg_match('/^[a-f0-9]{64}$/D', $planFingerprint) !== 1
            || $planContract === '' || $boundVersion <= 0) {
            throw CashierV3CommandException::invalidContext(
                '本次重试与原结账请求不一致，请刷新后重试。',
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_replay_context_mismatch']
            );
        }
        $effective = hash(
            'sha256',
            hash('sha256', implode(';', $parts))
                . '|' . $planContract . '|' . $planFingerprint . '|' . $boundVersion
        );
        if (!hash_equals((string)($existing['contexts_hash'] ?? ''), $effective)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '历史结账资源指纹不一致，请联系管理员对账。',
                CashierV3ResultCode::STATUS_RESULT_UNKNOWN,
                ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_receipt_hash_drift']
            );
        }
        return $effective;
    }

    protected function revalidateFollowUpCheckoutResourcePlan(
        string $checkoutRequestId,
        array $contract,
        string $canonicalAction
    ): array {
        $before = (array)($contract['server_checkout_resource_plan'] ?? []);
        $after = $this->loadCheckoutResourcePlan($checkoutRequestId, $canonicalAction);
        foreach ([
            'requestId', 'requestVersion', 'requestStatus', 'boundRequestVersion',
            'planContractVersion', 'resourcePlanFingerprint', 'resourceCount', 'roleCount',
        ] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                throw CashierV3CommandException::invalidContext(
                    '结账资源刚刚发生变化，请重新确认后再收款。',
                    ['action' => $canonicalAction, 'reason' => 'checkout_resource_plan_changed_after_lock', 'field' => $field]
                );
            }
        }
        $contract['server_checkout_resource_plan'] = $after;
        $contract['checkout_resource_plan_recheck_required'] = false;
        return $contract;
    }

    protected function consumeCheckoutResourcePlanInTx(
        string $checkoutRequestId,
        array $plan,
        CashierV3DataScopeContext $dataScope
    ): void {
        if (!($this->checkoutResourcePlanRepository instanceof CashierV3CheckoutResourcePlanRepository)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账资源计划仓储未就绪，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_resource_plan_repository_missing']
            );
        }
        try {
            $result = $this->checkoutResourcePlanRepository->markConsumedInTx(
                $checkoutRequestId,
                $dataScope->tenantId(),
                $dataScope->forcedStoreId(),
                (int)($plan['boundRequestVersion'] ?? 0),
                (string)($plan['resourcePlanFingerprint'] ?? '')
            );
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw CashierV3CommandException::invalidContext(
                '结账资源计划已失效，请重新确认后再收款。',
                ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
            );
        }
        if (!is_array($result) || (string)($result['status'] ?? '') !== 'consumed') {
            throw CashierV3CommandException::invalidContext(
                '结账资源计划消费失败，请重新确认后再收款。',
                ['reason' => 'checkout_resource_plan_consumption_invalid']
            );
        }
    }

    /**
     * 事务内第一阶段：无锁发现 checkout_request 来源，并要求客户端 contexts
     * 精确一致。这里禁止提前锁 checkout_request；完整 contexts 会交给统一资源
     * 锁按全序锁定，锁后再由 revalidateFollowUpCheckoutSources 二次核对。
     *
     * @param array<int,array> $validatedContexts
     * @param array<int,array> $rawContexts
     * @return array{contexts:array,contract:array}
     */
    protected function expandFollowUpFromCheckoutRequest(
        string $checkoutRequestId,
        array $validatedContexts,
        array $rawContexts,
        string $canonicalAction,
        array $baseContract = []
    ): array {
        if ($this->checkoutSourceLoader === null) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账来源权威尚未接入，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'checkout_source_loader_missing']
            );
        }
        $serverSources = $this->loadCheckoutSources($checkoutRequestId, $canonicalAction);

        // New clients submit only workspace / checkout_request and let the
        // server rebuild sources from the persisted request. During the
        // compatibility window, an older client may still send source
        // contexts; when it does, they must match the server set exactly.
        $clientExtra = [];
        foreach ($rawContexts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = (string)($row['kind'] ?? '');
            $id = trim((string)($row['id'] ?? ''));
            if ($kind === '' || $id === '' || in_array($kind, ['cashier_workspace', 'checkout_request'], true)) {
                continue;
            }
            $clientExtra[$kind . ':' . $id] = ['kind' => $kind, 'id' => $id];
        }
        $serverMap = [];
        foreach ($serverSources as $src) {
            $serverMap[$src['kind'] . ':' . $src['id']] = $src;
        }
        if ($clientExtra && (
            count($clientExtra) !== count($serverMap)
            || array_diff_key($clientExtra, $serverMap)
            || array_diff_key($serverMap, $clientExtra)
        )) {
            throw CashierV3CommandException::invalidContext(
                '结账后续步骤的对象版本与服务器来源不一致，请刷新后重试。',
                [
                    'action' => $canonicalAction,
                    'reason' => 'checkout_contexts_mismatch',
                    'server' => array_values($serverMap),
                    'client' => array_values($clientExtra),
                ]
            );
        }

        // Preserve an earlier server-resource discovery pack. Submission
        // preparation composes both expansions and must revalidate the exact
        // discovered resources after checkout_request sources are attached.
        $contract = $baseContract;
        $contract['required'] = ['cashier_workspace', 'checkout_request'];
        $contract['allowed'] = [];
        $contract['identities'] = [];
        $contract['required_read_roles'] = ['cashier_workspace', 'checkout_request'];
        // The ordinary payment-draft policy advances both resources. A
        // source-selection policy may lock the same pair for a consistent
        // read while only its workspace version participates in the command
        // receipt; preserve that policy-specific mutation contract here.
        $contract['required_touched_roles'] = array_values(array_unique(array_map(
            'strval',
            (array)($baseContract['required_touched_roles'] ?? ['cashier_workspace', 'checkout_request'])
        )));
        $contract['server_checkout_sources'] = $serverSources;
        $contract['checkout_request_id'] = $checkoutRequestId;
        $contract['checkout_source_recheck_required'] = true;
        $contract['expand_from_checkout_request'] = false;
        foreach ($validatedContexts as $ctx) {
            $contract['identities'][] = [
                'role' => (string)($ctx['role'] ?? $ctx['kind']),
                'kind' => $ctx['kind'],
                'id' => $ctx['id'],
                'required' => true,
            ];
            if (!in_array($ctx['kind'], ['cashier_workspace', 'checkout_request'], true)) {
                $contract['required_read_roles'][] = (string)($ctx['role'] ?? $ctx['kind']);
            }
        }
        $contract['required_read_roles'] = array_values(array_unique($contract['required_read_roles']));
        return ['contexts' => $validatedContexts, 'contract' => $contract];
    }

    /**
     * Second phase of checkout-source binding. All declared contexts have
     * already been locked in canonical order, including checkout_request.
     * A changed source set is a concurrency conflict and must roll back.
     */
    protected function revalidateFollowUpCheckoutSources(
        string $checkoutRequestId,
        array $contract,
        string $canonicalAction
    ): array {
        $before = $this->checkoutSourceMap((array)($contract['server_checkout_sources'] ?? []));
        $afterSources = $this->loadCheckoutSources($checkoutRequestId, $canonicalAction);
        $after = $this->checkoutSourceMap($afterSources);
        if (count($before) !== count($after)
            || array_diff_key($before, $after)
            || array_diff_key($after, $before)) {
            throw CashierV3CommandException::invalidContext(
                '结账来源刚刚发生变化，请刷新当前工作台后重试。',
                [
                    'action' => $canonicalAction,
                    'reason' => 'checkout_sources_changed_after_lock',
                    'discovered' => array_values($before),
                    'locked' => array_values($after),
                ]
            );
        }
        $contract['server_checkout_sources'] = $afterSources;
        $contract['checkout_source_recheck_required'] = false;
        return $contract;
    }

    /** @return array<int,array{kind:string,id:string}> */
    protected function loadCheckoutSources(string $checkoutRequestId, string $canonicalAction): array
    {
        if ($this->checkoutSourceLoader === null) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账来源权威尚未接入，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'checkout_source_loader_missing']
            );
        }
        $loaded = call_user_func($this->checkoutSourceLoader, $checkoutRequestId);
        if (!is_array($loaded)
            || (string)($loaded['contractVersion'] ?? '') === ''
            || !array_key_exists('sources', $loaded)
            || !is_array($loaded['sources'])) {
            throw CashierV3CommandException::invalidContext(
                '结账请求不存在或来源已失效，请刷新当前工作台后重试。',
                ['action' => $canonicalAction, 'reason' => 'checkout_request_sources_missing']
            );
        }
        $sources = array_values($this->checkoutSourceMap($loaded['sources']));
        return $sources;
    }

    /** @return array<string,array{kind:string,id:string}> */
    protected function checkoutSourceMap(array $sources): array
    {
        $map = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $kind = trim((string)($source['kind'] ?? ''));
            $id = trim((string)($source['id'] ?? ''));
            if ($kind === '' || $id === '') {
                continue;
            }
            CashierV3ResourceKindCatalog::assertKnown($kind);
            if (in_array($kind, ['cashier_workspace', 'checkout_request'], true)) {
                throw CashierV3CommandException::invalidContext(
                    '结账来源包含非法对象，请刷新当前工作台后重试。',
                    ['reason' => 'checkout_source_kind_forbidden', 'kind' => $kind]
                );
            }
            $map[$kind . ':' . $id] = ['kind' => $kind, 'id' => $id];
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * 幂等重放：返回第一次已确定的不可变业务结果。
     *
     * 重放前必须用当前权限／DataScope 重新校验；已撤权／停用／解绑不得泄露历史业务 data。
     * 不同 correlation 属于传输元数据，不参与哈希冲突；业务字段变化仍 IDEMPOTENCY_KEY_CONFLICT。
     * 不返回历史 versions；若调用方要求 returnCurrentState，由 dispatcher 用当前 DataScope 重建。
     */
    protected function replay(
        array $existing,
        string $canonicalAction,
        CashierV3OperatorScope $operatorScope,
        string $requestHash,
        string $contextsHash,
        array $stateContext,
        CashierV3DataScopeContext $txnDataScope = null,
        array $actionDefinition = [],
        array $payload = [],
        string $correlationId = ''
    ): array {
        if ((string)($existing['action'] ?? '') !== $canonicalAction
            || (int)($existing['store_id'] ?? 0) !== $operatorScope->storeId()
            || (int)($existing['operator_id'] ?? 0) !== $operatorScope->operatorId()
            || (string)($existing['request_hash'] ?? '') !== $requestHash
            || (string)($existing['contexts_hash'] ?? '') !== $contextsHash
        ) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '本次请求标识已用于另一次操作，请刷新当前工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction]
            );
        }
        if ((int)($existing['status'] ?? 0) !== self::RECEIPT_STATUS_SUCCEEDED) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '上一次同请求标识的操作尚未完成，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction]
            );
        }
        $decoded = json_decode((string)($existing['result_json'] ?? ''), true);
        if (!is_array($decoded) || !array_key_exists('data', $decoded) || !is_array($decoded['data'])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '历史操作结果不完整，请更换请求标识后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction]
            );
        }

        // 重放前重新检查当前功能权限与 DataScope；撤权后不泄露业务 data
        $permissionOk = true;
        $denyReason = '';
        if ($txnDataScope === null) {
            $permissionOk = false;
            $denyReason = 'data_scope_missing';
        } elseif ($actionDefinition && $this->permissionGuard !== null) {
            try {
                $this->permissionGuard->assertAllowed($actionDefinition, $txnDataScope, $payload);
            } catch (CashierV3CommandException $e) {
                $permissionOk = false;
                $denyReason = $e->getResultCode();
            }
        }

        if (!$permissionOk) {
            return [
                'status' => CashierV3ResultCode::STATUS_FAILED,
                'code' => CashierV3ResultCode::PERMISSION_DENIED,
                'message' => '当前账号已无该操作权限，历史结果不可查看。',
                'data' => [],
                'business_no' => '',
                'replay' => true,
                'permission_denied_on_replay' => true,
                'deny_reason' => $denyReason,
                'state_context_id' => $stateContext['state_context_id'],
                'context_changed' => (bool)$stateContext['context_changed'],
                'versions' => [],
                'data_scope' => $txnDataScope,
                'correlation_id' => $correlationId,
                'idempotency_key' => (string)($existing['idempotency_key'] ?? ''),
            ];
        }

        // 功能权限仍然有效并不代表历史命令引用的对象仍在当前数据范围内。
        // 重放必须重新解析第一次落回执时保存的 contexts；不能信任本次请求
        // 夹带的版本或只依赖 store_id/operator_id，否则数据范围收窄后会泄露旧 data。
        $storedContexts = json_decode((string)($existing['contexts_json'] ?? ''), true);
        if (!is_array($storedContexts) || $storedContexts === [] || $this->versionServices === null) {
            return $this->replayDenied(
                $stateContext,
                $txnDataScope,
                $correlationId,
                $existing,
                'stored_contexts_missing'
            );
        }
        $replayContexts = [];
        foreach ($storedContexts as $storedContext) {
            if (!is_array($storedContext)) {
                return $this->replayDenied(
                    $stateContext,
                    $txnDataScope,
                    $correlationId,
                    $existing,
                    'stored_context_invalid'
                );
            }
            $kind = trim((string)($storedContext['kind'] ?? ''));
            $resourceId = trim((string)($storedContext['id'] ?? ''));
            $expectedVersion = $storedContext['expected_version'] ?? $storedContext['expectedVersion'] ?? null;
            if ($kind === '' || $resourceId === '' || !is_numeric($expectedVersion) || (int)$expectedVersion <= 0) {
                return $this->replayDenied(
                    $stateContext,
                    $txnDataScope,
                    $correlationId,
                    $existing,
                    'stored_context_invalid'
                );
            }
            $replayContexts[] = [
                'kind' => $kind,
                'id' => $resourceId,
                'expected_version' => (int)$expectedVersion,
            ];
        }
        try {
            $this->versionServices->scopeResolver()->attachScopes(
                $replayContexts,
                $operatorScope,
                $txnDataScope
            );
        } catch (CashierV3CommandException $scopeException) {
            return $this->replayDenied(
                $stateContext,
                $txnDataScope,
                $correlationId,
                $existing,
                (string)($scopeException->getDetail()['reason'] ?? $scopeException->getResultCode())
            );
        }

        $replayEventContract = CashierV3BusinessEventContractRegistry::normalize(
            $actionDefinition,
            $canonicalAction
        );
        if ($replayEventContract['required_event_types']) {
            if (!($this->eventRecorder instanceof CashierV3BusinessEventRecorder)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_UNKNOWN,
                    '历史成功回执的业务事件无法核验，请联系管理员对账。',
                    CashierV3ResultCode::STATUS_RESULT_UNKNOWN,
                    ['action' => $canonicalAction, 'reason' => 'required_event_recorder_missing_on_replay']
                );
            }
            $this->eventRecorder->assertReplayPersistedInTx(
                $canonicalAction,
                (string)($existing['idempotency_key'] ?? ''),
                $operatorScope,
                $txnDataScope,
                (string)$stateContext['state_context_id'],
                $replayEventContract
            );
        }

        return [
            'status' => CashierV3ResultCode::STATUS_SUCCESS,
            'code' => '',
            'message' => (string)($decoded['message'] ?? ($existing['result_message'] ?? '操作成功')),
            'data' => $decoded['data'],
            'business_no' => (string)($existing['business_no'] ?? ''),
            'replay' => true,
            'state_context_id' => $stateContext['state_context_id'],
            'context_changed' => (bool)$stateContext['context_changed'],
            'versions' => [],
            'data_scope' => $txnDataScope,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string)($existing['idempotency_key'] ?? ''),
        ];
    }

    /**
     * 统一构造重放拒绝信封，避免任何历史 data、business_no 或版本泄露。
     */
    protected function replayDenied(
        array $stateContext,
        CashierV3DataScopeContext $dataScope = null,
        string $correlationId = '',
        array $existing = [],
        string $reason = ''
    ): array {
        return [
            'status' => CashierV3ResultCode::STATUS_FAILED,
            'code' => CashierV3ResultCode::PERMISSION_DENIED,
            'message' => '当前账号已无该对象的查看范围，历史结果不可查看。',
            'data' => [],
            'business_no' => '',
            'replay' => true,
            'permission_denied_on_replay' => true,
            'deny_reason' => $reason,
            'state_context_id' => $stateContext['state_context_id'],
            'context_changed' => (bool)$stateContext['context_changed'],
            'versions' => [],
            'data_scope' => $dataScope,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string)($existing['idempotency_key'] ?? ''),
        ];
    }

    /**
     * 领域结果规范化与 touched 校验。
     *
     * touched 可报 role（精确身份合同）或内部 versionKey；必须覆盖 required_touched_roles。
     *
     * @param mixed $result
     * @param array<string,int> $lockedVersions
     * @param array<int,array> $contexts
     * @param array $contract
     * @return array{data:array,message:string,business_no:string,touched_keys:string[]}
     */
    protected function normalizeBusinessResult(
        $result,
        string $canonicalAction,
        array $lockedVersions,
        array $contexts = [],
        array $contract = []
    ): array {
        if (!is_array($result) || !array_key_exists('data', $result)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '本次操作结果不完整，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'data_missing']
            );
        }
        if (!is_array($result['data'])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '本次操作结果不完整，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'data_not_array']
            );
        }

        // 预约资料保存没有工作台或预约资源版本上下文。领域事务通过
        // 幂等回执与预约主表状态写入保证一致性，因此不把业务结果再映射为
        // 通用资源版本变更。
        if (!empty($contract['allows_empty_contexts']) && !$contexts) {
            return [
                'data' => $result['data'],
                'message' => (string)($result['message'] ?? '操作成功'),
                'business_no' => (string)($result['business_no'] ?? ''),
                'touched_keys' => [],
            ];
        }

        $touched = $result['touched'] ?? null;
        if (!is_array($touched) || !$touched) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TOUCHED_INVALID,
                '本次操作未产生有效变更，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'touched_empty']
            );
        }
        $touched = array_values(array_unique(array_map('strval', $touched)));

        $roleToKey = [];
        $kindCounts = [];
        foreach ($contexts as $ctx) {
            $kind = (string)($ctx['kind'] ?? '');
            if ($kind !== '') {
                $kindCounts[$kind] = (int)($kindCounts[$kind] ?? 0) + 1;
            }
        }
        foreach ($contexts as $ctx) {
            if (!isset($ctx['scope']) || !($ctx['scope'] instanceof CashierV3ResourceScope)) {
                continue;
            }
            $key = CashierV3ResourceVersionServices::versionKey($ctx['scope'], $ctx['kind'], $ctx['id']);
            if (isset($ctx['role'])) {
                $roleToKey[(string)$ctx['role']] = $key;
            }
            foreach ((array)($ctx['roles'] ?? []) as $role) {
                $role = (string)$role;
                if ($role !== '') {
                    $roleToKey[$role] = $key;
                }
            }
            // kind 本身只在该 kind 恰好一个物理资源时可用。
            if ((int)($kindCounts[(string)$ctx['kind']] ?? 0) === 1) {
                $roleToKey[(string)$ctx['kind']] = $key;
            }
            // 也允许用 kind:id 声明
            $roleToKey[$ctx['kind'] . ':' . $ctx['id']] = $key;
        }

        $touchedKeys = [];
        foreach ($touched as $token) {
            if (array_key_exists($token, $lockedVersions)) {
                $touchedKeys[] = $token;
                continue;
            }
            if (isset($roleToKey[$token])) {
                $touchedKeys[] = $roleToKey[$token];
                continue;
            }
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TOUCHED_INVALID,
                '本次操作的变更范围异常，已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'touched_unknown_key', 'key' => $token]
            );
        }
        $touchedKeys = array_values(array_unique($touchedKeys));
        foreach ($touchedKeys as $key) {
            if (!array_key_exists($key, $lockedVersions)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_TOUCHED_INVALID,
                    '本次操作的变更范围异常，已回滚，请重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $canonicalAction, 'reason' => 'touched_not_locked', 'key' => $key]
                );
            }
        }

        // 必须覆盖合同声明的全部必改 role
        foreach ((array)($contract['required_touched_roles'] ?? []) as $role) {
            $role = (string)$role;
            if ($role === '') {
                continue;
            }
            if (!isset($roleToKey[$role]) || !in_array($roleToKey[$role], $touchedKeys, true)) {
                // 也接受直接报了 role 字符串
                if (!in_array($role, $touched, true)) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::COMMAND_TOUCHED_INVALID,
                        '本次操作的变更范围不完整，已回滚，请重试。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['action' => $canonicalAction, 'reason' => 'touched_role_missing', 'role' => $role]
                    );
                }
            }
        }

        return [
            'data' => $result['data'],
            'message' => (string)($result['message'] ?? '操作成功'),
            'business_no' => (string)($result['business_no'] ?? ''),
            'touched_keys' => $touchedKeys,
        ];
    }

    /**
     * 对外 versions：稳定对象数组 [{kind,id,version}]，不泄漏内部 canonical scope key。
     *
     * @param array<string,int> $internal
     * @param array<int,array> $contexts
     * @return array<int,array{kind:string,id:string,version:int,role?:string}>
     */
    public static function toPublicVersions(array $internal, array $contexts): array
    {
        $out = [];
        foreach ($contexts as $ctx) {
            if (!isset($ctx['scope']) || !($ctx['scope'] instanceof CashierV3ResourceScope)) {
                continue;
            }
            $key = CashierV3ResourceVersionServices::versionKey($ctx['scope'], $ctx['kind'], $ctx['id']);
            if (!array_key_exists($key, $internal)) {
                continue;
            }
            $version = (int)$internal[$key];
            if ($version <= 0) {
                continue;
            }
            $row = [
                'kind' => (string)$ctx['kind'],
                'id' => (string)$ctx['id'],
                'version' => $version,
            ];
            if (isset($ctx['role'])) {
                $row['role'] = (string)$ctx['role'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 规范化业务参数后取指纹：同幂等键必须绑定同 action 与同内容。
     */
    public function hashRequest(string $canonicalAction, array $payload): string
    {
        // 防御：业务 hash 前剥离传输元数据（即使调用方误传入）
        $payload = self::stripTransportFields($payload);
        unset($payload['correlationId'], $payload['correlation_id'], $payload['returnCurrentState']);
        $json = json_encode(
            ['action' => $canonicalAction, 'payload' => $this->normalizeForHash($payload)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '本次操作参数无法规范化，请重试。'
            );
        }
        return hash('sha256', $json);
    }

    /**
     * Public Gateway calls cannot supply a weaker action/event definition.
     * Aliases may add alias_of, but every security-relevant field must match
     * the canonical server manifest before the authoritative row is used.
     */
    private function authoritativeActionDefinition(string $canonicalAction, array $supplied): array
    {
        $authoritative = CashierV3ActionManifest::requireAction($canonicalAction);
        if ((string)($authoritative['canonical'] ?? '') !== $canonicalAction
            || (string)($authoritative['type'] ?? '') !== CashierV3ActionManifest::TYPE_COMMAND) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::EVENT_CONTRACT_INVALID,
                '服务端操作定义无效，当前操作已停止。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'canonical_action_definition_invalid']
            );
        }
        if (!$supplied) {
            return $authoritative;
        }

        foreach (['canonical', 'type', 'owner', 'permission', 'permissionPolicyId'] as $field) {
            if (($supplied[$field] ?? null) !== ($authoritative[$field] ?? null)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::EVENT_CONTRACT_INVALID,
                    '请求绑定的操作定义与服务端不一致，当前操作已停止。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $canonicalAction, 'reason' => 'action_definition_not_authoritative', 'field' => $field]
                );
            }
        }
        $expectedContract = CashierV3BusinessEventContractRegistry::normalize(
            $authoritative,
            $canonicalAction
        );
        $suppliedContract = CashierV3BusinessEventContractRegistry::normalize(
            $supplied,
            $canonicalAction
        );
        if ($suppliedContract !== $expectedContract) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::EVENT_CONTRACT_INVALID,
                '请求绑定的事件合同与服务端不一致，当前操作已停止。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'reason' => 'action_definition_not_authoritative', 'field' => 'event_contract']
            );
        }
        return $authoritative;
    }

    /**
     * 递归规范化，用于稳定哈希。
     *
     * **不**在这里剥离传输字段：顶层的 command／clientSessionId／stateContextId
     * 已经由 dispatcher 用 stripTransportFields() 剥离一次。若在递归里删，
     * 业务对象内部恰好也叫 command 或 idempotencyKey 的合法嵌套字段会被抹掉，
     * 两个内容不同的请求就会算出同一个哈希，第二个被当成重放直接返回第一个的结果。
     *
     * @param mixed $value
     * @return mixed
     */
    public function normalizeForHash($value)
    {
        if (!is_array($value)) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if (is_float($value)) {
                return (string)$value;
            }
            return $value;
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->normalizeForHash($item);
            }
            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string)$key] = $this->normalizeForHash($item);
        }
        return $out;
    }

    /**
     * 顶层剥离一次传输字段，得到唯一的业务 payload。
     * 网关、哈希与领域处理器全程使用这同一份，处理器不得再回头读原始 Request。
     */
    public static function stripTransportFields(array $body): array
    {
        foreach (self::TRANSPORT_FIELDS as $field) {
            unset($body[$field]);
        }
        return $body;
    }

    /**
     * 回执里保存的 contexts 快照（含服务端解析出的 scope，便于审计与排障）。
     *
     * @param array<int,array> $contexts
     */
    protected function exportContexts(array $contexts): array
    {
        $out = [];
        foreach ($contexts as $context) {
            $scope = $context['scope'] ?? null;
            $out[] = [
                'kind' => $context['kind'],
                'id' => $context['id'],
                'expected_version' => $context['expected_version'],
                'scope' => $scope instanceof CashierV3ResourceScope ? $scope->signature() : '',
            ] + array_filter([
                'roles' => isset($context['roles']) ? array_values((array)$context['roles']) : null,
                'resource_plan_access_mode' => $context['resource_plan_access_mode'] ?? null,
                'resource_plan_provider_contract_version' => $context['resource_plan_provider_contract_version'] ?? null,
                'resource_plan_authority_fingerprint' => $context['resource_plan_authority_fingerprint'] ?? null,
                'resource_plan_row_fingerprint' => $context['resource_plan_row_fingerprint'] ?? null,
                'checkout_resource_plan_fingerprint' => $context['checkout_resource_plan_fingerprint'] ?? null,
                'checkout_resource_plan_contract_version' => $context['checkout_resource_plan_contract_version'] ?? null,
                'checkout_resource_plan_bound_request_version' => $context['checkout_resource_plan_bound_request_version'] ?? null,
            ], static function ($value): bool {
                return $value !== null;
            });
        }
        return $out;
    }

    /**
     * 表就绪检查：command／projection 共用 ReadinessGuard。
     * 使用 information_schema 等值查完整表名；不使用 static 永久缓存；不使用 LIKE 通配。
     */
    public function assertTablesReady(): void
    {
        if ($this->readinessGuard === null) {
            $this->readinessGuard = new CashierV3TableReadinessGuard();
        }
        $this->readinessGuard->assertReady();
    }

    protected function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '本次操作结果序列化失败，已回滚，请重试。'
            );
        }
        return $json;
    }
}

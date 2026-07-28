<?php
namespace app\services\cashier\v3;

use app\services\cashier\v3\event\CashierV3ActionActivationGate;
use app\services\cashier\v3\event\CashierV3EventConsumerRegistry;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry;
use app\services\cashier\v3\projection\CashierV3RootProjector;
use app\services\cashier\v3\projection\CashierV3RootStateContract;
use app\services\cashier\v3\readiness\CashierV3TableReadinessGuard;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
use app\services\cashier\v3\registry\CashierV3HandlerRegistry;
use app\services\cashier\v3\registry\CashierV3PermissionGuard;

/**
 * 收银 V3 action 分发器。
 */
class CashierV3ActionDispatcher
{
    protected $gateway;
    protected $handlers;
    protected $policies;
    protected $permissionGuard;
    protected $scopeResolver;
    protected $stateContexts;
    /** @var CashierV3DataScopeFactory */
    protected $dataScopeFactory;
    /** @var CashierV3RootProjector|null */
    protected $rootProjector;
    /** @var CashierV3TableReadinessGuard|null */
    protected $readiness;
    /** @var CashierV3ResourceVersionServices|null */
    protected $versionServices;
    /** @var CashierV3ResourceLockServices|null */
    protected $lockServices;
    /** @var CashierV3FeatureResolver|null */
    protected $featureResolver;
    /** @var CashierV3PermissionPolicyRegistry|null */
    protected $permissionPolicies;
    /** @var CashierV3PermissionSnapshotServices|null */
    protected $permissionSnapshots;
    /** @var CashierV3ActionActivationGate */
    protected $actionActivationGate;
    /** @var bool */
    protected $frozen = false;

    public function __construct(
        CashierV3CommandGatewayServices $gateway,
        CashierV3HandlerRegistry $handlers,
        CashierV3ContextPolicyRegistry $policies,
        CashierV3PermissionGuard $permissionGuard,
        CashierV3ScopeResolver $scopeResolver,
        CashierV3StateContextServices $stateContexts,
        CashierV3DataScopeFactory $dataScopeFactory,
        CashierV3ActionActivationGate $actionActivationGate,
        CashierV3RootProjector $rootProjector = null,
        CashierV3TableReadinessGuard $readiness = null,
        CashierV3ResourceVersionServices $versionServices = null,
        CashierV3ResourceLockServices $lockServices = null,
        CashierV3FeatureResolver $featureResolver = null,
        CashierV3PermissionPolicyRegistry $permissionPolicies = null,
        CashierV3PermissionSnapshotServices $permissionSnapshots = null
    ) {
        $this->gateway = $gateway;
        $this->handlers = $handlers;
        $this->policies = $policies;
        $this->permissionGuard = $permissionGuard;
        $this->scopeResolver = $scopeResolver;
        $this->stateContexts = $stateContexts;
        $this->dataScopeFactory = $dataScopeFactory;
        $this->actionActivationGate = $actionActivationGate;
        $this->rootProjector = $rootProjector;
        $this->readiness = $readiness;
        $this->versionServices = $versionServices;
        $this->lockServices = $lockServices;
        $this->featureResolver = $featureResolver;
        $this->permissionPolicies = $permissionPolicies;
        $this->permissionSnapshots = $permissionSnapshots;
        if ($gateway->actionActivationGate() !== $actionActivationGate) {
            throw new \LogicException('Dispatcher 与 Gateway 必须共用同一 action activation gate');
        }
        if ($readiness !== null) {
            $this->gateway->setReadinessGuard($readiness);
        }
    }

    public function freeze(): void
    {
        $this->frozen = true;
        $this->handlers->freeze();
        $this->policies->freeze();
        if ($this->permissionPolicies !== null) {
            $this->permissionPolicies->freeze();
        }
        if ($this->featureResolver !== null) {
            $this->featureResolver->freeze();
        }
        if ($this->versionServices !== null) {
            $this->versionServices->freeze();
        }
        if ($this->lockServices !== null) {
            $this->lockServices->freeze();
        }
        if ($this->rootProjector !== null) {
            $this->rootProjector->freeze();
        }
        $this->scopeResolver->freeze();
        $this->actionActivationGate->consumerRegistry()->freeze();
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function handlers(): CashierV3HandlerRegistry
    {
        return $this->handlers;
    }

    public function policies(): CashierV3ContextPolicyRegistry
    {
        return $this->policies;
    }

    public function permissionGuard(): CashierV3PermissionGuard
    {
        return $this->permissionGuard;
    }

    public function gateway(): CashierV3CommandGatewayServices
    {
        return $this->gateway;
    }

    public function scopeResolver(): CashierV3ScopeResolver
    {
        return $this->scopeResolver;
    }

    public function dataScopeFactory(): CashierV3DataScopeFactory
    {
        return $this->dataScopeFactory;
    }

    public function actionActivationGate(): CashierV3ActionActivationGate
    {
        return $this->actionActivationGate;
    }

    public function eventConsumerRegistry(): CashierV3EventConsumerRegistry
    {
        return $this->actionActivationGate->consumerRegistry();
    }

    public function versionServices(): ?CashierV3ResourceVersionServices
    {
        return $this->versionServices;
    }

    public function lockServices(): ?CashierV3ResourceLockServices
    {
        return $this->lockServices;
    }

    public function rootProjector(): ?CashierV3RootProjector
    {
        return $this->rootProjector;
    }

    public function setRootProjector(CashierV3RootProjector $projector): void
    {
        if ($this->frozen) {
            throw new \LogicException('dispatcher 已 freeze');
        }
        $this->rootProjector = $projector;
    }

    public function dispatch(array $body, array $session): array
    {
        if ($this->readiness !== null) {
            $this->readiness->assertReady();
        } else {
            $this->gateway->assertTablesReady();
        }

        $action = trim((string)($body['action'] ?? ''));
        $command = isset($body['command']) && is_array($body['command']) ? $body['command'] : [];

        if ($action === '') {
            $action = trim((string)($command['action'] ?? ''));
        }
        if ($action === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::UNKNOWN_COMMAND_ACTION,
                '本次请求缺少操作标识。'
            );
        }
        if ($command && trim((string)($command['action'] ?? '')) !== $action) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::UNKNOWN_COMMAND_ACTION,
                '本次请求的操作标识不一致，请刷新当前工作台后重试。'
            );
        }

        $definition = CashierV3ActionManifest::requireAction($action);
        $canonical = (string)$definition['canonical'];

        $operatorScope = $this->scopeResolver->operatorScope(
            (int)($session['store_id'] ?? 0),
            (int)($session['operator_id'] ?? 0)
        );

        // 传输元数据必须在 strip 前读取；不得进入业务 payload／哈希
        $correlationId = trim((string)($body['correlationId'] ?? $body['correlation_id'] ?? ''));
        $wantCurrentState = !empty($body['returnCurrentState']);
        $payload = CashierV3CommandGatewayServices::stripTransportFields($body);
        // 防御：strip 后再清一次，避免别名漏网
        unset($payload['correlationId'], $payload['correlation_id'], $payload['returnCurrentState']);

        // context-switch 元数据只服务于首份 bootstrap 投影。它必须离开业务
        // payload／哈希，但不能在此处丢失，否则 handler 无法签发服务端独立 token。
        // 只在明确的 bootstrap action 注入，其他 projection／command 永不接收。
        if ($action === 'open-cashier-workbench' || $canonical === 'open-cashier-workbench') {
            if (array_key_exists('contextSwitchEpoch', $body) || array_key_exists('context_switch_epoch', $body)) {
                $payload['contextSwitchEpoch'] = $body['contextSwitchEpoch'] ?? $body['context_switch_epoch'];
            }
            if (array_key_exists('contextSwitchToken', $body) || array_key_exists('context_switch_token', $body)) {
                $payload['contextSwitchToken'] = $body['contextSwitchToken'] ?? $body['context_switch_token'];
            }
        }

        if ($correlationId === '' && $definition['type'] !== CashierV3ActionManifest::TYPE_PROJECTION) {
            // 写命令必须带 correlation；缺失则服务端生成并回传强制绑定
            $correlationId = sprintf('CORR-%s', str_replace('.', '', uniqid('', true)));
        }
        // projection 也要生成 correlation，便于严格响应绑定
        if ($correlationId === '' && $definition['type'] === CashierV3ActionManifest::TYPE_PROJECTION) {
            $correlationId = sprintf('CORR-%s', str_replace('.', '', uniqid('', true)));
        }

        if (self::isResultQueryAction($canonical)) {
            $originalKey = trim((string)($payload['originalIdempotencyKey'] ?? ''));
            $directExportTask = $canonical === 'query-unified-query-export-task'
                && trim((string)($payload['taskId'] ?? $payload['task_id'] ?? '')) !== '';
            if ($originalKey === '' && !$directExportTask) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ORIGINAL_IDEMPOTENCY_KEY_REQUIRED,
                    '结果追查缺少原命令标识，请刷新当前工作台后重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $canonical]
                );
            }
        }

        if ($definition['type'] === CashierV3ActionManifest::TYPE_PROJECTION) {
            if ($command) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::READ_ONLY_ACTION_NOT_COMMANDABLE,
                    '该操作是只读查看动作，不能作为业务命令提交。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $action]
                );
            }
            // 投影：事务外构建 DataScope（只读）；写命令权限在 Gateway 事务内锁定
            $dataScope = $this->dataScopeFactory->build(
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                is_array($session['operator_profile'] ?? null) ? $session['operator_profile'] : [],
                $operatorScope->tenantId(),
                $operatorScope->organizationId()
            );
            $this->permissionGuard->assertAllowed($definition, $dataScope, $payload);
            return $this->dispatchProjection($canonical, $payload, $operatorScope, $dataScope, $session, $correlationId);
        }

        if (!$command) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_REQUIRED,
                '本次写操作缺少命令信息，请刷新当前工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action]
            );
        }
        // 写命令：事务外只做请求结构与登录态形状校验；最终权限在 Gateway 事务内
        return $this->dispatchCommand(
            $canonical,
            $command,
            $payload,
            $operatorScope,
            $session,
            $definition,
            $correlationId,
            $wantCurrentState
        );
    }

    protected function dispatchProjection(
        string $canonical,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $session,
        string $correlationId = ''
    ): array {
        $handler = $this->handlers->requireProjection($canonical);

        $stateContext = $this->stateContexts->resolve(
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            (string)($session['client_session_id'] ?? ''),
            (string)($session['state_context_id'] ?? '')
        );

        $result = call_user_func($handler, [
            'action' => $canonical,
            'payload' => $payload,
            'operator_scope' => $operatorScope,
            'data_scope' => $dataScope,
            'state_context_id' => $stateContext['state_context_id'],
        ]);
        if (!is_array($result) || !array_key_exists('data', $result) || !is_array($result['data'])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '该查看功能暂时无法加载，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonical]
            );
        }

        $envelope = [
            'result' => [
                'status' => CashierV3ResultCode::STATUS_SUCCESS,
                'code' => '',
                'message' => (string)($result['message'] ?? 'ok'),
            ],
            'data' => $result['data'],
            'replay' => false,
            'stateContextId' => $stateContext['state_context_id'],
            'contextChanged' => (bool)$stateContext['context_changed'] || $canonical === 'open-cashier-workbench',
            'boundAction' => $canonical,
            'boundCanonical' => $canonical,
            'correlationId' => $correlationId,
            'boundCorrelationId' => $correlationId,
        ];
        $originalKey = trim((string)($payload['originalIdempotencyKey'] ?? ''));
        if (self::isResultQueryAction($canonical) && $originalKey !== '') {
            $envelope['boundOriginalIdempotencyKey'] = $originalKey;
        }
        if (isset($result['versions'])) {
            if (!is_array($result['versions'])) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '该查看功能返回的对象版本无效，请稍后重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $canonical, 'reason' => 'projection_versions_not_array']
                );
            }
            $versions = [];
            foreach ($result['versions'] as $row) {
                $kind = trim((string)($row['kind'] ?? ''));
                $id = trim((string)($row['id'] ?? ''));
                $version = (int)($row['version'] ?? 0);
                if ($kind === '' || $id === '' || $version <= 0
                    || !CashierV3ResourceKindCatalog::isKnown($kind)) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '该查看功能返回的对象版本无效，请稍后重试。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['action' => $canonical, 'reason' => 'projection_version_invalid']
                    );
                }
                $versions[] = ['kind' => $kind, 'id' => $id, 'version' => $version];
            }
            if ($versions) {
                $envelope['versions'] = $versions;
            }
        }

        // 完整根只能走 RootProjector；分区未齐时不得空壳返回
        $wantRoot = !empty($result['return_root_state']);
        if ($wantRoot) {
            if ($this->rootProjector === null || !$this->rootProjector->isReadyForFullRoot()) {
                unset($envelope['state'], $envelope['stateRevision'], $envelope['versions']);
                $envelope['requiresRefresh'] = true;
                // 根未就绪：禁止夹带 overlay／navigation 副作用
                return $this->attachContextSwitchBinding($envelope, $payload, $result, $stateContext);
            }
            $rebuilt = $this->rootProjector->rebuild(
                $stateContext['state_context_id'],
                $operatorScope,
                $dataScope,
                []
            );
            if ($rebuilt === null) {
                unset($envelope['state'], $envelope['stateRevision'], $envelope['versions']);
                $envelope['requiresRefresh'] = true;
                return $this->attachContextSwitchBinding($envelope, $payload, $result, $stateContext);
            }
            $envelope['state'] = $rebuilt['state'];
            $envelope['stateRevision'] = $rebuilt['stateRevision'];
            $envelope['stateContextId'] = $rebuilt['stateContextId'];
            $envelope['state']['stateContextId'] = $rebuilt['stateContextId'];
            $envelope['state']['stateRevision'] = $rebuilt['stateRevision'];
            if ($rebuilt['versions']) {
                $envelope['versions'] = $rebuilt['versions'];
            }
        }
        if (isset($result['overlay']) && is_array($result['overlay'])) {
            $envelope['overlay'] = $result['overlay'];
        }
        foreach (['navigation', 'feedback'] as $optional) {
            if (isset($result[$optional]) && is_array($result[$optional])) {
                $envelope[$optional] = $result[$optional];
            }
        }
        return $this->attachContextSwitchBinding($envelope, $payload, $result, $stateContext);
    }

    /**
     * 服务端绑定 context switch：必须带 serverBound；禁止把客户端自报 token 原样回显当成校验通过。
     */
    protected function attachContextSwitchBinding(array $envelope, array $payload, array $handlerResult, array $stateContext): array
    {
        $switch = is_array($handlerResult['context_switch'] ?? null) ? $handlerResult['context_switch'] : [];
        if (!empty($switch['serverBound'])) {
            $epoch = $switch['epoch'] ?? null;
            $serverToken = trim((string)($switch['token'] ?? ''));
            $clientToken = trim((string)($switch['clientToken'] ?? ''));
            $requestedToken = trim((string)($payload['contextSwitchToken'] ?? $payload['context_switch_token'] ?? ''));
            if ($epoch !== null && $epoch !== '' && $serverToken !== '' && $clientToken !== ''
                && $clientToken === $requestedToken && $serverToken !== $clientToken) {
                $envelope['contextSwitchEpoch'] = is_numeric($epoch) ? (0 + $epoch) : $epoch;
                $envelope['contextSwitchToken'] = $serverToken;
                $envelope['contextSwitchClientToken'] = $clientToken;
                $envelope['contextSwitchServerBound'] = true;
                $envelope['contextChanged'] = true;
            }
        }
        if (!empty($stateContext['context_changed'])) {
            $envelope['contextChanged'] = true;
        }
        return $envelope;
    }

    protected function dispatchCommand(
        string $canonical,
        array $command,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        array $session,
        array $definition,
        string $correlationId = '',
        bool $wantCurrentState = false
    ): array {
        $this->actionActivationGate->assertExecutable($definition, $canonical);
        $this->policies->requirePolicy($canonical);
        $handler = $this->handlers->requireCommand($canonical);

        // returnCurrentState 为传输元数据：不进业务 hash；仅控制是否重建当前投影
        $wantCurrentState = $wantCurrentState
            || !empty($command['returnCurrentState']);
        unset($command['returnCurrentState'], $command['correlationId'], $command['correlation_id']);
        $idempotencyKey = (string)($command['idempotencyKey'] ?? $command['idempotency_key'] ?? '');

        $outcome = $this->gateway->execute(
            $canonical,
            $command,
            $payload,
            $operatorScope,
            [
                'client_session_id' => (string)($session['client_session_id'] ?? ''),
                'state_context_id' => (string)($session['state_context_id'] ?? ''),
                'operator_ip' => (string)($session['operator_ip'] ?? ''),
                'operator_profile' => is_array($session['operator_profile'] ?? null) ? $session['operator_profile'] : [],
                'correlation_id' => $correlationId,
            ],
            function (array $scope) use ($handler) {
                return call_user_func($handler, $scope);
            },
            null,
            $definition
        );

        $dataScope = $outcome['data_scope'] ?? null;
        $envelope = [
            'result' => [
                'status' => $outcome['status'],
                'code' => (string)$outcome['code'],
                'message' => (string)$outcome['message'],
            ],
            'data' => $outcome['data'],
            'replay' => (bool)$outcome['replay'],
            'stateContextId' => $outcome['state_context_id'],
            'contextChanged' => (bool)$outcome['context_changed'],
            'boundAction' => $canonical,
            'boundCanonical' => $canonical,
            'boundIdempotencyKey' => $idempotencyKey !== '' ? $idempotencyKey : (string)($outcome['idempotency_key'] ?? ''),
            'correlationId' => $correlationId !== '' ? $correlationId : (string)($outcome['correlation_id'] ?? ''),
            'boundCorrelationId' => $correlationId !== '' ? $correlationId : (string)($outcome['correlation_id'] ?? ''),
        ];
        if ((string)$outcome['business_no'] !== '') {
            $envelope['businessNo'] = (string)$outcome['business_no'];
        }
        if (!empty($outcome['idempotency_key'])) {
            $envelope['idempotencyKey'] = (string)$outcome['idempotency_key'];
        }

        // 撤权重放：最小结果，不重建投影、不夹带业务副作用字段
        if (!empty($outcome['permission_denied_on_replay'])) {
            $envelope['requiresRefresh'] = true;
            return $envelope;
        }

        $shouldProject = (!$outcome['replay'] || $wantCurrentState)
            && $outcome['status'] === CashierV3ResultCode::STATUS_SUCCESS
            && $this->rootProjector !== null
            && $this->rootProjector->isReadyForFullRoot()
            && $dataScope instanceof CashierV3DataScopeContext;

        if ($shouldProject) {
            // returnCurrentState：必须用当前 DataScope 重建；无法重建 → requiresRefresh
            $rebuilt = $this->rootProjector->rebuild(
                $outcome['state_context_id'],
                $operatorScope,
                $dataScope,
                []
            );
            if ($rebuilt === null) {
                unset($envelope['state'], $envelope['stateRevision'], $envelope['versions']);
                $envelope['requiresRefresh'] = true;
                return $envelope;
            }
            $envelope['state'] = $rebuilt['state'];
            $envelope['stateRevision'] = $rebuilt['stateRevision'];
            $envelope['state']['stateContextId'] = $rebuilt['stateContextId'];
            $envelope['state']['stateRevision'] = $rebuilt['stateRevision'];
            if ($rebuilt['versions']) {
                $envelope['versions'] = $rebuilt['versions'];
            }
        } elseif ($wantCurrentState && $outcome['status'] === CashierV3ResultCode::STATUS_SUCCESS) {
            // 要求当前状态但无法重建（投影器未就绪／无 DataScope）
            $envelope['requiresRefresh'] = true;
        } elseif (!$outcome['replay'] && !empty($outcome['versions']) && !$shouldProject) {
            $envelope['versions'] = $outcome['versions'];
        }

        foreach (['overlay', 'navigation', 'feedback'] as $optional) {
            if (isset($outcome['data']['_' . $optional]) && is_array($outcome['data']['_' . $optional])) {
                $envelope[$optional] = $outcome['data']['_' . $optional];
                unset($envelope['data']['_' . $optional]);
            }
        }

        return $envelope;
    }

    public static function projectionRebuildFallback(array $committedEnvelope): array
    {
        $committedEnvelope['result'] = [
            'status' => CashierV3ResultCode::STATUS_SUCCESS,
            'code' => CashierV3ResultCode::PROJECTION_REBUILD_FAILED,
            'message' => '操作已完成，但页面数据未能刷新，请手动刷新查看最新结果。',
        ];
        $committedEnvelope['requiresRefresh'] = true;
        unset($committedEnvelope['state'], $committedEnvelope['stateRevision'], $committedEnvelope['versions']);
        return $committedEnvelope;
    }

    public static function isResultQueryAction(string $canonical): bool
    {
        return in_array($canonical, [
            'query-checkout-result',
            'query-service-completion-result',
            'query-hang-order-result',
            'query-writeoff-result',
            'query-reservation-result',
            'query-unified-query-export-task',
        ], true);
    }
}

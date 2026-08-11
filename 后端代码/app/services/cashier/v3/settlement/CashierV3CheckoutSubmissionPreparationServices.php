<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;

/**
 * Eventless final preparation for a checkout submission.
 *
 * Gateway owns the transaction and every business-resource lock. This service
 * only accepts Gateway's revalidated server discovery pack and binds its hidden
 * resources to the next immutable checkout-request version.
 */
final class CashierV3CheckoutSubmissionPreparationServices
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-submission-preparation-v1';

    private const ACTION = 'prepare-checkout-submission';
    private const DISCOVERY_CONTRACT_VERSION = 'cashier-v3-server-resource-discovery-v1';
    private const EXCLUDED_PLAN_KINDS = ['cashier_workspace', 'checkout_request'];

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3CheckoutDraftAuthorityRebuilder */
    private $rebuilder;

    /** @var CashierV3CheckoutResourcePlanRepository */
    private $resourcePlans;

    /** @var string */
    private $serverIdSecret;

    public function __construct(
        CashierV3CheckoutRequestRepository $requests = null,
        CashierV3CheckoutDraftAuthorityRebuilder $rebuilder = null,
        CashierV3CheckoutResourcePlanRepository $resourcePlans = null,
        string $serverIdSecret = ''
    ) {
        $this->resourcePlans = $resourcePlans ?: new ThinkPhpCashierV3CheckoutResourcePlanRepository();
        $this->requests = $requests
            ?: new ThinkPhpCashierV3CheckoutRequestRepository($this->resourcePlans);
        $this->rebuilder = $rebuilder ?: new CashierV3CheckoutDraftAuthorityRebuilder();
        $this->serverIdSecret = $serverIdSecret;
    }

    public function prepareInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutSubmissionPreparation');

        try {
            if (($scope['action'] ?? null) !== self::ACTION) {
                throw self::invalid(
                    'checkout_submission_action_invalid',
                    '本次结账确认操作无效，请刷新后重试。'
                );
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            self::assertPayload($payload);
            $operatorScope = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operatorScope instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::invalid(
                    'checkout_submission_scope_missing',
                    '当前收银账号或门店权限已失效，请重新登录后重试。'
                );
            }
            self::assertDataScope($operatorScope, $dataScope);

            $requestId = (string)$payload['checkoutRequestId'];
            $requestVersion = (int)$payload['checkoutRequestVersion'];
            $stateContextId = self::stateContextId($scope['state_context_id'] ?? null);
            $workspaceId = self::workspaceId($operatorScope, $stateContextId);
            $contexts = self::listValue($scope['contexts'] ?? null, 'checkout_submission_contexts_invalid');
            $lockedVersions = is_array($scope['locked_versions'] ?? null)
                ? $scope['locked_versions']
                : [];
            $workspaceContext = self::lockedContext(
                $contexts,
                $lockedVersions,
                'cashier_workspace',
                $workspaceId,
                $dataScope
            );
            $requestContext = self::lockedContext(
                $contexts,
                $lockedVersions,
                'checkout_request',
                $requestId,
                $dataScope
            );
            if ((int)$requestContext['expected_version'] !== $requestVersion) {
                throw self::versionConflict('checkout_submission_request_context_version_mismatch');
            }

            $idempotencyKey = self::submissionIdempotencyKey(
                $scope['idempotency_key'] ?? null
            );
            $secret = $this->serverIdSecret();
            $discovery = self::discoveryPack($scope['server_resource_discovery'] ?? null);

            $aggregate = $this->requests->lockAggregateForEditInTx(
                $requestId,
                $requestVersion,
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $dataScope
            );
            self::assertPreparationIdentity($payload, $aggregate['request'] ?? null);

            $now = time();
            $snapshot = $this->rebuilder->rebuild(
                $aggregate,
                (int)$workspaceContext['expected_version'],
                $dataScope->permissionVersion(),
                $now
            );
            $composition = self::supportedComposition($snapshot);

            $kernel = CashierV3CheckoutSettlementKernel::prepareSubmission([
                'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
                'operation' => CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION,
                'idempotencyKey' => $idempotencyKey,
                'workspaceId' => $workspaceId,
                'stateContextId' => $stateContextId,
                'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
                'requestId' => $requestId,
                'expectedVersion' => $requestVersion,
            ], $snapshot, $aggregate['currentRequest'] ?? null, $secret);
            self::assertKernelTransition($kernel, $requestId, $requestVersion, $composition);

            $plan = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
                $dataScope->tenantId(),
                $dataScope->forcedStoreId(),
                (int)$kernel['requestVersion'],
                self::resourcePlanRows(
                    $discovery['resources'],
                    $contexts,
                    $lockedVersions,
                    $dataScope
                )
            );

            $persistedRequest = $this->requests->persistKernelPlanInTx(
                $kernel,
                $aggregate['verifiedSources'],
                $operatorScope,
                $dataScope
            );
            self::assertPersistedRequest($persistedRequest, $kernel);
            $persistedPlan = $this->resourcePlans->persistInTx($requestId, $plan, $now);
            self::assertPersistedPlan($persistedPlan, $plan, $requestId);

            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'checkoutRequestId' => $requestId,
                'checkoutRequestVersion' => (int)$kernel['requestVersion'],
                'requestId' => $requestId,
                'version' => (int)$kernel['requestVersion'],
                'requestStatus' => (string)$kernel['requestStatus'],
                'composition' => (string)$kernel['composition'],
                'totals' => (array)$kernel['totals'],
                'resourcePlanFingerprint' => $plan->fingerprint(),
                'resourceCount' => $plan->resourceCount(),
                'roleCount' => $plan->roleCount(),
                'replayed' => !empty($kernel['replayed'])
                    || !empty($persistedRequest['replayed'])
                    || !empty($persistedPlan['replayed']),
                'eventless' => true,
                'businessEffects' => (array)$kernel['businessEffects'],
                'message' => '结账资料已完成最终校验。',
            ];
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw self::translateContractFailure($exception);
        }
    }

    private static function assertPayload(array $payload): void
    {
        $expected = [
            'checkoutRequestId',
            'checkoutRequestVersion',
            'preparationRequestId',
            'preparationToken',
        ];
        $actual = array_keys($payload);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw self::invalid(
                'checkout_submission_payload_shape_invalid',
                '本次结账确认资料格式无效，请刷新后重试。'
            );
        }
        if (!is_string($payload['checkoutRequestId'])
            || preg_match('/^CKR-[0-9a-f]{40}$/D', $payload['checkoutRequestId']) !== 1
            || !is_int($payload['checkoutRequestVersion'])
            || $payload['checkoutRequestVersion'] <= 0
            || !is_string($payload['preparationRequestId'])
            || preg_match(
                '/^(?:CHECKOUT|CHECKOUT_PREPARE)-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $payload['preparationRequestId']
            ) !== 1
            || !is_string($payload['preparationToken'])
            || preg_match('/^CKPT-[0-9a-f]{64}$/D', $payload['preparationToken']) !== 1) {
            throw self::invalid(
                'checkout_submission_identity_invalid',
                '本次结账确认资料已失效，请关闭后重新进入。'
            );
        }
    }

    private static function assertDataScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || $operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::invalid(
                'checkout_submission_data_scope_denied',
                '当前账号无权在该门店确认结账，请重新登录后重试。'
            );
        }
        $permissionVersion = $dataScope->permissionVersion();
        if (strlen($permissionVersion) < 16
            || strlen($permissionVersion) > 128
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $permissionVersion) !== 1) {
            throw self::invalid(
                'checkout_submission_permission_version_invalid',
                '当前账号权限版本无效，请重新登录后重试。'
            );
        }
    }

    private static function assertPreparationIdentity(array $payload, $request): void
    {
        if (!is_array($request)) {
            throw self::invalid(
                'checkout_submission_request_missing',
                '结账请求不存在或已经失效，请关闭后重新进入。'
            );
        }
        $creationKey = (string)($request['creation_idempotency_key'] ?? '');
        if ($creationKey === ''
            || !hash_equals($creationKey, (string)$payload['preparationRequestId'])) {
            throw self::invalid(
                'checkout_preparation_request_mismatch',
                '结账页面已变化，请关闭后重新进入。'
            );
        }
        $requestId = (string)($request['request_id'] ?? '');
        $version = (int)($request['request_version'] ?? 0);
        $aggregateFingerprint = (string)($request['aggregate_fingerprint'] ?? '');
        $operationFingerprint = (string)($request['last_operation_fingerprint'] ?? '');
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1
            || $version <= 0
            || preg_match('/^[0-9a-f]{64}$/D', $aggregateFingerprint) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $operationFingerprint) !== 1) {
            throw self::invalid(
                'checkout_preparation_fingerprint_invalid',
                '结账页面校验失败，请重新进入。'
            );
        }
        $expectedToken = 'CKPT-' . hash('sha256', implode('|', [
            CashierV3CheckoutProjectionServices::CONTRACT_VERSION,
            $requestId,
            (string)$version,
            $aggregateFingerprint,
            $operationFingerprint,
        ]));
        if (!hash_equals($expectedToken, (string)$payload['preparationToken'])) {
            throw self::versionConflict('checkout_preparation_token_stale');
        }
    }

    private static function supportedComposition(array $snapshot): string
    {
        $saleLines = $snapshot['saleLines'] ?? null;
        $entitlementLines = $snapshot['entitlementLines'] ?? null;
        $balance = $snapshot['balanceDeduction'] ?? null;
        $debt = $snapshot['debt'] ?? null;
        if (!is_array($saleLines)
            || !is_array($entitlementLines)
            || ($saleLines === [] && $entitlementLines === [])
            || !is_array($balance)
            || !is_int($balance['amountCents'] ?? null)
            || !is_array($debt)
            || !is_int($debt['amountCents'] ?? null)) {
            throw self::invalid(
                'checkout_submission_composition_not_supported',
                '当前结账内容暂不支持最终提交，请返回编辑后重试。'
            );
        }
        // 欠款对应本次销售应收。混合单中的权益核销不增加应收，
        // 但不能阻断该笔新购销售行按欠款结算；纯权益单仍一律不允许欠款。
        if ($debt['amountCents'] > 0 && $saleLines === []) {
            throw self::invalid(
                'checkout_submission_debt_composition_not_supported',
                '权益使用不支持欠款结账，请返回编辑后重试。'
            );
        }
        // Balance only settles the newly sold receivable. Entitlement service
        // lines add no receivable, so a mixed checkout remains eligible as
        // long as it has server-locked sale lines. Pure entitlement checkout
        // stays fail-closed because it has no sale balance to settle.
        if ($balance['amountCents'] > 0 && $saleLines === []) {
            throw self::invalid(
                'checkout_submission_balance_composition_not_supported',
                '当前结账内容暂不支持余额支付，请返回编辑后重试。'
            );
        }
        if ($saleLines !== [] && $entitlementLines !== []) {
            return CashierV3CheckoutSettlementKernel::COMPOSITION_MIXED;
        }
        return $saleLines !== []
            ? CashierV3CheckoutSettlementKernel::COMPOSITION_SALE_ONLY
            : CashierV3CheckoutSettlementKernel::COMPOSITION_ENTITLEMENT_ONLY;
    }

    private static function assertKernelTransition(
        array $kernel,
        string $requestId,
        int $requestVersion,
        string $expectedComposition
    ): void {
        if ((string)($kernel['requestId'] ?? '') !== $requestId
            || (int)($kernel['requestVersion'] ?? 0) !== $requestVersion + 1
            || (string)($kernel['requestStatus'] ?? '') !== 'ready_for_submit'
            || (string)($kernel['composition'] ?? '') !== $expectedComposition
            || empty($kernel['eventless'])
            || !is_array($kernel['businessEffects'] ?? null)
            || !empty($kernel['businessEffects']['checkoutSucceeded'])) {
            throw self::invalid(
                'checkout_submission_transition_invalid',
                '结账请求状态推进异常，请刷新后重试。'
            );
        }
    }

    private static function discoveryPack($value): array
    {
        if (!is_array($value)) {
            throw self::invalid(
                'checkout_submission_discovery_missing',
                '结账资源校验结果缺失，请刷新后重试。'
            );
        }
        $keys = array_keys($value);
        $expected = ['contractVersion', 'resources', 'fingerprint'];
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || ($value['contractVersion'] ?? null) !== self::DISCOVERY_CONTRACT_VERSION
            || !is_array($value['resources'])
            || !$value['resources']
            || count($value['resources']) > CashierV3CheckoutVerifiedResourcePlan::MAX_RESOURCES
            || array_keys($value['resources']) !== range(0, count($value['resources']) - 1)
            || !is_string($value['fingerprint'])
            || preg_match('/^[0-9a-f]{64}$/D', $value['fingerprint']) !== 1) {
            throw self::invalid(
                'checkout_submission_discovery_invalid',
                '结账资源校验结果无效，请刷新后重试。',
                [
                    'discovery_keys' => array_values(array_map('strval', array_keys($value))),
                    'contract_version_valid' => ($value['contractVersion'] ?? null)
                        === self::DISCOVERY_CONTRACT_VERSION,
                    'resources_type' => gettype($value['resources'] ?? null),
                    'resource_count' => is_array($value['resources'] ?? null)
                        ? count($value['resources'])
                        : -1,
                    'resources_are_list' => is_array($value['resources'] ?? null)
                        && array_keys($value['resources'])
                            === (($value['resources'] ?? [])
                                ? range(0, count($value['resources']) - 1)
                                : []),
                    'fingerprint_format_valid' => is_string($value['fingerprint'] ?? null)
                        && preg_match('/^[0-9a-f]{64}$/D', $value['fingerprint']) === 1,
                ]
            );
        }
        $json = json_encode($value['resources'], JSON_UNESCAPED_UNICODE);
        if ($json === false
            || !hash_equals((string)$value['fingerprint'], hash('sha256', $json))) {
            throw self::versionConflict('checkout_submission_discovery_fingerprint_mismatch');
        }
        return $value;
    }

    private static function resourcePlanRows(
        array $resources,
        array $contexts,
        array $lockedVersions,
        CashierV3DataScopeContext $dataScope
    ): array {
        $rows = [];
        $rowIndexByPhysical = [];
        $seenPhysical = [];
        $seenRoles = [];
        $previous = null;
        foreach ($resources as $index => $resource) {
            if (!is_array($resource)) {
                throw self::invalid(
                    'checkout_submission_discovery_row_invalid',
                    '结账资源校验明细无效，请刷新后重试。'
                );
            }
            self::assertDiscoveryRowShape($resource, $index);
            $kind = $resource['kind'];
            $id = $resource['id'];
            CashierV3ResourceKindCatalog::assertKnown($kind);
            $physical = $kind . ':' . $id;
            if (isset($seenPhysical[$physical])) {
                throw self::invalid(
                    'checkout_submission_discovery_duplicate',
                    '结账资源校验明细重复，请刷新后重试。'
                );
            }
            $seenPhysical[$physical] = true;
            if ($previous !== null
                && CashierV3ResourceKindCatalog::compareResources(
                    $previous['kind'],
                    $previous['id'],
                    $kind,
                    $id
                ) >= 0) {
                throw self::invalid(
                    'checkout_submission_discovery_order_invalid',
                    '结账资源锁定顺序无效，请刷新后重试。'
                );
            }
            $previous = ['kind' => $kind, 'id' => $id];

            if (in_array($kind, self::EXCLUDED_PLAN_KINDS, true)) {
                continue;
            }
            $context = self::lockedContext(
                $contexts,
                $lockedVersions,
                $kind,
                $id,
                $dataScope
            );
            $contextProvider = $context['server_resource_provider_contract_version'] ?? null;
            $contextAuthority = $context['server_resource_authority_fingerprint'] ?? null;
            if ((int)$context['expected_version'] !== $resource['expectedVersion']
                || ($contextProvider !== null
                    && $contextProvider !== $resource['providerContractVersion'])
                || ($contextAuthority !== null
                    && $contextAuthority !== $resource['authorityFingerprint'])) {
                throw self::versionConflict('checkout_submission_locked_discovery_mismatch');
            }
            $roles = self::mergeRoles(
                $resource['roles'],
                self::contextRoles($context, $kind)
            );
            self::claimRoles($seenRoles, $roles, $physical);
            $accessMode = self::mergeAccessModes(
                $resource['accessMode'],
                self::contextAccessMode($context)
            );
            [$scopeType, $scopeId] = self::planScope($kind, $context, $dataScope);
            $rowIndexByPhysical[$physical] = count($rows);
            $rows[] = [
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $dataScope->forcedStoreId(),
                'kind' => $kind,
                'id' => $id,
                'scopeType' => $scopeType,
                'scopeId' => $scopeId,
                'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf($kind),
                'expectedVersion' => $resource['expectedVersion'],
                'roles' => $roles,
                'accessMode' => $accessMode,
                'providerContractVersion' => $resource['providerContractVersion'],
                'authorityFingerprint' => $resource['authorityFingerprint'],
            ];
        }

        // Navigation sources are server-bound by checkout_request, not by the
        // discovery provider. They still belong to the immutable final plan so
        // submit-checkout locks them again from server authority.
        foreach ($contexts as $context) {
            if (!is_array($context)) {
                throw self::invalid(
                    'checkout_submission_context_shape_invalid',
                    '结账对象版本格式无效，请刷新后重试。'
                );
            }
            $kind = (string)($context['kind'] ?? '');
            $id = (string)($context['id'] ?? '');
            if (in_array($kind, self::EXCLUDED_PLAN_KINDS, true)) {
                continue;
            }
            CashierV3ResourceKindCatalog::assertKnown($kind);
            $locked = self::lockedContext(
                $contexts,
                $lockedVersions,
                $kind,
                $id,
                $dataScope
            );
            $physical = $kind . ':' . $id;
            $roles = self::contextRoles($locked, $kind);
            $accessMode = self::contextAccessMode($locked);
            if (isset($rowIndexByPhysical[$physical])) {
                $rowIndex = $rowIndexByPhysical[$physical];
                $mergedRoles = self::mergeRoles($rows[$rowIndex]['roles'], $roles);
                self::claimRoles($seenRoles, $mergedRoles, $physical);
                $rows[$rowIndex]['roles'] = $mergedRoles;
                $rows[$rowIndex]['accessMode'] = self::mergeAccessModes(
                    $rows[$rowIndex]['accessMode'],
                    $accessMode
                );
                continue;
            }

            self::claimRoles($seenRoles, $roles, $physical);
            [$scopeType, $scopeId] = self::planScope($kind, $locked, $dataScope);
            $rowIndexByPhysical[$physical] = count($rows);
            $rows[] = [
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $dataScope->forcedStoreId(),
                'kind' => $kind,
                'id' => $id,
                'scopeType' => $scopeType,
                'scopeId' => $scopeId,
                'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf($kind),
                'expectedVersion' => (int)$locked['expected_version'],
                'roles' => $roles,
                'accessMode' => $accessMode,
                'providerContractVersion' => 'gateway-locked-context-v1',
                'authorityFingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint([
                    'contractVersion' => 'gateway-locked-context-v1',
                    'kind' => $kind,
                    'id' => $id,
                    'expectedVersion' => (int)$locked['expected_version'],
                    'scopeType' => $scopeType,
                    'scopeId' => $scopeId,
                ]),
            ];
        }
        if (!$rows) {
            throw self::invalid(
                'checkout_submission_hidden_resources_missing',
                '结账所需的服务端资源尚未准备完成，请刷新后重试。'
            );
        }
        return $rows;
    }

    private static function planScope(
        string $kind,
        array $context,
        CashierV3DataScopeContext $dataScope
    ): array {
        $scopeType = CashierV3ResourceKindCatalog::scopeTypeOf($kind);
        if ($scopeType === CashierV3ResourceScope::TYPE_TENANT) {
            $scopeId = $dataScope->tenantId();
        } elseif ($scopeType === CashierV3ResourceScope::TYPE_STORE) {
            $scopeId = (string)$dataScope->forcedStoreId();
        } else {
            throw self::invalid(
                'checkout_submission_resource_scope_unsupported',
                '结账资源作用域暂不支持，请联系管理员。'
            );
        }
        $resolvedScope = $context['scope'];
        if ($resolvedScope->type() !== $scopeType
            || !hash_equals($scopeId, $resolvedScope->id())) {
            throw self::invalid(
                'checkout_submission_resource_scope_mismatch',
                '结账资源归属已变化，请刷新后重试。'
            );
        }
        return [$scopeType, $scopeId];
    }

    private static function contextRoles(array $context, string $kind): array
    {
        $roles = [];
        if (isset($context['roles']) && is_array($context['roles'])) {
            $roles = $context['roles'];
        }
        if (isset($context['role']) && is_string($context['role'])) {
            $roles[] = $context['role'];
        }
        if (!$roles) {
            $roles[] = $kind;
        }
        $normalized = [];
        foreach ($roles as $role) {
            if (!is_string($role)
                || $role === ''
                || strlen($role) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $role) !== 1) {
                throw self::invalid(
                    'checkout_submission_context_role_invalid',
                    '结账对象角色无效，请刷新后重试。'
                );
            }
            $normalized[$role] = $role;
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function contextAccessMode(array $context): string
    {
        $modes = [];
        foreach (['server_resource_access_mode', 'resource_plan_access_mode'] as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            if (!in_array($context[$key], ['read', 'mutate'], true)) {
                throw self::invalid(
                    'checkout_submission_context_access_mode_invalid',
                    '结账对象访问方式无效，请刷新后重试。'
                );
            }
            $modes[] = $context[$key];
        }
        return in_array('mutate', $modes, true) ? 'mutate' : 'read';
    }

    private static function mergeRoles(array $left, array $right): array
    {
        $roles = array_values(array_unique(array_merge($left, $right)));
        sort($roles, SORT_STRING);
        return $roles;
    }

    private static function mergeAccessModes(string $left, string $right): string
    {
        return $left === 'mutate' || $right === 'mutate' ? 'mutate' : 'read';
    }

    private static function claimRoles(array &$owners, array $roles, string $physical): void
    {
        foreach ($roles as $role) {
            if (isset($owners[$role]) && $owners[$role] !== $physical) {
                throw self::invalid(
                    'checkout_submission_resource_role_conflict',
                    '结账资源角色冲突，请刷新后重试。'
                );
            }
            $owners[$role] = $physical;
        }
    }

    private static function assertDiscoveryRowShape(array $resource, int $index): void
    {
        $expected = [
            'kind',
            'id',
            'expectedVersion',
            'roles',
            'accessMode',
            'providerContractVersion',
            'authorityFingerprint',
        ];
        $actual = array_keys($resource);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected
            || !is_string($resource['kind'])
            || !is_string($resource['id'])
            || $resource['id'] === ''
            || strlen($resource['id']) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $resource['id']) !== 1
            || !is_int($resource['expectedVersion'])
            || $resource['expectedVersion'] <= 0
            || !is_array($resource['roles'])
            || !$resource['roles']
            || array_keys($resource['roles']) !== range(0, count($resource['roles']) - 1)
            || !in_array($resource['accessMode'], ['read', 'mutate'], true)
            || !is_string($resource['providerContractVersion'])
            || !is_string($resource['authorityFingerprint'])
            || preg_match('/^[a-f0-9]{64}$/D', $resource['authorityFingerprint']) !== 1) {
            throw self::invalid(
                'checkout_submission_discovery_row_invalid',
                '结账资源校验明细无效，请刷新后重试。',
                ['index' => $index]
            );
        }
        $roles = [];
        foreach ($resource['roles'] as $role) {
            if (!is_string($role)
                || $role === ''
                || strlen($role) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $role) !== 1
                || isset($roles[$role])) {
                throw self::invalid(
                    'checkout_submission_discovery_role_invalid',
                    '结账资源角色无效，请刷新后重试。',
                    ['index' => $index]
                );
            }
            $roles[$role] = true;
        }
        $sorted = $resource['roles'];
        sort($sorted, SORT_STRING);
        if ($sorted !== $resource['roles']
            || strlen($resource['providerContractVersion']) > 128
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $resource['providerContractVersion']) !== 1) {
            throw self::invalid(
                'checkout_submission_discovery_row_not_canonical',
                '结账资源校验明细未规范化，请刷新后重试。',
                ['index' => $index]
            );
        }
    }

    private static function lockedContext(
        array $contexts,
        array $lockedVersions,
        string $kind,
        string $id,
        CashierV3DataScopeContext $dataScope
    ): array {
        $found = null;
        foreach ($contexts as $context) {
            if (!is_array($context)
                || ($context['kind'] ?? null) !== $kind
                || ($context['id'] ?? null) !== $id) {
                continue;
            }
            if ($found !== null) {
                throw self::invalid(
                    'checkout_submission_context_duplicate',
                    '结账对象版本重复，请刷新后重试。'
                );
            }
            $found = $context;
        }
        $scope = is_array($found) ? ($found['scope'] ?? null) : null;
        $version = is_array($found) ? ($found['expected_version'] ?? null) : null;
        if (!is_array($found)
            || !is_int($version)
            || $version <= 0
            || !($scope instanceof CashierV3ResourceScope)
            || ($found['data_scope'] ?? null) !== $dataScope) {
            throw self::invalid(
                'checkout_submission_locked_context_missing',
                '结账对象尚未完成服务端锁定，请刷新后重试。',
                ['kind' => $kind, 'id' => $id]
            );
        }
        $versionKey = $scope->signature() . ':' . $kind . ':' . $id;
        if (!array_key_exists($versionKey, $lockedVersions)
            || !is_int($lockedVersions[$versionKey])
            || $lockedVersions[$versionKey] !== $version) {
            throw self::invalid(
                'checkout_submission_lock_evidence_missing',
                '结账对象锁定凭据无效，请刷新后重试。',
                ['kind' => $kind, 'id' => $id]
            );
        }
        return $found;
    }

    private static function assertPersistedRequest(array $persisted, array $kernel): void
    {
        if (($persisted['requestId'] ?? null) !== $kernel['requestId']
            || ($persisted['requestVersion'] ?? null) !== $kernel['requestVersion']
            || ($persisted['requestStatus'] ?? null) !== $kernel['requestStatus']
            || empty($persisted['eventless'])) {
            throw self::invalid(
                'checkout_submission_request_persistence_invalid',
                '结账请求保存结果无效，已回滚，请重试。'
            );
        }
    }

    private static function assertPersistedPlan(
        array $persisted,
        CashierV3CheckoutVerifiedResourcePlan $plan,
        string $requestId
    ): void {
        if (($persisted['requestId'] ?? null) !== $requestId
            || ($persisted['boundRequestVersion'] ?? null) !== $plan->boundRequestVersion()
            || ($persisted['resourcePlanFingerprint'] ?? null) !== $plan->fingerprint()
            || ($persisted['resourceCount'] ?? null) !== $plan->resourceCount()
            || ($persisted['roleCount'] ?? null) !== $plan->roleCount()) {
            throw self::invalid(
                'checkout_submission_plan_persistence_invalid',
                '结账资源计划保存结果无效，已回滚，请重试。'
            );
        }
    }

    private static function stateContextId($value): string
    {
        if (!is_string($value)) {
            throw self::invalid(
                'checkout_submission_state_context_missing',
                '当前收银工作台已失效，请刷新后重试。'
            );
        }
        $value = trim($value);
        if ($value === ''
            || strlen($value) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::invalid(
                'checkout_submission_state_context_invalid',
                '当前收银工作台已失效，请刷新后重试。'
            );
        }
        return $value;
    }

    private static function workspaceId(
        CashierV3OperatorScope $operatorScope,
        string $stateContextId
    ): string {
        $workspaceId = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if (strlen($workspaceId) > 64) {
            throw self::invalid(
                'checkout_submission_workspace_id_invalid',
                '当前收银工作台标识无效，请刷新后重试。'
            );
        }
        return $workspaceId;
    }

    private static function submissionIdempotencyKey($value): string
    {
        if (!is_string($value)
            || preg_match(
                '/^CHECKOUT_PREPARE-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $value
            ) !== 1) {
            throw self::invalid(
                'checkout_submission_idempotency_key_invalid',
                '本次结账确认请求标识无效，请重试。'
            );
        }
        return $value;
    }

    private function serverIdSecret(): string
    {
        $secret = $this->serverIdSecret;
        if ($secret === '' && function_exists('config')) {
            $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        }
        if (strlen($secret) < 32) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账请求签名服务尚未配置，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_namespace_secret_missing']
            );
        }
        return $secret;
    }

    private static function listValue($value, string $reason): array
    {
        if (!is_array($value)
            || array_keys($value) !== ($value ? range(0, count($value) - 1) : [])) {
            throw self::invalid($reason, '结账对象版本格式无效，请刷新后重试。');
        }
        return $value;
    }

    private static function translateContractFailure(
        CashierV3CheckoutSettlementContractException $exception
    ): CashierV3CommandException {
        if ($exception->reason() === 'checkout_request_version_conflict') {
            return CashierV3CommandException::versionConflict(
                '结账信息已被其他操作更新，请刷新后重试。',
                ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
            );
        }
        if (in_array($exception->reason(), [
            'checkout_idempotency_key_conflict',
            'checkout_resource_plan_idempotency_conflict',
        ], true)) {
            return new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '本次操作请求标识已用于其他内容，请重新操作。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
            );
        }
        return CashierV3CommandException::invalidContext(
            '结账草稿已变化或资料不完整，请刷新结账页面后重试。',
            ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
        );
    }

    private static function versionConflict(string $reason): CashierV3CommandException
    {
        return CashierV3CommandException::versionConflict(
            '结账信息已被其他操作更新，请刷新后重试。',
            ['reason' => $reason]
        );
    }

    private static function invalid(
        string $reason,
        string $message,
        array $detail = []
    ): CashierV3CommandException {
        return CashierV3CommandException::invalidContext(
            $message,
            ['reason' => $reason] + $detail
        );
    }
}

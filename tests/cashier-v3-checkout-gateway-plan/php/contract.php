<?php
declare(strict_types=1);

namespace app\services {
    // The gateway only needs its empty base type in this pure contract test.
    abstract class BaseServices
    {
    }
}

namespace {
    use app\services\cashier\v3\CashierV3CommandContextServices;
    use app\services\cashier\v3\CashierV3CommandException;
    use app\services\cashier\v3\CashierV3CommandGatewayServices;
    use app\services\cashier\v3\CashierV3DataScopeContext;
    use app\services\cashier\v3\CashierV3OperatorScope;
    use app\services\cashier\v3\CashierV3ResourceKindCatalog;
    use app\services\cashier\v3\CashierV3ResourceScope;
    use app\services\cashier\v3\registry\CashierV3ContextPolicy;
    use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
    use app\services\cashier\v3\settlement\CashierV3CheckoutResourcePlanLoader;
    use app\services\cashier\v3\settlement\CashierV3CheckoutResourcePlanRepository;
    use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedResourcePlan;

    $backend = getenv('CHECKOUT_GATEWAY_PLAN_BACKEND_ROOT')
        ?: dirname(__DIR__, 3) . '/后端代码';

    spl_autoload_register(static function (string $class) use ($backend): void {
        if (strpos($class, 'app\\') !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, 4));
        $file = $backend . '/app/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    final class CheckoutGatewayPlanProbe extends CashierV3CommandGatewayServices
    {
        public function __construct(CashierV3CommandContextServices $contexts)
        {
            $this->contextServices = $contexts;
        }

        public function expandPlan(
            string $requestId,
            array $contexts,
            array $contract,
            CashierV3DataScopeContext $dataScope
        ): array {
            return $this->expandFollowUpFromCheckoutResourcePlan(
                $requestId,
                $contexts,
                $contract,
                'submit-checkout',
                $dataScope
            );
        }

        public function effectiveHash(array $contexts, array $plan): string
        {
            return $this->effectiveCheckoutResourcePlanContextsHash($contexts, $plan);
        }

        public function replayHash(array $receipt, array $clientContexts): string
        {
            return $this->resourcePlanReplayContextsHash(
                $receipt,
                $clientContexts,
                'submit-checkout'
            );
        }

        public function recheck(string $requestId, array $contract): array
        {
            return $this->revalidateFollowUpCheckoutResourcePlan(
                $requestId,
                $contract,
                'submit-checkout'
            );
        }

        public function consumePlan(
            string $requestId,
            array $plan,
            CashierV3DataScopeContext $dataScope
        ): void {
            $this->consumeCheckoutResourcePlanInTx($requestId, $plan, $dataScope);
        }

        public function exportForReceipt(array $contexts): array
        {
            return $this->exportContexts($contexts);
        }

        public function expandServerResources(
            array $contexts,
            array $contract,
            string $action,
            array $payload,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope,
            string $stateContextId
        ): array {
            return $this->expandFromServerResourceDiscovery(
                $contexts,
                $contract,
                $action,
                $payload,
                $operatorScope,
                $dataScope,
                $stateContextId
            );
        }

        public function recheckServerResources(
            array $contexts,
            array $contract,
            string $action,
            array $payload,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope,
            string $stateContextId
        ): array {
            return $this->revalidateServerResourceDiscovery(
                $contexts,
                $contract,
                $action,
                $payload,
                $operatorScope,
                $dataScope,
                $stateContextId
            );
        }
    }

    final class CheckoutGatewayPlanFakeRepository implements CashierV3CheckoutResourcePlanRepository
    {
        /** @var array<int,array> */
        public $consumeCalls = [];

        public function persistInTx(
            string $requestId,
            CashierV3CheckoutVerifiedResourcePlan $plan,
            int $preparedAt
        ): array {
            return ['status' => 'active'];
        }

        public function invalidateInTx(
            string $requestId,
            string $tenantId,
            int $storeId,
            int $boundRequestVersion,
            string $reason
        ): array {
            return ['status' => 'invalidated'];
        }

        public function markConsumedInTx(
            string $requestId,
            string $tenantId,
            int $storeId,
            int $boundRequestVersion,
            string $expectedFingerprint
        ): array {
            $this->consumeCalls[] = [
                'requestId' => $requestId,
                'tenantId' => $tenantId,
                'storeId' => $storeId,
                'boundRequestVersion' => $boundRequestVersion,
                'expectedFingerprint' => $expectedFingerprint,
            ];
            return ['affected' => 1, 'status' => 'consumed'];
        }

        public function supersedeActiveBeforeVersionInTx(
            string $requestId,
            string $tenantId,
            int $storeId,
            int $nextVersion,
            string $reason
        ): array {
            return ['status' => 'superseded'];
        }
    }

    $passed = 0;
    $failed = 0;

    function gatewayPlanOk(string $name, bool $condition, string $detail = ''): void
    {
        global $passed, $failed;
        if ($condition) {
            $passed++;
            echo "PASS {$name}\n";
            return;
        }
        $failed++;
        echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
    }

    function gatewayPlanReason(callable $callback): string
    {
        try {
            $callback();
        } catch (CashierV3CommandException $exception) {
            return (string)($exception->getDetail()['reason'] ?? $exception->getResultCode());
        } catch (\Throwable $throwable) {
            return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
        }
        return '';
    }

    function gatewayPlanRow(
        string $kind,
        string $id,
        int $version,
        array $roles,
        string $accessMode = 'read'
    ): array {
        $scopeType = CashierV3ResourceKindCatalog::scopeTypeOf($kind);
        return [
            'tenantId' => 'merchant-1',
            'storeId' => 7,
            'kind' => $kind,
            'id' => $id,
            'scopeType' => $scopeType,
            'scopeId' => $scopeType === CashierV3ResourceScope::TYPE_STORE ? '7' : 'merchant-1',
            'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf($kind),
            'expectedVersion' => $version,
            'roles' => $roles,
            'accessMode' => $accessMode,
            'providerContractVersion' => 'provider-' . $kind . '-v1',
            'authorityFingerprint' => hash('sha256', $kind . '|' . $id . '|' . $version),
        ];
    }

    function gatewayPlanPayload(
        string $requestId,
        int $boundVersion,
        array $rows
    ): array {
        $plan = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1',
            7,
            $boundVersion,
            $rows
        );
        return [
            'contractVersion' => CashierV3CheckoutResourcePlanLoader::CONTRACT_VERSION,
            'planContractVersion' => CashierV3CheckoutVerifiedResourcePlan::CONTRACT_VERSION,
            'requestId' => $requestId,
            'tenantId' => 'merchant-1',
            'storeId' => 7,
            'requestVersion' => $boundVersion,
            'requestStatus' => 'ready_for_submit',
            'boundRequestVersion' => $boundVersion,
            'resourcePlanFingerprint' => $plan->fingerprint(),
            'resourceCount' => $plan->resourceCount(),
            'roleCount' => $plan->roleCount(),
            'resources' => $plan->resources(),
            'trustedContexts' => $plan->trustedContexts(),
        ];
    }

    function gatewayPlanWithScopes(array $contexts): array
    {
        foreach ($contexts as &$context) {
            $type = CashierV3ResourceKindCatalog::scopeTypeOf((string)$context['kind']);
            $id = $type === CashierV3ResourceScope::TYPE_STORE ? '7' : 'merchant-1';
            $context['scope'] = CashierV3ResourceScope::of($type, $id);
        }
        unset($context);
        return $contexts;
    }

    function gatewayPlanByPhysical(array $contexts): array
    {
        $out = [];
        foreach ($contexts as $context) {
            $out[$context['kind'] . ':' . $context['id']] = $context;
        }
        return $out;
    }

    $requestId = 'CKR-' . str_repeat('a', 40);
    $workspaceId = 'ws:7:11:state-1';
    $boundVersion = 3;
    $contextServices = new CashierV3CommandContextServices();
    $registry = (new \ReflectionClass(CashierV3ContextPolicyRegistry::class))
        ->newInstanceWithoutConstructor();
    $policy = new CashierV3ContextPolicy(
        'submit-checkout',
        ['cashier_workspace', 'checkout_request'],
        [],
        [$registry, 'resolveCheckoutSubmitBranch'],
        ['cashier_workspace', 'checkout_request'],
        ['service_order', 'hang_order', 'reservation', 'room'],
        ['service_order', 'hang_order', 'reservation', 'room']
    );
    $contract = $policy->resolve(
        ['checkoutRequestId' => $requestId],
        [
            'workspace_id' => $workspaceId,
            'store_id' => 7,
            'operator_id' => 11,
            'state_context_id' => 'state-1',
        ]
    );
    $clientContexts = [
        ['kind' => 'cashier_workspace', 'id' => $workspaceId, 'expectedVersion' => 9],
        ['kind' => 'checkout_request', 'id' => $requestId, 'expectedVersion' => $boundVersion],
    ];
    $validated = $contextServices->validate($clientContexts, $contract);

    $allowed = $contract['allowed'];
    sort($allowed, SORT_STRING);
    gatewayPlanOk(
        'submit accepts only workspace plus checkout request',
        $allowed === ['cashier_workspace', 'checkout_request']
            && !empty($contract['expand_from_checkout_resource_plan'])
            && array_column($validated, 'kind') === ['checkout_request', 'cashier_workspace']
    );
    gatewayPlanOk(
        'client extra hidden context is rejected',
        gatewayPlanReason(static function () use (
            $contextServices,
            $clientContexts,
            $contract
        ): void {
            $extra = $clientContexts;
            $extra[] = ['kind' => 'member', 'id' => '93', 'expectedVersion' => 4];
            $contextServices->validate($extra, $contract);
        }) === 'kind_not_allowed'
    );
    gatewayPlanOk(
        'client missing checkout request is rejected',
        gatewayPlanReason(static function () use (
            $contextServices,
            $clientContexts,
            $contract
        ): void {
            $contextServices->validate([$clientContexts[0]], $contract);
        }) === 'identity_missing'
    );

    $rowsA = [
        gatewayPlanRow('inventory_batch', '10', 5, ['inventory_batch:10'], 'mutate'),
        gatewayPlanRow('member', '93', 4, ['member_authority'], 'read'),
        gatewayPlanRow('inventory_batch', '2', 6, ['inventory_batch:2'], 'mutate'),
        gatewayPlanRow('member_balance', '93', 7, ['checkout_balance'], 'mutate'),
        gatewayPlanRow('member', '93', 4, ['checkout_member'], 'mutate'),
    ];
    $planA = gatewayPlanPayload($requestId, $boundVersion, $rowsA);
    $repository = new CheckoutGatewayPlanFakeRepository();
    $loaderCalls = 0;
    $gateway = new CheckoutGatewayPlanProbe($contextServices);
    $gateway->setCheckoutResourcePlanServices(
        static function (string $loadedRequestId) use (&$loaderCalls, $requestId, $planA): array {
            $loaderCalls++;
            return $loadedRequestId === $requestId ? $planA : [];
        },
        $repository
    );
    $dataScope = new CashierV3DataScopeContext(
        11,
        21,
        7,
        'merchant-1',
        'org-1',
        [7],
        CashierV3DataScopeContext::MODE_STORES,
        [],
        false,
        '',
        'permission-v1',
        [],
        []
    );
    $expandedA = $gateway->expandPlan(
        $requestId,
        $validated,
        $contract,
        $dataScope
    );
    $expandedByPhysical = gatewayPlanByPhysical($expandedA['contexts']);
    gatewayPlanOk(
        'server plan supplies full resources roles and strongest access',
        array_keys($expandedByPhysical) === [
            'member:93',
            'member_balance:93',
            'inventory_batch:2',
            'inventory_batch:10',
            'checkout_request:' . $requestId,
            'cashier_workspace:' . $workspaceId,
        ]
            && $expandedByPhysical['member:93']['roles'] === ['checkout_member', 'member_authority']
            && $expandedByPhysical['member:93']['resource_plan_access_mode'] === 'mutate'
            && in_array('checkout_balance', $expandedA['contract']['required_touched_roles'], true)
    );
    gatewayPlanOk(
        'catalog orders canonical numeric ids and opaque ids correctly',
        CashierV3ResourceKindCatalog::compareResourceIds('2', '10') < 0
            && CashierV3ResourceKindCatalog::compareResourceIds('A10', 'A2') < 0
            && array_column($planA['resources'], 'id') === ['93', '93', '2', '10']
    );

    $wrongVersionContexts = $clientContexts;
    $wrongVersionContexts[1]['expectedVersion'] = $boundVersion + 1;
    $wrongVersionValidated = $contextServices->validate($wrongVersionContexts, $contract);
    gatewayPlanOk(
        'client checkout request version must equal plan bound version',
        gatewayPlanReason(static function () use (
            $gateway,
            $requestId,
            $wrongVersionValidated,
            $contract,
            $dataScope
        ): void {
            $gateway->expandPlan(
                $requestId,
                $wrongVersionValidated,
                $contract,
                $dataScope
            );
        }) === 'checkout_resource_plan_bound_version_mismatch'
    );

    $scopedA = gatewayPlanWithScopes($expandedA['contexts']);
    $hashA = $gateway->effectiveHash($scopedA, $planA);
    $rowsB = $rowsA;
    $rowsB[4]['roles'] = ['checkout_member_changed'];
    $planB = gatewayPlanPayload($requestId, $boundVersion, $rowsB);
    $gateway->setCheckoutResourcePlanServices(
        static function (string $loadedRequestId) use ($requestId, $planB): array {
            return $loadedRequestId === $requestId ? $planB : [];
        },
        $repository
    );
    $expandedB = $gateway->expandPlan($requestId, $validated, $contract, $dataScope);
    $scopedB = gatewayPlanWithScopes($expandedB['contexts']);
    $hashB = $gateway->effectiveHash($scopedB, $planB);
    gatewayPlanOk(
        'effective contexts hash is bound to server plan fingerprint and roles',
        $planA['resourcePlanFingerprint'] !== $planB['resourcePlanFingerprint']
            && $hashA !== $hashB
            && $hashA === $gateway->effectiveHash($scopedA, $planA)
    );

    gatewayPlanOk(
        'locked plan fingerprint drift is rejected',
        gatewayPlanReason(static function () use (
            $gateway,
            $requestId,
            $expandedA
        ): void {
            $gateway->recheck($requestId, $expandedA['contract']);
        }) === 'checkout_resource_plan_changed_after_lock'
    );

    $gateway->consumePlan($requestId, $planA, $dataScope);
    gatewayPlanOk(
        'consume forwards exact request scope version and fingerprint',
        count($repository->consumeCalls) === 1
            && $repository->consumeCalls[0] === [
                'requestId' => $requestId,
                'tenantId' => 'merchant-1',
                'storeId' => 7,
                'boundRequestVersion' => $boundVersion,
                'expectedFingerprint' => $planA['resourcePlanFingerprint'],
            ]
    );

    $receipt = [
        'contexts_json' => json_encode(
            $gateway->exportForReceipt($scopedA),
            JSON_UNESCAPED_UNICODE
        ),
        'contexts_hash' => $hashA,
    ];
    $loaderCallsBeforeReplay = $loaderCalls;
    $gateway->setCheckoutResourcePlanServices(
        static function (string $loadedRequestId): array {
            throw new \RuntimeException('consumed plan must not be loaded on replay: ' . $loadedRequestId);
        },
        $repository
    );
    gatewayPlanOk(
        'successful receipt replays after plan consumption from stored snapshot',
        $gateway->replayHash($receipt, $validated) === $hashA
            && $loaderCalls === $loaderCallsBeforeReplay
    );
    gatewayPlanOk(
        'receipt replay rejects client extra hidden context',
        gatewayPlanReason(static function () use (
            $gateway,
            $receipt,
            $validated
        ): void {
            $extra = $validated;
            $extra[] = [
                'kind' => 'member',
                'id' => '93',
                'expected_version' => 4,
            ];
            $gateway->replayHash($receipt, $extra);
        }) === 'checkout_resource_plan_replay_context_mismatch'
    );
    gatewayPlanOk(
        'receipt replay rejects a different client request version',
        gatewayPlanReason(static function () use (
            $gateway,
            $receipt,
            $validated
        ): void {
            $different = $validated;
            $different[0]['expected_version']++;
            $gateway->replayHash($receipt, $different);
        }) === 'checkout_resource_plan_replay_context_mismatch'
    );

    $operatorScope = new CashierV3OperatorScope(7, 11, 'org-1', 'merchant-1');
    $serverPhases = [];
    $serverResourceVersion = 5;
    $serverDiscoverer = static function (array $scope) use (
        &$serverPhases,
        &$serverResourceVersion
    ): array {
        $serverPhases[] = [
            'phase' => (string)($scope['phase'] ?? ''),
            'contextKinds' => array_column((array)($scope['contexts'] ?? []), 'kind'),
        ];
        return ['resources' => [
            [
                'kind' => 'catalog_sku',
                'id' => '503',
                'expectedVersion' => $serverResourceVersion,
                'roles' => ['sale_catalog_sku'],
                'accessMode' => 'read',
                'providerContractVersion' => 'sale-catalog-v1',
            ],
            [
                'kind' => 'catalog_card_definition',
                'id' => '501',
                'expectedVersion' => 3,
                'roles' => ['sale_catalog_card_definition'],
                'accessMode' => 'read',
                'providerContractVersion' => 'sale-catalog-v1',
            ],
            [
                'kind' => 'catalog_product',
                'id' => '502',
                'expectedVersion' => 4,
                'roles' => ['sale_catalog_product'],
                'accessMode' => 'read',
                'providerContractVersion' => 'sale-catalog-v1',
            ],
        ]];
    };
    $serverPolicy = new CashierV3ContextPolicy(
        'choose-catalog-item',
        ['cashier_workspace'],
        [],
        null,
        ['cashier_workspace']
    );
    $serverPolicy->configureServerResourceDiscovery(
        $serverDiscoverer,
        ['sale_catalog_card_definition', 'sale_catalog_product', 'sale_catalog_sku'],
        ['catalog_card_definition', 'catalog_product', 'catalog_sku']
    );
    $serverContract = $serverPolicy->resolve([], [
        'workspace_id' => $workspaceId,
        'store_id' => 7,
        'operator_id' => 11,
        'state_context_id' => 'state-1',
    ]);
    $serverClientContexts = [[
        'kind' => 'cashier_workspace',
        'id' => $workspaceId,
        'expected_version' => 9,
        'role' => 'cashier_workspace',
    ]];
    $serverExpanded = $gateway->expandServerResources(
        $serverClientContexts,
        $serverContract,
        'choose-catalog-item',
        ['skuId' => 503],
        $operatorScope,
        $dataScope,
        'state-1'
    );
    gatewayPlanOk(
        'server-only discovery expands hidden catalog resources before locking',
        $serverPhases === [[
            'phase' => 'discover',
            'contextKinds' => ['cashier_workspace'],
        ]]
            && array_column($serverExpanded['contexts'], 'kind') === [
                'catalog_card_definition',
                'catalog_product',
                'catalog_sku',
                'cashier_workspace',
            ]
            && !empty($serverExpanded['contract']['server_resource_discovery_recheck_required'])
    );
    gatewayPlanOk(
        'catalog lock order is fixed at 41 then 42 then 43 then workspace 150',
        array_map(static function (array $context): int {
            return CashierV3ResourceKindCatalog::lockOrderOf((string)$context['kind']);
        }, $serverExpanded['contexts']) === [41, 42, 43, 150]
    );

    $serverStable = $gateway->recheckServerResources(
        $serverExpanded['contexts'],
        $serverExpanded['contract'],
        'choose-catalog-item',
        ['skuId' => 503],
        $operatorScope,
        $dataScope,
        'state-1'
    );
    gatewayPlanOk(
        'all-lock rediscovery accepts the identical authority set',
        array_column($serverPhases, 'phase') === ['discover', 'revalidate']
            && empty($serverStable['server_resource_discovery_recheck_required'])
    );

    $serverPhases = [];
    $serverResourceVersion = 5;
    $serverExpandedBeforeDrift = $gateway->expandServerResources(
        $serverClientContexts,
        $serverContract,
        'choose-catalog-item',
        ['skuId' => 503],
        $operatorScope,
        $dataScope,
        'state-1'
    );
    $serverResourceVersion = 6;
    gatewayPlanOk(
        'all-lock rediscovery rejects authority drift',
        gatewayPlanReason(static function () use (
            $gateway,
            $serverExpandedBeforeDrift,
            $operatorScope,
            $dataScope
        ): void {
            $gateway->recheckServerResources(
                $serverExpandedBeforeDrift['contexts'],
                $serverExpandedBeforeDrift['contract'],
                'choose-catalog-item',
                ['skuId' => 503],
                $operatorScope,
                $dataScope,
                'state-1'
            );
        }) === 'server_resource_discovery_drift'
            && array_column($serverPhases, 'phase') === ['discover', 'revalidate']
    );

    $gatewaySource = file_get_contents(
        $backend . '/app/services/cashier/v3/CashierV3CommandGatewayServices.php'
    );
    $consumePosition = strpos(
        $gatewaySource,
        '$this->consumeCheckoutResourcePlanInTx('
    );
    $businessPosition = strpos($gatewaySource, '$result = $business([', $consumePosition);
    $normalizedPosition = strpos(
        $gatewaySource,
        '$normalized = $this->normalizeBusinessResult(',
        $businessPosition
    );
    $bumpPosition = strpos(
        $gatewaySource,
        '$this->versionServices->bumpTouched(',
        $normalizedPosition
    );
    gatewayPlanOk(
        'plan consumption precedes request CAS business and version bump',
        is_int($businessPosition)
            && is_int($normalizedPosition)
            && is_int($consumePosition)
            && is_int($bumpPosition)
            && $consumePosition < $businessPosition
            && $businessPosition < $normalizedPosition
            && $normalizedPosition < $bumpPosition
    );

    echo "CHECKOUT_GATEWAY_PLAN_CONTRACT passed={$passed} failed={$failed}\n";
    exit($failed === 0 ? 0 : 1);
}

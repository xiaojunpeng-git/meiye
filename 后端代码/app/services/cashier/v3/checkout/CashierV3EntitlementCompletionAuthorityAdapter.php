<?php

namespace app\services\cashier\v3\checkout;

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementDebtGuardProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationContributorVersionProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationGuardVersionProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContracts;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderDataScope;
use app\services\cashier\v3\checkout\provider\CashierV3InventoryCompletionGatewayAdapter;
use app\services\cashier\v3\checkout\provider\CashierV3PerformanceRuleProvider;
use app\services\cashier\v3\checkout\provider\CashierV3StaffProfileProvider;
use app\services\cashier\v3\service\CashierV3ServiceOrderOccupationAuthorityProvider;
use app\services\cashier\v3\service\CashierV3ServiceOrderState;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionExecutionContext;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutRequestRepository;
use app\services\order\store\WriteOffOrderServices;
use app\services\product\inventory\completion\InventoryCompletionDataScope;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;
use think\facade\Log;

/**
 * Server-only authority adapter for entitlement completion inside checkout.
 *
 * Discovery is read-only. The locked build must run inside Gateway's final
 * transaction after every discovered context has been scope-resolved, locked
 * and version-checked. No client payload value is used as an authority field.
 */
final class CashierV3EntitlementCompletionAuthorityAdapter
{
    public const CONTRACT_VERSION = 'cashier-v3-entitlement-completion-authority-adapter-v1';
    public const DISCOVERY_CONTRACT_VERSION = 'cashier-v3-entitlement-completion-discovery-v1';

    private const DISCOVERY_ACTION = 'prepare-checkout-submission';
    private const ENTITLEMENT_ROLE = 'entitlement_service';
    private const MAX_LINES = 100;

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3StaffProfileProvider */
    private $staffProfiles;

    /** @var CashierV3PerformanceRuleProvider */
    private $performanceRules;

    /** @var CashierV3EntitlementDebtGuardProvider */
    private $debtGuards;

    /** @var CashierV3EntitlementOccupationProvider */
    private $occupations;

    /** @var CashierV3InventoryCompletionGatewayAdapter */
    private $inventory;

    /** @var CashierV3EntitlementOccupationGuardVersionProvider */
    private $occupationGuards;

    /** @var CashierV3EntitlementOccupationContributorVersionProvider */
    private $occupationContributors;

    /** @var WriteOffOrderServices|null */
    private $writeoffServices;

    /** @var CashierV3CardRuleEntitlementAuthorityServices */
    private $cardRules;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3StaffProfileProvider $staffProfiles,
        CashierV3PerformanceRuleProvider $performanceRules,
        CashierV3EntitlementDebtGuardProvider $debtGuards,
        ?CashierV3EntitlementOccupationProvider $occupations = null,
        ?CashierV3InventoryCompletionGatewayAdapter $inventory = null,
        ?CashierV3EntitlementOccupationGuardVersionProvider $occupationGuards = null,
        ?CashierV3EntitlementOccupationContributorVersionProvider $occupationContributors = null,
        ?WriteOffOrderServices $writeoffServices = null,
        ?CashierV3CardRuleEntitlementAuthorityServices $cardRules = null
    ) {
        $repository = new ThinkPhpCashierV3ServiceOrderRepository();
        $this->workspace = $workspace;
        $this->staffProfiles = $staffProfiles;
        $this->performanceRules = $performanceRules;
        $this->debtGuards = $debtGuards;
        $this->occupations = $occupations ?: new CashierV3EntitlementOccupationProvider(
            new CashierV3ServiceOrderOccupationAuthorityProvider($repository)
        );
        $this->inventory = $inventory ?: new CashierV3InventoryCompletionGatewayAdapter();
        $this->occupationGuards = $occupationGuards
            ?: new CashierV3EntitlementOccupationGuardVersionProvider($repository);
        $this->occupationContributors = $occupationContributors
            ?: new CashierV3EntitlementOccupationContributorVersionProvider();
        $this->writeoffServices = $writeoffServices;
        $this->cardRules = $cardRules ?: new CashierV3CardRuleEntitlementAuthorityServices();
    }

    /** Callable contract for prepare-checkout-submission Gateway discovery. */
    public function discover(array $scope): array
    {
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!($operatorScope instanceof CashierV3OperatorScope)
            || !($dataScope instanceof CashierV3DataScopeContext)) {
            throw self::failure('authority_discovery_scope_missing');
        }
        $this->assertCheckoutDataScope($operatorScope, $dataScope);
        if ((string)($scope['action'] ?? '') !== self::DISCOVERY_ACTION) {
            throw self::failure('authority_discovery_action_invalid');
        }
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $requestId = self::checkoutRequestId($payload['checkoutRequestId'] ?? null);
        $requestVersion = self::positiveInt(
            $payload['checkoutRequestVersion'] ?? null,
            'authority_discovery_request_version_invalid'
        );
        $stateContextId = self::token(
            $scope['state_context_id'] ?? null,
            64,
            'authority_discovery_state_context_invalid'
        );
        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );

        $request = $this->requestRowForDiscovery(
            $requestId,
            $requestVersion,
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        $draft = $this->workspace->discoverCheckoutDraft(
            $workspaceId,
            $stateContextId,
            $operatorScope
        );
        $checkoutLines = $this->requestLinesForDiscovery($requestId, $requestVersion);
        $this->assertDiscoveryWorkspaceMatchesRequest(
            (array)$draft['rows'],
            $checkoutLines
        );
        $entitlementLines = $this->entitlementCheckoutLines($checkoutLines);
        if ($entitlementLines === []) {
            return [
                'contractVersion' => self::DISCOVERY_CONTRACT_VERSION,
                'resources' => [],
            ];
        }
        $this->assertEntitlementCompletionFeature($dataScope);

        $source = $this->sourceFromContexts((array)($scope['contexts'] ?? []));
        $memberId = self::positiveInt(
            $request['member_id'] ?? null,
            'authority_discovery_member_invalid'
        );
        $authorities = $this->loadEntitlementAuthorities(
            $entitlementLines,
            $memberId,
            $operatorScope,
            $dataScope
        );
        $intents = $this->serviceIntentsFromWorkspaceRows((array)$draft['rows'], $entitlementLines);

        $resources = [];
        $this->addResource(
            $resources,
            'member',
            (string)$memberId,
            $this->shadowVersion('member', $memberId),
            'member',
            self::CONTRACT_VERSION
        );
        foreach ($entitlementLines as $line) {
            $lineId = (string)$line['line_id'];
            $authority = $authorities[$lineId];
            $detailId = (int)$authority['detail']['id'];
            $holderId = (int)$authority['holder']['id'];
            $originOrderId = (int)$authority['order']['id'];
            $projectId = (int)$authority['detail']['product_id'];

            $this->addResource(
                $resources,
                'member_benefit_pool',
                (string)$detailId,
                (int)$line['project_version'],
                'benefit_pool:' . $detailId,
                self::CONTRACT_VERSION
            );
            $this->addResource(
                $resources,
                'card_holder',
                (string)$holderId,
                (int)$line['source_version'],
                'card_holder:' . $holderId,
                self::CONTRACT_VERSION
            );
            $this->addResource(
                $resources,
                'entitlement_debt_guard',
                CashierV3EntitlementDebtGuardProvider::identityForOrder($originOrderId),
                $this->debtGuardDiscoveryVersion($dataScope->tenantId(), $originOrderId),
                'entitlement_debt_guard:' . $originOrderId,
                $this->debtGuards->contractVersion()
            );
            $this->addResource(
                $resources,
                CashierV3EntitlementOccupationGuardVersionProvider::KIND,
                (string)$detailId,
                $this->occupationGuards->discoverVersion($detailId, $operatorScope, $dataScope),
                'occupation_guard:' . $detailId,
                $this->occupationGuards->contractVersion()
            );
            $this->addResource(
                $resources,
                CashierV3PerformanceRuleProvider::KIND,
                (string)$projectId,
                $this->performanceRules->discoverVersion($projectId, $operatorScope, $dataScope),
                'performance_rule:' . $lineId,
                $this->performanceRules->contractVersion()
            );

            foreach ($intents[$lineId]['craftsmanIds'] as $staffId) {
                $this->addResource(
                    $resources,
                    CashierV3StaffProfileProvider::KIND,
                    (string)$staffId,
                    $this->staffDiscoveryVersion($dataScope, $staffId),
                    'staff:' . $staffId,
                    $this->staffProfiles->contractVersion()
                );
            }
            foreach ($this->discoverOccupationContributors(
                $detailId,
                $source,
                $operatorScope,
                $dataScope
            ) as $contributor) {
                $this->addResource(
                    $resources,
                    $contributor['kind'],
                    (string)$contributor['id'],
                    (int)$contributor['version'],
                    'occupation:' . $lineId . ':' . $contributor['kind'] . ':' . $contributor['id'],
                    $this->occupationContributors->contractVersion()
                );
            }
        }

        $inventoryRequest = $this->inventoryRequest($authorities, $dataScope);
        $inventoryScope = $this->inventoryScopeFromRequest($request, $operatorScope, $dataScope);
        $inventoryPack = $this->inventory->discover($inventoryRequest, $inventoryScope);
        foreach ((array)$inventoryPack['resources'] as $resource) {
            $this->mergeProviderResource($resources, $resource);
        }

        $resources = $this->sortResources(array_values($resources));
        return [
            'contractVersion' => self::DISCOVERY_CONTRACT_VERSION,
            'resources' => $resources,
        ];
    }

    /**
     * Rebuild and validate the exact entitlement completion bundle after all
     * Gateway contexts are locked. The caller supplies only server objects.
     */
    public function buildAfterGatewayLocks(array $aggregate, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('entitlementCompletionAuthorityAdapter');
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!($operatorScope instanceof CashierV3OperatorScope)
            || !($dataScope instanceof CashierV3DataScopeContext)) {
            throw self::failure('authority_locked_scope_missing');
        }
        $this->assertCheckoutDataScope($operatorScope, $dataScope);
        $this->assertEntitlementCompletionFeature($dataScope);
        $this->assertLockedAggregate($aggregate, $operatorScope, $dataScope);
        $request = (array)$aggregate['request'];
        $requestId = self::checkoutRequestId($request['request_id'] ?? null);
        $requestVersion = self::positiveInt(
            $request['request_version'] ?? null,
            'authority_locked_request_version_invalid'
        );
        $workspaceId = self::token(
            $request['workspace_id'] ?? null,
            64,
            'authority_locked_workspace_invalid'
        );
        $stateContextId = self::token(
            $request['state_context_id'] ?? null,
            64,
            'authority_locked_state_context_invalid'
        );
        $commandKey = self::token(
            $scope['idempotency_key'] ?? null,
            128,
            'authority_locked_idempotency_key_invalid'
        );
        $now = isset($scope['server_time'])
            ? self::positiveInt($scope['server_time'], 'authority_locked_server_time_invalid')
            : time();

        $entitlementLines = $this->entitlementCheckoutLines((array)$aggregate['lines']);
        if ($entitlementLines === []) {
            throw self::failure('authority_locked_entitlement_lines_missing');
        }
        $memberId = self::positiveInt(
            $request['member_id'] ?? null,
            'authority_locked_member_invalid'
        );
        $serviceIntents = $this->workspace->lockCheckoutServiceIntentsInTx(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            (array)$aggregate['lines']
        );
        $intentsByLine = $this->serviceIntentsByLine($serviceIntents, $entitlementLines);
        $authorities = $this->loadEntitlementAuthorities(
            $entitlementLines,
            $memberId,
            $operatorScope,
            $dataScope
        );
        $source = $this->sourceFromVerifiedAggregate($aggregate);

        $gateway = $this->lockedGatewayResources(
            (array)($scope['contexts'] ?? []),
            (array)($scope['locked_versions'] ?? []),
            $scope['checkout_resource_plan'] ?? null,
            $requestId,
            $requestVersion,
            $dataScope
        );
        $workspaceVersion = $this->lockedVersion($gateway, 'cashier_workspace', $workspaceId);
        $memberVersion = $this->lockedVersion($gateway, 'member', (string)$memberId);

        $inventoryRequest = $this->inventoryRequest($authorities, $dataScope);
        $inventoryScope = $this->inventoryScopeFromRequest($request, $operatorScope, $dataScope);
        $inventorySnapshot = $this->inventory->lockSnapshotAfterGatewayLocks(
            $inventoryRequest,
            $inventoryScope
        );
        $this->assertInventoryResourcesLocked($inventorySnapshot, $gateway);

        $staffSnapshots = [];
        $staffById = [];
        foreach ($intentsByLine as $intent) {
            foreach ($intent['craftsmanIds'] as $staffId) {
                if (isset($staffById[$staffId])) {
                    continue;
                }
                $profile = $this->staffProfiles->lockProfileSnapshotInTx(
                    $staffId,
                    $operatorScope,
                    $dataScope
                );
                $expected = $this->lockedVersion($gateway, 'staff_profile', (string)$staffId);
                if ((int)$profile['staffVersion'] !== $expected) {
                    throw self::failure('authority_staff_version_mismatch', ['staffId' => $staffId]);
                }
                $staffById[$staffId] = $profile;
                $staffSnapshots[] = [
                    'staffId' => (int)$profile['staffId'],
                    'employeeId' => (int)$profile['employeeId'],
                    'staffName' => (string)$profile['staffName'],
                    'staffVersion' => (int)$profile['staffVersion'],
                    'storeId' => (int)$profile['storeId'],
                    'employeeTypeCodeSnapshot' => (string)$profile['employeeTypeCodeSnapshot'],
                    'employeeTypeAuthorityVersion' => (int)$profile['employeeTypeAuthorityVersion'],
                ];
            }
        }
        usort($staffSnapshots, static function (array $left, array $right): int {
            return $left['staffId'] <=> $right['staffId'];
        });

        $snapshotLines = [];
        foreach ($entitlementLines as $index => $line) {
            $lineId = (string)$line['line_id'];
            $authority = $authorities[$lineId];
            $detail = $authority['detail'];
            $holder = $authority['holder'];
            $order = $authority['order'];
            $intent = $intentsByLine[$lineId];
            $detailId = (int)$detail['id'];
            $holderId = (int)$holder['id'];
            $originOrderId = (int)$order['id'];
            $projectId = (int)$detail['product_id'];

            $sourceVersion = $this->lockedVersion($gateway, 'card_holder', (string)$holderId);
            $detailVersion = $this->lockedVersion($gateway, 'member_benefit_pool', (string)$detailId);
            if ($sourceVersion !== (int)$line['source_version']
                || $detailVersion !== (int)$line['project_version']) {
                throw self::failure('authority_entitlement_version_mismatch', ['lineId' => $lineId]);
            }
            $debtGuard = $this->debtGuards->lockOrCreateSnapshotInTx(
                $originOrderId,
                $operatorScope,
                $dataScope
            );
            if ((int)$debtGuard['version'] !== $this->lockedVersion(
                $gateway,
                'entitlement_debt_guard',
                (string)$debtGuard['identity']
            )) {
                throw self::failure('authority_debt_guard_version_mismatch', ['lineId' => $lineId]);
            }
            $guardId = (string)$detailId;
            $guardScope = CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_TENANT,
                $dataScope->tenantId()
            );
            $guardVersion = $this->occupationGuards->lockAndReadVersionWithDataScope(
                $guardScope,
                CashierV3EntitlementOccupationGuardVersionProvider::KIND,
                $guardId,
                $dataScope
            );
            if ((int)$guardVersion !== $this->lockedVersion(
                $gateway,
                CashierV3EntitlementOccupationGuardVersionProvider::KIND,
                $guardId,
                'occupation_guard:' . $detailId
            )) {
                throw self::failure('authority_occupation_guard_version_mismatch', ['lineId' => $lineId]);
            }

            $occupation = $this->occupations->lockSnapshotInTx([
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $dataScope->forcedStoreId(),
                'entitlementSourceDetailId' => $detailId,
                'source' => [
                    'type' => $source['type'],
                    'serviceOrderId' => $source['serviceOrderId'],
                    'reservationId' => $source['reservationId'],
                ],
            ], $operatorScope, $dataScope);
            foreach ($occupation['occupationContributors'] as $contributor) {
                $role = 'occupation:' . $lineId . ':' . $contributor['kind'] . ':' . $contributor['id'];
                if ((int)$contributor['version'] !== $this->lockedVersion(
                    $gateway,
                    (string)$contributor['kind'],
                    (string)$contributor['id'],
                    $role
                )) {
                    throw self::failure('authority_occupation_contributor_version_mismatch', [
                        'lineId' => $lineId,
                        'kind' => $contributor['kind'],
                        'id' => $contributor['id'],
                    ]);
                }
            }

            $performanceVersion = $this->lockedVersion(
                $gateway,
                'performance_rule',
                (string)$projectId,
                'performance_rule:' . $lineId
            );
            $performance = $this->performanceRules->snapshotAfterGatewayLock(
                $projectId,
                $performanceVersion,
                $dataScope
            );
            // A complete-mode labor fee is a checkout-scoped override.  It is
            // already locked by the workspace aggregate; project configuration
            // remains the default and is never mutated by this branch.
            $manualLaborFeeCents = $intent['laborManualFeeCents'] ?? null;
            if ($manualLaborFeeCents !== null) {
                $manualLaborFeeCents = (int)$manualLaborFeeCents;
                if ($manualLaborFeeCents < 0) {
                    throw self::failure('authority_manual_labor_fee_invalid', ['lineId' => $lineId]);
                }
                $performance['laborMode'] = CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED;
                $performance['laborConfiguredUnitAmountCents'] = $manualLaborFeeCents;
            }
            $craftsmen = [];
            foreach ($intent['craftsmanIds'] as $sequence => $staffId) {
                $profile = $staffById[$staffId];
                $settings = $intent['craftsmanSettingsById'][$staffId] ?? [];
                $craftsmen[] = [
                    'staffId' => $staffId,
                    'staffVersion' => (int)$profile['staffVersion'],
                    'staffName' => (string)$profile['staffName'],
                    'storeId' => (int)$profile['storeId'],
                    'active' => !empty($profile['active']),
                    'craftsmanEligible' => !empty($profile['craftsmanEligible']),
                    'sequence' => $sequence + 1,
                    'isPrimary' => $sequence === 0,
                    'laborWeight' => (int)($settings['laborWeight'] ?? 0),
                ];
            }
            $inventory = $inventorySnapshot['lineInventoryByLineId'][$lineId] ?? null;
            if (!is_array($inventory)) {
                throw self::failure('authority_inventory_line_snapshot_missing', ['lineId' => $lineId]);
            }
            $snapshotLines[] = [
                'lineId' => $lineId,
                'sortNo' => $index + 1,
                'lineRole' => self::ENTITLEMENT_ROLE,
                'entitlementInstanceType' => CashierV3EntitlementCompletionKernel::ENTITLEMENT_CARD_HOLDER,
                'entitlementInstanceId' => $holderId,
                'sourceKind' => $authority['sourceKind'],
                'isGift' => $authority['isGift'],
                'giftSourceType' => $authority['isGift']
                    ? CashierV3EntitlementCompletionKernel::GIFT_SOURCE_HOLDER_BACKED
                    : 'none',
                'giftId' => 0,
                'giftVersion' => 0,
                'holderId' => $holderId,
                'originOrderId' => $originOrderId,
                'sourceNameSnapshot' => (string)$line['source_name_snapshot'],
                'sourceCodeSnapshot' => (string)$line['source_code_snapshot'],
                'sourceDetailId' => $detailId,
                'projectId' => $projectId,
                'projectNameSnapshot' => (string)$line['project_name_snapshot'],
                'projectCategoryIdSnapshot' => max(0, (int)($line['category_id_snapshot'] ?? 0)),
                'projectCategoryNameSnapshot' => trim((string)($line['category_name_snapshot'] ?? '')) !== ''
                    ? (string)$line['category_name_snapshot']
                    : (string)$authority['projectCategoryName'],
                'sourceVersion' => $sourceVersion,
                'detailVersion' => $detailVersion,
                'holderActive' => true,
                'detailActive' => true,
                'usableAtSettlement' => true,
                'physicalRemainingTimes' => (int)$authority['physicalRemainingTimes'],
                'debtLimitedUsableTimes' => (int)$authority['debtLimitedUsableTimes'],
                'debtGuardId' => (string)$debtGuard['identity'],
                'debtGuardVersion' => (int)$debtGuard['version'],
                'occupiedTimes' => (int)$occupation['occupiedTimes'],
                'currentSourceConvertibleTimes' => (int)$occupation['currentSourceConvertibleTimes'],
                'occupationAuthorityComplete' => $occupation['occupationAuthorityComplete'] === true,
                'occupationContributors' => array_values($occupation['occupationContributors']),
                'purchaseAmount' => (string)$authority['purchaseAmount'],
                'totalPurchaseTimes' => (int)$authority['totalPurchaseTimes'],
                'amountCalculationVersion' => (string)$authority['amountCalculationVersion'],
                'allowedStoreIds' => [$dataScope->forcedStoreId()],
                'craftsmen' => $craftsmen,
                'performance' => $performance,
                'inventory' => $inventory,
            ];
        }

        $snapshot = $this->lockedSnapshot(
            $request,
            $snapshotLines,
            $inventorySnapshot,
            $source,
            $workspaceVersion,
            $memberVersion,
            $operatorScope,
            $dataScope,
            $now
        );
        $command = [
            'contractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
            'action' => CashierV3EntitlementCompletionKernel::ACTION,
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
            'memberId' => $memberId,
            'lines' => array_values(array_map(static function (array $line) use ($intentsByLine): array {
                $intent = $intentsByLine[$line['lineId']];
                return [
                    'lineId' => $line['lineId'],
                    'quantity' => (int)$intent['quantity'],
                    'serviceObject' => (string)$intent['serviceObject'],
                    'isExperience' => (bool)$intent['isExperience'],
                    'craftsmanIds' => array_values($intent['craftsmanIds']),
                ];
            }, $snapshotLines)),
        ];
        $kernelPlanResources = $this->kernelResourceSubset($gateway, $snapshot);
        $kernelPlan = CashierV3EntitlementCompletionKernel::plan(
            $command,
            $snapshot,
            $kernelPlanResources
        );
        $this->assertKernelAmountsMatchCheckout($kernelPlan, $entitlementLines);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'checkoutRequestId' => $requestId,
            'checkoutRequestVersion' => $requestVersion,
            'command' => $command,
            'lockedSnapshot' => $snapshot,
            'lockedResourcePlan' => $kernelPlanResources,
            'kernelPlan' => $kernelPlan,
            'staffSnapshots' => $staffSnapshots,
            'inventoryProviderSnapshot' => $inventorySnapshot,
            'inventoryDataScope' => $inventoryScope,
        ];
    }

    /**
     * Build the writer plan only after BusinessEventRecorder returned a real,
     * already-persisted checkout.completed descriptor in the same transaction.
     */
    public function buildPersistencePlanAfterEvents(
        array $kernelPlan,
        array $staffSnapshots,
        array $recordedEvent,
        CashierV3CheckoutSubmissionExecutionContext $executionContext,
        array $serverDocument
    ): CashierV3EntitlementCompletionPlanV1 {
        CashierV3TransactionGuard::assertInTransaction('entitlementCompletionPersistenceAfterEvents');
        self::assertExactKeys($serverDocument, [
            'documentId', 'documentNo', 'tenantNameSnapshot',
        ], 'server_document');
        $aggregate = $executionContext->aggregate();
        $request = is_array($aggregate['request'] ?? null)
            ? $aggregate['request']
            : [];
        $requestId = self::checkoutRequestId($request['request_id'] ?? null);
        $requestVersion = self::positiveInt(
            $request['request_version'] ?? null,
            'authority_persistence_request_version_invalid'
        );
        $eventExecution = $executionContext->eventExecution();
        $commandKey = self::token(
            $eventExecution->idempotencyKey(),
            128,
            'authority_persistence_command_key_invalid'
        );
        $executionAuthority = [
            'checkoutRequestId' => $requestId,
            'checkoutRequestVersion' => $requestVersion,
            'workspaceId' => (string)($request['workspace_id'] ?? ''),
            'stateContextId' => (string)($request['state_context_id'] ?? ''),
            'tenantId' => (string)($request['tenant_id'] ?? ''),
            'storeId' => (int)($request['store_id'] ?? 0),
            'memberId' => (int)($request['member_id'] ?? 0),
            'composition' => (string)($request['composition'] ?? ''),
            'commandIdempotencyKey' => $commandKey,
        ];
        try {
            $executionContext->assertMatchesAuthority($executionAuthority);
        } catch (\LogicException $exception) {
            throw self::failure('authority_persistence_execution_context_mismatch');
        }
        $entitlementBundle = $executionContext->entitlementBundle();
        if (!is_array($entitlementBundle['kernelPlan'] ?? null)
            || $entitlementBundle['kernelPlan'] !== $kernelPlan) {
            throw self::failure('authority_persistence_kernel_context_mismatch');
        }
        $eventId = self::positiveInt(
            $recordedEvent['event_id'] ?? null,
            'authority_recorded_event_id_invalid'
        );
        $eventNo = self::token(
            $recordedEvent['event_no'] ?? null,
            64,
            'authority_recorded_event_no_invalid'
        );
        $eventKey = self::text(
            $recordedEvent['event_key'] ?? null,
            255,
            'authority_recorded_event_key_invalid'
        );
        $eventDescriptor = null;
        foreach ($eventExecution->emittedEvents() as $candidate) {
            if ((int)($candidate['event_id'] ?? 0) === $eventId) {
                $eventDescriptor = $candidate;
                break;
            }
        }
        if (!is_array($eventDescriptor)
            || (string)($eventDescriptor['event_no'] ?? '') !== $eventNo
            || (string)($eventDescriptor['event_key'] ?? '') !== $eventKey
            || (string)($eventDescriptor['event_type'] ?? '') !== 'checkout.completed'
            || (string)($eventDescriptor['source_id'] ?? '') !== $requestId
            || (string)($eventDescriptor['command_idempotency_key'] ?? '') !== $commandKey
            || (string)($eventDescriptor['tenant_id'] ?? '') !== (string)$executionAuthority['tenantId']
            || (int)($eventDescriptor['store_id'] ?? 0) !== (int)$executionAuthority['storeId']
            || (int)($eventDescriptor['member_id'] ?? 0) !== (int)$executionAuthority['memberId']) {
            throw self::failure('authority_recorded_event_execution_mismatch', [
                'eventId' => $eventId,
            ]);
        }
        if ((string)($recordedEvent['event_type'] ?? '') !== 'checkout.completed'
            || (string)($recordedEvent['business_date'] ?? '') !== (string)($kernelPlan['businessDate'] ?? '')
            || (int)($recordedEvent['occurred_at'] ?? 0) !== (int)($kernelPlan['occurredAt'] ?? 0)
            || (int)($recordedEvent['settled_at'] ?? 0) !== (int)($kernelPlan['settledAt'] ?? 0)
            || (int)($recordedEvent['recorded_at'] ?? 0) !== (int)($kernelPlan['recordedAt'] ?? 0)
            || (string)($eventDescriptor['business_date'] ?? '') !== (string)$recordedEvent['business_date']
            || (int)($eventDescriptor['occurred_at'] ?? 0) !== (int)$recordedEvent['occurred_at']
            || (int)($eventDescriptor['settled_at'] ?? 0) !== (int)$recordedEvent['settled_at']
            || (int)($eventDescriptor['recorded_at'] ?? 0) !== (int)$recordedEvent['recorded_at']) {
            throw self::failure('authority_recorded_checkout_event_mismatch', [
                'eventId' => $eventId,
            ]);
        }
        $context = [
            'contractVersion' => CashierV3EntitlementCompletionPlanV1::CONTRACT_VERSION,
            'checkoutRequestId' => $requestId,
            'commandIdempotencyKey' => $commandKey,
            'businessEventNo' => $eventNo,
            'documentId' => self::token(
                $serverDocument['documentId'],
                64,
                'authority_document_id_invalid'
            ),
            'documentNo' => self::text(
                $serverDocument['documentNo'],
                64,
                'authority_document_no_invalid'
            ),
            'tenantNameSnapshot' => self::text(
                $serverDocument['tenantNameSnapshot'],
                128,
                'authority_tenant_name_invalid'
            ),
            'staffSnapshots' => $staffSnapshots,
        ];
        return CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernelPlan, $context);
    }

    private function requestRowForDiscovery(
        string $requestId,
        int $requestVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $row = $this->row(Db::name(ThinkPhpCashierV3CheckoutRequestRepository::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('request_version', $requestVersion)
            ->whereIn('request_status', ['editing', 'ready_for_submit'])
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->find());
        if (!$row) {
            // This lookup runs before the checkout resources are locked. Keep
            // only request identity/scope diagnostics so a stale client
            // version can be distinguished from a missing persisted draft.
            Log::warning('[cashier_v3_checkout_discovery_request_missing] ' . json_encode([
                'request_id' => $requestId,
                'request_version' => $requestVersion,
                'workspace_id' => $workspaceId,
                'state_context_id' => $stateContextId,
                'tenant_id' => $dataScope->tenantId(),
                'store_id' => $dataScope->forcedStoreId(),
                'operator_id' => $operatorScope->operatorId(),
            ], JSON_UNESCAPED_SLASHES));
            throw self::failure('authority_discovery_checkout_request_not_found');
        }
        return $row;
    }

    private function requestLinesForDiscovery(string $requestId, int $requestVersion): array
    {
        $rows = $this->rows(Db::name(ThinkPhpCashierV3CheckoutRequestRepository::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->select());
        if (!$rows) {
            throw self::failure('authority_discovery_checkout_lines_missing');
        }
        return $rows;
    }

    private function assertDiscoveryWorkspaceMatchesRequest(
        array $workspaceRows,
        array $checkoutLines
    ): void {
        if (count($workspaceRows) !== count($checkoutLines)) {
            throw self::failure('authority_discovery_workspace_line_count_changed');
        }
        $workspaceByKey = [];
        foreach ($workspaceRows as $row) {
            $workspaceByKey[(string)($row['line_key'] ?? '')] = $row;
        }
        foreach ($checkoutLines as $line) {
            $role = (string)($line['line_role'] ?? '');
            $authorityKey = (string)($line['authority_key'] ?? '');
            $lineKey = $role === 'sale' && strpos($authorityKey, 'sale:') === 0
                ? substr($authorityKey, 5)
                : $authorityKey;
            $workspace = $workspaceByKey[$lineKey] ?? null;
            if (!is_array($workspace)
                || (string)($workspace['line_role'] ?? '') !== $role
                || (int)($workspace['quantity'] ?? 0) !== (int)($line['quantity'] ?? -1)
                || ($role === self::ENTITLEMENT_ROLE
                    && ((int)($workspace['holder_id'] ?? 0) !== (int)($line['source_id'] ?? -1)
                        || (int)($workspace['source_detail_id'] ?? 0)
                            !== (int)($line['entitlement_source_detail_id'] ?? -1)
                        || (int)($workspace['project_id'] ?? 0) !== (int)($line['project_id'] ?? -1)
                        || (int)($workspace['source_version'] ?? 0) !== (int)($line['source_version'] ?? -1)
                        || (int)($workspace['detail_version'] ?? 0) !== (int)($line['project_version'] ?? -1)))) {
                throw self::failure('authority_discovery_workspace_line_changed', ['lineId' => $lineKey]);
            }
        }
    }

    private function entitlementCheckoutLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $row) {
            if (!is_array($row)) {
                throw self::failure('authority_checkout_line_shape_invalid');
            }
            if ((string)($row['line_role'] ?? '') !== self::ENTITLEMENT_ROLE) {
                continue;
            }
            $lineId = self::token(
                $row['line_id'] ?? null,
                64,
                'authority_checkout_line_id_invalid'
            );
            if (isset($result[$lineId])) {
                throw self::failure('authority_checkout_line_duplicate', ['lineId' => $lineId]);
            }
            foreach ([
                'source_id', 'entitlement_source_detail_id', 'source_version',
                'project_id', 'project_version', 'quantity',
            ] as $field) {
                self::positiveInt($row[$field] ?? null, 'authority_checkout_' . $field . '_invalid');
            }
            $row['line_id'] = $lineId;
            $result[$lineId] = $row;
        }
        if (count($result) > self::MAX_LINES) {
            throw self::failure('authority_checkout_line_limit_exceeded');
        }
        return array_values($result);
    }

    private function serviceIntentsFromWorkspaceRows(array $workspaceRows, array $checkoutLines): array
    {
        $byKey = [];
        foreach ($workspaceRows as $row) {
            $byKey[(string)($row['line_key'] ?? '')] = $row;
        }
        $result = [];
        foreach ($checkoutLines as $line) {
            $lineId = (string)$line['line_id'];
            $workspace = $byKey[(string)$line['authority_key']] ?? null;
            if (!is_array($workspace)) {
                throw self::failure('authority_service_intent_missing', ['lineId' => $lineId]);
            }
            $craftsmen = json_decode((string)($workspace['craftsmen_json'] ?? ''), true);
            $staffIds = [];
            $settingsById = [];
            foreach (is_array($craftsmen) ? $craftsmen : [] as $craftsman) {
                $staffId = is_array($craftsman) ? (int)($craftsman['staffId'] ?? $craftsman['id'] ?? 0) : 0;
                $laborWeight = is_array($craftsman) ? (int)($craftsman['laborWeight'] ?? 0) : 0;
                if ($staffId <= 0 || $laborWeight <= 0 || $laborWeight > 100 || isset($staffIds[$staffId])) {
                    throw self::failure('authority_service_intent_craftsman_invalid', ['lineId' => $lineId]);
                }
                $staffIds[$staffId] = $staffId;
                $settingsById[$staffId] = [
                    'laborWeight' => $laborWeight,
                    'isPointCustomer' => !empty($craftsman['isPointCustomer']),
                ];
            }
            if (!$staffIds) {
                throw self::failure('authority_service_intent_craftsman_required', ['lineId' => $lineId]);
            }
            if (array_sum(array_column($settingsById, 'laborWeight')) !== 100) {
                throw self::failure('authority_service_intent_craftsman_weight_invalid', ['lineId' => $lineId]);
            }
            $result[$lineId] = [
                'craftsmanIds' => array_values($staffIds),
                'craftsmanSettingsById' => $settingsById,
            ];
        }
        return $result;
    }

    private function serviceIntentsByLine(array $intents, array $checkoutLines): array
    {
        $expected = [];
        foreach ($checkoutLines as $line) {
            $expected[(string)$line['line_id']] = (int)$line['quantity'];
        }
        $result = [];
        foreach ($intents as $intent) {
            $lineId = (string)($intent['checkoutLineId'] ?? '');
            if (!isset($expected[$lineId])
                || isset($result[$lineId])
                || (int)($intent['quantity'] ?? 0) !== $expected[$lineId]) {
                throw self::failure('authority_locked_service_intent_mismatch', ['lineId' => $lineId]);
            }
            $result[$lineId] = $intent;
        }
        if (count($result) !== count($expected)) {
            throw self::failure('authority_locked_service_intent_incomplete');
        }
        return $result;
    }

    /** @return array<string,array> checkout line id => authority */
    private function loadEntitlementAuthorities(
        array $lines,
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $result = [];
        foreach ($lines as $line) {
            $lineId = (string)$line['line_id'];
            $holderId = (int)$line['source_id'];
            $detailId = (int)$line['entitlement_source_detail_id'];
            $projectId = (int)$line['project_id'];
            $holder = $this->row(Db::name('user_card_holder')
                ->where('id', $holderId)
                ->where('uid', $memberId)
                ->where('is_del', 0)
                ->find());
            $detail = $this->row(Db::name('store_order_cart_info')
                ->where('id', $detailId)
                ->where('product_id', $projectId)
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->where('is_writeoff', 0)
                ->find());
            if (!$holder || !$detail || (int)$holder['oid'] !== (int)$detail['oid']) {
                throw self::failure('authority_entitlement_identity_not_found', ['lineId' => $lineId]);
            }
            $order = $this->row(Db::name('store_order')
                ->where('id', (int)$holder['oid'])
                ->find());
            if (!$this->activeOrder($order)
                || (int)($holder['write_surplus_times'] ?? 0) <= 0
                || (int)($detail['write_surplus_times'] ?? 0) <= 0) {
                throw self::failure('authority_entitlement_not_active', ['lineId' => $lineId]);
            }
            $this->assertCardOperationStateActive($holder, $memberId);
            $this->assertOriginStoreAllowed((int)$order['store_id'], $operatorScope, $dataScope);
            $this->assertOriginStoreAllowed((int)$holder['store_id'], $operatorScope, $dataScope);
            if ($this->shadowVersion('card_holder', $holderId) !== (int)$line['source_version']
                || $this->shadowVersion('member_benefit_pool', $detailId) !== (int)$line['project_version']) {
                throw self::failure('authority_entitlement_shadow_version_changed', ['lineId' => $lineId]);
            }
            $decoded = json_decode((string)($detail['cart_info'] ?? ''), true);
            $decoded = is_array($decoded) ? $decoded : [];
            $ruleAuthority = $this->cardRules->authorityForDetail(
                $operatorScope->tenantId(),
                $holderId,
                $detailId,
                false
            );
            $projectName = trim((string)($decoded['productInfo']['store_name'] ?? ''));
            if ($projectName === '') {
                $projectName = '项目';
            }
            if ((string)($line['source_name_snapshot'] ?? '') !== (string)$holder['card_name']
                || (string)($line['source_code_snapshot'] ?? '') !== (string)$holder['card_no']
                || (string)($line['project_name_snapshot'] ?? '') !== $projectName) {
                throw self::failure('authority_checkout_snapshot_changed', ['lineId' => $lineId]);
            }
            $remaining = (int)$detail['write_surplus_times'];
            $amount = $this->purchaseAmount($detail, $decoded);
            $totalTimes = (int)($detail['write_times'] ?? 0);
            $amountCalculationVersion = $this->amountCalculationVersion($decoded);
            if (is_array($ruleAuthority)) {
                $remaining = (int)$ruleAuthority['remainingTimes'];
                $amount = $this->centsToMoney((int)$ruleAuthority['purchaseAmountCents']);
                $totalTimes = (int)$ruleAuthority['totalTimes'];
                $amountCalculationVersion = 'issued-card-rule-'
                    . (string)$ruleAuthority['ruleType'] . '-'
                    . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
                if ((string)$ruleAuthority['stateStatus'] !== 'active'
                    || empty($ruleAuthority['choiceAvailable'])) {
                    throw self::failure('authority_card_rule_not_usable', ['lineId' => $lineId]);
                }
            }
            if ($amount === null || $totalTimes <= 0 || $remaining > $totalTimes) {
                throw self::failure('authority_entitlement_amount_invalid', ['lineId' => $lineId]);
            }
            $validityStart = max((int)($holder['write_start'] ?? 0), (int)($detail['write_start'] ?? 0));
            $ends = array_values(array_filter([
                (int)($holder['write_end'] ?? 0),
                (int)($detail['write_end'] ?? 0),
            ], static function (int $value): bool { return $value > 0; }));
            $validityEnd = $ends ? min($ends) : 0;
            if (is_array($ruleAuthority)) {
                $validityStart = max(0, (int)$ruleAuthority['validFrom']);
                $validityEnd = max(0, (int)$ruleAuthority['validThrough']);
            }
            $now = time();
            if (($validityEnd > 0 && $validityStart > $validityEnd)
                || ($validityStart > 0 && $now < $validityStart)
                || ($validityEnd > 0 && $now > $validityEnd)) {
                throw self::failure('authority_entitlement_not_usable_at_settlement', ['lineId' => $lineId]);
            }
            $pendingDebt = $this->pendingDebt($order);
            $debtLimited = $this->writeoffServices()->calcEffectiveWriteSurplusTimes(
                $detail,
                (float)$pendingDebt,
                $order
            );
            $projectUnique = self::token(
                $detail['cart_id'] ?? null,
                64,
                'authority_inventory_project_unique_invalid'
            );
            $isGift = (int)($detail['is_gift'] ?? 0) === 1;
            $sourceKind = $isGift
                ? 'gift'
                : ((int)($holder['product_type'] ?? 0) === 4 ? 'count_card' : 'unknown');
            if (!$isGift && is_array($ruleAuthority)) {
                $sourceKind = (string)$ruleAuthority['sourceKind'];
            }
            $categoryName = trim((string)(
                $decoded['productInfo']['categoryName']
                ?? $decoded['productInfo']['cate_name']
                ?? ''
            ));
            $result[$lineId] = [
                'holder' => $holder,
                'detail' => $detail,
                'order' => $order,
                'sourceKind' => $sourceKind,
                'isGift' => $isGift,
                'physicalRemainingTimes' => $remaining,
                'debtLimitedUsableTimes' => max(0, min($remaining, (int)$debtLimited)),
                'purchaseAmount' => $amount,
                'totalPurchaseTimes' => $totalTimes,
                'amountCalculationVersion' => $amountCalculationVersion,
                'projectUnique' => $projectUnique,
                'projectCategoryName' => $categoryName !== '' ? $categoryName : '未分类',
            ];
        }
        return $result;
    }

    private function assertCardOperationStateActive(array $holder, int $memberId): void
    {
        try {
            $state = $this->row(Db::name('cashier_v3_card_state')
                ->where('tenant_id', '0')
                ->where('card_holder_id', (int)$holder['id'])
                ->find());
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (strpos($message, 'cashier_v3_card_state') !== false
                && (strpos($message, 'doesn\'t exist') !== false || strpos($message, 'not found') !== false)) {
                return;
            }
            throw $exception;
        }
        if ($state === []) {
            return;
        }
        if ((int)($state['origin_order_id'] ?? 0) !== (int)($holder['oid'] ?? 0)
            || (int)($state['current_member_id'] ?? 0) !== $memberId
            || (string)($state['card_status'] ?? '') !== 'enabled') {
            throw self::failure('authority_card_operation_state_not_active', [
                'holderId' => (int)($holder['id'] ?? 0),
            ]);
        }
    }

    private function discoverOccupationContributors(
        int $detailId,
        array $source,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $status = $this->occupations->readinessStatus();
        if (empty($status['ready'])) {
            throw self::failure('authority_occupation_provider_not_ready', ['status' => $status]);
        }
        $contributors = [];
        $reservations = $this->rows(Db::name('store_reservation_order')
            ->where('cart_info_id', $detailId)
            ->whereIn('status', [0, 1, 3])
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->order('id asc')
            ->select());
        foreach ($reservations as $row) {
            $id = (int)($row['id'] ?? 0);
            $storeId = (int)($row['store_id'] ?? 0);
            if ($id <= 0 || $storeId !== $dataScope->forcedStoreId()) {
                throw self::failure('cross_store_occupation_contributor_fail_closed', [
                    'kind' => 'reservation', 'id' => $id, 'storeId' => $storeId,
                ]);
            }
            $version = $this->occupationContributors->discoverVersion(
                'reservation',
                $id,
                $operatorScope,
                $dataScope
            );
            $contributors[] = [
                'kind' => 'reservation',
                'id' => $id,
                'version' => $version,
                'occupiedTimes' => 1,
                'convertibleTimes' => $source['type'] === 'reservation'
                    && $source['reservationId'] === $id ? 1 : 0,
            ];
        }

        $serviceLines = $this->rows(Db::name(ThinkPhpCashierV3ServiceOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('source_type', ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT)
            ->where('entitlement_source_detail_id', $detailId)
            ->order('service_order_id asc,id asc')
            ->select());
        $occupiedByOrder = [];
        foreach ($serviceLines as $line) {
            if (!CashierV3ServiceOrderState::isActiveLine((string)($line['status'] ?? ''))) {
                continue;
            }
            $orderId = (int)($line['service_order_id'] ?? 0);
            $occupied = (int)($line['occupied_times'] ?? 0);
            if ($orderId <= 0 || $occupied <= 0) {
                throw self::failure('authority_service_occupation_line_invalid');
            }
            $occupiedByOrder[$orderId] = ($occupiedByOrder[$orderId] ?? 0) + $occupied;
        }
        foreach ($occupiedByOrder as $orderId => $occupied) {
            $order = $this->row(Db::name(ThinkPhpCashierV3ServiceOrderRepository::ORDER_TABLE)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('id', $orderId)
                ->find());
            if (!$order || !CashierV3ServiceOrderState::isActiveOrder((string)($order['status'] ?? ''))) {
                continue;
            }
            if ((int)($order['business_store_id'] ?? 0) !== $dataScope->forcedStoreId()) {
                throw self::failure('cross_store_occupation_contributor_fail_closed', [
                    'kind' => 'service_order', 'id' => $orderId,
                    'storeId' => (int)($order['business_store_id'] ?? 0),
                ]);
            }
            $version = $this->occupationContributors->discoverVersion(
                'service_order',
                $orderId,
                $operatorScope,
                $dataScope
            );
            $contributors[] = [
                'kind' => 'service_order',
                'id' => $orderId,
                'version' => $version,
                'occupiedTimes' => $occupied,
                'convertibleTimes' => $source['type'] === 'service_order'
                    && $source['serviceOrderId'] === $orderId ? $occupied : 0,
            ];
        }
        $this->assertOccupationSourcePresent($contributors, $source);
        return $contributors;
    }

    private function assertOccupationSourcePresent(array $contributors, array $source): void
    {
        $convertible = 0;
        foreach ($contributors as $contributor) {
            $convertible += (int)$contributor['convertibleTimes'];
        }
        if ($source['type'] === 'direct' && $convertible !== 0) {
            throw self::failure('authority_direct_occupation_conversion_forbidden');
        }
        if ($source['type'] !== 'direct' && $convertible <= 0) {
            throw self::failure('authority_current_source_not_occupation_contributor');
        }
    }

    private function inventoryRequest(array $authorities, CashierV3DataScopeContext $dataScope): array
    {
        $lines = [];
        foreach ($authorities as $lineId => $authority) {
            $lines[] = [
                'lineId' => $lineId,
                'projectId' => (int)$authority['detail']['product_id'],
                'projectUnique' => (string)$authority['projectUnique'],
            ];
        }
        return [
            'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'tenantId' => $dataScope->tenantId(),
            'storeId' => $dataScope->forcedStoreId(),
            'lines' => $lines,
        ];
    }

    private function inventoryScopeFromRequest(
        array $request,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): InventoryCompletionDataScope {
        $organizationId = self::token(
            $request['organization_id'] ?? null,
            32,
            'authority_inventory_organization_invalid'
        );
        $organizationPath = self::pathToken(
            $request['organization_path'] ?? null,
            191,
            'authority_inventory_organization_path_invalid'
        );
        if (!hash_equals($organizationId, $dataScope->organizationId())) {
            throw self::failure('authority_inventory_organization_scope_mismatch');
        }
        return new InventoryCompletionDataScope(
            $dataScope->tenantId(),
            $organizationId,
            $organizationPath,
            [$dataScope->forcedStoreId()],
            $operatorScope->operatorId()
        );
    }

    private function lockedSnapshot(
        array $request,
        array $lines,
        array $inventory,
        array $source,
        int $workspaceVersion,
        int $memberVersion,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        int $now
    ): array {
        return [
            'workspaceId' => (string)$request['workspace_id'],
            'stateContextId' => (string)$request['state_context_id'],
            'workspaceVersion' => $workspaceVersion,
            'tenantId' => $dataScope->tenantId(),
            'organizationId' => self::nonNegativeInt(
                $request['organization_id'] ?? null,
                'authority_organization_id_invalid'
            ),
            'organizationName' => self::text(
                $request['organization_name_snapshot'] ?? null,
                128,
                'authority_organization_name_invalid'
            ),
            'organizationPath' => self::text(
                $request['organization_path'] ?? null,
                191,
                'authority_organization_path_invalid'
            ),
            'storeId' => $dataScope->forcedStoreId(),
            'storeName' => self::text(
                $request['store_name_snapshot'] ?? null,
                128,
                'authority_store_name_invalid'
            ),
            'memberId' => (int)$request['member_id'],
            'memberName' => self::text(
                $request['member_name_snapshot'] ?? null,
                128,
                'authority_member_name_invalid'
            ),
            'memberVersion' => $memberVersion,
            'memberActive' => true,
            'workspaceStatus' => 'editing',
            'operatorId' => $operatorScope->operatorId(),
            'operatorName' => self::operatorName($dataScope->operatorProfile()),
            'operatorStoreId' => $operatorScope->storeId(),
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
            'operatorFeatures' => $dataScope->grantedFeatures(),
            'businessDate' => (string)$request['business_date'],
            'businessTimezone' => (string)$request['business_timezone'],
            'occurredAt' => $now,
            'settledAt' => $now,
            'recordedAt' => $now,
            'source' => $source,
            'inventoryProviderContractVersion' => (string)$inventory['contractVersion'],
            'inventoryProviderTenantId' => (string)$inventory['tenantId'],
            'inventoryProviderStoreId' => (int)$inventory['storeId'],
            'inventoryShortageCursorLockGate' => (string)$inventory['shortageCursorLockGate'],
            'lines' => $lines,
            'inventoryStocks' => array_values($inventory['inventoryStocks']),
        ];
    }

    private function lockedGatewayResources(
        array $contexts,
        array $lockedVersions,
        $plan,
        string $requestId,
        int $requestVersion,
        CashierV3DataScopeContext $dataScope
    ): array {
        if (!is_array($plan)
            || (string)($plan['requestId'] ?? '') !== $requestId
            || (int)($plan['boundRequestVersion'] ?? 0) !== $requestVersion
            || (string)($plan['tenantId'] ?? '') !== $dataScope->tenantId()
            || (int)($plan['storeId'] ?? 0) !== $dataScope->forcedStoreId()
            || !is_array($plan['resources'] ?? null)) {
            throw self::failure('authority_gateway_resource_plan_invalid');
        }
        $planByPhysical = [];
        foreach ($plan['resources'] as $row) {
            if (!is_array($row)) {
                throw self::failure('authority_gateway_resource_row_invalid');
            }
            $kind = (string)($row['kind'] ?? '');
            $id = (string)($row['id'] ?? '');
            $version = (int)($row['expectedVersion'] ?? 0);
            $roles = is_array($row['roles'] ?? null) ? array_values($row['roles']) : [];
            if ($kind === '' || $id === '' || $version <= 0 || !$roles) {
                throw self::failure('authority_gateway_resource_row_invalid');
            }
            sort($roles, SORT_STRING);
            $planByPhysical[$kind . "\0" . $id] = compact('kind', 'id', 'version', 'roles');
        }

        $locked = [];
        foreach ($contexts as $context) {
            if (!is_array($context)) {
                throw self::failure('authority_gateway_context_invalid');
            }
            $kind = (string)($context['kind'] ?? '');
            $id = (string)($context['id'] ?? '');
            $expected = (int)($context['expected_version'] ?? 0);
            $scope = $context['scope'] ?? null;
            if ($kind === '' || $id === '' || $expected <= 0
                || !($scope instanceof CashierV3ResourceScope)) {
                throw self::failure('authority_gateway_context_invalid');
            }
            $key = CashierV3ResourceVersionServices::versionKey($scope, $kind, $id);
            if ((int)($lockedVersions[$key] ?? 0) !== $expected) {
                throw self::failure('authority_gateway_context_not_locked', ['kind' => $kind, 'id' => $id]);
            }
            $physical = $kind . "\0" . $id;
            $roles = is_array($context['roles'] ?? null) ? array_values($context['roles']) : [];
            if (isset($planByPhysical[$physical])) {
                if ($planByPhysical[$physical]['version'] !== $expected) {
                    throw self::failure('authority_gateway_plan_context_version_mismatch', [
                        'kind' => $kind, 'id' => $id,
                    ]);
                }
                $roles = array_values(array_unique(array_merge(
                    $roles,
                    $planByPhysical[$physical]['roles']
                )));
                unset($planByPhysical[$physical]);
            }
            sort($roles, SORT_STRING);
            $locked[$physical] = [
                'kind' => $kind,
                'id' => $id,
                'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf($kind),
                'lockedVersion' => $expected,
                'roles' => $roles,
            ];
        }
        if ($planByPhysical !== []) {
            throw self::failure('authority_gateway_plan_resource_not_locked', [
                'resources' => array_keys($planByPhysical),
            ]);
        }
        return $locked;
    }

    private function kernelResourceSubset(array $gateway, array $snapshot): array
    {
        $requirements = [
            'member' => ['member', (string)$snapshot['memberId'], $snapshot['memberVersion']],
            'workspace' => ['cashier_workspace', $snapshot['workspaceId'], $snapshot['workspaceVersion']],
        ];
        if ($snapshot['source']['serviceOrderId'] > 0) {
            $requirements['service_order'] = [
                'service_order',
                (string)$snapshot['source']['serviceOrderId'],
                $snapshot['source']['serviceOrderVersion'],
            ];
        }
        if ($snapshot['source']['reservationId'] > 0) {
            $requirements['reservation'] = [
                'reservation',
                (string)$snapshot['source']['reservationId'],
                $snapshot['source']['reservationVersion'],
            ];
        }
        foreach ($snapshot['lines'] as $line) {
            $lineId = $line['lineId'];
            $requirements['benefit_pool:' . $line['sourceDetailId']] = [
                'member_benefit_pool', (string)$line['sourceDetailId'], $line['detailVersion'],
            ];
            $requirements['card_holder:' . $line['holderId']] = [
                'card_holder', (string)$line['holderId'], $line['sourceVersion'],
            ];
            $requirements['entitlement_debt_guard:' . $line['originOrderId']] = [
                'entitlement_debt_guard', $line['debtGuardId'], $line['debtGuardVersion'],
            ];
            $requirements['performance_rule:' . $lineId] = [
                'performance_rule', (string)$line['projectId'], $line['performance']['ruleVersion'],
            ];
            if ($line['inventory']['inventoryManaged'] === false) {
                continue;
            }
            $requirements['inventory_policy:' . $lineId] = [
                'inventory_policy', (string)$line['projectId'], $line['inventory']['policyVersion'],
            ];
            $requirements['inventory_recipe:' . $lineId] = [
                'inventory_recipe', (string)$line['inventory']['recipeId'], $line['inventory']['recipeVersion'],
            ];
            foreach ($line['inventory']['consumables'] as $consumable) {
                $requirements['inventory_shortage_cursor:' . $lineId . ':' . $consumable['stockId']] = [
                    'inventory_shortage_cursor',
                    $consumable['shortageCursorId'],
                    $consumable['shortageCursorVersion'],
                ];
            }
            foreach ($line['craftsmen'] as $craftsman) {
                $requirements['staff:' . $craftsman['staffId']] = [
                    'staff_profile', (string)$craftsman['staffId'], $craftsman['staffVersion'],
                ];
            }
            foreach ($line['occupationContributors'] as $contributor) {
                $requirements['occupation:' . $lineId . ':' . $contributor['kind'] . ':' . $contributor['id']] = [
                    $contributor['kind'], (string)$contributor['id'], $contributor['version'],
                ];
            }
        }
        foreach ($snapshot['inventoryStocks'] as $stock) {
            $requirements['inventory_stock:' . $stock['stockId']] = [
                'inventory_stock', $stock['stockId'], $stock['stockVersion'],
            ];
            foreach ($stock['batches'] as $batch) {
                $requirements['inventory_batch:' . $stock['stockId'] . ':' . $batch['batchId']] = [
                    'inventory_batch', (string)$batch['batchId'], $batch['batchVersion'],
                ];
            }
        }

        $physical = [];
        foreach ($requirements as $role => $expectation) {
            [$kind, $id, $version] = $expectation;
            $key = $kind . "\0" . $id;
            $row = $gateway[$key] ?? null;
            if (!is_array($row) || (int)$row['lockedVersion'] !== (int)$version) {
                throw self::failure('authority_kernel_resource_not_locked', [
                    'role' => $role, 'kind' => $kind, 'id' => $id,
                ]);
            }
            // Public workspace/source contexts predate the entitlement role
            // names. Their physical lock is authoritative; hidden resources
            // must carry the exact server-discovered role.
            $publicRole = in_array($role, ['workspace', 'service_order', 'reservation'], true);
            if (!$publicRole && !in_array($role, $row['roles'], true)) {
                throw self::failure('authority_kernel_resource_role_missing', [
                    'role' => $role, 'kind' => $kind, 'id' => $id,
                ]);
            }
            if (!isset($physical[$key])) {
                $physical[$key] = [
                    'kind' => $kind,
                    'id' => $id,
                    'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf($kind),
                    'lockedVersion' => (int)$version,
                    'roles' => [],
                ];
            } elseif ($physical[$key]['lockedVersion'] !== (int)$version) {
                throw self::failure('authority_kernel_physical_version_inconsistent');
            }
            $physical[$key]['roles'][] = $role;
        }
        $rows = array_values($physical);
        foreach ($rows as &$row) {
            $row['roles'] = array_values(array_unique($row['roles']));
            sort($row['roles'], SORT_STRING);
        }
        unset($row);
        usort($rows, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $left['kind'], $left['id'], $right['kind'], $right['id']
            );
        });
        return $rows;
    }

    private function assertInventoryResourcesLocked(array $snapshot, array $gateway): void
    {
        if (($snapshot['contractVersion'] ?? '')
                !== CashierV3EntitlementCompletionKernel::INVENTORY_PROVIDER_CONTRACT_VERSION
            || ($snapshot['shortageCursorLockGate'] ?? '')
                !== CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE) {
            throw self::failure('authority_inventory_contract_mismatch');
        }
        foreach ((array)($snapshot['lockedInventoryResources'] ?? []) as $resource) {
            $kind = (string)($resource['kind'] ?? '');
            $id = (string)($resource['id'] ?? '');
            $version = (int)($resource['lockedVersion'] ?? 0);
            $gatewayRow = $gateway[$kind . "\0" . $id] ?? null;
            if (!is_array($gatewayRow) || (int)$gatewayRow['lockedVersion'] !== $version) {
                throw self::failure('authority_inventory_resource_not_locked', [
                    'kind' => $kind, 'id' => $id,
                ]);
            }
            foreach ((array)($resource['roles'] ?? []) as $role) {
                if (!in_array($role, $gatewayRow['roles'], true)) {
                    throw self::failure('authority_inventory_resource_role_missing', ['role' => $role]);
                }
            }
        }
    }

    private function assertKernelAmountsMatchCheckout(array $kernelPlan, array $checkoutLines): void
    {
        $expected = [];
        foreach ($checkoutLines as $line) {
            $expected[(string)$line['line_id']] = (int)$line['entitlement_actual_amount_cents'];
        }
        foreach ((array)$kernelPlan['linePlans'] as $line) {
            $lineId = (string)$line['lineId'];
            if (!isset($expected[$lineId])
                || $expected[$lineId] !== (int)$line['actualEntitlementAmountCents']) {
                throw self::failure('authority_checkout_actual_amount_mismatch', ['lineId' => $lineId]);
            }
            unset($expected[$lineId]);
        }
        if ($expected !== []) {
            throw self::failure('authority_checkout_actual_amount_line_missing');
        }
    }

    private function sourceFromContexts(array $contexts): array
    {
        $references = [];
        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }
            $kind = (string)($context['kind'] ?? '');
            if (!in_array($kind, ['service_order', 'reservation'], true)) {
                continue;
            }
            $id = self::positiveInt($context['id'] ?? null, 'authority_source_id_invalid');
            $version = self::positiveInt(
                $context['expected_version'] ?? $context['expectedVersion'] ?? null,
                'authority_source_version_invalid'
            );
            if (isset($references[$kind])) {
                throw self::failure('authority_source_kind_duplicate', ['kind' => $kind]);
            }
            $references[$kind] = compact('id', 'version');
        }
        return $this->sourceFromReferenceMap($references);
    }

    private function sourceFromVerifiedAggregate(array $aggregate): array
    {
        $verified = $aggregate['verifiedSources'] ?? null;
        if (!is_object($verified) || !method_exists($verified, 'references')) {
            throw self::failure('authority_verified_sources_missing');
        }
        $references = [];
        foreach ($verified->references() as $reference) {
            $kind = (string)($reference['kind'] ?? '');
            if (!in_array($kind, ['service_order', 'reservation'], true)) {
                continue;
            }
            $id = self::positiveInt($reference['id'] ?? null, 'authority_source_id_invalid');
            $version = self::positiveInt(
                $reference['sourceVersion'] ?? null,
                'authority_source_version_invalid'
            );
            if (isset($references[$kind])) {
                throw self::failure('authority_source_kind_duplicate', ['kind' => $kind]);
            }
            $references[$kind] = compact('id', 'version');
        }
        return $this->sourceFromReferenceMap($references);
    }

    private function sourceFromReferenceMap(array $references): array
    {
        $service = $references['service_order'] ?? null;
        $reservation = $references['reservation'] ?? null;
        $type = $service ? 'service_order' : ($reservation ? 'reservation' : 'direct');
        return [
            'type' => $type,
            'serviceOrderId' => $service ? (int)$service['id'] : 0,
            'serviceOrderVersion' => $service ? (int)$service['version'] : 0,
            'reservationId' => !$service && $reservation ? (int)$reservation['id'] : 0,
            'reservationVersion' => !$service && $reservation ? (int)$reservation['version'] : 0,
        ];
    }

    private function lockedVersion(
        array $gateway,
        string $kind,
        string $id,
        string $requiredRole = ''
    ): int {
        $row = $gateway[$kind . "\0" . $id] ?? null;
        if (!is_array($row) || (int)($row['lockedVersion'] ?? 0) <= 0) {
            throw self::failure('authority_gateway_locked_resource_missing', [
                'kind' => $kind, 'id' => $id,
            ]);
        }
        if ($requiredRole !== '' && !in_array($requiredRole, (array)$row['roles'], true)) {
            throw self::failure('authority_gateway_locked_role_missing', [
                'kind' => $kind, 'id' => $id, 'role' => $requiredRole,
            ]);
        }
        return (int)$row['lockedVersion'];
    }

    private function assertLockedAggregate(
        array $aggregate,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        foreach (['request', 'lines', 'payments', 'sources', 'currentRequest', 'verifiedSources'] as $key) {
            if (!array_key_exists($key, $aggregate)) {
                throw self::failure('authority_locked_aggregate_incomplete', ['missing' => $key]);
            }
        }
        $request = is_array($aggregate['request']) ? $aggregate['request'] : [];
        if (!$request
            || (string)($request['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (int)($request['store_id'] ?? 0) !== $dataScope->forcedStoreId()
            || (string)($request['request_status'] ?? '') !== 'ready_for_submit'
            || !is_array($aggregate['lines'])) {
            throw self::failure('authority_locked_aggregate_scope_invalid');
        }
    }

    private function assertCheckoutDataScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        CashierV3EntitlementProviderDataScope::assertTenant($operatorScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore($operatorScope->storeId(), $dataScope);
        if (!in_array('cashier.v3.cashier', $dataScope->grantedFeatures(), true)) {
            throw self::failure('authority_required_feature_missing');
        }
    }

    private function assertEntitlementCompletionFeature(CashierV3DataScopeContext $dataScope): void
    {
        $features = $dataScope->grantedFeatures();
        // "使用权益" is a cashier workflow. The store-v3 role catalog has no
        // separate grant for completing an entitlement, while standalone
        // project replacement remains protected by cashier.v3.writeoff.
        if (!in_array('cashier.v3.cashier', $features, true)
            && !in_array('cashier.v3.writeoff', $features, true)) {
            throw self::failure('authority_entitlement_completion_feature_missing');
        }
    }

    private function assertOriginStoreAllowed(
        int $originStoreId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($originStoreId <= 0) {
            throw self::failure('authority_origin_store_invalid');
        }
        if ($originStoreId === $operatorScope->storeId()) {
            return;
        }
        if (!CashierV3CrossStoreEntitlementPolicy::enabled()
            || $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_NONE
            || $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
            throw self::failure('authority_cross_store_entitlement_denied');
        }
    }

    private function activeOrder(array $order): bool
    {
        return $order
            && (int)($order['paid'] ?? 0) === 1
            && (int)($order['is_del'] ?? 1) === 0
            && (int)($order['is_system_del'] ?? 1) === 0
            && (int)($order['is_user_del'] ?? 1) === 0
            && (int)($order['refund_status'] ?? -1) === 0
            && (int)($order['terminal_action'] ?? -1) === 0
            && (int)($order['card_upgrade_use_oid'] ?? -1) === 0;
    }

    private function pendingDebt(array $order): string
    {
        $rows = $this->rows(Db::name('store_debt')
            ->where('order_id', (int)$order['id'])
            ->select());
        if (count($rows) > 1) {
            throw self::failure('authority_duplicate_debt_rows');
        }
        if ($rows) {
            if ((int)($rows[0]['status'] ?? 0) !== 0) {
                return '0.00';
            }
            $pending = bcsub((string)$rows[0]['total_debt'], (string)$rows[0]['repaid_debt'], 2);
            return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
        }
        $pending = bcsub((string)$order['debt_amount'], (string)$order['repaid_debt_amount'], 2);
        return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
    }

    private function purchaseAmount(array $detail, array $decoded)
    {
        $legacy = is_array($decoded['rh_source'] ?? null) ? $decoded['rh_source'] : [];
        $value = array_key_exists('source_line_paid_amount', $legacy)
            ? $legacy['source_line_paid_amount']
            : ($detail['pay_price'] ?? null);
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            return null;
        }
        return bcadd($raw, '0', 2);
    }

    private function amountCalculationVersion(array $decoded): string
    {
        $legacy = is_array($decoded['rh_source'] ?? null) ? $decoded['rh_source'] : [];
        $prefix = array_key_exists('source_line_paid_amount', $legacy)
            ? 'rh-source-line-payment-'
            : 'legacy-cart-line-payment-';
        return $prefix . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
    }

    private function centsToMoney(int $cents): string
    {
        if ($cents < 0) {
            throw self::failure('authority_entitlement_amount_invalid');
        }
        return bcdiv((string)$cents, '100', 2);
    }

    private function writeoffServices(): WriteOffOrderServices
    {
        if ($this->writeoffServices instanceof WriteOffOrderServices) {
            return $this->writeoffServices;
        }
        try {
            $service = app()->make(WriteOffOrderServices::class);
        } catch (\Throwable $exception) {
            throw self::failure('authority_writeoff_debt_service_not_ready');
        }
        if (!($service instanceof WriteOffOrderServices)) {
            throw self::failure('authority_writeoff_debt_service_invalid');
        }
        $this->writeoffServices = $service;
        return $service;
    }

    private function shadowVersion(string $kind, int $resourceId): int
    {
        $row = $this->row(Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', (string)$resourceId)
            ->field('current_version')
            ->find());
        $version = (int)($row['current_version'] ?? 0);
        if ($version <= 0) {
            throw self::failure('authority_entitlement_shadow_version_missing', [
                'kind' => $kind, 'resourceId' => $resourceId,
            ]);
        }
        return $version;
    }

    private function debtGuardDiscoveryVersion(string $tenantId, int $originOrderId): int
    {
        $row = $this->row(Db::name(CashierV3EntitlementDebtGuardProvider::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('origin_order_id', $originOrderId)
            ->field('current_version')
            ->find());
        return $row ? self::positiveInt(
            $row['current_version'] ?? null,
            'authority_debt_guard_version_invalid'
        ) : 1;
    }

    private function staffDiscoveryVersion(CashierV3DataScopeContext $dataScope, int $staffId): int
    {
        $staff = $this->row(Db::name('system_store_staff')
            ->where('id', $staffId)
            ->field('id,employee_id,store_id,staff_name,status,is_del,cashier_craftsman_enabled')
            ->find());
        $employee = $staff ? $this->row(Db::name('employee')
            ->where('id', (int)$staff['employee_id'])
            ->field('id,name,status,is_del,employment_type_code,employment_type_version')
            ->find()) : [];
        $typeCode = (string)($employee['employment_type_code'] ?? '');
        $typeVersion = (int)($employee['employment_type_version'] ?? 0);
        if (!$staff || !$employee
            || (int)($staff['store_id'] ?? 0) !== $dataScope->forcedStoreId()
            || (int)($staff['status'] ?? 0) !== 1
            || (int)($staff['is_del'] ?? 1) !== 0
            || (int)($employee['status'] ?? 0) !== 1
            || (int)($employee['is_del'] ?? 1) !== 0
            || !in_array($typeCode, CashierV3EntitlementProviderContracts::staffTypes(), true)
            || $typeVersion <= 0) {
            throw self::failure('authority_staff_not_active', ['staffId' => $staffId]);
        }
        $staffName = trim((string)($employee['name'] ?? ''));
        if ($staffName === '') {
            $staffName = trim((string)($staff['staff_name'] ?? ''));
        }
        if ($staffName === '') {
            throw self::failure('authority_staff_name_invalid', ['staffId' => $staffId]);
        }
        $fingerprint = hash('sha256', json_encode([
            'staffId' => $staffId,
            'employeeId' => (int)$staff['employee_id'],
            'storeId' => (int)$staff['store_id'],
            'staffName' => $staffName,
            'staffStatus' => (int)$staff['status'],
            'staffIsDel' => (int)$staff['is_del'],
            'craftsmanEligible' => (int)($staff['cashier_craftsman_enabled'] ?? 0),
            'employeeStatus' => (int)$employee['status'],
            'employeeIsDel' => (int)$employee['is_del'],
            'employeeTypeCode' => $typeCode,
            'employeeTypeAuthorityVersion' => $typeVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $row = $this->row(Db::name(CashierV3StaffProfileProvider::TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('staff_id', $staffId)
            ->field('current_version,profile_fingerprint')
            ->find());
        if (!$row) {
            return 1;
        }
        $version = self::positiveInt(
            $row['current_version'] ?? null,
            'authority_staff_version_invalid'
        );
        if (!hash_equals((string)($row['profile_fingerprint'] ?? ''), $fingerprint)) {
            if ($version >= PHP_INT_MAX) {
                throw self::failure('authority_staff_version_invalid');
            }
            return $version + 1;
        }
        return $version;
    }

    private function addResource(
        array &$resources,
        string $kind,
        string $id,
        int $version,
        string $role,
        string $providerContractVersion
    ): void {
        if (!CashierV3ResourceKindCatalog::isKnown($kind)
            || $id === '' || $version <= 0 || $role === '' || $providerContractVersion === '') {
            throw self::failure('authority_discovery_resource_invalid');
        }
        $key = $kind . "\0" . $id;
        if (!isset($resources[$key])) {
            $resources[$key] = [
                'kind' => $kind,
                'id' => $id,
                'expectedVersion' => $version,
                'roles' => [],
                'accessMode' => 'read',
                'providerContractVersion' => $providerContractVersion,
                'authorityFingerprint' => '',
            ];
        } elseif ((int)$resources[$key]['expectedVersion'] !== $version
            || (string)$resources[$key]['providerContractVersion'] !== $providerContractVersion) {
            throw self::failure('authority_discovery_resource_inconsistent', ['kind' => $kind, 'id' => $id]);
        }
        if (!in_array($role, $resources[$key]['roles'], true)) {
            $resources[$key]['roles'][] = $role;
        }
        sort($resources[$key]['roles'], SORT_STRING);
        $resources[$key]['authorityFingerprint'] = self::resourceFingerprint($resources[$key]);
    }

    private function mergeProviderResource(array &$resources, array $resource): void
    {
        foreach ((array)($resource['roles'] ?? []) as $role) {
            $this->addResource(
                $resources,
                (string)($resource['kind'] ?? ''),
                (string)($resource['id'] ?? ''),
                (int)($resource['expectedVersion'] ?? 0),
                (string)$role,
                (string)($resource['providerContractVersion'] ?? '')
            );
        }
    }

    private function sortResources(array $resources): array
    {
        usort($resources, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $left['kind'], $left['id'], $right['kind'], $right['id']
            );
        });
        return $resources;
    }

    private static function resourceFingerprint(array $resource): string
    {
        return hash('sha256', json_encode([
            'contractVersion' => self::DISCOVERY_CONTRACT_VERSION,
            'kind' => $resource['kind'],
            'id' => $resource['id'],
            'expectedVersion' => $resource['expectedVersion'],
            'roles' => $resource['roles'],
            'accessMode' => $resource['accessMode'],
            'providerContractVersion' => $resource['providerContractVersion'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function checkoutRequestId($value): string
    {
        $id = is_string($value) ? trim($value) : '';
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $id) !== 1) {
            throw self::failure('authority_checkout_request_id_invalid');
        }
        return $id;
    }

    private static function positiveInt($value, string $reason): int
    {
        $raw = is_int($value) ? (string)$value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::failure($reason);
        }
        return (int)$raw;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        $raw = is_int($value) ? (string)$value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::failure($reason);
        }
        return (int)$raw;
    }

    private static function token($value, int $max, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > $max
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function pathToken($value, int $max, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > $max
            || preg_match('/^[A-Za-z0-9:._\/-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function text($value, int $max, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function operatorName(array $profile): string
    {
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $value = trim((string)($profile[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        throw self::failure('authority_operator_name_invalid');
    }

    private static function assertExactKeys(array $value, array $expected, string $field): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::failure('authority_shape_invalid', ['field' => $field, 'actual' => $actual]);
        }
    }

    private function row($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) && $value ? $value : [];
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3EntitlementCompletionAuthorityException {
        return new CashierV3EntitlementCompletionAuthorityException($reason, $detail);
    }
}

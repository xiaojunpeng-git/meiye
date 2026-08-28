<?php

namespace app\services\cashier\v3\checkout;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;

/**
 * Isolated, side-effect-free domain kernel for C2-B1 entitlement completion.
 *
 * It consumes an operator intent, the orchestrator-filtered exact entitlement
 * subset of the already-locked checkout plan, and the authoritative snapshot
 * read under those locks. The outer checkout_request lock stays with the
 * orchestrator and is never passed here. This kernel never queries or writes a
 * table and never claims that completion happened; it returns a deterministic
 * persistence plan for a future Gateway handler.
 */
final class CashierV3EntitlementCompletionKernel
{
    public const CONTRACT_VERSION = 'c2-entitlement-completion-v3';
    public const AMOUNT_CALCULATION_VERSION = 'whole-yuan-floor-final-remainder-v1';
    public const ACTION = 'complete-entitlement-service';

    public const COMPOSITION_EMPTY = 'empty';
    public const COMPOSITION_SALE_ONLY = 'sale_only';
    public const COMPOSITION_ENTITLEMENT_ONLY = 'entitlement_only';
    public const COMPOSITION_MIXED = 'mixed';

    public const PERFORMANCE_ACTUAL = 'actual_entitlement_amount';
    public const PERFORMANCE_CONFIGURED = 'project_configured_amount';

    public const INVENTORY_DENY_SHORTAGE = 'deny_shortage';
    public const INVENTORY_ALLOW_SHORTAGE = 'allow_shortage';
    public const INVENTORY_POLICY_INHERIT = 'inherit';

    public const ENTITLEMENT_CARD_HOLDER = 'card_holder';
    public const ENTITLEMENT_INDEPENDENT_GIFT = 'independent_gift';
    public const ENTITLEMENT_ORDER_ATTACHED_GIFT = 'order_attached_gift';
    public const GIFT_SOURCE_HOLDER_BACKED = 'holder_backed';

    public const INVENTORY_PROVIDER_CONTRACT_VERSION = 'inventory-entitlement-completion-provider-v1';
    public const INVENTORY_SHORTAGE_CURSOR_GATE = 'explicit_resource_plan_locked_v1';

    /** A cashier workflow may complete an entitlement; standalone writeoff users may too. */
    private const REQUIRED_FEATURES_ANY_OF = [
        'cashier.v3.cashier',
        'cashier.v3.writeoff',
    ];

    private const EVENT_TYPES = [
        'entitlement.writeoff.completed',
        'service.completed',
        'performance.consumption.recorded',
        'performance.labor.allocated',
        'gift.consumed',
        'inventory.batch.consumed',
        'inventory.shortage.recorded',
        'inventory.service_consumption.resolved',
    ];

    private const FORBIDDEN_COMMAND_KEYS = [
        'cardOperation',
        'cardOperations',
        'cardUpgrade',
        'cardExtension',
        'cardTransfer',
        'cardDisable',
        'cardEnable',
        'projectReplacement',
        'projectUpgrade',
    ];

    private const FORBIDDEN_WRITE_DOMAINS = [
        'sales_order',
        'sale_line',
        'payment',
        'balance',
        'coupon',
        'new_debt',
        'card_operation',
    ];

    private const MAX_LINES = 100;
    private const MAX_TIMES = 1000000;
    private const MAX_MONEY_CENTS = 100000000000;
    private const MAX_STOCK_UNITS = 1000000000000;
    private const MAX_WEIGHT = 1000000;
    private const MAX_TOTAL_CONSUMABLE_REQUESTS = 1000;
    private const MAX_BATCHES_PER_STOCK = 500;
    private const MAX_TOTAL_BATCHES = 5000;
    private const MAX_ALLOCATION_STEPS = 500000;
    private const MAX_LOCKED_RESOURCES = 10000;

    /**
     * @param array $command Untrusted command intent after envelope parsing.
     * @param array $lockedSnapshot Authoritative snapshot built under the final lock set.
     * @param array $lockedResourcePlan Shared-layer plan that was already locked.
     */
    public static function plan(array $command, array $lockedSnapshot, array $lockedResourcePlan): array
    {
        $intent = self::normalizeCommand($command);
        $snapshot = self::normalizeSnapshot($lockedSnapshot);
        $lockedPlan = self::normalizeLockedResourcePlan($lockedResourcePlan);
        $intent = self::assertCommandMatchesSnapshot($intent, $snapshot);

        $roles = [];
        foreach ($snapshot['lines'] as $line) {
            $roles[] = $line['lineRole'];
        }
        $composition = self::classifyLineRoles($roles);
        if ($composition !== self::COMPOSITION_ENTITLEMENT_ONLY) {
            throw self::failure('entitlement_only_composition_required', ['composition' => $composition]);
        }

        $snapshotByLine = [];
        foreach ($snapshot['lines'] as $line) {
            $snapshotByLine[$line['lineId']] = $line;
        }

        // A browser-owned checkout has exactly one business snapshot. It does
        // not inherit the persisted-draft resource-version graph. The final
        // writer locks the real entitlement rows and verifies remaining
        // quantity in its transaction, so this legacy coverage check would
        // only create a second, stale-version gate.
        if ($snapshot['workspaceLockRequired']) {
            self::assertLockedResourceCoverage($lockedPlan, $snapshot);
        }

        $sourceGroups = self::sourceGroups($intent['lines'], $snapshotByLine);
        $lineAmounts = self::actualAmountsByLine($sourceGroups);
        $inventory = self::inventoryPlan($intent['lines'], $snapshotByLine, $snapshot['inventoryStocks']);

        $linePlans = [];
        $actualAmountTotal = 0;
        $consumptionTotal = 0;
        $laborTotal = 0;
        $serviceQuantityTotal = 0;
        foreach ($intent['lines'] as $intentLine) {
            $lineId = $intentLine['lineId'];
            $authority = $snapshotByLine[$lineId];
            $quantity = $intentLine['quantity'];
            $actualAmount = $lineAmounts[$lineId];
            $performance = self::performancePlan($authority['performance'], $actualAmount, $quantity);
            // “仅手工费”手艺人不参与劳动业绩分摊。保留服务和手工费
            // 快照，但不能把项目劳动业绩强行分配给这类人员。
            $hasPerformanceCraftsman = false;
            foreach ($authority['craftsmen'] as $craftsman) {
                if (($craftsman['craftsmanPerformanceType'] ?? 'commission_labor') !== 'labor') {
                    $hasPerformanceCraftsman = true;
                    break;
                }
            }
            if (!$hasPerformanceCraftsman) {
                $performance['laborAmountCents'] = 0;
            }
            $craftsmen = self::craftsmanPlan(
                $intentLine['craftsmanIds'],
                $authority['craftsmen'],
                $snapshot['storeId'],
                $performance['laborAmountCents']
            );
            $lineInventory = $inventory['byLine'][$lineId] ?? [
                'merchantDefaultPolicy' => $authority['inventory']['merchantDefaultPolicy'],
                'merchantDefaultPolicyVersion' => $authority['inventory']['merchantDefaultPolicyVersion'],
                'productPolicyOverride' => $authority['inventory']['productPolicyOverride'],
                'productPolicyVersion' => $authority['inventory']['productPolicyVersion'],
                'policy' => $authority['inventory']['policy'],
                'policyVersion' => $authority['inventory']['policyVersion'],
                'policyVersionSemantics' => $authority['inventory']['policyVersionSemantics'],
                'policyResolutionFingerprint' => $authority['inventory']['policyResolutionFingerprint'],
                'recipeId' => $authority['inventory']['recipeId'],
                'recipeVersion' => $authority['inventory']['recipeVersion'],
                'recipeFormulaHash' => $authority['inventory']['recipeFormulaHash'],
                'costComplete' => true,
                'actualCostCents' => 0,
                'estimatedShortageCostCents' => 0,
                'consumables' => [],
            ];

            $linePlans[] = [
                'lineId' => $lineId,
                'sortNo' => $authority['sortNo'],
                'source' => [
                    'entitlementInstanceType' => $authority['entitlementInstanceType'],
                    'entitlementInstanceId' => $authority['entitlementInstanceId'],
                    'sourceKind' => $authority['sourceKind'],
                    'isGift' => $authority['isGift'],
                    'giftSourceType' => $authority['giftSourceType'],
                    'giftId' => $authority['giftId'],
                    'giftVersion' => $authority['giftVersion'],
                    'holderId' => $authority['holderId'],
                    'originOrderId' => $authority['originOrderId'],
                    'sourceNameSnapshot' => $authority['sourceNameSnapshot'],
                    'sourceCodeSnapshot' => $authority['sourceCodeSnapshot'],
                    'sourceDetailId' => $authority['sourceDetailId'],
                    'projectId' => $authority['projectId'],
                    'projectNameSnapshot' => $authority['projectNameSnapshot'],
                    'projectCategoryIdSnapshot' => $authority['projectCategoryIdSnapshot'],
                    'projectCategoryNameSnapshot' => $authority['projectCategoryNameSnapshot'],
                    'sourceVersion' => $authority['sourceVersion'],
                    'detailVersion' => $authority['detailVersion'],
                    'purchaseAmountCents' => $authority['purchaseAmountCents'],
                    'totalPurchaseTimes' => $authority['totalPurchaseTimes'],
                    'consumedTimesAtLock' => $authority['consumedTimesAtLock'],
                    'amountCalculationVersion' => $authority['amountCalculationVersion'],
                ],
                'quantity' => $quantity,
                'actualEntitlementAmountCents' => $actualAmount,
                'performanceRuleSnapshot' => [
                    'ruleVersion' => $authority['performance']['ruleVersion'],
                    'consumptionMode' => $authority['performance']['consumptionMode'],
                    'consumptionConfiguredUnitAmountCents' => $authority['performance']['consumptionConfiguredUnitAmountCents'],
                    'laborMode' => $authority['performance']['laborMode'],
                    'laborConfiguredUnitAmountCents' => $authority['performance']['laborConfiguredUnitAmountCents'],
                ],
                'consumptionPerformance' => [
                    'mode' => $performance['consumptionMode'],
                    'amountCents' => $performance['consumptionAmountCents'],
                ],
                'laborPerformance' => [
                    'mode' => $performance['laborMode'],
                    'amountCents' => $performance['laborAmountCents'],
                    'allocations' => $craftsmen,
                ],
                'serviceSnapshot' => [
                    'serviceObject' => $intentLine['serviceObject'],
                    'isExperience' => $intentLine['isExperience'],
                    'craftsmen' => $craftsmen,
                    'primaryCraftsmanId' => $craftsmen[0]['staffId'],
                    'businessDate' => $snapshot['businessDate'],
                    'businessTimezone' => $snapshot['businessTimezone'],
                    'occurredAt' => $snapshot['occurredAt'],
                    'settledAt' => $snapshot['settledAt'],
                    'recordedAt' => $snapshot['recordedAt'],
                    'sourceType' => $snapshot['source']['type'],
                    'serviceOrderId' => $snapshot['source']['serviceOrderId'],
                    'reservationId' => $snapshot['source']['reservationId'],
                    'occupationContributors' => $authority['occupationContributors'],
                ],
                'inventory' => $lineInventory,
                'factIntents' => [
                    'writeoff' => 1,
                    'service' => 1,
                    'consumptionPerformance' => 1,
                    'laborPerformance' => count($craftsmen),
                    'giftConsumption' => $authority['isGift'] ? 1 : 0,
                    'inventoryBatchConsumption' => self::countInventoryAllocations($lineInventory),
                    'inventoryShortage' => self::countInventoryShortages($lineInventory),
                ],
                'naturalKeyComponents' => [
                    'workspaceId' => $intent['workspaceId'],
                    'lineId' => $lineId,
                    'sourceVersion' => $authority['sourceVersion'],
                    'detailVersion' => $authority['detailVersion'],
                ],
            ];
            $serviceQuantityTotal += $quantity;
            $actualAmountTotal += $actualAmount;
            $consumptionTotal += $performance['consumptionAmountCents'];
            $laborTotal += $performance['laborAmountCents'];
        }

        $eventContract = self::eventContract($linePlans, $snapshot['workspaceId']);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'action' => self::ACTION,
            'composition' => $composition,
            'persistenceStatus' => 'not_persisted',
            'requiresGatewayTransaction' => true,
            'workspaceId' => $intent['workspaceId'],
            'stateContextId' => $intent['stateContextId'],
            'permissionSnapshotFingerprint' => $snapshot['permissionSnapshotFingerprint'],
            'memberId' => $intent['memberId'],
            'storeId' => $snapshot['storeId'],
            'operatorId' => $snapshot['operatorId'],
            'businessDate' => $snapshot['businessDate'],
            'businessTimezone' => $snapshot['businessTimezone'],
            'occurredAt' => $snapshot['occurredAt'],
            'settledAt' => $snapshot['settledAt'],
            'recordedAt' => $snapshot['recordedAt'],
            'dimensionSnapshot' => [
                'tenantId' => $snapshot['tenantId'],
                'organizationId' => $snapshot['organizationId'],
                'organizationName' => $snapshot['organizationName'],
                'organizationPath' => $snapshot['organizationPath'],
                'storeId' => $snapshot['storeId'],
                'storeName' => $snapshot['storeName'],
                'memberId' => $snapshot['memberId'],
                'memberName' => $snapshot['memberName'],
                'operatorId' => $snapshot['operatorId'],
                'operatorName' => $snapshot['operatorName'],
            ],
            'source' => $snapshot['source'],
            'inventoryAdapterContract' => [
                'consumerContractVersion' => self::CONTRACT_VERSION,
                'providerContractVersion' => $snapshot['inventoryProviderContractVersion'],
                'providerTenantId' => $snapshot['inventoryProviderTenantId'],
                'providerStoreId' => $snapshot['inventoryProviderStoreId'],
                'shortageCursorLockGate' => $snapshot['inventoryShortageCursorLockGate'],
            ],
            'lockedResourcePlan' => $lockedPlan,
            'entitlementDeductions' => self::deductionPlans($sourceGroups),
            'linePlans' => $linePlans,
            'totals' => [
                'lineCount' => count($linePlans),
                'serviceQuantity' => $serviceQuantityTotal,
                'actualEntitlementAmountCents' => $actualAmountTotal,
                'consumptionPerformanceCents' => $consumptionTotal,
                'laborPerformanceCents' => $laborTotal,
                'actualInventoryCostCents' => $inventory['actualCostCents'],
                'estimatedInventoryShortageCostCents' => $inventory['estimatedShortageCostCents'],
                'costComplete' => $inventory['costComplete'],
            ],
            'requiredEventTypes' => self::requiredEventTypes($eventContract),
            'allowedEventTypes' => array_keys($eventContract),
            'requiredEventContract' => $eventContract,
            'forbiddenWriteDomains' => self::FORBIDDEN_WRITE_DOMAINS,
            'activationContract' => [
                'gatewayStatus' => 'blocked_until_all_providers_and_legacy_writers_are_ready',
                'permissionSnapshot' => [
                    'source' => 'CashierV3DataScopeContext.permissionVersion',
                    'mustMatchCommandFingerprint' => true,
                    'resourceKind' => null,
                ],
                'staffProfile' => [
                    'resourceKind' => 'staff_profile',
                    'authority' => ['system_store_staff', 'employee'],
                    'providerRequired' => true,
                ],
                'entitlementDebtGuard' => [
                    'resourceKind' => 'entitlement_debt_guard',
                    'identity' => 'order:<originOrderId>',
                    'coversAbsentDebtRow' => true,
                    'legacyDebtCreateAndRepayMustShareGuard' => true,
                ],
                'standaloneGift' => [
                    'independent' => 'disabled_no_authority',
                    'orderAttached' => 'disabled_no_authority',
                    'legacyHolderBacked' => 'supported',
                ],
                'occupation' => [
                    'completeContributorSetRequired' => true,
                    'currentSourceConversionMustMatchContributor' => true,
                ],
                'inventory' => [
                    'consumerContractVersion' => self::CONTRACT_VERSION,
                    'providerContractVersion' => self::INVENTORY_PROVIDER_CONTRACT_VERSION,
                    'shortageCursorGate' => self::INVENTORY_SHORTAGE_CURSOR_GATE,
                    'callerOwnsFinalBusinessTransaction' => true,
                    'dataScopeMustMatchTenantAndStore' => true,
                    'providerLockOrder' => [
                        'inventory_policy' => 46,
                        'inventory_recipe' => 47,
                        'inventory_stock' => 50,
                        'inventory_batch' => 55,
                        'inventory_shortage_cursor' => 56,
                    ],
                ],
            ],
            'integrationRequirements' => [
                'registerCanonicalActionAndStaticEventContract',
                'deriveCompleteResourcePlanBeforeSnapshotAndLockEveryResourceBeforeCallingKernel',
                'passAndMatchGatewayPermissionSnapshotFingerprintAfterPermissionLock',
                'recheckMemberEntitlementStaffProfileAndStoreScopeInTransaction',
                'registerStaffProfileProviderThatLocksStoreStaffAndEmployeeBinding',
                'lockStableEntitlementDebtGuardBeforeReadingDebtEvenWhenNoDebtExists',
                'makeLegacyDebtCreationAndRepaymentLockAndAdvanceTheSameEntitlementDebtGuard',
                'selectorMustReceiveCurrentServiceOrReservationContextAndReturnEveryOccupationContributor',
                'lockAndRecheckEveryOccupationContributorOrFailClosed',
                'persistWriteoffServiceInventoryPerformanceFactsAndEventsAtomically',
                'inventoryAdapterMustRejectConsumerOrProviderContractVersionMismatch',
                'inventoryProviderMustReturnEveryLockedShortageCursorAsAnExplicitVersionedResource',
                'callInventoryProviderInsideFinalGatewayBusinessTransaction',
                'assertInventoryTenantAndStoreAgainstLockedGatewayDataScope',
                'advanceBatchAndShortageCostAllocationCursorsAtomicallyWithFacts',
                'lockMerchantDefaultAndProductOverrideAndAdvanceResolvedPolicyVersionWhenEitherChanges',
                'persistMerchantDefaultProductOverrideAndResolvedInventoryPolicySnapshots',
                'recordBusinessDateOccurredAtSettledAtAndRecordedAtSeparately',
                'closeWorkspaceDraftAndAdvanceVersionsInSameTransaction',
                'mapKernelReasonsToSharedResultCodes',
                'addLegacyAndV3TwoConnectionEntitlementRaceTest',
                'addReversalFactsWithoutOverwritingOriginalFacts',
            ],
        ];
    }

    public static function classifyLineRoles(array $roles): string
    {
        $hasSale = false;
        $hasEntitlement = false;
        foreach ($roles as $index => $role) {
            if ($role === 'sale') {
                $hasSale = true;
            } elseif ($role === 'entitlement_service') {
                $hasEntitlement = true;
            } else {
                throw self::failure('line_role_not_allowed', ['index' => $index, 'lineRole' => $role]);
            }
        }
        if (!$hasSale && !$hasEntitlement) {
            return self::COMPOSITION_EMPTY;
        }
        if ($hasSale && $hasEntitlement) {
            return self::COMPOSITION_MIXED;
        }
        return $hasEntitlement ? self::COMPOSITION_ENTITLEMENT_ONLY : self::COMPOSITION_SALE_ONLY;
    }

    public static function allocateActualAmountCents(
        int $purchaseAmountCents,
        int $totalPurchaseTimes,
        int $consumedTimes,
        int $quantity,
        string $amountCalculationVersion = self::AMOUNT_CALCULATION_VERSION
    ): int {
        self::assertNonnegativeInt($purchaseAmountCents, 'purchaseAmountCents', self::MAX_MONEY_CENTS);
        self::assertPositiveInt($totalPurchaseTimes, 'totalPurchaseTimes', self::MAX_TIMES);
        self::assertNonnegativeInt($consumedTimes, 'consumedTimes', $totalPurchaseTimes);
        self::assertPositiveInt($quantity, 'quantity', self::MAX_TIMES);
        if ($quantity > $totalPurchaseTimes - $consumedTimes) {
            throw self::failure('entitlement_allocation_times_invalid');
        }
        if (self::isCentCapableAmountCalculation($amountCalculationVersion)) {
            $regularAmount = intdiv($purchaseAmountCents, $totalPurchaseTimes);
            $start = $regularAmount * $consumedTimes;
            $end = $regularAmount * ($consumedTimes + $quantity);
            if ($consumedTimes + $quantity >= $totalPurchaseTimes) {
                $end = $purchaseAmountCents;
            }
            return $end - $start;
        }
        if ($purchaseAmountCents % 100 !== 0) {
            throw self::failure('entitlement_purchase_amount_not_whole_yuan');
        }
        $start = self::cumulativeAmount($purchaseAmountCents, $totalPurchaseTimes, $consumedTimes);
        $end = self::cumulativeAmount(
            $purchaseAmountCents,
            $totalPurchaseTimes,
            $consumedTimes + $quantity
        );
        return $end - $start;
    }

    private static function isCentCapableAmountCalculation(string $version): bool
    {
        return str_starts_with($version, 'operation-cent-');
    }

    /**
     * @param array<int,int> $staffIds
     * @param array<int,int> $weightsByStaffId
     * @return array<int,array{staffId:int,isPrimary:bool,sequence:int,amountCents:int}>
     */
    public static function allocateLaborAmount(int $amountCents, array $staffIds, array $weightsByStaffId): array
    {
        self::assertNonnegativeInt($amountCents, 'laborAmountCents', self::MAX_MONEY_CENTS);
        if (!$staffIds || count($staffIds) > 20) {
            throw self::failure('craftsman_count_invalid');
        }
        $seen = [];
        $weightSum = 0;
        foreach ($staffIds as $staffId) {
            self::assertPositiveInt($staffId, 'staffId');
            if (isset($seen[$staffId])) {
                throw self::failure('craftsman_duplicate', ['staffId' => $staffId]);
            }
            $seen[$staffId] = true;
            $weight = $weightsByStaffId[$staffId] ?? null;
            if (!is_int($weight) || $weight <= 0 || $weight > self::MAX_WEIGHT) {
                throw self::failure('craftsman_weight_invalid', ['staffId' => $staffId]);
            }
            $weightSum += $weight;
        }
        if (count($weightsByStaffId) !== count($staffIds)) {
            throw self::failure('craftsman_weight_scope_mismatch');
        }

        $secondaryTotal = 0;
        $amounts = [];
        foreach ($staffIds as $index => $staffId) {
            if ($index === 0) {
                continue;
            }
            $part = intdiv($amountCents * $weightsByStaffId[$staffId], $weightSum);
            $amounts[$staffId] = $part;
            $secondaryTotal += $part;
        }
        $amounts[$staffIds[0]] = $amountCents - $secondaryTotal;

        $result = [];
        foreach ($staffIds as $index => $staffId) {
            $result[] = [
                'staffId' => $staffId,
                'isPrimary' => $index === 0,
                'sequence' => $index + 1,
                'amountCents' => $amounts[$staffId],
            ];
        }
        return $result;
    }

    private static function normalizeCommand(array $command): array
    {
        foreach (self::FORBIDDEN_COMMAND_KEYS as $key) {
            if (array_key_exists($key, $command)) {
                throw self::failure('card_operation_not_in_scope', ['key' => $key]);
            }
        }
        self::assertExactKeys(
            $command,
            [
                'contractVersion',
                'action',
                'workspaceId',
                'stateContextId',
                'permissionSnapshotFingerprint',
                'memberId',
                'lines',
            ],
            'command'
        );
        if ($command['contractVersion'] !== self::CONTRACT_VERSION) {
            throw self::failure('contract_version_mismatch');
        }
        if ($command['action'] !== self::ACTION) {
            throw self::failure('action_not_allowed', ['action' => $command['action']]);
        }
        self::assertToken($command['workspaceId'], 'workspaceId', 128);
        self::assertToken($command['stateContextId'], 'stateContextId', 128);
        self::assertPermissionFingerprint($command['permissionSnapshotFingerprint'], 'command.permissionSnapshotFingerprint');
        self::assertPositiveInt($command['memberId'], 'memberId');
        if (!is_array($command['lines']) || !self::isList($command['lines'])
            || count($command['lines']) < 1 || count($command['lines']) > self::MAX_LINES) {
            throw self::failure('command_lines_invalid');
        }
        $lines = [];
        $seen = [];
        foreach ($command['lines'] as $index => $line) {
            $normalized = self::normalizeCommandLine($line, $index);
            if (isset($seen[$normalized['lineId']])) {
                throw self::failure('command_line_duplicate', ['lineId' => $normalized['lineId']]);
            }
            $seen[$normalized['lineId']] = true;
            $lines[] = $normalized;
        }
        $command['lines'] = $lines;
        return $command;
    }

    private static function normalizeSource($source): array
    {
        if (!is_array($source)) {
            throw self::failure('service_source_invalid');
        }
        self::assertExactKeys(
            $source,
            ['type', 'serviceOrderId', 'serviceOrderVersion', 'reservationId', 'reservationVersion'],
            'source'
        );
        if (!in_array($source['type'], ['direct', 'service_order', 'reservation'], true)) {
            throw self::failure('service_source_type_invalid');
        }
        self::assertNonnegativeInt($source['serviceOrderId'], 'serviceOrderId');
        self::assertNonnegativeInt($source['serviceOrderVersion'], 'serviceOrderVersion');
        self::assertNonnegativeInt($source['reservationId'], 'reservationId');
        self::assertNonnegativeInt($source['reservationVersion'], 'reservationVersion');
        if ($source['type'] === 'direct' && ($source['serviceOrderId'] !== 0
            || $source['serviceOrderVersion'] !== 0
            || $source['reservationId'] !== 0
            || $source['reservationVersion'] !== 0)) {
            throw self::failure('direct_source_must_not_reference_service');
        }
        if ($source['type'] === 'service_order'
            && ($source['serviceOrderId'] <= 0 || $source['serviceOrderVersion'] <= 0)) {
            throw self::failure('service_order_source_id_required');
        }
        if ($source['type'] === 'reservation'
            && ($source['reservationId'] <= 0 || $source['reservationVersion'] <= 0)) {
            throw self::failure('reservation_source_id_required');
        }
        if ($source['serviceOrderId'] === 0 && $source['serviceOrderVersion'] !== 0) {
            throw self::failure('service_order_source_version_orphan');
        }
        if ($source['reservationId'] === 0 && $source['reservationVersion'] !== 0) {
            throw self::failure('reservation_source_version_orphan');
        }
        return $source;
    }

    private static function normalizeCommandLine($line, int $index): array
    {
        if (!is_array($line)) {
            throw self::failure('command_line_shape_invalid', ['index' => $index]);
        }
        self::assertExactKeys($line, ['lineId', 'quantity', 'serviceObject', 'isExperience', 'craftsmanIds'], 'command.lines');
        self::assertToken($line['lineId'], 'lineId', 128);
        self::assertPositiveInt($line['quantity'], 'quantity', self::MAX_TIMES);
        if (!in_array($line['serviceObject'], ['self', 'friend'], true)) {
            throw self::failure('service_object_invalid', ['lineId' => $line['lineId']]);
        }
        if (!is_bool($line['isExperience'])) {
            throw self::failure('experience_flag_invalid', ['lineId' => $line['lineId']]);
        }
        if (!is_array($line['craftsmanIds']) || !self::isList($line['craftsmanIds'])
            || count($line['craftsmanIds']) < 1 || count($line['craftsmanIds']) > 20) {
            throw self::failure('craftsman_count_invalid', ['lineId' => $line['lineId']]);
        }
        $seen = [];
        foreach ($line['craftsmanIds'] as $staffId) {
            self::assertPositiveInt($staffId, 'craftsmanId');
            if (isset($seen[$staffId])) {
                throw self::failure('craftsman_duplicate', ['lineId' => $line['lineId'], 'staffId' => $staffId]);
            }
            $seen[$staffId] = true;
        }
        return $line;
    }

    private static function normalizeSnapshot(array $snapshot): array
    {
        // Older internal callers represent the persisted-workspace flow and
        // therefore retain its lock requirement by default. The direct browser
        // snapshot adapter sets this explicitly from its trusted scope.
        if (!array_key_exists('workspaceLockRequired', $snapshot)) {
            $snapshot['workspaceLockRequired'] = true;
        }
        self::assertExactKeys($snapshot, [
            'workspaceId',
            'stateContextId',
            'workspaceVersion',
            'workspaceLockRequired',
            'tenantId',
            'organizationId',
            'organizationName',
            'organizationPath',
            'storeId',
            'storeName',
            'memberId',
            'memberName',
            'memberVersion',
            'memberActive',
            'workspaceStatus',
            'operatorId',
            'operatorName',
            'operatorStoreId',
            'permissionSnapshotFingerprint',
            'operatorFeatures',
            'businessDate',
            'businessTimezone',
            'occurredAt',
            'settledAt',
            'recordedAt',
            'source',
            'inventoryProviderContractVersion',
            'inventoryProviderTenantId',
            'inventoryProviderStoreId',
            'inventoryShortageCursorLockGate',
            'lines',
            'inventoryStocks',
        ], 'snapshot');
        self::assertToken($snapshot['workspaceId'], 'snapshot.workspaceId', 128);
        self::assertToken($snapshot['stateContextId'], 'snapshot.stateContextId', 128);
        self::assertPositiveInt($snapshot['workspaceVersion'], 'snapshot.workspaceVersion');
        if (!is_bool($snapshot['workspaceLockRequired'])) {
            throw self::failure('workspace_lock_requirement_invalid');
        }
        self::assertToken($snapshot['tenantId'], 'snapshot.tenantId', 64);
        self::assertNonnegativeInt($snapshot['organizationId'], 'snapshot.organizationId');
        self::assertNonemptyString($snapshot['organizationName'], 'snapshot.organizationName', 128);
        self::assertNonemptyString($snapshot['organizationPath'], 'snapshot.organizationPath', 191);
        self::assertPositiveInt($snapshot['storeId'], 'snapshot.storeId');
        self::assertNonemptyString($snapshot['storeName'], 'snapshot.storeName', 128);
        self::assertPositiveInt($snapshot['memberId'], 'snapshot.memberId');
        self::assertNonemptyString($snapshot['memberName'], 'snapshot.memberName', 128);
        self::assertPositiveInt($snapshot['memberVersion'], 'snapshot.memberVersion');
        if ($snapshot['memberActive'] !== true) {
            throw self::failure('member_not_active');
        }
        if ($snapshot['workspaceStatus'] !== 'editing') {
            throw self::failure('workspace_not_editable', ['status' => $snapshot['workspaceStatus']]);
        }
        self::assertPositiveInt($snapshot['operatorId'], 'snapshot.operatorId');
        self::assertNonemptyString($snapshot['operatorName'], 'snapshot.operatorName', 128);
        self::assertPositiveInt($snapshot['operatorStoreId'], 'snapshot.operatorStoreId');
        self::assertPermissionFingerprint(
            $snapshot['permissionSnapshotFingerprint'],
            'snapshot.permissionSnapshotFingerprint'
        );
        if ($snapshot['operatorStoreId'] !== $snapshot['storeId']) {
            throw self::failure('operator_store_mismatch');
        }
        self::assertStringList($snapshot['operatorFeatures'], 'operatorFeatures', 50);
        if (!array_intersect(self::REQUIRED_FEATURES_ANY_OF, $snapshot['operatorFeatures'])) {
            throw self::failure('required_feature_missing', [
                'requiredAnyOf' => self::REQUIRED_FEATURES_ANY_OF,
            ]);
        }
        self::assertDate($snapshot['businessDate']);
        if ($snapshot['businessTimezone'] !== 'Asia/Shanghai') {
            throw self::failure('business_timezone_not_allowed');
        }
        self::assertPositiveInt($snapshot['occurredAt'], 'snapshot.occurredAt');
        self::assertPositiveInt($snapshot['settledAt'], 'snapshot.settledAt');
        self::assertPositiveInt($snapshot['recordedAt'], 'snapshot.recordedAt');
        if ($snapshot['settledAt'] < $snapshot['occurredAt']
            || $snapshot['recordedAt'] < $snapshot['settledAt']) {
            throw self::failure('completion_time_order_invalid');
        }
        $snapshot['source'] = self::normalizeSource($snapshot['source']);
        if ($snapshot['inventoryProviderContractVersion'] !== self::INVENTORY_PROVIDER_CONTRACT_VERSION) {
            throw self::failure('inventory_provider_contract_version_mismatch');
        }
        self::assertToken($snapshot['inventoryProviderTenantId'], 'inventoryProviderTenantId', 64);
        self::assertPositiveInt($snapshot['inventoryProviderStoreId'], 'inventoryProviderStoreId');
        if ($snapshot['inventoryProviderTenantId'] !== $snapshot['tenantId']
            || $snapshot['inventoryProviderStoreId'] !== $snapshot['storeId']) {
            throw self::failure('inventory_provider_scope_mismatch');
        }
        if ($snapshot['inventoryShortageCursorLockGate'] !== self::INVENTORY_SHORTAGE_CURSOR_GATE) {
            throw self::failure('inventory_shortage_cursor_gate_missing');
        }
        if (!is_array($snapshot['lines']) || !self::isList($snapshot['lines'])
            || count($snapshot['lines']) < 1 || count($snapshot['lines']) > self::MAX_LINES) {
            throw self::failure('snapshot_lines_invalid');
        }
        $lines = [];
        $seen = [];
        foreach ($snapshot['lines'] as $index => $line) {
            $normalized = self::normalizeSnapshotLine($line, $snapshot['storeId'], $index);
            self::assertOccupationContextMatchesSource($normalized, $snapshot['source']);
            if (isset($seen[$normalized['lineId']])) {
                throw self::failure('snapshot_line_duplicate', ['lineId' => $normalized['lineId']]);
            }
            $seen[$normalized['lineId']] = true;
            $lines[] = $normalized;
        }
        usort($lines, static function (array $left, array $right): int {
            $sort = $left['sortNo'] <=> $right['sortNo'];
            return $sort !== 0 ? $sort : strcmp($left['lineId'], $right['lineId']);
        });
        self::assertSharedResourceSnapshots($lines);
        $snapshot['lines'] = $lines;
        $snapshot['inventoryStocks'] = self::normalizeInventoryStocks($snapshot['inventoryStocks']);
        self::assertComplexityBudget($snapshot['lines'], $snapshot['inventoryStocks']);
        return $snapshot;
    }

    private static function normalizeSnapshotLine($line, int $storeId, int $index): array
    {
        if (!is_array($line)) {
            throw self::failure('snapshot_line_shape_invalid', ['index' => $index]);
        }
        self::assertExactKeys($line, [
            'lineId',
            'sortNo',
            'lineRole',
            'entitlementInstanceType',
            'entitlementInstanceId',
            'sourceKind',
            'isGift',
            'giftSourceType',
            'giftId',
            'giftVersion',
            'holderId',
            'originOrderId',
            'sourceNameSnapshot',
            'sourceCodeSnapshot',
            'sourceDetailId',
            'projectId',
            'projectNameSnapshot',
            'projectCategoryIdSnapshot',
            'projectCategoryNameSnapshot',
            'sourceVersion',
            'detailVersion',
            'holderActive',
            'detailActive',
            'usableAtSettlement',
            'physicalRemainingTimes',
            'debtLimitedUsableTimes',
            'debtGuardId',
            'debtGuardVersion',
            'occupiedTimes',
            'currentSourceConvertibleTimes',
            'occupationAuthorityComplete',
            'occupationContributors',
            'purchaseAmount',
            'totalPurchaseTimes',
            'amountCalculationVersion',
            'allowedStoreIds',
            'craftsmen',
            'performance',
            'inventory',
        ], 'snapshot.lines');
        self::assertToken($line['lineId'], 'snapshot.lineId', 128);
        self::assertPositiveInt($line['sortNo'], 'snapshot.sortNo', self::MAX_TIMES);
        if (!in_array($line['lineRole'], ['sale', 'entitlement_service'], true)) {
            throw self::failure('line_role_not_allowed', ['lineId' => $line['lineId']]);
        }
        if (!in_array($line['entitlementInstanceType'], [
            self::ENTITLEMENT_CARD_HOLDER,
            self::ENTITLEMENT_INDEPENDENT_GIFT,
            self::ENTITLEMENT_ORDER_ATTACHED_GIFT,
        ], true)) {
            throw self::failure('entitlement_instance_type_invalid', ['lineId' => $line['lineId']]);
        }
        self::assertPositiveInt($line['entitlementInstanceId'], 'entitlementInstanceId');
        if (!in_array($line['sourceKind'], ['count_card', 'time_card', 'custom_card', 'gift', 'unknown'], true)) {
            throw self::failure('entitlement_source_kind_invalid', ['lineId' => $line['lineId']]);
        }
        if (!is_bool($line['isGift'])) {
            throw self::failure('entitlement_gift_flag_invalid', ['lineId' => $line['lineId']]);
        }
        if (!in_array(
            $line['giftSourceType'],
            ['none', self::GIFT_SOURCE_HOLDER_BACKED, 'independent', 'order_attached'],
            true
        )) {
            throw self::failure('gift_source_type_invalid', ['lineId' => $line['lineId']]);
        }
        self::assertNonnegativeInt($line['holderId'], 'holderId');
        self::assertNonnegativeInt($line['originOrderId'], 'originOrderId');
        self::assertNonnegativeInt($line['giftId'], 'giftId');
        self::assertNonnegativeInt($line['giftVersion'], 'giftVersion');
        if ($line['entitlementInstanceType'] === self::ENTITLEMENT_CARD_HOLDER) {
            if ($line['holderId'] <= 0
                || $line['entitlementInstanceId'] !== $line['holderId']
                || $line['originOrderId'] <= 0
                || $line['giftId'] !== 0
                || $line['giftVersion'] !== 0) {
                throw self::failure('card_holder_source_identity_invalid', ['lineId' => $line['lineId']]);
            }
            if ($line['isGift']) {
                if ($line['sourceKind'] !== 'gift'
                    || $line['giftSourceType'] !== self::GIFT_SOURCE_HOLDER_BACKED) {
                    throw self::failure('holder_backed_gift_identity_invalid', ['lineId' => $line['lineId']]);
                }
            } elseif ($line['sourceKind'] === 'gift' || $line['giftSourceType'] !== 'none') {
                throw self::failure('card_holder_source_identity_invalid', ['lineId' => $line['lineId']]);
            }
        } else {
            // 当前生产权威只有 user_card_holder + cart detail。在独立赠送
            // 权威表、provider 与并发测试完成前，禁止虚构可达路径。
            throw self::failure('standalone_gift_authority_not_ready', [
                'lineId' => $line['lineId'],
                'entitlementInstanceType' => $line['entitlementInstanceType'],
            ]);
        }
        self::assertNonemptyString($line['sourceNameSnapshot'], 'sourceNameSnapshot', 128);
        self::assertNonemptyString($line['sourceCodeSnapshot'], 'sourceCodeSnapshot', 128);
        self::assertPositiveInt($line['sourceDetailId'], 'sourceDetailId');
        self::assertPositiveInt($line['projectId'], 'projectId');
        self::assertNonemptyString($line['projectNameSnapshot'], 'projectNameSnapshot', 128);
        self::assertNonnegativeInt($line['projectCategoryIdSnapshot'], 'projectCategoryIdSnapshot');
        self::assertNonemptyString($line['projectCategoryNameSnapshot'], 'projectCategoryNameSnapshot', 128);
        self::assertPositiveInt($line['sourceVersion'], 'sourceVersion');
        self::assertPositiveInt($line['detailVersion'], 'detailVersion');
        if ($line['holderActive'] !== true || $line['detailActive'] !== true || $line['usableAtSettlement'] !== true) {
            throw self::failure('entitlement_not_usable', ['lineId' => $line['lineId']]);
        }
        self::assertPositiveInt($line['totalPurchaseTimes'], 'totalPurchaseTimes', self::MAX_TIMES);
        self::assertToken($line['amountCalculationVersion'], 'amountCalculationVersion', 128);
        if (substr(
            $line['amountCalculationVersion'],
            -strlen(self::AMOUNT_CALCULATION_VERSION)
        ) !== self::AMOUNT_CALCULATION_VERSION) {
            throw self::failure('entitlement_amount_calculation_version_stale', ['lineId' => $line['lineId']]);
        }
        self::assertNonnegativeInt($line['physicalRemainingTimes'], 'physicalRemainingTimes', $line['totalPurchaseTimes']);
        self::assertNonnegativeInt($line['debtLimitedUsableTimes'], 'debtLimitedUsableTimes', $line['physicalRemainingTimes']);
        self::assertToken($line['debtGuardId'], 'debtGuardId', 128);
        self::assertPositiveInt($line['debtGuardVersion'], 'debtGuardVersion');
        if ($line['debtGuardId'] !== 'order:' . $line['originOrderId']) {
            throw self::failure('entitlement_debt_guard_identity_invalid', ['lineId' => $line['lineId']]);
        }
        self::assertNonnegativeInt($line['occupiedTimes'], 'occupiedTimes', $line['debtLimitedUsableTimes']);
        self::assertNonnegativeInt(
            $line['currentSourceConvertibleTimes'],
            'currentSourceConvertibleTimes',
            $line['occupiedTimes']
        );
        if ($line['occupationAuthorityComplete'] !== true) {
            throw self::failure('occupation_authority_incomplete', ['lineId' => $line['lineId']]);
        }
        $line['occupationContributors'] = self::normalizeOccupationContributors(
            $line['occupationContributors'],
            $line['lineId'],
            $line['occupiedTimes'],
            $line['currentSourceConvertibleTimes']
        );
        if ($line['physicalRemainingTimes'] <= 0) {
            throw self::failure('entitlement_physical_remaining_empty', ['lineId' => $line['lineId']]);
        }
        $line['usableTimesForCommand'] = $line['debtLimitedUsableTimes']
            - $line['occupiedTimes']
            + $line['currentSourceConvertibleTimes'];
        $line['consumedTimesAtLock'] = $line['totalPurchaseTimes'] - $line['physicalRemainingTimes'];
        $line['purchaseAmountCents'] = self::moneyToCents($line['purchaseAmount'], 'purchaseAmount');
        self::assertPositiveIntList($line['allowedStoreIds'], 'allowedStoreIds', 1000);
        sort($line['allowedStoreIds'], SORT_NUMERIC);
        if (!in_array($storeId, $line['allowedStoreIds'], true)) {
            throw self::failure('entitlement_store_not_allowed', ['lineId' => $line['lineId'], 'storeId' => $storeId]);
        }
        $line['craftsmen'] = self::normalizeAuthorityCraftsmen($line['craftsmen'], $line['lineId']);
        $line['performance'] = self::normalizePerformance($line['performance'], $line['lineId']);
        $line['inventory'] = self::normalizeLineInventory($line['inventory'], $line['lineId']);
        return $line;
    }

    private static function normalizeOccupationContributors(
        $contributors,
        string $lineId,
        int $occupiedTimes,
        int $currentSourceConvertibleTimes
    ): array {
        if (!is_array($contributors) || !self::isList($contributors) || count($contributors) > 100) {
            throw self::failure('occupation_contributors_invalid', ['lineId' => $lineId]);
        }
        $normalized = [];
        $seen = [];
        $occupiedTotal = 0;
        $convertibleTotal = 0;
        foreach ($contributors as $index => $contributor) {
            if (!is_array($contributor)) {
                throw self::failure('occupation_contributor_shape_invalid', ['lineId' => $lineId, 'index' => $index]);
            }
            self::assertExactKeys(
                $contributor,
                ['kind', 'id', 'version', 'occupiedTimes', 'convertibleTimes'],
                'occupationContributor'
            );
            if (!in_array($contributor['kind'], ['service_order', 'reservation'], true)) {
                throw self::failure('occupation_contributor_kind_invalid', ['lineId' => $lineId, 'index' => $index]);
            }
            self::assertPositiveInt($contributor['id'], 'occupationContributor.id');
            self::assertPositiveInt($contributor['version'], 'occupationContributor.version');
            self::assertPositiveInt(
                $contributor['occupiedTimes'],
                'occupationContributor.occupiedTimes',
                self::MAX_TIMES
            );
            self::assertNonnegativeInt(
                $contributor['convertibleTimes'],
                'occupationContributor.convertibleTimes',
                $contributor['occupiedTimes']
            );
            $physical = $contributor['kind'] . ':' . $contributor['id'];
            if (isset($seen[$physical])) {
                throw self::failure('occupation_contributor_duplicate', [
                    'lineId' => $lineId,
                    'kind' => $contributor['kind'],
                    'id' => $contributor['id'],
                ]);
            }
            $seen[$physical] = true;
            $occupiedTotal += $contributor['occupiedTimes'];
            $convertibleTotal += $contributor['convertibleTimes'];
            $normalized[] = $contributor;
        }
        if ($occupiedTotal !== $occupiedTimes || $convertibleTotal !== $currentSourceConvertibleTimes) {
            throw self::failure('occupation_contributor_totals_mismatch', [
                'lineId' => $lineId,
                'expectedOccupiedTimes' => $occupiedTimes,
                'actualOccupiedTimes' => $occupiedTotal,
                'expectedConvertibleTimes' => $currentSourceConvertibleTimes,
                'actualConvertibleTimes' => $convertibleTotal,
            ]);
        }
        usort($normalized, static function (array $left, array $right): int {
            $kind = strcmp($left['kind'], $right['kind']);
            return $kind !== 0 ? $kind : ($left['id'] <=> $right['id']);
        });
        return $normalized;
    }

    private static function assertOccupationContextMatchesSource(array $line, array $source): void
    {
        foreach ($line['occupationContributors'] as $contributor) {
            if ($contributor['convertibleTimes'] === 0) {
                continue;
            }
            $idField = $contributor['kind'] === 'reservation' ? 'reservationId' : 'serviceOrderId';
            $versionField = $contributor['kind'] === 'reservation'
                ? 'reservationVersion'
                : 'serviceOrderVersion';
            if ($source['type'] !== $contributor['kind']
                || $source[$idField] !== $contributor['id']
                || $source[$versionField] !== $contributor['version']) {
                throw self::failure('occupation_not_convertible_by_current_source', [
                    'lineId' => $line['lineId'],
                    'kind' => $contributor['kind'],
                    'id' => $contributor['id'],
                ]);
            }
        }
        if ($source['type'] === 'direct' && $line['currentSourceConvertibleTimes'] !== 0) {
            throw self::failure('occupation_convertible_times_without_service_context', ['lineId' => $line['lineId']]);
        }
    }

    private static function assertSharedResourceSnapshots(array $lines): void
    {
        $seen = [];
        foreach ($lines as $line) {
            self::assertSharedResourceSnapshot(
                $seen,
                'performance_rule',
                (string)$line['projectId'],
                $line['performance']['ruleVersion'],
                $line['performance']
            );
            if ($line['inventory']['inventoryManaged'] === false) {
                continue;
            }
            self::assertSharedResourceSnapshot(
                $seen,
                'inventory_policy',
                (string)$line['projectId'],
                $line['inventory']['policyVersion'],
                [
                    'merchantDefaultPolicy' => $line['inventory']['merchantDefaultPolicy'],
                    'merchantDefaultPolicyVersion' => $line['inventory']['merchantDefaultPolicyVersion'],
                    'productPolicyOverride' => $line['inventory']['productPolicyOverride'],
                    'productPolicyVersion' => $line['inventory']['productPolicyVersion'],
                    'policy' => $line['inventory']['policy'],
                    'policyVersionSemantics' => $line['inventory']['policyVersionSemantics'],
                    'policyResolutionFingerprint' => $line['inventory']['policyResolutionFingerprint'],
                ]
            );
            $recipeConsumables = array_values(array_map(static function (array $consumable): array {
                return [
                    'stockId' => $consumable['stockId'],
                    'quantityUnitsPerService' => $consumable['quantityUnitsPerService'],
                    'shortageEstimatedUnitCostCents' => $consumable['shortageEstimatedUnitCostCents'],
                ];
            }, $line['inventory']['consumables']));
            self::assertSharedResourceSnapshot(
                $seen,
                'inventory_recipe',
                (string)$line['inventory']['recipeId'],
                $line['inventory']['recipeVersion'],
                [
                    'projectId' => $line['projectId'],
                    'recipeFormulaHash' => $line['inventory']['recipeFormulaHash'],
                    'consumables' => $recipeConsumables,
                ]
            );
        }
    }

    private static function assertSharedResourceSnapshot(
        array &$seen,
        string $kind,
        string $id,
        int $version,
        array $payload
    ): void {
        $key = $kind . "\0" . $id;
        $snapshot = ['version' => $version, 'payload' => $payload];
        if (isset($seen[$key]) && $seen[$key] !== $snapshot) {
            throw self::failure('shared_resource_snapshot_inconsistent', [
                'kind' => $kind,
                'id' => $id,
            ]);
        }
        $seen[$key] = $snapshot;
    }

    private static function normalizeAuthorityCraftsmen($craftsmen, string $lineId): array
    {
        if (!is_array($craftsmen) || !self::isList($craftsmen) || count($craftsmen) < 1 || count($craftsmen) > 20) {
            throw self::failure('authority_craftsmen_invalid', ['lineId' => $lineId]);
        }
        $result = [];
        $seen = [];
        foreach ($craftsmen as $row) {
            if (!is_array($row)) {
                throw self::failure('authority_craftsman_shape_invalid', ['lineId' => $lineId]);
            }
            $baseKeys = [
                'staffId',
                'staffVersion',
                'staffName',
                'storeId',
                'active',
                'craftsmanEligible',
                'sequence',
                'isPrimary',
                'laborWeight',
            ];
            $optionalKeys = ['craftsmanPerformanceType', 'laborFeeCents', 'personnelSource'];
            $expectedKeys = array_merge($baseKeys, array_values(array_intersect($optionalKeys, array_keys($row))));
            self::assertExactKeys($row, $expectedKeys, 'craftsman');
            self::assertPositiveInt($row['staffId'], 'craftsman.staffId');
            self::assertPositiveInt($row['staffVersion'], 'craftsman.staffVersion');
            self::assertNonemptyString($row['staffName'], 'craftsman.staffName', 100);
            self::assertPositiveInt($row['storeId'], 'craftsman.storeId');
            self::assertPositiveInt($row['sequence'], 'craftsman.sequence', 20);
            self::assertNonnegativeInt($row['laborWeight'], 'craftsman.laborWeight', self::MAX_WEIGHT);
            $performanceType = (string)($row['craftsmanPerformanceType'] ?? 'commission_labor');
            if (!in_array($performanceType, ['commission', 'labor', 'commission_labor'], true)
                || ($performanceType !== 'labor' && $row['laborWeight'] <= 0)) {
                throw self::failure('authority_craftsman_performance_invalid', ['staffId' => $row['staffId']]);
            }
            self::assertNonnegativeInt(
                $row['laborFeeCents'] ?? 0,
                'craftsman.laborFeeCents',
                self::MAX_MONEY_CENTS
            );
            $laborFeeCents = (int)($row['laborFeeCents'] ?? 0);
            if ($performanceType === 'commission' && $laborFeeCents !== 0) {
                throw self::failure('authority_craftsman_labor_fee_invalid', ['staffId' => $row['staffId']]);
            }
            if (!is_bool($row['active']) || !is_bool($row['craftsmanEligible']) || !is_bool($row['isPrimary'])) {
                throw self::failure('authority_craftsman_flags_invalid', ['staffId' => $row['staffId']]);
            }
            if (isset($seen[$row['staffId']])) {
                throw self::failure('authority_craftsman_duplicate', ['staffId' => $row['staffId']]);
            }
            $seen[$row['staffId']] = true;
            $row['craftsmanPerformanceType'] = $performanceType;
            $row['laborFeeCents'] = $laborFeeCents;
            $result[] = $row;
        }
        usort($result, static function (array $left, array $right): int {
            $sequence = $left['sequence'] <=> $right['sequence'];
            return $sequence !== 0 ? $sequence : ($left['staffId'] <=> $right['staffId']);
        });
        foreach ($result as $index => $row) {
            if ($row['sequence'] !== $index + 1 || $row['isPrimary'] !== ($index === 0)) {
                throw self::failure('authority_craftsman_order_invalid', [
                    'lineId' => $lineId,
                    'staffId' => $row['staffId'],
                ]);
            }
        }
        return $result;
    }

    private static function normalizePerformance($performance, string $lineId): array
    {
        if (!is_array($performance)) {
            throw self::failure('performance_snapshot_invalid', ['lineId' => $lineId]);
        }
        self::assertExactKeys($performance, [
            'ruleVersion',
            'consumptionMode',
            'consumptionConfiguredUnitAmount',
            'laborMode',
            'laborConfiguredUnitAmount',
        ], 'performance');
        self::assertPositiveInt($performance['ruleVersion'], 'performance.ruleVersion');
        foreach (['consumptionMode', 'laborMode'] as $key) {
            if (!in_array($performance[$key], [self::PERFORMANCE_ACTUAL, self::PERFORMANCE_CONFIGURED], true)) {
                throw self::failure('performance_mode_invalid', ['lineId' => $lineId, 'field' => $key]);
            }
        }
        $performance['consumptionConfiguredUnitAmountCents'] = self::moneyToCents(
            $performance['consumptionConfiguredUnitAmount'],
            'consumptionConfiguredUnitAmount'
        );
        $performance['laborConfiguredUnitAmountCents'] = self::moneyToCents(
            $performance['laborConfiguredUnitAmount'],
            'laborConfiguredUnitAmount'
        );
        return $performance;
    }

    private static function normalizeLineInventory($inventory, string $lineId): array
    {
        if (!is_array($inventory)) {
            throw self::failure('line_inventory_invalid', ['lineId' => $lineId]);
        }
        if (($inventory['inventoryManaged'] ?? null) === false && count($inventory) === 1) {
            return ['inventoryManaged' => false, 'consumables' => []];
        }
        if (!array_key_exists('inventoryManaged', $inventory)) {
            $inventory['inventoryManaged'] = true;
        }
        self::assertExactKeys(
            $inventory,
            [
                'inventoryManaged',
                'merchantDefaultPolicy',
                'merchantDefaultPolicyVersion',
                'productPolicyOverride',
                'productPolicyVersion',
                'policy',
                'policyVersion',
                'recipeId',
                'recipeVersion',
                'recipeFormulaHash',
                'consumables',
            ],
            'inventory'
        );
        if ($inventory['inventoryManaged'] !== true) {
            throw self::failure('inventory_managed_flag_invalid', ['lineId' => $lineId]);
        }
        if (!in_array(
            $inventory['merchantDefaultPolicy'],
            [self::INVENTORY_DENY_SHORTAGE, self::INVENTORY_ALLOW_SHORTAGE],
            true
        )) {
            throw self::failure('merchant_inventory_policy_invalid', ['lineId' => $lineId]);
        }
        if (!in_array(
            $inventory['productPolicyOverride'],
            [self::INVENTORY_POLICY_INHERIT, self::INVENTORY_DENY_SHORTAGE, self::INVENTORY_ALLOW_SHORTAGE],
            true
        )) {
            throw self::failure('product_inventory_policy_invalid', ['lineId' => $lineId]);
        }
        if (!in_array($inventory['policy'], [self::INVENTORY_DENY_SHORTAGE, self::INVENTORY_ALLOW_SHORTAGE], true)) {
            throw self::failure('inventory_policy_not_resolved', ['lineId' => $lineId]);
        }
        $expectedPolicy = $inventory['productPolicyOverride'] === self::INVENTORY_POLICY_INHERIT
            ? $inventory['merchantDefaultPolicy']
            : $inventory['productPolicyOverride'];
        if ($inventory['policy'] !== $expectedPolicy) {
            throw self::failure('inventory_policy_resolution_mismatch', ['lineId' => $lineId]);
        }
        self::assertPositiveInt($inventory['merchantDefaultPolicyVersion'], 'inventory.merchantDefaultPolicyVersion');
        self::assertPositiveInt($inventory['productPolicyVersion'], 'inventory.productPolicyVersion');
        self::assertPositiveInt($inventory['policyVersion'], 'inventory.policyVersion');
        self::assertPositiveInt($inventory['recipeId'], 'inventory.recipeId');
        self::assertPositiveInt($inventory['recipeVersion'], 'inventory.recipeVersion');
        self::assertToken($inventory['recipeFormulaHash'], 'inventory.recipeFormulaHash', 128);
        $inventory['policyVersionSemantics'] = 'merchant-default-plus-product-override-v1';
        $inventory['policyResolutionFingerprint'] = hash('sha256', json_encode([
            'semanticVersion' => $inventory['policyVersionSemantics'],
            'merchantDefaultPolicy' => $inventory['merchantDefaultPolicy'],
            'merchantDefaultPolicyVersion' => $inventory['merchantDefaultPolicyVersion'],
            'productPolicyOverride' => $inventory['productPolicyOverride'],
            'productPolicyVersion' => $inventory['productPolicyVersion'],
            'resolvedPolicy' => $inventory['policy'],
            'resolvedPolicyVersion' => $inventory['policyVersion'],
        ], JSON_UNESCAPED_SLASHES));
        if (!is_array($inventory['consumables']) || !self::isList($inventory['consumables'])
            || count($inventory['consumables']) > 100) {
            throw self::failure('line_consumables_invalid', ['lineId' => $lineId]);
        }
        $seen = [];
        foreach ($inventory['consumables'] as $index => $consumable) {
            if (!is_array($consumable)) {
                throw self::failure('line_consumable_shape_invalid', ['lineId' => $lineId, 'index' => $index]);
            }
            self::assertExactKeys(
                $consumable,
                [
                    'stockId',
                    'quantityUnitsPerService',
                    'shortageEstimatedUnitCostCents',
                    'shortageCostAllocatedQuantityUnitsBefore',
                    'shortageCursorId',
                    'shortageCursorVersion',
                ],
                'inventory.consumables'
            );
            self::assertToken($consumable['stockId'], 'stockId', 128);
            self::assertPositiveInt($consumable['quantityUnitsPerService'], 'quantityUnitsPerService', self::MAX_STOCK_UNITS);
            self::assertNonnegativeInt(
                $consumable['shortageEstimatedUnitCostCents'],
                'shortageEstimatedUnitCostCents',
                self::MAX_MONEY_CENTS
            );
            self::assertNonnegativeInt(
                $consumable['shortageCostAllocatedQuantityUnitsBefore'],
                'shortageCostAllocatedQuantityUnitsBefore',
                self::MAX_STOCK_UNITS
            );
            self::assertToken($consumable['shortageCursorId'], 'shortageCursorId', 128);
            self::assertPositiveInt($consumable['shortageCursorVersion'], 'shortageCursorVersion');
            $expectedCursorId = self::inventoryShortageCursorId(
                $consumable['stockId'],
                $inventory['recipeId'],
                $consumable['shortageEstimatedUnitCostCents']
            );
            if ($consumable['shortageCursorId'] !== $expectedCursorId) {
                throw self::failure('inventory_shortage_cursor_identity_invalid', [
                    'lineId' => $lineId,
                    'stockId' => $consumable['stockId'],
                ]);
            }
            if (isset($seen[$consumable['stockId']])) {
                throw self::failure('line_consumable_duplicate', ['lineId' => $lineId, 'stockId' => $consumable['stockId']]);
            }
            $seen[$consumable['stockId']] = true;
        }
        usort($inventory['consumables'], static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResourceIds(
                $left['stockId'],
                $right['stockId']
            );
        });
        return $inventory;
    }

    private static function normalizeInventoryStocks($stocks): array
    {
        if (!is_array($stocks) || !self::isList($stocks) || count($stocks) > 1000) {
            throw self::failure('inventory_stocks_invalid');
        }
        $result = [];
        $seenStock = [];
        foreach ($stocks as $index => $stock) {
            if (!is_array($stock)) {
                throw self::failure('inventory_stock_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys(
                $stock,
                ['stockId', 'stockVersion', 'consumableId', 'skuId', 'stockUnitScale', 'batches'],
                'inventoryStocks'
            );
            self::assertToken($stock['stockId'], 'inventoryStock.stockId', 128);
            self::assertPositiveInt($stock['stockVersion'], 'inventoryStock.stockVersion');
            self::assertPositiveInt($stock['consumableId'], 'inventoryStock.consumableId');
            self::assertPositiveInt($stock['skuId'], 'inventoryStock.skuId');
            self::assertNonnegativeInt($stock['stockUnitScale'], 'inventoryStock.stockUnitScale', 4);
            if (isset($seenStock[$stock['stockId']])) {
                throw self::failure('inventory_stock_duplicate', ['stockId' => $stock['stockId']]);
            }
            $seenStock[$stock['stockId']] = true;
            if (!is_array($stock['batches']) || !self::isList($stock['batches'])
                || count($stock['batches']) > self::MAX_BATCHES_PER_STOCK) {
                throw self::failure('inventory_batch_budget_exceeded', [
                    'stockId' => $stock['stockId'],
                    'count' => is_array($stock['batches']) ? count($stock['batches']) : -1,
                ]);
            }
            $batches = [];
            $seenBatch = [];
            foreach ($stock['batches'] as $batchIndex => $batch) {
                if (!is_array($batch)) {
                    throw self::failure('inventory_batch_shape_invalid', ['stockId' => $stock['stockId'], 'index' => $batchIndex]);
                }
                self::assertExactKeys(
                    $batch,
                    [
                        'batchId',
                        'batchVersion',
                        'availableQuantityUnits',
                        'unitCostCents',
                        'costAllocatedQuantityUnitsBefore',
                        'allocationOrder',
                    ],
                    'inventoryBatch'
                );
                self::assertPositiveInt($batch['batchId'], 'inventoryBatch.batchId');
                self::assertPositiveInt($batch['batchVersion'], 'inventoryBatch.batchVersion');
                self::assertPositiveInt($batch['availableQuantityUnits'], 'inventoryBatch.availableQuantityUnits', self::MAX_STOCK_UNITS);
                self::assertNonnegativeInt($batch['unitCostCents'], 'inventoryBatch.unitCostCents', self::MAX_MONEY_CENTS);
                self::assertNonnegativeInt(
                    $batch['costAllocatedQuantityUnitsBefore'],
                    'inventoryBatch.costAllocatedQuantityUnitsBefore',
                    self::MAX_STOCK_UNITS
                );
                if ($batch['availableQuantityUnits']
                    > self::MAX_STOCK_UNITS - $batch['costAllocatedQuantityUnitsBefore']) {
                    throw self::failure('inventory_cost_cursor_overflow', [
                        'stockId' => $stock['stockId'],
                        'batchId' => $batch['batchId'],
                    ]);
                }
                self::assertNonnegativeInt($batch['allocationOrder'], 'inventoryBatch.allocationOrder', self::MAX_TIMES);
                if (isset($seenBatch[$batch['batchId']])) {
                    throw self::failure('inventory_batch_duplicate', ['stockId' => $stock['stockId'], 'batchId' => $batch['batchId']]);
                }
                $seenBatch[$batch['batchId']] = true;
                $batches[] = $batch;
            }
            usort($batches, static function (array $left, array $right): int {
                $order = $left['allocationOrder'] <=> $right['allocationOrder'];
                return $order !== 0 ? $order : ($left['batchId'] <=> $right['batchId']);
            });
            $stock['batches'] = $batches;
            $result[$stock['stockId']] = $stock;
        }
        uksort($result, static function ($left, $right): int {
            return CashierV3ResourceKindCatalog::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        return $result;
    }

    private static function assertCommandMatchesSnapshot(array $intent, array $snapshot): array
    {
        if ($intent['workspaceId'] !== $snapshot['workspaceId']
            || $intent['stateContextId'] !== $snapshot['stateContextId']) {
            throw self::failure('workspace_snapshot_mismatch');
        }
        if (!hash_equals(
            $snapshot['permissionSnapshotFingerprint'],
            $intent['permissionSnapshotFingerprint']
        )) {
            throw self::failure('permission_snapshot_changed');
        }
        if ($intent['memberId'] !== $snapshot['memberId']) {
            throw self::failure('member_snapshot_mismatch');
        }
        if (count($intent['lines']) !== count($snapshot['lines'])) {
            throw self::failure('workspace_line_set_changed');
        }
        $intentById = [];
        foreach ($intent['lines'] as $line) {
            $intentById[$line['lineId']] = $line;
        }
        $ordered = [];
        foreach ($snapshot['lines'] as $line) {
            if (!isset($intentById[$line['lineId']])) {
                throw self::failure('workspace_line_set_changed', ['lineId' => $line['lineId']]);
            }
            $ordered[] = $intentById[$line['lineId']];
        }
        $intent['lines'] = $ordered;
        return $intent;
    }

    private static function sourceGroups(array $intentLines, array $snapshotByLine): array
    {
        $groups = [];
        foreach ($intentLines as $intent) {
            $authority = $snapshotByLine[$intent['lineId']];
            $key = $authority['entitlementInstanceType'] . ':'
                . $authority['entitlementInstanceId'] . ':'
                . $authority['sourceDetailId'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'sourceKey' => $key,
                    'entitlementInstanceType' => $authority['entitlementInstanceType'],
                    'entitlementInstanceId' => $authority['entitlementInstanceId'],
                    'sourceKind' => $authority['sourceKind'],
                    'isGift' => $authority['isGift'],
                    'giftSourceType' => $authority['giftSourceType'],
                    'giftId' => $authority['giftId'],
                    'giftVersion' => $authority['giftVersion'],
                    'holderId' => $authority['holderId'],
                    'originOrderId' => $authority['originOrderId'],
                    'sourceNameSnapshot' => $authority['sourceNameSnapshot'],
                    'sourceCodeSnapshot' => $authority['sourceCodeSnapshot'],
                    'sourceDetailId' => $authority['sourceDetailId'],
                    'projectId' => $authority['projectId'],
                    'projectNameSnapshot' => $authority['projectNameSnapshot'],
                    'projectCategoryIdSnapshot' => $authority['projectCategoryIdSnapshot'],
                    'projectCategoryNameSnapshot' => $authority['projectCategoryNameSnapshot'],
                    'sourceVersion' => $authority['sourceVersion'],
                    'detailVersion' => $authority['detailVersion'],
                    'physicalRemainingTimes' => $authority['physicalRemainingTimes'],
                    'debtLimitedUsableTimes' => $authority['debtLimitedUsableTimes'],
                    'debtGuardId' => $authority['debtGuardId'],
                    'debtGuardVersion' => $authority['debtGuardVersion'],
                    'occupiedTimes' => $authority['occupiedTimes'],
                    'currentSourceConvertibleTimes' => $authority['currentSourceConvertibleTimes'],
                    'occupationContributors' => $authority['occupationContributors'],
                    'usableTimesForCommand' => $authority['usableTimesForCommand'],
                    'purchaseAmountCents' => $authority['purchaseAmountCents'],
                    'totalPurchaseTimes' => $authority['totalPurchaseTimes'],
                    'amountCalculationVersion' => $authority['amountCalculationVersion'],
                    'consumedTimesAtLock' => $authority['consumedTimesAtLock'],
                    'allowedStoreIds' => $authority['allowedStoreIds'],
                    'performance' => $authority['performance'],
                    'inventory' => $authority['inventory'],
                    'lines' => [],
                    'quantity' => 0,
                ];
            } else {
                self::assertSameSourceSnapshot($groups[$key], $authority, $key);
            }
            $groups[$key]['lines'][] = [
                'lineId' => $intent['lineId'],
                'sortNo' => $authority['sortNo'],
                'quantity' => $intent['quantity'],
            ];
            $groups[$key]['quantity'] += $intent['quantity'];
            if ($groups[$key]['quantity'] > $groups[$key]['usableTimesForCommand']) {
                throw self::failure('entitlement_quantity_exceeds_available', [
                    'sourceKey' => $key,
                    'requested' => $groups[$key]['quantity'],
                    'debtLimitedUsable' => $groups[$key]['debtLimitedUsableTimes'],
                    'occupied' => $groups[$key]['occupiedTimes'],
                    'currentSourceConvertible' => $groups[$key]['currentSourceConvertibleTimes'],
                ]);
            }
        }
        return $groups;
    }

    private static function assertSameSourceSnapshot(array $group, array $line, string $key): void
    {
        $fields = [
            'entitlementInstanceType',
            'entitlementInstanceId',
            'sourceKind',
            'isGift',
            'giftSourceType',
            'giftId',
            'giftVersion',
            'holderId',
            'originOrderId',
            'sourceNameSnapshot',
            'sourceCodeSnapshot',
            'sourceDetailId',
            'projectId',
            'projectNameSnapshot',
            'projectCategoryIdSnapshot',
            'projectCategoryNameSnapshot',
            'sourceVersion',
            'detailVersion',
            'physicalRemainingTimes',
            'debtLimitedUsableTimes',
            'debtGuardId',
            'debtGuardVersion',
            'occupiedTimes',
            'currentSourceConvertibleTimes',
            'occupationContributors',
            'usableTimesForCommand',
            'purchaseAmountCents',
            'totalPurchaseTimes',
            'amountCalculationVersion',
            'consumedTimesAtLock',
            'allowedStoreIds',
            'performance',
            'inventory',
        ];
        foreach ($fields as $field) {
            if ($group[$field] !== $line[$field]) {
                throw self::failure('entitlement_source_snapshot_inconsistent', ['sourceKey' => $key, 'field' => $field]);
            }
        }
    }

    private static function actualAmountsByLine(array $groups): array
    {
        $amounts = [];
        foreach ($groups as $group) {
            $consumedCursor = $group['consumedTimesAtLock'];
            foreach ($group['lines'] as $line) {
                $amounts[$line['lineId']] = self::allocateActualAmountCents(
                    $group['purchaseAmountCents'],
                    $group['totalPurchaseTimes'],
                    $consumedCursor,
                    $line['quantity'],
                    $group['amountCalculationVersion']
                );
                $consumedCursor += $line['quantity'];
            }
        }
        return $amounts;
    }

    private static function deductionPlans(array $groups): array
    {
        $plans = [];
        foreach ($groups as $group) {
            $plans[] = [
                'sourceKey' => $group['sourceKey'],
                'entitlementInstanceType' => $group['entitlementInstanceType'],
                'entitlementInstanceId' => $group['entitlementInstanceId'],
                'sourceKind' => $group['sourceKind'],
                'isGift' => $group['isGift'],
                'giftSourceType' => $group['giftSourceType'],
                'giftId' => $group['giftId'],
                'giftVersion' => $group['giftVersion'],
                'holderId' => $group['holderId'],
                'originOrderId' => $group['originOrderId'],
                'sourceNameSnapshot' => $group['sourceNameSnapshot'],
                'sourceCodeSnapshot' => $group['sourceCodeSnapshot'],
                'sourceDetailId' => $group['sourceDetailId'],
                'projectId' => $group['projectId'],
                'projectNameSnapshot' => $group['projectNameSnapshot'],
                'projectCategoryIdSnapshot' => $group['projectCategoryIdSnapshot'],
                'projectCategoryNameSnapshot' => $group['projectCategoryNameSnapshot'],
                'sourceVersion' => $group['sourceVersion'],
                'detailVersion' => $group['detailVersion'],
                'debtGuardId' => $group['debtGuardId'],
                'debtGuardVersion' => $group['debtGuardVersion'],
                'expectedPhysicalRemainingTimes' => $group['physicalRemainingTimes'],
                'purchaseAmountCents' => $group['purchaseAmountCents'],
                'totalPurchaseTimes' => $group['totalPurchaseTimes'],
                'consumedTimesAtLock' => $group['consumedTimesAtLock'],
                'amountCalculationVersion' => $group['amountCalculationVersion'],
                'deductPhysicalTimes' => $group['quantity'],
                'convertCurrentSourceOccupiedTimes' => min(
                    $group['quantity'],
                    $group['currentSourceConvertibleTimes']
                ),
                'lineIds' => array_values(array_map(static function (array $line): string {
                    return $line['lineId'];
                }, $group['lines'])),
            ];
        }
        usort($plans, static function (array $left, array $right): int {
            return strcmp($left['sourceKey'], $right['sourceKey']);
        });
        return $plans;
    }

    private static function performancePlan(array $snapshot, int $actualAmountCents, int $quantity): array
    {
        $consumption = $snapshot['consumptionMode'] === self::PERFORMANCE_ACTUAL
            ? $actualAmountCents
            : self::multiplyMoney($snapshot['consumptionConfiguredUnitAmountCents'], $quantity, 'consumptionPerformance');
        $labor = $snapshot['laborMode'] === self::PERFORMANCE_ACTUAL
            ? $actualAmountCents
            : self::multiplyMoney($snapshot['laborConfiguredUnitAmountCents'], $quantity, 'laborPerformance');
        return [
            'consumptionMode' => $snapshot['consumptionMode'],
            'consumptionAmountCents' => $consumption,
            'laborMode' => $snapshot['laborMode'],
            'laborAmountCents' => $labor,
        ];
    }

    private static function craftsmanPlan(array $staffIds, array $authorityRows, int $storeId, int $amountCents): array
    {
        $byId = [];
        $authorityIds = [];
        foreach ($authorityRows as $row) {
            $byId[$row['staffId']] = $row;
            $authorityIds[] = $row['staffId'];
        }
        $requestedSet = $staffIds;
        $authoritySet = $authorityIds;
        sort($requestedSet, SORT_NUMERIC);
        sort($authoritySet, SORT_NUMERIC);
        if ($requestedSet !== $authoritySet) {
            throw self::failure('authority_craftsman_scope_mismatch');
        }
        $weights = [];
        $performanceStaffIds = [];
        foreach ($authorityIds as $staffId) {
            $row = $byId[$staffId] ?? null;
            if (!$row || $row['active'] !== true || $row['storeId'] !== $storeId) {
                throw self::failure('craftsman_not_active_eligible_in_store', ['staffId' => $staffId]);
            }
            if (($row['craftsmanPerformanceType'] ?? 'commission_labor') !== 'labor') {
                $performanceStaffIds[] = $staffId;
                $weights[$staffId] = $row['laborWeight'];
            }
        }
        $amountByStaffId = [];
        if ($performanceStaffIds !== []) {
            foreach (self::allocateLaborAmount($amountCents, $performanceStaffIds, $weights) as $allocation) {
                $amountByStaffId[(int)$allocation['staffId']] = (int)$allocation['amountCents'];
            }
        }
        $allocations = [];
        foreach ($authorityRows as $index => $row) {
            $staffId = (int)$row['staffId'];
            $allocation = [
                'staffId' => $staffId,
                'isPrimary' => $index === 0,
                'sequence' => $index + 1,
                'amountCents' => (int)($amountByStaffId[$staffId] ?? 0),
            ];
            $allocation['staffVersion'] = $row['staffVersion'];
            $allocation['staffName'] = $row['staffName'];
            $allocation['storeId'] = $row['storeId'];
            $allocation['laborWeight'] = $row['laborWeight'];
            $allocation['craftsmanPerformanceType'] = $row['craftsmanPerformanceType'] ?? 'commission_labor';
            $allocation['laborFeeCents'] = (int)($row['laborFeeCents'] ?? 0);
            if (isset($row['personnelSource'])) {
                $allocation['personnelSource'] = $row['personnelSource'];
            }
            $allocations[] = $allocation;
        }
        return $allocations;
    }

    private static function inventoryPlan(array $intentLines, array $snapshotByLine, array $stocksById): array
    {
        $requestsByStock = [];
        $lineResult = [];
        foreach ($intentLines as $intentLine) {
            $lineId = $intentLine['lineId'];
            $authorityLine = $snapshotByLine[$lineId];
            $inventory = $authorityLine['inventory'];
            if ($inventory['inventoryManaged'] === false) {
                $lineResult[$lineId] = ['inventoryManaged' => false, 'costComplete' => true, 'actualCostCents' => 0, 'estimatedShortageCostCents' => 0, 'consumables' => []];
                continue;
            }
            $lineResult[$lineId] = [
                'inventoryManaged' => true,
                'merchantDefaultPolicy' => $inventory['merchantDefaultPolicy'],
                'merchantDefaultPolicyVersion' => $inventory['merchantDefaultPolicyVersion'],
                'productPolicyOverride' => $inventory['productPolicyOverride'],
                'productPolicyVersion' => $inventory['productPolicyVersion'],
                'policy' => $inventory['policy'],
                'policyVersion' => $inventory['policyVersion'],
                'policyVersionSemantics' => $inventory['policyVersionSemantics'],
                'policyResolutionFingerprint' => $inventory['policyResolutionFingerprint'],
                'recipeId' => $inventory['recipeId'],
                'recipeVersion' => $inventory['recipeVersion'],
                'recipeFormulaHash' => $inventory['recipeFormulaHash'],
                'costComplete' => true,
                'actualCostCents' => 0,
                'estimatedShortageCostCents' => 0,
                'consumables' => [],
            ];
            foreach ($inventory['consumables'] as $consumableIndex => $consumable) {
                $stockId = $consumable['stockId'];
                if (!isset($stocksById[$stockId])) {
                    throw self::failure('inventory_stock_snapshot_missing', ['lineId' => $lineId, 'stockId' => $stockId]);
                }
                if ($intentLine['quantity'] > intdiv(self::MAX_STOCK_UNITS, $consumable['quantityUnitsPerService'])) {
                    throw self::failure('inventory_required_quantity_overflow', ['lineId' => $lineId, 'stockId' => $stockId]);
                }
                $required = $intentLine['quantity'] * $consumable['quantityUnitsPerService'];
                $requestsByStock[$stockId][] = [
                    'lineId' => $lineId,
                    'sortNo' => $authorityLine['sortNo'],
                    'consumablePosition' => $consumableIndex,
                    'policy' => $inventory['policy'],
                    'recipeId' => $inventory['recipeId'],
                    'quantityUnitsPerService' => $consumable['quantityUnitsPerService'],
                    'requiredQuantityUnits' => $required,
                    'shortageEstimatedUnitCostCents' => $consumable['shortageEstimatedUnitCostCents'],
                    'shortageCostAllocatedQuantityUnitsBefore' => $consumable['shortageCostAllocatedQuantityUnitsBefore'],
                    'shortageCursorId' => $consumable['shortageCursorId'],
                    'shortageCursorVersion' => $consumable['shortageCursorVersion'],
                ];
            }
        }

        $actualCostTotal = 0;
        $estimatedShortageTotal = 0;
        $allComplete = true;
        uksort($requestsByStock, static function ($left, $right): int {
            return CashierV3ResourceKindCatalog::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        foreach ($requestsByStock as $stockId => $requests) {
            $stock = $stocksById[$stockId];
            usort($requests, static function (array $left, array $right): int {
                $leftPriority = $left['policy'] === self::INVENTORY_DENY_SHORTAGE ? 0 : 1;
                $rightPriority = $right['policy'] === self::INVENTORY_DENY_SHORTAGE ? 0 : 1;
                $policy = $leftPriority <=> $rightPriority;
                if ($policy !== 0) {
                    return $policy;
                }
                $sort = $left['sortNo'] <=> $right['sortNo'];
                if ($sort !== 0) {
                    return $sort;
                }
                $line = strcmp($left['lineId'], $right['lineId']);
                return $line !== 0 ? $line : ($left['consumablePosition'] <=> $right['consumablePosition']);
            });
            $batchState = [];
            $available = 0;
            foreach ($stock['batches'] as $batch) {
                $batchState[] = [
                    'batchId' => $batch['batchId'],
                    'batchVersion' => $batch['batchVersion'],
                    'remainingQuantityUnits' => $batch['availableQuantityUnits'],
                    'unitCostCents' => $batch['unitCostCents'],
                    'costAllocatedQuantityUnitsCursor' => $batch['costAllocatedQuantityUnitsBefore'],
                ];
                $available += $batch['availableQuantityUnits'];
            }
            $strictRequired = 0;
            foreach ($requests as $request) {
                if ($request['policy'] === self::INVENTORY_DENY_SHORTAGE) {
                    $strictRequired += $request['requiredQuantityUnits'];
                }
            }
            if ($strictRequired > $available) {
                throw self::failure('inventory_shortage_denied', [
                    'stockId' => $stockId,
                    'requiredQuantityUnits' => $strictRequired,
                    'availableQuantityUnits' => $available,
                ]);
            }

            $shortageCostCursors = [];
            foreach ($requests as $request) {
                $remaining = $request['requiredQuantityUnits'];
                $allocations = [];
                $actualCost = 0;
                foreach ($batchState as &$batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    if ($batch['remainingQuantityUnits'] <= 0) {
                        continue;
                    }
                    $take = min($remaining, $batch['remainingQuantityUnits']);
                    $batch['remainingQuantityUnits'] -= $take;
                    $remaining -= $take;
                    $costCursorBefore = $batch['costAllocatedQuantityUnitsCursor'];
                    $cost = self::scaledCostDeltaCents(
                        $costCursorBefore,
                        $take,
                        $batch['unitCostCents'],
                        $stock['stockUnitScale']
                    );
                    $batch['costAllocatedQuantityUnitsCursor'] += $take;
                    $actualCost += $cost;
                    $allocations[] = [
                        'batchId' => $batch['batchId'],
                        'batchVersion' => $batch['batchVersion'],
                        'quantityUnits' => $take,
                        'unitCostCents' => $batch['unitCostCents'],
                        'costAllocatedQuantityUnitsBefore' => $costCursorBefore,
                        'costAllocatedQuantityUnitsAfter' => $batch['costAllocatedQuantityUnitsCursor'],
                        'actualCostCents' => $cost,
                    ];
                }
                unset($batch);
                if ($remaining > 0 && $request['policy'] === self::INVENTORY_DENY_SHORTAGE) {
                    throw self::failure('inventory_shortage_denied', ['stockId' => $stockId]);
                }
                $shortageCostKey = $request['recipeId'] . "\0" . $request['shortageEstimatedUnitCostCents'];
                if (!array_key_exists($shortageCostKey, $shortageCostCursors)) {
                    $shortageCostCursors[$shortageCostKey] = [
                        'initial' => $request['shortageCostAllocatedQuantityUnitsBefore'],
                        'current' => $request['shortageCostAllocatedQuantityUnitsBefore'],
                        'id' => $request['shortageCursorId'],
                        'version' => $request['shortageCursorVersion'],
                    ];
                } elseif ($request['shortageCostAllocatedQuantityUnitsBefore']
                    !== $shortageCostCursors[$shortageCostKey]['initial']
                    || $request['shortageCursorId'] !== $shortageCostCursors[$shortageCostKey]['id']
                    || $request['shortageCursorVersion'] !== $shortageCostCursors[$shortageCostKey]['version']) {
                    throw self::failure('shortage_cost_cursor_inconsistent', [
                        'stockId' => $stockId,
                        'recipeId' => $request['recipeId'],
                    ]);
                }
                $shortageCostCursorBefore = $shortageCostCursors[$shortageCostKey]['current'];
                $estimatedShortage = self::scaledCostDeltaCents(
                    $shortageCostCursorBefore,
                    $remaining,
                    $request['shortageEstimatedUnitCostCents'],
                    $stock['stockUnitScale']
                );
                $shortageCostCursors[$shortageCostKey]['current'] = $shortageCostCursorBefore + $remaining;
                $complete = $remaining === 0;
                $entry = [
                    'stockId' => $stockId,
                    'stockVersion' => $stock['stockVersion'],
                    'consumableId' => $stock['consumableId'],
                    'skuId' => $stock['skuId'],
                    'stockUnitScale' => $stock['stockUnitScale'],
                    'quantityUnitsPerService' => $request['quantityUnitsPerService'],
                    'requiredQuantityUnits' => $request['requiredQuantityUnits'],
                    'actualQuantityUnits' => $request['requiredQuantityUnits'] - $remaining,
                    'shortageQuantityUnits' => $remaining,
                    'actualCostCents' => $actualCost,
                    'estimatedShortageCostCents' => $estimatedShortage,
                    'shortageEstimatedUnitCostCents' => $request['shortageEstimatedUnitCostCents'],
                    'shortageCostAllocatedQuantityUnitsBefore' => $shortageCostCursorBefore,
                    'shortageCostAllocatedQuantityUnitsAfter' => $shortageCostCursors[$shortageCostKey]['current'],
                    'shortageCursorId' => $request['shortageCursorId'],
                    'shortageCursorVersion' => $request['shortageCursorVersion'],
                    'batchAllocations' => $allocations,
                    'costComplete' => $complete,
                ];
                $lineId = $request['lineId'];
                $lineResult[$lineId]['consumables'][] = $entry;
                $lineResult[$lineId]['actualCostCents'] += $actualCost;
                $lineResult[$lineId]['estimatedShortageCostCents'] += $estimatedShortage;
                $lineResult[$lineId]['costComplete'] = $lineResult[$lineId]['costComplete'] && $complete;
                $actualCostTotal += $actualCost;
                $estimatedShortageTotal += $estimatedShortage;
                $allComplete = $allComplete && $complete;
            }
        }

        foreach ($stocksById as $stockId => $stock) {
            if (!isset($requestsByStock[$stockId])) {
                throw self::failure('unused_inventory_stock_snapshot', ['stockId' => $stockId]);
            }
        }
        return [
            'byLine' => $lineResult,
            'actualCostCents' => $actualCostTotal,
            'estimatedShortageCostCents' => $estimatedShortageTotal,
            'costComplete' => $allComplete,
        ];
    }

    private static function normalizeLockedResourcePlan(array $plan): array
    {
        if (!self::isList($plan) || !$plan || count($plan) > self::MAX_LOCKED_RESOURCES) {
            throw self::failure('locked_resource_plan_invalid');
        }
        $normalized = [];
        $seenPhysical = [];
        $seenRoles = [];
        $previous = null;
        foreach ($plan as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('locked_resource_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($row, ['kind', 'id', 'lockOrder', 'lockedVersion', 'roles'], 'lockedResourcePlan');
            self::assertToken($row['kind'], 'lockedResource.kind', 128);
            self::assertToken($row['id'], 'lockedResource.id', 128);
            self::assertPositiveInt($row['lockOrder'], 'lockedResource.lockOrder', self::MAX_TIMES);
            self::assertPositiveInt($row['lockedVersion'], 'lockedResource.lockedVersion');
            if (!CashierV3ResourceKindCatalog::isKnown($row['kind'])) {
                throw self::failure('locked_resource_kind_not_allowed', [
                    'index' => $index,
                    'kind' => $row['kind'],
                ]);
            }
            $canonicalLockOrder = CashierV3ResourceKindCatalog::lockOrderOf($row['kind']);
            if ($row['lockOrder'] !== $canonicalLockOrder) {
                throw self::failure('locked_resource_order_mismatch', [
                    'index' => $index,
                    'kind' => $row['kind'],
                    'expected' => $canonicalLockOrder,
                    'actual' => $row['lockOrder'],
                ]);
            }
            self::assertStringList($row['roles'], 'lockedResource.roles', 1000);
            if (!$row['roles']) {
                throw self::failure('locked_resource_roles_empty', ['index' => $index]);
            }
            sort($row['roles'], SORT_STRING);
            $physical = $row['kind'] . "\0" . $row['id'];
            if (isset($seenPhysical[$physical])) {
                throw self::failure('locked_physical_resource_duplicate', [
                    'kind' => $row['kind'],
                    'id' => $row['id'],
                ]);
            }
            $seenPhysical[$physical] = true;
            foreach ($row['roles'] as $role) {
                self::assertToken($role, 'lockedResource.role', 256);
                if (isset($seenRoles[$role])) {
                    throw self::failure('locked_resource_role_duplicate', ['role' => $role]);
                }
                $seenRoles[$role] = true;
            }
            if ($previous !== null) {
                if (CashierV3ResourceKindCatalog::compareResources(
                    $previous['kind'],
                    $previous['id'],
                    $row['kind'],
                    $row['id']
                ) > 0) {
                    throw self::failure('locked_resource_plan_not_monotonic', ['index' => $index]);
                }
            }
            $previous = $row;
            $normalized[] = $row;
        }
        return $normalized;
    }

    private static function assertLockedResourceCoverage(array $plan, array $snapshot): void
    {
        $required = self::requiredLockedResources($snapshot);
        $expectedPhysical = [];
        foreach ($required as $role => $expectation) {
            $physical = $expectation['kind'] . "\0" . $expectation['id'];
            if (!isset($expectedPhysical[$physical])) {
                $expectedPhysical[$physical] = [
                    'kind' => $expectation['kind'],
                    'id' => $expectation['id'],
                    'version' => $expectation['version'],
                    'roles' => [],
                ];
            } elseif ($expectedPhysical[$physical]['version'] !== $expectation['version']) {
                throw self::failure('locked_snapshot_physical_version_inconsistent', [
                    'kind' => $expectation['kind'],
                    'id' => $expectation['id'],
                ]);
            }
            $expectedPhysical[$physical]['roles'][] = $role;
        }
        foreach ($expectedPhysical as &$expected) {
            sort($expected['roles'], SORT_STRING);
        }
        unset($expected);

        $covered = [];
        foreach ($plan as $row) {
            $physical = $row['kind'] . "\0" . $row['id'];
            if (!isset($expectedPhysical[$physical])) {
                throw self::failure('locked_resource_coverage_extra_resource', [
                    'kind' => $row['kind'],
                    'id' => $row['id'],
                ]);
            }
            if ($row['lockedVersion'] !== $expectedPhysical[$physical]['version']) {
                throw self::failure('locked_resource_coverage_mismatch', [
                    'kind' => $row['kind'],
                    'id' => $row['id'],
                    'expectedVersion' => $expectedPhysical[$physical]['version'],
                    'actualVersion' => $row['lockedVersion'],
                ]);
            }
            if ($row['roles'] !== $expectedPhysical[$physical]['roles']) {
                $extraRoles = array_values(array_diff($row['roles'], $expectedPhysical[$physical]['roles']));
                if ($extraRoles) {
                    throw self::failure('locked_resource_coverage_extra_role', [
                        'kind' => $row['kind'],
                        'id' => $row['id'],
                        'roles' => $extraRoles,
                    ]);
                }
            }
            foreach ($row['roles'] as $role) {
                $covered[$role] = [
                    'id' => $row['id'],
                    'version' => $row['lockedVersion'],
                    'kind' => $row['kind'],
                ];
            }
        }
        foreach ($required as $role => $expectation) {
            if (!isset($covered[$role])) {
                throw self::failure('locked_resource_coverage_missing', ['role' => $role]);
            }
            if ($covered[$role]['id'] !== $expectation['id']
                || $covered[$role]['version'] !== $expectation['version']
                || $covered[$role]['kind'] !== $expectation['kind']) {
                throw self::failure('locked_resource_coverage_mismatch', [
                    'role' => $role,
                    'expected' => $expectation,
                    'actual' => $covered[$role],
                ]);
            }
        }
    }

    /** @return array<string,array{kind:string,id:string,version:int}> */
    private static function requiredLockedResources(array $snapshot): array
    {
        $required = [];
        self::addRequiredLock(
            $required,
            'member',
            'member',
            (string)$snapshot['memberId'],
            $snapshot['memberVersion']
        );
        if ($snapshot['workspaceLockRequired']) {
            self::addRequiredLock(
                $required,
                'workspace',
                'cashier_workspace',
                $snapshot['workspaceId'],
                $snapshot['workspaceVersion']
            );
        }
        if ($snapshot['source']['serviceOrderId'] > 0) {
            self::addRequiredLock(
                $required,
                'service_order',
                'service_order',
                (string)$snapshot['source']['serviceOrderId'],
                $snapshot['source']['serviceOrderVersion']
            );
        }
        if ($snapshot['source']['reservationId'] > 0) {
            self::addRequiredLock(
                $required,
                'reservation',
                'reservation',
                (string)$snapshot['source']['reservationId'],
                $snapshot['source']['reservationVersion']
            );
        }
        foreach ($snapshot['lines'] as $line) {
            self::addRequiredLock(
                $required,
                'benefit_pool:' . $line['sourceDetailId'],
                'member_benefit_pool',
                (string)$line['sourceDetailId'],
                $line['detailVersion']
            );
            self::addRequiredLock(
                $required,
                'card_holder:' . $line['holderId'],
                'card_holder',
                (string)$line['holderId'],
                $line['sourceVersion']
            );
            self::addRequiredLock(
                $required,
                'entitlement_debt_guard:' . $line['originOrderId'],
                'entitlement_debt_guard',
                $line['debtGuardId'],
                $line['debtGuardVersion']
            );
            self::addRequiredLock(
                $required,
                'performance_rule:' . $line['lineId'],
                'performance_rule',
                (string)$line['projectId'],
                $line['performance']['ruleVersion']
            );
            if ($line['inventory']['inventoryManaged'] === false) {
                continue;
            }
            self::addRequiredLock(
                $required,
                'inventory_policy:' . $line['lineId'],
                'inventory_policy',
                (string)$line['projectId'],
                $line['inventory']['policyVersion']
            );
            self::addRequiredLock(
                $required,
                'inventory_recipe:' . $line['lineId'],
                'inventory_recipe',
                (string)$line['inventory']['recipeId'],
                $line['inventory']['recipeVersion']
            );
            foreach ($line['inventory']['consumables'] as $consumable) {
                self::addRequiredLock(
                    $required,
                    'inventory_shortage_cursor:' . $line['lineId'] . ':' . $consumable['stockId'],
                    'inventory_shortage_cursor',
                    $consumable['shortageCursorId'],
                    $consumable['shortageCursorVersion']
                );
            }
            foreach ($line['craftsmen'] as $craftsman) {
                self::addRequiredLock(
                    $required,
                    'staff:' . $craftsman['staffId'],
                    'staff_profile',
                    (string)$craftsman['staffId'],
                    $craftsman['staffVersion']
                );
            }
            foreach ($line['occupationContributors'] as $contributor) {
                self::addRequiredLock(
                    $required,
                    'occupation:' . $line['lineId'] . ':' . $contributor['kind'] . ':' . $contributor['id'],
                    $contributor['kind'],
                    (string)$contributor['id'],
                    $contributor['version']
                );
            }
        }
        foreach ($snapshot['inventoryStocks'] as $stock) {
            self::addRequiredLock(
                $required,
                'inventory_stock:' . $stock['stockId'],
                'inventory_stock',
                $stock['stockId'],
                $stock['stockVersion']
            );
            foreach ($stock['batches'] as $batch) {
                self::addRequiredLock(
                    $required,
                    'inventory_batch:' . $stock['stockId'] . ':' . $batch['batchId'],
                    'inventory_batch',
                    (string)$batch['batchId'],
                    $batch['batchVersion']
                );
            }
        }
        return $required;
    }

    private static function addRequiredLock(
        array &$required,
        string $role,
        string $kind,
        string $id,
        int $version
    ): void
    {
        $expectation = ['kind' => $kind, 'id' => $id, 'version' => $version];
        if (isset($required[$role]) && $required[$role] !== $expectation) {
            throw self::failure('locked_snapshot_role_inconsistent', ['role' => $role]);
        }
        $required[$role] = $expectation;
    }

    private static function inventoryShortageCursorId(
        string $stockId,
        int $recipeId,
        int $estimatedUnitCostCents
    ): string {
        return CashierV3EntitlementCompletionLockPlanner::shortageCursorResourceId(
            $stockId,
            $recipeId,
            $estimatedUnitCostCents
        );
    }

    private static function eventContract(array $linePlans, string $workspaceId): array
    {
        $lineIds = array_values(array_map(static function (array $line): string {
            return $line['lineId'];
        }, $linePlans));
        $digest = hash('sha256', implode("\0", $lineIds));
        $writeoffKeys = [];
        $serviceKeys = [];
        $consumptionKeys = [];
        $laborKeys = [];
        $giftKeys = [];
        $batchKeys = [];
        $shortageKeys = [];
        foreach ($linePlans as $line) {
            $writeoffKeys[] = [
                'lineId' => $line['lineId'],
                'entitlementInstanceType' => $line['source']['entitlementInstanceType'],
                'entitlementInstanceId' => $line['source']['entitlementInstanceId'],
                'sourceDetailId' => $line['source']['sourceDetailId'],
                'sourceVersion' => $line['source']['sourceVersion'],
                'detailVersion' => $line['source']['detailVersion'],
            ];
            $serviceKeys[] = [
                'lineId' => $line['lineId'],
                'projectId' => $line['source']['projectId'],
            ];
            $consumptionKeys[] = [
                'lineId' => $line['lineId'],
                'projectId' => $line['source']['projectId'],
            ];
            foreach ($line['laborPerformance']['allocations'] as $allocation) {
                $laborKeys[] = [
                    'lineId' => $line['lineId'],
                    'staffId' => $allocation['staffId'],
                ];
            }
            if ($line['source']['isGift']) {
                $giftKeys[] = [
                    'lineId' => $line['lineId'],
                    'entitlementInstanceType' => $line['source']['entitlementInstanceType'],
                    'entitlementInstanceId' => $line['source']['entitlementInstanceId'],
                    'sourceDetailId' => $line['source']['sourceDetailId'],
                    'detailVersion' => $line['source']['detailVersion'],
                    'giftSourceType' => $line['source']['giftSourceType'],
                ];
            }
            foreach ($line['inventory']['consumables'] as $consumable) {
                foreach ($consumable['batchAllocations'] as $allocation) {
                    $batchKeys[] = [
                        'lineId' => $line['lineId'],
                        'stockId' => $consumable['stockId'],
                        'batchId' => $allocation['batchId'],
                    ];
                }
                if ($consumable['shortageQuantityUnits'] > 0) {
                    $shortageKeys[] = [
                        'lineId' => $line['lineId'],
                        'stockId' => $consumable['stockId'],
                    ];
                }
            }
        }

        $contract = [
            'entitlement.writeoff.completed' => self::eventRule(
                'line',
                'entitlement_source_detail',
                ['lineId', 'entitlementInstanceType', 'entitlementInstanceId', 'sourceDetailId', 'sourceVersion', 'detailVersion'],
                $writeoffKeys
            ),
            'service.completed' => self::eventRule(
                'line',
                'service_line',
                ['lineId', 'projectId'],
                $serviceKeys
            ),
            'performance.consumption.recorded' => self::eventRule(
                'line',
                'service_line',
                ['lineId', 'projectId'],
                $consumptionKeys
            ),
            'performance.labor.allocated' => self::eventRule(
                'line_staff',
                'service_line_staff',
                ['lineId', 'staffId'],
                $laborKeys
            ),
            'gift.consumed' => self::eventRule(
                'line_gift',
                'entitlement_source_detail',
                [
                    'lineId',
                    'entitlementInstanceType',
                    'entitlementInstanceId',
                    'sourceDetailId',
                    'detailVersion',
                    'giftSourceType',
                ],
                $giftKeys
            ),
            'inventory.batch.consumed' => self::eventRule(
                'line_stock_batch',
                'service_line_inventory_batch',
                ['lineId', 'stockId', 'batchId'],
                $batchKeys
            ),
            'inventory.shortage.recorded' => self::eventRule(
                'line_stock_shortage',
                'service_line_inventory_shortage',
                ['lineId', 'stockId'],
                $shortageKeys
            ),
            'inventory.service_consumption.resolved' => self::eventRule(
                'command',
                'entitlement_completion',
                ['workspaceId', 'sortedLineIdsSha256'],
                [[
                    'workspaceId' => $workspaceId,
                    'sortedLineIdsSha256' => $digest,
                    'lineIds' => $lineIds,
                ]]
            ),
        ];
        if (array_keys($contract) !== self::EVENT_TYPES) {
            throw self::failure('event_contract_type_order_drift');
        }
        return $contract;
    }

    private static function eventRule(
        string $grain,
        string $aggregateType,
        array $grainKeyComponents,
        array $requiredNaturalKeys
    ): array {
        $count = count($requiredNaturalKeys);
        return [
            'grain' => $grain,
            'cardinality' => 'exact_from_locked_plan',
            'minCount' => $count,
            'maxCount' => $count,
            'required' => $count > 0,
            'aggregateType' => $aggregateType,
            'sourceType' => self::ACTION,
            'naturalKeyComponents' => array_merge(
                ['commandIdempotencyKey', 'eventType'],
                $grainKeyComponents
            ),
            'requiredNaturalKeys' => array_values($requiredNaturalKeys),
        ];
    }

    private static function requiredEventTypes(array $contract): array
    {
        $required = [];
        foreach ($contract as $eventType => $rule) {
            if (($rule['required'] ?? false) === true) {
                $required[] = $eventType;
            }
        }
        return $required;
    }

    private static function assertComplexityBudget(array $lines, array $stocksById): void
    {
        $requestCounts = [];
        $totalRequests = 0;
        foreach ($lines as $line) {
            foreach ($line['inventory']['consumables'] as $consumable) {
                $totalRequests++;
                $stockId = $consumable['stockId'];
                $requestCounts[$stockId] = ($requestCounts[$stockId] ?? 0) + 1;
            }
        }
        if ($totalRequests > self::MAX_TOTAL_CONSUMABLE_REQUESTS) {
            throw self::failure('consumable_request_budget_exceeded', ['count' => $totalRequests]);
        }
        $totalBatches = 0;
        $allocationSteps = 0;
        foreach ($stocksById as $stockId => $stock) {
            $batchCount = count($stock['batches']);
            $totalBatches += $batchCount;
            $allocationSteps += ($requestCounts[$stockId] ?? 0) * $batchCount;
        }
        if ($totalBatches > self::MAX_TOTAL_BATCHES) {
            throw self::failure('inventory_batch_budget_exceeded', ['count' => $totalBatches]);
        }
        if ($allocationSteps > self::MAX_ALLOCATION_STEPS) {
            throw self::failure('inventory_allocation_budget_exceeded', ['steps' => $allocationSteps]);
        }
    }

    private static function countInventoryAllocations(array $inventory): int
    {
        $count = 0;
        foreach ($inventory['consumables'] as $consumable) {
            $count += count($consumable['batchAllocations']);
        }
        return $count;
    }

    private static function countInventoryShortages(array $inventory): int
    {
        $count = 0;
        foreach ($inventory['consumables'] as $consumable) {
            if ($consumable['shortageQuantityUnits'] > 0) {
                $count++;
            }
        }
        return $count;
    }

    private static function cumulativeAmount(int $total, int $times, int $completed): int
    {
        if ($completed <= 0) {
            return 0;
        }
        if ($completed >= $times) {
            return $total;
        }
        $regularWholeYuan = intdiv(intdiv($total, 100), $times);
        return $regularWholeYuan * 100 * $completed;
    }

    private static function scaledCostCents(int $quantityUnits, int $unitCostCents, int $scale): int
    {
        if ($quantityUnits === 0 || $unitCostCents === 0) {
            return 0;
        }
        if ($quantityUnits > intdiv(PHP_INT_MAX, $unitCostCents)) {
            throw self::failure('inventory_cost_overflow');
        }
        $factor = 1;
        for ($i = 0; $i < $scale; $i++) {
            $factor *= 10;
        }
        $numerator = $quantityUnits * $unitCostCents;
        $whole = intdiv($numerator, $factor);
        $remainder = $numerator % $factor;
        $result = $whole + (($remainder * 2 >= $factor) ? 1 : 0);
        if ($result > self::MAX_MONEY_CENTS) {
            throw self::failure('inventory_cost_overflow');
        }
        return $result;
    }

    private static function scaledCostDeltaCents(
        int $allocatedQuantityUnitsBefore,
        int $quantityUnits,
        int $unitCostCents,
        int $scale
    ): int {
        self::assertNonnegativeInt(
            $allocatedQuantityUnitsBefore,
            'allocatedQuantityUnitsBefore',
            self::MAX_STOCK_UNITS
        );
        self::assertNonnegativeInt($quantityUnits, 'quantityUnits', self::MAX_STOCK_UNITS);
        if ($quantityUnits > self::MAX_STOCK_UNITS - $allocatedQuantityUnitsBefore) {
            throw self::failure('inventory_cost_cursor_overflow');
        }
        $before = self::scaledCostCents($allocatedQuantityUnitsBefore, $unitCostCents, $scale);
        $after = self::scaledCostCents(
            $allocatedQuantityUnitsBefore + $quantityUnits,
            $unitCostCents,
            $scale
        );
        return $after - $before;
    }

    private static function multiplyMoney(int $unitCents, int $quantity, string $field): int
    {
        if ($quantity > 0 && $unitCents > intdiv(self::MAX_MONEY_CENTS, $quantity)) {
            throw self::failure('money_multiplication_overflow', ['field' => $field]);
        }
        return $unitCents * $quantity;
    }

    private static function moneyToCents($amount, string $field): int
    {
        if (!is_string($amount) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw self::failure('money_format_invalid', ['field' => $field]);
        }
        $parts = explode('.', $amount, 2);
        $digits = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = (string)self::MAX_MONEY_CENTS;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw self::failure('money_out_of_range', ['field' => $field]);
        }
        return (int)$digits;
    }

    private static function assertExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        $sortedExpected = $expected;
        sort($sortedExpected, SORT_STRING);
        if ($actual !== $sortedExpected) {
            throw self::failure('contract_keys_invalid', [
                'path' => $path,
                'expected' => $sortedExpected,
                'actual' => $actual,
            ]);
        }
    }

    private static function assertToken($value, string $field, int $maxLength): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw self::failure('token_invalid', ['field' => $field]);
        }
    }

    private static function assertNonemptyString($value, string $field, int $maxLength): void
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength) {
            throw self::failure('string_invalid', ['field' => $field]);
        }
    }

    private static function assertDate($value): void
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) !== 1
            || !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
            throw self::failure('business_date_invalid');
        }
    }

    private static function assertPermissionFingerprint($value, string $field): void
    {
        if (!is_string($value) || preg_match('/^roles:[a-f0-9]{32}$/D', $value) !== 1) {
            throw self::failure('permission_snapshot_fingerprint_invalid', ['field' => $field]);
        }
    }

    private static function assertPositiveInt($value, string $field, int $max = PHP_INT_MAX): void
    {
        if (!is_int($value) || $value <= 0 || $value > $max) {
            throw self::failure('positive_integer_invalid', ['field' => $field]);
        }
    }

    private static function assertNonnegativeInt($value, string $field, int $max = PHP_INT_MAX): void
    {
        if (!is_int($value) || $value < 0 || $value > $max) {
            throw self::failure('nonnegative_integer_invalid', ['field' => $field]);
        }
    }

    private static function assertStringList($value, string $field, int $max): void
    {
        if (!is_array($value) || !self::isList($value) || count($value) > $max) {
            throw self::failure('string_list_invalid', ['field' => $field]);
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                throw self::failure('string_list_invalid', ['field' => $field]);
            }
            $seen[$item] = true;
        }
    }

    private static function assertPositiveIntList($value, string $field, int $max): void
    {
        if (!is_array($value) || !self::isList($value) || !$value || count($value) > $max) {
            throw self::failure('positive_integer_list_invalid', ['field' => $field]);
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item <= 0 || isset($seen[$item])) {
                throw self::failure('positive_integer_list_invalid', ['field' => $field]);
            }
            $seen[$item] = true;
        }
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private static function failure(string $reason, array $detail = []): CashierV3EntitlementCompletionContractException
    {
        return new CashierV3EntitlementCompletionContractException($reason, $detail);
    }
}

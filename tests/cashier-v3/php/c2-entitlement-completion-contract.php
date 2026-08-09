<?php
/**
 * C2-B1 isolated entitlement-completion domain contract.
 *
 * This test is intentionally database-free and does not bootstrap ThinkPHP.
 */

$backendRoot = getenv('C2_B1_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && $backendRoot !== ''
    ? rtrim($backendRoot, '/')
    : __DIR__ . '/../../../后端代码';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceKindCatalog.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionContractException.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionLockPlanner.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php';

use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionContractException;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionLockPlanner;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3ResourceScope;

$completionKernelSource = (string)file_get_contents(
    $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php'
);
$completionLockPlannerSource = (string)file_get_contents(
    $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionLockPlanner.php'
);

$passed = 0;
$failed = 0;

function b1Assert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function b1Reason(callable $callable): string
{
    try {
        $callable();
    } catch (CashierV3EntitlementCompletionContractException $exception) {
        return $exception->reason();
    } catch (\Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function b1Command(): array
{
    return [
        'contractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
        'action' => CashierV3EntitlementCompletionKernel::ACTION,
        'workspaceId' => 'workspace-001',
        'stateContextId' => 'state-001',
        'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
        'memberId' => 1001,
        'lines' => [
            [
                'lineId' => 'entitlement-line-001',
                'quantity' => 1,
                'serviceObject' => 'self',
                'isExperience' => false,
                // Intent is a set. The locked snapshot owns primary/order.
                'craftsmanIds' => [12, 11],
            ],
            [
                'lineId' => 'entitlement-line-002',
                'quantity' => 2,
                'serviceObject' => 'friend',
                'isExperience' => true,
                'craftsmanIds' => [12],
            ],
        ],
    ];
}

function b1AuthorityCraftsmen(array $ids): array
{
    $rows = [];
    foreach ($ids as $index => $staffId) {
        $rows[] = [
            'staffId' => $staffId,
            'staffVersion' => 100 + $staffId,
            'staffName' => 'staff-' . $staffId,
            'storeId' => 7,
            'active' => true,
            'craftsmanEligible' => true,
            'sequence' => $index + 1,
            'isPrimary' => $index === 0,
            'laborWeight' => $staffId === 11 ? 1 : 2,
        ];
    }
    return $rows;
}

function b1Performance(int $sourceNo): array
{
    return [
        'ruleVersion' => 300 + $sourceNo,
        'consumptionMode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
        'consumptionConfiguredUnitAmount' => '0.00',
        'laborMode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED,
        'laborConfiguredUnitAmount' => '10.00',
    ];
}

function b1SnapshotLine(
    string $lineId,
    int $sortNo,
    int $sourceNo,
    int $totalTimes,
    string $purchaseAmount,
    array $craftsmen,
    string $policy
): array {
    return [
        'lineId' => $lineId,
        'sortNo' => $sortNo,
        'lineRole' => 'entitlement_service',
        'entitlementInstanceType' => CashierV3EntitlementCompletionKernel::ENTITLEMENT_CARD_HOLDER,
        'entitlementInstanceId' => 2000 + $sourceNo,
        'sourceKind' => 'count_card',
        'isGift' => false,
        'giftSourceType' => 'none',
        'giftId' => 0,
        'giftVersion' => 0,
        'holderId' => 2000 + $sourceNo,
        'originOrderId' => 5000 + $sourceNo,
        'sourceNameSnapshot' => '护理卡-' . $sourceNo,
        'sourceCodeSnapshot' => 'CARD-' . $sourceNo,
        'sourceDetailId' => 3000 + $sourceNo,
        'projectId' => 4000 + $sourceNo,
        'projectNameSnapshot' => '护理项目-' . $sourceNo,
        'projectCategoryIdSnapshot' => 51,
        'projectCategoryNameSnapshot' => '面部护理',
        'sourceVersion' => 80 + $sourceNo,
        'detailVersion' => 130 + $sourceNo,
        'holderActive' => true,
        'detailActive' => true,
        'usableAtSettlement' => true,
        'physicalRemainingTimes' => $totalTimes,
        'debtLimitedUsableTimes' => $totalTimes,
        'debtGuardId' => 'order:' . (5000 + $sourceNo),
        'debtGuardVersion' => 200 + $sourceNo,
        'occupiedTimes' => $sourceNo === 1 ? 1 : 0,
        'currentSourceConvertibleTimes' => $sourceNo === 1 ? 1 : 0,
        'occupationAuthorityComplete' => true,
        'occupationContributors' => $sourceNo === 1 ? [[
            'kind' => 'reservation',
            'id' => 8001,
            'version' => 5,
            'occupiedTimes' => 1,
            'convertibleTimes' => 1,
        ]] : [],
        'purchaseAmount' => $purchaseAmount,
        'totalPurchaseTimes' => $totalTimes,
        'amountCalculationVersion' => 'whole-yuan-floor-final-remainder-v1',
        'allowedStoreIds' => [8, 7],
        'craftsmen' => b1AuthorityCraftsmen($craftsmen),
        'performance' => b1Performance($sourceNo),
        'inventory' => [
            'merchantDefaultPolicy' => CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE,
            'merchantDefaultPolicyVersion' => 350 + $sourceNo,
            'productPolicyOverride' => $policy === CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE
                ? CashierV3EntitlementCompletionKernel::INVENTORY_POLICY_INHERIT
                : $policy,
            'productPolicyVersion' => 375 + $sourceNo,
            'policy' => $policy,
            'policyVersion' => 400 + $sourceNo,
            'recipeId' => 450 + $sourceNo,
            'recipeVersion' => 500 + $sourceNo,
            'recipeFormulaHash' => 'recipe-formula-' . $sourceNo,
            'consumables' => [[
                'stockId' => 'stock-9001',
                'quantityUnitsPerService' => 2,
                'shortageEstimatedUnitCostCents' => 300,
                'shortageCostAllocatedQuantityUnitsBefore' => 0,
                'shortageCursorId' => 'stock-9001:' . (450 + $sourceNo) . ':300',
                'shortageCursorVersion' => 600 + $sourceNo,
            ]],
        ],
    ];
}

function b1Snapshot(): array
{
    return [
        'workspaceId' => 'workspace-001',
        'stateContextId' => 'state-001',
        'workspaceVersion' => 9,
        'tenantId' => 'tenant-001',
        'organizationId' => 3,
        'organizationName' => '美业华东区',
        'organizationPath' => '/1/3/',
        'storeId' => 7,
        'storeName' => '魔核旗舰店',
        'memberId' => 1001,
        'memberName' => '肖君鹏',
        'memberVersion' => 4,
        'memberActive' => true,
        'workspaceStatus' => 'editing',
        'operatorId' => 21,
        'operatorName' => '收银员甲',
        'operatorStoreId' => 7,
        'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
        'operatorFeatures' => ['cashier.v3.cashier', 'cashier.v3.writeoff'],
        'businessDate' => '2026-07-29',
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => 1785258000,
        'settledAt' => 1785258001,
        'recordedAt' => 1785258002,
        'source' => [
            'type' => 'reservation',
            'serviceOrderId' => 7001,
            'serviceOrderVersion' => 4,
            'reservationId' => 8001,
            'reservationVersion' => 5,
        ],
        'inventoryProviderContractVersion' => CashierV3EntitlementCompletionKernel::INVENTORY_PROVIDER_CONTRACT_VERSION,
        'inventoryProviderTenantId' => 'tenant-001',
        'inventoryProviderStoreId' => 7,
        'inventoryShortageCursorLockGate' => CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE,
        'lines' => [
            b1SnapshotLine(
                'entitlement-line-001',
                10,
                1,
                3,
                '100.00',
                [11, 12],
                CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE
            ),
            b1SnapshotLine(
                'entitlement-line-002',
                20,
                2,
                2,
                '60.00',
                [12],
                CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE
            ),
        ],
        'inventoryStocks' => [[
            'stockId' => 'stock-9001',
            'stockVersion' => 17,
            'consumableId' => 9001,
            'skuId' => 9101,
            'stockUnitScale' => 0,
            'batches' => [[
                'batchId' => 501,
                'batchVersion' => 19,
                'availableQuantityUnits' => 5,
                'unitCostCents' => 100,
                'costAllocatedQuantityUnitsBefore' => 0,
                'allocationOrder' => 1,
            ]],
        ]],
    ];
}

function b1AddLockedResource(array &$resources, string $kind, string $id, int $order, int $version, string $role): void
{
    if (!CashierV3ResourceKindCatalog::isKnown($kind)
        || CashierV3ResourceKindCatalog::lockOrderOf($kind) !== $order) {
        throw new \RuntimeException('fixture does not match canonical resource catalog: ' . $kind);
    }
    $key = $kind . "\0" . $id;
    if (!isset($resources[$key])) {
        $resources[$key] = [
            'kind' => $kind,
            'id' => $id,
            'lockOrder' => $order,
            'lockedVersion' => $version,
            'roles' => [],
        ];
    }
    if ($resources[$key]['lockedVersion'] !== $version) {
        throw new \RuntimeException('fixture lock version mismatch: ' . $kind . ':' . $id);
    }
    if (!in_array($role, $resources[$key]['roles'], true)) {
        $resources[$key]['roles'][] = $role;
    }
}

function b1LockedPlan(array $snapshot): array
{
    $resources = [];
    b1AddLockedResource($resources, 'member', (string)$snapshot['memberId'], 10, $snapshot['memberVersion'], 'member');
    foreach ($snapshot['lines'] as $line) {
        b1AddLockedResource(
            $resources,
            'member_benefit_pool',
            (string)$line['sourceDetailId'],
            30,
            $line['detailVersion'],
            'benefit_pool:' . $line['sourceDetailId']
        );
        b1AddLockedResource(
            $resources,
            'card_holder',
            (string)$line['holderId'],
            40,
            $line['sourceVersion'],
            'card_holder:' . $line['holderId']
        );
        b1AddLockedResource(
            $resources,
            'performance_rule',
            (string)$line['projectId'],
            45,
            $line['performance']['ruleVersion'],
            'performance_rule:' . $line['lineId']
        );
        b1AddLockedResource(
            $resources,
            'inventory_policy',
            (string)$line['projectId'],
            46,
            $line['inventory']['policyVersion'],
            'inventory_policy:' . $line['lineId']
        );
        b1AddLockedResource(
            $resources,
            'inventory_recipe',
            (string)$line['inventory']['recipeId'],
            47,
            $line['inventory']['recipeVersion'],
            'inventory_recipe:' . $line['lineId']
        );
        foreach ($line['inventory']['consumables'] as $consumable) {
            b1AddLockedResource(
                $resources,
                'inventory_shortage_cursor',
                $consumable['shortageCursorId'],
                56,
                $consumable['shortageCursorVersion'],
                'inventory_shortage_cursor:' . $line['lineId'] . ':' . $consumable['stockId']
            );
        }
        b1AddLockedResource(
            $resources,
            'entitlement_debt_guard',
            $line['debtGuardId'],
            64,
            $line['debtGuardVersion'],
            'entitlement_debt_guard:' . $line['originOrderId']
        );
        foreach ($line['craftsmen'] as $craftsman) {
            b1AddLockedResource(
                $resources,
                'staff_profile',
                (string)$craftsman['staffId'],
                125,
                $craftsman['staffVersion'],
                'staff:' . $craftsman['staffId']
            );
        }
        foreach ($line['occupationContributors'] as $contributor) {
            b1AddLockedResource(
                $resources,
                $contributor['kind'],
                (string)$contributor['id'],
                CashierV3ResourceKindCatalog::lockOrderOf($contributor['kind']),
                $contributor['version'],
                'occupation:' . $line['lineId'] . ':' . $contributor['kind'] . ':' . $contributor['id']
            );
        }
    }
    foreach ($snapshot['inventoryStocks'] as $stock) {
        b1AddLockedResource(
            $resources,
            'inventory_stock',
            $stock['stockId'],
            50,
            $stock['stockVersion'],
            'inventory_stock:' . $stock['stockId']
        );
        foreach ($stock['batches'] as $batch) {
            b1AddLockedResource(
                $resources,
                'inventory_batch',
                (string)$batch['batchId'],
                55,
                $batch['batchVersion'],
                'inventory_batch:' . $stock['stockId'] . ':' . $batch['batchId']
            );
        }
    }
    if ($snapshot['source']['serviceOrderId'] > 0) {
        b1AddLockedResource(
            $resources,
            'service_order',
            (string)$snapshot['source']['serviceOrderId'],
            70,
            $snapshot['source']['serviceOrderVersion'],
            'service_order'
        );
    }
    if ($snapshot['source']['reservationId'] > 0) {
        b1AddLockedResource(
            $resources,
            'reservation',
            (string)$snapshot['source']['reservationId'],
            100,
            $snapshot['source']['reservationVersion'],
            'reservation'
        );
    }
    b1AddLockedResource(
        $resources,
        'cashier_workspace',
        $snapshot['workspaceId'],
        150,
        $snapshot['workspaceVersion'],
        'workspace'
    );
    $plan = array_values($resources);
    foreach ($plan as &$row) {
        sort($row['roles'], SORT_STRING);
    }
    unset($row);
    usort($plan, static function (array $left, array $right): int {
        return CashierV3ResourceKindCatalog::compareResources(
            $left['kind'],
            $left['id'],
            $right['kind'],
            $right['id']
        );
    });
    return $plan;
}

function b1Plan(?array $command = null, ?array $snapshot = null, ?array $lockedPlan = null): array
{
    $command = $command ?? b1Command();
    $snapshot = $snapshot ?? b1Snapshot();
    return CashierV3EntitlementCompletionKernel::plan(
        $command,
        $snapshot,
        $lockedPlan ?? b1LockedPlan($snapshot)
    );
}

function b1SortLockedPlan(array $plan): array
{
    foreach ($plan as &$row) {
        sort($row['roles'], SORT_STRING);
    }
    unset($row);
    usort($plan, static function (array $left, array $right): int {
        return CashierV3ResourceKindCatalog::compareResources(
            $left['kind'],
            $left['id'],
            $right['kind'],
            $right['id']
        );
    });
    return $plan;
}

function b1RemoveLockedRole(array $plan, string $role): array
{
    foreach ($plan as $index => &$row) {
        $row['roles'] = array_values(array_filter($row['roles'], static function (string $candidate) use ($role): bool {
            return $candidate !== $role;
        }));
        if (!$row['roles']) {
            unset($plan[$index]);
        }
    }
    unset($row);
    return array_values($plan);
}

b1Assert('composition empty', CashierV3EntitlementCompletionKernel::classifyLineRoles([]) === 'empty');
b1Assert('composition sale only', CashierV3EntitlementCompletionKernel::classifyLineRoles(['sale']) === 'sale_only');
b1Assert(
    'composition entitlement only',
    CashierV3EntitlementCompletionKernel::classifyLineRoles(['entitlement_service', 'entitlement_service']) === 'entitlement_only'
);
b1Assert(
    'composition mixed',
    CashierV3EntitlementCompletionKernel::classifyLineRoles(['sale', 'entitlement_service']) === 'mixed'
);
b1Assert(
    'unknown role rejected',
    b1Reason(static function (): void {
        CashierV3EntitlementCompletionKernel::classifyLineRoles(['unknown']);
    }) === 'line_role_not_allowed'
);

$plan = b1Plan();
$lineOne = $plan['linePlans'][0];
$lineTwo = $plan['linePlans'][1];
b1Assert(
    'plan is explicitly not persisted and entitlement only',
    $plan['persistenceStatus'] === 'not_persisted'
        && $plan['requiresGatewayTransaction'] === true
        && $plan['composition'] === 'entitlement_only'
);
b1Assert(
    'gateway permission fingerprint and both inventory adapter contract gates are frozen',
    $plan['permissionSnapshotFingerprint'] === b1Command()['permissionSnapshotFingerprint']
        && $plan['inventoryAdapterContract'] === [
            'consumerContractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
            'providerContractVersion' => CashierV3EntitlementCompletionKernel::INVENTORY_PROVIDER_CONTRACT_VERSION,
            'providerTenantId' => 'tenant-001',
            'providerStoreId' => 7,
            'shortageCursorLockGate' => CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE,
        ]
        && in_array(
            'inventoryAdapterMustRejectConsumerOrProviderContractVersionMismatch',
            $plan['integrationRequirements'],
            true
        )
        && in_array(
            'inventoryProviderMustReturnEveryLockedShortageCursorAsAnExplicitVersionedResource',
            $plan['integrationRequirements'],
            true
        )
        && $plan['activationContract']['gatewayStatus']
            === 'blocked_until_all_providers_and_legacy_writers_are_ready'
        && $plan['activationContract']['staffProfile']['resourceKind'] === 'staff_profile'
        && $plan['activationContract']['entitlementDebtGuard']['coversAbsentDebtRow'] === true
        && $plan['activationContract']['entitlementDebtGuard']['legacyDebtCreateAndRepayMustShareGuard'] === true
        && $plan['activationContract']['inventory']['callerOwnsFinalBusinessTransaction'] === true
        && $plan['activationContract']['inventory']['dataScopeMustMatchTenantAndStore'] === true
        && $plan['activationContract']['inventory']['providerLockOrder'] === [
            'inventory_policy' => 46,
            'inventory_recipe' => 47,
            'inventory_stock' => 50,
            'inventory_batch' => 55,
            'inventory_shortage_cursor' => 56,
        ]
        && in_array('callInventoryProviderInsideFinalGatewayBusinessTransaction', $plan['integrationRequirements'], true)
        && in_array('assertInventoryTenantAndStoreAgainstLockedGatewayDataScope', $plan['integrationRequirements'], true)
);
b1Assert(
    'authoritative sort controls line order',
    array_column($plan['linePlans'], 'lineId') === ['entitlement-line-001', 'entitlement-line-002']
        && array_column($plan['linePlans'], 'sortNo') === [10, 20]
);
b1Assert(
    'physical baseline and delta are explicit without writable after-values',
    count($plan['entitlementDeductions']) === 2
        && $plan['entitlementDeductions'][0]['expectedPhysicalRemainingTimes'] === 3
        && $plan['entitlementDeductions'][0]['deductPhysicalTimes'] === 1
        && $plan['entitlementDeductions'][0]['convertCurrentSourceOccupiedTimes'] === 1
        && !array_key_exists('availableBefore', $plan['entitlementDeductions'][0])
        && !array_key_exists('availableAfter', $plan['entitlementDeductions'][0])
);
b1Assert(
    'actual amount uses each locked physical source baseline',
    $lineOne['actualEntitlementAmountCents'] === 3300
        && $lineTwo['actualEntitlementAmountCents'] === 6000
        && $plan['totals']['actualEntitlementAmountCents'] === 9300
);
$staleAmountVersionSnapshot = b1Snapshot();
$staleAmountVersionSnapshot['lines'][0]['amountCalculationVersion'] = 'cumulative-half-up-cent-v2';
b1Assert(
    'stale fractional allocation drafts are rejected before completion',
    b1Reason(static function () use ($staleAmountVersionSnapshot): void {
        b1Plan(null, $staleAmountVersionSnapshot);
    }) === 'entitlement_amount_calculation_version_stale'
);
b1Assert(
    'consumption and labor performance remain separate facts',
    $plan['totals']['consumptionPerformanceCents'] === 9300
        && $plan['totals']['laborPerformanceCents'] === 3000
        && $lineOne['consumptionPerformance']['amountCents'] === 3300
        && $lineOne['laborPerformance']['amountCents'] === 1000
);
b1Assert(
    'locked craftsman sequence owns primary and tail allocation',
    $lineOne['laborPerformance']['allocations'][0]['staffId'] === 11
        && $lineOne['laborPerformance']['allocations'][0]['isPrimary'] === true
        && $lineOne['laborPerformance']['allocations'][0]['amountCents'] === 334
        && $lineOne['laborPerformance']['allocations'][1]['amountCents'] === 666
        && $lineOne['serviceSnapshot']['primaryCraftsmanId'] === 11
);
b1Assert(
    'business date and service linkage come only from locked snapshot',
    $lineOne['serviceSnapshot']['businessDate'] === '2026-07-29'
        && $lineOne['serviceSnapshot']['sourceType'] === 'reservation'
        && $lineOne['serviceSnapshot']['serviceOrderId'] === 7001
        && $lineOne['serviceSnapshot']['reservationId'] === 8001
);
b1Assert(
    'business dimensions times source names and allocation basis remain historical snapshots',
    $plan['businessTimezone'] === 'Asia/Shanghai'
        && $plan['occurredAt'] === 1785258000
        && $plan['settledAt'] === 1785258001
        && $plan['recordedAt'] === 1785258002
        && $plan['dimensionSnapshot'] === [
            'tenantId' => 'tenant-001',
            'organizationId' => 3,
            'organizationName' => '美业华东区',
            'organizationPath' => '/1/3/',
            'storeId' => 7,
            'storeName' => '魔核旗舰店',
            'memberId' => 1001,
            'memberName' => '肖君鹏',
            'operatorId' => 21,
            'operatorName' => '收银员甲',
        ]
        && $lineOne['source']['sourceNameSnapshot'] === '护理卡-1'
        && $lineOne['source']['sourceCodeSnapshot'] === 'CARD-1'
        && $lineOne['source']['projectNameSnapshot'] === '护理项目-1'
        && $lineOne['source']['projectCategoryNameSnapshot'] === '面部护理'
        && $lineOne['source']['purchaseAmountCents'] === 10000
        && $lineOne['source']['totalPurchaseTimes'] === 3
        && $lineOne['source']['consumedTimesAtLock'] === 0
        && $lineOne['source']['amountCalculationVersion'] === 'whole-yuan-floor-final-remainder-v1'
);
b1Assert(
    'strict and flexible projects use different entitlement sources',
    $lineOne['source']['holderId'] !== $lineTwo['source']['holderId']
        && $lineOne['inventory']['policy'] === CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE
        && $lineTwo['inventory']['policy'] === CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE
);
b1Assert(
    'inventory follows authoritative sort and never creates fake batches',
    $lineOne['inventory']['consumables'][0]['actualQuantityUnits'] === 2
        && $lineOne['inventory']['costComplete'] === true
        && $lineTwo['inventory']['consumables'][0]['actualQuantityUnits'] === 3
        && $lineTwo['inventory']['consumables'][0]['shortageQuantityUnits'] === 1
        && $lineTwo['inventory']['consumables'][0]['batchAllocations'][0]['batchId'] === 501
        && $lineTwo['inventory']['consumables'][0]['batchAllocations'][0]['quantityUnits'] === 3
        && $plan['totals']['actualInventoryCostCents'] === 500
        && $plan['totals']['estimatedInventoryShortageCostCents'] === 300
        && $plan['totals']['costComplete'] === false
);
b1Assert(
    'persistence plan preserves performance inventory and cost calculation snapshots',
    $lineOne['performanceRuleSnapshot'] === [
        'ruleVersion' => 301,
        'consumptionMode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
        'consumptionConfiguredUnitAmountCents' => 0,
        'laborMode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED,
        'laborConfiguredUnitAmountCents' => 1000,
    ]
        && $lineOne['inventory']['merchantDefaultPolicy'] === CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE
        && $lineOne['inventory']['merchantDefaultPolicyVersion'] === 351
        && $lineOne['inventory']['productPolicyOverride'] === CashierV3EntitlementCompletionKernel::INVENTORY_POLICY_INHERIT
        && $lineOne['inventory']['productPolicyVersion'] === 376
        && $lineOne['inventory']['policyVersion'] === 401
        && $lineOne['inventory']['policyVersionSemantics'] === 'merchant-default-plus-product-override-v1'
        && preg_match('/^[a-f0-9]{64}$/D', $lineOne['inventory']['policyResolutionFingerprint']) === 1
        && $lineOne['inventory']['recipeId'] === 451
        && $lineOne['inventory']['recipeVersion'] === 501
        && $lineOne['inventory']['recipeFormulaHash'] === 'recipe-formula-1'
        && $lineOne['inventory']['consumables'][0]['quantityUnitsPerService'] === 2
        && $lineTwo['inventory']['consumables'][0]['shortageEstimatedUnitCostCents'] === 300
        && $lineOne['inventory']['consumables'][0]['batchAllocations'][0]['costAllocatedQuantityUnitsBefore'] === 0
        && $lineOne['inventory']['consumables'][0]['batchAllocations'][0]['costAllocatedQuantityUnitsAfter'] === 2
        && $lineTwo['inventory']['consumables'][0]['batchAllocations'][0]['costAllocatedQuantityUnitsBefore'] === 2
        && $lineTwo['inventory']['consumables'][0]['batchAllocations'][0]['costAllocatedQuantityUnitsAfter'] === 5
        && $lineTwo['inventory']['consumables'][0]['shortageCostAllocatedQuantityUnitsBefore'] === 0
        && $lineTwo['inventory']['consumables'][0]['shortageCostAllocatedQuantityUnitsAfter'] === 1
        && $lineOne['inventory']['consumables'][0]['shortageCursorId'] === 'stock-9001:451:300'
        && $lineOne['inventory']['consumables'][0]['shortageCursorVersion'] === 601
);

$fractionalCostSnapshot = b1Snapshot();
$fractionalCostSnapshot['inventoryStocks'][0]['stockUnitScale'] = 1;
$fractionalCostSnapshot['inventoryStocks'][0]['batches'][0]['availableQuantityUnits'] = 6;
$fractionalCostSnapshot['inventoryStocks'][0]['batches'][0]['unitCostCents'] = 1;
$fractionalCostPlan = b1Plan(null, $fractionalCostSnapshot);
b1Assert(
    'batch cost tail is allocated cumulatively so split cart lines do not change total cost',
    $fractionalCostPlan['linePlans'][0]['inventory']['actualCostCents'] === 0
        && $fractionalCostPlan['linePlans'][1]['inventory']['actualCostCents'] === 1
        && $fractionalCostPlan['totals']['actualInventoryCostCents'] === 1
);

$batchCommandOne = b1Command();
$batchCommandOne['lines'] = [$batchCommandOne['lines'][0]];
$batchSnapshotOne = b1Snapshot();
$batchSnapshotOne['lines'] = [$batchSnapshotOne['lines'][0]];
$batchSnapshotOne['lines'][0]['inventory']['consumables'][0]['quantityUnitsPerService'] = 5;
$batchSnapshotOne['inventoryStocks'][0]['stockUnitScale'] = 1;
$batchSnapshotOne['inventoryStocks'][0]['batches'][0]['availableQuantityUnits'] = 5;
$batchSnapshotOne['inventoryStocks'][0]['batches'][0]['unitCostCents'] = 1;
$batchFirst = b1Plan($batchCommandOne, $batchSnapshotOne);

$batchSnapshotTwo = $batchSnapshotOne;
$batchSnapshotTwo['lines'][0]['physicalRemainingTimes'] = 2;
$batchSnapshotTwo['lines'][0]['debtLimitedUsableTimes'] = 2;
$batchSnapshotTwo['lines'][0]['occupiedTimes'] = 0;
$batchSnapshotTwo['lines'][0]['currentSourceConvertibleTimes'] = 0;
$batchSnapshotTwo['lines'][0]['occupationContributors'] = [];
$batchSnapshotTwo['lines'][0]['sourceVersion']++;
$batchSnapshotTwo['lines'][0]['detailVersion']++;
$batchSnapshotTwo['inventoryStocks'][0]['stockVersion']++;
$batchSnapshotTwo['inventoryStocks'][0]['batches'][0]['batchVersion']++;
$batchSnapshotTwo['inventoryStocks'][0]['batches'][0]['costAllocatedQuantityUnitsBefore'] = 5;
$batchSecond = b1Plan($batchCommandOne, $batchSnapshotTwo);

$batchCombinedCommand = $batchCommandOne;
$batchCombinedCommand['lines'][0]['quantity'] = 2;
$batchCombinedSnapshot = $batchSnapshotOne;
$batchCombinedSnapshot['inventoryStocks'][0]['batches'][0]['availableQuantityUnits'] = 10;
$batchCombined = b1Plan($batchCombinedCommand, $batchCombinedSnapshot);
b1Assert(
    'batch cost cursor keeps tails stable across separate completion commands',
    $batchFirst['totals']['actualInventoryCostCents'] === 1
        && $batchSecond['totals']['actualInventoryCostCents'] === 0
        && $batchCombined['totals']['actualInventoryCostCents'] === 1
        && $batchFirst['totals']['actualInventoryCostCents']
            + $batchSecond['totals']['actualInventoryCostCents']
            === $batchCombined['totals']['actualInventoryCostCents']
);

$shortageCommandOne = b1Command();
$shortageCommandOne['lines'] = [$shortageCommandOne['lines'][0]];
$shortageSnapshotOne = b1Snapshot();
$shortageSnapshotOne['lines'] = [$shortageSnapshotOne['lines'][0]];
$shortageSnapshotOne['lines'][0]['inventory']['policy'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
$shortageSnapshotOne['lines'][0]['inventory']['productPolicyOverride'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
$shortageSnapshotOne['lines'][0]['inventory']['consumables'][0]['quantityUnitsPerService'] = 5;
$shortageSnapshotOne['lines'][0]['inventory']['consumables'][0]['shortageEstimatedUnitCostCents'] = 1;
$shortageSnapshotOne['lines'][0]['inventory']['consumables'][0]['shortageCursorId'] = 'stock-9001:451:1';
$shortageSnapshotOne['inventoryStocks'][0]['stockUnitScale'] = 1;
$shortageSnapshotOne['inventoryStocks'][0]['batches'] = [];
$shortageFirst = b1Plan($shortageCommandOne, $shortageSnapshotOne);

$shortageSnapshotTwo = $shortageSnapshotOne;
$shortageSnapshotTwo['lines'][0]['physicalRemainingTimes'] = 2;
$shortageSnapshotTwo['lines'][0]['debtLimitedUsableTimes'] = 2;
$shortageSnapshotTwo['lines'][0]['occupiedTimes'] = 0;
$shortageSnapshotTwo['lines'][0]['currentSourceConvertibleTimes'] = 0;
$shortageSnapshotTwo['lines'][0]['occupationContributors'] = [];
$shortageSnapshotTwo['lines'][0]['sourceVersion']++;
$shortageSnapshotTwo['lines'][0]['detailVersion']++;
$shortageSnapshotTwo['inventoryStocks'][0]['stockVersion']++;
$shortageSnapshotTwo['lines'][0]['inventory']['consumables'][0]['shortageCostAllocatedQuantityUnitsBefore'] = 5;
$shortageSnapshotTwo['lines'][0]['inventory']['consumables'][0]['shortageCursorVersion']++;
$shortageSecond = b1Plan($shortageCommandOne, $shortageSnapshotTwo);

$shortageCombinedCommand = $shortageCommandOne;
$shortageCombinedCommand['lines'][0]['quantity'] = 2;
$shortageCombined = b1Plan($shortageCombinedCommand, $shortageSnapshotOne);
b1Assert(
    'shortage estimated cost cursor keeps tails stable across separate completion commands',
    $shortageFirst['totals']['estimatedInventoryShortageCostCents'] === 1
        && $shortageSecond['totals']['estimatedInventoryShortageCostCents'] === 0
        && $shortageCombined['totals']['estimatedInventoryShortageCostCents'] === 1
        && $shortageFirst['totals']['estimatedInventoryShortageCostCents']
            + $shortageSecond['totals']['estimatedInventoryShortageCostCents']
            === $shortageCombined['totals']['estimatedInventoryShortageCostCents']
);

$strictPrioritySnapshot = b1Snapshot();
$strictPrioritySnapshot['lines'][0]['inventory']['policy'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
$strictPrioritySnapshot['lines'][0]['inventory']['productPolicyOverride'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
$strictPrioritySnapshot['lines'][1]['inventory']['policy'] = CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE;
$strictPrioritySnapshot['lines'][1]['inventory']['productPolicyOverride'] = CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE;
$strictPriorityPlan = b1Plan(null, $strictPrioritySnapshot);
b1Assert(
    'strict inventory requests are reserved before shortage-tolerant lines',
    $strictPriorityPlan['linePlans'][0]['inventory']['consumables'][0]['actualQuantityUnits'] === 1
        && $strictPriorityPlan['linePlans'][0]['inventory']['consumables'][0]['shortageQuantityUnits'] === 1
        && $strictPriorityPlan['linePlans'][1]['inventory']['consumables'][0]['actualQuantityUnits'] === 4
        && $strictPriorityPlan['linePlans'][1]['inventory']['consumables'][0]['shortageQuantityUnits'] === 0
);

$lockedKinds = array_column($plan['lockedResourcePlan'], 'kind');
$lockedRoles = [];
$recipeLockIds = [];
foreach ($plan['lockedResourcePlan'] as $resource) {
    $lockedRoles = array_merge($lockedRoles, $resource['roles']);
    if ($resource['kind'] === 'inventory_recipe') {
        $recipeLockIds[] = $resource['id'];
    }
}
b1Assert(
    'shared locked plan covers debt guard formula batch occupation staff profile and workspace',
    in_array('entitlement_debt_guard', $lockedKinds, true)
        && in_array('performance_rule', $lockedKinds, true)
        && in_array('inventory_recipe', $lockedKinds, true)
        && in_array('inventory_batch', $lockedKinds, true)
        && in_array('reservation', $lockedKinds, true)
        && in_array('staff_profile', $lockedKinds, true)
        && in_array('cashier_workspace', $lockedKinds, true)
        && in_array('entitlement_debt_guard:5001', $lockedRoles, true)
        && in_array('occupation:entitlement-line-001:reservation:8001', $lockedRoles, true)
        && in_array('451', $recipeLockIds, true)
        && !in_array('recipe-formula-1', $recipeLockIds, true)
        && !in_array('checkout_request', $lockedKinds, true)
);

$otherOccupationSnapshot = b1Snapshot();
$otherOccupationSnapshot['lines'][0]['occupiedTimes'] = 2;
$otherOccupationSnapshot['lines'][0]['occupationContributors'][] = [
    'kind' => 'reservation',
    'id' => 9002,
    'version' => 6,
    'occupiedTimes' => 1,
    'convertibleTimes' => 0,
];
$otherOccupationPlan = b1Plan(null, $otherOccupationSnapshot);
$otherOccupationRoles = [];
foreach ($otherOccupationPlan['lockedResourcePlan'] as $resource) {
    $otherOccupationRoles = array_merge($otherOccupationRoles, $resource['roles']);
}
b1Assert(
    'current reservation conversion is separated from and locks every other occupation',
    $otherOccupationPlan['entitlementDeductions'][0]['convertCurrentSourceOccupiedTimes'] === 1
        && in_array(
            'occupation:entitlement-line-001:reservation:9002',
            $otherOccupationRoles,
            true
        )
);

$serviceOccupationSnapshot = b1Snapshot();
$serviceOccupationSnapshot['source'] = [
    'type' => 'service_order',
    'serviceOrderId' => 7001,
    'serviceOrderVersion' => 4,
    'reservationId' => 0,
    'reservationVersion' => 0,
];
$serviceOccupationSnapshot['lines'][0]['occupationContributors'][0] = [
    'kind' => 'service_order',
    'id' => 7001,
    'version' => 4,
    'occupiedTimes' => 1,
    'convertibleTimes' => 1,
];
$serviceOccupationPlan = b1Plan(null, $serviceOccupationSnapshot);
b1Assert(
    'current service order can convert only its own locked occupation',
    $serviceOccupationPlan['source']['type'] === 'service_order'
        && $serviceOccupationPlan['entitlementDeductions'][0]['convertCurrentSourceOccupiedTimes'] === 1
);

$events = $plan['requiredEventContract'];
b1Assert(
    'writeoff service and consumption events are exact per line',
    $events['entitlement.writeoff.completed']['grain'] === 'line'
        && $events['entitlement.writeoff.completed']['minCount'] === 2
        && $events['entitlement.writeoff.completed']['maxCount'] === 2
        && array_column($events['entitlement.writeoff.completed']['requiredNaturalKeys'], 'lineId')
            === ['entitlement-line-001', 'entitlement-line-002']
        && $events['service.completed']['grain'] === 'line'
        && $events['service.completed']['minCount'] === 2
        && $events['performance.consumption.recorded']['grain'] === 'line'
        && $events['performance.consumption.recorded']['minCount'] === 2
);
b1Assert(
    'labor events are exact per line and craftsman',
    $events['performance.labor.allocated']['grain'] === 'line_staff'
        && $events['performance.labor.allocated']['minCount'] === 3
        && $events['performance.labor.allocated']['maxCount'] === 3
        && $events['performance.labor.allocated']['requiredNaturalKeys'] === [
            ['lineId' => 'entitlement-line-001', 'staffId' => 11],
            ['lineId' => 'entitlement-line-001', 'staffId' => 12],
            ['lineId' => 'entitlement-line-002', 'staffId' => 12],
        ]
);
b1Assert(
    'non-gift entitlement keeps gift consumption optional but explicitly allowed',
    $events['gift.consumed']['grain'] === 'line_gift'
        && $events['gift.consumed']['minCount'] === 0
        && $events['gift.consumed']['maxCount'] === 0
        && $events['gift.consumed']['required'] === false
        && !in_array('gift.consumed', $plan['requiredEventTypes'], true)
);

$giftCommand = b1Command();
$giftCommand['lines'] = [$giftCommand['lines'][0]];
$giftSnapshot = b1Snapshot();
$giftSnapshot['lines'] = [$giftSnapshot['lines'][0]];
$giftSnapshot['lines'][0]['sourceKind'] = 'gift';
$giftSnapshot['lines'][0]['isGift'] = true;
$giftSnapshot['lines'][0]['giftSourceType'] = CashierV3EntitlementCompletionKernel::GIFT_SOURCE_HOLDER_BACKED;
$giftPlan = b1Plan($giftCommand, $giftSnapshot);
$giftLine = $giftPlan['linePlans'][0];
$giftKinds = array_column($giftPlan['lockedResourcePlan'], 'kind');
$giftEvent = $giftPlan['requiredEventContract']['gift.consumed'];
b1Assert(
    'legacy gift stays holder-backed and requires an exact gift consumption event',
    $giftLine['source']['entitlementInstanceType'] === CashierV3EntitlementCompletionKernel::ENTITLEMENT_CARD_HOLDER
        && $giftLine['source']['isGift'] === true
        && $giftLine['source']['giftSourceType'] === CashierV3EntitlementCompletionKernel::GIFT_SOURCE_HOLDER_BACKED
        && $giftLine['source']['giftId'] === 0
        && $giftLine['source']['giftVersion'] === 0
        && $giftLine['factIntents']['giftConsumption'] === 1
        && in_array('card_holder', $giftKinds, true)
        && in_array('member_benefit_pool', $giftKinds, true)
        && !in_array('member_gift', $giftKinds, true)
        && $giftEvent['aggregateType'] === 'entitlement_source_detail'
        && $giftEvent['required'] === true
        && $giftEvent['minCount'] === 1
        && $giftEvent['requiredNaturalKeys'][0]['sourceDetailId'] === $giftLine['source']['sourceDetailId']
        && $giftEvent['requiredNaturalKeys'][0]['detailVersion'] === $giftLine['source']['detailVersion']
        && in_array('gift.consumed', $giftPlan['requiredEventTypes'], true)
);

$standaloneGiftsRejected = true;
foreach ([
    CashierV3EntitlementCompletionKernel::ENTITLEMENT_INDEPENDENT_GIFT => 'independent',
    CashierV3EntitlementCompletionKernel::ENTITLEMENT_ORDER_ATTACHED_GIFT => 'order_attached',
] as $giftInstanceType => $giftSourceType) {
    $standaloneGift = b1Snapshot();
    $standaloneGift['lines'] = [$standaloneGift['lines'][0]];
    $standaloneGift['lines'][0]['entitlementInstanceType'] = $giftInstanceType;
    $standaloneGift['lines'][0]['giftSourceType'] = $giftSourceType;
    $standaloneGiftsRejected = $standaloneGiftsRejected
        && b1Reason(static function () use ($giftCommand, $standaloneGift): void {
            b1Plan($giftCommand, $standaloneGift, []);
        }) === 'standalone_gift_authority_not_ready';
}
b1Assert(
    'standalone gift paths fail closed until a real gift authority and provider exist',
    $standaloneGiftsRejected
);
b1Assert(
    'inventory events distinguish every real batch and shortage',
    $events['inventory.batch.consumed']['grain'] === 'line_stock_batch'
        && $events['inventory.batch.consumed']['minCount'] === 2
        && $events['inventory.batch.consumed']['requiredNaturalKeys'] === [
            ['lineId' => 'entitlement-line-001', 'stockId' => 'stock-9001', 'batchId' => 501],
            ['lineId' => 'entitlement-line-002', 'stockId' => 'stock-9001', 'batchId' => 501],
        ]
        && $events['inventory.shortage.recorded']['grain'] === 'line_stock_shortage'
        && $events['inventory.shortage.recorded']['minCount'] === 1
        && $events['inventory.shortage.recorded']['requiredNaturalKeys'] === [[
            'lineId' => 'entitlement-line-002',
            'stockId' => 'stock-9001',
        ]]
);
$allEventsHaveNaturalKeyEnvelope = true;
foreach ($events as $event) {
    $allEventsHaveNaturalKeyEnvelope = $allEventsHaveNaturalKeyEnvelope
        && $event['cardinality'] === 'exact_from_locked_plan'
        && $event['sourceType'] === CashierV3EntitlementCompletionKernel::ACTION
        && in_array('commandIdempotencyKey', $event['naturalKeyComponents'], true)
        && in_array('eventType', $event['naturalKeyComponents'], true)
        && $event['minCount'] === $event['maxCount'];
}
b1Assert(
    'every event rule has exact locked cardinality and idempotent natural-key envelope',
    count($events) === 8
        && count($plan['requiredEventTypes']) === 7
        && $plan['allowedEventTypes'] === array_keys($events)
        && $allEventsHaveNaturalKeyEnvelope
        && $events['inventory.service_consumption.resolved']['requiredNaturalKeys'][0]['lineIds']
            === ['entitlement-line-001', 'entitlement-line-002']
);
b1Assert(
    'pure entitlement plan forbids sale and money writes',
    in_array('sales_order', $plan['forbiddenWriteDomains'], true)
        && in_array('payment', $plan['forbiddenWriteDomains'], true)
        && in_array('balance', $plan['forbiddenWriteDomains'], true)
        && in_array('card_operation', $plan['forbiddenWriteDomains'], true)
);

$permutedCommand = b1Command();
$permutedCommand['lines'] = array_reverse($permutedCommand['lines']);
$permutedSnapshot = b1Snapshot();
$permutedSnapshot['lines'] = array_reverse($permutedSnapshot['lines']);
$permutedSnapshot['lines'][1]['craftsmen'] = array_reverse($permutedSnapshot['lines'][1]['craftsmen']);
$permutedPlan = b1Plan($permutedCommand, $permutedSnapshot);
b1Assert(
    'command and snapshot array permutations cannot move tails or stock',
    $permutedPlan['linePlans'] === $plan['linePlans']
        && $permutedPlan['entitlementDeductions'] === $plan['entitlementDeductions']
        && $permutedPlan['totals'] === $plan['totals']
        && $permutedPlan['requiredEventContract'] === $plan['requiredEventContract']
);

$sameSourceSnapshot = b1Snapshot();
$sourceFields = [
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
    'occupiedTimes',
    'currentSourceConvertibleTimes',
    'debtGuardId',
    'debtGuardVersion',
    'occupationAuthorityComplete',
    'occupationContributors',
    'purchaseAmount',
    'totalPurchaseTimes',
    'amountCalculationVersion',
    'allowedStoreIds',
    'performance',
    'inventory',
];
foreach ($sourceFields as $field) {
    $sameSourceSnapshot['lines'][1][$field] = $sameSourceSnapshot['lines'][0][$field];
}
$sameSourceSnapshot['inventoryStocks'][0]['batches'][0]['availableQuantityUnits'] = 6;
$sameSourcePlan = b1Plan(null, $sameSourceSnapshot);
b1Assert(
    'consistent duplicate source lines aggregate and return the whole-yuan tail deterministically',
    count($sameSourcePlan['entitlementDeductions']) === 1
        && $sameSourcePlan['entitlementDeductions'][0]['deductPhysicalTimes'] === 3
        && $sameSourcePlan['linePlans'][0]['actualEntitlementAmountCents'] === 3300
        && $sameSourcePlan['linePlans'][1]['actualEntitlementAmountCents'] === 6700
        && $sameSourcePlan['totals']['actualEntitlementAmountCents'] === 10000
);
b1Assert(
    'zero shortage remains allowed but is not falsely required',
    $sameSourcePlan['requiredEventContract']['inventory.shortage.recorded']['minCount'] === 0
        && $sameSourcePlan['requiredEventContract']['inventory.shortage.recorded']['maxCount'] === 0
        && $sameSourcePlan['requiredEventContract']['inventory.shortage.recorded']['required'] === false
        && !in_array('inventory.shortage.recorded', $sameSourcePlan['requiredEventTypes'], true)
        && in_array('inventory.shortage.recorded', $sameSourcePlan['allowedEventTypes'], true)
);

$inconsistentSources = [];
$inconsistentSources['policy'] = $sameSourceSnapshot;
$inconsistentSources['policy']['lines'][1]['inventory']['policy'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
$inconsistentSources['policy']['lines'][1]['inventory']['productPolicyOverride'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
$inconsistentSources['allowed_store'] = $sameSourceSnapshot;
$inconsistentSources['allowed_store']['lines'][1]['allowedStoreIds'] = [7];
$inconsistentSources['performance_rule'] = $sameSourceSnapshot;
$inconsistentSources['performance_rule']['lines'][1]['performance']['laborConfiguredUnitAmount'] = '11.00';
$inconsistentSources['formula'] = $sameSourceSnapshot;
$inconsistentSources['formula']['lines'][1]['inventory']['recipeFormulaHash'] = 'different-recipe-formula';
$allSourceInconsistenciesRejected = true;
foreach ($inconsistentSources as $inconsistencyName => $inconsistentSource) {
    $expectedReason = $inconsistencyName === 'allowed_store'
        ? 'entitlement_source_snapshot_inconsistent'
        : 'shared_resource_snapshot_inconsistent';
    $allSourceInconsistenciesRejected = $allSourceInconsistenciesRejected
        && b1Reason(static function () use ($inconsistentSource): void {
            b1Plan(null, $inconsistentSource);
        }) === $expectedReason;
}
b1Assert(
    'same source cannot diverge on store scope performance policy or formula',
    $allSourceInconsistenciesRejected
);

$sharedResourceInconsistencies = [];
$sharedResourceInconsistencies['performance'] = b1Snapshot();
$sharedResourceInconsistencies['performance']['lines'][1]['projectId']
    = $sharedResourceInconsistencies['performance']['lines'][0]['projectId'];
$sharedResourceInconsistencies['performance']['lines'][1]['performance']['ruleVersion']
    = $sharedResourceInconsistencies['performance']['lines'][0]['performance']['ruleVersion'];
$sharedResourceInconsistencies['performance']['lines'][1]['performance']['laborConfiguredUnitAmount'] = '11.00';
$sharedResourceInconsistencies['policy'] = b1Snapshot();
$sharedResourceInconsistencies['policy']['lines'][1]['projectId']
    = $sharedResourceInconsistencies['policy']['lines'][0]['projectId'];
$sharedResourceInconsistencies['policy']['lines'][1]['inventory']['policyVersion']
    = $sharedResourceInconsistencies['policy']['lines'][0]['inventory']['policyVersion'];
$sharedResourceInconsistencies['recipe'] = b1Snapshot();
$sharedResourceInconsistencies['recipe']['lines'][1]['inventory']['recipeId']
    = $sharedResourceInconsistencies['recipe']['lines'][0]['inventory']['recipeId'];
$sharedResourceInconsistencies['recipe']['lines'][1]['inventory']['recipeVersion']
    = $sharedResourceInconsistencies['recipe']['lines'][0]['inventory']['recipeVersion'];
$sharedResourceInconsistencies['recipe']['lines'][1]['inventory']['consumables'][0]['shortageCursorId']
    = 'stock-9001:451:300';
$allSharedResourceInconsistenciesRejected = true;
$sharedResourceReasons = [];
foreach ($sharedResourceInconsistencies as $sharedResourceName => $sharedResourceInconsistency) {
    $actualReason = b1Reason(static function () use ($sharedResourceInconsistency): void {
        b1Plan(null, $sharedResourceInconsistency, []);
    });
    $sharedResourceReasons[$sharedResourceName] = $actualReason;
    $allSharedResourceInconsistenciesRejected = $allSharedResourceInconsistenciesRejected
        && $actualReason === 'shared_resource_snapshot_inconsistent';
}
b1Assert(
    'different entitlement sources cannot attach conflicting snapshots to one locked rule policy or recipe',
    $allSharedResourceInconsistenciesRejected,
    json_encode($sharedResourceReasons)
);

$allocationInvariant = true;
for ($totalCents = 0; $totalCents <= 10000; $totalCents += 100) {
    for ($times = 1; $times <= 10; $times++) {
        $sum = 0;
        for ($used = 0; $used < $times; $used++) {
            $sum += CashierV3EntitlementCompletionKernel::allocateActualAmountCents($totalCents, $times, $used, 1);
        }
        if ($sum !== $totalCents) {
            $allocationInvariant = false;
            break 2;
        }
    }
}
b1Assert('all tested whole-yuan tails return to source total', $allocationInvariant);
b1Assert(
    'fractional-yuan source amounts are rejected instead of rounded or truncated',
    b1Reason(static function (): void {
        CashierV3EntitlementCompletionKernel::allocateActualAmountCents(10001, 3, 0, 1);
    }) === 'entitlement_purchase_amount_not_whole_yuan'
);

$labor = CashierV3EntitlementCompletionKernel::allocateLaborAmount(1, [30, 20, 10], [30 => 1, 20 => 1, 10 => 1]);
b1Assert(
    'labor allocation is exact even below craftsman count',
    array_sum(array_column($labor, 'amountCents')) === 1
        && $labor[0]['staffId'] === 30
        && $labor[0]['amountCents'] === 1
        && $labor[0]['isPrimary'] === true
);

$cardCommand = b1Command();
$cardCommand['cardTransfer'] = ['targetMemberId' => 2];
b1Assert(
    'card operations are explicitly rejected',
    b1Reason(static function () use ($cardCommand): void {
        b1Plan($cardCommand);
    }) === 'card_operation_not_in_scope'
);

$authorityLeakCommand = b1Command();
$authorityLeakCommand['businessDate'] = '2020-01-01';
b1Assert(
    'command cannot override authoritative business date or source',
    b1Reason(static function () use ($authorityLeakCommand): void {
        b1Plan($authorityLeakCommand);
    }) === 'contract_keys_invalid'
);

$mixedSnapshot = b1Snapshot();
$mixedSnapshot['lines'][1]['lineRole'] = 'sale';
b1Assert(
    'mixed checkout cannot enter B1 kernel',
    b1Reason(static function () use ($mixedSnapshot): void {
        b1Plan(null, $mixedSnapshot);
    }) === 'entitlement_only_composition_required'
);

$shortSnapshot = b1Snapshot();
$shortSnapshot['lines'][1]['inventory']['policy'] = CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE;
$shortSnapshot['lines'][1]['inventory']['productPolicyOverride'] = CashierV3EntitlementCompletionKernel::INVENTORY_DENY_SHORTAGE;
b1Assert(
    'deny-shortage policy rejects the whole plan',
    b1Reason(static function () use ($shortSnapshot): void {
        b1Plan(null, $shortSnapshot);
    }) === 'inventory_shortage_denied'
);

$mismatchedPolicySnapshot = b1Snapshot();
$mismatchedPolicySnapshot['lines'][0]['inventory']['policy'] = CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE;
b1Assert(
    'resolved inventory policy must equal merchant default plus product override',
    b1Reason(static function () use ($mismatchedPolicySnapshot): void {
        b1Plan(null, $mismatchedPolicySnapshot);
    }) === 'inventory_policy_resolution_mismatch'
);

$inactiveStaff = b1Snapshot();
$inactiveStaff['lines'][0]['craftsmen'][0]['active'] = false;
b1Assert(
    'inactive craftsman fails closed',
    b1Reason(static function () use ($inactiveStaff): void {
        b1Plan(null, $inactiveStaff);
    }) === 'craftsman_not_active_eligible_in_store'
);

$missingPermission = b1Snapshot();
$missingPermission['operatorFeatures'] = ['cashier.v3.member'];
b1Assert(
    '权益完成必须拥有收银或核销权限',
    b1Reason(static function () use ($missingPermission): void {
        b1Plan(null, $missingPermission);
    }) === 'required_feature_missing'
);

$stalePermissionCommand = b1Command();
$stalePermissionCommand['permissionSnapshotFingerprint'] = 'roles:' . str_repeat('b', 32);
b1Assert(
    'kernel matches the command to the gateway locked permission fingerprint without a fake resource lock',
    b1Reason(static function () use ($stalePermissionCommand): void {
        b1Plan($stalePermissionCommand);
    }) === 'permission_snapshot_changed'
        && !in_array('operator_permission', array_column($plan['lockedResourcePlan'], 'kind'), true)
);

$providerVersionDrift = b1Snapshot();
$providerVersionDrift['inventoryProviderContractVersion'] = 'inventory-provider-unreviewed';
$providerScopeDrift = b1Snapshot();
$providerScopeDrift['inventoryProviderStoreId'] = 8;
$shortageGateMissing = b1Snapshot();
$shortageGateMissing['inventoryShortageCursorLockGate'] = 'not_locked';
b1Assert(
    'inventory adapter rejects provider version drift and an unproven shortage cursor lock',
    b1Reason(static function () use ($providerVersionDrift): void {
        b1Plan(null, $providerVersionDrift, []);
    }) === 'inventory_provider_contract_version_mismatch'
        && b1Reason(static function () use ($providerScopeDrift): void {
            b1Plan(null, $providerScopeDrift, []);
        }) === 'inventory_provider_scope_mismatch'
        && b1Reason(static function () use ($shortageGateMissing): void {
            b1Plan(null, $shortageGateMissing, []);
        }) === 'inventory_shortage_cursor_gate_missing'
);

$incompleteOccupation = b1Snapshot();
$incompleteOccupation['lines'][0]['occupationAuthorityComplete'] = false;
$wrongOccupationContext = b1Snapshot();
$wrongOccupationContext['lines'][0]['occupationContributors'][0]['id'] = 8999;
b1Assert(
    'occupation selection fails closed unless every contributor and current conversion context are authoritative',
    b1Reason(static function () use ($incompleteOccupation): void {
        b1Plan(null, $incompleteOccupation, []);
    }) === 'occupation_authority_incomplete'
        && b1Reason(static function () use ($wrongOccupationContext): void {
            b1Plan(null, $wrongOccupationContext, []);
        }) === 'occupation_not_convertible_by_current_source'
);

$overQuantity = b1Command();
$overQuantity['lines'][0]['quantity'] = 4;
b1Assert(
    'debt limited usable and occupied times cap aggregate quantity',
    b1Reason(static function () use ($overQuantity): void {
        b1Plan($overQuantity);
    }) === 'entitlement_quantity_exceeds_available'
);

$directSnapshot = b1Snapshot();
$directSnapshot['source'] = [
    'type' => 'direct',
    'serviceOrderId' => 0,
    'serviceOrderVersion' => 0,
    'reservationId' => 0,
    'reservationVersion' => 0,
];
$directSnapshot['lines'][0]['currentSourceConvertibleTimes'] = 0;
$directSnapshot['lines'][0]['occupationContributors'][0]['convertibleTimes'] = 0;
b1Assert(
    'direct completion is authoritative and does not fabricate service linkage',
    b1Plan(null, $directSnapshot)['source']['type'] === 'direct'
        && b1Plan(null, $directSnapshot)['source']['serviceOrderId'] === 0
        && b1Plan(null, $directSnapshot)['source']['reservationId'] === 0
);

$missingDebtPlan = b1RemoveLockedRole(b1LockedPlan(b1Snapshot()), 'entitlement_debt_guard:5001');
b1Assert(
    'kernel rejects an incomplete already-locked resource plan',
    b1Reason(static function () use ($missingDebtPlan): void {
        b1Plan(null, null, $missingDebtPlan);
    }) === 'locked_resource_coverage_missing'
);

$missingShortageCursorPlan = b1RemoveLockedRole(
    b1LockedPlan(b1Snapshot()),
    'inventory_shortage_cursor:entitlement-line-001:stock-9001'
);
b1Assert(
    'kernel rejects a missing explicit shortage cursor resource lock',
    b1Reason(static function () use ($missingShortageCursorPlan): void {
        b1Plan(null, null, $missingShortageCursorPlan);
    }) === 'locked_resource_coverage_missing'
);

$wrongShortageCursorVersionPlan = b1LockedPlan(b1Snapshot());
foreach ($wrongShortageCursorVersionPlan as &$lockedResource) {
    if ($lockedResource['kind'] === 'inventory_shortage_cursor'
        && $lockedResource['id'] === 'stock-9001:451:300') {
        $lockedResource['lockedVersion']++;
    }
}
unset($lockedResource);
b1Assert(
    'kernel rejects shortage cursor version drift',
    b1Reason(static function () use ($wrongShortageCursorVersionPlan): void {
        b1Plan(null, null, $wrongShortageCursorVersionPlan);
    }) === 'locked_resource_coverage_mismatch'
);

$extraKnownResourcePlan = b1LockedPlan(b1Snapshot());
$extraKnownResourcePlan[] = [
    'kind' => 'member_balance',
    'id' => '1001',
    'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf('member_balance'),
    'lockedVersion' => 1,
    'roles' => ['unexpected_member_balance'],
];
$extraKnownResourcePlan = b1SortLockedPlan($extraKnownResourcePlan);
$extraKnownRolePlan = b1LockedPlan(b1Snapshot());
foreach ($extraKnownRolePlan as &$resource) {
    if ($resource['kind'] === 'member' && $resource['id'] === '1001') {
        $resource['roles'][] = 'unexpected_member_role';
    }
}
unset($resource);
$extraKnownRolePlan = b1SortLockedPlan($extraKnownRolePlan);
b1Assert(
    'kernel accepts only the exact orchestrator-filtered entitlement lock subset',
    b1Reason(static function () use ($extraKnownResourcePlan): void {
        b1Plan(null, null, $extraKnownResourcePlan);
    }) === 'locked_resource_coverage_extra_resource'
        && b1Reason(static function () use ($extraKnownRolePlan): void {
            b1Plan(null, null, $extraKnownRolePlan);
        }) === 'locked_resource_coverage_extra_role'
);

$outerCheckoutPlan = b1LockedPlan(b1Snapshot());
$outerCheckoutPlan[] = [
    'kind' => 'checkout_request',
    'id' => 'checkout-outer-1',
    'lockOrder' => CashierV3ResourceKindCatalog::lockOrderOf('checkout_request'),
    'lockedVersion' => 1,
    'roles' => ['checkout_request'],
];
$outerCheckoutPlan = b1SortLockedPlan($outerCheckoutPlan);
b1Assert(
    'outer checkout request stays locked by orchestration but is never passed into the entitlement kernel',
    b1Reason(static function () use ($outerCheckoutPlan): void {
        b1Plan(null, null, $outerCheckoutPlan);
    }) === 'locked_resource_coverage_extra_resource'
);

$wrongBatchPlan = b1LockedPlan(b1Snapshot());
foreach ($wrongBatchPlan as &$resource) {
    if (in_array('inventory_batch:stock-9001:501', $resource['roles'], true)) {
        $resource['lockedVersion']++;
    }
}
unset($resource);
b1Assert(
    'kernel rejects a locked resource version that differs from snapshot',
    b1Reason(static function () use ($wrongBatchPlan): void {
        b1Plan(null, null, $wrongBatchPlan);
    }) === 'locked_resource_coverage_mismatch'
);

$unknownKindPlan = b1LockedPlan(b1Snapshot());
$unknownKindPlan[0]['kind'] = 'forged_kind';
b1Assert(
    'kernel rejects lock kinds outside the canonical C1 catalog',
    b1Reason(static function () use ($unknownKindPlan): void {
        b1Plan(null, null, $unknownKindPlan);
    }) === 'locked_resource_kind_not_allowed'
);

$wrongOrderPlan = b1LockedPlan(b1Snapshot());
$wrongOrderPlan[0]['lockOrder'] = 999;
b1Assert(
    'kernel rejects caller-supplied lock order that differs from the canonical catalog',
    b1Reason(static function () use ($wrongOrderPlan): void {
        b1Plan(null, null, $wrongOrderPlan);
    }) === 'locked_resource_order_mismatch'
);

$wrongKindCoveragePlan = b1LockedPlan(b1Snapshot());
foreach ($wrongKindCoveragePlan as &$resource) {
    if (in_array('performance_rule:entitlement-line-001', $resource['roles'], true)) {
        $resource['kind'] = 'card_holder';
        $resource['lockOrder'] = CashierV3ResourceKindCatalog::lockOrderOf('card_holder');
    }
}
unset($resource);
usort($wrongKindCoveragePlan, static function (array $left, array $right): int {
    $order = $left['lockOrder'] <=> $right['lockOrder'];
    if ($order !== 0) {
        return $order;
    }
    $kind = strcmp($left['kind'], $right['kind']);
    return $kind !== 0 ? $kind : strcmp($left['id'], $right['id']);
});
b1Assert(
    'kernel requires each lock role to use the expected canonical resource kind',
    b1Reason(static function () use ($wrongKindCoveragePlan): void {
        b1Plan(null, null, $wrongKindCoveragePlan);
    }) === 'locked_resource_coverage_extra_resource'
);

$tooManyBatches = b1Snapshot();
$tooManyBatches['inventoryStocks'][0]['batches'] = [];
for ($i = 1; $i <= 501; $i++) {
    $tooManyBatches['inventoryStocks'][0]['batches'][] = [
        'batchId' => 1000 + $i,
        'batchVersion' => 1,
        'availableQuantityUnits' => 1,
        'unitCostCents' => 1,
        'costAllocatedQuantityUnitsBefore' => 0,
        'allocationOrder' => $i,
    ];
}
b1Assert(
    'batch complexity budget fails before allocation scanning',
    b1Reason(static function () use ($tooManyBatches): void {
        b1Plan(null, $tooManyBatches, []);
    }) === 'inventory_batch_budget_exceeded'
);

$tooManyConsumables = b1Snapshot();
$tooManyConsumables['lines'] = [];
for ($lineNo = 1; $lineNo <= 11; $lineNo++) {
    $line = b1SnapshotLine(
        'budget-line-' . $lineNo,
        $lineNo,
        100 + $lineNo,
        1,
        '1.00',
        [11],
        CashierV3EntitlementCompletionKernel::INVENTORY_ALLOW_SHORTAGE
    );
    $line['occupiedTimes'] = 0;
    $line['currentSourceConvertibleTimes'] = 0;
    $line['occupationContributors'] = [];
    $line['inventory']['consumables'] = [];
    for ($consumableNo = 1; $consumableNo <= 100; $consumableNo++) {
        $line['inventory']['consumables'][] = [
            'stockId' => 'budget-stock-' . $lineNo . '-' . $consumableNo,
            'quantityUnitsPerService' => 1,
            'shortageEstimatedUnitCostCents' => 0,
            'shortageCostAllocatedQuantityUnitsBefore' => 0,
            'shortageCursorId' => 'budget-stock-' . $lineNo . '-' . $consumableNo
                . ':' . $line['inventory']['recipeId'] . ':0',
            'shortageCursorVersion' => 1,
        ];
    }
    $tooManyConsumables['lines'][] = $line;
}
b1Assert(
    'total consumable request budget fails before stock scanning',
    b1Reason(static function () use ($tooManyConsumables): void {
        b1Plan(null, $tooManyConsumables, []);
    }) === 'consumable_request_budget_exceeded'
);

$candidateResources = [
    ['kind' => 'card_holder', 'id' => '2'],
    ['kind' => 'card_holder', 'id' => '10'],
    ['kind' => 'member', 'id' => '9'],
];
$candidatePlan = CashierV3EntitlementCompletionLockPlanner::build($candidateResources);
$c1Parity = $candidateResources;
usort($c1Parity, static function (array $left, array $right): int {
    return CashierV3ResourceKindCatalog::compareResources(
        $left['kind'],
        $left['id'],
        $right['kind'],
        $right['id']
    );
});
b1Assert(
    'pre-lock candidate id order exactly matches canonical numeric id semantics',
    array_column($candidatePlan, 'id') === array_column($c1Parity, 'id')
        && array_column($candidatePlan, 'id') === ['9', '2', '10']
);
b1Assert(
    'completion planner and post-lock kernel share the catalog comparator',
    strpos($completionLockPlannerSource, 'CashierV3ResourceKindCatalog::compareResources') !== false
        && substr_count($completionKernelSource, 'CashierV3ResourceKindCatalog::compareResourceIds') >= 3
        && strpos($completionKernelSource, 'CashierV3ResourceKindCatalog::compareResources') !== false
);
b1Assert(
    'completion lock kinds and orders are canonical C1 catalog entries',
    CashierV3ResourceKindCatalog::selfCheck() === []
        && !CashierV3ResourceKindCatalog::isKnown('operator_permission')
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('entitlement_debt_guard') === 64
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('performance_rule') === 45
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('inventory_policy') === 46
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('inventory_recipe') === 47
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('inventory_batch') === 55
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('inventory_shortage_cursor') === 56
        && CashierV3EntitlementCompletionLockPlanner::lockOrder('staff_profile') === 125
);
b1Assert(
    'shortage cursor resource identity is stable across C2 planning',
    CashierV3EntitlementCompletionLockPlanner::shortageCursorResourceId('502', 901, 50)
        === '502:901:50'
        && b1Reason(static function (): void {
            CashierV3EntitlementCompletionLockPlanner::shortageCursorResourceId('', 901, 50);
        }) === 'inventory_shortage_cursor_identity_invalid'
);
b1Assert(
    'new completion resources have explicit canonical scopes and remain domain-owned fail-closed',
    CashierV3ResourceKindCatalog::scopeTypeOf('entitlement_debt_guard') === CashierV3ResourceScope::TYPE_TENANT
        && CashierV3ResourceKindCatalog::scopeTypeOf('staff_profile') === CashierV3ResourceScope::TYPE_STORE
        && CashierV3ResourceKindCatalog::scopeTypeOf('performance_rule') === CashierV3ResourceScope::TYPE_TENANT
        && CashierV3ResourceKindCatalog::scopeTypeOf('inventory_policy') === CashierV3ResourceScope::TYPE_TENANT
        && CashierV3ResourceKindCatalog::scopeTypeOf('inventory_recipe') === CashierV3ResourceScope::TYPE_TENANT
        && CashierV3ResourceKindCatalog::scopeTypeOf('inventory_batch') === CashierV3ResourceScope::TYPE_STORE
        && CashierV3ResourceKindCatalog::scopeTypeOf('inventory_shortage_cursor') === CashierV3ResourceScope::TYPE_STORE
        && CashierV3ResourceKindCatalog::isDomainOwned('entitlement_debt_guard')
        && CashierV3ResourceKindCatalog::isDomainOwned('staff_profile')
        && CashierV3ResourceKindCatalog::isDomainOwned('performance_rule')
        && CashierV3ResourceKindCatalog::isDomainOwned('inventory_policy')
        && CashierV3ResourceKindCatalog::isDomainOwned('inventory_recipe')
        && CashierV3ResourceKindCatalog::isDomainOwned('inventory_batch')
        && CashierV3ResourceKindCatalog::isDomainOwned('inventory_shortage_cursor')
);
b1Assert(
    'standalone member gift and staff time slot are not completion lock candidates',
    b1Reason(static function (): void {
        CashierV3EntitlementCompletionLockPlanner::lockOrder('member_gift');
    }) === 'lock_resource_kind_not_allowed'
        && b1Reason(static function (): void {
            CashierV3EntitlementCompletionLockPlanner::lockOrder('staff_time_slot');
        }) === 'lock_resource_kind_not_allowed'
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);

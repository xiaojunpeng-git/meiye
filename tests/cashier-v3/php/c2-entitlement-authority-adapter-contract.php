<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码';
$files = [
    'adapter' => $backend . '/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php',
    'exception' => $backend . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionAuthorityException.php',
    'guard' => $backend . '/app/services/cashier/v3/checkout/provider/CashierV3EntitlementOccupationGuardVersionProvider.php',
    'contributor' => $backend . '/app/services/cashier/v3/checkout/provider/CashierV3EntitlementOccupationContributorVersionProvider.php',
    'recorder' => $backend . '/app/services/cashier/v3/event/CashierV3BusinessEventRecorder.php',
    'workspace' => $backend . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php',
    'writer' => $backend . '/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php',
];

$passed = 0;
$failed = 0;

function authorityContractAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}\n";
}

foreach ($files as $name => $file) {
    authorityContractAssert($name . ' source exists', is_file($file));
}

$adapter = (string)file_get_contents($files['adapter']);
$guard = (string)file_get_contents($files['guard']);
$contributor = (string)file_get_contents($files['contributor']);
$recorder = (string)file_get_contents($files['recorder']);
$workspace = (string)file_get_contents($files['workspace']);
$writer = (string)file_get_contents($files['writer']);

authorityContractAssert(
    'adapter exposes a Gateway-compatible read-only discovery entry',
    strpos($adapter, 'public function discover(array $scope): array') !== false
        && strpos($adapter, "private const DISCOVERY_ACTION = 'finalize-checkout-snapshot'") !== false
        && strpos($adapter, 'requestRowForDiscovery(') !== false
        && strpos($adapter, 'requestLinesForDiscovery(') !== false
);
authorityContractAssert(
    'discovery binds the immutable checkout request to the current workspace and DataScope',
    strpos($adapter, "->where('workspace_id', \$workspaceId)") !== false
        && strpos($adapter, "->where('state_context_id', \$stateContextId)") !== false
        && strpos($adapter, "->where('tenant_id', \$dataScope->tenantId())") !== false
        && strpos($adapter, "->where('store_id', \$dataScope->forcedStoreId())") !== false
        && strpos($adapter, 'requestLinesForDiscovery(') !== false
);
authorityContractAssert(
    'discovery builds service intent from persisted checkout-line snapshots, not workspace rows',
    strpos($adapter, '$this->workspace->discoverCheckoutDraft(') === false
        && strpos($adapter, 'assertDiscoveryWorkspaceMatchesRequest(') === false
        && strpos($adapter, 'serviceIntentsFromCheckoutLines(') !== false
        && strpos($adapter, "'quantity' => (int)\$line['quantity']") !== false
        && strpos($adapter, 'craftsmen_snapshot_json') !== false
        && strpos($adapter, 'service_object') !== false
        && strpos($adapter, 'friend_counts_as_customer') !== false
        && strpos($adapter, 'is_experience') !== false
);
authorityContractAssert(
    'discovery supports idempotent preparation replay but excludes terminal checkout states',
    strpos($adapter, "->whereIn('request_status', ['editing', 'ready_for_submit'])") !== false
        && strpos($adapter, "->where('request_status', 'editing')") === false
);
authorityContractAssert(
    'client values are not used to construct tenant store member or completion facts',
    strpos($adapter, "\$payload['tenantId']") === false
        && strpos($adapter, "\$payload['storeId']") === false
        && strpos($adapter, "\$payload['memberId']") === false
        && strpos($adapter, "\$payload['craftsmanIds']") === false
);
authorityContractAssert(
    'discovery includes the stable entitlement occupation guard resource',
    strpos($adapter, 'CashierV3EntitlementOccupationGuardVersionProvider::KIND') !== false
        && strpos($adapter, "'occupation_guard:' . \$detailId") !== false
        && strpos($adapter, '$this->occupationGuards->discoverVersion(') !== false
);
authorityContractAssert(
    'inventory discovery is delegated to the inventory-owned adapter',
    strpos($adapter, '$this->inventory->discover($inventoryRequest, $inventoryScope)') !== false
        && strpos($adapter, 'InventoryCompletionDataScope') !== false
);
authorityContractAssert(
    'locked build starts from the locked checkout aggregate and persisted service intents',
    strpos($adapter, 'public function buildAfterGatewayLocks(array $aggregate, array $scope): array') !== false
        && strpos($adapter, "CashierV3TransactionGuard::assertInTransaction('entitlementCompletionAuthorityAdapter')") !== false
        && strpos($adapter, 'serviceIntentsFromCheckoutLines(') !== false
        && strpos($adapter, '$this->assertLockedAggregate(') !== false
);
authorityContractAssert(
    'locked workspace service intents retain the authoritative craftsman allocation weights',
    strpos($workspace, "'craftsmanSettingsById'") !== false
        && strpos($workspace, 'craftsmanSelectionsFromSnapshot($craftsmen, $lineKey)') !== false
);
authorityContractAssert(
    'final entitlement persistence shares the card-state fingerprint boundary with the version provider',
    strpos($writer, "'card_state' => \$this->entitlementCardStateSnapshot") !== false
        && strpos($writer, 'private function entitlementCardStateSnapshot(?array $state, array $holder, array $order): ?array') !== false
        && strpos($writer, 'private function cardOperationState(int $holderId): ?array') !== false
);
authorityContractAssert(
    'final benefit-pool persistence includes the locked holder and card state in its fingerprint',
    strpos($writer, "'holder' => \$holderSnapshot") !== false
        && strpos($writer, "'card_state' => \$this->entitlementCardStateSnapshot") !== false
);
authorityContractAssert(
    'locked authorities are rebuilt from legacy entitlement source tables and shadow versions',
    strpos($adapter, "Db::name('user_card_holder')") !== false
        && strpos($adapter, "Db::name('store_order_cart_info')") !== false
        && strpos($adapter, "Db::name('store_order')") !== false
        && strpos($adapter, 'authorityForDetail(') !== false
        && strpos($adapter, 'authority_checkout_snapshot_changed') !== false
);
authorityContractAssert(
    'staff performance debt occupation and inventory providers rebuild final snapshots',
    strpos($adapter, '$this->staffProfiles->lockProfileSnapshotInTx(') !== false
        && strpos($adapter, '$this->performanceRules->snapshotAfterGatewayLock(') !== false
        && strpos($adapter, '$this->debtGuards->lockOrCreateSnapshotInTx(') !== false
        && strpos($adapter, '$this->occupations->lockSnapshotInTx(') !== false
        && strpos($adapter, '$this->inventory->lockSnapshotAfterGatewayLocks(') !== false
);
authorityContractAssert(
    'Gateway lock proof is checked against scope-aware locked version keys',
    strpos($adapter, 'CashierV3ResourceVersionServices::versionKey(') !== false
        && strpos($adapter, 'authority_gateway_context_not_locked') !== false
        && strpos($adapter, 'authority_gateway_plan_resource_not_locked') !== false
);
authorityContractAssert(
    'occupation guard lock is asserted before the Kernel plan is built',
    strpos($adapter, "'occupation_guard:' . \$detailId")
        < strpos($adapter, '$kernelPlan = CashierV3EntitlementCompletionKernel::plan(')
        && strpos($adapter, 'authority_occupation_guard_version_mismatch') !== false
);

$kernelSubsetStart = strpos($adapter, 'private function kernelResourceSubset(');
$kernelSubsetEnd = strpos($adapter, 'private function assertInventoryResourcesLocked(', $kernelSubsetStart);
$kernelSubset = $kernelSubsetStart !== false && $kernelSubsetEnd !== false
    ? substr($adapter, $kernelSubsetStart, $kernelSubsetEnd - $kernelSubsetStart)
    : '';
authorityContractAssert(
    'Kernel receives an exact resource subset and never receives the occupation guard',
    $kernelSubset !== ''
        && strpos($kernelSubset, 'entitlement_occupation_guard') === false
        && strpos($kernelSubset, "'occupation:' . \$lineId") !== false
        && strpos($kernelSubset, "'service_order'") !== false
        && strpos($kernelSubset, "'reservation'") !== false
);
authorityContractAssert(
    'locked build validates the Kernel hand-off but does not invent an event number',
    strpos($adapter, 'CashierV3EntitlementCompletionKernel::plan(') !== false
        && strpos($adapter, 'assertKernelAmountsMatchCheckout(') !== false
        && strpos($adapter, "'persistencePlan' =>") === false
        && strpos($adapter, "'inventoryProviderSnapshot' => \$inventorySnapshot") !== false
);
authorityContractAssert(
    'persistence plan is built only after a real checkout completed event exists',
    strpos($adapter, 'public function buildPersistencePlanAfterEvents(') !== false
        && strpos($adapter, 'CashierV3CheckoutSubmissionExecutionContext $executionContext') !== false
        && strpos($adapter, '$executionContext->aggregate()') !== false
        && strpos($adapter, '$executionContext->eventExecution()') !== false
        && strpos($adapter, '$executionContext->entitlementBundle()') !== false
        && strpos($adapter, '$eventExecution->emittedEvents()') !== false
        && strpos($adapter, "'event_type'] ?? '') !== 'checkout.completed'") !== false
        && strpos($adapter, "'event_key'") !== false
        && strpos($adapter, "'event_no'") !== false
        && strpos($adapter, "'business_date'] ?? '') !== (string)(\$kernelPlan['businessDate'] ?? '')") !== false
        && strpos($adapter, "'recorded_at'] ?? 0) !== (int)(\$kernelPlan['recordedAt'] ?? 0)") !== false
        && strpos($adapter, 'authority_recorded_checkout_event_mismatch') !== false
        && strpos($adapter, 'CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernelPlan, $context)') !== false
);
$persistenceStart = strpos($adapter, 'public function buildPersistencePlanAfterEvents(');
$persistenceEnd = strpos($adapter, 'private function requestRowForDiscovery(', $persistenceStart);
$persistenceMethod = $persistenceStart !== false && $persistenceEnd !== false
    ? substr($adapter, $persistenceStart, $persistenceEnd - $persistenceStart)
    : '';
authorityContractAssert(
    'compact recorder result is never treated as the checkout identity authority',
    $persistenceMethod !== ''
        && strpos($persistenceMethod, "\$recordedEvent['source_id']") === false
        && strpos($persistenceMethod, "\$recordedEvent['command_idempotency_key']") === false
        && strpos($persistenceMethod, "\$recordedEvent['tenant_id']") === false
        && strpos($persistenceMethod, "\$recordedEvent['store_id']") === false
        && strpos($persistenceMethod, "\$recordedEvent['member_id']") === false
        && strpos($persistenceMethod, "'checkoutRequestId', 'commandIdempotencyKey'") === false
        && strpos($persistenceMethod, "'documentId', 'documentNo', 'tenantNameSnapshot'") !== false
);
$recorderResultStart = strpos($recorder, 'private function recordedEventResult(');
$recorderResultEnd = strpos($recorder, 'private function explicitBusinessDate(', $recorderResultStart);
$recorderResult = $recorderResultStart !== false && $recorderResultEnd !== false
    ? substr($recorder, $recorderResultStart, $recorderResultEnd - $recorderResultStart)
    : '';
authorityContractAssert(
    'adapter receipt fields match the real BusinessEventRecorder return contract',
    $recorderResult !== ''
        && strpos($recorderResult, "'event_id'") !== false
        && strpos($recorderResult, "'event_no'") !== false
        && strpos($recorderResult, "'event_key'") !== false
        && strpos($recorderResult, "'event_type'") !== false
        && strpos($recorderResult, "'business_date'") !== false
        && strpos($recorderResult, "'occurred_at'") !== false
        && strpos($recorderResult, "'settled_at'") !== false
        && strpos($recorderResult, "'recorded_at'") !== false
        && strpos($recorderResult, "'source_id'") === false
        && strpos($recorderResult, "'command_idempotency_key'") === false
        && strpos($recorderResult, "'tenant_id'") === false
        && strpos($recorderResult, "'store_id'") === false
        && strpos($recorderResult, "'member_id'") === false
);
$immutableStart = strpos($recorder, 'private function immutableDescriptorFields(');
$immutableEnd = strpos($recorder, 'private function descriptorFromRow(', $immutableStart);
$immutableDescriptor = $immutableStart !== false && $immutableEnd !== false
    ? substr($recorder, $immutableStart, $immutableEnd - $immutableStart)
    : '';
$descriptorStart = strpos($recorder, 'private function descriptorFromRow(');
$descriptorEnd = strpos($recorder, 'private function executionDescriptorByEventKey(', $descriptorStart);
$descriptor = $descriptorStart !== false && $descriptorEnd !== false
    ? substr($recorder, $descriptorStart, $descriptorEnd - $descriptorStart)
    : '';
authorityContractAssert(
    'execution descriptor preserves recorded time as an immutable persisted identity field',
    $immutableDescriptor !== ''
        && strpos($immutableDescriptor, "'recorded_at'") !== false
        && $descriptor !== ''
        && strpos($descriptor, "'recorded_at' => (int)(\$row['recorded_at'] ?? 0)") !== false
        && strpos($persistenceMethod, "\$eventDescriptor['recorded_at']") !== false
);
authorityContractAssert(
    'guard provider owns discovery and locking but refuses generic bumps',
    strpos($guard, "public const KIND = 'entitlement_occupation_guard'") !== false
        && strpos($guard, 'public function discoverVersion(') !== false
        && strpos($guard, '$this->repository->lockOrCreateEntitlementGuard(') !== false
        && strpos($guard, 'occupation_guard_advance_owned_by_service_order_writer') !== false
);
authorityContractAssert(
    'guard provider is tenant scoped and uses the stable detail id',
    strpos($guard, 'CashierV3ResourceScope::TYPE_TENANT') !== false
        && strpos($guard, "->where('entitlement_source_detail_id', \$entitlementSourceDetailId)") !== false
        && strpos($guard, "Db::name('store_order_cart_info')") !== false
);
authorityContractAssert(
    'contributor provider covers both reservation shadow and native service-order versions',
    strpos($contributor, "public const KINDS = ['service_order', 'reservation']") !== false
        && strpos($contributor, "Db::name(ThinkPhpCashierV3ServiceOrderRepository::ORDER_TABLE)") !== false
        && strpos($contributor, "Db::name(CashierV3EntitlementOccupationProvider::TABLE)") !== false
        && strpos($contributor, 'reservation_occupation_version_conflict') !== false
);
authorityContractAssert(
    'global reservation provider accepts stable zero-detail authorities without inventing an occupation',
    strpos($contributor, "\$detailId = (int)(\$row['cart_info_id'] ?? -1)") !== false
        && strpos($contributor, '|| $detailId < 0') !== false
        && strpos($contributor, "(int)(\$row['cart_info_id'] ?? 0) <= 0") === false
        && strpos($contributor, "'cartInfoId' => \$detailId") !== false
        && strpos($contributor, "'entitlement_source_detail_id_snapshot' => \$detailId") !== false
        && strpos($contributor, "'reservation_authority_discovered'") !== false
);
authorityContractAssert(
    'positive reservation details retain the entitlement occupation shadow semantics',
    strpos($contributor, "\$detailId > 0") !== false
        && strpos($contributor, "? 'occupation_discovered'") !== false
        && strpos($contributor, "'cartInfoId' => \$detailId") !== false
        && strpos($contributor, "\$this->reservationVersion(") !== false
);
authorityContractAssert(
    'global contributor provider remains active-only cross-store fail-closed and read-only',
    strpos($contributor, "in_array((int)(\$row['status'] ?? -999), [0, 1, 3], true)") !== false
        && strpos($contributor, "(int)(\$row['is_del'] ?? 1) !== 0") !== false
        && strpos($contributor, "(int)(\$row['is_system_del'] ?? 1) !== 0") !== false
        && strpos($contributor, 'cross_store_occupation_contributor_fail_closed') !== false
        && strpos($contributor, 'occupation_contributor_advance_owned_by_source_writer') !== false
);
authorityContractAssert(
    'cross-store active contributors fail closed instead of being hidden',
    strpos($contributor, 'cross_store_occupation_contributor_fail_closed') !== false
        && strpos($adapter, 'cross_store_occupation_contributor_fail_closed') !== false
        && strpos($adapter, 'assertOccupationSourcePresent(') !== false
);
authorityContractAssert(
    'contributor generic version bump is fail closed',
    strpos($contributor, 'occupation_contributor_advance_owned_by_source_writer') !== false
);
authorityContractAssert(
    'adapter does not register actions providers routes or shared front-end behavior',
    strpos($adapter, 'registerProvider(') === false
        && strpos($adapter, 'ActionManifest') === false
        && strpos($adapter, 'CashierV3CashierModule') === false
        && strpos($adapter, 'route(') === false
);

echo sprintf("entitlement authority adapter contract: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);

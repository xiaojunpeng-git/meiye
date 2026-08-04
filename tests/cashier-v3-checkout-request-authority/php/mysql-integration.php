<?php
declare(strict_types=1);

require __DIR__ . '/mysql-fixture.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\settlement\CashierV3CheckoutRequestVersionProvider;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\CashierV3CheckoutSourceAuthorityLoader;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutRequestRepository;
use think\facade\Db;

$passed = 0;
$failed = 0;
function checkoutMysqlOk(string $name, bool $condition, string $detail = ''): void
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
function checkoutMysqlReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CheckoutSettlementContractException $exception) {
        return $exception->reason();
    } catch (Throwable $throwable) {
        return get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

$repository = new ThinkPhpCashierV3CheckoutRequestRepository();
$projectionService = new CashierV3CheckoutProjectionServices($repository);
$loader = new CashierV3CheckoutSourceAuthorityLoader();
$provider = new CashierV3CheckoutRequestVersionProvider();
$operator = checkoutAuthorityOperator();
$scope = checkoutAuthorityScope();
$secret = 'checkout-authority-integration-secret-32-bytes';
$initialSources = checkoutAuthoritySources([
    ['service_order', 'SO-501', 'service_origin', 31],
    ['room', '12', 'service_room', 12],
]);

checkoutAuthorityReset();
Db::name('cashier_v3_resource_version')
    ->where('scope_type', 'store')
    ->where('scope_id', '7')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', 'ws:7:21:checkout-authority-state')
    ->delete();
Db::name('cashier_v3_resource_version')->insert([
    'scope_type' => 'store',
    'scope_id' => '7',
    'resource_kind' => 'cashier_workspace',
    'resource_id' => 'ws:7:21:checkout-authority-state',
    'current_version' => 1,
    'last_action' => '',
    'add_time' => time(),
    'update_time' => time(),
]);
checkoutMysqlOk('workspace without an editing checkout request projects null',
    $projectionService->readCurrent(
        'ws:7:21:checkout-authority-state',
        'checkout-authority-state',
        $operator,
        $scope
    ) === null);
$createCommand = checkoutAuthorityCommand(1);
$snapshotOne = checkoutAuthoritySnapshot(1);
$created = Db::transaction(static function () use (
    $repository,
    $operator,
    $scope,
    $secret,
    $createCommand,
    $snapshotOne,
    $initialSources
): array {
    $current = $repository->lockCurrentForKernelInTx(
        '',
        $createCommand['idempotencyKey'],
        $operator,
        $scope
    );
    $plan = CashierV3CheckoutSettlementKernel::saveDraft(
        $createCommand,
        $snapshotOne,
        $current,
        $secret
    );
    return $repository->persistKernelPlanInTx($plan, $initialSources, $operator, $scope);
});
checkoutMysqlOk('new aggregate inserts request line and exact source collection',
    $created['replayed'] === false
        && $created['requestVersion'] === 1
        && checkoutAuthorityCount('cashier_v3_checkout_request') === 1
        && checkoutAuthorityCount('cashier_v3_checkout_line_draft') === 2
        && checkoutAuthorityCount('cashier_v3_checkout_payment_draft') === 0
        && checkoutAuthorityCount('cashier_v3_checkout_source_reference') === 2);
checkoutMysqlOk('affected-row result is exact for insert',
    $created['affected']['requestRows'] === 1
        && $created['affected']['lineRowsInserted'] === 2
        && $created['affected']['sourceRowsInserted'] === 2);
$persistedEntitlementLine = Db::name('cashier_v3_checkout_line_draft')
    ->where('request_id', $created['requestId'])
    ->where('line_role', 'entitlement_service')
    ->find();
checkoutMysqlOk('entitlement draft reads back exact holder detail and project identities',
    is_array($persistedEntitlementLine)
        && (int)$persistedEntitlementLine['source_id'] === 701
        && (int)$persistedEntitlementLine['entitlement_source_detail_id'] === 1701
        && (int)$persistedEntitlementLine['project_id'] === 502
        && preg_match('/^[0-9a-f]{64}$/D', (string)$persistedEntitlementLine['line_fingerprint']) === 1);

$entitlementDetailDriftReason = '';
try {
    Db::transaction(static function () use (
        $repository,
        $operator,
        $scope,
        $secret,
        $createCommand,
        $snapshotOne,
        $initialSources,
        $created
    ): void {
        Db::name('cashier_v3_checkout_line_draft')
            ->where('request_id', $created['requestId'])
            ->where('line_role', 'entitlement_service')
            ->update(['entitlement_source_detail_id' => 1702]);
        $current = $repository->lockCurrentForKernelInTx(
            '',
            $createCommand['idempotencyKey'],
            $operator,
            $scope
        );
        $plan = CashierV3CheckoutSettlementKernel::saveDraft(
            $createCommand,
            $snapshotOne,
            $current,
            $secret
        );
        $repository->persistKernelPlanInTx($plan, $initialSources, $operator, $scope);
    });
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $entitlementDetailDriftReason = $exception->reason();
}
checkoutMysqlOk('idempotent readback rejects entitlement source detail drift and rolls it back',
    $entitlementDetailDriftReason === 'checkout_idempotent_line_drift'
        && (int)Db::name('cashier_v3_checkout_line_draft')
            ->where('request_id', $created['requestId'])
            ->where('line_role', 'entitlement_service')
            ->value('entitlement_source_detail_id') === 1701);
Db::name('cashier_v3_resource_version')
    ->where('scope_type', 'store')
    ->where('scope_id', '7')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', 'ws:7:21:checkout-authority-state')
    ->update(['current_version' => 2, 'last_action' => 'prepare-checkout']);
$currentProjection = $projectionService->readCurrent(
    'ws:7:21:checkout-authority-state',
    'checkout-authority-state',
    $operator,
    $scope
);
checkoutMysqlOk('latest editing request projects complete checkout recovery state',
    is_array($currentProjection)
        && $currentProjection['checkoutRequestId'] === $created['requestId']
        && $currentProjection['checkoutRequestVersion'] === 1
        && $currentProjection['preparationTokenRole'] === 'projection_correlation_only'
        && count($currentProjection['orderLines']) === 2
        && count($currentProjection['payment']['methods']) === 8
        && $currentProjection['payment']['methods'][7]['id'] === 'old_card_entry'
        && $currentProjection['payment']['methods'][7]['canAdd'] === false
        && $currentProjection['commandContexts'][1] === [
            'kind' => 'checkout_request',
            'id' => $created['requestId'],
            'expectedVersion' => 1,
        ]
        && $currentProjection['commandContexts'][2] === [
            'kind' => 'service_order',
            'id' => 'SO-501',
            'expectedVersion' => 31,
        ]
        && $currentProjection['commandContexts'][3] === [
            'kind' => 'room',
            'id' => '12',
            'expectedVersion' => 12,
        ]);
Db::name('cashier_v3_resource_version')
    ->where('scope_type', 'store')
    ->where('scope_id', '7')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', 'ws:7:21:checkout-authority-state')
    ->update(['current_version' => 3, 'last_action' => 'cart_changed_after_prepare']);
checkoutMysqlOk('checkout projection returns null after the workspace advances beyond preparation',
    $projectionService->readCurrent(
        'ws:7:21:checkout-authority-state',
        'checkout-authority-state',
        $operator,
        $scope
    ) === null);
Db::name('cashier_v3_resource_version')
    ->where('scope_type', 'store')
    ->where('scope_id', '7')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', 'ws:7:21:checkout-authority-state')
    ->update(['current_version' => 2, 'last_action' => 'prepare-checkout']);

$loadedOne = $loader($created['requestId']);
checkoutMysqlOk('authority loader returns stable Gateway sources and request version',
    $loadedOne['requestVersion'] === 1
        && $loadedOne['boundRequestVersion'] === 1
        && $loadedOne['sources'] === [
            ['kind' => 'service_order', 'id' => 'SO-501'],
            ['kind' => 'room', 'id' => '12'],
        ]
        && $loadedOne['references'][0]['sourceVersion'] === 31
        && $loadedOne['references'][1]['sourceVersion'] === 12);

$replay = Db::transaction(static function () use (
    $repository,
    $operator,
    $scope,
    $secret,
    $createCommand,
    $snapshotOne,
    $initialSources
): array {
    $current = $repository->lockCurrentForKernelInTx(
        '',
        $createCommand['idempotencyKey'],
        $operator,
        $scope
    );
    $plan = CashierV3CheckoutSettlementKernel::saveDraft(
        $createCommand,
        $snapshotOne,
        $current,
        $secret
    );
    return $repository->persistKernelPlanInTx($plan, $initialSources, $operator, $scope);
});
checkoutMysqlOk('same creation idempotency and fingerprint replays without writes',
    $replay['replayed'] === true
        && $replay['persistenceMode'] === 'none'
        && array_sum($replay['affected']) === 0
        && checkoutAuthorityCount('cashier_v3_checkout_request') === 1
        && checkoutAuthorityCount('cashier_v3_checkout_source_reference') === 2);

$changedSameKey = checkoutAuthoritySnapshot(2);
$sameKeyConflict = Db::transaction(static function () use (
    $repository,
    $operator,
    $scope,
    $secret,
    $createCommand,
    $changedSameKey
): string {
    $current = $repository->lockCurrentForKernelInTx(
        '',
        $createCommand['idempotencyKey'],
        $operator,
        $scope
    );
    return checkoutMysqlReason(static function () use (
        $createCommand,
        $changedSameKey,
        $current,
        $secret
    ): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            $createCommand,
            $changedSameKey,
            $current,
            $secret
        );
    });
});
checkoutMysqlOk('same creation idempotency with another fingerprint conflicts',
    $sameKeyConflict === 'checkout_idempotency_key_conflict');

$staleCommand = checkoutAuthorityCommand(2, $created['requestId'], 1);
$stalePlan = Db::transaction(static function () use (
    $repository,
    $operator,
    $scope,
    $secret,
    $staleCommand,
    $changedSameKey,
    $createCommand
): array {
    $current = $repository->lockCurrentForKernelInTx(
        $staleCommand['requestId'],
        $createCommand['idempotencyKey'],
        $operator,
        $scope
    );
    return CashierV3CheckoutSettlementKernel::saveDraft(
        $staleCommand,
        $changedSameKey,
        $current,
        $secret
    );
});

$casCommand = checkoutAuthorityCommand(3, $created['requestId'], 1);
$replacementSources = checkoutAuthoritySources([
    ['reservation', 'RES-701', 'reservation_origin'],
]);
$cas = Db::transaction(static function () use (
    $repository,
    $operator,
    $scope,
    $secret,
    $casCommand,
    $changedSameKey,
    $replacementSources,
    $createCommand
): array {
    $current = $repository->lockCurrentForKernelInTx(
        $casCommand['requestId'],
        $createCommand['idempotencyKey'],
        $operator,
        $scope
    );
    $plan = CashierV3CheckoutSettlementKernel::saveDraft(
        $casCommand,
        $changedSameKey,
        $current,
        $secret
    );
    return $repository->persistKernelPlanInTx($plan, $replacementSources, $operator, $scope);
});
checkoutMysqlOk('CAS advances one version and precisely replaces child and source sets',
    $cas['requestVersion'] === 2
        && $cas['affected']['requestRows'] === 1
        && $cas['affected']['lineRowsDeleted'] === 2
        && $cas['affected']['lineRowsInserted'] === 2
        && $cas['affected']['sourceRowsDeleted'] === 2
        && $cas['affected']['sourceRowsInserted'] === 1
        && Db::name('cashier_v3_checkout_source_reference')
            ->where('source_kind', 'reservation')->where('source_id', 'RES-701')->count() === 1);

$loadedTwo = $loader($created['requestId']);
checkoutMysqlOk('loader exposes legal source drift with the new request version',
    $loadedTwo['requestVersion'] === 2
        && $loadedTwo['sourceSetFingerprint'] !== $loadedOne['sourceSetFingerprint']
        && $loadedTwo['sources'] === [['kind' => 'reservation', 'id' => 'RES-701']]);

$staleReason = Db::transaction(static function () use (
    $repository,
    $stalePlan,
    $initialSources,
    $operator,
    $scope
): string {
    return checkoutMysqlReason(static function () use (
        $repository,
        $stalePlan,
        $initialSources,
        $operator,
        $scope
    ): void {
        $repository->persistKernelPlanInTx($stalePlan, $initialSources, $operator, $scope);
    });
});
checkoutMysqlOk('stale CAS is rejected without replacing current sources',
    $staleReason === 'checkout_request_version_conflict'
        && $loader($created['requestId'])['sources'] === [['kind' => 'reservation', 'id' => 'RES-701']]);

$resolved = $provider->resolveScopeWithDataScope(
    'checkout_request',
    $created['requestId'],
    $operator,
    $scope
);
checkoutMysqlOk('DataScoped provider resolves the row real store scope',
    $resolved instanceof CashierV3ResourceScope
        && $resolved->type() === CashierV3ResourceScope::TYPE_STORE
        && $resolved->id() === '7');
checkoutMysqlOk('DataScoped provider hides cross-store and NONE scope rows',
    $provider->resolveScopeWithDataScope(
        'checkout_request',
        $created['requestId'],
        checkoutAuthorityOperator(8),
        checkoutAuthorityScope(8)
    ) === null
        && $provider->resolveScopeWithDataScope(
            'checkout_request',
            $created['requestId'],
            $operator,
            checkoutAuthorityScope(7, '0', CashierV3DataScopeContext::MODE_NONE)
        ) === null);

$versionBump = Db::transaction(static function () use (
    $provider,
    $resolved,
    $created,
    $scope
): array {
    $locked = $provider->lockAndReadVersionWithDataScope(
        $resolved,
        'checkout_request',
        $created['requestId'],
        $scope
    );
    $affected = (int)Db::name('cashier_v3_checkout_request')
        ->where('request_id', $created['requestId'])
        ->where('request_version', $locked)
        ->update(['request_version' => Db::raw('request_version + 1')]);
    if ($affected !== 1) {
        throw new RuntimeException('domain request CAS fixture failed');
    }
    $next = $provider->bumpVersionWithDataScope(
        $resolved,
        'checkout_request',
        $created['requestId'],
        'test_version_bump',
        $scope
    );
    return [$locked, $next];
});
checkoutMysqlOk('Gateway provider observes the domain-owned request CAS without a second bump',
    $versionBump === [2, 3]
        && $loader($created['requestId'])['requestVersion'] === 3);

$driftRejected = false;
try {
    Db::transaction(static function () use ($loader, $created): void {
        Db::name('cashier_v3_checkout_source_reference')
            ->where('request_id', $created['requestId'])
            ->update(['source_role' => 'tampered_role']);
        $loader($created['requestId']);
    });
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $driftRejected = $exception->reason() === 'checkout_source_reference_fingerprint_drift';
}
checkoutMysqlOk('authority loader rejects source-row drift and transaction rolls it back',
    $driftRejected
        && (string)Db::name('cashier_v3_checkout_source_reference')
            ->where('request_id', $created['requestId'])->value('source_role')
            === 'reservation_origin');

$crossStoreRejected = false;
try {
    Db::transaction(static function () use ($loader, $created): void {
        Db::name('cashier_v3_checkout_source_reference')
            ->where('request_id', $created['requestId'])
            ->update(['store_id' => 8]);
        $loader($created['requestId']);
    });
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $crossStoreRejected = $exception->reason() === 'checkout_source_scope_mismatch';
}
checkoutMysqlOk('authority loader explicitly rejects a cross-store reference and rolls it back',
    $crossStoreRejected
        && (int)Db::name('cashier_v3_checkout_source_reference')
            ->where('request_id', $created['requestId'])->value('store_id') === 7);

$failureCommand = checkoutAuthorityCommand(100);
$failureSnapshot = checkoutAuthoritySnapshot(10);
$failurePlan = CashierV3CheckoutSettlementKernel::saveDraft(
    $failureCommand,
    $failureSnapshot,
    null,
    $secret
);
Db::execute('DROP TRIGGER IF EXISTS `checkout_source_test_failure`');
Db::execute("CREATE TRIGGER `checkout_source_test_failure`
  BEFORE INSERT ON `eb_cashier_v3_checkout_source_reference`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CHECKOUT_SOURCE_TEST_FAILURE'");
$failedAtSource = false;
try {
    Db::transaction(static function () use (
        $repository,
        $failurePlan,
        $initialSources,
        $operator,
        $scope
    ): void {
        $repository->persistKernelPlanInTx($failurePlan, $initialSources, $operator, $scope);
    });
} catch (Throwable $throwable) {
    $failedAtSource = strpos($throwable->getMessage(), 'CHECKOUT_SOURCE_TEST_FAILURE') !== false;
} finally {
    Db::execute('DROP TRIGGER IF EXISTS `checkout_source_test_failure`');
}
checkoutMysqlOk('source insert failure rolls request child and sources back together',
    $failedAtSource
        && Db::name('cashier_v3_checkout_request')
            ->where('request_id', $failurePlan['requestId'])->count() === 0
        && Db::name('cashier_v3_checkout_line_draft')
            ->where('request_id', $failurePlan['requestId'])->count() === 0
        && Db::name('cashier_v3_checkout_source_reference')
            ->where('request_id', $failurePlan['requestId'])->count() === 0);

checkoutMysqlOk('eventless repository never creates business side-effect tables',
    $created['businessEffects'] === [
        'checkoutSucceeded' => false,
        'saleFacts' => 0,
        'paymentCollectedFacts' => 0,
        'balanceMutations' => 0,
        'debtMutations' => 0,
        'entitlementMutations' => 0,
        'performanceFacts' => 0,
        'businessEvents' => 0,
        'outboxRows' => 0,
    ]);

echo "CHECKOUT_REQUEST_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

require __DIR__ . '/mysql-fixture.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutResourcePlanLoader;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResourcePlanRepository;
use think\facade\Db;

$passed = 0;
$failed = 0;
function resourceMysqlOk(string $name, bool $condition, string $detail = ''): void
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

checkoutResourceReset();
checkoutResourceInsertRequest(1, 'ready_for_submit');
$repository = new ThinkPhpCashierV3CheckoutResourcePlanRepository();
$loader = new CashierV3CheckoutResourcePlanLoader();
$planV1 = checkoutResourcePlan(1, 1);
$persisted = Db::transaction(static function () use ($repository, $planV1): array {
    return $repository->persistInTx(checkoutResourcePlanRequestId(), $planV1, 1785283201);
});
resourceMysqlOk('resource plan persists one header and one row per physical resource',
    $persisted['replayed'] === false
        && $persisted['resourceCount'] === 3
        && $persisted['roleCount'] === 4
        && (int)Db::name('cashier_v3_checkout_resource_plan')->count() === 1
        && (int)Db::name('cashier_v3_checkout_resource_plan_row')->count() === 3);
$memberRow = Db::name('cashier_v3_checkout_resource_plan_row')
    ->where('resource_kind', 'member')->where('resource_id', '93')->find();
resourceMysqlOk('multi-role physical row is canonical and mutate dominates read',
    json_decode((string)$memberRow['roles_json'], true) === ['checkout_member', 'member']
        && (string)$memberRow['access_mode'] === 'mutate'
        && (int)$memberRow['role_count'] === 2);

$loadedV1 = $loader(checkoutResourcePlanRequestId());
resourceMysqlOk('read-only loader returns exact current bound plan',
    $loadedV1['boundRequestVersion'] === 1
        && $loadedV1['tenantId'] === '0'
        && $loadedV1['storeId'] === 7
        && $loadedV1['resourcePlanFingerprint'] === $planV1->fingerprint()
        && $loadedV1['resourceCount'] === 3
        && $loadedV1['roleCount'] === 4
        && count($loadedV1['trustedContexts']) === 3
        && array_column(array_slice($loadedV1['resources'], 1), 'id') === ['2', '10']);

$replay = Db::transaction(static function () use ($repository, $planV1): array {
    return $repository->persistInTx(checkoutResourcePlanRequestId(), $planV1, 1785283201);
});
resourceMysqlOk('same request version and plan replay without duplicate rows',
    $replay['replayed'] === true
        && (int)Db::name('cashier_v3_checkout_resource_plan')->count() === 1
        && (int)Db::name('cashier_v3_checkout_resource_plan_row')->count() === 3);

$conflictPlan = checkoutResourcePlan(1, 2);
$conflictReason = checkoutResourceReason(static function () use ($repository, $conflictPlan): void {
    Db::transaction(static function () use ($repository, $conflictPlan): void {
        $repository->persistInTx(checkoutResourcePlanRequestId(), $conflictPlan, 1785283202);
    });
});
resourceMysqlOk('same request version with another authority plan conflicts',
    $conflictReason === 'checkout_resource_plan_idempotency_conflict');

$tamperReason = '';
try {
    Db::transaction(static function () use ($loader, &$tamperReason): void {
        Db::name('cashier_v3_checkout_resource_plan_row')
            ->where('resource_kind', 'member')
            ->update(['authority_fingerprint' => str_repeat('f', 64)]);
        $tamperReason = checkoutResourceReason(static function () use ($loader): void {
            $loader(checkoutResourcePlanRequestId());
        });
        throw new RuntimeException('rollback_tamper_probe');
    });
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'rollback_tamper_probe') {
        throw $exception;
    }
}
resourceMysqlOk('loader rejects resource-row authority drift',
    in_array($tamperReason, [
        'checkout_resource_plan_header_fingerprint_drift',
        'checkout_resource_plan_row_fingerprint_drift',
    ], true));

Db::name('cashier_v3_checkout_request')
    ->where('request_id', checkoutResourcePlanRequestId())
    ->update(['request_version' => 2, 'request_status' => 'editing']);
resourceMysqlOk('loader returns no stale plan after request version or status changes',
    $loader(checkoutResourcePlanRequestId()) === []);

$staleTransitionReasons = [];
foreach (['invalidate', 'consume'] as $transition) {
    $staleTransitionReasons[$transition] = checkoutResourceReason(
        static function () use ($repository, $planV1, $transition): void {
            Db::transaction(static function () use ($repository, $planV1, $transition): void {
                if ($transition === 'invalidate') {
                    $repository->invalidateInTx(
                        checkoutResourcePlanRequestId(), '0', 7, 1, 'stale_invalidation'
                    );
                    return;
                }
                $repository->markConsumedInTx(
                    checkoutResourcePlanRequestId(), '0', 7, 1, $planV1->fingerprint()
                );
            });
        }
    );
}
resourceMysqlOk('request-version lock rejects stale invalidation and consumption',
    $staleTransitionReasons === [
        'invalidate' => 'checkout_resource_plan_request_version_conflict',
        'consume' => 'checkout_resource_plan_request_version_conflict',
    ]
        && (string)Db::name('cashier_v3_checkout_resource_plan')
            ->where('bound_request_version', 1)->value('plan_status') === 'active');

$supersededByCas = Db::transaction(static function () use ($repository): array {
    return $repository->supersedeActiveBeforeVersionInTx(
        checkoutResourcePlanRequestId(), '0', 7, 2, 'request_version_advanced'
    );
});
$supersededReplay = Db::transaction(static function () use ($repository): array {
    return $repository->supersedeActiveBeforeVersionInTx(
        checkoutResourcePlanRequestId(), '0', 7, 2, 'request_version_advanced'
    );
});
resourceMysqlOk('request CAS supersedes old active headers and preserves immutable rows',
    $supersededByCas['affected'] === 1
        && $supersededReplay['affected'] === 0
        && (string)Db::name('cashier_v3_checkout_resource_plan')
            ->where('bound_request_version', 1)->value('plan_status') === 'superseded'
        && (int)Db::name('cashier_v3_checkout_resource_plan_row')->count() === 3);

$wrongCasReason = checkoutResourceReason(static function () use ($repository): void {
    Db::transaction(static function () use ($repository): void {
        $repository->supersedeActiveBeforeVersionInTx(
            checkoutResourcePlanRequestId(), '0', 7, 3, 'request_version_advanced'
        );
    });
});
resourceMysqlOk('supersession requires checkout request current version to equal nextVersion',
    $wrongCasReason === 'checkout_resource_plan_request_version_conflict');

Db::name('cashier_v3_checkout_request')
    ->where('request_id', checkoutResourcePlanRequestId())
    ->update(['request_status' => 'ready_for_submit']);
$planV2 = checkoutResourcePlan(2, 2);
$persistedV2 = Db::transaction(static function () use ($repository, $planV2): array {
    return $repository->persistInTx(checkoutResourcePlanRequestId(), $planV2, 1785283202);
});
$oldStatus = (string)Db::name('cashier_v3_checkout_resource_plan')
    ->where('bound_request_version', 1)->value('plan_status');
resourceMysqlOk('new exact request version supersedes old header without deleting audit rows',
    $persistedV2['boundRequestVersion'] === 2
        && $oldStatus === 'superseded'
        && (int)Db::name('cashier_v3_checkout_resource_plan')->count() === 2
        && (int)Db::name('cashier_v3_checkout_resource_plan_row')->count() === 6);

$invalidated = Db::transaction(static function () use ($repository): array {
    return $repository->invalidateInTx(
        checkoutResourcePlanRequestId(), '0', 7, 2, 'payment_draft_changed'
    );
});
resourceMysqlOk('invalidation preserves rows and removes plan from active loader',
    $invalidated['affected'] === 1
        && $loader(checkoutResourcePlanRequestId()) === []
        && (int)Db::name('cashier_v3_checkout_resource_plan_row')->count() === 6);

Db::name('cashier_v3_checkout_request')
    ->where('request_id', checkoutResourcePlanRequestId())
    ->update(['request_version' => 3, 'request_status' => 'ready_for_submit']);
$planV3 = checkoutResourcePlan(3, 3);
Db::transaction(static function () use ($repository, $planV3): void {
    $repository->persistInTx(checkoutResourcePlanRequestId(), $planV3, 1785283203);
});
$consumed = Db::transaction(static function () use ($repository, $planV3): array {
    return $repository->markConsumedInTx(
        checkoutResourcePlanRequestId(), '0', 7, 3, $planV3->fingerprint()
    );
});
resourceMysqlOk('consumption is fingerprint-bound idempotent metadata transition',
    $consumed['affected'] === 1
        && Db::transaction(static function () use ($repository, $planV3): array {
            return $repository->markConsumedInTx(
                checkoutResourcePlanRequestId(), '0', 7, 3, $planV3->fingerprint()
            );
        })['affected'] === 0
        && $loader(checkoutResourcePlanRequestId()) === []);

$wrongBound = checkoutResourcePlan(4, 4);
$wrongBoundReason = checkoutResourceReason(static function () use ($repository, $wrongBound): void {
    Db::transaction(static function () use ($repository, $wrongBound): void {
        $repository->persistInTx(checkoutResourcePlanRequestId(), $wrongBound, 1785283204);
    });
});
resourceMysqlOk('repository rejects plan not exactly bound to request version',
    $wrongBoundReason === 'checkout_resource_plan_request_not_ready');

echo "CHECKOUT_RESOURCE_PLAN_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

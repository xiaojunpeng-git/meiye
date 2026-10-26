<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeFactory;
use app\services\cashier\v3\CashierV3PermissionSnapshotServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\registry\CashierV3PermissionGuard;
use app\services\organization\EmployeeDataScopeServices;

/** This fixture isolates the V3 authorization boundary from any production database. */
final class ReservationScopeFixture extends EmployeeDataScopeServices
{
    public function isSuperAdmin(array $adminInfo): bool { return false; }
    public function resolveEffectiveStoreIds(int $employeeId, int $contextStoreId = 0, array $adminInfo = []) { return [179]; }
}

/** The test varies the current mobile grant independently of the visible menu projection. */
final class ReservationFeatureFixture extends CashierV3FeatureResolver
{
    public $mobileGranted = true;
    public function resolveGrantedFeatures(array $operatorProfile): array
    {
        return $this->mobileGranted ? ['cashier.v3.reservation'] : [];
    }
    public function resolveVisibleFeatures(array $operatorProfile): array
    {
        return ['cashier.v3.reservation', 'cashier.v3.cashier'];
    }
}

function reservationPermissionAssert(string $label, bool $passed): void
{
    if (!$passed) throw new RuntimeException($label);
    echo "PASS {$label}\n";
}

function reservationPermissionResult(CashierV3PermissionGuard $guard, $scope, string $action): bool
{
    try {
        $guard->assertAllowed([
            'type' => 'command', 'action' => $action,
            'permissionPolicyId' => 'policy:reservation_operation',
        ], $scope);
        return true;
    } catch (CashierV3CommandException $error) {
        return false;
    }
}

$features = new ReservationFeatureFixture();
$factory = new CashierV3DataScopeFactory($features, new ReservationScopeFixture());
$guard = new CashierV3PermissionGuard();
$profile = [
    'employee_id' => 21, 'store_id' => 179, 'level' => 1, 'admin_type' => 3,
    'roles' => [], '_cashier_v3_delegated' => 1,
];
$ordinary = $factory->build(179, 21, $profile);
reservationPermissionAssert('ordinary delegated session remains read-only',
    $ordinary->isReadOnlySession() && !reservationPermissionResult($guard, $ordinary, 'create-reservation'));
$missingWrite = $factory->build(179, 21, $profile + ['_trusted_mobile_merchant_session' => 1]);
reservationPermissionAssert('trusted mobile read without write origin cannot create',
    !reservationPermissionResult($guard, $missingWrite, 'create-reservation'));
$missingTrust = $factory->build(179, 21, $profile + ['_mobile_merchant_write' => 1]);
reservationPermissionAssert('write hint without trusted mobile origin cannot create',
    !reservationPermissionResult($guard, $missingTrust, 'create-reservation'));

$mobile = $profile + ['_trusted_mobile_merchant_session' => 1, '_mobile_merchant_write' => 1];
$mobileScope = $factory->build(179, 21, $mobile);
reservationPermissionAssert('trusted mobile reservation grant stays read-only for unrelated V3 commands',
    $mobileScope->isReadOnlySession() && reservationPermissionResult($guard, $mobileScope, 'create-reservation')
    && !reservationPermissionResult($guard, $mobileScope, 'prepare-reservation-checkout')
    && !reservationPermissionResult($guard, $mobileScope, 'select-reservation-member'));
foreach (['update-reservation', 'cancel-reservation', 'confirm-reservation', 'reject-reservation', 'start-reservation-service', 'end-reservation-service'] as $action) {
    reservationPermissionAssert('mobile reservation action ' . $action,
        reservationPermissionResult($guard, $mobileScope, $action));
}
$features->mobileGranted = false;
$revoked = $factory->build(179, 21, $mobile);
reservationPermissionAssert('revoked mobile grant cannot write',
    !$revoked->hasFeature('cashier.v3.reservation')
    && !reservationPermissionResult($guard, $revoked, 'create-reservation'));
$features->mobileGranted = true;
$otherStore = $factory->build(180, 21, $mobile);
reservationPermissionAssert('selected store outside data scope cannot write',
    !reservationPermissionResult($guard, $otherStore, 'create-reservation'));

$snapshot = new CashierV3PermissionSnapshotServices($factory);
$rebuild = new ReflectionMethod($snapshot, 'rebuildOperatorProfile');
$rebuilt = $rebuild->invoke($snapshot, ['id' => 0, 'employee_id' => 21, 'store_id' => 179, 'level' => 1], $mobile);
$rebuiltScope = $factory->build(179, 21, $rebuilt);
reservationPermissionAssert('transaction snapshot retains the trusted mobile origin',
    reservationPermissionResult($guard, $rebuiltScope, 'create-reservation'));
$ordinaryRebuilt = $rebuild->invoke($snapshot, ['id' => 0, 'employee_id' => 21, 'store_id' => 179, 'level' => 1], $profile);
reservationPermissionAssert('transaction snapshot does not promote ordinary delegated sessions',
    empty($ordinaryRebuilt['_trusted_mobile_merchant_session'])
    && !reservationPermissionResult($guard, $factory->build(179, 21, $ordinaryRebuilt), 'create-reservation'));

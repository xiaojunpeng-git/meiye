<?php

require_once '/tests/cashier-v3/lib/boot-env.php';
require_once '/var/www/html/vendor/autoload.php';
require_once '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementActivationReadinessEvaluator;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementDebtGuardProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContractException;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContracts;
use app\services\cashier\v3\checkout\provider\CashierV3InventoryCompletionReadinessProbe;
use app\services\cashier\v3\checkout\provider\CashierV3LegacyDebtGuardIntegrationProbe;
use app\services\cashier\v3\checkout\provider\CashierV3ServiceOrderOccupationAuthority;
use app\services\cashier\v3\checkout\provider\CashierV3StaffProfileProvider;
use app\services\cashier\v3\checkout\provider\CashierV3StaffTypeAuthority;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

$passed = 0;
$failed = 0;
function c2piAssert(string $name, bool $condition, string $detail = ''): void
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

function c2piReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3EntitlementProviderContractException $exception) {
        return $exception->reason();
    } catch (\Throwable $exception) {
        return 'UNEXPECTED:' . get_class($exception) . ':' . $exception->getMessage();
    }
    return '';
}

function c2piOperator(int $storeId = 8, string $tenantId = '0'): CashierV3OperatorScope
{
    return new CashierV3OperatorScope($storeId, 10, '1', $tenantId);
}

function c2piScope(
    int $storeId = 8,
    string $tenantId = '0',
    string $mode = CashierV3DataScopeContext::MODE_STORES,
    int $employeeId = 1010
): CashierV3DataScopeContext {
    $stores = $mode === CashierV3DataScopeContext::MODE_ALL ? null : [$storeId];
    return new CashierV3DataScopeContext(
        10,
        $employeeId,
        $storeId,
        $tenantId,
        '1',
        $stores,
        $mode,
        [],
        $mode === CashierV3DataScopeContext::MODE_ALL,
        $mode === CashierV3DataScopeContext::MODE_ALL ? 'test' : '',
        'permission-v1',
        ['cashier.v3.cashier', 'cashier.v3.writeoff'],
        ['id' => 10, 'employee_id' => $employeeId]
    );
}

final class C2ProviderTestStaffTypeAuthority implements CashierV3StaffTypeAuthority
{
    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::STAFF_TYPE_AUTHORITY;
    }

    public function readinessStatus(): array
    {
        return ['ready' => true, 'reasons' => []];
    }

    public function lockTypeSnapshotInTx(
        array $staff,
        array $employee,
        CashierV3DataScopeContext $dataScope
    ): array {
        $row = Db::name('test_staff_type_authority')
            ->where('employee_id', (int)$employee['id'])
            ->lock(true)
            ->find();
        if (!$row) {
            throw new CashierV3EntitlementProviderContractException('test_staff_type_missing');
        }
        return [
            'employeeTypeCodeSnapshot' => (string)$row['type_code'],
            'employeeTypeAuthorityVersion' => (int)$row['version'],
        ];
    }
}

final class C2ProviderTestServiceAuthority implements CashierV3ServiceOrderOccupationAuthority
{
    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::SERVICE_ORDER_OCCUPATION;
    }

    public function readinessStatus(): array
    {
        return ['ready' => true, 'reasons' => []];
    }

    public function lockContributorsInTx(array $request, CashierV3DataScopeContext $dataScope): array
    {
        $rows = Db::name('test_service_occupation')
            ->where('cart_info_id', (int)$request['entitlementSourceDetailId'])
            ->order('id asc')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $result = [];
        foreach ((array)$rows as $row) {
            if ((int)$row['status'] !== 1) {
                continue;
            }
            $result[] = [
                'kind' => 'service_order',
                'id' => (int)$row['id'],
                'version' => (int)$row['version'],
                'occupiedTimes' => 1,
                'convertibleTimes' => (int)$row['id'] === (int)$request['source']['serviceOrderId'] ? 1 : 0,
            ];
        }
        return $result;
    }
}

foreach ([
    'cashier_v3_entitlement_debt_guard_mutation',
    'cashier_v3_entitlement_debt_guard',
    'cashier_v3_staff_profile_version',
    'cashier_v3_entitlement_occupation_version',
    'test_staff_type_authority',
    'test_service_occupation',
    'store_reservation_order',
    'system_store_staff',
    'employee',
    'store_order',
] as $table) {
    Db::name($table)->delete(true);
}

Db::name('store_order')->insertAll([
    ['id' => 501, 'store_id' => 8],
    ['id' => 502, 'store_id' => 8],
]);
Db::name('employee')->insertAll([
    ['id' => 1010, 'name' => '员工甲', 'status' => 1, 'is_del' => 0],
    ['id' => 1020, 'name' => '员工乙', 'status' => 1, 'is_del' => 0],
    ['id' => 1030, 'name' => '跨店员工', 'status' => 1, 'is_del' => 0],
]);
Db::name('system_store_staff')->insertAll([
    ['id' => 10, 'employee_id' => 1010, 'store_id' => 8, 'staff_name' => '旧员工甲', 'status' => 1, 'is_del' => 0],
    ['id' => 20, 'employee_id' => 1020, 'store_id' => 8, 'staff_name' => '旧员工乙', 'status' => 1, 'is_del' => 0],
    ['id' => 30, 'employee_id' => 1030, 'store_id' => 9, 'staff_name' => '跨店员工', 'status' => 1, 'is_del' => 0],
]);
Db::name('test_staff_type_authority')->insertAll([
    ['employee_id' => 1010, 'type_code' => 'internal', 'version' => 1],
    ['employee_id' => 1020, 'type_code' => 'outsourced', 'version' => 1],
    ['employee_id' => 1030, 'type_code' => 'partner', 'version' => 1],
]);
Db::name('store_reservation_order')->insertAll([
    ['id' => 9001, 'store_id' => 8, 'cart_info_id' => 2001, 'status' => 0, 'is_del' => 0, 'is_system_del' => 0],
    ['id' => 9002, 'store_id' => 9, 'cart_info_id' => 2001, 'status' => 3, 'is_del' => 0, 'is_system_del' => 0],
    ['id' => 9003, 'store_id' => 8, 'cart_info_id' => 2001, 'status' => 2, 'is_del' => 0, 'is_system_del' => 0],
]);
Db::name('test_service_occupation')->insertAll([
    ['id' => 7001, 'store_id' => 8, 'cart_info_id' => 2001, 'status' => 1, 'version' => 4],
    ['id' => 7002, 'store_id' => 8, 'cart_info_id' => 2002, 'status' => 1, 'version' => 2],
]);

$operator = c2piOperator();
$scope = c2piScope();
$debt = new CashierV3EntitlementDebtGuardProvider();

$firstGuard = Db::transaction(function () use ($debt, $operator, $scope): array {
    return $debt->lockOrCreateSnapshotInTx(501, $operator, $scope);
});
$secondGuard = Db::transaction(function () use ($debt, $operator, $scope): array {
    return $debt->lockOrCreateSnapshotInTx(501, $operator, $scope);
});
c2piAssert(
    'empty debt order gets one stable positive guard',
    $firstGuard['identity'] === 'order:501'
        && $firstGuard['version'] === 1
        && $secondGuard['version'] === 1
        && (int)Db::name('cashier_v3_entitlement_debt_guard')->count() === 1
);

$fingerprint = hash('sha256', 'repay-501-1');
$advance = Db::transaction(function () use ($debt, $operator, $scope, $fingerprint): array {
    return $debt->advanceAfterDebtMutationInTx(
        501,
        1,
        'debt-repay:HK501-1',
        $fingerprint,
        'debt_repaid',
        $operator,
        $scope
    );
});
$replay = Db::transaction(function () use ($debt, $operator, $scope, $fingerprint): array {
    return $debt->advanceAfterDebtMutationInTx(
        501,
        1,
        'debt-repay:HK501-1',
        $fingerprint,
        'debt_repaid',
        $operator,
        $scope
    );
});
c2piAssert(
    'debt mutation advance is exact plus one and idempotent',
    !$advance['idempotentReplay']
        && $advance['versionBefore'] === 1
        && $advance['versionAfter'] === 2
        && $replay['idempotentReplay']
        && $replay['versionAfter'] === 2
        && (int)Db::name('cashier_v3_entitlement_debt_guard_mutation')->count() === 1
);
$versionConflict = Db::transaction(function () use ($debt, $operator, $scope): string {
    return c2piReason(function () use ($debt, $operator, $scope): void {
        $debt->advanceAfterDebtMutationInTx(
            501,
            1,
            'debt-repay:HK501-2',
            hash('sha256', 'repay-501-2'),
            'debt_repaid',
            $operator,
            $scope
        );
    });
});
$keyConflict = Db::transaction(function () use ($debt, $operator, $scope): string {
    return c2piReason(function () use ($debt, $operator, $scope): void {
        $debt->advanceAfterDebtMutationInTx(
            501,
            2,
            'debt-repay:HK501-1',
            hash('sha256', 'different-request'),
            'debt_repaid',
            $operator,
            $scope
        );
    });
});
c2piAssert(
    'debt guard rejects stale versions and mutation key reuse',
    $versionConflict === 'debt_guard_version_conflict'
        && $keyConflict === 'debt_guard_mutation_key_conflict',
    $versionConflict . ' / ' . $keyConflict
);
$scopeDenied = Db::transaction(function () use ($debt, $operator): string {
    return c2piReason(function () use ($debt, $operator): void {
        $debt->lockOrCreateSnapshotInTx(502, $operator, c2piScope(9));
    });
});
c2piAssert('debt guard enforces server DataScope', $scopeDenied === 'provider_data_scope_session_mismatch', $scopeDenied);

$defaultStaff = new CashierV3StaffProfileProvider();
c2piAssert(
    'staff provider fails closed without employee type authority',
    empty($defaultStaff->readinessStatus()['ready'])
        && in_array('staff_type_authority_not_ready', $defaultStaff->readinessStatus()['reasons'], true)
);
$staff = new CashierV3StaffProfileProvider(new C2ProviderTestStaffTypeAuthority());
$profileOne = Db::transaction(function () use ($staff, $operator, $scope): array {
    return $staff->lockProfileSnapshotInTx(10, $operator, $scope);
});
Db::name('employee')->where('id', 1010)->update(['name' => '员工甲改名']);
$profileTwo = Db::transaction(function () use ($staff, $operator, $scope): array {
    return $staff->lockProfileSnapshotInTx(10, $operator, $scope);
});
c2piAssert(
    'staff binding name and type snapshot get stable conflict version',
    $profileOne['employeeId'] === 1010
        && $profileOne['staffVersion'] === 1
        && $profileOne['employeeTypeCodeSnapshot'] === 'internal'
        && $profileTwo['staffName'] === '员工甲改名'
        && $profileTwo['staffVersion'] === 2
);
Db::name('test_staff_type_authority')->where('employee_id', 1010)->update([
    'type_code' => 'partner',
    'version' => 2,
]);
$profileThree = Db::transaction(function () use ($staff, $operator, $scope): array {
    return $staff->lockProfileSnapshotInTx(10, $operator, $scope);
});
c2piAssert(
    'staff type authority change advances profile version',
    $profileThree['employeeTypeCodeSnapshot'] === 'partner'
        && $profileThree['employeeTypeAuthorityVersion'] === 2
        && $profileThree['staffVersion'] === 3
);
$crossStoreStaff = Db::transaction(function () use ($staff, $operator, $scope): string {
    return c2piReason(function () use ($staff, $operator, $scope): void {
        $staff->lockProfileSnapshotInTx(30, $operator, $scope);
    });
});
Db::name('system_store_staff')->where('id', 20)->update(['status' => 0]);
$inactiveStaff = Db::transaction(function () use ($staff, $operator, $scope): string {
    return c2piReason(function () use ($staff, $operator, $scope): void {
        $staff->lockProfileSnapshotInTx(20, $operator, $scope);
    });
});
c2piAssert(
    'staff provider rejects cross-store and inactive assignments',
    $crossStoreStaff === 'provider_data_scope_store_mismatch'
        && $inactiveStaff === 'staff_profile_inactive',
    $crossStoreStaff . ' / ' . $inactiveStaff
);
Db::name('system_store_staff')->where('id', 20)->update(['status' => 1]);

$occupationMissingService = new CashierV3EntitlementOccupationProvider();
$occupation = new CashierV3EntitlementOccupationProvider(new C2ProviderTestServiceAuthority());
$directRequest = [
    'tenantId' => '0',
    'storeId' => 8,
    'entitlementSourceDetailId' => 2001,
    'source' => ['type' => 'direct', 'serviceOrderId' => 0, 'reservationId' => 0],
];
$missingDirectReason = Db::transaction(function () use ($occupationMissingService, $directRequest, $operator, $scope): string {
    return c2piReason(function () use ($occupationMissingService, $directRequest, $operator, $scope): void {
        $occupationMissingService->lockSnapshotInTx($directRequest, $operator, $scope);
    });
});
$directOccupation = Db::transaction(function () use ($occupation, $directRequest, $operator, $scope): array {
    return $occupation->lockSnapshotInTx($directRequest, $operator, $scope);
});
$reservationRequest = $directRequest;
$reservationRequest['source'] = ['type' => 'reservation', 'serviceOrderId' => 0, 'reservationId' => 9001];
$reservationOccupation = Db::transaction(function () use ($occupation, $reservationRequest, $operator, $scope): array {
    return $occupation->lockSnapshotInTx($reservationRequest, $operator, $scope);
});
c2piAssert(
    'all source paths merge complete reservation and service contributor sets',
    $directOccupation['occupationAuthorityComplete']
        && $directOccupation['occupiedTimes'] === 3
        && $directOccupation['currentSourceConvertibleTimes'] === 0
        && count($directOccupation['occupationContributors']) === 3
        && $reservationOccupation['occupiedTimes'] === 3
        && $reservationOccupation['currentSourceConvertibleTimes'] === 1
);
$crossStoreCurrent = $reservationRequest;
$crossStoreCurrent['source']['reservationId'] = 9002;
$crossStoreCurrentReason = Db::transaction(function () use ($occupation, $crossStoreCurrent, $operator, $scope): string {
    return c2piReason(function () use ($occupation, $crossStoreCurrent, $operator, $scope): void {
        $occupation->lockSnapshotInTx($crossStoreCurrent, $operator, $scope);
    });
});
$unknownSource = $directRequest;
$unknownSource['source']['type'] = 'unknown';
$unknownReason = Db::transaction(function () use ($occupationMissingService, $unknownSource, $operator, $scope): string {
    return c2piReason(function () use ($occupationMissingService, $unknownSource, $operator, $scope): void {
        $occupationMissingService->lockSnapshotInTx($unknownSource, $operator, $scope);
    });
});
$serviceRequest = $directRequest;
$serviceRequest['source'] = ['type' => 'service_order', 'serviceOrderId' => 7001, 'reservationId' => 0];
$missingServiceReason = Db::transaction(function () use ($occupationMissingService, $serviceRequest, $operator, $scope): string {
    return c2piReason(function () use ($occupationMissingService, $serviceRequest, $operator, $scope): void {
        $occupationMissingService->lockSnapshotInTx($serviceRequest, $operator, $scope);
    });
});
c2piAssert(
    'occupation fails closed for wrong current source and unknown authority',
    $crossStoreCurrentReason === 'current_reservation_store_mismatch'
        && $unknownReason === 'occupation_source_invalid'
        && $missingDirectReason === 'service_order_occupation_authority_not_ready'
        && $missingServiceReason === 'service_order_occupation_authority_not_ready',
    $crossStoreCurrentReason . ' / ' . $unknownReason . ' / '
        . $missingDirectReason . ' / ' . $missingServiceReason
);

$serviceOccupation = Db::transaction(function () use ($occupation, $serviceRequest, $operator, $scope): array {
    return $occupation->lockSnapshotInTx($serviceRequest, $operator, $scope);
});
c2piAssert(
    'service current conversion must match service contributor',
    $serviceOccupation['occupiedTimes'] === 3
        && $serviceOccupation['currentSourceConvertibleTimes'] === 1
        && count($serviceOccupation['occupationContributors']) === 3
);
$selfParticipantScope = c2piScope(
    8,
    '0',
    CashierV3DataScopeContext::MODE_SELF_PARTICIPANT,
    1010
);
$selfDirectReason = Db::transaction(function () use ($occupation, $directRequest, $operator, $selfParticipantScope): string {
    return c2piReason(function () use ($occupation, $directRequest, $operator, $selfParticipantScope): void {
        $occupation->lockSnapshotInTx($directRequest, $operator, $selfParticipantScope);
    });
});
$selfServiceOccupation = Db::transaction(function () use ($occupation, $serviceRequest, $operator, $selfParticipantScope): array {
    return $occupation->lockSnapshotInTx($serviceRequest, $operator, $selfParticipantScope);
});
c2piAssert(
    'SELF_PARTICIPANT only reaches C3 authority through current service order',
    $selfDirectReason === 'occupation_self_participant_service_order_required'
        && $selfServiceOccupation['currentSourceConvertibleTimes'] === 1,
    $selfDirectReason
);
$beforeReservationVersion = 0;
foreach ($directOccupation['occupationContributors'] as $contributor) {
    if ($contributor['kind'] === 'reservation' && $contributor['id'] === 9001) {
        $beforeReservationVersion = $contributor['version'];
    }
}
Db::name('store_reservation_order')->where('id', 9001)->update(['status' => 1]);
$afterOccupation = Db::transaction(function () use ($occupation, $directRequest, $operator, $scope): array {
    return $occupation->lockSnapshotInTx($directRequest, $operator, $scope);
});
$afterReservationVersion = 0;
foreach ($afterOccupation['occupationContributors'] as $contributor) {
    if ($contributor['kind'] === 'reservation' && $contributor['id'] === 9001) {
        $afterReservationVersion = $contributor['version'];
    }
}
c2piAssert(
    'reservation authority change advances contributor version',
    $beforeReservationVersion === 1 && $afterReservationVersion === 2
);

$defaultEvaluator = new CashierV3EntitlementActivationReadinessEvaluator(
    $debt,
    $defaultStaff,
    $occupationMissingService,
    new CashierV3InventoryCompletionReadinessProbe(),
    new CashierV3LegacyDebtGuardIntegrationProbe()
);
$blocked = $defaultEvaluator->evaluate();
c2piAssert(
    'current system stays blocked without staff type C3 service and old writer integration',
    !$blocked['ready']
        && !$blocked['gatewayActivationPerformed']
        && in_array('dependency_not_ready:legacyDebtWriters', $blocked['blockingReasons'], true)
        && in_array('dependency_not_ready:staffProfile', $blocked['blockingReasons'], true)
        && in_array('dependency_not_ready:occupation', $blocked['blockingReasons'], true)
);

$readyEvaluator = new CashierV3EntitlementActivationReadinessEvaluator(
    $debt,
    $staff,
    $occupation,
    new CashierV3InventoryCompletionReadinessProbe(),
    new CashierV3LegacyDebtGuardIntegrationProbe([
        'create' => CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER,
        'repay' => CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER,
        'adjustment' => CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER,
    ])
);
$ready = $readyEvaluator->evaluate();
c2piAssert(
    'readiness becomes true only with every exact provider contract',
    $ready['ready']
        && !$ready['gatewayActivationPerformed']
        && $ready['blockingReasons'] === [],
    json_encode($ready, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$tenantScope = CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_TENANT, '0');
$guardVersion = Db::transaction(function () use ($debt, $tenantScope, $scope): int {
    return (int)$debt->lockAndReadVersionWithDataScope(
        $tenantScope,
        CashierV3EntitlementDebtGuardProvider::KIND,
        'order:501',
        $scope
    );
});
c2piAssert('DataScoped version API returns current guard version', $guardVersion === 2);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "C2_ENTITLEMENT_PROVIDER_INTEGRATION=PASS\n";

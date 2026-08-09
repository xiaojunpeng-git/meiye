<?php

require_once '/tests/cashier-v3/lib/boot-env.php';
require_once '/var/www/html/vendor/autoload.php';
require_once '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\checkout\provider\CashierV3EmployeeTypeAuthority;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContractException;
use app\services\employee\EmployeeTypeAuthorityServices;
use app\services\organization\OrganizationScopeService;
use mohe\exceptions\AdminException;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_ORGANIZATION);

$passed = 0;
$failed = 0;

function etaiAssert(string $name, bool $condition, string $detail = ''): void
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

function etaiMessage(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3EntitlementProviderContractException $exception) {
        return $exception->reason();
    } catch (\Throwable $exception) {
        return $exception->getMessage();
    }
    return '';
}

function etaiAdmin(int $id, int $level, int $adminType = 0): array
{
    return [
        'id' => $id,
        'level' => $level,
        'admin_type' => $adminType,
        'account' => 'admin_' . $id,
        'real_name' => '管理员' . $id,
    ];
}

function etaiAudit(int $operatorId = 1): array
{
    return [
        'operator_id' => $operatorId,
        'operator_name' => '管理员' . $operatorId,
        'operator_ip' => '127.0.0.1',
        'request_id' => 'eta-integration-' . $operatorId,
    ];
}

function etaiScope(int $storeId, int $employeeId = 100): CashierV3DataScopeContext
{
    return new CashierV3DataScopeContext(
        1,
        $employeeId,
        $storeId,
        '0',
        '1',
        [$storeId],
        CashierV3DataScopeContext::MODE_STORES,
        [],
        false,
        '',
        'permission-v1',
        ['cashier.v3.cashier'],
        ['id' => 1, 'employee_id' => $employeeId]
    );
}

foreach ([
    'organization_write_idempotency',
    'employee_change_log',
    'store_order_cart_info',
    'system_store_staff',
    'employee',
] as $table) {
    Db::name($table)->delete(true);
}

$typeMenuId = (int)Db::name('system_menus')
    ->where('unique_auth', EmployeeTypeAuthorityServices::AUTH_MANAGE)
    ->where('is_del', 0)
    ->value('id');
$staffMenuId = (int)Db::name('system_menus')
    ->where('unique_auth', 'setting-staff-index')
    ->where('is_del', 0)
    ->value('id');
Db::name('system_role')->where('id', 10)->update([
    'rules' => $staffMenuId . ',' . $typeMenuId,
    'status' => 1,
]);
Db::name('system_role')->where('id', 11)->update([
    'rules' => (string)$staffMenuId,
    'status' => 1,
]);

Db::name('employee')->insertAll([
    ['id' => 10, 'name' => '待分类员工', 'phone' => '13900000010', 'status' => 1, 'is_del' => 0,
        'employment_type_code' => null, 'employment_type_version' => 0],
    ['id' => 11, 'name' => '未分类手艺人', 'phone' => '13900000011', 'status' => 1, 'is_del' => 0,
        'employment_type_code' => null, 'employment_type_version' => 0],
    ['id' => 12, 'name' => '内部员工', 'phone' => '13900000012', 'status' => 1, 'is_del' => 0,
        'employment_type_code' => 'internal', 'employment_type_version' => 1],
    ['id' => 13, 'name' => '跨店员工', 'phone' => '13900000013', 'status' => 1, 'is_del' => 0,
        'employment_type_code' => 'partner', 'employment_type_version' => 2],
    ['id' => 40, 'name' => '并发员工', 'phone' => '13900000040', 'status' => 1, 'is_del' => 0,
        'employment_type_code' => 'internal', 'employment_type_version' => 1],
]);
Db::name('system_store_staff')->insertAll([
    ['id' => 110, 'employee_id' => 10, 'store_id' => 8, 'staff_name' => '待分类员工',
        'status' => 1, 'is_del' => 0, 'is_fencheng' => 0, 'is_hezuofang' => 0],
    ['id' => 111, 'employee_id' => 11, 'store_id' => 8, 'staff_name' => '未分类手艺人',
        'status' => 1, 'is_del' => 0, 'is_fencheng' => 1, 'is_hezuofang' => 0],
    ['id' => 112, 'employee_id' => 12, 'store_id' => 8, 'staff_name' => '内部员工',
        'status' => 1, 'is_del' => 0, 'is_fencheng' => 0, 'is_hezuofang' => 0],
    ['id' => 113, 'employee_id' => 13, 'store_id' => 9, 'staff_name' => '跨店员工',
        'status' => 1, 'is_del' => 0, 'is_fencheng' => 0, 'is_hezuofang' => 1],
]);
Db::name('store_order_cart_info')->insert([
    'id' => 900,
    'staff_yeji' => '[{"staff_id":110,"staff_name":"历史员工","yeji":"80.00"}]',
]);

$service = app()->make(EmployeeTypeAuthorityServices::class);
$super = etaiAdmin(1, 0);
$explicit = etaiAdmin(2, 1);
$missing = etaiAdmin(3, 1);
$proxy = etaiAdmin(4, 0, 3);

$permissionResults = [
    'super' => etaiMessage(function () use ($service, $super): void {
        $service->assertManagePermission($super);
    }),
    'explicit' => etaiMessage(function () use ($service, $explicit): void {
        $service->assertManagePermission($explicit);
    }),
    'missing' => etaiMessage(function () use ($service, $missing): void {
        $service->assertManagePermission($missing);
    }),
    'proxy' => etaiMessage(function () use ($service, $proxy): void {
        $service->assertManagePermission($proxy);
    }),
];
etaiAssert(
    'permission allows super and explicit role but rejects missing and proxy',
    $permissionResults['super'] === ''
        && $permissionResults['explicit'] === ''
        && strpos($permissionResults['missing'], '无人员类型管理权限') !== false
        && strpos($permissionResults['proxy'], '无人员类型管理权限') !== false,
    json_encode($permissionResults, JSON_UNESCAPED_UNICODE)
);

$first = Db::transaction(function () use ($service, $super): array {
    return $service->saveTypeInTx(10, 'partner', 0, $super, etaiAudit(), 'hq');
});
$auditCountAfterFirst = (int)Db::name('employee_change_log')
    ->where('employee_id', 10)
    ->where('action', EmployeeTypeAuthorityServices::ACTION)
    ->count();
$same = Db::transaction(function () use ($service, $super): array {
    return $service->saveTypeInTx(10, 'partner', 1, $super, etaiAudit(), 'hq');
});
$auditCountAfterSame = (int)Db::name('employee_change_log')
    ->where('employee_id', 10)
    ->where('action', EmployeeTypeAuthorityServices::ACTION)
    ->count();
etaiAssert(
    'classification advances from zero once and same value is no-op',
    $first['changed'] && $first['employment_type_version'] === 1
        && !$same['changed'] && $same['employment_type_version'] === 1
        && $auditCountAfterFirst === 1 && $auditCountAfterSame === 1
);

$audit = Db::name('employee_change_log')
    ->where('employee_id', 10)
    ->where('action', EmployeeTypeAuthorityServices::ACTION)
    ->find();
$before = json_decode((string)($audit['before_data'] ?? ''), true);
$after = json_decode((string)($audit['after_data'] ?? ''), true);
etaiAssert(
    'audit records explicit before after operator and request id',
    is_array($before) && $before['employment_type_code'] === null
        && (int)$before['employment_type_version'] === 0
        && is_array($after) && $after['employment_type_code'] === 'partner'
        && (int)$after['employment_type_version'] === 1
        && (int)($audit['operator_id'] ?? 0) === 1
        && (string)($audit['request_id'] ?? '') !== ''
);

$stale = Db::transaction(function () use ($service, $super): string {
    return etaiMessage(function () use ($service, $super): void {
        $service->saveTypeInTx(10, 'outsourced', 0, $super, etaiAudit(), 'hq');
    });
});
$invalid = etaiMessage(function () use ($service, $super): void {
    Db::transaction(function () use ($service, $super): void {
        $service->saveTypeInTx(10, 'vendor', 1, $super, etaiAudit(), 'hq');
    });
});
$storeOperator = etaiAdmin(110, 1, 3);
$storeWrite = Db::transaction(function () use ($service, $storeOperator): string {
    return etaiMessage(function () use ($service, $storeOperator): void {
        $service->saveTypeInTx(10, 'outsourced', 1, $storeOperator, etaiAudit(110), 'store', 110, 8);
    });
});
etaiAssert(
    'stale version and invalid enum fail closed while current-store assignment can change type',
    strpos($stale, '刷新后重试') !== false
        && strpos($invalid, '人员类型无效') !== false
        && $storeWrite === '',
    $stale . ' / ' . $invalid . ' / ' . $storeWrite
);

$snapshot = $service->readSnapshot(10, 'hq');
$storeSnapshot = $service->readSnapshot(10, 'store', 110, 8);
$crossStoreWrite = Db::transaction(function () use ($service, $storeOperator): string {
    return etaiMessage(function () use ($service, $storeOperator): void {
        $service->saveTypeInTx(13, 'internal', 2, $storeOperator, etaiAudit(110), 'store', 113, 9);
    });
});
etaiAssert(
    'HQ and current-store snapshots are readable but cross-store writes are rejected',
    $snapshot['employment_type_code'] === 'outsourced'
        && $snapshot['employment_type_version'] === 2
        && $storeSnapshot['employment_type_code'] === 'outsourced'
        && $storeSnapshot['employment_type_version'] === 2
        && strpos($crossStoreWrite, '当前账号无本门店人员类型管理权限') !== false
);

$token = '11111111-1111-4111-8111-111111111111';
$firstIdem = $service->saveType(12, 'outsourced', 1, $super, [
    'body_token' => $token,
    'operator_ip' => '127.0.0.1',
]);
$replayIdem = $service->saveType(12, 'outsourced', 1, $super, [
    'body_token' => $token,
    'operator_ip' => '127.0.0.1',
]);
$tokenConflict = etaiMessage(function () use ($service, $super, $token): void {
    $service->saveType(12, 'partner', 2, $super, [
        'body_token' => $token,
        'operator_ip' => '127.0.0.1',
    ]);
});
etaiAssert(
    'independent command is UUID idempotent and rejects token payload conflict',
    empty($firstIdem['replay'])
        && !empty($replayIdem['replay'])
        && $firstIdem['data']['employment_type_version'] === 2
        && $replayIdem['data']['employment_type_version'] === 2
        && $tokenConflict === 'IDEMPOTENCY_TOKEN_CONFLICT'
        && (int)Db::name('employee_change_log')
            ->where('employee_id', 12)
            ->where('action', EmployeeTypeAuthorityServices::ACTION)
            ->count() === 1,
    $tokenConflict
);

$historicalSnapshotBefore = (string)Db::name('store_order_cart_info')->where('id', 900)->value('staff_yeji');
Db::transaction(function () use ($service, $super): void {
    $service->saveTypeInTx(10, 'outsourced', 1, $super, etaiAudit(), 'hq');
});
$historicalSnapshotAfter = (string)Db::name('store_order_cart_info')->where('id', 900)->value('staff_yeji');
etaiAssert(
    'changing current type never rewrites historical staff snapshot',
    $historicalSnapshotBefore !== '' && $historicalSnapshotAfter === $historicalSnapshotBefore
);

$adapter = new CashierV3EmployeeTypeAuthority();
$ready = $adapter->readinessStatus();
$staff12 = Db::name('system_store_staff')->where('id', 112)->find();
$employee12 = Db::name('employee')->where('id', 12)->find();
$adapterSnapshot = Db::transaction(function () use ($adapter, $staff12, $employee12): array {
    return $adapter->lockTypeSnapshotInTx($staff12, $employee12, etaiScope(8));
});
$staff11 = Db::name('system_store_staff')->where('id', 111)->find();
$employee11 = Db::name('employee')->where('id', 11)->find();
$unclassified = Db::transaction(function () use ($adapter, $staff11, $employee11): string {
    return etaiMessage(function () use ($adapter, $staff11, $employee11): void {
        $adapter->lockTypeSnapshotInTx($staff11, $employee11, etaiScope(8));
    });
});
$staff13 = Db::name('system_store_staff')->where('id', 113)->find();
$employee13 = Db::name('employee')->where('id', 13)->find();
$crossStore = Db::transaction(function () use ($adapter, $staff13, $employee13): string {
    return etaiMessage(function () use ($adapter, $staff13, $employee13): void {
        $adapter->lockTypeSnapshotInTx($staff13, $employee13, etaiScope(8));
    });
});
etaiAssert(
    'C2 adapter returns exact snapshot and rejects unclassified or cross-store staff',
    !empty($ready['ready'])
        && $adapterSnapshot === [
            'employeeTypeCodeSnapshot' => 'outsourced',
            'employeeTypeAuthorityVersion' => 2,
        ]
        && $unclassified === 'staff_type_unclassified'
        && $crossStore === 'provider_data_scope_store_mismatch',
    $unclassified . ' / ' . $crossStore
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "EMPLOYEE_TYPE_AUTHORITY_INTEGRATION=PASS\n";

<?php
/**
 * 本机事务回归：总部与门店读同一员工范围；过期权限版本不能覆盖新设置。
 * 仅连接 mohe-mysql，所有测试写入在结束时回滚。
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('拒绝连接非本地测试数据库');
}

$scopeSvc = app()->make(EmployeeDataScopeServices::class);
$completeSvc = app()->make(EmployeePersonCompleteWriteServices::class);
$staffRows = Db::name('system_store_staff')->where('is_del', 0)->where('status', 1)
    ->where('employee_id', '>', 0)->where('store_id', '>', 0)
    ->order('id', 'desc')->limit(200)->field('id,employee_id')->select()->toArray();
$staff = null;
foreach ($staffRows as $candidate) {
    if ($scopeSvc->resolveCurrentActiveStoreIds((int)$candidate['employee_id'])) {
        $staff = $candidate;
        break;
    }
}
if (!$staff) {
    throw new RuntimeException('本地测试库缺少在职员工');
}
$employeeId = (int)$staff['employee_id'];
$staffId = (int)$staff['id'];
$admin = ['id' => 1, 'level' => 0, 'admin_type' => 0];
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
};
$hqScope = static function (string $mode, int $expectedVersion) use ($scopeSvc, $employeeId, $admin): array {
    return $scopeSvc->saveScopeInTx($employeeId, [
        'source_type' => EmployeeDataScopeServices::SOURCE_HQ,
        'scope_mode' => $mode,
        'expected_version' => $expectedVersion,
    ], $admin);
};

Db::startTrans();
try {
    $before = $scopeSvc->readEditorScope($employeeId);
    $personal = $hqScope(EmployeeDataScopeServices::MODE_PERSONAL, (int)$before['version']);
    $hqView = $completeSvc->getComplete($employeeId, $staffId, 'hq');
    $storeView = $completeSvc->getComplete($employeeId, $staffId, 'store');
    $assert(($hqView['scope']['scope_mode'] ?? '') === 'personal'
        && ($storeView['scope']['scope_mode'] ?? '') === 'personal', '总部个人在两端一致回显');
    $assert($scopeSvc->resolveEffectiveStoreIds($employeeId, 0) === [], '总部个人覆盖旧门店来源');

    $store = $hqScope(EmployeeDataScopeServices::MODE_STORE, (int)$personal['version']);
    $hqView = $completeSvc->getComplete($employeeId, $staffId, 'hq');
    $storeView = $completeSvc->getComplete($employeeId, $staffId, 'store');
    $assert(($hqView['scope']['scope_mode'] ?? '') === 'store'
        && ($storeView['scope']['scope_mode'] ?? '') === 'store', '门店范围在两端一致回显');
    $assert($scopeSvc->resolveEffectiveStoreIds($employeeId, 0)
        === $scopeSvc->resolveCurrentActiveStoreIds($employeeId), '门店范围按全部当前任职门店计算');

    try {
        $hqScope(EmployeeDataScopeServices::MODE_PERSONAL, (int)$personal['version']);
        $assert(false, '拒绝过期编辑页覆盖新范围');
    } catch (\mohe\exceptions\AdminException $error) {
        $assert(str_contains($error->getMessage(), '已由他人修改'), '拒绝过期编辑页覆盖新范围');
    }
    $assert((int)$scopeSvc->readEditorScope($employeeId)['version'] === (int)$store['version'],
        '过期保存不改变权限版本');

    $selectedStoreId = $scopeSvc->resolveCurrentActiveStoreIds($employeeId)[0];
    $selected = $scopeSvc->saveScopeInTx($employeeId, [
        'source_type' => EmployeeDataScopeServices::SOURCE_HQ,
        'scope_mode' => EmployeeDataScopeServices::MODE_STORE_SELECTED,
        'store_ids' => [$selectedStoreId],
        'expected_version' => (int)$store['version'],
    ], $admin);
    $hqView = $completeSvc->getComplete($employeeId, $staffId, 'hq');
    $storeView = $completeSvc->getComplete($employeeId, $staffId, 'store');
    $assert(($hqView['scope']['scope_mode'] ?? '') === 'store_selected'
        && ($storeView['scope']['scope_mode'] ?? '') === 'store_selected'
        && (int)$selected['version'] > (int)$store['version'],
        '总部指定门店在门店端如实回显');

    $staffRow = Db::name('system_store_staff')->where('id', $staffId)->find();
    $employeeRow = Db::name('employee')->where('id', $employeeId)->find();
    $orgId = (int)Db::name('organization_store')->where('store_id', (int)$staffRow['store_id'])->value('org_id');
    if (!$orgId || !preg_match('/^1[3-9]\d{9}$/', (string)$employeeRow['phone'])) {
        throw new RuntimeException('本地员工不满足完整保存测试条件');
    }
    $baseInput = [
        'employee_id' => $employeeId,
        'staff_id' => $staffId,
        'org_id' => $orgId,
        'store_id' => (int)$staffRow['store_id'],
        'staff_name' => (string)$employeeRow['name'],
        'phone' => (string)$employeeRow['phone'],
        'avatar' => (string)$employeeRow['avatar'],
    ];
    $storeOperator = ['id' => $staffId, 'level' => 1, 'admin_type' => 3,
        'account' => 'local-scope-test', 'real_name' => '本地事务测试'];
    $saveFromStore = static function (array $input) use ($completeSvc, $storeOperator): array {
        $token = '70a00000-0000-4000-8000-' . bin2hex(random_bytes(6));
        $input['request_token'] = $token;
        return $completeSvc->saveComplete($input, $storeOperator,
            ['body_token' => $token, 'operator_ip' => '127.0.0.1'], 'store');
    };
    $savedWithoutScope = $saveFromStore($baseInput);
    $assert(($savedWithoutScope['data']['scope']['scope_mode'] ?? '') === 'store_selected'
        && (int)$scopeSvc->readEditorScope($employeeId)['version'] === (int)$selected['version'],
        '门店仅保存档案时不覆盖总部指定门店范围');

    $savedFromStore = $saveFromStore($baseInput + [
        'scope_mode' => EmployeeDataScopeServices::MODE_PERSONAL,
        'scope_version' => (int)$selected['version'],
    ]);
    $assert(($savedFromStore['data']['scope']['scope_mode'] ?? '') === 'personal'
        && $completeSvc->getComplete($employeeId, $staffId, 'hq')['scope']['scope_mode'] === 'personal',
        '门店完整保存覆盖总部范围，平台端立即读取新值');

    // 模拟尚未归并的历史门店来源，验证两端仍读取同一合成范围。
    Db::name('employee_data_scope')->where('employee_id', $employeeId)
        ->where('source_type', EmployeeDataScopeServices::SOURCE_HQ)->update(['is_del' => 1]);
    $scopeSvc->saveScopeInTx($employeeId, [
        'source_type' => EmployeeDataScopeServices::SOURCE_STORE,
        'source_store_id' => $selectedStoreId,
        'scope_mode' => EmployeeDataScopeServices::MODE_STORE_SELF,
    ], $admin);
    $hqView = $completeSvc->getComplete($employeeId, $staffId, 'hq');
    $storeView = $completeSvc->getComplete($employeeId, $staffId, 'store');
    $assert($hqView['scope']['scope_mode'] === $storeView['scope']['scope_mode']
        && $hqView['scope']['store_ids'] === $storeView['scope']['store_ids'],
        '仅有历史门店来源时两端仍一致回显');
} finally {
    Db::rollback();
}

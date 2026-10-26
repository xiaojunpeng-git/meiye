<?php

/** C22-00003 员工权限仅由岗位决定的兼容边界契约（PHP 7.4）。 */

$root = dirname(__DIR__, 3);
$backendRoot = trim((string)getenv('CASHIER_V3_BACKEND_ROOT'));
if ($backendRoot === '') {
    $backendRoot = $root . '/后端代码';
}

require $backendRoot . '/mohe/exceptions/AdminException.php';
require $backendRoot . '/app/services/cashier/v3/permission/CashierV3StaffFeatureOverrideServices.php';

use app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices;
use mohe\exceptions\AdminException;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}\n";
};

$message = CashierV3StaffFeatureOverrideServices::INDIVIDUAL_PERMISSION_EDITING_DISABLED_MESSAGE;
$rejected = false;
try {
    CashierV3StaffFeatureOverrideServices::assertIndividualPermissionEditingDisabled();
} catch (AdminException $exception) {
    $rejected = $exception->getMessage() === $message;
}
$check('旧单人权限命令统一明确拒绝', $rejected);

$overrideService = new CashierV3StaffFeatureOverrideServices();
$writeRejectedBeforeDatabaseAccess = false;
try {
    $overrideService->save(1, 1, [], 0, []);
} catch (AdminException $exception) {
    $writeRejectedBeforeDatabaseAccess = $exception->getMessage() === $message;
}
$check('遗留服务无法写入个人权限覆盖', $writeRejectedBeforeDatabaseAccess);

$staffPage = file_get_contents($root . '/前端代码/cashier-v3/src/views/StaffListView.vue');
$staffApi = file_get_contents($root . '/前端代码/cashier-v3/src/services/staffManagementApi.js');
$resolver = file_get_contents($backendRoot . '/app/services/cashier/v3/permission/CashierV3FeatureResolver.php');
$policy = file_get_contents($backendRoot . '/app/services/organization/JobPositionPolicyServices.php');
$staffQueryRegistrar = file_get_contents($backendRoot . '/app/services/query/provider/StaffUnifiedQueryPageRegistrar.php');

$check('员工列表不再渲染单人权限编辑按钮', strpos($staffPage, '>权限编辑</button>') === false);
$check('员工列表不再引用个人权限读写接口', strpos($staffPage, 'readStoreStaffFeaturePermissions') === false
    && strpos($staffPage, 'saveStoreStaffFeaturePermissions') === false
    && strpos($staffApi, 'feature-permissions') === false);
$check('权限解析只取岗位规则，不合并个人覆盖', strpos($resolver, 'mergeEmployeeOverrides') === false
    && strpos($resolver, 'staff_store_v3_feature_override') === false);
$check('岗位权限树不再提供个人权限编辑项', strpos($policy, 'cashier.v3.staff.permission_edit') === false);
$check('员工导出不再是岗位可配置的独立权限', strpos($policy, "'导出员工'") === false
    && strpos($staffQueryRegistrar, "'exportFeature' => 'cashier.v3.staff.export'") === false
    && strpos($staffPage, "canUseCashierV3Operation('cashier.v3.staff.export')") === false
    && strpos($staffPage, 'return unifiedQuery.createExport(payload)') !== false);

echo "STAFF_POSITION_ONLY_PERMISSION_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

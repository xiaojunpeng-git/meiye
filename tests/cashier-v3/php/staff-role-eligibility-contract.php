<?php

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) {
        throw new RuntimeException('无法读取源码：' . $relative);
    }
    return $source;
};

$failed = 0;
$check = static function (bool $condition, string $name, string $id) use (&$failed): void {
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name} [{$id}]\n");
        return;
    }
    echo "PASS: {$name} [{$id}]\n";
};

$selector = $read('app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php');
$workspace = $read('app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$reservation = $read('app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$member = $read('app/services/cashier/v3/member/CashierV3MemberModule.php');
$profile = $read('app/services/cashier/v3/checkout/provider/CashierV3StaffProfileProvider.php');
$storeController = $read('app/controller/store/staff/StoreStaff.php');
$completeWrite = $read('app/services/employee/EmployeePersonCompleteWriteServices.php');
$permissionPolicy = $read('app/services/cashier/v3/permission/CashierV3PermissionPolicyRegistry.php');
$queryPreferences = $read('app/services/query/UnifiedQueryPreferenceServices.php');
$staffPageRegistrar = $read('app/services/query/provider/StaffUnifiedQueryPageRegistrar.php');
$defaultInternalMigration = $read(
    'database/upgrades/2026-08-01-在职门店员工默认内部类型/02-正式升级.sql'
);

$check(
    strpos($selector, "? 'ss.cashier_salesperson_enabled'") !== false
        && strpos($selector, ": 'ss.cashier_craftsman_enabled'") !== false
        && strpos($selector, 'employment_type_version') !== false,
    '销售人与手艺人查询分别按任职开关筛选且销售人保留人员类型门禁',
    'STAFF-ROLE-SELECTOR-01'
);
$check(
    strpos($workspace, "where('ss.cashier_salesperson_enabled', 1)") !== false
        && strpos($workspace, 'employment_type_version') !== false
        && strpos($workspace, "where('ss.cashier_craftsman_enabled', 1)") !== false,
    '收银最终提交重新校验销售人与手艺人资格',
    'STAFF-ROLE-CASHIER-01'
);
$check(
    strpos($reservation, "where('cashier_craftsman_enabled', 1)") !== false
        && substr_count($member, "where('ss.cashier_craftsman_enabled', 1)") >= 2
        && strpos($profile, "'craftsmanEligible'") !== false,
    '预约、核销和专属服务链路统一校验手艺人资格',
    'STAFF-ROLE-CRAFTSMAN-01'
);
$check(
    substr_count($completeWrite, "'cashier_salesperson_enabled'") >= 3
        && substr_count($completeWrite, "'cashier_craftsman_enabled'") >= 3
        && strpos($completeWrite, "?? 1") !== false,
    '新建和编辑任职默认开启并持久化两个独立开关',
    'STAFF-ROLE-WRITE-01'
);
$check(
    strpos($completeWrite, "'position_ids_present'") !== false
        && strpos($completeWrite, '$legacyProjection') !== false
        && strpos($completeWrite, '$legacyOrderStatus') !== false
        && strpos($completeWrite, '$staffPayload[\'account\'] = $account') !== false,
    '历史任职的空岗位回显不得清空账号或撤销既有岗位投影',
    'STAFF-ROLE-LEGACY-EDIT-PRESERVE-01'
);
$check(
    strpos($completeWrite, "if (\$source === 'store')") !== false
        && strpos($completeWrite, "\$employeeData['employment_type_code'] = 'internal'") !== false
        && strpos($completeWrite, "\$employeeData['employment_type_version'] = 1") !== false
        && strpos($completeWrite, 'employee_employment_type_default_internal') !== false,
    '门店新建员工在同一事务内默认归类内部员工并记录审计',
    'STAFF-ROLE-STORE-DEFAULT-INTERNAL-01'
);
$check(
    strpos($defaultInternalMigration, 'employee.employment_type_code IS NULL') !== false
        && strpos($defaultInternalMigration, 'employee.employment_type_version=0') !== false
        && strpos($defaultInternalMigration, 'flagged.is_hezuofang=1 OR flagged.is_fencheng=1') !== false
        && strpos($defaultInternalMigration, "'employee_employment_type_default_internal'") !== false,
    '历史在职员工默认内部类型迁移保留外部合作标记并完整审计',
    'STAFF-ROLE-HISTORICAL-DEFAULT-INTERNAL-01'
);
$check(
    strpos($completeWrite, "\$staffPayload['can_choose'],\n                    \$staffPayload['cashier_salesperson_enabled']") === false
        && strpos($completeWrite, "\$staffPayload['can_choose'],\n                    \$staffPayload['cashier_craftsman_enabled']") === false,
    '门店员工编辑不得静默丢弃销售人和手艺人资格开关',
    'STAFF-ROLE-STORE-WRITE-01'
);
$check(
    preg_match(
        '/function read\\(\\$id\\).*?assertStaffInCurrentStore\\(\\(int\\)\\$id\\)/s',
        $storeController
    ) === 1,
    '门店员工详情读取强制校验当前门店',
    'STAFF-ROLE-READ-SCOPE-01'
);
$check(
    strpos($permissionPolicy, "'staff_list' => 'cashier.v3.management_center'") !== false
        && preg_match(
            '/policy:unified_query_page.*?if \\(!\\$scope->hasFeature\\(\\$feature\\)\\)/s',
            $permissionPolicy
        ) === 1,
    '员工统一查询能力沿用管理入口权限且服务端强制校验',
    'STAFF-ROLE-UNIFIED-QUERY-PERMISSION-01'
);
$check(
    preg_match(
        "/protected function defaultSettings\\(.*?'sorts'\\s*=>\\s*\\[\\]/s",
        $queryPreferences
    ) === 1,
    '统一查询新页面默认使用稳定主键排序且不依赖不存在的 created_at',
    'STAFF-ROLE-UNIFIED-QUERY-DEFAULT-SORT-01'
);
$check(
    strpos($staffPageRegistrar, "['staff_id', 'ID', 'integer', false, false]") !== false
        && strpos($staffPageRegistrar, "['uid', '商城用户ID', 'integer', true, false]") !== false
        && strpos($staffPageRegistrar, "['birthday_type', '生日类型', 'text', true, false]") !== false,
    '员工统一查询默认保留旧列表全部业务字段且仅隐藏内部ID',
    'STAFF-ROLE-UNIFIED-QUERY-LEGACY-FIELDS-01'
);

if ($failed > 0) {
    exit(1);
}
echo "STAFF_ROLE_ELIGIBILITY_CONTRACT=PASS\n";

<?php

declare(strict_types=1);

/**
 * 员工新建手机号复用契约（静态闭包检查）。
 *
 * 该契约只检查正式写入入口是否具备“锁定软删除主档→恢复主档→由当前
 * 请求创建任职关系”的闭环，避免前端另造员工或 SQL 旁路恢复。
 */
$root = dirname(__DIR__, 3);
$staff = file_get_contents($root . '/后端代码/app/services/employee/EmployeeStaffWriteServices.php');
$complete = file_get_contents($root . '/后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php');
$organization = file_get_contents($root . '/后端代码/app/services/organization/OrganizationEmployeeServices.php');

if ($staff === false || $complete === false || $organization === false) {
    throw new RuntimeException('employee write source missing');
}

$checks = [
    'central restore locks employee by phone' => strpos($staff, "where('phone', \$phone)->lock(true)") !== false,
    'central restore clears soft delete and activates master' => strpos($staff, "'is_del' => 0") !== false
        && strpos($staff, "'status' => 1") !== false,
    'central restore is audited' => strpos($staff, "'employee_archive_restore'") !== false
        && strpos($staff, "'assignment_scope' => 'current_request_only'") !== false,
    'staff assignment reuses restored master' => strpos($staff, 'restoreDeletedEmployeeByPhone($phone, $operatorContext)') !== false,
    'complete person save reuses restored master' => strpos($complete, 'restoreDeletedEmployeeByPhone($phone, $opCtx)') !== false,
    'organization save reuses restored master' => strpos($organization, 'restoreDeletedEmployeeByPhone($phone, $operatorContext)') !== false,
    'current organization assignment remains separate' => strpos($organization, "where('org_id', \$orgId)") !== false
        && strpos($organization, "where('employee_id', \$employeeId)") !== false,
    'historical store assignment is not bulk restored' => strpos($staff, 'projectEmployeeToActiveStaff') !== false
        && strpos($staff, "where('is_del', 0)") !== false
        && strpos($staff, "where('status', 1)") !== false,
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    throw new RuntimeException('FAIL ' . implode('; ', $failed));
}

echo count($checks) . " PASS\n";

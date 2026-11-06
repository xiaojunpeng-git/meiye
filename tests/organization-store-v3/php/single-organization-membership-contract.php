<?php

declare(strict_types=1);

/**
 * 人员单一所属组织合同：所属关系必须替换旧关系，不能与组织授权混用。
 * 静态合同锁定正式写入口和编辑回显，数据库事务行为另由隔离回滚测试覆盖。
 */
$root = dirname(__DIR__, 3);
$organization = file_get_contents($root . '/后端代码/app/services/organization/OrganizationEmployeeServices.php');
$complete = file_get_contents($root . '/后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php');
$editor = file_get_contents($root . '/前端代码/admin/src/pages/setting/staff/add.vue');

if ($organization === false || $complete === false || $editor === false) {
    throw new RuntimeException('single organization membership source missing');
}

$checks = [
    'locks other active memberships' => strpos($organization, "where('org_id', '<>', \$orgId)") !== false
        && strpos($organization, "where('is_del', 0)") !== false
        && strpos($organization, '->lock(true)') !== false,
    'closes other memberships in same save' => strpos($organization, "'is_del' => 1") !== false
        && strpos($organization, "\$close['status'] = 0") !== false,
    'audits replacement' => strpos($organization, "'organization_employee_replace'") !== false
        && strpos($organization, "'replacement_org_id' => \$orgId") !== false,
    'keeps management grants separate' => strpos($organization, 'organization_admin 与门店任职由各自流程维护') !== false,
    'detail only reads active current membership' => substr_count($complete, "->where('oe.status', 1)") >= 1
        && strpos($complete, '一人一个当前组织') !== false,
    'editor no longer presents multiple memberships' => strpos($editor, '已有组织关系：') === false,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
    throw new RuntimeException('FAIL ' . implode('; ', $failed));
}

echo count($checks) . " PASS\n";

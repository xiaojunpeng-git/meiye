<?php

declare(strict_types=1);

/** 组织树找人必须包含有效组织管理员，即使其无门店任职或直属名册关系。 */
$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/organization/OrganizationWorkspaceReadServices.php');
$workspace = file_get_contents($root . '/前端代码/admin/src/pages/store/region/workspace/index.vue');
if ($service === false || $workspace === false) {
    throw new RuntimeException('organization workspace sources missing');
}

$checks = [
    'unions organization admin into employee search' => strpos($service, 'FROM eb_organization_admin oa') !== false
        && strpos($service, 'UNION ({$adminPart})') !== false,
    'requires active exact employee and admin binding' => strpos($service, 'sa.id = oa.admin_id AND sa.employee_id = oa.employee_id') !== false
        && strpos($service, 'AND sa.status=1 AND sa.is_del=0') !== false,
    'returns explicit administrator relationship' => strpos($service, "'admin_grants' => \$item['admin_grants']") !== false,
    'locates an administrator organization in the tree' => strpos($workspace, 'const grants = person.admin_grants || [];') !== false,
    'labels administrator without store assignment' => strpos($workspace, '组织管理员（无任职门店）') !== false,
];

$failed = array_keys(array_filter($checks, static fn ($ok) => !$ok));
if ($failed) {
    throw new RuntimeException('FAIL ' . implode('; ', $failed));
}

echo count($checks) . " PASS\n";

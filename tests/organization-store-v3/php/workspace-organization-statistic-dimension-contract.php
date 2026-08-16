<?php

declare(strict_types=1);

/** 组织统计维度仅允许从落库值读取并保存，不引入组织层级或代码白名单判断。 */
$root = dirname(__DIR__, 3);
$read = file_get_contents($root . '/后端代码/app/services/organization/OrganizationWorkspaceReadServices.php');
$write = file_get_contents($root . '/后端代码/app/services/organization/OrganizationWorkspaceWriteServices.php');
$manage = file_get_contents($root . '/后端代码/app/services/organization/OrganizationManageServices.php');
$controller = file_get_contents($root . '/后端代码/app/controller/admin/v1/organization/Organization.php');
$routes = file_get_contents($root . '/后端代码/route/admin.php');
$api = file_get_contents($root . '/前端代码/admin/src/api/store.js');
$workspace = file_get_contents($root . '/前端代码/admin/src/pages/store/region/workspace/index.vue');

if (in_array(false, [$read, $write, $manage, $controller, $routes, $api, $workspace], true)) {
    throw new RuntimeException('organization statistic dimension sources missing');
}

$checks = [
    'options read from the persisted dimension table' => strpos($read, "Db::name('cashier_v3_report_organization_dimension')") !== false
        && strpos($read, "->group('dimension_code')") !== false,
    'stored codes use Chinese display labels' => strpos($read, "'company' => '分公司'") !== false
        && strpos($read, "'city_manager' => '城市经理'") !== false,
    'current selection is a single persisted value' => strpos($read, "'current_value' => \$currentValue") !== false,
    'organization save carries the selected value inside the existing write flow' => strpos($write, "'statistic_dimension_code' => trim") !== false
        && strpos($write, "'statistic_dimension_provided' => !empty") !== false
        && strpos($write, 'applySaveOrganizationStatisticDimension') !== false,
    'legacy saves do not clear an omitted dimension field' => strpos($controller, "statistic_dimension_provided'] = \$this->request->has('statistic_dimension_code', 'post')") !== false
        && strpos($write, "if ((int)\$payload['statistic_dimension_provided'] === 1)") !== false,
    'persist does not use the report dimension whitelist' => strpos($manage, 'DIMENSION_CODES') === false
        && strpos($manage, 'applySaveOrganizationStatisticDimension') !== false,
    'persist is audited and uses the organization save transaction' => strpos($manage, "'save_statistic_dimension'") !== false
        && strpos($write, "'org_save'") !== false,
    'controller and static route expose option read before parameter route' => strpos($controller, 'function statistic_dimensions') !== false
        && strpos($routes, "Route::get('organization/statistic_dimensions'") !== false
        && strpos($routes, "organization/statistic_dimensions") < strpos($routes, "Route::post('organization/:id'"),
    'platform form uses one select after sort and submits its value' => strpos($workspace, '<label>统计维度</label>') !== false
        && strpos($workspace, 'v-model="orgFormModal.statisticDimensionCode"') !== false
        && strpos($workspace, 'statistic_dimension_code: String(modal.statisticDimensionCode || \'\')') !== false,
    'platform option API is backend sourced' => strpos($api, 'getOrganizationStatisticDimensions') !== false
        && strpos($workspace, 'getOrganizationStatisticDimensions({ org_id: modalOrgId })') !== false,
];

$failed = array_keys(array_filter($checks, static fn ($ok) => !$ok));
if ($failed) {
    throw new RuntimeException('FAIL ' . implode('; ', $failed));
}

echo count($checks) . " PASS\n";

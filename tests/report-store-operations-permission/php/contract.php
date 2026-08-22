<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$menuStore = (string)file_get_contents($root . '/前端代码/admin/src/store/modules/admin/modules/menus.js');
$permissionEditor = (string)file_get_contents($root . '/前端代码/admin/src/pages/store/region/workspace/components/FourChannelPermissionEditor.vue');
$router = (string)file_get_contents($root . '/前端代码/admin/src/router/modules/report.js');
$controller = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$menuController = (string)file_get_contents($root . '/后端代码/app/controller/admin/Common.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-16-平台门店运营报表独立权限/02-正式升级.sql');

function reportPermissionCheck(bool $condition, string $message): void
{
    if ($condition) {
        echo "PASS {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$codes = [
    'partner_item_summary', 'partner_item_detail', 'member_consumption_detail',
    'store_item_analysis', 'store_craftsman_consumption', 'store_salesperson_performance',
    'market_performance', 'market_detail', 'member_visit_analysis', 'member_visit_annual_summary',
    'field_acquisition_detail', 'field_acquisition_summary', 'cross_industry_customer_detail',
    'cross_industry_customer_summary', 'new_customer_analysis', 'new_customer_analysis_summary',
    'salesperson_large_order_statistics', 'store_refund_ledger',
];

reportPermissionCheck(
    !str_contains($menuStore, 'STORE_OPERATION_REPORTS')
    && str_contains($menuStore, 'withoutLegacyStoreOperationsMenu')
    && !str_contains($menuStore, 'dataMenu.children.push({'),
    'platform menu no longer manufactures report entries in the browser'
);
reportPermissionCheck(
    str_contains($permissionEditor, 'check-directly')
    && str_contains($permissionEditor, '@on-toggle-expand="onToggleExpand"')
    && str_contains($permissionEditor, 'expandedIds')
    && str_contains($permissionEditor, 'syncFromTreeRef(checkedNodes)')
    && str_contains($permissionEditor, 'mergeExpandState'),
    'permission tree keeps independent checkbox state and expansion state'
);
reportPermissionCheck(
    preg_match("/path: 'store-operations\\/:report',[\\s\\S]*?meta: \\{[\\s\\S]*?title: '门店运营报表'/", $router) === 1
    && !str_contains(substr($router, strpos($router, "path: 'store-operations/:report'"), 260), "auth: ['report-sale-info']"),
    'report route defers access to report-specific server authorization'
);
reportPermissionCheck(
    str_contains($controller, 'private const STORE_OPERATION_REPORTS')
    && str_contains($controller, 'canAccessStoreOperationReport($report)')
    && str_contains($controller, "'当前账号未配置该报表权限'")
    && str_contains($controller, 'getRolesByAuth($roles, 1)'),
    'query and export use the assigned page menu as their authority'
);
reportPermissionCheck(
    str_contains($menuController, 'admin_common_menu_list_v2_')
    && str_contains($menuController, '$ruleIds'),
    'menu cache is isolated by assigned role rules'
);
foreach ($codes as $code) {
    reportPermissionCheck(
        str_contains($migration, "'{$code}'")
        && str_contains($migration, "admin-report-store-operations-',r.report_code"),
        "migration registers {$code} as an independent menu"
    );
}
reportPermissionCheck(
    preg_match('/UPDATE `eb_system_menus` parent_menu[\\s\\S]*?SET parent_menu\\.`pid`=data_menu\\.`id`[\\s\\S]*?parent_menu\\.`is_show`=0/', $migration) === 1
    && str_contains($migration, "'admin-report-store-operations'"),
    'legacy aggregate menu is hidden instead of remaining an alternate entrance'
);
reportPermissionCheck(
    str_contains($migration, "SELECT data_menu.id,1,'ios-stats-outline'")
    && !str_contains($migration, "SELECT parent_menu.id,1,'ios-stats-outline'"),
    'independent report menus are attached directly below the data menu'
);

echo "store operations permission contract: PASS\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$cashierMiddleware = file_get_contents($root . '/后端代码/app/http/middleware/cashier/CashierCheckRoleMiddleware.php');
$storeMiddleware = file_get_contents($root . '/后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php');
$policies = file_get_contents($root . '/后端代码/app/services/cashier/v3/permission/CashierV3PermissionPolicyRegistry.php');
$staffRegistrar = file_get_contents($root . '/后端代码/app/services/query/provider/StaffUnifiedQueryPageRegistrar.php');

$checks = [
    '员工保存按新增与编辑分别判权' => strpos($cashierMiddleware, "'cashier.v3.staff.edit' : 'cashier.v3.staff.create'") !== false,
    '员工保存门禁绑定 V3 管理路由' => strpos($cashierMiddleware, 'cashierapi/v3/management/staff/') !== false,
    '预售列表要求库存出库入口' => strpos($storeMiddleware, "return 'cashier.v3.inventory.outbound';") !== false,
    '预售明细要求独立权限' => strpos($storeMiddleware, "return 'cashier.v3.inventory.presale_claim.detail';") !== false,
    '预售领用要求独立权限' => strpos($storeMiddleware, "return 'cashier.v3.inventory.presale_claim.create';") !== false,
    '预售作废要求独立权限' => strpos($storeMiddleware, "return 'cashier.v3.inventory.presale_claim.void';") !== false,
    '组织查看会话拒绝预售写入' => strpos($storeMiddleware, '不能执行预售领用或作废操作') !== false,
    '旧门店会话仍保留原角色门禁' => strpos($storeMiddleware, '$services->verifiAuth($request);') !== false,
    '员工导出使用独立权限' => strpos($staffRegistrar, "'exportFeature' => 'cashier.v3.staff.export'") !== false
        && strpos($policies, "\$action === 'create-unified-query-export' && \$pageCode === 'staff_list'") !== false
        && strpos($policies, "\$feature = 'cashier.v3.staff.export';") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, '门店端一期直连接口权限契约失败：' . implode('、', $failed) . PHP_EOL);
    exit(1);
}

echo '门店端一期直连接口权限契约通过。' . PHP_EOL;

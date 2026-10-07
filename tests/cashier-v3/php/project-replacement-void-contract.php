<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码/app/services/cashier/v3';
$files = [
    'void' => "$backend/order/CashierV3OrderCenterVoidServices.php",
    'rules' => "$backend/card/CashierV3CardRuleEntitlementAuthorityServices.php",
    'query' => "$backend/order/CashierV3OrderCenterRecordQueryServices.php",
    'lifecycle' => "$backend/order/CashierV3OrderLifecycleModule.php",
    'manifest' => "$backend/manifest/CashierV3ActionManifest.php",
    'permission' => "$backend/manifest/CashierV3C5MemberOrderModule.php",
    'view' => "$root/前端代码/cashier-v3/src/views/OrderCenterView.vue",
    'frontendManifest' => "$root/前端代码/cashier-v3/src/services/cashierV3ActionManifest.js",
    'bridge' => "$root/前端代码/cashier-v3/src/services/cashierV3Bridge.js",
];
$source = array_map(static fn(string $file): string => (string)file_get_contents($file), $files);
$checks = [
    'V3 replacement only' => str_contains($source['void'], "operation_type'] !== 'project_replacement'")
        && str_contains($source['void'], "^card-operation:"),
    'permission and event registered' => str_contains($source['permission'], "void-order-center-project-replacement")
        && str_contains($source['manifest'], "card.operation.voided")
        && str_contains($source['lifecycle'], "void-order-center-project-replacement"),
    'frontend action passes registration and context gates' => str_contains($source['frontendManifest'], "'void-order-center-project-replacement': FEATURE_ORDER_VOID")
        && substr_count($source['bridge'], "'void-order-center-project-replacement'") >= 2,
    'UI limited to completed replacement' => str_contains($source['view'], "record?.operationType === 'project_replacement'")
        && str_contains($source['view'], "record?.recordStatus === '已完成'"),
    'list action opens void form directly' => str_contains($source['view'], 'v-if="canVoidProjectReplacement(record)"')
        && str_contains($source['view'], "openRecordDetail(record, 'replacement-void')")
        && str_contains($source['view'], ':initial-lifecycle-action="initialDetailLifecycleAction"'),
    'source, target and card reversed atomically' => str_contains($source['void'], 'reverseProjectComponentsInTx(')
        && str_contains($source['void'], "'write_surplus_times' => (int)\$line['quantity_before']")
        && str_contains($source['void'], "'last_operation_id' => (string)\$void['operation_id']"),
    'downstream occupancy blocks reversal' => str_contains($source['void'], "Db::name('cashier_v3_entitlement_writeoff_fact')")
        && str_contains($source['void'], "Db::name('cashier_v3_service_order_line')")
        && str_contains($source['void'], "Db::name('cashier_v3_reservation_entitlement_occupation')"),
    'rule projection rejects changed target' => str_contains($source['rules'], "replacementOperationId")
        && str_contains($source['rules'], "'status' => 'revoked'"),
    'list status derived from void audit' => str_contains($source['query'], 'v.id AS void_operation_id')
        && str_contains($source['query'], "'voided' => '已作废'"),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo $failed === 0 ? "PROJECT_REPLACEMENT_VOID_CONTRACT=PASS\n" : "PROJECT_REPLACEMENT_VOID_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);

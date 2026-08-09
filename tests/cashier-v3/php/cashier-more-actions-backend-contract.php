<?php

$root = dirname(__DIR__, 3);
$servicePath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierMoreActionServices.php';
$modulePath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php';
$manifestPath = $root . '/后端代码/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php';
$eventPath = $root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php';
$preparationPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php';
$kernelPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php';
$repositoryPath = $root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php';
$salesOrderPath = $root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php';
$paymentPlanPath = $root . '/后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php';
$factPath = $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php';
$workspacePath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php';
$migrationDir = $root . '/后端代码/database/upgrades/2026-08-05-收银V3更多操作权威';

$files = [
    $servicePath, $modulePath, $manifestPath, $eventPath, $preparationPath,
    $kernelPath, $repositoryPath, $salesOrderPath, $paymentPlanPath, $factPath,
    $workspacePath,
];
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing file: {$file}\n");
        exit(1);
    }
}
$service = file_get_contents($servicePath);
$module = file_get_contents($modulePath);
$manifest = file_get_contents($manifestPath);
$event = file_get_contents($eventPath);
$preparation = file_get_contents($preparationPath);
$kernel = file_get_contents($kernelPath);
$repository = file_get_contents($repositoryPath);
$salesOrder = file_get_contents($salesOrderPath);
$paymentPlan = file_get_contents($paymentPlanPath);
$fact = file_get_contents($factPath);
$workspace = file_get_contents($workspacePath);
$applySql = file_get_contents($migrationDir . '/02-正式升级.sql');
$preSql = file_get_contents($migrationDir . '/01-升级前检查.sql');
$postSql = file_get_contents($migrationDir . '/03-升级后验证.sql');

$checks = [
    'transaction guard' => strpos($service, "assertInTransaction('cashierMoreAction:'") !== false,
    'workspace binding lock' => strpos($service, "->where('state_context_id', \$stateContextId)") !== false
        && strpos($service, "->lock(true)") !== false,
    'three canonical commands' => strpos($service, "'update-cashier-order-note'") !== false
        && strpos($service, "'update-cashier-line-price'") !== false
        && strpos($service, "'update-cashier-supplement'") !== false,
    'module handlers and policies' => substr_count($module, "'update-cashier-order-note'") >= 2
        && substr_count($module, "'update-cashier-line-price'") >= 2
        && substr_count($module, "'update-cashier-supplement'") >= 2,
    'manifest command ownership' => strpos($manifest, "'update-cashier-order-note'") !== false
        && strpos($manifest, "'update-cashier-line-price'") !== false
        && strpos($manifest, "'update-cashier-supplement'") !== false,
    'eventless workspace edits' => strpos($event, "'update-cashier-line-price' => \$eventless(\$workspaceDraft)") !== false,
    'current sku locked cost' => strpos($service, "->field('id,product_id,cost,price,ot_price')") !== false
        && strpos($service, "'price_change_below_configured_cost'") !== false
        && strpos($service, '$minimumLineAmountCents') !== false,
    'price change requires whole yuan and rounds cost floor upward' => strpos($service, "'price_change_whole_yuan_required'") !== false
        && strpos($service, '$lineAmountCents % 100 !== 0') !== false
        && strpos($service, 'roundUpToWholeYuan(') !== false,
    'price audit saved' => strpos($service, "'configured_cost_cents'") !== false
        && strpos($service, "'price_change_reason'") !== false
        && strpos($service, "'price_changed_by_name_snapshot'") !== false
        && strpos($service, "'price_changed_at'") !== false,
    'supplement keeps real operation time' => strpos($service, "'supplement_business_date'") !== false
        && strpos($service, "'supplement_operated_at' => \$now") !== false
        && strpos($service, "'update_time' => time()") !== false,
    'future supplement rejected' => strpos($service, "'supplement_business_date_in_future'") !== false,
    'order note persisted' => strpos($service, "'order_note' => \$note") !== false,
    'no legacy order writes' => strpos($service, "Db::name('store_order')") === false
        && strpos($service, "Db::name('store_order_cart_info')") === false
        && strpos($service, "Db::name('user_card_holder')") === false,
    'migration is mysql56 rerunnable' => strpos($applySql, 'MySQL 5.6 compatible') !== false
        && strpos($applySql, 'cashier_v3_add_column_if_missing') !== false,
    'migration does not backfill old data' => preg_match('/\bUPDATE\s+`?eb_/i', $applySql) !== 1
        && strpos($applySql, 'no old-data backfill') !== false,
    'migration pre and post checks' => strpos($preSql, 'PRECHECK_OK') !== false
        && strpos($postSql, 'POSTCHECK_OK') !== false,
    'audit propagated schema' => substr_count($applySql, "'configured_cost_cents'") === 3
        && substr_count($applySql, "'order_note'") === 3,
    'workspace audit enters locked checkout snapshot' => strpos(
        $preparation,
        "'businessDate' => \$supplementEnabled ? \$supplementBusinessDate : date('Y-m-d', \$now)"
    ) !== false
        && strpos($preparation, "'occurredAt' => \$now") !== false
        && strpos($preparation, "'orderNote' =>") !== false
        && strpos($preparation, "'configuredCostCents' =>") !== false,
    'checkout request and lines persist full audit' => strpos($kernel, "'orderNote' => \$snapshot['orderNote']") !== false
        && strpos($kernel, "'supplementOperatedAt' => \$snapshot['supplement']['operatedAt']") !== false
        && strpos($kernel, "'configuredCostCents' => \$line['configuredCostCents']") !== false
        && strpos($repository, "'orderNote' => 'order_note'") !== false
        && strpos($repository, "'priceChangedAt' => 'price_changed_at'") !== false,
    'sales order freezes note supplement and price audit' => strpos($salesOrder, "'order_note' => \$request['order_note']") !== false
        && strpos($salesOrder, "'supplement_operated_at' => \$request['supplement_operated_at']") !== false
        && strpos($salesOrder, "'configured_cost_cents' => \$line['configured_cost_cents']") !== false
        && strpos($salesOrder, "'price_changed_at' => \$line['price_changed_at']") !== false,
    'strict settlement consumers accept audit shape' => strpos($paymentPlan, "'order_note', 'supplement_enabled'") !== false
        && strpos($fact, "'order_note', 'supplement_enabled'") !== false
        && strpos($fact, "'configured_cost_cents', 'price_change_reason'") !== false,
    'completed checkout clears order-scoped draft state' => strpos($workspace, "'order_note' => ''") !== false
        && strpos($workspace, "'supplement_enabled' => 0") !== false
        && strpos($workspace, "'supplement_business_date' => null") !== false
        && strpos($workspace, "'supplement_reason' => ''") !== false
        && strpos($workspace, "'supplement_operator_id' => 0") !== false
        && strpos($workspace, "'supplement_operator_name_snapshot' => ''") !== false
        && strpos($workspace, "'supplement_operated_at' => 0") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo 'cashier more-actions backend contract PASS (' . count($checks) . " checks)\n";

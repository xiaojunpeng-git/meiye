<?php

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码';
$frontend = $root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue';

$passed = 0;
$failed = 0;

function exportOk(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
}

$contract = (string)file_get_contents($backend . '/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php');
$provider = (string)file_get_contents($backend . '/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php');
$salesQuery = (string)file_get_contents($backend . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$commandCoordinator = (string)file_get_contents($backend . '/app/services/query/UnifiedQueryCommandCoordinator.php');
$exportJob = (string)file_get_contents($backend . '/app/jobs/query/UnifiedQueryExportJob.php');
$exportWorker = (string)file_get_contents($backend . '/app/services/query/UnifiedQueryExportWorkerServices.php');
$workerResolver = (string)file_get_contents($backend . '/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php');
$config = (string)file_get_contents($backend . '/config/unified_query.php');
$view = (string)file_get_contents($frontend);

$pages = [
    'sales' => 'order_center_sales',
    'recharge' => 'order_center_recharge',
    'refund' => 'order_center_refund',
    'debt' => 'order_center_debt',
    'service' => 'order_center_service',
    'supplement' => 'order_center_supplement',
    'gift' => 'order_center_gift',
    'card_operation' => 'order_center_card_operation',
];

foreach ($pages as $type => $pageCode) {
    exportOk("{$type} 页签登记统一查询导出页码", strpos($contract, "'{$type}' => '{$pageCode}'") !== false);
    exportOk("{$type} 页签前端绑定同一导出页码", strpos($view, "pageCode: '{$pageCode}'") !== false);
}

exportOk('订单中心统一查询 registrar 已登记', strpos($config, 'CashierV3OrderCenterUnifiedQueryRegistrar::class') !== false);
exportOk('八类订单记录 provider 已登记', substr_count($config, 'CashierV3') >= 10
    && strpos($config, 'CashierV3CardOperationUnifiedQueryProvider::class') !== false);
exportOk('异步导出 worker 会重建订单中心权限上下文', strpos($config, 'CashierV3OrderCenterUnifiedQueryWorkerContextResolver::class') !== false
    && strpos($provider, 'authoritativeScopes') !== false
    && strpos($provider, 'CashierV3Bootstrap::dispatcher()') !== false
    && strpos($workerResolver, "['pageCode' => (string)(\$task['page_code'] ?? '')]") !== false
    && strpos($exportWorker, "method_exists(\$resolver, 'pageCodes')") !== false
    && strpos($exportWorker, 'in_array($pageCode, $resolvedPageCodes, true)') !== false);
exportOk('导出只读取现有 V3 订单中心权威投影', strpos($provider, 'querySalesOrders') !== false
    && strpos($provider, 'queryRecords') !== false
    && strpos($provider, 'store_order_refund') === false);
exportOk('导出冻结最近一次成功查询，而不是浏览器当前行', strpos($view, 'saveExecutedQuery(recordType, nextQuery)') !== false
    && strpos($view, 'saveExecutedQuery(recordType, cursorQuery)') !== false
    && strpos($view, ':executed-query="executedQuery"') !== false
    && strpos($view, ':on-create-export="createActiveExport"') !== false);
exportOk('导出切换账号或门店时清除旧快照', strpos($view, 'executedQueryByType.value = {}') !== false);
exportOk('销售订单只显示结账时选择的最终客户来源', strpos($contract, "['source', '客户来源']") !== false
    && strpos($view, "field('source', '客户来源')") !== false
    && strpos($salesQuery, "'source' => \$this->businessSourceLabel(") !== false
    && strpos($salesQuery, "business_source_secondary_name_snapshot") !== false
    && substr_count($salesQuery, 'business_source_primary_name_snapshot') >= 3
    && strpos($salesQuery, "return '—';") !== false);
exportOk('导出任务创建后会投递后台执行器', strpos($commandCoordinator, 'UnifiedQueryExportJob::dispatch([$taskNo])') !== false
    && strpos($exportJob, 'processPending(1, $taskNo)') !== false);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
echo $failed === 0 ? "ORDER_CENTER_UNIFIED_EXPORT=PASS\n" : "ORDER_CENTER_UNIFIED_EXPORT=FAIL\n";
exit($failed === 0 ? 0 : 1);

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
$toolbar = (string)file_get_contents($root . '/前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue');
$exportDrawer = (string)file_get_contents($root . '/前端代码/shared/unified-query-vue3/src/components/UnifiedQueryExportDrawer.vue');
$module = (string)file_get_contents($backend . '/app/services/cashier/v3/query/UnifiedQueryModule.php');
$commandController = (string)file_get_contents($backend . '/app/controller/cashier/v3/Command.php');
$recordQuery = (string)file_get_contents($backend . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$contextFactory = (string)file_get_contents($backend . '/app/services/query/UnifiedQueryContextFactory.php');
$permissionPolicy = (string)file_get_contents($backend . '/app/services/cashier/v3/permission/CashierV3PermissionPolicyRegistry.php');

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

exportOk('销售日期与其余七类业务日期均为当天默认的起止日期范围', substr_count($view, "field('business_date', '销售日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true })") === 1
    && substr_count($view, "field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true })") === 7
    && strpos($contract, "['sales_order_no', '销售订单号', 'text', true, true], ['business_date', '销售日期', 'date', true, true]") !== false
    && strpos($contract, "['debt_no', '欠款编号', 'text', true, true], ['business_date', '业务日期', 'date', true, true]") !== false
    && strpos($toolbar, 'function isQuickDateRange(field)') !== false
    && strpos($toolbar, 'function ensureQuickDateRangeDefaults()') !== false
    && strpos($toolbar, '<span>从</span>') !== false
    && strpos($toolbar, 'aria-label="结束日期"') !== false
    && strpos($view, 'inline-quick-controls') !== false
    && strpos($view, 'compact-keyword-search') !== false
    && strpos($toolbar, 'compactKeywordSearch') !== false);
exportOk('销售列表区分销售归属日与真实下单时间，旧单不以支付时间冒充下单',
    strpos($view, "<span>销售日期：{{ displayRecordField(record, 'business_date') }}</span>") !== false
    && strpos($view, "<span>实际下单时间：{{ displayRecordField(record, 'occurred_at') }}</span>") !== false
    && strpos($view, "occurred_at: ['occurredAt']") !== false
    && strpos($salesQuery, "'o.add_time', 'o.pay_time'") !== false
    && strpos($salesQuery, "(int)(\$row['add_time'] ?? 0)") !== false);
exportOk('订单中心日期范围会传给销售与其余七类记录的真实查询', strpos($view, 'function normalizeOrderCenterDateQuery(query = {})') !== false
    && strpos($view, 'queryRecords(defaultOrderCenterDateQuery(), true)') !== false
    && strpos($recordQuery, 'private function businessDateRange(array $payload): array') !== false
    && strpos($recordQuery, 'private function applyBusinessDateRange($query, string $field, array $criteria): void') !== false
    && strpos($recordQuery, 'private function applyTimestampBusinessDateRange($query, string $field, array $criteria): void') !== false
    && substr_count($recordQuery, 'applyBusinessDateRange($query') >= 6
    && substr_count($recordQuery, 'applyTimestampBusinessDateRange($query') >= 7);

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
exportOk('订单中心导出快照不会携带旧列表 status 参数', strpos($view, 'status: ignoredStatus') !== false);
exportOk('服务记录前后端字段合同一致，能力不会因工资项目数字段降级', strpos($contract, "['project_count', '工资项目数', 'integer']") !== false
    && strpos($view, "field('project_count', '工资项目数', 'number')") !== false);
exportOk('工作台上下文就绪后会重试当前页签统一查询能力', strpos($view, "activeUnifiedQuery.value?.load({ silent: true })") !== false);
exportOk('订单中心导出位于设置右侧且固定导出当前查询结果', strpos($view, 'export-button-after-settings') !== false
    && strpos($view, 'direct-query-export') !== false
    && strpos($toolbar, 'exportButtonAfterSettings') !== false
    && strpos($toolbar, "scope: 'query'") !== false
    && strpos($toolbar, 'includeSummary: false') !== false);
exportOk('导出是查询权限的附属能力，不额外要求导出功能权限', strpos($contextFactory, "'exportAllowed' => \$pageAllowed") !== false
    && strpos($contextFactory, "\$authorization['exportAllowed'] = \$authorization['pageAllowed'];") !== false
    && strpos($permissionPolicy, "\$action === 'create-unified-query-export' && \$pageCode === 'staff_list'") === false);
exportOk('订单中心下载导出文件会携带门店会话并校验 xlsx 二进制', strpos($view, 'on-download-export="downloadActiveExport"') !== false
    && strpos($view, 'readStoreV3SessionToken') !== false
    && strpos($view, "Authorization: `Bearer \${token}`") !== false
    && strpos($view, 'header[0] !== 0x50') !== false
    && strpos($toolbar, 'onDownloadExport') !== false
    && strpos($exportDrawer, 'onDownload') !== false
    && strpos($exportDrawer, '@click="downloadFile"') !== false);
exportOk('导出下载按任务冻结页签重建上下文并二次校验', strpos($module, "/download?pageCode=") !== false
    && strpos($module, "rawurlencode((string)(\$task['pageCode'] ?? \$payload['pageCode']))") !== false
    && strpos($commandController, "'pageCode' => trim((string)\$this->request->get('pageCode', ''))") !== false
    && strpos($view, "endpoint.searchParams.set('pageCode', pageCode)") !== false);
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

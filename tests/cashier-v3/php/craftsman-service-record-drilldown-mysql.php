<?php

declare(strict_types=1);

/**
 * 只读集成验证：从本地真实劳动业绩事实选一笔当前有效记录，沿报表下钻
 * 找回服务记录；若存在作废夹具，再验证原事实与冲销事实均不进入经营报表。
 * 脚本不写库、不创建测试订单。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\StoreReportNormalDataScopeServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
$sampleQuery = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_entitlement_service_fact sf',
        'sf.tenant_id=pf.tenant_id AND sf.store_id=pf.store_id AND sf.checkout_request_id=pf.checkout_request_id AND sf.source_line_id=pf.source_line_id')
    ->where('pf.performance_type', 'labor_performance_allocated')
    ->where('pf.status', 'effective')->where('pf.employee_id', '>', 0)
    ->where('pf.amount_cents', '<>', 0)
    ->field('pf.tenant_id,pf.store_id,pf.employee_id,pf.business_date,pf.checkout_request_id,pf.source_line_id,sf.service_record_no,sf.service_fact_id');
$normalScope = new StoreReportNormalDataScopeServices();
$normalScope->excludeVoidedSalesOrderFacts($sampleQuery, 'pf.tenant_id', 'pf.order_id');
$normalScope->excludeVoidedServicePerformanceFacts($sampleQuery, 'pf');
$sample = (clone $sampleQuery)->order('pf.id', 'desc')->find();
if (!$sample) throw new RuntimeException('No local service labor fact fixture');

$verify = static function (array $fixture, string $case): array {
    $storeId = (int)$fixture['store_id'];
    $employeeId = (int)$fixture['employee_id'];
    $tenantId = (string)$fixture['tenant_id'];
    $date = (string)$fixture['business_date'];
    $scope = new CashierV3DataScopeContext(
        71, 701, $storeId, $tenantId, 'organization:test', [$storeId],
        CashierV3DataScopeContext::MODE_ALL, [], true, 'test-super', 'permission-v2',
        ['cashier.v3.order_center'], ['account' => 'craftsman-drill-readonly']
    );
    $operator = new CashierV3OperatorScope($storeId, 71, 'organization:test', $tenantId);
    $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'service', 'storeIds' => [$storeId], 'dataScope' => 'all',
        'page' => 1, 'pageSize' => 100,
        'servicePerformanceDrilldown' => [
            'employeeId' => $employeeId, 'storeId' => $storeId,
            'from' => $date, 'to' => $date, 'dayOfMonth' => (int)substr($date, 8, 2),
        ],
    ], $operator, $scope);
    $matched = null;
    foreach ($page['records'] as $record) {
        if ((string)($record['serviceRecordNo'] ?? '') === (string)($fixture['service_record_no'] ?: $fixture['service_fact_id'])) {
            $matched = $record;
            break;
        }
    }
    if (!$matched) throw new RuntimeException($case . ' fact source service record missing from drilldown');
    $facts = (array)($matched['reportPerformanceFacts'] ?? []);
    if ($facts === []) throw new RuntimeException($case . ' fact breakdown missing');
    $sum = array_sum(array_column($facts, 'amountCents'));
    $expected = (int)Db::name('cashier_v3_performance_fact')
        ->where('tenant_id', $tenantId)->where('store_id', $storeId)
        ->where('employee_id', $employeeId)->where('performance_type', 'labor_performance_allocated')
        ->where('status', 'effective')->where('business_date', $date)
        ->where('checkout_request_id', (string)$fixture['checkout_request_id'])
        ->where('source_line_id', (string)$fixture['source_line_id'])->sum('amount_cents');
    if ($sum !== $expected) throw new RuntimeException($case . ' signed fact sum differs from source');
    $laborSum = array_sum(array_column($facts, 'laborFeeCents'));
    $expectedLabor = (int)Db::name('cashier_v3_performance_fact')
        ->where('tenant_id', $tenantId)->where('store_id', $storeId)
        ->where('employee_id', $employeeId)->where('performance_type', 'labor_performance_allocated')
        ->where('status', 'effective')->where('business_date', $date)
        ->where('checkout_request_id', (string)$fixture['checkout_request_id'])
        ->where('source_line_id', (string)$fixture['source_line_id'])->sum('labor_fee_amount_cents');
    if ($laborSum !== $expectedLabor) throw new RuntimeException($case . ' signed labor sum differs from source');
    if ($case === 'reversal' && !in_array('冲销', array_column($facts, 'direction'), true)) {
        throw new RuntimeException('Reversal fact missing from source service breakdown');
    }
    echo 'craftsman service record drilldown mysql: PASS ' . $case
        . '; records=' . (int)$page['total'] . '; matched_facts=' . count($facts)
        . '; signed_cents=' . $sum . "\n";
    return $matched;
};
$verify($sample, 'forward');
$voidedForward = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_entitlement_service_fact sf',
        'sf.tenant_id=pf.tenant_id AND sf.store_id=pf.store_id AND sf.checkout_request_id=pf.checkout_request_id AND sf.source_line_id=pf.source_line_id')
    ->join('cashier_v3_service_record_void_operation vo',
        "vo.tenant_id=sf.tenant_id AND vo.service_fact_id=sf.id AND vo.status='succeeded'")
    ->where('pf.performance_type', 'labor_performance_allocated')
    ->where('pf.status', 'effective')->where('pf.fact_direction', 'forward')
    ->where('sf.service_status', 'completed')
    ->where('pf.labor_fee_amount_cents', '>', 0)->where('pf.employee_id', '>', 0)
    ->field('pf.tenant_id,pf.store_id,pf.employee_id,pf.business_date,pf.order_id,pf.checkout_request_id,pf.source_line_id,sf.service_record_no,sf.service_fact_id')
    ->order('pf.id', 'desc');
// 与下钻查询使用同一“非作废销售单”边界，避免抽到列表本就必须排除的夹具。
(new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($voidedForward, 'pf.tenant_id', 'pf.order_id');
$voidedForward = $voidedForward->find();
if ($voidedForward) {
    $storeId = (int)$voidedForward['store_id'];
    $employeeId = (int)$voidedForward['employee_id'];
    $tenantId = (string)$voidedForward['tenant_id'];
    $date = (string)$voidedForward['business_date'];
    $detail = (new RegisteredMetricReadServices())->personnelDetailResult(
        'staff_labor_yeji', $tenantId, [$storeId], ['start' => $date, 'end' => $date], [$employeeId], 0, true
    );
    foreach ($detail['rows'] as $row) {
        if ((string)$row['order_id'] === (string)$voidedForward['order_id']
            && (string)$row['source_line_id'] === (string)$voidedForward['source_line_id']) {
            throw new RuntimeException('Voided service remained in current-service report detail');
        }
    }

    $scope = new CashierV3DataScopeContext(
        71, 701, $storeId, $tenantId, 'organization:test', [$storeId],
        CashierV3DataScopeContext::MODE_ALL, [], true, 'test-super', 'permission-v2',
        ['cashier.v3.order_center'], ['account' => 'craftsman-drill-readonly']
    );
    $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'service', 'storeIds' => [$storeId], 'dataScope' => 'all',
        'page' => 1, 'pageSize' => 100,
        'servicePerformanceDrilldown' => [
            'employeeId' => $employeeId, 'storeId' => $storeId,
            'from' => $date, 'to' => $date, 'dayOfMonth' => (int)substr($date, 8, 2),
        ],
    ], new CashierV3OperatorScope($storeId, 71, 'organization:test', $tenantId), $scope);
    foreach ($page['records'] as $record) {
        if ((string)($record['serviceRecordNo'] ?? '') === (string)($voidedForward['service_record_no'] ?: $voidedForward['service_fact_id'])) {
            throw new RuntimeException('Voided service remained in current-service report drilldown');
        }
    }
    echo "craftsman service record drilldown mysql: PASS voided service excluded from every report date\n";
} else {
    echo "craftsman service record drilldown mysql: SKIP voided exclusion (no local fixture)\n";
}

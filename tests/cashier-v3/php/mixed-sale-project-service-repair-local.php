<?php
declare(strict_types=1);

/** Verify every audited mixed-checkout repair against its frozen sources. */
if (getenv('MIXED_SERVICE_REPAIR_LOCAL_TEST') !== '1') {
    throw new RuntimeException('explicit local verification flag required');
}
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->initialize();

use app\services\query\metric\ServiceCustomerMetricReadServices;
use think\facade\Db;

$audits = Db::name('cashier_v3_mixed_sale_service_repair_audit')->select()->toArray();
if ($audits === []) throw new RuntimeException('no local repair audit rows');
$scope = [];
foreach ($audits as $audit) {
    $line = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $audit['tenant_id'])
        ->where('order_line_id', $audit['source_line_id'])->find();
    $order = Db::name('cashier_v3_sales_order')->where('tenant_id', $audit['tenant_id'])
        ->where('order_id', $audit['order_id'])->find();
    $fact = Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $audit['service_fact_id'])->find();
    if (!$line || !$order || !$fact) throw new RuntimeException('audited source/fact missing: ' . $audit['source_line_id']);
    if ((string)$line['immutable_fingerprint'] !== (string)$audit['source_line_fingerprint']
        || (string)$order['immutable_fingerprint'] !== (string)$audit['source_order_fingerprint']
        || (string)$fact['immutable_fingerprint'] !== (string)$audit['service_fingerprint']
        || (string)$fact['source_line_id'] !== (string)$line['order_line_id']
        || (string)$fact['checkout_request_id'] !== (string)$order['checkout_request_id']
        || (string)$fact['document_id'] !== (string)$order['order_id']
        || (string)$fact['service_record_no'] !== (string)$audit['service_record_no']
        || (int)$fact['quantity'] !== (int)$line['quantity']
        || (string)$fact['craftsmen_snapshot_json'] !== (string)$line['craftsmen_snapshot_json']
        || (int)$fact['occurred_at'] !== (int)$order['occurred_at']
        || (int)$fact['recorded_at'] < (int)$order['settled_at']) {
        throw new RuntimeException('audited service drift: ' . $audit['source_line_id']);
    }
    $labor = Db::name('cashier_v3_performance_fact')->where('tenant_id', $audit['tenant_id'])
        ->where('checkout_request_id', $order['checkout_request_id'])
        ->where('source_line_id', $line['order_line_id'])
        ->where('performance_type', 'labor_performance_allocated')
        ->where('fact_direction', 'forward')
        ->whereLike('rule_code_snapshot', 'SALE-PROJECT-LABOR%')->select()->toArray();
    $ids = array_column($labor, 'fact_id');
    sort($ids, SORT_STRING);
    $fees = array_sum(array_map(static function (array $row): int {
        return (int)$row['labor_fee_amount_cents'];
    }, $labor));
    if (hash('sha256', json_encode($ids)) !== (string)$audit['original_labor_fingerprint']
        || (int)$fact['labor_fee_amount_cents'] !== $fees) {
        throw new RuntimeException('audited labor drift: ' . $audit['source_line_id']);
    }
    $scope[$order['tenant_id'] . '|' . $order['store_id'] . '|' . $order['business_date']] = [
        (string)$order['tenant_id'], (int)$order['store_id'], (string)$order['business_date'],
    ];
}
foreach ($scope as $triple) {
    $metrics = (new ServiceCustomerMetricReadServices())->employeeMetrics(
        $triple[0], [$triple[1]], ['start' => $triple[2], 'end' => $triple[2]]
    );
    if (!is_array($metrics['summary_by_store_employee'] ?? null)
        || !is_array($metrics['detail_by_labor_key'] ?? null)) {
        throw new RuntimeException('salary metric reader failed for ' . implode('|', $triple));
    }
}
echo 'PASS audited lines=' . count($audits) . ', affected tenant/store/days=' . count($scope)
    . ', live salary reader scopes=' . count($scope) . PHP_EOL;

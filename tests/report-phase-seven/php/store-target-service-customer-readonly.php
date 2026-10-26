<?php

declare(strict_types=1);

/**
 * 只读本地回归：目标页两项客数必须等于统一员工服务客数出口。
 * 固定样本 FW100200008 是“朋友不算”；实际消耗和项目数仍留在排行。
 */
if (getenv('STORE_TARGET_CUSTOMER_READONLY') !== '1') {
    throw new RuntimeException('explicit read-only local flag required');
}
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->initialize();

use app\services\cashier\v3\dashboard\CashierV3StoreTargetDashboardReadModel;
use app\services\query\metric\ServiceCustomerMetricReadServices;
use think\facade\Db;

function targetCustomerCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$service = Db::name('cashier_v3_entitlement_service_fact')
    ->where('service_record_no', 'FW100200008')
    ->field('tenant_id,store_id,business_date,service_object,friend_counts_as_customer,checkout_request_id,source_line_id')
    ->find();
targetCustomerCheck(is_array($service)
    && (string)$service['service_object'] === 'friend'
    && (int)$service['friend_counts_as_customer'] === 0, 'fixed service is excluded friend');
$storeId = (int)$service['store_id'];
$date = (string)$service['business_date'];
$range = ['start' => substr($date, 0, 7) . '-01', 'end' => $date];
$labor = Db::name('cashier_v3_performance_fact')
    ->where('tenant_id', (string)$service['tenant_id'])
    ->where('checkout_request_id', (string)$service['checkout_request_id'])
    ->where('source_line_id', (string)$service['source_line_id'])
    ->where('performance_type', 'labor_performance_allocated')
    ->where('fact_direction', 'forward')->where('status', 'effective')
    ->field('employee_id,amount_cents')->find();
targetCustomerCheck(is_array($labor) && (int)$labor['employee_id'] > 0
    && (int)$labor['amount_cents'] > 0, 'excluded friend still has valid labor fact');

$method = new ReflectionMethod(CashierV3StoreTargetDashboardReadModel::class, 'consumptionRanking');
$method->setAccessible(true);
$ranking = $method->invoke(new CashierV3StoreTargetDashboardReadModel(),
    (string)$service['tenant_id'], $storeId, $range);
$employeeId = (int)$labor['employee_id'];
$row = null;
foreach ($ranking['records'] as $record) {
    if ((int)$record['employee_id'] === $employeeId) $row = $record;
}
targetCustomerCheck(is_array($row), 'employee remains in consumption ranking');
$metrics = (new ServiceCustomerMetricReadServices())->employeeMetrics(
    (string)$service['tenant_id'], [$storeId], $range
);
$summary = $metrics['summary_by_store_employee'][$storeId . '|' . $employeeId] ?? [];
targetCustomerCheck((float)$row['service_count'] === (int)($summary['visit_tenths'] ?? 0) / 10
    && (float)$row['customer_count'] === (int)($summary['people_tenths'] ?? 0) / 10,
    'target customer columns equal unified reader');
targetCustomerCheck((int)$row['consumption_performance_cents'] >= (int)$labor['amount_cents']
    && (float)$row['project_count'] > 0, 'friend consumption and project count remain');
echo "TARGET_CROSSCHECK " . json_encode([
    'record' => 'FW100200008', 'employee_id' => $employeeId,
    'service_count' => $row['service_count'], 'customer_count' => $row['customer_count'],
    'consumption_performance_cents' => $row['consumption_performance_cents'],
    'project_count' => $row['project_count'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

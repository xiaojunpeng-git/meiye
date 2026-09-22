<?php

declare(strict_types=1);

/**
 * 本地事务内克隆一条有效服务为 B 来源 ¥0 单，只读验证报表后回滚。
 * 断言服务人次与现金金额独立，且人次下钻与汇总能精确对账。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

$bSource = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('name', 'like', 'B%')->find();
$seed = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
    ->join('cashier_v3_sales_order o', 'o.tenant_id=sv.tenant_id AND o.store_id=sv.store_id AND o.checkout_request_id=sv.checkout_request_id')
    ->where('sv.service_status', 'completed')->where('o.order_status', 'settled')
    ->where('o.order_direction', 'forward')->where('o.store_id', '>', 0)
    ->field('o.order_id,sv.service_fact_id')->find();
if (!$bSource || !$seed) throw new RuntimeException('Missing local B source or completed service fixture');

$seedOrder = Db::name('cashier_v3_sales_order')->where('order_id', (string)$seed['order_id'])->find();
$seedService = Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', (string)$seed['service_fact_id'])->find();
$random = bin2hex(random_bytes(20));
$orderId = 'CSO-' . $random;
$checkoutId = 'CKR-' . $random;
$serviceId = 'ESF-' . $random;
$date = '2026-09-22';
$storeId = (int)$seedOrder['store_id'];
$input = ['start_date' => $date, 'end_date' => $date, 'page' => 1, 'limit' => 100];
$reports = new StoreUnifiedReportServices();
$report = static function (string $code, array $extra = []) use ($reports, $storeId, $input): array {
    return $reports->query([$storeId], array_merge($input, ['report' => $code], $extra));
};
$before = $report('market_performance');
$bKey = 'channel_' . (int)$bSource['id'];
$beforeSummary = $before['records'][0] ?? [];

Db::startTrans();
try {
    $order = $seedOrder;
    unset($order['id']);
    $order['order_id'] = $orderId;
    $order['order_no'] = 'XSTEST' . substr($random, 0, 16);
    $order['natural_key'] = 'market-b-service-' . $random;
    $order['command_idempotency_key'] = 'market-b-service-' . $random;
    $order['immutable_fingerprint'] = hash('sha256', $random . '|order');
    $order['checkout_request_id'] = $checkoutId;
    $order['business_date'] = $date;
    $order['business_source_primary_id'] = (int)$bSource['id'];
    $order['business_source_primary_name_snapshot'] = (string)$bSource['name'];
    $order['business_source_secondary_id'] = 0;
    $order['business_source_secondary_name_snapshot'] = '';
    $order['business_source_label_snapshot'] = (string)$bSource['name'];
    $order['sale_amount_cents'] = 0;
    $order['original_amount_cents'] = 0;
    $order['discount_amount_cents'] = 0;
    $order['order_status'] = 'settled';
    $order['order_direction'] = 'forward';
    Db::name('cashier_v3_sales_order')->insert($order);

    $service = $seedService;
    unset($service['id']);
    $service['service_fact_id'] = $serviceId;
    $service['natural_key'] = 'market-b-service-' . $random;
    $service['immutable_fingerprint'] = hash('sha256', $random . '|service');
    $service['command_idempotency_key'] = 'market-b-service-' . $random;
    $service['business_event_no'] = 'market-b-service-' . $random;
    $service['service_record_no'] = 'SRTEST' . substr($random, 0, 16);
    $service['checkout_request_id'] = $checkoutId;
    $service['business_date'] = $date;
    $service['service_status'] = 'completed';
    Db::name('cashier_v3_entitlement_service_fact')->insert($service);

    $summary = $report('market_performance');
    $storeSummary = $summary['records'][0] ?? [];
    $visitsBefore = (int)($beforeSummary[$bKey . '_visits'] ?? 0);
    $visitsAfter = (int)($storeSummary[$bKey . '_visits'] ?? 0);
    if ($visitsAfter !== $visitsBefore + 1) throw new RuntimeException('B visits did not increase by one');
    if ((float)($storeSummary[$bKey . '_amount'] ?? 0) !== (float)($beforeSummary[$bKey . '_amount'] ?? 0)) {
        throw new RuntimeException('Zero-cash service changed B cash amount: before=' . (string)($beforeSummary[$bKey . '_amount'] ?? 'missing') . ', after=' . (string)($storeSummary[$bKey . '_amount'] ?? 'missing'));
    }

    $detail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'visits']);
    $matched = array_values(array_filter($detail['records'], static function (array $row) use ($orderId): bool {
        return (string)($row['order_id'] ?? '') === $orderId;
    }));
    if (count($matched) !== 1 || (int)$matched[0]['visits'] !== 1 || (int)$matched[0]['amount_cents'] !== 0) {
        throw new RuntimeException('B visit drilldown omitted or miscounted the zero-cash service order');
    }
    if ((int)($detail['summary_row']['visits'] ?? -1) !== $visitsAfter) {
        throw new RuntimeException('B visit drilldown does not reconcile with summary');
    }

    $cashDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'amount']);
    foreach ($cashDetail['records'] as $row) {
        if ((string)($row['order_id'] ?? '') === $orderId) throw new RuntimeException('Zero-cash service leaked into amount drilldown');
    }
    // 同一订单的第二条服务仍记 1 次，结账请求与原单双重匹配不得重复计数。
    $second = $service;
    $secondRandom = bin2hex(random_bytes(20));
    $second['service_fact_id'] = 'ESF-' . $secondRandom;
    $second['natural_key'] = 'market-b-service-' . $secondRandom;
    $second['source_line_id'] = 'SL-' . $secondRandom;
    $second['service_record_no'] = 'SRTEST' . substr($secondRandom, 0, 16);
    $second['immutable_fingerprint'] = hash('sha256', $secondRandom . '|service');
    $second['command_idempotency_key'] = 'market-b-service-' . $secondRandom;
    $second['business_event_no'] = 'market-b-service-' . $secondRandom;
    Db::name('cashier_v3_entitlement_service_fact')->insert($second);
    $twoServiceSummary = $report('market_performance');
    $twoServiceDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'visits']);
    $twoServiceRows = array_values(array_filter($twoServiceDetail['records'], static function (array $row) use ($orderId): bool {
        return (string)($row['order_id'] ?? '') === $orderId;
    }));
    if ((int)($twoServiceSummary['records'][0][$bKey . '_visits'] ?? 0) !== $visitsBefore + 2
        || count($twoServiceRows) !== 1 || (int)$twoServiceRows[0]['visits'] !== 2) {
        throw new RuntimeException('Two service facts on one B order were not counted exactly twice');
    }
    // 仅用于验证查询端口径；补充记录与测试业务事实都在同一事务回滚。
    Db::name('cashier_v3_report_annotation')->insert([
        'tenant_id' => (string)$order['tenant_id'], 'organization_id' => (string)$order['organization_id'],
        'store_id' => $storeId, 'report_code' => 'market_detail', 'subject_type' => 'sales_order',
        'subject_key' => $orderId, 'source_order_id' => $orderId, 'field_key' => 'walk_in',
        'value_type' => 'nonnegative_integer', 'field_value' => '2', 'version' => 1,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    $manualSummary = $report('market_performance');
    $manualDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'walk_in']);
    $manualRows = array_values(array_filter($manualDetail['records'], static function (array $row) use ($orderId): bool {
        return (string)($row['order_id'] ?? '') === $orderId;
    }));
    if ((int)($manualSummary['records'][0][$bKey . '_walk_in'] ?? 0) !== 2
        || count($manualRows) !== 1 || (int)$manualRows[0]['walk_in'] !== 2) {
        throw new RuntimeException('B zero-cash manual walk-in does not reconcile');
    }
    echo "PASS B completed service counts one visit without payment\n";
    echo "PASS B zero-cash service appears in precise visit drilldown\n";
    echo "PASS B cash amount and amount drilldown remain unchanged\n";
    echo "PASS two services on one B order count exactly twice\n";
    echo "PASS B zero-cash manual walk-in reconciles between summary and detail\n";
} finally {
    Db::rollback();
}

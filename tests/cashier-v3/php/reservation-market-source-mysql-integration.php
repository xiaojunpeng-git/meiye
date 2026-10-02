<?php

declare(strict_types=1);

/** An appointment-only service must count one sourced visit without cash. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

$source = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'B%')->find();
$seed = Db::name('cashier_v3_entitlement_service_fact')->where('service_status', 'completed')->where('member_id', '>', 0)->find();
if (!$source || !$seed) throw new RuntimeException('Local source or service seed is missing');

$storeId = (int)$seed['store_id'];
$day = '2026-10-03';
$reports = new StoreUnifiedReportServices();
$report = static function (string $code, array $extra = []) use ($reports, $storeId, $day): array {
    return $reports->query([$storeId], array_merge([
        'report' => $code, 'start_date' => $day, 'end_date' => $day, 'page' => 1, 'limit' => 100,
    ], $extra));
};
$column = 'channel_' . (int)$source['id'] . '_visits';
$before = $report('market_performance');
$beforeVisits = (int)(($before['records'][0] ?? [])[$column] ?? 0);
$cashColumn = 'channel_' . (int)$source['id'] . '_amount';
$beforeCash = (float)(($before['records'][0] ?? [])[$cashColumn] ?? 0);
$token = bin2hex(random_bytes(20));
$reservationId = random_int(1000000000, 2000000000);
$requestId = 'reservation:' . $reservationId;

Db::startTrans();
try {
    $service = $seed;
    unset($service['id']);
    $service['service_fact_id'] = 'ESF-' . $token;
    $service['service_record_no'] = 'SRT' . substr($token, 0, 16);
    $service['natural_key'] = 'reservation-service-test:' . $token;
    $service['immutable_fingerprint'] = hash('sha256', $token);
    $service['business_event_no'] = 'reservation-test:' . substr($token, 0, 20);
    $service['command_idempotency_key'] = 'reservation-test:' . $token;
    $service['checkout_request_id'] = $requestId;
    $service['source_line_id'] = $requestId . ':1';
    $service['source_document_type'] = 'reservation';
    $service['source_document_id'] = $reservationId;
    $service['document_id'] = '0';
    $service['document_no_snapshot'] = 'RSVT' . substr($token, 0, 16);
    $service['business_date'] = $day;
    $service['occurred_at'] = strtotime($day . ' 12:00:00');
    $service['settled_at'] = $service['occurred_at'];
    $service['recorded_at'] = $service['occurred_at'];
    $service['service_status'] = 'completed';
    $service['service_object'] = 'SELF';
    $service['friend_counts_as_customer'] = 1;
    $service['business_source_primary_id'] = (int)$source['id'];
    $service['business_source_primary_name_snapshot'] = (string)$source['name'];
    $service['business_source_secondary_id'] = 0;
    $service['business_source_secondary_name_snapshot'] = '';
    $service['business_source_label_snapshot'] = (string)$source['name'];
    $service['source_attribution_type_snapshot'] = (string)($source['attribution_type'] ?? 'other');
    Db::name('cashier_v3_entitlement_service_fact')->insert($service);

    $after = $report('market_performance');
    $afterVisits = (int)(($after['records'][0] ?? [])[$column] ?? 0);
    if ($afterVisits !== $beforeVisits + 1) throw new RuntimeException('Appointment visit did not increase exactly once');
    if ((float)(($after['records'][0] ?? [])[$cashColumn] ?? 0) !== $beforeCash) {
        throw new RuntimeException('Appointment service changed market cash');
    }

    // Two projects from the same reservation are one member visit.
    $second = $service;
    $secondToken = bin2hex(random_bytes(20));
    $second['service_fact_id'] = 'ESF-' . $secondToken;
    $second['service_record_no'] = 'SRT' . substr($secondToken, 0, 16);
    $second['natural_key'] = 'reservation-service-test:' . $secondToken;
    $second['immutable_fingerprint'] = hash('sha256', $secondToken);
    $second['source_line_id'] = $requestId . ':2';
    Db::name('cashier_v3_entitlement_service_fact')->insert($second);
    $twoProjects = $report('market_performance');
    if ((int)(($twoProjects['records'][0] ?? [])[$column] ?? 0) !== $afterVisits) {
        throw new RuntimeException('Two appointment projects counted as two visits');
    }

    $detail = $report('market_detail', ['dimension_code' => (string)$source['id'], 'metric_code' => 'visits']);
    $matched = array_values(array_filter($detail['records'], static function (array $row) use ($requestId): bool {
        return in_array('service:' . $requestId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true);
    }));
    if (count($matched) !== 1 || (int)$matched[0]['visits'] !== 1 || (int)$matched[0]['amount_cents'] !== 0) {
        throw new RuntimeException('Appointment service is absent or incorrect in market detail');
    }
    if ((int)($detail['summary_row']['visits'] ?? -1) !== $afterVisits) {
        throw new RuntimeException('Appointment market detail and performance disagree');
    }
    echo "reservation market source integration: PASS\n";
} finally {
    Db::rollback();
}

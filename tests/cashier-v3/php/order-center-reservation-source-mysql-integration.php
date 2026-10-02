<?php

declare(strict_types=1);

/** A reservation service source must survive order-center list, search and filter reads. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

$seed = Db::name('cashier_v3_entitlement_service_fact')->where('service_status', 'completed')->find();
$source = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->find();
if (!$seed || !$source) throw new RuntimeException('Local service or source fixture is missing');

$token = bin2hex(random_bytes(16));
$reservationId = random_int(1000000000, 2000000000);
$day = '2099-12-31';
$fact = $seed;
unset($fact['id']);
$fact['service_fact_id'] = 'ESF-' . $token;
$fact['service_record_no'] = 'SRT' . strtoupper(substr($token, 0, 16));
$fact['natural_key'] = 'reservation-source-test:' . $token;
$fact['immutable_fingerprint'] = hash('sha256', $token);
$fact['business_event_no'] = 'reservation-source-test:' . $token;
$fact['command_idempotency_key'] = 'reservation-source-test:' . $token;
$fact['checkout_request_id'] = 'reservation:' . $reservationId;
$fact['source_line_id'] = 'reservation:' . $reservationId . ':1';
$fact['source_document_type'] = 'reservation';
$fact['source_document_id'] = $reservationId;
$fact['document_id'] = '0';
$fact['business_date'] = $day;
$fact['service_status'] = 'completed';
$fact['business_source_primary_id'] = (int)$source['id'];
$fact['business_source_primary_name_snapshot'] = (string)$source['name'];
$fact['business_source_secondary_id'] = 0;
$fact['business_source_secondary_name_snapshot'] = '';
$fact['business_source_label_snapshot'] = (string)$source['name'];
$fact['source_attribution_type_snapshot'] = (string)($source['attribution_type'] ?? 'other');

$criteria = [
    'allowedStoreIds' => [(int)$seed['store_id']],
    'businessDateFrom' => $day,
    'businessDateTo' => $day,
    'keyword' => $fact['service_record_no'],
    'topFilters' => [],
    'dataScope' => 'normal',
    'status' => '',
    'sorts' => [],
    'page' => 1,
    'pageSize' => 100,
];
$scope = new CashierV3OperatorScope((int)$seed['store_id'], 1, '', (string)$seed['tenant_id']);
$reader = new CashierV3OrderCenterRecordQueryServices();
$read = new ReflectionMethod($reader, 'readServices');
$read->setAccessible(true);

Db::startTrans();
try {
    Db::name('cashier_v3_entitlement_service_fact')->insert($fact);
    [$records, $total] = $read->invoke($reader, $criteria, $scope);
    if ($total !== 1 || count($records) !== 1 || $records[0]['source'] !== (string)$source['name']) {
        throw new RuntimeException('Reservation source is absent from service list');
    }

    $criteria['keyword'] = (string)$source['name'];
    [$records] = $read->invoke($reader, $criteria, $scope);
    if (count($records) !== 1 || $records[0]['serviceRecordNo'] !== $fact['service_record_no']) {
        throw new RuntimeException('Reservation source is absent from keyword search');
    }

    $criteria['keyword'] = '';
    $criteria['topFilters'] = ['source' => (string)$source['name']];
    [$records] = $read->invoke($reader, $criteria, $scope);
    if (count($records) !== 1 || $records[0]['serviceRecordNo'] !== $fact['service_record_no']) {
        throw new RuntimeException('Reservation source is absent from source filter');
    }
    echo "order center reservation source integration: PASS\n";
} finally {
    Db::rollback();
}

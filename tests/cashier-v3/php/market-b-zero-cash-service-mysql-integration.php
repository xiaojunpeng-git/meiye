<?php

declare(strict_types=1);

/**
 * 本地事务内克隆一条有效服务为 B 来源 ¥0 单，只读验证报表后回滚。
 * 断言服务人次与现金金额独立，且人次下钻与汇总能精确对账。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;
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

    // 同一会员当天同一来源的第二张 ¥0 服务单，必须并入同一条可编辑记录。
    $secondOrderRandom = bin2hex(random_bytes(20));
    $secondOrderId = 'CSO-' . $secondOrderRandom;
    $secondCheckoutId = 'CKR-' . $secondOrderRandom;
    $secondOrder = $order;
    $secondOrder['order_id'] = $secondOrderId;
    $secondOrder['order_no'] = 'XSTEST' . substr($secondOrderRandom, 0, 16);
    $secondOrder['checkout_request_id'] = $secondCheckoutId;
    $secondOrder['natural_key'] = 'market-b-service-' . $secondOrderRandom;
    $secondOrder['command_idempotency_key'] = 'market-b-service-' . $secondOrderRandom;
    $secondOrder['immutable_fingerprint'] = hash('sha256', $secondOrderRandom . '|order');
    Db::name('cashier_v3_sales_order')->insert($secondOrder);
    $secondOrderService = $service;
    $secondOrderService['service_fact_id'] = 'ESF-' . $secondOrderRandom;
    $secondOrderService['checkout_request_id'] = $secondCheckoutId;
    $secondOrderService['source_line_id'] = 'SL-' . $secondOrderRandom;
    $secondOrderService['service_record_no'] = 'SRTEST' . substr($secondOrderRandom, 0, 16);
    $secondOrderService['natural_key'] = 'market-b-service-' . $secondOrderRandom;
    $secondOrderService['command_idempotency_key'] = 'market-b-service-' . $secondOrderRandom;
    $secondOrderService['business_event_no'] = 'market-b-service-' . $secondOrderRandom;
    $secondOrderService['immutable_fingerprint'] = hash('sha256', $secondOrderRandom . '|service');
    Db::name('cashier_v3_entitlement_service_fact')->insert($secondOrderService);

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
        return in_array($orderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true);
    }));
    if (count($matched) !== 1 || (int)$matched[0]['visits'] !== 1 || (int)$matched[0]['amount_cents'] !== 0
        || count($matched[0]['_market_orders'] ?? []) !== 2) {
        throw new RuntimeException('B visit drilldown omitted or miscounted the zero-cash service order');
    }
    if ((int)($detail['summary_row']['visits'] ?? -1) !== $visitsAfter) {
        throw new RuntimeException('B visit drilldown does not reconcile with summary');
    }

    $cashDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'amount']);
    foreach ($cashDetail['records'] as $row) {
        if (in_array($orderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true)) throw new RuntimeException('Zero-cash service leaked into amount drilldown');
    }
    // 同一会员当天第二条正常服务仍只记 1 人次，不能按服务事实条数累加。
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
        return in_array($orderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true);
    }));
    if ((int)($twoServiceSummary['records'][0][$bKey . '_visits'] ?? 0) !== $visitsBefore + 1
        || count($twoServiceRows) !== 1 || (int)$twoServiceRows[0]['visits'] !== 1) {
        throw new RuntimeException('Two service facts on one member day were counted more than once');
    }
    // 游客没有会员 ID，必须按有效服务单计 1；同一服务单的多个项目仍只算一次。
    $guestRandom = bin2hex(random_bytes(20));
    $guestOrderId = 'CSO-' . $guestRandom;
    $guestCheckoutId = 'CKR-' . $guestRandom;
    $guestOrder = $order;
    $guestOrder['order_id'] = $guestOrderId;
    $guestOrder['order_no'] = 'XSTEST' . substr($guestRandom, 0, 16);
    $guestOrder['checkout_request_id'] = $guestCheckoutId;
    $guestOrder['member_id'] = 0;
    $guestOrder['member_name_snapshot'] = '';
    $guestOrder['natural_key'] = 'market-b-guest-' . $guestRandom;
    $guestOrder['command_idempotency_key'] = 'market-b-guest-' . $guestRandom;
    $guestOrder['immutable_fingerprint'] = hash('sha256', $guestRandom . '|order');
    Db::name('cashier_v3_sales_order')->insert($guestOrder);
    foreach (['first', 'second'] as $index => $suffix) {
        $guestService = $service;
        $guestService['service_fact_id'] = 'ESF-' . substr(hash('sha256', $guestRandom . '|fact|' . $suffix), 0, 40);
        $guestService['checkout_request_id'] = $guestCheckoutId;
        $guestService['member_id'] = 0;
        $guestService['member_name_snapshot'] = '';
        $guestService['source_line_id'] = 'SL-' . $guestRandom . '-' . $index;
        $guestService['service_record_no'] = 'SRTEST' . substr($guestRandom, 0, 14) . $index;
        $guestService['natural_key'] = 'market-b-guest-' . $guestRandom . '-' . $suffix;
        $guestService['command_idempotency_key'] = 'market-b-guest-' . $guestRandom . '-' . $suffix;
        $guestService['business_event_no'] = 'market-b-guest-' . $guestRandom . '-' . $suffix;
        $guestService['immutable_fingerprint'] = hash('sha256', $guestRandom . '|service|' . $suffix);
        Db::name('cashier_v3_entitlement_service_fact')->insert($guestService);
    }
    $guestSummary = $report('market_performance');
    $guestDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'visits']);
    $guestRows = array_values(array_filter($guestDetail['records'], static function (array $row) use ($guestOrderId): bool {
        return in_array($guestOrderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true);
    }));
    if ((int)($guestSummary['records'][0][$bKey . '_visits'] ?? 0) !== $visitsBefore + 2
        || count($guestRows) !== 1 || (int)$guestRows[0]['visits'] !== 1
        || (int)$guestRows[0]['member_id'] !== 0 || (string)$guestRows[0]['member_name_snapshot'] !== '游客') {
        throw new RuntimeException('Guest service order was omitted or its projects were counted more than once');
    }
    $guestKey = 'market-guest-v1:' . $storeId . ':' . $date . ':' . (int)$bSource['id'] . ':' . hash('sha256', $guestOrderId);
    if ((string)($guestRows[0]['annotation_subject_key'] ?? '') !== $guestKey
        || (string)($guestRows[0]['annotation_subject_type'] ?? '') !== 'market_guest_order'
        || (string)($guestRows[0]['source_order_id'] ?? '') !== $guestOrderId) {
        throw new RuntimeException('Guest detail row did not expose its independent editable subject');
    }
    $annotations = new StoreOperationsReportAnnotationServices();
    $context = [
        'tenant_id' => (string)$order['tenant_id'], 'organization_id' => (string)$order['organization_id'],
        'store_id' => $storeId, 'store_ids' => [$storeId], 'authorization_mode' => 'stores',
        'operator_id' => 1, 'operator_name' => 'report-test',
    ];
    $guestWalkInBefore = (int)($guestSummary['records'][0][$bKey . '_walk_in'] ?? 0);
    $guestPayload = [
        'report_code' => 'market_detail', 'subject_type' => 'market_guest_order',
        'subject_key' => $guestKey, 'store_id' => $storeId, 'field_key' => 'walk_in',
        'field_value' => '3', 'expected_version' => 0,
        'idempotency_key' => 'market-guest-test-' . $random,
    ];
    $guestSaved = $annotations->saveAnnotation($context, $guestPayload);
    $guestReplayed = $annotations->saveAnnotation($context, $guestPayload);
    $guestSavedDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id']]);
    $guestSavedRows = array_values(array_filter($guestSavedDetail['records'], static function (array $row) use ($guestKey): bool {
        return (string)($row['annotation_subject_key'] ?? '') === $guestKey;
    }));
    $guestSavedSummary = $report('market_performance');
    if ((int)$guestSaved['version'] !== 1 || empty($guestReplayed['replayed'])
        || count($guestSavedRows) !== 1 || (int)$guestSavedRows[0]['walk_in'] !== 3
        || (int)$guestSavedRows[0]['walk_in_version'] !== 1
        || (int)($guestSavedSummary['records'][0][$bKey . '_walk_in'] ?? -1) !== $guestWalkInBefore + 3) {
        throw new RuntimeException('Guest walk-in save did not persist and reconcile with market performance');
    }
    try {
        $annotations->saveAnnotation($context, array_merge($guestPayload, [
            'field_value' => '4', 'idempotency_key' => 'market-guest-conflict-' . $random,
        ]));
        throw new RuntimeException('Stale guest row version was accepted');
    } catch (InvalidArgumentException $expected) {
        if (strpos($expected->getMessage(), '已更新') === false) throw $expected;
    }
    try {
        $annotations->saveAnnotation($context, array_merge($guestPayload, [
            'subject_key' => substr($guestKey, 0, -1) . ($guestKey[-1] === '0' ? '1' : '0'),
            'idempotency_key' => 'market-guest-missing-' . $random,
        ]));
        throw new RuntimeException('Forged guest row key was accepted');
    } catch (InvalidArgumentException $expected) {
        // 游客主键必须由后端反查到当前门店下的唯一原单。
    }
    $guestCleared = $annotations->saveAnnotation($context, array_merge($guestPayload, [
        'field_value' => '', 'expected_version' => 1,
        'idempotency_key' => 'market-guest-clear-' . $random,
    ]));
    $guestClearedDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id']]);
    $guestClearedRows = array_values(array_filter($guestClearedDetail['records'], static function (array $row) use ($guestKey): bool {
        return (string)($row['annotation_subject_key'] ?? '') === $guestKey;
    }));
    $guestClearedSummary = $report('market_performance');
    if ((int)$guestCleared['version'] !== 2 || count($guestClearedRows) !== 1
        || (int)$guestClearedRows[0]['walk_in'] !== 0 || (int)$guestClearedRows[0]['walk_in_version'] !== 2
        || (int)($guestClearedSummary['records'][0][$bKey . '_walk_in'] ?? -1) !== $guestWalkInBefore) {
        throw new RuntimeException('Guest walk-in clear did not survive readback or summary aggregation');
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
        return in_array($orderId, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true);
    }));
    if ((int)($manualSummary['records'][0][$bKey . '_walk_in'] ?? 0) !== 2
        || count($manualRows) !== 1 || (int)$manualRows[0]['walk_in'] !== 2) {
        throw new RuntimeException('B zero-cash manual walk-in does not reconcile');
    }
    // 新的每日来源行覆盖旧逐单值；0 也是明确输入，不能回退成旧值 2。
    $dailyKey = 'market-day-v1:' . $storeId . ':' . $date . ':' . (int)$order['member_id'] . ':' . (int)$bSource['id'];
    Db::name('cashier_v3_report_annotation')->insert([
        'tenant_id' => (string)$order['tenant_id'], 'organization_id' => (string)$order['organization_id'],
        'store_id' => $storeId, 'report_code' => 'market_detail', 'subject_type' => 'market_member_day',
        'subject_key' => $dailyKey, 'field_key' => 'walk_in', 'value_type' => 'integer',
        'field_value' => '4', 'version' => 1, 'created_at' => time(), 'updated_at' => time(),
    ]);
    $dailySummary = $report('market_performance');
    $dailyDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id'], 'metric_code' => 'walk_in']);
    $dailyRows = array_values(array_filter($dailyDetail['records'], static function (array $row) use ($dailyKey): bool {
        return (string)($row['annotation_subject_key'] ?? '') === $dailyKey;
    }));
    if (count($dailyRows) !== 1 || (int)$dailyRows[0]['walk_in'] !== 4
        || (int)$dailyRows[0]['walk_in_version'] !== 1
        || (int)($dailySummary['records'][0][$bKey . '_walk_in'] ?? 0) !== 4) {
        throw new RuntimeException('Daily row input did not replace legacy order input consistently');
    }
    Db::name('cashier_v3_report_annotation')->where('subject_key', $dailyKey)->update(['field_value' => '0', 'version' => 2]);
    $clearedSummary = $report('market_performance');
    $clearedDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id']]);
    $clearedRows = array_values(array_filter($clearedDetail['records'], static function (array $row) use ($dailyKey): bool {
        return (string)($row['annotation_subject_key'] ?? '') === $dailyKey;
    }));
    if (count($clearedRows) !== 1 || (int)$clearedRows[0]['walk_in'] !== 0
        || (int)$clearedRows[0]['walk_in_version'] !== 2
        || (int)($clearedSummary['records'][0][$bKey . '_walk_in'] ?? -1) !== 0) {
        throw new RuntimeException('Explicit daily zero incorrectly fell back to legacy input');
    }
    $payload = [
        'report_code' => 'market_detail', 'subject_type' => 'market_member_day',
        'subject_key' => $dailyKey, 'store_id' => $storeId, 'field_key' => 'walk_in',
        'field_value' => '5', 'expected_version' => 2,
        'idempotency_key' => 'market-daily-test-' . $random,
    ];
    $saved = $annotations->saveAnnotation($context, $payload);
    $replayed = $annotations->saveAnnotation($context, $payload);
    if ((int)$saved['version'] !== 3 || empty($replayed['replayed'])) {
        throw new RuntimeException('Daily row save did not retain version and idempotency');
    }
    try {
        $annotations->saveAnnotation($context, array_merge($payload, [
            'field_value' => '6', 'idempotency_key' => 'market-daily-conflict-' . $random,
        ]));
        throw new RuntimeException('Stale daily row version was accepted');
    } catch (InvalidArgumentException $expected) {
        if (strpos($expected->getMessage(), '已更新') === false) throw $expected;
    }
    foreach ([
        ['field_value' => '-1', 'expected_version' => 3, 'idempotency_key' => 'market-daily-negative-' . $random],
        ['subject_key' => 'market-day-v1:' . $storeId . ':' . $date . ':999999999:' . (int)$bSource['id'],
            'expected_version' => 0, 'idempotency_key' => 'market-daily-missing-' . $random],
    ] as $invalid) {
        try {
            $annotations->saveAnnotation($context, array_merge($payload, $invalid));
            throw new RuntimeException('Invalid daily row input was accepted');
        } catch (InvalidArgumentException $expected) {
            // 格式和行存在性都必须在写入前由服务端校验。
        }
    }
    $cleared = $annotations->saveAnnotation($context, array_merge($payload, [
        'field_value' => '', 'expected_version' => 3,
        'idempotency_key' => 'market-daily-clear-' . $random,
    ]));
    $emptyDetail = $report('market_detail', ['dimension_code' => (string)$bSource['id']]);
    $emptyRows = array_values(array_filter($emptyDetail['records'], static function (array $row) use ($dailyKey): bool {
        return (string)($row['annotation_subject_key'] ?? '') === $dailyKey;
    }));
    if ((int)$cleared['version'] !== 4 || count($emptyRows) !== 1
        || (int)$emptyRows[0]['walk_in'] !== 0 || (int)$emptyRows[0]['walk_in_version'] !== 4) {
        throw new RuntimeException('Empty daily input was not retained as an explicit clear');
    }
    echo "PASS B completed service counts one visit without payment\n";
    echo "PASS B zero-cash service appears in precise visit drilldown\n";
    echo "PASS B cash amount and amount drilldown remain unchanged\n";
    echo "PASS two services on one member day count once\n";
    echo "PASS guest service order counts once and its projects do not duplicate visits\n";
    echo "PASS guest walk-in saves, replays, conflicts, clears and reconciles with market performance\n";
    echo "PASS B zero-cash manual walk-in reconciles between summary and detail\n";
    echo "PASS member-day input replaces legacy order input and clear-to-zero survives readback\n";
    echo "PASS member-day save checks row existence, version and idempotency\n";
} finally {
    Db::rollback();
}

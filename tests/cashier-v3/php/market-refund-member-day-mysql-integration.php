<?php

declare(strict_types=1);

/** K 退款会员日的本地事务测试；不调用真实退款命令，所有夹具最终回滚。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
date_default_timezone_set('Asia/Shanghai');
if (getenv('MARKET_REFUND_DAY_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Explicit local Docker test environment required');
}
$checks = 0;
/** 断言只打印场景名，不泄漏从本地模板读取的业务身份。 */
function checkRefundDay(bool $valid, string $message): void
{
    global $checks;
    if (!$valid) throw new RuntimeException($message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}
$seed = Db::name('cashier_v3_payment_fact')->where('fact_direction', 'forward')->where('payment_method', 'cash')->where('status', 'effective')->find();
$operationSeed = Db::name('cashier_v3_order_lifecycle_operation')->where('status', 'succeeded')->find();
$adjustmentSeed = Db::name('cashier_v3_refund_adjustment')->where('id', '>', 0)->find();
$a = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'A%')->find();
$k = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'K%')->find();
$serviceSeed = Db::name('cashier_v3_entitlement_service_fact')->where('service_status', 'completed')->find();
$selectionSeed = Db::name('cashier_v3_checkout_business_source_selection')->where('primary_source_id', '>', 0)->find();
foreach (compact('seed', 'operationSeed', 'adjustmentSeed', 'a', 'k', 'serviceSeed', 'selectionSeed') as $name => $value) {
    if (!$value) throw new RuntimeException('Local seed required: ' . $name);
}
$stores = [(int)$seed['store_id'], (int)Db::name('cashier_v3_payment_fact')->where('store_id', '<>', $seed['store_id'])->value('store_id')];
$start = '2035-02-01'; $end = '2035-02-02';
foreach (['cashier_v3_payment_fact', 'cashier_v3_entitlement_service_fact'] as $table) {
    if (Db::name($table)->whereIn('store_id', $stores)->whereBetween('business_date', [$start, $end])->count()) throw new RuntimeException('Fixture dates must be empty');
}
$token = 'KRDAY-' . bin2hex(random_bytes(8)); $sequence = 0; $member = 987640000;
$reports = new StoreUnifiedReportServices();
$input = ['start_date' => $start, 'end_date' => $end, 'limit' => 10];
$query = static function (string $report, array $extra = [], ?array $scope = null) use ($reports, $stores, $input): array {
    return $reports->query($scope ?? $stores, array_merge($input, ['report' => $report], $extra));
};
/** 克隆字段结构，覆盖业务键；现金负向事实只在成功退款生命周期存在时有效。 */
$refund = static function (int $memberId, string $day, string $kind = 'cash', array $extra = [], string $operationStatus = 'succeeded') use ($seed, $operationSeed, $token, &$sequence, $a, $stores): array {
    $fact = $seed; unset($fact['id']);
    $key = $token . '-' . ++$sequence;
    foreach (['fact_id', 'natural_key', 'command_idempotency_key', 'business_event_no', 'order_id', 'checkout_request_id', 'payment_authority_key'] as $field) $fact[$field] = $key;
    $fact = array_replace($fact, [
        'order_no_snapshot' => $key, 'fact_type' => $kind === 'manual' ? 'manual_refund_adjustment' : 'payment_refunded',
        'fact_direction' => in_array($kind, ['manual', 'old_card'], true) ? 'adjustment' : 'reversal',
        'source_document_type' => $kind === 'manual' ? 'manual_refund' : ($kind === 'old_card' ? 'imported_card_refund' : 'sale'),
        'payment_method' => $kind === 'manual' ? 'manual_adjustment' : ($kind === 'old_card' ? 'manual_refund' : $kind),
        'store_id' => $stores[0], 'member_id' => $memberId, 'member_name_snapshot' => '退款人次测试',
        'business_date' => $day, 'business_source_primary_id' => (int)$a['id'],
        'business_source_primary_name_snapshot' => $a['name'], 'business_source_label_snapshot' => $a['name'],
        'amount_cents' => -100, 'immutable_fingerprint' => hash('sha256', $key),
    ], $extra);
    Db::name('cashier_v3_payment_fact')->insert($fact);
    if ($fact['fact_direction'] === 'reversal') {
        $operation = $operationSeed; unset($operation['id']);
        foreach (['operation_id', 'operation_no', 'command_idempotency_key', 'business_event_no'] as $field) $operation[$field] = $key;
        $operation = array_replace($operation, [
            'tenant_id' => $fact['tenant_id'], 'store_id' => $fact['store_id'], 'source_order_id' => $fact['order_id'],
            'source_type' => 'sales', 'operation_type' => 'refund', 'status' => $operationStatus,
            'immutable_fingerprint' => hash('sha256', $key),
        ]);
        Db::name('cashier_v3_order_lifecycle_operation')->insert($operation);
    }
    return $fact;
};
$drill = ['dimension_code' => (string)$k['id'], 'metric_code' => 'visits'];
$visitsKey = 'channel_' . $k['id'] . '_visits';
Db::startTrans();
try {
    // 同会员同日多单、多支付方式、登记及旧卡退款均只能得到一个退款人次。
    foreach (['cash', 'wechat', 'manual', 'old_card'] as $kind) $refund($member, $start, $kind);
    $one = $query('market_detail', $drill);
    checkRefundDay($one['total'] === 1 && (int)$one['summary_row']['visits'] === 1 && (int)$one['records'][0]['amount_cents'] === -400, 'Same member/day merges refund types without requiring service');
    $refund($member, $end); $refund($member + 1, $start, 'manual');
    $refund($member, $start, 'cash', ['store_id' => $stores[1]]);
    checkRefundDay((int)$query('market_performance')['summary_row'][$visitsKey] === 4, 'Different days, members and stores count independently');

    // 失败操作、失效事实、已作废登记退款都不得贡献退款人次。
    $refund($member + 2, $start, 'cash', [], 'failed');
    $refund($member + 3, $start, 'cash', ['status' => 'voided']);
    $voided = $refund($member + 4, $start, 'manual');
    $adjustment = $adjustmentSeed; unset($adjustment['id']);
    $adjustment['tenant_id'] = $voided['tenant_id']; $adjustment['operation_id'] = $voided['order_id'];
    $adjustment['adjustment_id'] = $voided['fact_id']; $adjustment['command_idempotency_key'] = $voided['command_idempotency_key'];
    $adjustment['status'] = 'voided';
    Db::name('cashier_v3_refund_adjustment')->insert($adjustment);
    checkRefundDay((int)$query('market_performance')['summary_row'][$visitsKey] === 4, 'Failed, ineffective and voided refunds excluded');

    // K 的正常收款和服务不是退款证据，不能沿用普通来源的服务资格。
    $refund($member + 5, $start, 'cash', ['fact_direction' => 'forward', 'fact_type' => 'payment_collected', 'amount_cents' => 100,
        'business_source_primary_id' => (int)$k['id'], 'business_source_primary_name_snapshot' => $k['name'], 'business_source_label_snapshot' => $k['name']]);
    $service = $serviceSeed; unset($service['id']);
    $checkout = $token . '-service';
    foreach (['service_fact_id', 'natural_key', 'command_idempotency_key', 'business_event_no', 'source_line_id'] as $field) $service[$field] = $checkout;
    $service = array_replace($service, ['checkout_request_id' => $checkout, 'store_id' => $stores[0], 'member_id' => $member + 6,
        'business_date' => $start, 'service_record_no' => $checkout, 'service_object' => 'self', 'is_experience' => 0,
        'friend_counts_as_customer' => 1, 'immutable_fingerprint' => hash('sha256', $checkout)]);
    Db::name('cashier_v3_entitlement_service_fact')->insert($service);
    $selection = $selectionSeed; unset($selection['id']);
    $selection = array_replace($selection, ['tenant_id' => $service['tenant_id'], 'store_id' => $stores[0], 'checkout_request_id' => $checkout,
        'checkout_kind' => 'sale', 'primary_source_id' => (int)$k['id'], 'primary_source_name_snapshot' => $k['name'],
        'secondary_source_id' => 0, 'secondary_source_name_snapshot' => '', 'source_label_snapshot' => $k['name']]);
    Db::name('cashier_v3_checkout_business_source_selection')->insert($selection);
    checkRefundDay($query('market_detail', $drill)['total'] === 4 && (int)$query('market_performance')['summary_row'][$visitsKey] === 4, 'K receipts and service alone do not count');
    // 退款会员即使当天仅有体验服务或人员不计客数标记，退款人次也不能被移除。
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $checkout)
        ->update(['member_id' => $member, 'is_experience' => 1, 'friend_counts_as_customer' => 0]);
    checkRefundDay($query('market_detail', $drill)['total'] === 4 && (int)$query('market_performance')['summary_row'][$visitsKey] === 4, 'Experience and personnel flags do not suppress refund visits');

    // 无稳定会员身份时沿用原单身份，不把所有游客合并，也不按姓名猜同一人。
    $guest = $refund(0, $start); $refund(0, $start, 'wechat', ['order_id' => $guest['order_id']]); $refund(0, $start);
    $performance = $query('market_performance'); $detail = $query('market_detail', $drill);
    checkRefundDay((int)$performance['summary_row'][$visitsKey] === 6 && $detail['total'] === 6 && (int)$detail['summary_row']['visits'] === 6, 'Guest refunds retain original-order identity');
    checkRefundDay((int)$detail['summary_row']['effective_people'] === 0 && (float)$performance['summary_row']['channel_' . $k['id'] . '_amount'] === -9.0, 'No spend threshold applied; K amount and effective metrics unchanged');
    $sum = 0;
    foreach ([$start, $end] as $date) $sum += (int)$query('market_performance', ['start_date' => $date, 'end_date' => $date])['summary_row'][$visitsKey];
    checkRefundDay($sum === 6, 'Period K count equals independent daily totals');
    checkRefundDay($query('market_detail', $drill + ['payment_method_code' => 'wechat'])['total'] === 2, 'Payment-method drill narrows refund facts');
    checkRefundDay($query('market_detail', $drill, [$stores[0]])['total'] === 5, 'Store authorization excludes another store');
    checkRefundDay($query('market_detail', $drill + ['store_id' => $stores[0]], [999999999])['records'] === [], 'Client filter cannot expand store scope');
    checkRefundDay($query('market_detail', $drill + ['_report_scope' => ['mode' => 'self_participant', 'employee_id' => 999999999]])['records'] === [], 'Participant scope excludes unrelated refunds');
    checkRefundDay($query('market_detail', $drill + ['company_dimension_id' => 'missing-test-company'])['records'] === [], 'Organization filter applies to all refund types');
    checkRefundDay($query('market_detail', array_merge($drill, ['start_date' => '2035-02-03', 'end_date' => '2035-02-03']))['total'] === 0, 'Empty refund day returns zero');
    foreach ($performance['columns'] as $column) if ($column['key'] === $visitsKey) {
        checkRefundDay(strpos($column['source_explanation'], '按有效退款记录统计') !== false && strpos($column['source_explanation'], '不要求有服务记录') !== false, 'K column explains refund-day rule');
    }
    // 人次合计和导出须覆盖未显示在第一页的全部退款会员日。
    for ($i = 20; $i < 32; $i++) $refund($member + $i, $end, 'manual');
    $page = $query('market_detail', $drill);
    $export = $reports->export($stores, $input + ['report' => 'market_detail'] + $drill);
    $marketExport = $reports->export($stores, $input + ['report' => 'market_performance']);
    checkRefundDay(count($page['records']) === 10 && $page['total'] === 18 && (int)$page['summary_row']['visits'] === 18, 'Refund summary covers every page');
    checkRefundDay(count($export['records']) === 18 && $export['summary_row'] === $page['summary_row'] && (int)$marketExport['summary_row'][$visitsKey] === 18, 'Summary, drilldown and exports reconcile');
    checkRefundDay($export['metric_version'] === 'market-imported-debt-effective-v6' && $export['metric_version'] === $marketExport['metric_version'], 'Market reports expose identical updated metric version');
    checkRefundDay($query('market_detail', $drill)['summary_row'] === $page['summary_row'], 'Repeated refund query is stable');
} finally {
    Db::rollback();
}
checkRefundDay((int)Db::name('cashier_v3_payment_fact')->where('fact_id', 'like', $token . '%')->count() === 0
    && (int)Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id', 'like', $token . '%')->count() === 0
    && (int)Db::name('cashier_v3_refund_adjustment')->where('operation_id', 'like', $token . '%')->count() === 0
    && (int)Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', 'like', $token . '%')->count() === 0
    && (int)Db::name('cashier_v3_checkout_business_source_selection')->where('checkout_request_id', 'like', $token . '%')->count() === 0, 'All local refund fixtures rolled back');
echo 'market refund member-day integration: ' . $checks . " PASS\n";

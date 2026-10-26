<?php

declare(strict_types=1);

// 仅在本地副本事务内构造 E 来源纯权益护理，验证无销售单也成行并无条件回滚。
if (getenv('FIELD_ENTITLEMENT_LOCAL_ROLLBACK_TEST') !== '1') {
    throw new RuntimeException('Explicit local rollback test flag required');
}

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();

use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;

$sample = Db::name('cashier_v3_entitlement_service_fact')->alias('service')
    ->join('cashier_v3_checkout_request request', 'request.tenant_id=service.tenant_id AND request.request_id=service.checkout_request_id')
    ->where('service.store_id', 18)->where('service.member_id', 1136096)
    ->where('service.service_status', 'completed')
    ->field('service.service_fact_id,service.checkout_request_id')->find();
if (!$sample) throw new RuntimeException('Local service fixture is missing');
$service = Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $sample['service_fact_id'])->find();
$request = Db::name('cashier_v3_checkout_request')->where('request_id', $sample['checkout_request_id'])->find();
if (!$service || !$request) throw new RuntimeException('Local checkout fixture is incomplete');

$nonce = bin2hex(random_bytes(8));
$requestId = 'report-pure-' . $nonce;
$serviceId = 'report-service-' . $nonce;
$sourceLineId = 'report-line-' . $nonce;
$date = '2026-10-13';
$tenant = (string)$service['tenant_id'];

Db::startTrans();
try {
    unset($request['id']);
    $request['request_id'] = $requestId;
    $request['creation_idempotency_key'] = 'report-pure-' . $nonce;
    $request['last_idempotency_key'] = 'report-pure-' . $nonce;
    $request['composition'] = 'entitlement_only';
    $request['request_status'] = 'succeeded';
    $request['business_date'] = $date;
    Db::name('cashier_v3_checkout_request')->insert($request);

    unset($service['id']);
    $service['service_fact_id'] = $serviceId;
    $service['natural_key'] = 'report-pure-' . $nonce;
    $service['command_idempotency_key'] = 'report-pure-' . $nonce;
    $service['business_event_no'] = 'REPORT-PURE-' . $nonce;
    $service['checkout_request_id'] = $requestId;
    $service['source_line_id'] = $sourceLineId;
    $service['document_id'] = 'report-receipt-' . $nonce;
    $service['business_date'] = $date;
    $service['project_name_snapshot'] = 'REPORT-PURE-' . $nonce;
    $service['business_source_primary_name_snapshot'] = 'E地推拓客';
    $service['business_source_primary_id'] = 0;
    $service['business_source_secondary_name_snapshot'] = '';
    $service['is_experience'] = 0;
    if (array_key_exists('service_record_no', $service)) $service['service_record_no'] = 'REPORT-' . $nonce;
    Db::name('cashier_v3_entitlement_service_fact')->insert($service);

    $report = new StoreUnifiedReportServices();
    $input = [
        'report' => 'field_acquisition_detail', 'start_date' => $date, 'end_date' => $date,
        'member_id' => 1136096, '_report_scope' => ['mode' => 'stores'],
    ];
    $detail = $report->query([18], $input);
    $rows = array_values(array_filter($detail['records'], static function (array $row) use ($serviceId): bool {
        return (string)($row['service_fact_id'] ?? '') === $serviceId;
    }));
    if (count($rows) !== 1) throw new RuntimeException('Pure entitlement service must appear exactly once without a sale');
    $row = $rows[0];
    if (($row['visit_none_date'] ?? '') !== $date
        || ($row['visit_none_project'] ?? '') !== 'REPORT-PURE-' . $nonce
        || ($row['source'] ?? '') !== 'E地推拓客'
        || ($row['annotation_subject_key'] ?? '') !== 'service:' . $serviceId
        || ($row['annotation_subject_type'] ?? '') !== 'business_event_line'
        || ($row['cash_none'] ?? null) != 0) {
        throw new RuntimeException('Pure entitlement service row has incorrect date, subject or cash');
    }
    // 服务自身的导购事实决定护理轮次，不得借同会员的销售单或按日期顺排。
    Db::name('cashier_v3_customer_guide_round_fact')->insert([
        'fact_id' => 'report-guide-' . $nonce,
        'natural_key' => 'report-guide-' . $nonce,
        'immutable_fingerprint' => hash('sha256', $nonce),
        'tenant_id' => $tenant,
        'organization_id' => (string)$service['organization_id'],
        'store_id' => (int)$service['store_id'],
        'member_id' => (int)$service['member_id'],
        'member_name_snapshot' => (string)$service['member_name_snapshot'],
        'order_id' => (string)$service['document_id'],
        'order_no_snapshot' => (string)$service['document_no_snapshot'],
        'checkout_request_id' => $requestId,
        'source_line_id' => $sourceLineId,
        'business_date' => $date,
        'guide_round_no' => 1,
        'guide_employee_id' => 1,
        'guide_employee_name_snapshot' => '本地测试导购',
        'guide_employee_type_snapshot' => 'guide',
        'operator_id' => (int)$service['operator_id'],
        'operator_name_snapshot' => (string)$service['operator_name_snapshot'],
        'business_event_no' => 'REPORT-PURE-' . $nonce,
        'command_idempotency_key' => 'report-guide-' . $nonce,
        'occurred_at' => (int)$service['occurred_at'],
        'recorded_at' => (int)$service['recorded_at'],
        'status' => 'effective',
    ]);
    // 服务回执 ID 不等于订单中心按结账请求合成的只读 ID；真实列表必须仍能回显导购。
    $storeId = (int)$service['store_id'];
    $organizationId = (string)$service['organization_id'];
    $orderScope = new CashierV3DataScopeContext(
        71, 701, $storeId, $tenant, $organizationId, [$storeId],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'permission-v2',
        ['cashier.v3.order_center'], []
    );
    $orders = (new CashierV3SalesOrderQueryServices())->querySalesOrders(
        ['dateFrom' => $date, 'dateTo' => $date, 'keyword' => 'REPORT-PURE-' . $nonce],
        new CashierV3OperatorScope($storeId, 71, $organizationId, $tenant),
        $orderScope
    );
    $orderItems = $orders['records'][0]['items'] ?? [];
    if (count($orders['records'] ?? []) !== 1
        || count($orderItems) !== 1
        || ($orderItems[0]['guides'][0]['name'] ?? '') !== '本地测试导购'
        || ($orderItems[0]['guides'][0]['guideRoundNo'] ?? null) !== 1
        || ($orderItems[0]['payableAmount'] ?? null) !== null) {
        throw new RuntimeException('Pure entitlement guide was not shown on the order center service line');
    }
    $roundDetail = $report->query([18], $input);
    $roundRows = array_values(array_filter($roundDetail['records'], static function (array $item) use ($serviceId): bool {
        return (string)($item['service_fact_id'] ?? '') === $serviceId;
    }));
    if (count($roundRows) !== 1 || ($roundRows[0]['visit_1_date'] ?? '') !== $date
        || ($roundRows[0]['visit_none_date'] ?? '') !== '') {
        throw new RuntimeException('Pure entitlement guide round was not projected from its own service line');
    }
    $annotation = new StoreOperationsReportAnnotationServices();
    $annotation->saveAnnotation(
        ['tenant_id' => $tenant, 'store_ids' => [18], 'operator_id' => 0, 'operator_name' => '本地回滚验证'],
        ['report_code' => 'field_acquisition_detail', 'subject_type' => 'business_event_line',
            'subject_key' => 'service:' . $serviceId, 'store_id' => 18, 'field_key' => 'visit_over_one_hour',
            'field_value' => '1', 'expected_version' => 0, 'idempotency_key' => 'report-annotation-' . $nonce]
    );
    $roundDetail = $report->query([18], $input);
    $annotatedRows = array_values(array_filter($roundDetail['records'], static function (array $item) use ($serviceId): bool {
        return (string)($item['service_fact_id'] ?? '') === $serviceId;
    }));
    if (count($annotatedRows) !== 1 || ($annotatedRows[0]['visit_over_one_hour'] ?? '') !== '1') {
        throw new RuntimeException('Pure entitlement manual field did not survive a fresh report query');
    }
    $export = $report->export([18], $input);
    if (($export['records'] ?? []) !== ($roundDetail['records'] ?? [])) {
        throw new RuntimeException('Pure entitlement service export differs from query');
    }
    $summary = $report->query([18], array_merge($input, ['report' => 'field_acquisition_summary']));
    if (!array_filter($summary['records'] ?? [], static function (array $item): bool {
        return (int)($item['member_id'] ?? 0) === 1136096;
    })) throw new RuntimeException('Pure entitlement member is absent from summary');
    $month = $report->query([18], array_replace($input, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']));
    foreach ($month['records'] as $item) {
        if (!empty($item['_service_only'])) continue;
        foreach (['visit_none_project', 'visit_1_project', 'visit_2_project', 'visit_3_project'] as $field) {
            if (in_array('REPORT-PURE-' . $nonce, explode('、', (string)($item[$field] ?? '')), true)) {
                throw new RuntimeException('Standalone pure entitlement service was duplicated in a sale row');
            }
        }
    }
    Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $serviceId)
        ->update(['business_source_primary_name_snapshot' => 'A老客首护']);
    $wrongSource = $report->query([18], $input);
    foreach ($wrongSource['records'] as $item) {
        if ((string)($item['service_fact_id'] ?? '') === $serviceId) {
            throw new RuntimeException('Non-E pure entitlement service leaked into field acquisition');
        }
    }
    echo "PASS E-source pure entitlement service row, guide round, zero cash, summary and export\n";
} finally {
    Db::rollback();
}

if (Db::name('cashier_v3_report_annotation')->where('subject_key', 'service:' . $serviceId)->count()
    || Db::name('cashier_v3_customer_guide_round_fact')->where('fact_id', 'report-guide-' . $nonce)->count()
    || Db::name('cashier_v3_entitlement_service_fact')->where('service_fact_id', $serviceId)->count()
    || Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->count()) {
    throw new RuntimeException('Local rollback left test facts behind');
}
echo "PASS rollback left no checkout or service facts\n";

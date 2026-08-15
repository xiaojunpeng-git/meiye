<?php

require dirname(__DIR__) . '/lib/_lib.php';
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreOperationsReportAnnotationServices;
use app\services\report\StoreReportParticipantScopeServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

c1aBootThinkApp(dirname(__DIR__, 3) . '/后端代码/');
date_default_timezone_set('Asia/Shanghai');

$participant = new StoreReportParticipantScopeServices();
$seed = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_sale_fact sf', 'sf.order_id=pf.order_id AND sf.status=\'effective\'')
    ->where('pf.status', 'effective')->where('pf.employee_id', '>', 0)
    ->field('pf.tenant_id,pf.employee_id,pf.store_id,pf.order_id,sf.source_line_id')
    ->order('pf.id', 'asc')->find();
if (!is_array($seed)) {
    fwrite(STDERR, "FAIL no participant fixture\n");
    exit(1);
}

$employeeId = (int)$seed['employee_id'];
$tenantId = (string)$seed['tenant_id'];
$ownStoreId = (int)$seed['store_id'];
$ownOrderId = (string)$seed['order_id'];
$ownLineId = (string)$seed['source_line_id'];

$participantStoreIds = $participant->participatingStoreIds($tenantId, $employeeId);
$unrelatedStoreId = (int)(Db::name('system_store')->where('id', 'not in', $participantStoreIds ?: [0])
    ->where('is_del', 0)->where('is_show', 1)->value('id') ?: 0);
ok('SELF organization picker contains only stores with own participation',
    in_array($ownStoreId, $participantStoreIds, true)
    && ($unrelatedStoreId === 0 || !in_array($unrelatedStoreId, $participantStoreIds, true)));

$ownQuery = Db::name('cashier_v3_sale_fact')->alias('scope_sale')
    ->where('scope_sale.tenant_id', $tenantId)->where('scope_sale.order_id', $ownOrderId);
$participant->applyOrder($ownQuery, 'scope_sale.order_id', $employeeId);
ok('SELF sees an order the employee participated in', (int)$ownQuery->count() > 0);

$other = Db::name('cashier_v3_sale_fact')->alias('other_sale')
    ->where('other_sale.tenant_id', $tenantId)->where('other_sale.store_id', $ownStoreId)
    ->where('other_sale.order_id', '<>', $ownOrderId)
    ->whereNotExists(function ($fact) use ($employeeId) {
        $fact->name('cashier_v3_performance_fact')->alias('other_pf')
            ->whereRaw('other_pf.order_id=other_sale.order_id')->where('other_pf.employee_id', $employeeId)->where('other_pf.status', 'effective');
    })->whereNotExists(function ($fact) use ($employeeId) {
        $fact->name('cashier_v3_customer_guide_round_fact')->alias('other_gf')
            ->whereRaw('other_gf.order_id=other_sale.order_id')->where('other_gf.guide_employee_id', $employeeId)->where('other_gf.status', 'effective');
    })->whereNotExists(function ($fact) use ($employeeId) {
        $fact->name('cashier_v3_sales_manager_fact')->alias('other_mf')
            ->whereRaw('other_mf.order_id=other_sale.order_id')->where('other_mf.sales_manager_employee_id', $employeeId)->where('other_mf.status', 'effective');
    })->field('other_sale.order_id,other_sale.source_line_id')->find();

if (is_array($other)) {
    $denied = Db::name('cashier_v3_sale_fact')->alias('denied_sale')->where('denied_sale.order_id', (string)$other['order_id']);
    $participant->applyOrder($denied, 'denied_sale.order_id', $employeeId);
    ok('SELF hides another employee order in the same store', (int)$denied->count() === 0);
} else {
    ok('SELF hides another employee order in the same store', false, 'fixture has no same-store non-participant order');
}

$stores = array_values(array_unique(array_map('intval', Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->column('id'))));
$service = new StoreUnifiedReportServices();
$scope = ['mode' => 'self_participant', 'employee_id' => $employeeId];
$input = ['report' => 'market_detail', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'page' => 1, 'limit' => 100, '_report_scope' => $scope];
$queryResult = $service->query($stores, $input);
$exportResult = $service->export($stores, $input);
$queryOrders = array_values(array_unique(array_filter(array_map('strval', array_column($queryResult['records'] ?? [], 'order_id')))));
$exportOrders = array_values(array_unique(array_filter(array_map('strval', array_column($exportResult['records'] ?? [], 'order_id')))));
ok('SELF query can return participated data outside an arbitrary current-store choice', in_array($ownOrderId, $queryOrders, true));
ok('SELF query and export use the same order scope', $queryOrders === $exportOrders);

$allRowsAuthorized = true;
foreach ($queryOrders as $orderId) {
    $check = Db::name('cashier_v3_sales_order')->alias('result_order')->where('result_order.order_id', $orderId);
    $participant->applyOrder($check, 'result_order.order_id', $employeeId);
    if ((int)$check->count() === 0) { $allRowsAuthorized = false; break; }
}
ok('SELF report never exposes an order without a participant fact', $allRowsAuthorized);

$dimension = (string)($queryResult['records'][0]['business_source_primary_id'] ?? '');
$drilldownInput = $input;
$drilldownInput['dimension_code'] = $dimension;
$drilldown = $service->query($stores, $drilldownInput);
$drilldownOrders = array_values(array_unique(array_filter(array_map('strval', array_column($drilldown['records'] ?? [], 'order_id')))));
ok('SELF drilldown remains a subset of the authorized query', count(array_diff($drilldownOrders, $queryOrders)) === 0);

$noneRejected = false;
try {
    $service->query($stores, array_merge($input, ['_report_scope' => ['mode' => 'none', 'employee_id' => $employeeId]]));
} catch (InvalidArgumentException $exception) {
    $noneRejected = strpos($exception->getMessage(), '没有可查看的数据范围') !== false;
}
ok('NONE is rejected before report execution', $noneRejected);

$ownSubject = $participant->resolveSubject($tenantId, 'sale_line', $ownLineId, $employeeId);
$otherSubject = is_array($other)
    ? $participant->resolveSubject($tenantId, 'sale_line', (string)$other['source_line_id'], $employeeId)
    : null;
ok('manual annotation subject resolver accepts own line', is_array($ownSubject));
ok('manual annotation subject resolver rejects another employee line', $otherSubject === null);

$annotation = new StoreOperationsReportAnnotationServices();
$annotationContext = [
    'tenant_id' => $tenantId, 'store_id' => 999999, 'store_ids' => null,
    'authorization_mode' => 'self_participant', 'participant_employee_id' => $employeeId,
    'operator_id' => 1, 'operator_name' => '测试',
];
$positiveWriteRead = false;
$idempotentReplay = false;
$versionConflict = false;
$explicitClearReadback = false;
$multipleLinesIsolated = false;
$crossSubjectReplayRejected = false;
Db::startTrans();
try {
    $existing = Db::name('cashier_v3_report_annotation')
        ->where('tenant_id', $tenantId)->where('report_code', 'new_customer_analysis')
        ->where('subject_type', 'sale_line')->where('subject_key', $ownLineId)
        ->where('field_key', 'care_duration')->find();
    $value = 'self-' . bin2hex(random_bytes(4));
    $savePayload = [
        'report_code' => 'new_customer_analysis', 'subject_type' => 'sale_line',
        'subject_key' => $ownLineId, 'field_key' => 'care_duration', 'field_value' => $value,
        'expected_version' => (int)($existing['version'] ?? 0),
        'idempotency_key' => 'report-self-write-' . bin2hex(random_bytes(8)),
    ];
    $saved = $annotation->saveAnnotation($annotationContext, $savePayload);
    $replayed = $annotation->saveAnnotation($annotationContext, $savePayload);
    $idempotentReplay = !empty($replayed['replayed'])
        && (int)$replayed['version'] === (int)$saved['version']
        && (string)$replayed['field_value'] === $value;
    try {
        $annotation->saveAnnotation($annotationContext, array_merge($savePayload, [
            'field_value' => $value . '-conflict',
            'expected_version' => (int)($existing['version'] ?? 0),
            'idempotency_key' => 'report-self-conflict-' . bin2hex(random_bytes(8)),
        ]));
    } catch (InvalidArgumentException $exception) {
        $versionConflict = strpos($exception->getMessage(), '刷新后再保存') !== false;
    }
    $listed = $annotation->listAnnotations($annotationContext, [
        'report_code' => 'new_customer_analysis', 'subject_type' => 'sale_line',
        'subject_key' => $ownLineId, 'field_key' => 'care_duration',
    ]);
    $positiveWriteRead = (string)($saved['field_value'] ?? '') === $value
        && count($listed) === 1 && (string)$listed[0]['field_value'] === $value
        && (int)$listed[0]['store_id'] === $ownStoreId;

    $cleared = $annotation->saveAnnotation($annotationContext, array_merge($savePayload, [
        'field_value' => '', 'expected_version' => (int)$saved['version'],
        'idempotency_key' => 'report-self-clear-' . bin2hex(random_bytes(8)),
    ]));
    $clearedRows = $annotation->listAnnotations($annotationContext, [
        'report_code' => 'new_customer_analysis', 'subject_type' => 'sale_line',
        'subject_key' => $ownLineId, 'field_key' => 'care_duration',
    ]);
    $explicitClearReadback = (string)$cleared['field_value'] === ''
        && count($clearedRows) === 1 && (string)$clearedRows[0]['field_value'] === ''
        && (int)$cleared['version'] === (int)$saved['version'] + 1;

    $secondLine = Db::name('cashier_v3_sale_fact')->where('tenant_id', $tenantId)
        ->where('order_id', $ownOrderId)->where('source_line_id', '<>', $ownLineId)
        ->where('status', 'effective')->field('source_line_id,store_id')->find();
    if (is_array($secondLine)) {
        $secondKey = (string)$secondLine['source_line_id'];
        $secondExisting = Db::name('cashier_v3_report_annotation')
            ->where('tenant_id', $tenantId)->where('report_code', 'new_customer_analysis')
            ->where('subject_type', 'sale_line')->where('subject_key', $secondKey)
            ->where('field_key', 'care_duration')->find();
        $secondSaved = $annotation->saveAnnotation($annotationContext, [
            'report_code' => 'new_customer_analysis', 'subject_type' => 'sale_line',
            'subject_key' => $secondKey, 'field_key' => 'care_duration', 'field_value' => 'second-line',
            'expected_version' => (int)($secondExisting['version'] ?? 0),
            'idempotency_key' => 'report-self-second-line-' . bin2hex(random_bytes(8)),
        ]);
        $multipleLinesIsolated = $secondKey !== $ownLineId
            && (string)$secondSaved['field_value'] === 'second-line'
            && (string)$cleared['field_value'] === '';
        try {
            $annotation->saveAnnotation($annotationContext, array_merge($savePayload, [
                'subject_key' => $secondKey,
            ]));
        } catch (InvalidArgumentException $exception) {
            $crossSubjectReplayRejected = strpos($exception->getMessage(), '幂等标识已用于其他补充内容') !== false;
        }
    } else {
        $multipleLinesIsolated = true;
        $crossSubjectReplayRejected = true;
    }
} finally {
    Db::rollback();
}
ok('manual annotation read and write resolve the authoritative participant store', $positiveWriteRead);
ok('manual annotation idempotent replay returns the first version without another write', $idempotentReplay);
ok('manual annotation rejects a stale expected version', $versionConflict);
ok('manual annotation preserves an explicit clear through readback', $explicitClearReadback);
ok('manual annotations remain isolated for multiple lines in one order', $multipleLinesIsolated);
ok('manual annotation rejects replaying one idempotency key for another line', $crossSubjectReplayRejected);

$writeDenied = false;
if (is_array($other)) {
    try {
        $annotation->saveAnnotation($annotationContext, [
            'report_code' => 'new_customer_analysis', 'subject_type' => 'sale_line',
            'subject_key' => (string)$other['source_line_id'], 'field_key' => 'care_duration',
            'field_value' => '1', 'expected_version' => 0,
            'idempotency_key' => 'report-self-denied-' . bin2hex(random_bytes(8)),
        ]);
    } catch (InvalidArgumentException $exception) {
        $writeDenied = strpos($exception->getMessage(), '非本人参与') !== false;
    }
}
ok('manual annotation write rejects a non-participant subject', $writeDenied);

finish('report-self-participant-mysql-integration');

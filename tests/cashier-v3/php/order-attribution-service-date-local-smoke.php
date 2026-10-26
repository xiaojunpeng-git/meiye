<?php
declare(strict_types=1);

/**
 * 第22轮纯服务订单本地验收：先事务回滚核对影响范围，显式 --persist 才保留指定 FW 单日期更正。
 * 来源始终只读，任何验收运行均不得把纯服务订单的来源改成 B。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderAttributionCorrectionServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$checkoutId = 'CKR-fc8ed3ee0ae98d0891edea1785d82d9e793b7902';
$storeId = 140;
$tenantId = '0';
$oldDate = '2026-10-03';
$newDate = '2026-10-02';
$action = 'correct-service-order-date';
$key = 'R22-LOCAL-SERVICE-FW100301126-DATE-20261002';
$persist = in_array('--persist', $argv, true);
$verify = in_array('--verify', $argv, true);
$request = (array)Db::name('cashier_v3_checkout_request')->where('request_id', $checkoutId)->find();
if (!$request || (int)$request['store_id'] !== $storeId || (string)$request['composition'] !== 'entitlement_only') {
    throw new RuntimeException('target checkout is not the expected local service-only order');
}
$operatorId = (int)$request['operator_id'];
$profile = (array)Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->find();
if (!$profile) throw new RuntimeException('original operator is unavailable');
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$operator = new CashierV3OperatorScope($storeId, $operatorId, $organizationId, $tenantId);
$scope = new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, $tenantId, $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'local-r22-service', ['cashier.v3.order_center'], $profile);
$service = new CashierV3OrderAttributionCorrectionServices();
$selection = (array)Db::name('cashier_v3_checkout_business_source_selection')
    ->where('checkout_request_id', $checkoutId)->where('tenant_id', $tenantId)->find();
if ((int)($selection['primary_source_id'] ?? 0) !== 17) throw new RuntimeException('original source A was not found');

$related = [
    'cashier_v3_entitlement_completion_receipt' => 1,
    'cashier_v3_entitlement_service_fact' => 2,
    'cashier_v3_entitlement_writeoff_fact' => 2,
    'cashier_v3_performance_fact' => 6,
];
$verifyState = static function (string $date) use ($checkoutId, $tenantId, $related, $selection): array {
    $requestDate = (string)Db::name('cashier_v3_checkout_request')->where('request_id', $checkoutId)->value('business_date');
    if ($requestDate !== $date) throw new RuntimeException('checkout request date mismatch');
    foreach ($related as $table => $expectedCount) {
        $rows = Db::name($table)->where('tenant_id', $tenantId)->where('checkout_request_id', $checkoutId)->select()->toArray();
        if (count($rows) !== $expectedCount || count(array_filter($rows, static function (array $row) use ($date): bool {
            return (string)$row['business_date'] !== $date;
        })) > 0) throw new RuntimeException($table . ' date or row count mismatch');
    }
    $visit = Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenantId)
        ->where('natural_key', 'service-visit:' . $checkoutId)->find();
    if (!$visit || (string)$visit['business_date'] !== $date) throw new RuntimeException('service visit date mismatch');
    $currentSource = (array)Db::name('cashier_v3_checkout_business_source_selection')
        ->where('checkout_request_id', $checkoutId)->where('tenant_id', $tenantId)->find();
    if ((int)$currentSource['primary_source_id'] !== 17
        || (string)$currentSource['source_label_snapshot'] !== (string)$selection['source_label_snapshot']) {
        throw new RuntimeException('pure service source must remain unchanged');
    }
    return ['checkoutDate' => $date, 'serviceFacts' => 2, 'writeoffFacts' => 2,
        'performanceFacts' => 6, 'sourcePrimaryId' => (int)$currentSource['primary_source_id']];
};

if ($verify) {
    // 验收后产品经理仍可再次更正；验证完整日期链，不把首次目标日误当永久最终值。
    $retainedDate = (string)$request['business_date'];
    $state = $verifyState($retainedDate);
    // 服务端同样拒绝绕过页面发送的来源更正，确保权限不是仅靠按钮显隐。
    Db::startTrans();
    try {
        $rejectKey = 'R22-LOCAL-SERVICE-SOURCE-MUST-REJECT';
        $rejectExecution = (new CashierV3BusinessEventRecorder())->newExecution($action, $rejectKey,
            $operator, $scope, 'R22-local-service-source-denial');
        $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
            'event_recorder' => new CashierV3BusinessEventRecorder(), 'event_execution' => $rejectExecution,
            'event_contract' => CashierV3ActionManifest::eventContractFor($action), 'action' => $action,
            'payload' => ['recordType' => 'service', 'recordId' => $checkoutId, 'field' => 'source',
                'expectedValue' => (string)$selection['source_label_snapshot'], 'primarySourceId' => 3],
            'idempotency_key' => $rejectKey]);
        throw new RuntimeException('pure service source mutation unexpectedly succeeded');
    } catch (\app\services\cashier\v3\CashierV3CommandException $expectedDenial) {
        if (!str_contains($expectedDenial->getMessage(), '不支持')) throw $expectedDenial;
    } finally {
        Db::rollback();
    }
    // 刷新后的订单中心也必须在新日期读到蓝色日期入口，而来源仍无编辑动作。
    $page = (new CashierV3SalesOrderQueryServices())->querySalesOrders([
        'recordType' => 'sales', 'dateFrom' => $retainedDate, 'dateTo' => $retainedDate,
        'keyword' => 'FW100301126', 'pageSize' => 20,
    ], $operator, $scope);
    $record = array_values(array_filter((array)($page['records'] ?? []), static function (array $item) use ($checkoutId): bool {
        return (string)($item['checkoutRequestId'] ?? '') === $checkoutId;
    }))[0] ?? null;
    if (!$record || empty($record['entitlementOnly'])
        || (string)$record['businessDate'] !== $retainedDate
        || !in_array('correct-service-order-date', (array)$record['availableActions'], true)
        || in_array('correct-sales-order-attribution', (array)$record['availableActions'], true)) {
        throw new RuntimeException('refreshed service-only order projection is not date-only editable');
    }
    $changes = $service->history(['recordType' => 'service', 'recordId' => $checkoutId], $operator, $scope)['changes'];
    $ascending = array_reverse($changes);
    $chainDate = $oldDate;
    foreach ($ascending as $change) {
        if ($change['recordNo'] !== 'FW100301126' || $change['field'] !== 'business_date'
            || $change['oldValue'] !== $chainDate) throw new RuntimeException('date correction chain is discontinuous');
        $chainDate = $change['newValue'];
    }
    if ($ascending === [] || $ascending[0]['newValue'] !== $newDate || $chainDate !== $retainedDate) {
        throw new RuntimeException('expected date-only change record is missing');
    }
    echo json_encode(['mode' => 'verify', 'state' => $state,
        'orderAvailableActions' => $record['availableActions'], 'changes' => $changes], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}
if ((string)$request['business_date'] !== $oldDate) throw new RuntimeException('target date already changed; use --verify');
$verifyState($oldDate);
$recorder = new CashierV3BusinessEventRecorder();
$execution = $recorder->newExecution($action, $key, $operator, $scope, 'R22-local-service-date');
$payload = ['recordType' => 'service', 'recordId' => $checkoutId, 'field' => 'business_date',
    'expectedValue' => $oldDate, 'newValue' => $newDate];
Db::startTrans();
try {
    $result = $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder, 'event_execution' => $execution,
        'event_contract' => CashierV3ActionManifest::eventContractFor($action),
        'action' => $action, 'payload' => $payload, 'idempotency_key' => $key]);
    $state = $verifyState($newDate);
    if ($persist) Db::commit(); else Db::rollback();
    if (!$persist) $verifyState($oldDate);
    echo json_encode(['mode' => $persist ? 'persist' : 'rollback', 'operationId' => $result['operationId'],
        'stateInTransaction' => $state], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}

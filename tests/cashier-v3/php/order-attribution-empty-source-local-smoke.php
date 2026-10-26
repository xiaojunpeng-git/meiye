<?php
declare(strict_types=1);

/** 第28轮本地真实库验证：空来源销售单可更正，所有写入在事务结束时回滚。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderAttributionCorrectionServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
$row = (array)Db::name('cashier_v3_sales_order')->where('order_no', 'XS26100300017')->find();
if (!$row || (int)$row['store_id'] !== 100 || (string)$row['tenant_id'] !== '0'
    || (int)$row['business_source_primary_id'] !== 0 || (int)$row['business_source_secondary_id'] !== 0
    || trim((string)$row['business_source_primary_name_snapshot']) !== ''
    || trim((string)$row['business_source_secondary_name_snapshot']) !== ''
    || trim((string)$row['business_source_label_snapshot']) !== '') {
    throw new RuntimeException('empty-source sales fixture unavailable');
}
$operatorId = (int)Db::name('system_store_staff')->where('store_id', 100)->where('status', 1)->value('id');
$organizationId = (string)Db::name('organization_store')->where('store_id', 100)->value('org_id');
if ($operatorId <= 0 || $organizationId === '') throw new RuntimeException('store operator unavailable');
$profile = (array)Db::name('system_store_staff')->where('id', $operatorId)->find();
$operator = new CashierV3OperatorScope(100, $operatorId, $organizationId, '0');
$scope = new CashierV3DataScopeContext($operatorId, $operatorId, 100, '0', $organizationId, [100],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'local-r28', ['cashier.v3.order_center'], $profile);
$recorder = new CashierV3BusinessEventRecorder();
$service = new CashierV3OrderAttributionCorrectionServices();
$payload = [
    'recordType' => 'sales', 'recordId' => (string)$row['order_id'], 'field' => 'source',
    'expectedValue' => '', 'expectedPrimarySourceId' => 0, 'expectedSecondarySourceId' => 0,
    'primarySourceId' => 3, 'secondarySourceId' => 0,
];

/** 每次独立事务用新操作号，避免幂等重放遮蔽并发校验。 */
function attempt(array $payload, string $key, CashierV3OperatorScope $operator,
    CashierV3DataScopeContext $scope, CashierV3BusinessEventRecorder $recorder,
    CashierV3OrderAttributionCorrectionServices $service): array
{
    $action = 'correct-sales-order-attribution';
    $execution = $recorder->newExecution($action, $key, $operator, $scope, 'R28-local-empty-source');
    return $service->executeInTx([
        'operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder, 'event_execution' => $execution,
        'event_contract' => CashierV3ActionManifest::eventContractFor($action),
        'action' => $action, 'payload' => $payload, 'idempotency_key' => $key,
    ]);
}

Db::startTrans();
try {
    $result = attempt($payload, 'R28-EMPTY-SOURCE-SUCCESS', $operator, $scope, $recorder, $service);
    $changed = (array)Db::name('cashier_v3_sales_order')->where('order_id', $row['order_id'])->find();
    if ((int)$changed['business_source_primary_id'] !== 3) throw new RuntimeException('source correction not applied in transaction');
    if (!Db::name('cashier_v3_order_attribution_change')->where('operation_id', $result['operationId'])->count()) {
        throw new RuntimeException('source correction audit missing');
    }
} finally {
    Db::rollback();
}

Db::startTrans();
try {
    $stale = $payload;
    $stale['expectedValue'] = '—';
    try {
        attempt($stale, 'R28-EMPTY-SOURCE-STALE', $operator, $scope, $recorder, $service);
        throw new RuntimeException('display placeholder bypassed concurrency guard');
    } catch (CashierV3CommandException $expected) {
        if (strpos($expected->getMessage(), '日期或来源已被其他操作更改') === false) throw $expected;
    }
} finally {
    Db::rollback();
}
$unchanged = (array)Db::name('cashier_v3_sales_order')->where('order_id', $row['order_id'])->find();
if ((int)$unchanged['business_source_primary_id'] !== 0 || (int)$unchanged['business_source_secondary_id'] !== 0) {
    throw new RuntimeException('rollback did not restore sales source');
}
echo "empty-source sale corrected in rollback-only transaction; stale displayed value denied\n";

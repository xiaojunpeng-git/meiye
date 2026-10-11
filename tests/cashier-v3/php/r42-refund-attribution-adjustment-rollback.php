<?php
declare(strict_types=1);

/** Local-only rollback probe: attribution revisions change the refund projection, never the original sale. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3RefundAttributionServices;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This probe is restricted to the local ruihao database.');
}
$operation = (array)Db::name('cashier_v3_order_lifecycle_operation')->alias('op')
    ->join('cashier_v3_refund_adjustment ra', 'ra.operation_id=op.operation_id AND ra.tenant_id=op.tenant_id')
    ->where('op.tenant_id', '0')->where('op.operation_type', 'refund')->where('op.status', 'succeeded')
    ->where('ra.status', 'effective')->whereIn('op.source_type', ['card', 'document', 'catalog'])
    ->field('op.operation_id,op.operation_no,op.member_id,op.store_id,op.business_date')
    ->order('op.id desc')->find();
if (!$operation) throw new RuntimeException('Local successful refund fixture is unavailable.');
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.store_id', (int)$operation['store_id'])->where('s.status', 1)->where('s.is_del', 0)
    ->field('s.*,os.org_id')->find();
if (!$staff) throw new RuntimeException('Local staff fixture is unavailable.');
$operator = new CashierV3OperatorScope((int)$staff['store_id'], (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'], '0',
    (string)$staff['org_id'], [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r42-refund-attribution-adjustment', ['cashier.v3.order_center', 'cashier.v3.order.staff_adjust'], $staff);
$service = new CashierV3RefundAttributionServices();
$recordId = (string)$operation['operation_id'];
$initial = $service->entry(['recordId' => $recordId], $operator, $scope);
$noPermissionScope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'], '0',
    (string)$staff['org_id'], [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r42-refund-attribution-no-permission', ['cashier.v3.order_center'], $staff);
try {
    $service->entry(['recordId' => $recordId], $operator, $noPermissionScope);
    throw new RuntimeException('Staff-adjust permission was not enforced.');
} catch (CashierV3CommandException $error) {
    if (!str_contains($error->getMessage(), '没有修改退款归属')) throw $error;
}
$beforeRevisions = (int)Db::name(CashierV3RefundAttributionServices::REVISION_TABLE)
    ->where('operation_id', $recordId)->count();
$beforeSale = (int)Db::name('cashier_v3_customer_guide_round_fact')->count();
$beforePerformance = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $recordId)->count();
$eventRecorder = new CashierV3BusinessEventRecorder();
$key = 'r42-refund-attribution-' . bin2hex(random_bytes(12));
$base = ['operator_scope' => $operator, 'data_scope' => $scope, 'action' => 'adjust-refund-attribution',
    'event_recorder' => $eventRecorder,
    'event_execution' => $eventRecorder->newExecution('adjust-refund-attribution', $key, $operator, $scope, 'r42-local-rollback'),
    'event_contract' => CashierV3ActionManifest::eventContractFor('adjust-refund-attribution'),
    'idempotency_key' => $key];
$candidate = (array)($initial['guides'][0] ?? []);
if (!$candidate) throw new RuntimeException('Local guide fixture is unavailable.');
$oldIds = array_map('intval', array_column($initial['currentGuides'], 'employeeId'));
$chooseGuide = $candidate && !in_array((int)$candidate['employeeId'], $oldIds, true);
$selection = $chooseGuide ? [['staffId' => (int)$candidate['employeeId'], 'guideRoundNo' => 0]] : [];
if (!$chooseGuide && $initial['currentGuides'] === [] && $initial['currentSalesManagers'] === []) {
    $selection = [['staffId' => (int)$candidate['employeeId'], 'guideRoundNo' => 0]];
}
$payload = ['recordId' => $recordId, 'recordVersion' => (int)$initial['recordVersion'],
    'guideSelections' => $selection, 'salesManagerSelections' => [], 'reason' => '本地回滚验证改派'];
Db::startTrans();
try {
    $result = $service->adjustInTx($base + ['payload' => $payload]);
    if ($result['replayed'] || (int)$result['recordVersion'] !== (int)$initial['recordVersion'] + 1) {
        throw new RuntimeException('Attribution revision did not advance once.');
    }
    $current = $service->entry(['recordId' => $recordId], $operator, $scope);
    if (count($current['currentGuides']) !== count($selection) || $current['currentSalesManagers'] !== []) {
        throw new RuntimeException('Current refund attribution did not use the new complete snapshot.');
    }
    $firstPage = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'keyword' => (string)$operation['operation_no'],
        'businessDateFrom' => (string)$operation['business_date'],
        'businessDateTo' => (string)$operation['business_date'],
    ], $operator, $scope);
    $firstSummary = (string)($firstPage['records'][0]['refundAttributionSummary'] ?? '');
    if ($selection !== [] && !str_contains($firstSummary, (string)$candidate['name'])) {
        throw new RuntimeException('Refund list did not show the revised guide.');
    }
    if ($selection === [] && $firstSummary !== '') {
        throw new RuntimeException('Refund list retained original personnel after clearing.');
    }
    $replay = $service->adjustInTx($base + ['payload' => $payload]);
    if (empty($replay['replayed'])) throw new RuntimeException('Idempotent retry created a second revision.');
    try {
        $service->adjustInTx(array_replace($base, ['idempotency_key' => $key . '-stale',
            'payload' => $payload]));
        throw new RuntimeException('Stale version was accepted.');
    } catch (CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '已被修改')) throw $error;
    }
    // 同一退款先改归属、再改销售人，两种事件必须连续编号，不能碰撞唯一键。
    $salesService = new CashierV3RefundSalespersonServices();
    $salesEntry = $salesService->entry(['recordId' => $recordId], $operator, $scope);
    $salesCandidate = (array)($salesEntry['salespeople'][0] ?? []);
    if (!$salesCandidate) throw new RuntimeException('Local refund salesperson fixture is unavailable.');
    $eventVersionBeforeSales = (int)Db::name('cashier_v3_business_event')->where('tenant_id', '0')
        ->where('aggregate_type', 'manual_refund')->where('aggregate_id', $recordId)->max('aggregate_version');
    $salesKey = $key . '-sales';
    $salesService->adjustInTx(array_replace($base, [
        'action' => 'adjust-refund-personnel', 'idempotency_key' => $salesKey,
        'event_execution' => $eventRecorder->newExecution('adjust-refund-personnel', $salesKey, $operator, $scope, 'r42-local-rollback'),
        'event_contract' => CashierV3ActionManifest::eventContractFor('adjust-refund-personnel'),
        'payload' => ['recordId' => $recordId, 'recordVersion' => $salesEntry['recordVersion'],
            'personnel' => [['staffId' => (int)$salesCandidate['staffId'], 'allocationWeight' => 100]],
            'reason' => '本地回滚验证事件编号'],
    ]));
    $eventVersionAfterSales = (int)Db::name('cashier_v3_business_event')->where('tenant_id', '0')
        ->where('aggregate_type', 'manual_refund')->where('aggregate_id', $recordId)->max('aggregate_version');
    if ($eventVersionAfterSales !== $eventVersionBeforeSales + 1) {
        throw new RuntimeException('Refund salesperson adjustment reused the attribution event version.');
    }
    // 二次改派和空人员集必须成为新版本，不能让列表回退到初次退款快照。
    if ($selection === []) {
        $second = $service->entry(['recordId' => $recordId], $operator, $scope);
        $service->adjustInTx(array_replace($base, ['idempotency_key' => $key . '-second',
            'event_execution' => $eventRecorder->newExecution('adjust-refund-attribution', $key . '-second', $operator, $scope, 'r42-local-rollback'),
            'payload' => array_replace($payload, ['recordVersion' => $second['recordVersion'],
                'guideSelections' => [['staffId' => (int)$candidate['employeeId'], 'guideRoundNo' => 0]]])]));
    }
    $beforeClear = $service->entry(['recordId' => $recordId], $operator, $scope);
    $service->adjustInTx(array_replace($base, ['idempotency_key' => $key . '-clear',
        'event_execution' => $eventRecorder->newExecution('adjust-refund-attribution', $key . '-clear', $operator, $scope, 'r42-local-rollback'),
        'payload' => array_replace($payload, ['recordVersion' => $beforeClear['recordVersion'],
            'guideSelections' => [], 'salesManagerSelections' => []])]));
    $cleared = $service->entry(['recordId' => $recordId], $operator, $scope);
    if ($cleared['currentGuides'] !== [] || $cleared['currentSalesManagers'] !== []
        || (int)$cleared['recordVersion'] !== (int)$beforeClear['recordVersion'] + 1) {
        throw new RuntimeException('Empty latest revision did not replace earlier personnel.');
    }
    $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'keyword' => (string)$operation['operation_no'],
        'businessDateFrom' => (string)$operation['business_date'],
        'businessDateTo' => (string)$operation['business_date'],
    ], $operator, $scope);
    $record = (array)($page['records'][0] ?? []);
    if ((string)($record['refundAttributionSummary'] ?? '') !== '') {
        throw new RuntimeException('Cleared attribution fell back to original personnel.');
    }
} finally {
    Db::rollback();
}
if ((int)Db::name(CashierV3RefundAttributionServices::REVISION_TABLE)
    ->where('operation_id', $recordId)->count() !== $beforeRevisions
    || (int)Db::name('cashier_v3_customer_guide_round_fact')->count() !== $beforeSale
    || (int)Db::name('cashier_v3_performance_fact')->where('order_id', $recordId)->count() !== $beforePerformance) {
    throw new RuntimeException('Rollback left refund revisions or changed original business facts.');
}
echo "R42 refund attribution adjustment rollback PASS\n";

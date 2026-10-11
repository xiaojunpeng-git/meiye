<?php
declare(strict_types=1);

/** Local-only attribution probe: defaults, role validation, list projection and transactional rollback. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3RefundAttributionServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This probe is restricted to the local ruihao database.');
}
$service = new CashierV3RefundAttributionServices();
$saleLine = (array)Db::name('cashier_v3_sales_order_line')->alias('l')
    ->join('cashier_v3_customer_guide_round_fact g',
        'g.tenant_id=l.tenant_id AND g.order_id=l.order_id AND g.source_line_id=l.checkout_line_id')
    ->join('cashier_v3_sales_manager_fact m',
        'm.tenant_id=l.tenant_id AND m.order_id=l.order_id AND m.source_line_id=l.checkout_line_id')
    ->where('l.tenant_id', '0')->where('l.line_direction', 'forward')->where('l.line_status', 'settled')
    ->where('g.status', 'effective')->where('m.status', 'effective')
    ->field('l.order_id,l.order_line_id,l.member_id,l.store_id')
    ->order('l.id desc')->find();
if (!$saleLine) throw new RuntimeException('Formal sale with guide and manager attribution is unavailable.');
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.store_id', (int)$saleLine['store_id'])->where('s.status', 1)->where('s.is_del', 0)
    ->field('s.*,os.org_id')->find();
if (!$staff) throw new RuntimeException('Local staff fixture is unavailable.');
$operator = new CashierV3OperatorScope((int)$staff['store_id'], (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'], '0',
    (string)$staff['org_id'], [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r42-refund-attribution', ['cashier.v3.order_center', 'cashier.v3.order.refund'], $staff);
$source = ['orderId' => (string)$saleLine['order_id'], 'lineId' => (string)$saleLine['order_line_id']];
$defaults = $service->selection($source, (int)$saleLine['member_id'], $operator, $scope);
if (count($defaults['currentGuides']) < 1 || count($defaults['currentSalesManagers']) < 1) {
    throw new RuntimeException('Original guide or sales manager was not read.');
}
$blank = $service->selection(['orderId' => '', 'lineId' => ''], (int)$saleLine['member_id'], $operator, $scope);
if ($blank['currentGuides'] !== [] || $blank['currentSalesManagers'] !== []) {
    throw new RuntimeException('A refund without a formal source inherited personnel.');
}

// 只借用尚无归属的本地退款作为列表夹具，避免与已真实落账的人员唯一键冲突。
$attributedIds = Db::name(CashierV3RefundAttributionServices::TABLE)->where('tenant_id', '0')->column('operation_id');
$operationQuery = Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id', '0')
    ->where('store_id', (int)$staff['store_id'])->where('operation_type', 'refund')
    ->where('status', 'succeeded');
if ($attributedIds !== []) $operationQuery->whereNotIn('operation_id', $attributedIds);
$operation = (array)$operationQuery->order('id desc')->find();
if ($operation === []) throw new RuntimeException('Local refund list fixture is unavailable.');
$guide = (array)Db::name('employee')->where('id', (int)$defaults['currentGuides'][0]['employeeId'])->find();
if ((int)($guide['status'] ?? 0) !== 1 || (int)($guide['employment_type_version'] ?? 0) <= 0) {
    throw new RuntimeException('Local original guide is no longer eligible.');
}
$manager = (array)Db::name('employee')->where('status', 1)->where('is_del', 0)
    ->where('id', '<>', (int)$guide['id'])->order('id asc')->find();
if (!$manager) throw new RuntimeException('Manager fixture is unavailable.');
$before = (int)Db::name(CashierV3RefundAttributionServices::TABLE)
    ->where('operation_id', (string)$operation['operation_id'])->count();
$saleBefore = (int)Db::name('cashier_v3_customer_guide_round_fact')
    ->where('order_id', $source['orderId'])->count();
$payload = ['guideSelections' => [['staffId' => (int)$guide['id'], 'guideRoundNo' => 0]],
    'salesManagerSelections' => [['staffId' => (int)$manager['id']]]];
Db::startTrans();
try {
    try {
        $invalid = $payload;
        $invalid['guideSelections'][0]['guideRoundNo'] = 1;
        $service->createInTx($operation, $invalid, $source,
            ['event_no' => (string)$operation['business_event_no']], $operator, $scope);
        throw new RuntimeException('A guide round was accepted for a refund.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '不记录轮次')) throw $error;
    }
    $service->createInTx($operation, $payload, $source,
        ['event_no' => (string)$operation['business_event_no']], $operator, $scope);
    $rows = Db::name(CashierV3RefundAttributionServices::TABLE)
        ->where('operation_id', (string)$operation['operation_id'])->select()->toArray();
    if (count($rows) !== $before + 2 || count(array_filter($rows,
        static fn(array $row): bool => (int)$row['employee_id'] === (int)$guide['id']
            && (string)$row['role'] === 'guide')) !== 1) {
        throw new RuntimeException('Refund attribution snapshots were not inserted once per role.');
    }
    $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'keyword' => (string)$operation['operation_no'],
        'businessDateFrom' => (string)$operation['business_date'],
        'businessDateTo' => (string)$operation['business_date'],
    ], $operator, $scope);
    $record = (array)($page['records'][0] ?? []);
    if (!str_contains((string)($record['refundAttributionSummary'] ?? ''), (string)$guide['name'])
        || !str_contains((string)$record['refundAttributionSummary'], (string)$manager['name'])) {
        throw new RuntimeException('Refund list did not project its saved attribution.');
    }
} finally {
    Db::rollback();
}
if ((int)Db::name(CashierV3RefundAttributionServices::TABLE)
    ->where('operation_id', (string)$operation['operation_id'])->count() !== $before
    || (int)Db::name('cashier_v3_customer_guide_round_fact')->where('order_id', $source['orderId'])->count() !== $saleBefore) {
    throw new RuntimeException('Transaction rollback changed existing refund or original sale facts.');
}
echo "R42 refund attribution rollback PASS\n";

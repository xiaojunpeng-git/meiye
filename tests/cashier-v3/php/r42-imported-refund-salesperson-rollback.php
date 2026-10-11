<?php
declare(strict_types=1);

/** Local fixture only: exercise the imported-card refund personnel command, then roll back every write. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('R42 fixture is restricted to the local ruihao database.');
}

$operationId = 'OLO-80F50383CDBECC9CBBFAC9D843CB28139A8AF783';
$operation = (array)Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id', $operationId)->find();
if ((int)($operation['store_id'] ?? 0) !== 175 || (string)($operation['source_type'] ?? '') !== 'imported_card'
    || (string)($operation['operation_type'] ?? '') !== 'refund' || (string)($operation['status'] ?? '') !== 'succeeded'
    || (int)($operation['cash_refund_cents'] ?? 0) !== 50000) {
    throw new RuntimeException('The local imported-card refund fixture changed.');
}
if (Db::name('cashier_v3_refund_salesperson_revision')->where('operation_id', $operationId)->count() !== 0) {
    throw new RuntimeException('The local fixture already has personnel revisions.');
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')->where('s.id', 818)
    ->field('s.*,os.org_id')->find();
$operator = new CashierV3OperatorScope(175, 818, (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext(818, 818, 175, '0', (string)$staff['org_id'], [175],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r42-imported-refund-personnel',
    ['cashier.v3.order_center', 'cashier.v3.order.staff_adjust'], $staff);
$personnel = new CashierV3RefundSalespersonServices();
$entry = $personnel->entry(['recordId' => $operationId], $operator, $scope);
$normal = array_values(array_filter($entry['salespeople'], static fn(array $p): bool => !$p['performanceIndependent']));
if ((int)$entry['recordVersion'] !== 0 || $entry['currentSalespeople'] !== [] || count($normal) < 2) {
    throw new RuntimeException('Initial old-card personnel entry or candidates are wrong.');
}
$key = static function (): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
};
$dispatcher = CashierV3Bootstrap::dispatcher();
$run = static function (string $action, string $commandKey, array $payload) use ($dispatcher, $operator, $scope, $staff): array {
    return $dispatcher->gateway()->execute($action,
        ['idempotencyKey' => $commandKey, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($commandKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand($action), $scope,
        CashierV3ActionManifest::requireAction($action));
};
$action = 'adjust-imported-refund-personnel';
$list = new CashierV3OrderCenterRecordQueryServices();
$listRow = static function () use ($list, $operation, $operator, $scope): array {
    $page = $list->queryRecords(['recordType' => 'refund', 'keyword' => $operation['operation_no'],
        'businessDateFrom' => $operation['business_date'], 'businessDateTo' => $operation['business_date']],
        $operator, $scope);
    return (array)($page['records'][0] ?? []);
};
if (($listRow()['salespersonSummary'] ?? '') !== '') throw new RuntimeException('Initial list must not guess a salesperson.');
$paymentBefore = (int)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)->sum('amount_cents');
$originalBefore = (int)Db::name('cashier_v3_performance_fact')->where('order_id', (string)$operation['source_order_id'])->count();
$refundFactBefore = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)->count();

Db::startTrans();
try {
    $commandKey = $key();
    $first = ['recordId' => $operationId, 'recordVersion' => 0, 'reason' => 'R42旧卡退款改派回滚验证',
        'personnel' => [['staffId' => $normal[0]['staffId'], 'allocationWeight' => 100]]];
    // The old-card refund cannot be smuggled through the ordinary refund command.
    try {
        $run('adjust-refund-personnel', $key(), $first);
        throw new RuntimeException('Ordinary refund command accepted an imported-card operation.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '操作类型')) throw $error;
    }
    $deniedStaff = (array)Db::name('system_store_staff')->where('id', 395)->find();
    $deniedOperator = new CashierV3OperatorScope(175, 395, (string)$staff['org_id'], '0');
    $deniedKey = $key();
    try {
        $dispatcher->gateway()->execute($action, ['idempotencyKey' => $deniedKey, 'contexts' => []],
            $first, $deniedOperator, ['client_session_id' => 'SESSION-' . substr($deniedKey, 4), 'operator_profile' => $deniedStaff],
            $dispatcher->handlers()->requireCommand($action), $scope,
            CashierV3ActionManifest::requireAction($action));
        throw new RuntimeException('Account without staff adjustment permission was accepted.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '没有该功能的操作权限')) throw $error;
    }
    $response = $run($action, $commandKey, $first);
    if (($response['status'] ?? '') !== 'success') throw new RuntimeException('First adjustment failed: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    $replay = $run($action, $commandKey, $first);
    if (!in_array((string)($replay['status'] ?? ''), ['success', 'succeeded'], true)
        || (int)Db::name('cashier_v3_refund_salesperson_revision')->where('operation_id', $operationId)->count() !== 1) {
        throw new RuntimeException('Idempotent replay duplicated the adjustment.');
    }
    $current = $personnel->entry(['recordId' => $operationId], $operator, $scope);
    if ((int)$current['recordVersion'] !== 1 || count($current['currentSalespeople']) !== 1
        || !str_contains((string)($listRow()['salespersonSummary'] ?? ''), $normal[0]['name'])) {
        throw new RuntimeException('First adjustment did not reach entry and list.');
    }
    $stale = $first;
    $stale['personnel'] = [['staffId' => $normal[1]['staffId'], 'allocationWeight' => 100]];
    try {
        $run($action, $key(), $stale);
        throw new RuntimeException('Stale personnel version was accepted.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '已被修改')) throw $error;
    }
    $second = $stale;
    $second['recordVersion'] = 1;
    $changed = $run($action, $key(), $second);
    if (($changed['status'] ?? '') !== 'success') throw new RuntimeException('Second adjustment failed.');
    $facts = Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
        ->where('performance_type', 'refund_sales_deduction')->order('id asc')->select()->toArray();
    if (count($facts) !== 3 || array_column($facts, 'fact_direction') !== ['forward', 'reversal', 'forward']
        || array_map('intval', array_column($facts, 'amount_cents')) !== [-50000, -50000, -50000]
        || (int)$facts[2]['employee_id'] !== (int)$normal[1]['employeeId']) {
        throw new RuntimeException('Negative performance reassignment/reversal is wrong.');
    }
    $salary = (new StoreUnifiedReportPhaseSixServices())->query('phase_six_salary_detail', [175],
        ['start' => $operation['business_date'], 'end' => $operation['business_date']],
        ['document_no' => $operation['operation_no'], '_internal_all' => true]);
    if (count($salary['records']) !== 1
        || (int)$salary['records'][0]['cash_amount_cents'] !== -50000
        || (int)$salary['records'][0]['employee_id'] !== (int)$normal[1]['employeeId']) {
        throw new RuntimeException('Salary detail does not show exactly one active deduction: '
            . json_encode($salary['records'], JSON_UNESCAPED_UNICODE));
    }
    if ((int)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)->sum('amount_cents') !== $paymentBefore
        || (int)Db::name('cashier_v3_performance_fact')->where('order_id', (string)$operation['source_order_id'])->count() !== $originalBefore
        || (int)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)->count() !== 0
        || (int)($personnel->entry(['recordId' => $operationId], $operator, $scope)['recordVersion'] ?? -1) !== 2) {
        throw new RuntimeException('Original payment/sale changed or imported refund was misclassified.');
    }
    echo "R42 imported-card refund personnel: command, replay, stale guard, list, salary, reversal, source preservation OK\n";
} finally {
    Db::rollback();
}
if ((int)Db::name('cashier_v3_refund_salesperson_revision')->where('operation_id', $operationId)->count() !== 0) {
    throw new RuntimeException('Test transaction did not roll back.');
}
if ((int)Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)->count() !== $refundFactBefore) {
    throw new RuntimeException('Test left refund performance facts behind.');
}

<?php
declare(strict_types=1);

/** Exact local BJ26101300001 repair; rollback by default, persist only with an explicit apply flag. */
if (getenv('R15_LOCAL_REISSUE') !== '1') throw new RuntimeException('Explicit local test flag required');
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
date_default_timezone_set('Asia/Shanghai');
$apply = in_array('--apply', $argv, true);
if ((string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Local ruihao database required');
}
$old = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_no', 'BJ26101300001')->find();
if ((string)($old['repayment_id'] ?? '') !== 'DRP-0368868802272a78d295b1696b18648d04fa276e'
    || (string)$old['status'] !== 'succeeded' || (int)$old['store_id'] !== 18
    || (int)$old['repayment_amount_cents'] !== 9800) {
    throw new RuntimeException('Original local repayment changed; no action taken');
}
$selected = json_decode((string)$old['salespeople_snapshot_json'], true);
if (!is_array($selected) || count($selected) !== 1 || (int)$selected[0]['staffId'] !== 1127
    || empty($selected[0]['isPreSale'])) {
    throw new RuntimeException('Original presale selection is not the expected one');
}
$cash = (array)Db::name('cashier_v3_debt_repayment_collection')
    ->where('repayment_id', $old['repayment_id'])->find();
if ((string)($cash['payment_method'] ?? '') !== 'cash' || (int)$cash['amount_cents'] !== 9800
    || (string)$cash['external_transaction_no_snapshot'] !== '') {
    throw new RuntimeException('Original cash line changed; no action taken');
}
$operatorStaff = (array)Db::name('system_store_staff')->where('id', 239)->where('store_id', 18)
    ->where('status', 1)->where('is_del', 0)->find();
if (!$operatorStaff) throw new RuntimeException('Local scoped operator unavailable');
$debtBefore = (array)Db::name('store_debt')->where('id', (int)$old['debt_id'])->find();
$source = (new CashierV3BusinessConfigServices())->resolveSourceSnapshot(
    (int)$old['business_source_primary_id'], (int)$old['business_source_secondary_id'], true);
if ((string)$source['primarySourceNameSnapshot'] !== (string)$old['business_source_primary_name_snapshot']) {
    throw new RuntimeException('Business source changed; no action taken');
}
$operator = new CashierV3OperatorScope(18, 239, (string)$old['organization_id'], '0');
$scope = new CashierV3DataScopeContext(239, 239, 18, '0', (string)$old['organization_id'], [18],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r15-local-reissue',
    ['cashier.v3.order_center', 'cashier.v3.order.void'], $operatorStaff);
$recorder = new CashierV3BusinessEventRecorder();
$suffix = bin2hex(random_bytes(8));
$voidKey = 'r15-presale-void-' . $suffix;
$submitKey = 'r15-presale-reissue-' . $suffix;

Db::startTrans();
try {
    $voidAction = 'void-order-center-supplement';
    (new CashierV3OrderCenterVoidServices())->executeInTx($voidAction, [
        'operator_scope' => $operator, 'data_scope' => $scope, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution($voidAction, $voidKey, $operator, $scope, 'r15-local-reissue'),
        'event_contract' => CashierV3ActionManifest::eventContractFor($voidAction),
        'idempotency_key' => $voidKey,
        'payload' => ['recordId' => 'v3-sales-supplement:' . $old['repayment_id'],
            'reason' => 'R15售前业绩事实漏记，作废后重开'],
    ]);
    $snapshot = [
        'businessType' => 'debt_repayment', 'memberId' => (int)$old['member_id'],
        'businessDate' => (string)$old['business_date'], 'repaymentAmount' => 98,
        'source' => $source, 'salespersonAllocations' => [[
            'staffId' => 1127, 'allocationWeight' => 100, 'isPreSale' => true,
        ]],
        'sourceDocument' => ['type' => 'debt_repayment', 'id' => (string)$old['debt_id']],
        'lines' => [
            ['lineRole' => 'sale', 'sourceId' => (int)$old['debt_id'], 'lineAmountCents' => 9800, 'debtAmountCents' => 0],
            ['lineRole' => 'payment', 'method' => 'cash', 'amount' => 98, 'remark' => (string)$cash['remark_snapshot']],
        ],
    ];
    $submitAction = 'submit-debt-repayment';
    $result = (new CashierV3DebtRepaymentServices())->submitInTx([
        'operator_scope' => $operator, 'data_scope' => $scope, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution($submitAction, $submitKey, $operator, $scope, 'r15-local-reissue'),
        'event_contract' => CashierV3ActionManifest::eventContractFor($submitAction),
        'idempotency_key' => $submitKey, 'payload' => ['checkoutSnapshot' => $snapshot],
    ]);
    $newId = (string)($result['data']['debtRepayment']['repaymentId'] ?? '');
    $newNo = (string)($result['data']['debtRepayment']['repaymentNo'] ?? '');
    $new = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_id', $newId)->find();
    $debtAfter = (array)Db::name('store_debt')->where('id', (int)$old['debt_id'])->find();
    $fact = (array)Db::name('cashier_v3_performance_fact')->where('order_id', $newId)
        ->where('performance_type', 'sales_performance_allocated')->where('fact_direction', 'forward')->find();
    $paymentFacts = Db::name('cashier_v3_payment_fact')->whereIn('order_id', [$old['repayment_id'], $newId])
        ->where('source_document_type', 'debt_repayment')->where('status', 'effective')->select()->toArray();
    $netCash = 0;
    foreach ($paymentFacts as $paymentFact) {
        // 作废反向事实的金额已带负号，不能再按方向反转一次。
        $netCash += (int)$paymentFact['amount_cents'];
    }
    $labelReader = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'supplementSalespeople');
    $labelReader->setAccessible(true);
    $labels = $labelReader->invoke(new CashierV3OrderCenterRecordQueryServices(static fn(): array => []),
        [$newId], 'debt_repayment', '0');
    if ((string)Db::name('cashier_v3_debt_repayment')->where('id', $old['id'])->value('status') !== 'voided'
        || (string)($new['status'] ?? '') !== 'succeeded'
        || (string)($debtAfter['repaid_debt'] ?? '') !== (string)$debtBefore['repaid_debt']
        || (int)($debtAfter['status'] ?? -1) !== (int)$debtBefore['status']
        || (string)($fact['role_snapshot'] ?? '') !== 'salesperson:presale'
        || (int)($fact['amount_cents'] ?? 0) !== 9800
        || $netCash !== 9800
        || ($labels[$newId] ?? '') !== '沈立华(售前,¥98)') {
        throw new RuntimeException('Reissue accounting or presale readback failed: ' . json_encode([
            'oldStatus' => Db::name('cashier_v3_debt_repayment')->where('id', $old['id'])->value('status'),
            'newStatus' => $new['status'] ?? null, 'debtAfter' => $debtAfter['repaid_debt'] ?? null,
            'role' => $fact['role_snapshot'] ?? null, 'amount' => $fact['amount_cents'] ?? null,
            'netCash' => $netCash, 'paymentFacts' => array_map(static fn(array $row): array => [
                'direction' => $row['fact_direction'], 'amount' => $row['amount_cents'],
                'orderId' => $row['order_id']], $paymentFacts), 'label' => $labels[$newId] ?? null,
        ], JSON_UNESCAPED_UNICODE));
    }
    $summary = ['oldNo' => (string)$old['repayment_no'], 'newNo' => $newNo, 'newId' => $newId,
        'salesperson' => $labels[$newId], 'debtBefore' => $debtBefore['repaid_debt'],
        'debtAfter' => $debtAfter['repaid_debt'], 'netCashCents' => $netCash,
        'mode' => $apply ? 'applied' : 'rolled_back'];
    if ($apply) Db::commit(); else Db::rollback();
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}

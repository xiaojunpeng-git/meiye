<?php
declare(strict_types=1);

/** Local-only rollback probe. Every refund, void, balance and right mutation is rolled back. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\user\UserBalanceAtomicServices;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('R33 probe is local ruihao only.');
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
    ->field('s.*,os.org_id')->find();
$operator = new CashierV3OperatorScope((int)$staff['store_id'], (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'], '0',
    (string)$staff['org_id'], [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r33-refund-void', ['cashier.v3.order_center', 'cashier.v3.order.refund',
        'cashier.v3.order.staff_adjust', 'cashier.v3.order.void'], $staff);
$dispatcher = CashierV3Bootstrap::dispatcher();
$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$newKey = static function (): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
        . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
};
$session = ['client_session_id' => 'SESSION-' . substr($newKey(), 4), 'operator_profile' => $staff];
$execute = static function (string $action, array $payload, string $key) use ($dispatcher, $operator, $scope, $session): array {
    return $dispatcher->gateway()->execute($action, ['idempotencyKey' => $key, 'contexts' => []],
        $payload, $operator, $session, $dispatcher->handlers()->requireCommand($action), $scope,
        CashierV3ActionManifest::requireAction($action));
};
$assert = static function (bool $okay, string $message): void {
    if (!$okay) throw new RuntimeException($message);
};
$catalogMember = 978855;
$catalog = (new CashierV3UnifiedRefundServices())->candidates([
    'mode' => 'catalog', 'memberId' => $catalogMember,
], $operator, $scope)['items'][0] ?? null;
$person = (new CashierV3RefundSalespersonServices())->selection([
    'mode' => 'catalog', 'memberId' => $catalogMember, 'catalogItemId' => (int)($catalog['skuId'] ?? 0),
], $operator, $scope)['salespeople'][0] ?? null;
$assert($catalog && $person, 'Catalog refund fixture unavailable.');

Db::startTrans();
try {
    $reports = new StoreUnifiedReportServices();
    $marketInput = ['report' => 'market_performance', 'start_date' => $date,
        'end_date' => $date, 'page' => 1, 'limit' => 100, '_internal_all' => true];
    $marketAmount = static function () use ($reports, $staff, $marketInput): int {
        $result = $reports->query([(int)$staff['store_id']], $marketInput);
        return array_sum(array_map(static fn(array $row): int => (int)($row['total_performance_cents'] ?? 0),
            $result['records']));
    };
    $marketBefore = $marketAmount();
    $refund = $execute('refund-card-or-line', [
        'mode' => 'catalog', 'memberId' => $catalogMember, 'catalogItemId' => (int)$catalog['skuId'],
        'holderId' => 0, 'componentId' => 0, 'lineId' => '', 'refundAmount' => '33',
        'balanceAmount' => '0', 'balanceKind' => 'bonus', 'voidTarget' => false,
        'validThrough' => 0, 'businessDate' => $date, 'reason' => 'R33本地作废回滚',
        'salespeople' => [['staffId' => (int)$person['staffId'], 'allocationWeight' => 100]],
    ], $newKey());
    $assert(($refund['status'] ?? '') === 'success', 'Catalog refund failed: ' . json_encode($refund, JSON_UNESCAPED_UNICODE));
    $id = (string)$refund['data']['unifiedRefund']['operationId'];
    $no = (string)$refund['data']['unifiedRefund']['operationNo'];
    $assert($marketAmount() === $marketBefore - 3300, 'Market performance missed the recorded refund.');
    $voidKey = $newKey();
    $voidPayload = ['recordId' => 'refund:' . $id, 'reason' => '误录完整撤销'];
    $void = $execute('void-order-center-refund', $voidPayload, $voidKey);
    $assert(($void['status'] ?? '') === 'success', 'Catalog void failed: ' . json_encode($void, JSON_UNESCAPED_UNICODE));
    $again = $execute('void-order-center-refund', $voidPayload, $voidKey);
    $assert(($again['status'] ?? '') === 'success' && !empty($again['replay']), 'Void replay failed.');
    try {
        $otherKey = $execute('void-order-center-refund', $voidPayload, $newKey());
        throw new RuntimeException('A second void command succeeded: ' . json_encode($otherKey, JSON_UNESCAPED_UNICODE));
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        $assert(str_contains($error->getMessage(), '已经作废'), 'Second command did not explain the existing void.');
    }
    $adjustment = (array)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->find();
    $assert((string)$adjustment['status'] === 'voided', 'Adjustment remains effective.');
    $payment = Db::name('cashier_v3_payment_fact')->where('order_id', $id)
        ->where('fact_type', 'manual_refund_adjustment')->select()->toArray();
    $assert(count($payment) === 2 && array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'], $payment)) === 0,
        'Refund cash did not net to zero.');
    $salary = Db::name('cashier_v3_performance_fact')->where('order_id', $id)
        ->where('performance_type', 'refund_sales_deduction')->select()->toArray();
    $assert(array_sum(array_map(static fn(array $row): int => (int)$row['amount_cents'], $salary)) === 0,
        'Refund salesperson salary did not net to zero.');
    $reader = new CashierV3OrderCenterRecordQueryServices();
    $normal = $reader->queryRecords(['recordType' => 'refund', 'keyword' => $no,
        'businessDateFrom' => $date, 'businessDateTo' => $date], $operator, $scope);
    $all = $reader->queryRecords(['recordType' => 'refund', 'keyword' => $no, 'dataScope' => 'all',
        'businessDateFrom' => $date, 'businessDateTo' => $date], $operator, $scope);
    $assert((int)($normal['total'] ?? -1) === 0,
        'Voided refund remains in normal list: ' . json_encode($normal, JSON_UNESCAPED_UNICODE));
    $assert(($all['records'][0]['refundStatus'] ?? '') === '已作废', 'Audit list does not show voided refund.');
    $assert($marketAmount() === $marketBefore, 'Voided refund still reduces market performance.');
    $salaryReport = (new StoreUnifiedReportPhaseSixServices())->query('phase_six_salary_detail',
        [(int)$staff['store_id']], ['start' => $date, 'end' => $date],
        ['document_no' => $no, '_internal_all' => true]);
    $assert(array_sum(array_map(static fn(array $row): int => (int)($row['cash_amount_cents'] ?? 0),
        $salaryReport['records'])) === 0, 'Voided refund still deducts salesperson salary.');
    echo "R33 catalog void and replay passed\n";
} finally {
    Db::rollback();
}

// The fixed local card fixture is exercised twice: a partial refund and a
// whole-card closure. Neither may leave changed rights or bonus balance.
$cardMember = 978855;
$holderId = 2074451;
$card = array_values(array_filter((new CashierV3UnifiedRefundServices())->candidates([
    'mode' => 'card', 'memberId' => $cardMember,
], $operator, $scope)['cards'], static fn(array $row): bool => (int)$row['holderId'] === $holderId))[0] ?? null;
$cardPerson = (new CashierV3RefundSalespersonServices())->selection([
    'mode' => 'card', 'memberId' => $cardMember, 'holderId' => $holderId,
], $operator, $scope)['salespeople'][0] ?? null;
$assert($card && $card['canRefund'] && $cardPerson, 'Card refund fixture unavailable.');
foreach ([false, true] as $closeCard) {
    Db::startTrans();
    try {
        $before = (new CashierV3UnifiedRefundServices())->refundReversalRightsSnapshotInTx($scope->tenantId(), $holderId);
        $bonusBefore = (int)Db::name('user')->where('uid', $cardMember)->value('give_money') * 100;
        $refund = $execute('refund-card-or-line', [
            'mode' => 'card', 'memberId' => $cardMember, 'holderId' => $holderId,
            'componentId' => (int)$card['components'][0]['id'], 'lineId' => '', 'refundAmount' => '450',
            'balanceAmount' => $closeCard ? '0' : '700', 'balanceKind' => 'bonus',
            'voidTarget' => $closeCard, 'validThrough' => 0, 'businessDate' => $date,
            'reason' => 'R33卡项作废回滚',
            'salespeople' => [['staffId' => (int)$cardPerson['staffId'], 'allocationWeight' => 100]],
        ], $newKey());
        $assert(($refund['status'] ?? '') === 'success', 'Card refund failed: ' . json_encode($refund, JSON_UNESCAPED_UNICODE));
        $id = (string)$refund['data']['unifiedRefund']['operationId'];
        $void = $execute('void-order-center-refund', ['recordId' => 'refund:' . $id,
            'reason' => '误录卡项退款完整撤销'], $newKey());
        $assert(($void['status'] ?? '') === 'success', 'Card void failed: ' . json_encode($void, JSON_UNESCAPED_UNICODE));
        $after = (new CashierV3UnifiedRefundServices())->refundReversalRightsSnapshotInTx($scope->tenantId(), $holderId);
        $assert($before['holder']['write_surplus_times'] === $after['holder']['write_surplus_times']
            && $before['holder']['is_del'] === $after['holder']['is_del']
            && $before['carts'][0]['cart_info'] === $after['carts'][0]['cart_info']
            && ($before['rule']['status'] ?? '') === ($after['rule']['status'] ?? ''),
            'Card rights did not return to their pre-refund state.');
        $bonusAfter = (int)Db::name('user')->where('uid', $cardMember)->value('give_money') * 100;
        $assert($bonusBefore === $bonusAfter, 'Bonus balance was not restored.');
        $ledger = (new StoreUnifiedReportServices())->query([(int)$staff['store_id']], [
            'report' => 'store_refund_ledger', 'start_date' => $date, 'end_date' => $date,
            'page' => 1, 'limit' => 100, '_internal_all' => true,
        ]);
        $assert(!in_array($id, array_column($ledger['records'], 'operation_id'), true),
            'Voided card refund remains in the store refund ledger.');
        echo $closeCard ? "R33 whole-card refund void passed\n" : "R33 partial card refund void passed\n";
    } finally {
        Db::rollback();
    }
}

foreach (['balance', 'rights', 'snapshot'] as $changedPart) {
    Db::startTrans();
    try {
        $refund = $execute('refund-card-or-line', [
            'mode' => 'card', 'memberId' => $cardMember, 'holderId' => $holderId,
            'componentId' => (int)$card['components'][0]['id'], 'lineId' => '', 'refundAmount' => '450',
            'balanceAmount' => $changedPart === 'balance' ? '700' : '0',
            'balanceKind' => 'bonus', 'voidTarget' => false, 'validThrough' => 0,
            'businessDate' => $date, 'reason' => 'R33拒绝原因回滚',
            'salespeople' => [['staffId' => (int)$cardPerson['staffId'], 'allocationWeight' => 100]],
        ], $newKey());
        $assert(($refund['status'] ?? '') === 'success', 'Guard refund failed.');
        $id = (string)$refund['data']['unifiedRefund']['operationId'];
        if ($changedPart === 'balance') {
            $adjustmentId = (int)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->value('id');
            // 后续收支允许作废；赠金和本金均可扣回，此拒绝用例应验证当前合计余额不足。
            $wallet = (array)Db::name('user')->where('uid', $cardMember)->field('give_money,ben_money')->find();
            (new UserBalanceAtomicServices())->deductBenGive($cardMember, (string)$wallet['ben_money'], (string)$wallet['give_money'],
                'manual_refund_void', $adjustmentId, 'R33模拟后续余额用尽',
                'cv3-r33-later-balance-' . bin2hex(random_bytes(12)));
        } else {
            if ($changedPart === 'rights') {
                Db::name('user_card_holder')->where('id', $holderId)->update([
                    'write_surplus_times' => Db::raw('write_surplus_times-1'),
                ]);
            } else {
                $old = (array)Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id', $id)->find();
                $request = json_decode((string)$old['request_json'], true);
                unset($request['_reversal']);
                Db::name('cashier_v3_order_lifecycle_operation')->where('operation_id', $id)->update([
                    'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        $failed = [];
        try {
            $failed = $execute('void-order-center-refund', [
                'recordId' => 'refund:' . $id, 'reason' => '应拒绝不安全恢复',
            ], $newKey());
            $message = (string)($failed['message'] ?? $failed['result']['message'] ?? '');
        } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
            $message = $error->getMessage();
        }
        $expected = $changedPart === 'balance' ? '余额充足后重试'
            : ($changedPart === 'rights' ? '卡项剩余次数或有效期' : '缺少退款前后的完整权益快照');
        $assert(($failed['status'] ?? '') !== 'success' && str_contains($message, $expected),
            'Unsafe ' . $changedPart . ' void did not report a specific cause: '
                . json_encode($failed, JSON_UNESCAPED_UNICODE));
        $assert((string)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->value('status') === 'effective'
            && (int)Db::name('cashier_v3_order_center_void_operation')->where('record_id', 'refund:' . $id)->count() === 0,
            'Rejected void left partial state.');
        echo 'R33 ' . $changedPart . " refusal reason passed\n";
    } finally {
        Db::rollback();
    }
}

$documentStaff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.store_id', 73)->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
    ->field('s.*,os.org_id')->find();
$assert((int)($documentStaff['id'] ?? 0) > 0, 'Document store fixture unavailable.');
$documentOperator = new CashierV3OperatorScope(73, (int)$documentStaff['id'], (string)$documentStaff['org_id'], '0');
$documentScope = new CashierV3DataScopeContext((int)$documentStaff['id'], (int)$documentStaff['id'], 73, '0',
    (string)$documentStaff['org_id'], [73], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r33-document-void', ['cashier.v3.order_center', 'cashier.v3.order.refund',
        'cashier.v3.order.staff_adjust', 'cashier.v3.order.void'], $documentStaff);
$documentSession = ['client_session_id' => 'SESSION-' . substr($newKey(), 4), 'operator_profile' => $documentStaff];
$documentMember = 1056888;
$lines = (new CashierV3UnifiedRefundServices())->candidates([
    'mode' => 'document', 'memberId' => $documentMember,
], $documentOperator, $documentScope)['lines'];
$line = array_values(array_filter($lines, static fn(array $row): bool =>
    (int)($row['remainingAmountCents'] ?? 0) >= 1000 && (string)($row['lineId'] ?? '') !== ''))[0] ?? null;
$documentPerson = (new CashierV3RefundSalespersonServices())->selection([
    'mode' => 'document', 'memberId' => $documentMember, 'lineId' => (string)($line['lineId'] ?? ''),
], $documentOperator, $documentScope)['salespeople'][0] ?? null;
$assert($line && $documentPerson, 'Document refund fixture unavailable.');
Db::startTrans();
try {
    $runDocument = static function (string $action, array $payload, string $key) use (
        $dispatcher, $documentOperator, $documentScope, $documentSession): array {
        return $dispatcher->gateway()->execute($action, ['idempotencyKey' => $key, 'contexts' => []],
            $payload, $documentOperator, $documentSession,
            $dispatcher->handlers()->requireCommand($action), $documentScope,
            CashierV3ActionManifest::requireAction($action));
    };
    $refund = $runDocument('refund-card-or-line', [
        'mode' => 'document', 'memberId' => $documentMember, 'holderId' => 0,
        'componentId' => 0, 'lineId' => (string)$line['lineId'], 'catalogItemId' => 0,
        'refundAmount' => '10', 'balanceAmount' => '0', 'balanceKind' => 'bonus',
        'voidTarget' => true, 'validThrough' => 0, 'businessDate' => $date,
        'reason' => 'R33单据作废回滚',
        'salespeople' => [['staffId' => (int)$documentPerson['staffId'], 'allocationWeight' => 100]],
    ], $newKey());
    $assert(($refund['status'] ?? '') === 'success', 'Document refund failed: ' . json_encode($refund, JSON_UNESCAPED_UNICODE));
    $id = (string)$refund['data']['unifiedRefund']['operationId'];
    $void = $runDocument('void-order-center-refund', [
        'recordId' => 'refund:' . $id, 'reason' => '误录单据退款撤销',
    ], $newKey());
    $assert(($void['status'] ?? '') === 'success', 'Document refund void failed: ' . json_encode($void, JSON_UNESCAPED_UNICODE));
    $assert((string)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->value('status') === 'voided',
        'Document refund adjustment remains effective.');
    echo "R33 document refund void passed\n";
} finally {
    Db::rollback();
}

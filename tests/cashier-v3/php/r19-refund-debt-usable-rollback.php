<?php
declare(strict_types=1);

/** Local-only card fixture: cancel its debt in one transaction and roll everything back. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('R19 debt refund probe is local-only.');
}
$holder = (array)Db::name('user_card_holder')->where('card_no', 'z007WZH')
    ->field('id,uid,oid')->find();
if ((int)($holder['id'] ?? 0) !== 2004366 || (int)($holder['uid'] ?? 0) !== 991702) {
    throw new RuntimeException('R19 local fixture changed; no data was modified.');
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
    ->field('s.*,os.org_id')->find();
$storeId = (int)$staff['store_id'];
$operator = new CashierV3OperatorScope($storeId, (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, '0',
    (string)$staff['org_id'], [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r19-refund-readonly', ['cashier.v3.order_center', 'cashier.v3.order.refund'], $staff);
$service = new CashierV3UnifiedRefundServices();
$readCard = static function () use ($service, $holder, $operator, $scope): array {
    $result = $service->candidates(['memberId' => (int)$holder['uid'], 'mode' => 'card',
        'keyword' => 'z007WZH'], $operator, $scope);
    foreach ($result['cards'] as $card) {
        if ((int)$card['holderId'] === (int)$holder['id']) return $card;
    }
    throw new RuntimeException('R19 local card is not in refund candidates.');
};
$pending = $readCard();
if ((int)$pending['remainingAmountCents'] !== 372400
    || (int)$pending['usableRemainingAmountCents'] !== 100000
    || (int)$pending['cashRefundRemainingCents'] !== 100000
    || $pending['canRefund']) {
    throw new RuntimeException('Pending debt projection is not 3724 gross / 1000 usable / blocked.');
}

// This imported card uses its original store_order -> store_debt link. The
// temporary audit and status transition remain inside the rollback transaction.
$debts = Db::name('store_debt')->where('order_id', (int)$holder['oid'])->select()->toArray();
$debt = (array)($debts[0] ?? []);
if (count($debts) !== 1 || (int)($debt['status'] ?? -1) !== 0
    || (int)$debt['uid'] !== (int)$holder['uid']
    || bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2) !== '2724.00') {
    throw new RuntimeException('R19 local debt fixture changed; no data was modified.');
}

Db::startTrans();
try {
    $now = time();
    $key = 'R19-ROLLBACK-' . bin2hex(random_bytes(8));
    try {
        $service->executeInTx([
            'operator_scope' => $operator, 'data_scope' => $scope,
            'idempotency_key' => 'CMD-' . bin2hex(random_bytes(16)),
            'payload' => ['mode' => 'card', 'memberId' => (int)$holder['uid'],
                'holderId' => (int)$holder['id'],
                'componentId' => (int)$pending['components'][0]['id'],
                'refundAmount' => '1', 'balanceAmount' => '0',
                'balanceKind' => 'bonus', 'voidTarget' => false,
                'reason' => 'R19 pending debt rollback probe'],
        ]);
        throw new RuntimeException('Refund was accepted while debt remained unpaid.');
    } catch (CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '先补交或取消欠款')) throw $error;
    }
    Db::name('cashier_v3_debt_cancellation')->insert([
        'cancellation_id' => 'DC-' . $key, 'debt_id' => (int)$debt['id'], 'tenant_id' => '0',
        'store_id' => (int)$debt['store_id'], 'member_id' => (int)$holder['uid'],
        'debt_no_snapshot' => (string)$debt['debt_no'],
        'source_order_no_snapshot' => (string)$debt['order_sn'],
        'original_debt_cents' => (int)bcmul((string)$debt['total_debt'], '100', 0),
        'repaid_debt_cents' => (int)bcmul((string)$debt['repaid_debt'], '100', 0),
        'cancelled_debt_cents' => 272400, 'reason_snapshot' => 'R19 rollback probe',
        'operator_id' => (int)$staff['id'], 'operator_name_snapshot' => 'R19 rollback probe',
        'store_name_snapshot' => 'R19 rollback probe', 'command_idempotency_key' => $key,
        'business_event_no' => $key, 'business_date' => date('Y-m-d', $now),
        'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
    ]);
    Db::name('store_debt')->where('id', (int)$debt['id'])->update(['status' => 3]);
    $cancelled = $readCard();
    if ((int)$cancelled['usableRemainingAmountCents'] !== 100000
        || (int)$cancelled['cashRefundRemainingCents'] !== 100000
        || !$cancelled['canRefund']) {
        throw new RuntimeException('Cancelled debt released unrefundable rights.');
    }
    try {
        $service->executeInTx([
            'operator_scope' => $operator, 'data_scope' => $scope,
            'idempotency_key' => 'CMD-' . bin2hex(random_bytes(16)),
            'payload' => ['mode' => 'card', 'memberId' => (int)$holder['uid'],
                'holderId' => (int)$holder['id'],
                'componentId' => (int)$cancelled['components'][0]['id'],
                'refundAmount' => '1001', 'balanceAmount' => '0',
                'balanceKind' => 'bonus', 'voidTarget' => false,
                'reason' => 'R19 over-cap rollback probe'],
        ]);
        throw new RuntimeException('Refund over 1000 was accepted after debt cancellation.');
    } catch (CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '超过实际剩余金额')) throw $error;
    }
    // Repayment is a different source state: the paid amount becomes usable,
    // whereas the cancelled amount never did. This projection-only fixture
    // transition does not create a payment fact and is always rolled back.
    Db::name('store_debt')->where('id', (int)$debt['id'])
        ->update(['status' => 1, 'repaid_debt' => (string)$debt['total_debt']]);
    $repaid = $readCard();
    if ((int)$repaid['usableRemainingAmountCents'] !== 372400
        || (int)$repaid['cashRefundRemainingCents'] !== 372400
        || !$repaid['canRefund']) {
        throw new RuntimeException('Repaid debt did not release the original unconsumed amount.');
    }
    echo "PASS R19 pending, cancelled and repaid debt projection/cap (rollback)\n";
} finally {
    Db::rollback();
}

<?php
declare(strict_types=1);

/** R56: local-only zero-price card candidate and refund checks; all writes roll back. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('R56 refund probe is local-only.');
}
$member = (array)Db::name('user')->where('real_name', '程建红')->where('is_del', 0)
    ->field('uid,belong_store_id')->find();
if ((int)($member['belong_store_id'] ?? 0) !== 108) throw new RuntimeException('R56 member fixture unavailable.');
$staff = (array)Db::name('system_store_staff')->where('store_id', 108)
    ->where('status', 1)->where('is_del', 0)->field('id,store_id')->find();
$orgId = (string)Db::name('organization_store')->where('store_id', 108)->value('org_id');
$operator = new CashierV3OperatorScope(108, (int)$staff['id'], $orgId, '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], 108, '0', $orgId,
    [108], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r56-rollback',
    ['cashier.v3.order_center', 'cashier.v3.order.refund'], $staff);
$service = new CashierV3UnifiedRefundServices();
$cards = $service->candidates(['memberId' => (int)$member['uid'], 'mode' => 'card'], $operator, $scope);
if ($cards['total'] !== 3 || count($cards['cards']) !== 3) throw new RuntimeException('One zero-price card hid the other candidates.');
$zero = array_values(array_filter($cards['cards'], static fn(array $card): bool =>
    (int)$card['holderId'] === 1829033))[0] ?? null;
$positive = array_values(array_filter($cards['cards'], static fn(array $card): bool =>
    (int)$card['holderId'] === 1829031))[0] ?? null;
if (!$zero || !$zero['canRefund'] || $zero['purchaseAmountCents'] !== 0
    || $zero['remainingTimes'] !== 3 || count($zero['components']) !== 1) {
    throw new RuntimeException('Zero-price card candidate is not selectable.');
}
if (!$positive || !$positive['canRefund']) throw new RuntimeException('Positive-price regression card unavailable.');

$dispatcher = CashierV3Bootstrap::dispatcher();
$salespeople = (new CashierV3RefundSalespersonServices())->selection([
    'mode' => 'card', 'memberId' => (int)$member['uid'], 'holderId' => 1829033,
], $operator, $scope)['salespeople'];
if ($salespeople === []) throw new RuntimeException('R56 salesperson fixture unavailable.');
$holderBefore = (array)Db::name('user_card_holder')->where('id', 1829033)
    ->field('is_del,write_surplus_times')->find();
$balanceBefore = (array)Db::name('user')->where('uid', (int)$member['uid'])
    ->field('ben_money,give_money,balance_version')->find();
Db::startTrans();
try {
    try {
        $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
            'payload' => ['mode' => 'document', 'memberId' => (int)$member['uid'],
                'voidTarget' => false, 'refundAmount' => '0', 'balanceAmount' => '0']]);
        throw new RuntimeException('Zero cash was accepted for document refunds.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '必须大于0')) throw $error;
    }
} finally {
    Db::rollback();
}
foreach ([['card' => $zero, 'amount' => '0', 'bonus' => '5', 'void' => false],
    ['card' => $zero, 'amount' => '0', 'bonus' => '0', 'void' => true],
    ['card' => $positive, 'amount' => '1', 'bonus' => '0', 'void' => false]] as $case) {
    $void = $case['void'];
    $target = $case['card'];
    $hex = bin2hex(random_bytes(16));
    $key = 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    $payload = ['mode' => 'card', 'memberId' => (int)$member['uid'], 'holderId' => (int)$target['holderId'],
        'componentId' => (int)$target['components'][0]['id'], 'lineId' => '',
        'refundAmount' => $case['amount'], 'balanceAmount' => $case['bonus'], 'balanceKind' => 'bonus',
        'voidTarget' => $void, 'validThrough' => 0, 'reason' => 'R56本地零元退款回滚验证',
        'salespeople' => $void ? [['staffId' => (int)$salespeople[0]['staffId'], 'allocationWeight' => 100]] : []];
    Db::startTrans();
    try {
        $response = $dispatcher->gateway()->execute('refund-card-or-line',
            ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
            ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
            $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
            CashierV3ActionManifest::requireAction('refund-card-or-line'));
        if (($response['status'] ?? '') !== 'success') {
            throw new RuntimeException('Zero refund failed: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }
        $operationId = (string)($response['data']['unifiedRefund']['operationId'] ?? '');
        $operation = (array)Db::name('cashier_v3_order_lifecycle_operation')
            ->where('operation_id', $operationId)->find();
        if (!$operation || (int)$operation['cash_refund_cents'] !== (int)$case['amount'] * 100) {
            throw new RuntimeException('Refund cash amount was not recorded correctly.');
        }
        $request = json_decode((string)$operation['request_json'], true);
        if (($request['_zeroPriceBonusGrantCents'] ?? 0) !== (int)$case['bonus'] * 100) {
            throw new RuntimeException('Zero-price bonus exception is not auditable.');
        }
        $balanceAfter = (array)Db::name('user')->where('uid', (int)$member['uid'])
            ->field('ben_money,give_money,balance_version')->find();
        if (bcsub((string)$balanceAfter['give_money'], (string)$balanceBefore['give_money'], 2) !== $case['bonus'] . '.00'
            || (string)$balanceAfter['ben_money'] !== (string)$balanceBefore['ben_money']) {
            throw new RuntimeException('Zero-price bonus credit changed the wrong balance.');
        }
        if ($case['amount'] === '0' && Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
            ->where('performance_type', 'refund_sales_deduction')->count() !== 0) {
            throw new RuntimeException('Zero refund created a salesperson deduction.');
        }
        if ($case['bonus'] === '5') {
            $records = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
                'recordType' => 'refund', 'keyword' => (string)$operation['operation_no'],
                'dateFrom' => date('Y-m-d'), 'dateTo' => date('Y-m-d'),
            ], $operator, $scope);
            if (count($records['records']) !== 1
                || !str_contains((string)$records['records'][0]['refundSummary'], '转入赠金 ¥5')) {
                throw new RuntimeException('Zero-cash bonus credit is missing from refund records.');
            }
        }
        $holderAfter = (array)Db::name('user_card_holder')->where('id', (int)$target['holderId'])
            ->field('is_del,write_surplus_times')->find();
        if ($case['amount'] === '0' && ((int)$holderAfter['is_del'] !== ($void ? 1 : (int)$holderBefore['is_del'])
            || (int)$holderAfter['write_surplus_times'] !== ($void ? 0 : (int)$holderBefore['write_surplus_times']))) {
            throw new RuntimeException('Zero refund changed rights outside the void choice.');
        }
        echo json_encode(['amount' => $case['amount'], 'bonus' => $case['bonus'], 'void' => $void, 'status' => $response['status'],
            'cashRefundCents' => (int)$operation['cash_refund_cents'],
            'holder' => $holderAfter], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } finally {
        Db::rollback();
    }
}
if ((array)Db::name('user_card_holder')->where('id', 1829033)
    ->field('is_del,write_surplus_times')->find() !== $holderBefore) {
    throw new RuntimeException('R56 rollback left a card-rights change.');
}
if ((array)Db::name('user')->where('uid', (int)$member['uid'])
    ->field('ben_money,give_money,balance_version')->find() !== $balanceBefore) {
    throw new RuntimeException('R56 rollback left a member-balance change.');
}

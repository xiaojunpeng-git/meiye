<?php
declare(strict_types=1);

/** Local-only refund regression: commands, rights, bonus, facts and fixtures always roll back. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use think\facade\Db;

(new think\App($backend . '/'))->initialize();
// ThinkPHP's default CLI renderer can exit zero on exceptions; CI must fail on every missing assertion.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('R46 is restricted to local Docker ruihao.');
}
$assertions = 0;
$assert = static function (bool $okay, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$okay) throw new RuntimeException($message);
};
$holder = (array)Db::name('user_card_holder')->where('card_no', 'z003VQS')->find();
$assert((int)($holder['id'] ?? 0) === 1976256 && (int)$holder['uid'] === 1030289,
    'The local reproduction card changed.');
$staff = [];
// Gateway permissions come from live position rules, not a supplied admin level or test scope.
$resolver = new CashierV3FeatureResolver();
foreach (Db::name('system_store_staff')->where('employee_id', '>', 0)
    ->where('status', 1)->where('is_del', 0)->order('id')->select()->toArray() as $candidate) {
    $features = $resolver->resolveGrantedFeatures($candidate);
    if (in_array('cashier.v3.order.refund', $features, true) && in_array('cashier.v3.order.void', $features, true)) {
        $staff = $candidate;
        break;
    }
}
$assert($staff !== [], 'No local operator is available.');
$orgId = (string)Db::name('organization_store')->where('store_id', (int)$staff['store_id'])->value('org_id');
$operator = new CashierV3OperatorScope((int)$staff['store_id'], (int)$staff['id'], $orgId, '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'],
    '0', $orgId, [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r46-independent-refund', ['cashier.v3.order_center', 'cashier.v3.order.refund',
        'cashier.v3.order.void'], $staff);
$newKey = static function (): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
        . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
};
$dispatcher = CashierV3Bootstrap::dispatcher();
$session = ['client_session_id' => 'SESSION-' . substr($newKey(), 4), 'operator_profile' => $staff];
$execute = static function (string $action, array $payload, string $key) use ($dispatcher, $operator, $scope, $session, $assert): array {
    $response = $dispatcher->gateway()->execute($action, ['idempotencyKey' => $key, 'contexts' => []],
        $payload, $operator, $session, $dispatcher->handlers()->requireCommand($action), $scope,
        CashierV3ActionManifest::requireAction($action));
    $assert(($response['status'] ?? '') === 'success', 'Command failed: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    return $response;
};
// Read the same locked-source projections used by the command, without changing any rights.
$snapshot = static function (string $mode, $id, int $memberId): array {
    $service = new CashierV3UnifiedRefundServices();
    $reader = new ReflectionMethod($service, $mode === 'card' ? 'cardSnapshot' : 'lineSnapshot');
    $reader->setAccessible(true);
    return $reader->invoke($service, $id, $memberId, '0', false);
};
$wallet = static function () use ($holder): array {
    return (array)Db::name('user')->where('uid', $holder['uid'])->field('ben_money,give_money,balance_version')->find();
};
$walletBefore = $wallet();
$before = $snapshot('card', (int)$holder['id'], (int)$holder['uid']);
$assert($before['canRefund'] && (int)$before['cashRefundRemainingCents'] === 960000
    && (int)$before['remainingTimes'] === 96, 'Expected the original 9600 yuan / 96 uses.');
$payload = ['mode' => 'card', 'memberId' => (int)$holder['uid'], 'holderId' => (int)$holder['id'],
    'componentId' => (int)$before['components'][0]['id'], 'lineId' => '', 'refundAmount' => '9600',
    'balanceAmount' => '9600', 'balanceKind' => 'bonus', 'voidTarget' => false,
    'validThrough' => 0, 'reason' => 'R46 local rollback regression', 'salespeople' => []];
$operations = [];

Db::startTrans();
try {
    $key = $newKey();
    $result = $execute('refund-card-or-line', $payload, $key);
    $refund = $result['data']['unifiedRefund'];
    $id = (string)$refund['operationId'];
    $operations[] = $id;
    $assert((int)$refund['refundAmountCents'] === 960000 && (int)$refund['balanceCreditCents'] === 960000,
        'The two independent amounts were not recorded separately.');
    $after = $snapshot('card', (int)$holder['id'], (int)$holder['uid']);
    $assert((int)$after['remainingAmountCents'] === 0 && (int)$after['remainingTimes'] === 0,
        'Rights were not reduced exactly once.');
    $balance = $wallet();
    $assert(bcsub((string)$balance['give_money'], (string)$walletBefore['give_money'], 2) === '9600.00'
        && $balance['ben_money'] === $walletBefore['ben_money'], 'Bonus credit changed the wrong wallet amount.');
    $assert((int)Db::name('cashier_v3_payment_fact')->where('order_id', $id)
        ->where('fact_type', 'manual_refund_adjustment')->sum('amount_cents') === -960000,
        'Refund performance included the bonus credit.');
    $replayed = $execute('refund-card-or-line', $payload, $key);
    $assert(!empty($replayed['replay']) && $wallet() === $balance,
        'An idempotent retry granted bonus twice.');
    $execute('void-order-center-refund', ['recordId' => 'refund:' . $id, 'reason' => 'R46 rollback reversal'], $newKey());
    $restored = $snapshot('card', (int)$holder['id'], (int)$holder['uid']);
    $assert($restored['remainingAmountCents'] === $before['remainingAmountCents']
        && $restored['remainingTimes'] === $before['remainingTimes']
        && $restored['cashRefundRemainingCents'] === $before['cashRefundRemainingCents'],
        'Refund reversal did not restore the original rights and cap.');
    $restoredWallet = $wallet();
    $assert($restoredWallet['give_money'] === $walletBefore['give_money']
        && $restoredWallet['ben_money'] === $walletBefore['ben_money'], 'Refund reversal did not reverse the bonus credit.');
} finally {
    Db::rollback();
}

// Previous bonus credits must not consume the cash ceiling of a later partial refund.
Db::startTrans();
try {
    foreach ([950000, 940000] as $expected) {
        $result = $execute('refund-card-or-line', array_replace($payload,
            ['refundAmount' => '100', 'balanceAmount' => '100']), $newKey());
        $operations[] = (string)$result['data']['unifiedRefund']['operationId'];
        $state = $snapshot('card', (int)$holder['id'], (int)$holder['uid']);
        $assert((int)$state['remainingAmountCents'] === $expected
            && (int)$state['cashRefundRemainingCents'] === $expected, 'A previous bonus credit was counted as another refund.');
    }
} finally {
    Db::rollback();
}

// Manual refund and bonus amounts are independent operator-entered facts. Only malformed
// non-integer values are rejected; rights reduction is capped separately in the transaction.
$reject = static function (array $input, string $message) use ($operator, $scope, $newKey, $assert): void {
    try {
        (new CashierV3UnifiedRefundServices())->executeInTx(['operator_scope' => $operator,
            'data_scope' => $scope, 'idempotency_key' => $newKey(), 'payload' => $input]);
    } catch (CashierV3CommandException $error) {
        $assert(strpos($error->getMessage(), $message) !== false, 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('An invalid refund bypassed the command guard.');
};
Db::startTrans();
try {
    $reject(array_replace($payload, ['refundAmount' => '1.50']), '整数元');
    $reject(array_replace($payload, ['balanceAmount' => '1.50']), '整数元');

    // Clone only a local schema fixture inside this transaction, avoiding reliance on mutable demo orders.
    $seedLine = (array)Db::name('cashier_v3_sales_order_line')->whereIn('item_type', ['project', 'product'])
        ->where('line_status', 'settled')->where('line_direction', 'forward')->find();
    $assert($seedLine !== [], 'No local sales-line schema fixture is available.');
    $seedOrder = (array)Db::name('cashier_v3_sales_order')->where('order_id', $seedLine['order_id'])->find();
    $suffix = bin2hex(random_bytes(10));
    unset($seedLine['id'], $seedOrder['id']);
    $seedOrder = array_replace($seedOrder, ['order_id' => 'R46-ORDER-' . $suffix,
        'order_no' => 'R46-' . $suffix, 'checkout_request_id' => 'R46-REQUEST-' . $suffix,
        'natural_key' => 'R46-ORDER-' . $suffix,
        'tenant_id' => '0', 'member_id' => (int)$holder['uid'], 'store_id' => $operator->storeId(),
        'order_status' => 'settled', 'order_direction' => 'forward']);
    $seedLine = array_replace($seedLine, ['order_line_id' => 'R46-LINE-' . $suffix,
        'natural_key' => 'R46-LINE-' . $suffix,
        'checkout_request_id' => $seedOrder['checkout_request_id'],
        'order_id' => $seedOrder['order_id'], 'tenant_id' => '0', 'member_id' => (int)$holder['uid'],
        'store_id' => $operator->storeId(), 'sale_amount_cents' => 100000, 'coupon_payment_cents' => 20000]);
    Db::name('cashier_v3_sales_order')->insert($seedOrder);
    Db::name('cashier_v3_sales_order_line')->insert($seedLine);
    Db::name('cashier_v3_order_balance_allocation')->insert(['tenant_id' => '0',
        'store_id' => $operator->storeId(), 'member_id' => (int)$holder['uid'],
        'request_id' => $seedOrder['checkout_request_id'], 'order_id' => $seedOrder['order_id'],
        'order_line_id' => $seedLine['order_line_id'], 'amount_cents' => 10000, 'created_at' => time()]);
    $doc = array_replace($payload, ['mode' => 'document', 'holderId' => 0, 'componentId' => 0,
        'lineId' => $seedLine['order_line_id'], 'refundAmount' => '200', 'balanceAmount' => '300']);
    $line = $snapshot('document', $seedLine['order_line_id'], (int)$holder['uid']);
    $assert((int)$line['remainingAmountCents'] === 80000 && (int)$line['cashRefundRemainingCents'] === 70000,
        'Document source snapshot changed before the refund.');
    $result = $execute('refund-card-or-line', $doc, $newKey());
    $operations[] = (string)$result['data']['unifiedRefund']['operationId'];
    $line = $snapshot('document', $seedLine['order_line_id'], (int)$holder['uid']);
    $assert((int)$line['remainingAmountCents'] === 60000 && (int)$line['cashRefundRemainingCents'] === 50000,
        'Sales-line reread deducted bonus as a second refund.');
    // The entered cash and bonus may exceed the remaining line value, but the line itself
    // must close at zero rather than becoming a negative entitlement.
    $result = $execute('refund-card-or-line', array_replace($doc,
        ['refundAmount' => '1000', 'balanceAmount' => '900']), $newKey());
    $operations[] = (string)$result['data']['unifiedRefund']['operationId'];
    $assert((int)$result['data']['unifiedRefund']['refundAmountCents'] === 100000
        && (int)$result['data']['unifiedRefund']['balanceCreditCents'] === 90000,
        'Over-remaining manual amounts were not recorded independently.');
    $line = $snapshot('document', $seedLine['order_line_id'], (int)$holder['uid']);
    $assert((int)$line['remainingAmountCents'] === 0 && (int)$line['cashRefundRemainingCents'] === 0,
        'Over-remaining manual refund created a negative document entitlement.');
} finally {
    Db::rollback();
}
$assert($wallet() === $walletBefore, 'A test wallet mutation escaped rollback.');
$assert((array)Db::name('user_card_holder')->where('id', $holder['id'])->find() === $holder,
    'A test rights mutation escaped rollback.');
$assert(Db::name('cashier_v3_order_lifecycle_operation')->whereIn('operation_id', $operations)->count() === 0,
    'A test refund escaped rollback.');
$assert(Db::name('cashier_v3_sales_order')->where('order_id', $seedOrder['order_id'])->count() === 0,
    'A test sales fixture escaped rollback.');
echo 'R46_INDEPENDENT_REFUND_BONUS=PASS assertions=' . $assertions . PHP_EOL;

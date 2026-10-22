<?php
declare(strict_types=1);

/** R25：真实本地命令链验证后续收支后的退款作废；所有变更均随外层事务回滚。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\user\UserBalanceAtomicServices;
use think\facade\Db;

(new think\App($backend . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('R25 only runs against local Docker ruihao.');
}
$checks = 0;
$assert = static function (bool $okay, string $message) use (&$checks): void {
    ++$checks;
    if (!$okay) throw new RuntimeException($message);
};
$newKey = static function (): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
        . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
};
// 使用已在 R46 验证过的本地卡夹具；不制造线上会员、支付或退款。
$holderId = 1976256;
$memberId = 1030289;
$holder = (array)Db::name('user_card_holder')->where('id', $holderId)->find();
$assert((int)($holder['uid'] ?? 0) === $memberId, 'Local card fixture changed.');
$resolver = new CashierV3FeatureResolver();
$staff = [];
foreach (Db::name('system_store_staff')->where('employee_id', '>', 0)
    ->where('status', 1)->where('is_del', 0)->order('id')->select()->toArray() as $candidate) {
    $features = $resolver->resolveGrantedFeatures($candidate);
    if (in_array('cashier.v3.order.refund', $features, true)
        && in_array('cashier.v3.order.void', $features, true)) {
        $staff = $candidate;
        break;
    }
}
$assert($staff !== [], 'No authorized local operator.');
$org = (string)Db::name('organization_store')->where('store_id', (int)$staff['store_id'])->value('org_id');
$operator = new CashierV3OperatorScope((int)$staff['store_id'], (int)$staff['id'], $org, '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'],
    '0', $org, [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r25-refund-current-bonus', ['cashier.v3.order_center', 'cashier.v3.order.refund',
        'cashier.v3.order.void'], $staff);
$dispatcher = CashierV3Bootstrap::dispatcher();
$session = ['client_session_id' => 'SESSION-' . substr($newKey(), 4), 'operator_profile' => $staff];
$execute = static function (string $action, array $payload, string $key) use ($dispatcher, $operator, $scope, $session): array {
    return $dispatcher->gateway()->execute($action, ['idempotencyKey' => $key, 'contexts' => []],
        $payload, $operator, $session, $dispatcher->handlers()->requireCommand($action), $scope,
        CashierV3ActionManifest::requireAction($action));
};
$wallet = static function () use ($memberId): array {
    return (array)Db::name('user')->where('uid', $memberId)
        ->field('now_money,ben_money,give_money,balance_version')->find();
};
$rights = static function () use ($scope, $holderId): array {
    return (new CashierV3UnifiedRefundServices())->refundReversalRightsSnapshotInTx($scope->tenantId(), $holderId);
};
$snapshot = new ReflectionMethod(CashierV3UnifiedRefundServices::class, 'cardSnapshot');
$snapshot->setAccessible(true);
$card = $snapshot->invoke(new CashierV3UnifiedRefundServices(), $holderId, $memberId, '0', false);
$assert(!empty($card['canRefund']) && (int)$card['usableRemainingAmountCents'] >= 152000, 'Card is not refundable.');
$payload = ['mode' => 'card', 'memberId' => $memberId, 'holderId' => $holderId,
    'componentId' => (int)$card['components'][0]['id'], 'lineId' => '', 'refundAmount' => '10',
    'balanceAmount' => '1520', 'balanceKind' => 'bonus', 'voidTarget' => false,
    'validThrough' => 0, 'reason' => 'R25本地退款作废回滚验证', 'salespeople' => []];
$originalWallet = $wallet();
$refundIds = [];

// 本轮不放宽金额口径：新退款仍只允许整元，不得靠取消版本限制写入小数金额。
Db::startTrans();
try {
    try {
        $decimal = $execute('refund-card-or-line', array_replace($payload, ['balanceAmount' => '1520.5']), $newKey());
        $assert(($decimal['status'] ?? '') !== 'success', 'Fractional refund credit was accepted.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        $assert(strpos($error->getMessage(), '整数') !== false, 'Fractional refund failed for another reason.');
    }
    $assert($wallet() === $originalWallet, 'Fractional refund changed wallet.');
} finally {
    Db::rollback();
}

// 用户明确允许本金补足赠金：分别验证单一资金来源及两者合计刚好够的边界。
foreach (['unchanged', 'principal_credit', 'gift_credit', 'gift_spent', 'spent_then_refilled',
    'principal_only', 'mixed', 'combined_exact', 'insufficient', 'late_failure'] as $scenario) {
    Db::startTrans();
    try {
        $principal = $scenario === 'insufficient' ? '0.00'
            : ($scenario === 'combined_exact' ? '1000.00' : ($scenario === 'principal_only' ? '1520.00' : '10000.00'));
        Db::name('user')->where('uid', $memberId)->update([
            'ben_money' => $principal, 'give_money' => '0.00', 'now_money' => $principal,
        ]);
        $beforeRights = $rights();
        $refund = $execute('refund-card-or-line', $payload, $newKey());
        $assert(($refund['status'] ?? '') === 'success', 'Refund failed: ' . json_encode($refund, JSON_UNESCAPED_UNICODE));
        $id = (string)$refund['data']['unifiedRefund']['operationId'];
        $refundIds[] = $id;
        $adjustmentId = (int)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->value('id');
        $originalFact = (array)Db::name('cashier_v3_balance_fact')->where('order_id', $id)->find();
        $atomic = new UserBalanceAtomicServices();
        if ($scenario === 'principal_credit') {
            $atomic->creditBenGive($memberId, '100.00', '0.00', 'manual_refund_credit', $adjustmentId,
                'R25后续本金入账', 'r25-later-' . $newKey());
        } elseif (in_array($scenario, ['gift_credit', 'gift_spent'], true)) {
            $atomic->creditBenGive($memberId, '0.00', '80.00', 'manual_refund_credit', $adjustmentId,
                'R25后续赠金入账', 'r25-later-' . $newKey());
            if ($scenario === 'gift_spent') {
                $atomic->deductBenGive($memberId, '0.00', '50.00', 'manual_refund_void', $adjustmentId,
                    'R25后续赠金支出', 'r25-later-' . $newKey());
            }
        } elseif ($scenario === 'spent_then_refilled') {
            $atomic->deductBenGive($memberId, '0.00', '1520.00', 'manual_refund_void', $adjustmentId,
                'R25赠金用尽', 'r25-later-' . $newKey());
            $atomic->creditBenGive($memberId, '0.00', '2000.00', 'manual_refund_credit', $adjustmentId,
                'R25重新入账', 'r25-later-' . $newKey());
        } elseif (in_array($scenario, ['principal_only', 'late_failure', 'combined_exact'], true)) {
            $atomic->deductBenGive($memberId, '0.00', $scenario === 'combined_exact' ? '1000.00' : '1520.00',
                'manual_refund_void', $adjustmentId, 'R25验证本金补足', 'r25-later-' . $newKey());
        } elseif (in_array($scenario, ['insufficient', 'mixed'], true)) {
            $atomic->deductBenGive($memberId, '0.00', '1.00', 'manual_refund_void', $adjustmentId,
                'R25赠金差一元', 'r25-later-' . $newKey());
        }
        $beforeVoid = $wallet();
        $rightsAfterRefund = $rights();
        $laterLedger = Db::name('user_money')->where('uid', $memberId)->order('id')->select()->toArray();
        $key = $newKey();
        $voidPayload = ['recordId' => 'refund:' . $id, 'reason' => 'R25撤销误录退款'];
        if ($scenario === 'late_failure') {
            // 制造后续冲销事实主键冲突，使余额扣回和权益恢复后失败，验证完整事务回滚。
            $operationId = 'OCV-' . strtoupper(substr(hash_hmac('sha256',
                $scope->tenantId() . '|refund:' . $id . '|' . $key,
                (string)config('cashier_v3.checkout_namespace_secret')), 0, 40));
            $collision = (array)Db::name('cashier_v3_payment_fact')->where('order_id', $id)->find();
            unset($collision['id']);
            $collision['fact_id'] = 'RVD-' . strtoupper(substr(hash('sha256',
                'cashier_v3_payment_fact|' . $collision['fact_id'] . '|' . $operationId), 0, 40));
            $collision['order_id'] = 'R25-FAILURE-' . bin2hex(random_bytes(5));
            $collision['natural_key'] = $collision['fact_id'];
            Db::name('cashier_v3_payment_fact')->insert($collision);
        }
        try {
            $void = $execute('void-order-center-refund', $voidPayload, $key);
            $errorText = (string)($void['message'] ?? '');
        } catch (Throwable $error) {
            $void = ['status' => 'failed'];
            $errorText = $error->getMessage();
        }
        if (in_array($scenario, ['insufficient', 'late_failure'], true)) {
            $assert(($void['status'] ?? '') !== 'success', 'Unsafe/faulted void succeeded.');
            if ($scenario === 'insufficient') {
                $assert(strpos($errorText, '当前本金为0元、赠金为1519元，合计1519元') !== false
                    && strpos($errorText, '需扣回1520元') !== false
                    && strpos($errorText, '余额充足后重试') !== false, 'Insufficient balance guidance is incomplete: ' . $errorText);
            } else {
                $assert(stripos($errorText, 'duplicate') !== false, 'Fault did not reach reversal insert: ' . $errorText);
            }
            $assert($wallet() === $beforeVoid && $rights() === $rightsAfterRefund, 'Failed void changed wallet or rights.');
            $assert(Db::name('user_money')->where('uid', $memberId)->order('id')->select()->toArray() === $laterLedger,
                'Failed void changed the financial ledger.');
            $assert((int)Db::name('cashier_v3_order_center_void_operation')->where('record_id', 'refund:' . $id)->count() === 0
                && (string)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->value('status') === 'effective'
                && (int)Db::name('cashier_v3_balance_fact')->where('order_id', $id)->count() === 1,
                'Failed void left partial operations/facts.');
        } else {
            $assert(($void['status'] ?? '') === 'success', 'Current sufficient balance was rejected: ' . $errorText);
            $after = $wallet();
            $bonusDebit = min(152000, (int)bcmul($beforeVoid['give_money'], '100', 0));
            $principalDebit = 152000 - $bonusDebit;
            $assert((int)bcmul(bcsub($beforeVoid['ben_money'], $after['ben_money'], 2), '100', 0) === $principalDebit
                && (int)bcmul(bcsub($beforeVoid['give_money'], $after['give_money'], 2), '100', 0) === $bonusDebit
                && bcsub($beforeVoid['now_money'], $after['now_money'], 2) === '1520.00'
                && (int)$after['balance_version'] === (int)$beforeVoid['balance_version'] + 1,
                'Void did not debit exactly 1520 using gift first and principal for the shortfall.');
            if ($scenario === 'gift_spent') {
                // 第二个真实连接尝试写同一会员，必须等整笔作废结束，不能穿透当前余额检查。
                $config = config('database.connections.mysql');
                $contender = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport']
                    . ';dbname=' . $config['database'], $config['username'], $config['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $contender->exec('SET SESSION innodb_lock_wait_timeout=1');
                $contender->beginTransaction();
                try {
                    $query = $contender->prepare('UPDATE eb_user SET give_money=give_money-1,now_money=now_money-1 WHERE uid=?');
                    $query->execute([$memberId]);
                    throw new RuntimeException('Concurrent debit bypassed the wallet lock.');
                } catch (PDOException $error) {
                    $assert((int)($error->errorInfo[1] ?? 0) === 1205, 'Concurrent debit failed for another reason.');
                } finally {
                    $contender->rollBack();
                }
            }
            $restored = $rights();
            $assert($restored['holder']['write_surplus_times'] === $beforeRights['holder']['write_surplus_times']
                && $restored['holder']['is_del'] === $beforeRights['holder']['is_del']
                && $restored['carts'] === $beforeRights['carts'], 'Void did not restore original card rights.');
            $facts = Db::name('cashier_v3_balance_fact')->where('order_id', $id)->order('id')->select()->toArray();
            $assert(count($facts) === 2 && $facts[0] === $originalFact
                && (string)$facts[1]['reversal_of'] === (string)$originalFact['fact_id']
                && (int)$facts[1]['principal_delta_cents'] === -$principalDebit
                && (int)$facts[1]['bonus_delta_cents'] === -$bonusDebit
                && (int)$facts[1]['account_version'] === (int)$after['balance_version']
                && (int)$facts[1]['bonus_after_cents'] === (int)bcmul($after['give_money'], '100', 0)
                && (int)$facts[1]['principal_after_cents'] === (int)bcmul($after['ben_money'], '100', 0),
                'Reversal facts used the historical balance or changed original facts.');
            $assert((int)Db::name('cashier_v3_payment_fact')->where('order_id', $id)->sum('amount_cents') === 0
                && (string)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $id)->value('status') === 'voided',
                'Successful void did not reverse the complete refund.');
            $currentLedger = Db::name('user_money')->where('uid', $memberId)->order('id')->select()->toArray();
            $assert(array_slice($currentLedger, 0, count($laterLedger)) === $laterLedger
                && count($currentLedger) === count($laterLedger) + 1, 'Later ledger history was overwritten.');
            $debitLedger = $currentLedger[count($currentLedger) - 1];
            $assert((int)bcmul((string)$debitLedger['ben_change_amount'], '100', 0) === -$principalDebit
                && (int)bcmul((string)$debitLedger['give_change_amount'], '100', 0) === -$bonusDebit
                && (string)$debitLedger['ben_money'] === (string)$after['ben_money']
                && (string)$debitLedger['give_money'] === (string)$after['give_money'],
                'Financial ledger split differs from the wallet and reversal fact.');
            $replay = $execute('void-order-center-refund', $voidPayload, $key);
            $assert(($replay['status'] ?? '') === 'success' && !empty($replay['replay']) && $wallet() === $after,
                'Same-key replay debited twice.');
            try {
                $second = $execute('void-order-center-refund', $voidPayload, $newKey());
                $assert(($second['status'] ?? '') !== 'success', 'Another key voided the refund twice.');
            } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
                $assert(strpos($error->getMessage(), '已经作废') !== false, 'Second void failed for the wrong reason.');
            }
        }
        echo 'R25 ' . $scenario . " PASS\n";
    } finally {
        Db::rollback();
    }
}
$assert($wallet() === $originalWallet, 'Rollback left wallet changes.');
$assert((int)Db::name('cashier_v3_refund_adjustment')->whereIn('operation_id', $refundIds)->count() === 0,
    'Rollback left refund fixtures.');
echo 'R25_CURRENT_BONUS_VOID=PASS checks=' . $checks . PHP_EOL;

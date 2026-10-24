<?php
declare(strict_types=1);

// Execute all three legacy undo writers on isolated, rolled-back fixture rows.
// No real wallet, customer rights, inventory or external channel is changed.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/'); $app->env->load('/var/www/html/.env'); $app->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1);
});
use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\card\CashierV3CardConversionGuard;
use app\services\order\StoreOrderWriteOffServices;
use app\services\order\BatchWriteoffServices;
use app\services\order\StoreOrderRollbackServices;
use app\services\order\StoreOrderTerminalOperationServices;
use app\services\order\StoreDebtServices;
use app\services\cashier\v3\checkout\provider\CashierV3LegacyDebtGuardWriterAdapter;
$config = Db::connect()->getConfig();
if (getenv('R53_LOCAL_ONLY') !== '1' || ($config['hostname'] ?? '') !== 'mysql'
    || ($config['database'] ?? '') !== 'ruihao') throw new RuntimeException('local guard');
$prefix = 'R53-LEGACY-' . bin2hex(random_bytes(5));
$clone = static function (string $table, array $changes): int {
    $row = (array)Db::name($table)->order('id')->find();
    if (!$row) throw new RuntimeException('fixture source missing: ' . $table);
    unset($row['id']);
    return (int)Db::name($table)->insertGetId(array_replace($row, $changes));
};
$checks = 0;
Db::startTrans();
try {
    $orderId = $clone('store_order', ['order_id' => $prefix, 'unique' => md5($prefix),
        'order_type' => 0, 'refund_status' => 0, 'terminal_action' => 0, 'is_del' => 0,
        'is_system_del' => 0, 'is_user_del' => 0, 'paid' => 1, 'store_id' => 8,
        'is_debt_repay' => 0, 'debt_repay_origin_order_id' => 0,
        'debt_amount' => '100.00', 'repaid_debt_amount' => '40.00']);
    $order = (array)Db::name('store_order')->where('id', $orderId)->find();
    $uid = (int)$order['uid'];
    $holderId = $clone('user_card_holder', ['oid' => $orderId, 'uid' => $uid,
        'card_no' => $prefix, 'is_del' => 1, 'write_surplus_times' => 0]);
    $cartId = $clone('store_order_cart_info', ['oid' => $orderId, 'uid' => $uid,
        'cart_id' => $prefix, 'is_writeoff' => 1, 'write_surplus_times' => 0]);
    $operationId = $clone('cashier_v3_card_operation', ['operation_id' => $prefix,
        'operation_no' => $prefix, 'command_idempotency_key' => $prefix, 'natural_key' => $prefix,
        'tenant_id' => '0', 'operation_type' => 'card_to_balance', 'operation_status' => 'completed',
        'source_card_holder_id' => $holderId, 'origin_order_id' => $orderId]);
    $writeoffId = (int)Db::name('store_order_writeoff')->insertGetId(['oid' => $orderId, 'uid' => $uid,
        'order_cart_id' => $cartId, 'status' => 0, 'batch_id' => 0, 'writeoff_num' => 1]);
    $subOrderId = $clone('store_order', ['order_id' => $prefix . '-SUB', 'unique' => md5($prefix . '-SUB'),
        'order_type' => 2, 'link_id' => $writeoffId, 'link_order' => $orderId, 'refund_status' => 0]);
    $read = static function () use ($orderId, $holderId, $cartId, $writeoffId, $subOrderId): array {
        return [Db::name('store_order')->where('id', $orderId)->find(),
            Db::name('user_card_holder')->where('id', $holderId)->find(),
            Db::name('store_order_cart_info')->where('id', $cartId)->find(),
            Db::name('store_order_writeoff')->where('id', $writeoffId)->find(),
            Db::name('store_order')->where('id', $subOrderId)->find()];
    };
    $before = $read();
    $orphanBatch = new ReflectionMethod(BatchWriteoffServices::class, 'cancelOrphanWriteoffRow');
    $orphanBatch->setAccessible(true);
    $orphanOrder = new ReflectionMethod(StoreOrderRollbackServices::class, 'cancelOrphanWriteoff');
    $orphanOrder->setAccessible(true);
    $calls = [
        static fn() => app()->make(StoreOrderWriteOffServices::class)->cancelWriteoff($subOrderId, 'R53 test'),
        static fn() => $orphanBatch->invoke(app()->make(BatchWriteoffServices::class), $before[3], 'R53 test'),
        static fn() => $orphanOrder->invoke(new StoreOrderRollbackServices(), $writeoffId, 'R53 test'),
        // This entry is upstream of refund leases and external channel calls.
        static fn() => app()->make(StoreOrderTerminalOperationServices::class)->lockOrderForTerminal($orderId, 1),
    ];
    foreach ($calls as $index => $call) {
        try { $call(); throw new RuntimeException('legacy undo bypassed conversion: ' . $index); }
        catch (CashierV3CommandException | \think\exception\ValidateException $expected) {
            if (strpos($expected->getMessage(), '转入赠金') === false) throw $expected;
        }
        if ($read() !== $before) throw new RuntimeException('legacy undo modified closed rights or source history');
        $checks++;
    }
    // An unrelated order remains outside the guard; existing undo paths are not globally disabled.
    CashierV3CardConversionGuard::assertLegacyOrderNotConverted($orderId + 100000000);
    $checks++;

    // Build the actual legacy DAO relation, not a numeric-ID coincidence or
    // an empty repayment hint that would return before reaching the guard.
    $makeRepayment = static function (int $originId, int $detailId, string $suffix) use ($clone, $prefix, $uid): array {
        $origin = (array)Db::name('store_order')->where('id', $originId)->find();
        Db::name('store_order_cart_info')->where('id', $detailId)
            ->update(['debt_amount' => '100.00', 'repaid_debt_amount' => '40.00']);
        $debtId = (int)Db::name('store_debt')->insertGetId([
            'debt_no' => $prefix . '-D-' . $suffix, 'order_id' => $originId, 'order_sn' => $origin['order_id'],
            'uid' => $uid, 'store_id' => $origin['store_id'], 'staff_id' => 0,
            'total_debt' => '100.00', 'repaid_debt' => '40.00', 'status' => 0,
            'remark' => $prefix, 'add_time' => time(), 'update_time' => time(),
        ]);
        $itemId = (int)Db::name('store_debt_item')->insertGetId([
            'debt_id' => $debtId, 'order_id' => $originId, 'cart_info_id' => $detailId,
            'product_id' => 1, 'product_type' => 6, 'product_name' => 'R53 isolated debt item',
            'cart_num' => 1, 'debt_amount' => '100.00', 'repaid_debt' => '40.00',
            'add_time' => time(), 'update_time' => time(),
        ]);
        $repayOrderId = $clone('store_order', [
            'order_id' => $prefix . '-RP-' . $suffix, 'unique' => md5($prefix . '-RP-' . $suffix),
            'uid' => $uid, 'store_id' => $origin['store_id'], 'order_type' => 0, 'link_id' => 0,
            'is_debt_repay' => 1, 'debt_repay_origin_order_id' => $originId, 'debt_repay_item_id' => $itemId,
            'paid' => 1, 'is_del' => 0, 'is_system_del' => 0, 'is_user_del' => 0,
            'refund_status' => 0, 'terminal_action' => 0, 'refund_price' => '10.00', 'pay_price' => '40.00',
        ]);
        $repayId = (int)Db::name('store_debt_repay')->insertGetId([
            'repay_no' => $prefix . '-R-' . $suffix, 'debt_id' => $debtId, 'debt_item_id' => $itemId,
            'order_id' => $originId, 'order_sn' => $origin['order_id'], 'repay_order_id' => $repayOrderId,
            'uid' => $uid, 'repay_amount' => '40.00', 'pay_type' => 'cash',
            'pay_store_id' => $origin['store_id'], 'debt_store_id' => $origin['store_id'],
            'staff_id' => 0, 'combination_info' => '[]', 'add_time' => time(),
        ]);
        // Initialize the technical guard before recording the unchanged-row
        // baseline, so first-time guard creation is not confused with reversal.
        (new CashierV3LegacyDebtGuardWriterAdapter())->lockDebtInTx($debtId);
        return compact('originId', 'detailId', 'debtId', 'itemId', 'repayOrderId', 'repayId');
    };
    $convertedRepay = $makeRepayment($orderId, $cartId, 'CONVERTED');
    $unrelatedOriginId = $clone('store_order', [
        'order_id' => $prefix . '-OTHER', 'unique' => md5($prefix . '-OTHER'),
        'uid' => $uid, 'store_id' => 8, 'order_type' => 0, 'link_id' => 0,
        'is_debt_repay' => 0, 'debt_repay_origin_order_id' => 0, 'paid' => 1,
        'is_del' => 0, 'is_system_del' => 0, 'is_user_del' => 0, 'refund_status' => 0, 'terminal_action' => 0,
        'debt_amount' => '100.00', 'repaid_debt_amount' => '40.00',
    ]);
    $unrelatedDetailId = $clone('store_order_cart_info', ['oid' => $unrelatedOriginId, 'uid' => $uid,
        'cart_id' => $prefix . '-OTHER', 'debt_amount' => '100.00', 'repaid_debt_amount' => '40.00']);
    $unrelatedRepay = $makeRepayment($unrelatedOriginId, $unrelatedDetailId, 'UNRELATED');
    $readRepayment = static function (array $fixture): array {
        return [
            'origin' => Db::name('store_order')->where('id', $fixture['originId'])->find(),
            'order' => Db::name('store_order')->where('id', $fixture['repayOrderId'])->find(),
            'detail' => Db::name('store_order_cart_info')->where('id', $fixture['detailId'])->find(),
            'debt' => Db::name('store_debt')->where('id', $fixture['debtId'])->find(),
            'item' => Db::name('store_debt_item')->where('id', $fixture['itemId'])->find(),
            'repay' => Db::name('store_debt_repay')->where('id', $fixture['repayId'])->find(),
            'ledgerLinks' => Db::name('store_debt_repay')->where('repay_order_id', $fixture['repayOrderId'])->order('id')->select()->toArray(),
            'holders' => Db::name('user_card_holder')->where('oid', $fixture['originId'])->order('id')->select()->toArray(),
            'terminal' => Db::name('store_order_terminal_operation')->whereIn('store_order_id', [$fixture['originId'], $fixture['repayOrderId']])->order('id')->select()->toArray(),
            'guard' => Db::name('cashier_v3_entitlement_debt_guard')->where('origin_order_id', $fixture['originId'])->order('id')->select()->toArray(),
            'mutations' => Db::name('cashier_v3_entitlement_debt_guard_mutation')->where('origin_order_id', $fixture['originId'])->order('id')->select()->toArray(),
            'status' => Db::name('store_order_status')->whereIn('oid', [$fixture['originId'], $fixture['repayOrderId']])->order('id')->select()->toArray(),
            'conversion' => Db::name('cashier_v3_card_operation')->where('origin_order_id', $fixture['originId'])->order('id')->select()->toArray(),
        ];
    };
    $convertedBefore = $readRepayment($convertedRepay);
    $unrelatedBefore = $readRepayment($unrelatedRepay);
    $rightsBefore = $read();
    $blockedCalls = [
        'repayment terminal refund' => static fn() => app()->make(StoreOrderTerminalOperationServices::class)
            ->lockOrderForTerminal($convertedRepay['repayOrderId'], 1),
        'repayment terminal void' => static fn() => app()->make(StoreOrderTerminalOperationServices::class)
            ->lockOrderForTerminal($convertedRepay['repayOrderId'], 2),
        'actual reverseRepayOnRefund' => static fn() => app()->make(StoreDebtServices::class)
            ->reverseRepayOnRefund($convertedRepay['repayOrderId'], 10.0),
    ];
    foreach ($blockedCalls as $label => $call) {
        try { $call(); throw new RuntimeException('converted repayment bypass: ' . $label); }
        catch (CashierV3CommandException | \think\exception\ValidateException $expected) {
            if (strpos($expected->getMessage(), '转入赠金') === false) throw $expected;
        }
        if ($readRepayment($convertedRepay) !== $convertedBefore || $read() !== $rightsBefore
            || $readRepayment($unrelatedRepay) !== $unrelatedBefore) {
            throw new RuntimeException('blocked repayment mutated associated rows: ' . $label);
        }
        echo 'PASS ' . $label . ': conversion blocked; associated rows unchanged' . "\n";
        $checks++;
    }
    // Older repayments can omit every explicit order flag. The immutable
    // repay -> debt -> origin relation must still protect the converted card.
    $explicitFields = [
        'is_debt_repay' => $convertedBefore['order']['is_debt_repay'],
        'debt_repay_origin_order_id' => $convertedBefore['order']['debt_repay_origin_order_id'],
        'debt_repay_item_id' => $convertedBefore['order']['debt_repay_item_id'],
    ];
    Db::name('store_order')->where('id', $convertedRepay['repayOrderId'])->update([
        'is_debt_repay' => 0, 'debt_repay_origin_order_id' => 0, 'debt_repay_item_id' => 0,
    ]);
    $ledgerOnlyBefore = $readRepayment($convertedRepay);
    foreach ([false, true] as $lock) {
        if (CashierV3CardConversionGuard::legacyOriginOrderId($convertedRepay['repayOrderId'], $lock) !== $orderId) {
            throw new RuntimeException('ledger-only origin resolution missed converted order');
        }
    }
    foreach ([1, 2] as $action) {
        try {
            app()->make(StoreOrderTerminalOperationServices::class)->lockOrderForTerminal($convertedRepay['repayOrderId'], $action);
            throw new RuntimeException('ledger-only converted repayment terminal bypass');
        } catch (\think\exception\ValidateException $expected) {
            if (strpos($expected->getMessage(), '转入赠金') === false) throw $expected;
        }
        if ($readRepayment($convertedRepay) !== $ledgerOnlyBefore || $readRepayment($unrelatedRepay) !== $unrelatedBefore
            || $read() !== $rightsBefore) throw new RuntimeException('ledger-only block mutated associated rows');
        echo 'PASS ledger-only terminal action=' . $action . ': conversion blocked; associated rows unchanged' . "\n";
        $checks++;
    }

    // Two distinct debt origins for one repayment document are ambiguous even
    // when the document has no explicit marker. Refuse before choosing either
    // asset; verify the conflict message rather than accepting another failure.
    $ambiguousLink = $ledgerOnlyBefore['repay'];
    unset($ambiguousLink['id']);
    $ambiguousLinkId = (int)Db::name('store_debt_repay')->insertGetId(array_replace($ambiguousLink, [
        'repay_no' => $prefix . '-AMBIGUOUS', 'debt_id' => $unrelatedRepay['debtId'],
        'debt_item_id' => $unrelatedRepay['itemId'], 'order_id' => $unrelatedOriginId,
        'order_sn' => $unrelatedBefore['origin']['order_id'],
    ]));
    $ambiguousBefore = $readRepayment($convertedRepay);
    $conflictCalls = [
        'unlocked resolver' => static fn() => CashierV3CardConversionGuard::legacyOriginOrderId($convertedRepay['repayOrderId']),
        'locked resolver' => static fn() => CashierV3CardConversionGuard::legacyOriginOrderId($convertedRepay['repayOrderId'], true),
        'terminal refund' => static fn() => app()->make(StoreOrderTerminalOperationServices::class)->lockOrderForTerminal($convertedRepay['repayOrderId'], 1),
        'terminal void' => static fn() => app()->make(StoreOrderTerminalOperationServices::class)->lockOrderForTerminal($convertedRepay['repayOrderId'], 2),
    ];
    foreach ($conflictCalls as $label => $call) {
        try { $call(); throw new RuntimeException('ambiguous ledger origin accepted: ' . $label); }
        catch (\think\exception\ValidateException $expected) {
            if (strpos($expected->getMessage(), '原欠款关联存在冲突') === false) throw $expected;
        }
        if ($readRepayment($convertedRepay) !== $ambiguousBefore || $readRepayment($unrelatedRepay) !== $unrelatedBefore
            || $read() !== $rightsBefore) throw new RuntimeException('ambiguous ledger block mutated associated rows');
        $checks++;
    }
    echo "PASS ambiguous ledger mapping: unlocked/locked resolver and terminal refund/void fail closed without mutation\n";
    // Restore only our transaction-local fixture before the original positive
    // control; no production relation is edited or repaired by this test.
    Db::name('store_debt_repay')->where('id', $ambiguousLinkId)->delete();
    Db::name('store_order')->where('id', $convertedRepay['repayOrderId'])->update($explicitFields);
    if ($readRepayment($convertedRepay) !== $convertedBefore) throw new RuntimeException('explicit fixture restoration mismatch');

    // Ledger-only resolution must not block an unrelated real repayment.
    $unrelatedExplicit = [
        'is_debt_repay' => $unrelatedBefore['order']['is_debt_repay'],
        'debt_repay_origin_order_id' => $unrelatedBefore['order']['debt_repay_origin_order_id'],
        'debt_repay_item_id' => $unrelatedBefore['order']['debt_repay_item_id'],
    ];
    Db::name('store_order')->where('id', $unrelatedRepay['repayOrderId'])->update([
        'is_debt_repay' => 0, 'debt_repay_origin_order_id' => 0, 'debt_repay_item_id' => 0,
    ]);
    $unrelatedLedgerBefore = $readRepayment($unrelatedRepay);
    foreach ([1, 2] as $action) {
        $locked = app()->make(StoreOrderTerminalOperationServices::class)->lockOrderForTerminal($unrelatedRepay['repayOrderId'], $action);
        if ((int)$locked['order']['id'] !== $unrelatedRepay['repayOrderId']
            || $readRepayment($unrelatedRepay) !== $unrelatedLedgerBefore
            || $readRepayment($convertedRepay) !== $convertedBefore || $read() !== $rightsBefore) {
            throw new RuntimeException('unrelated ledger-only terminal rejected or mutated associated rows');
        }
        $checks++;
    }
    Db::name('store_order')->where('id', $unrelatedRepay['repayOrderId'])->update($unrelatedExplicit);
    echo "PASS unrelated ledger-only terminal refund/void accepted without mutation\n";
    // A real unrelated order, not merely an absent ID, reaches both terminal
    // locks and the real DAO mutation. No payment channel or wallet is invoked.
    foreach ([1, 2] as $action) {
        $locked = app()->make(StoreOrderTerminalOperationServices::class)
            ->lockOrderForTerminal($unrelatedRepay['repayOrderId'], $action);
        if ((int)$locked['order']['id'] !== $unrelatedRepay['repayOrderId']
            || $readRepayment($unrelatedRepay) !== $unrelatedBefore) {
            throw new RuntimeException('unrelated terminal falsely blocked or mutated rows');
        }
        $checks++;
    }
    app()->make(StoreDebtServices::class)->reverseRepayOnRefund($unrelatedRepay['repayOrderId'], 10.0);
    $unrelatedAfter = $readRepayment($unrelatedRepay);
    foreach (['origin' => 'repaid_debt_amount', 'detail' => 'repaid_debt_amount',
        'debt' => 'repaid_debt', 'item' => 'repaid_debt'] as $part => $field) {
        if (bccomp((string)$unrelatedAfter[$part][$field], '30.00', 2) !== 0) {
            throw new RuntimeException('unrelated repayment failed real reversal: ' . $part);
        }
    }
    if ($unrelatedAfter['repay'] !== $unrelatedBefore['repay'] || $unrelatedAfter['terminal'] !== []
        || count($unrelatedAfter['mutations']) !== 1
        || (int)$unrelatedAfter['guard'][0]['current_version'] !== (int)$unrelatedBefore['guard'][0]['current_version'] + 1
        || $readRepayment($convertedRepay) !== $convertedBefore || $read() !== $rightsBefore) {
        throw new RuntimeException('unrelated reversal audit or converted-row isolation mismatch');
    }
    echo "PASS unrelated terminal refund/void and real repayment reversal: 40 -> 30; converted rows unchanged\n";
    $checks++;
} finally { Db::rollback(); }
if (Db::name('cashier_v3_card_operation')->where('operation_id', $prefix)->find()
    || Db::name('store_order')->where('order_id', $prefix)->find()) throw new RuntimeException('fixture rollback failed');
foreach ([$convertedRepay, $unrelatedRepay] as $fixture) {
    if (Db::name('store_order')->where('id', $fixture['repayOrderId'])->find()
        || Db::name('store_debt')->where('id', $fixture['debtId'])->find()
        || Db::name('store_debt_item')->where('id', $fixture['itemId'])->find()
        || Db::name('store_debt_repay')->where('id', $fixture['repayId'])->find()
        || Db::name('cashier_v3_entitlement_debt_guard')->where('origin_order_id', $fixture['originId'])->find()
        || Db::name('cashier_v3_entitlement_debt_guard_mutation')->where('origin_order_id', $fixture['originId'])->find()) {
        throw new RuntimeException('repayment fixtures or guard audit survived rollback');
    }
}
if (Db::name('store_order')->where('id', $unrelatedOriginId)->find()
    || Db::name('store_order')->where('id', $subOrderId)->find()
    || Db::name('store_order_cart_info')->whereIn('id', [$cartId, $unrelatedDetailId])->find()
    || Db::name('user_card_holder')->where('id', $holderId)->find()
    || Db::name('store_order_writeoff')->where('id', $writeoffId)->find()
    || Db::name('store_debt_repay')->where('id', $ambiguousLinkId)->find()) {
    throw new RuntimeException('origin/detail fixtures survived rollback');
}
echo "PASS R53 legacy undo and refund/void gates blocked without mutation; {$checks} checks; fixtures rolled back\n";

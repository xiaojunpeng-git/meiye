<?php
/**
 * Read-only rehearsal against the named 010 isolation database and unchanged deployed services.
 * The proposed split exists in PHP arrays only. No balance, order, configuration or code is changed.
 * Passing source validation is not an end-to-end void/refund success.
 */
$root = '/www/wwwroot/010.cc3798.com/';
require $root . 'vendor/autoload.php';
$app = new think\App($root);
$app->env->load($root . '.env');
$app->initialize();
$credentials = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW)['client'];
$config = config('database');
$config['connections']['mysql'] = array_replace($config['connections']['mysql'], [
    'database' => 'mohe_010_migration_test_refresh', 'hostname' => '127.0.0.1',
    'hostport' => '3307', 'username' => $credentials['user'] ?? 'root', 'password' => $credentials['password'],
]);
think\facade\Config::set($config, 'database');
if (think\facade\Db::query('SELECT DATABASE() db')[0]['db'] !== 'mohe_010_migration_test_refresh') {
    throw new RuntimeException('Wrong rehearsal database');
}
think\facade\Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
// Existing refund validation uses SELECT FOR UPDATE, which MySQL rejects in READ ONLY.
// Use a rolled-back transaction on the isolated database; no mutation API is called.
think\facade\Db::startTrans();
$results = [];
try {
    $void = app()->make(app\services\order\StoreOrderVoidServices::class);
    $refund = app()->make(app\services\order\StoreOrderRefundDomainServices::class);
    // Existing, standalone, integral-yuan wallet orders with unclosed source split.
    foreach ([7488, 7685, 7704, 7709, 7738] as $id) {
        $order = think\facade\Db::name('store_order')->where('id', $id)->find();
        if (!$order || $order['pay_type'] !== 'yue' || (int)$order['pid'] !== 0
            || bccomp($order['pay_price'], $order['yue_pay_price'], 2) !== 0) {
            throw new RuntimeException('Sample no longer matches verified payment shape');
        }
        $before = $order;
        $order['paid_ben_amount'] = '0.00';
        $order['paid_give_amount'] = $order['yue_pay_price'];
        $order['paid_balance_ready'] = 1;
        $row = ['orderId' => $id, 'walletAmount' => $order['yue_pay_price']];
        foreach (['voidSource' => function () use ($void, $order) {
            $void->assertSalesBalanceSnapshotClosed($order);
            return 'accepted';
        }, 'refundSplit' => function () use ($refund, $order) {
            return $refund->resolveAndValidateRefundMoney($order, [], false);
        }, 'refundWithCurrentBalanceGate' => function () use ($refund, $order) {
            return $refund->resolveAndValidateRefundMoney($order, [], true);
        }] as $name => $test) {
            try { $row[$name] = ['ok' => true, 'result' => $test()]; }
            catch (Throwable $error) { $row[$name] = ['ok' => false, 'error' => $error->getMessage()]; }
        }
        $after = think\facade\Db::name('store_order')->where('id', $id)->find();
        if ($before !== $after) { throw new RuntimeException('Read-only order changed'); }
        $row['databaseOrderUnchanged'] = true;
        $results[] = $row;
    }
    echo json_encode(['mode' => 'read-only preflight, not completed transactions', 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    think\facade\Db::rollback();
}

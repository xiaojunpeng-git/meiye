<?php
/**
 * Exercise unchanged deployed order-domain services against existing 010 rehearsal rows.
 * Only historical payment split is temporarily patched; every test rolls back.
 * Outbox delivery is held in the test process to prevent notifications/queue messages.
 * This is isolated domain testing, not browser or production end-to-end acceptance.
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
class HistoricalTestHeldOutbox extends app\services\order\terminal\RefundSideEffectOutboxServices
{
    public function flushPending(string $operationNo, ?string $stepType = null): int { return 0; }
}
$app->instance(app\services\order\terminal\RefundSideEffectOutboxServices::class, new HistoricalTestHeldOutbox());
$results = [];
$cases = [];
foreach ([7488, 7685, 7704, 7709, 7738] as $sampleId) {
    $cases[] = ['void', $sampleId];
    $cases[] = ['refund', $sampleId];
}
foreach ($cases as [$action, $id]) {
    $orderBefore = think\facade\Db::name('store_order')->where('id', $id)->find();
    $uid = (int)$orderBefore['uid'];
    $userBefore = think\facade\Db::name('user')->where('uid', $uid)->find();
    if ($orderBefore['pay_type'] !== 'yue' || (int)$orderBefore['pid'] !== 0
        || bccomp($orderBefore['pay_price'], $orderBefore['yue_pay_price'], 2) !== 0) {
        throw new RuntimeException('Unsafe payment shape: no external payment may enter this test');
    }
    $result = ['action' => $action, 'orderId' => $id];
    think\facade\Db::startTrans();
    try {
        think\facade\Db::name('store_order')->where('id', $id)->update([
            'paid_ben_amount' => '0.00', 'paid_give_amount' => $orderBefore['yue_pay_price'], 'paid_balance_ready' => 1,
        ]);
        $input = ['store_order_id' => $id, 'store_scope' => (int)$orderBefore['store_id'],
            'operator_type' => 'store', 'operator_id' => 25,
            'request_token' => '010-historical-rehearsal-' . $action . '-' . bin2hex(random_bytes(8)),
            'reason' => '010 isolated historical bonus rollback test'];
        $service = $action === 'void' ? app()->make(app\services\order\StoreOrderVoidServices::class)
            : app()->make(app\services\order\StoreOrderRefundDomainServices::class);
        $method = $action === 'void' ? 'voidWholeOrder' : 'refundWholeOrder';
        $result['operation'] = $service->$method($input);
        $after = think\facade\Db::name('user')->where('uid', $uid)->find();
        $result['principalDelta'] = bcsub($after['ben_money'], $userBefore['ben_money'], 2);
        $result['bonusDelta'] = bcsub($after['give_money'], $userBefore['give_money'], 2);
        if ($result['principalDelta'] !== '0.00' || bccomp($result['bonusDelta'], $orderBefore['yue_pay_price'], 2) !== 0) {
            throw new RuntimeException('Unexpected restored asset split');
        }
        try { $result['repeat'] = $service->$method($input); }
        catch (Throwable $error) { $result['repeatRejected'] = $error->getMessage(); }
        $repeated = think\facade\Db::name('user')->where('uid', $uid)->find();
        if ($after !== $repeated) { throw new RuntimeException('Repeat changed member assets'); }
        $result['domainPassed'] = true;
    } catch (Throwable $error) {
        $result['domainPassed'] = false;
        $result['error'] = $error->getMessage();
        $result['errorAt'] = $error->getFile() . ':' . $error->getLine();
    } finally {
        think\facade\Db::rollback();
    }
    $result['orderRestored'] = $orderBefore === think\facade\Db::name('store_order')->where('id', $id)->find();
    $result['memberRestored'] = $userBefore === think\facade\Db::name('user')->where('uid', $uid)->find();
    if (!$result['orderRestored'] || !$result['memberRestored']) { throw new RuntimeException('Rollback verification failed'); }
    $results[] = $result;
}
echo json_encode(['mode' => 'isolated rollback, outbox delivery held', 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

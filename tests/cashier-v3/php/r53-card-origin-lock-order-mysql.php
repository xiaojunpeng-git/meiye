<?php
declare(strict_types=1);

/** Two real PHP/MySQL connections exercise five production lock entrances; neither commits. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(fopen('php://stderr', 'wb'), 'R53_ORIGIN_LOCK_ORDER_FAILED ' . $error->getMessage() . PHP_EOL); exit(1);
});
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('local MySQL only');
$fixture = (array)Db::name('cashier_v3_card_operation')->alias('op')
    ->join('user_card_holder h', 'h.id=op.source_card_holder_id AND h.oid=op.origin_order_id')
    ->join('store_order o', 'o.id=op.origin_order_id')
    ->where('op.operation_type', 'project_replacement')->where('op.operation_status', 'succeeded')
    ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)
    ->where('o.refund_status', 0)->where('o.terminal_action', 0)->where('o.card_upgrade_use_oid', 0)
    ->field('op.id,op.origin_order_id,op.source_card_holder_id,op.store_id,op.operator_id,h.uid')->find();
if (!$fixture) throw new RuntimeException('committed replacement fixture missing');
$fixture['organization_id'] = (string)Db::name('organization_store')->where('store_id', $fixture['store_id'])->value('org_id');
$fixture['detail_id'] = (int)Db::name('store_order_cart_info')->where('oid', $fixture['origin_order_id'])
    ->where('cart_type', 2)->where('product_type', 6)->value('id');
if (!$fixture['detail_id']) throw new RuntimeException('committed benefit detail fixture missing');
$beforeOrder = Db::name('store_order')->where('id', $fixture['origin_order_id'])->find();
$beforeHolder = Db::name('user_card_holder')->where('id', $fixture['source_card_holder_id'])->find();
$beforeWallet = Db::name('user')->where('uid', $fixture['uid'])->find();
$beforeDetail = Db::name('store_order_cart_info')->where('id', $fixture['detail_id'])->find();
$childCode = <<<'PHP'
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(fopen('php://stderr','wb'),$e->getMessage()); exit(1); });
$f = json_decode($argv[1], true); $mode = $argv[2];
$operator = new app\services\cashier\v3\CashierV3OperatorScope((int)$f['store_id'], (int)$f['operator_id'], $f['organization_id'], '0');
// This direct-service lock test has no gateway grant assertion. The separate
// real Gateway suite covers authenticated staff/employee/job permissions.
$scope = new app\services\cashier\v3\CashierV3DataScopeContext((int)$f['operator_id'],0,(int)$f['store_id'],'0',
    $f['organization_id'],[(int)$f['store_id']],'stores',[],false,'','r53-lock',
    ['cashier.v3.cashier.card.to_balance'],[]);
$recorder = new app\services\cashier\v3\event\CashierV3BusinessEventRecorder();
think\facade\Db::execute('SET SESSION innodb_lock_wait_timeout=3');
think\facade\Db::startTrans(); $blocked = false;
try {
    echo "R53_LOCK_CHILD_READY\n"; flush();
    if ($mode === 'conversion') {
        (new app\services\cashier\v3\card\CashierV3CardToBalanceServices())->submitInTx([
            'operator_scope'=>$operator,'data_scope'=>$scope,'idempotency_key'=>'r53-lock-only',
            'payload'=>['operationType'=>'card_to_balance','memberId'=>(int)$f['uid'],
                'sourceCardHolderId'=>(int)$f['source_card_holder_id'],'confirmedAmountCents'=>100,'reason'=>'R53 lock only']]);
    } elseif ($mode === 'card_authority') {
        $class = new ReflectionClass(app\services\cashier\v3\card\CashierV3CardOperationAuthorityServices::class);
        $method = $class->getMethod('loadSourceCardForUpdate'); $method->setAccessible(true);
        $method->invoke($class->newInstanceWithoutConstructor(), (int)$f['source_card_holder_id'], $operator, 'card_disable', []);
    } elseif ($mode === 'gateway_provider' || $mode === 'gateway_benefit_pool') {
        $class = new ReflectionClass(app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider::class);
        $method = $class->getMethod('loadAuthority'); $method->setAccessible(true);
        $kind = $mode === 'gateway_provider' ? 'card_holder' : 'member_benefit_pool';
        $resourceId = $mode === 'gateway_provider' ? $f['source_card_holder_id'] : $f['detail_id'];
        $method->invoke($class->newInstanceWithoutConstructor(),$kind,(string)$resourceId,$operator,true);
    } else {
        $service = new app\services\cashier\v3\order\CashierV3OrderCenterVoidServices();
        $method = new ReflectionMethod($service,'voidProjectReplacement'); $method->setAccessible(true);
        $execution = $recorder->newExecution('void-order-center-project-replacement','r53-lock-only',$operator,$scope,'r53-lock');
        $method->invoke($service,'card-operation:'.$f['id'],'R53 lock only','r53-lock-only',$operator,$scope,$recorder,$execution,[]);
    }
} catch (Throwable $e) {
    $blocked = strpos($e->getMessage(),'1205') !== false;
    if (!$blocked) echo 'UNEXPECTED '.get_class($e).': '.$e->getMessage()."\n";
} finally { think\facade\Db::rollback(); }
echo $blocked ? "R53_LOCK_CHILD_ORIGIN_TIMEOUT\n" : "R53_LOCK_CHILD_FAILED\n";
exit($blocked ? 0 : 1);
PHP;
$checks = 0;
$oldTimeout = (int)Db::query('SELECT @@SESSION.innodb_lock_wait_timeout AS n')[0]['n'];
try {
    Db::execute('SET SESSION innodb_lock_wait_timeout=1');
    foreach (['conversion', 'card_authority', 'replacement_void', 'gateway_provider', 'gateway_benefit_pool'] as $mode) {
        Db::startTrans(); $process = null; $pipes = [];
        try {
            // Simulate the terminal writer owning origin before taking holder.
            Db::name('store_order')->where('id', $fixture['origin_order_id'])->lock(true)->find();
            $process = proc_open([PHP_BINARY, '-r', $childCode, json_encode($fixture), $mode],
                [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('cannot launch lock contender');
            fclose($pipes[0]); stream_set_timeout($pipes[1], 10);
            if (trim((string)fgets($pipes[1])) !== 'R53_LOCK_CHILD_READY') throw new RuntimeException('contender did not initialize');
            usleep(200000);
            // The old holder->origin path would hold this row and either cause
            // parent timeout or a deadlock victim (1213), failing this test.
            $holder = Db::name('user_card_holder')->where('id', $fixture['source_card_holder_id'])->lock(true)->find();
            $detail = Db::name('store_order_cart_info')->where('id', $fixture['detail_id'])->lock(true)->find();
            if (!$holder || !$detail) throw new RuntimeException('holder/detail missing during lock probe');
            $checks++; echo 'PASS ' . $mode . ' waits for origin without locking holder first' . PHP_EOL;
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
            $exit = proc_close($process); $process = null;
            if ($exit !== 0 || strpos($stdout, 'R53_LOCK_CHILD_ORIGIN_TIMEOUT') === false) {
                throw new RuntimeException($mode . ' expected origin lock timeout without deadlock: ' . $stdout . $stderr);
            }
            $checks++; echo 'PASS ' . $mode . ' real service contender exits1205 without deadlock or mutation' . PHP_EOL;
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            Db::rollback();
        }
    }
} finally { Db::execute('SET SESSION innodb_lock_wait_timeout=' . $oldTimeout); }
if (Db::name('store_order')->where('id', $fixture['origin_order_id'])->find() !== $beforeOrder
    || Db::name('user_card_holder')->where('id', $fixture['source_card_holder_id'])->find() !== $beforeHolder
    || Db::name('user')->where('uid', $fixture['uid'])->find() !== $beforeWallet
    || Db::name('store_order_cart_info')->where('id', $fixture['detail_id'])->find() !== $beforeDetail) throw new RuntimeException('lock probe changed business rows');
$checks++; echo "R53_ORIGIN_LOCK_ORDER_MYSQL_OK checks=$checks\n";

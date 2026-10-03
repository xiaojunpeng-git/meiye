<?php

declare(strict_types=1);

// Local MySQL only: execute real V3 supplement voids inside a transaction,
// inspect immutable reversals, and always roll back before reporting success.
error_reporting(E_ALL & ~E_DEPRECATED);
$database = trim((string)getenv('ROUND3_TEST_DATABASE'));
$password = (string)getenv('ROUND3_TEST_PASSWORD');
if ($database === '' || $password === '') {
    fwrite(STDERR, "Set ROUND3_TEST_DATABASE and ROUND3_TEST_PASSWORD for localhost:3307.\n");
    exit(2);
}
if (!function_exists('swoole_cpu_num')) {
    function swoole_cpu_num(): int { return 1; }
}
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}

$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
final class RoundThreeRollbackApp extends \think\App
{
    protected $initializers = [\think\initializer\RegisterService::class, \think\initializer\BootService::class];
}
$app = new RoundThreeRollbackApp($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => '127.0.0.1', 'DATABASE_HOSTNAME' => '127.0.0.1',
    'database.hostport' => '3307', 'DATABASE_HOSTPORT' => '3307',
    'database.database' => $database, 'DATABASE_DATABASE' => $database,
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => $password, 'DATABASE_PASSWORD' => $password,
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'round_three_rollback_local_mysql_no_dotenv');
$app->initialize();
\think\facade\Config::set(['default' => 'file'], 'cache');
if ((string)\think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database) {
    throw new RuntimeException('Local database identity mismatch.');
}

$writer = new \app\services\cashier\v3\order\CashierV3OrderCenterVoidServices();
$recorder = new \app\services\cashier\v3\event\CashierV3BusinessEventRecorder();
$contract = \app\services\cashier\v3\manifest\CashierV3ActionManifest::eventContractFor('void-order-center-supplement');
function normalRepaymentPaymentCents(string $repaymentId): int
{
    $query = \think\facade\Db::name('cashier_v3_payment_fact')->alias('payment')
        ->where('payment.tenant_id', '0')->where('payment.order_id', $repaymentId)
        ->where('payment.status', 'effective');
    $normal = new \app\services\report\StoreReportNormalDataScopeServices();
    $normal->excludeVoidedSalesOrderFacts($query, 'payment.tenant_id', 'payment.order_id');
    $normal->excludeVoidedRechargeOrderFacts($query, 'payment.tenant_id', 'payment.order_id');
    $normal->excludeVoidedSupplementPaymentFacts($query, 'payment.tenant_id', 'payment.store_id', 'payment.order_id');
    return (int)$query->sum('payment.amount_cents');
}
$attempts = ['sales' => 0, 'recharge' => 0];
$rolledBack = [];
foreach (['sales' => 'cashier_v3_debt_repayment', 'recharge' => 'cashier_v3_recharge_debt_repayment'] as $type => $table) {
    $candidates = \think\facade\Db::name($table)->where('tenant_id', '0')->where('status', 'succeeded')
        ->order('id', 'desc')->limit(20)->select()->toArray();
    $failures = [];
    foreach ($candidates as $candidate) {
        $repaymentId = (string)$candidate['repayment_id'];
        $storeId = (int)$candidate['store_id'];
        $orgId = (string)(\think\facade\Db::name('organization_store')->where('store_id', $storeId)->value('org_id') ?? '');
        if ($orgId === '') { $failures[] = 'store organization unavailable'; continue; }
        $operator = new \app\services\cashier\v3\CashierV3OperatorScope($storeId, 71, $orgId, '0');
        $scope = new \app\services\cashier\v3\CashierV3DataScopeContext(
            71, 701, $storeId, '0', $orgId, [$storeId],
            \app\services\cashier\v3\CashierV3DataScopeContext::MODE_ALL,
            [], true, 'test-super', 'permission-v2',
            ['cashier.v3.order_center', 'cashier.v3.order.supplement_void'],
            ['id' => 71, 'name' => '本地回滚测试']
        );
        $recordId = ($type === 'sales' ? 'v3-sales-supplement:' : 'v3-supplement:') . $repaymentId;
        $key = 'round3-rollback-' . bin2hex(random_bytes(8));
        $normalBefore = normalRepaymentPaymentCents($repaymentId);
        \think\facade\Db::startTrans();
        try {
            $result = $writer->executeInTx('void-order-center-supplement', [
                'operator_scope' => $operator, 'data_scope' => $scope,
                'event_recorder' => $recorder,
                'event_execution' => $recorder->newExecution('void-order-center-supplement', $key, $operator, $scope, 'round3-local'),
                'event_contract' => $contract, 'idempotency_key' => $key,
                'payload' => ['recordId' => $recordId, 'reason' => '本地事务回滚测试'],
            ]);
            if (($result['data']['void']['replayed'] ?? true) !== false) throw new RuntimeException('Void was replayed.');
            $sourceType = $type === 'sales' ? 'debt_repayment' : 'recharge_debt_repayment';
            $payment = \think\facade\Db::name('cashier_v3_payment_fact')
                ->where('tenant_id', '0')->where('order_id', $repaymentId)
                ->where('source_document_type', $sourceType)->where('status', 'effective');
            $forward = (int)(clone $payment)->where('fact_direction', 'forward')->sum('amount_cents');
            $reversed = (int)(clone $payment)->where('fact_direction', 'reversal')->sum('amount_cents');
            if ($forward <= 0 || $forward + $reversed !== 0) throw new RuntimeException('Payment reversals do not net to zero.');
            if ($normalBefore !== $forward || normalRepaymentPaymentCents($repaymentId) !== 0) {
                throw new RuntimeException('Normal report payment did not disappear after successful void.');
            }
            $performance = \think\facade\Db::name('cashier_v3_performance_fact')
                ->where('tenant_id', '0')->where('order_id', $repaymentId)
                ->where('source_document_type', $sourceType)->where('status', 'effective');
            if ((int)$performance->sum('amount_cents') !== 0) {
                throw new RuntimeException('Repayment performance reversals do not net to zero.');
            }
            if ($type === 'sales') {
                $allocationNet = (int)\think\facade\Db::name('cashier_v3_payment_sale_allocation_fact')->alias('allocation')
                    ->join('cashier_v3_payment_fact payment',
                        'payment.tenant_id=allocation.tenant_id AND payment.fact_id=allocation.payment_fact_id')
                    ->where('allocation.tenant_id', '0')->where('payment.order_id', $repaymentId)
                    ->where('allocation.status', 'effective')->sum('allocation.amount_cents');
                if ($allocationNet !== 0) throw new RuntimeException('Repayment sale allocations do not net to zero.');
            }
            if ($type === 'recharge') {
                $balance = \think\facade\Db::name('cashier_v3_balance_fact')->where('tenant_id', '0')
                    ->where('order_id', $repaymentId)->where('source_document_type', $sourceType);
                if ((int)(clone $balance)->where('fact_direction', 'reversal')->count() !== 1) {
                    throw new RuntimeException('Recharge balance reversal missing.');
                }
                $balanceRows = $balance->field('principal_delta_cents,bonus_delta_cents')->select()->toArray();
                $balanceNet = array_sum(array_map(static fn(array $row): int =>
                    (int)$row['principal_delta_cents'] + (int)$row['bonus_delta_cents'], $balanceRows));
                if ($balanceNet !== 0) throw new RuntimeException('Recharge balance deltas do not net to zero.');
            }
            $attempts[$type]++;
            $rolledBack[] = ['table' => $table, 'repayment_id' => $repaymentId, 'key' => $key];
            echo 'PASS local rollback void: ' . $type . ' ' . (string)$candidate['repayment_no'] . PHP_EOL;
            break;
        } catch (Throwable $error) {
            $failures[] = (string)$candidate['repayment_no'] . ': ' . $error->getMessage();
        } finally {
            \think\facade\Db::rollback();
        }
    }
    if ($attempts[$type] === 0) {
        fwrite(STDERR, 'No eligible local ' . $type . ' supplement void: ' . implode(' | ', $failures) . PHP_EOL);
        exit(1);
    }
}

foreach ($rolledBack as $sample) {
    if ((string)\think\facade\Db::name($sample['table'])->where('repayment_id', $sample['repayment_id'])->value('status') !== 'succeeded'
        || (int)\think\facade\Db::name('cashier_v3_order_center_void_operation')
            ->where('command_idempotency_key', $sample['key'])->count() !== 0) {
        throw new RuntimeException('Rollback left a voided repayment or operation behind.');
    }
}
echo 'PASS local rollback leaves no persisted supplement voids' . PHP_EOL;

<?php
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3RechargeDebtReversalServices;
use think\facade\Config;
use think\facade\Db;

$backend = '/var/www/html';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'recharge_debt_reversal_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$rechargeId = (int)(getenv('RECHARGE_DEBT_TEST_RECHARGE_ID') ?: 95);
$authority = (array)Db::name('cashier_v3_recharge_debt_authority')->where('recharge_id', $rechargeId)->find();
if (!$authority) throw new RuntimeException('RECHARGE_DEBT_TEST_AUTHORITY_MISSING');
$source = [
    'rechargeId' => $rechargeId, 'orderNo' => (string)$authority['recharge_order_no_snapshot'],
    'memberId' => (int)$authority['member_id'], 'storeId' => (int)$authority['store_id'],
];
$operator = new CashierV3OperatorScope((int)$source['storeId'], 1, '0', (string)$authority['tenant_id']);
$scope = new CashierV3DataScopeContext(1, 1, (int)$source['storeId'], (string)$authority['tenant_id'], '0', null, CashierV3DataScopeContext::MODE_ALL, [], true, 'integration', '1', [], []);
$service = new CashierV3RechargeDebtReversalServices();
$passed = 0; $failed = 0;
$ok = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}\n";
};

Db::startTrans();
try {
    $before = (array)Db::name('store_debt')->where('id', (int)$authority['debt_id'])->find();
    $prepared = $service->prepare($source, 'refund-recharge-order', $scope);
    $ok('refund does not prepare or alter recharge debt', empty($prepared['debt'])
        && (array)Db::name('store_debt')->where('id', (int)$authority['debt_id'])->find() === $before);
    Db::rollback();
} catch (Throwable $throwable) { Db::rollback(); throw $throwable; }

Db::startTrans();
try {
    $rejected = false;
    try { $service->prepare($source, 'void-recharge-order', $scope); }
    catch (CashierV3CommandException $exception) {
        $rejected = (string)($exception->getDetail()['reason'] ?? '') === 'recharge_void_debt_repayment_exists';
    }
    $ok('any repayment rejects recharge void with zero writes', $rejected
        && (int)Db::name('cashier_v3_order_lifecycle_debt_reversal')->where('debt_id', (int)$authority['debt_id'])->count() === 0);
    Db::rollback();
} catch (Throwable $throwable) { Db::rollback(); throw $throwable; }

Db::startTrans();
try {
    $debtId = (int)$authority['debt_id'];
    Db::name('cashier_v3_recharge_debt_repayment')->where('debt_id', $debtId)->delete();
    Db::name('store_debt')->where('id', $debtId)->update(['repaid_debt' => '0.00', 'status' => 0]);
    Db::name('store_debt_item')->where('debt_id', $debtId)->update(['repaid_debt' => '0.00']);
    $prepared = $service->prepare($source, 'void-recharge-order', $scope);
    $operationId = 'RLO-INTEGRATION-DEBT-VOID';
    $service->apply($source, 'void-recharge-order', $prepared, $operationId, 'integration-recharge-debt-void', $operator, $scope, time());
    $audit = (array)Db::name('cashier_v3_order_lifecycle_debt_reversal')->where('operation_id', $operationId)->find();
    $ok('never-repaid debt closes and appends exact cancellation audit',
        (int)Db::name('store_debt')->where('id', $debtId)->value('status') === 2
        && (int)($audit['cancelled_debt_cents'] ?? 0) === (int)$prepared['cancelledDebtCents']
        && (string)($audit['status'] ?? '') === 'cancelled');
    Db::rollback();
} catch (Throwable $throwable) { Db::rollback(); throw $throwable; }

$ok('all debt integration mutations rolled back',
    (int)Db::name('store_debt')->where('id', (int)$authority['debt_id'])->value('status') === 1
    && (int)Db::name('cashier_v3_recharge_debt_repayment')->where('debt_id', (int)$authority['debt_id'])->count() > 0);
echo "RECHARGE_DEBT_REVERSAL_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

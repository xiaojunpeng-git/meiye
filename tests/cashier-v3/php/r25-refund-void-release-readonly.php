<?php
declare(strict_types=1);

/** 逐实例只读核验：不提交退款、不作废真实记录、不修改会员余额。 */
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3RefundVoidServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use think\facade\Db;

$instance = $argv[1] ?? '';
$sites = ['rh' => ['rh.cc3798.com', 'ruihao'], '008' => ['008.cc3798.com', 'mohe_008']];
if (!isset($sites[$instance])) throw new InvalidArgumentException('Explicit instance required.');
[$domain, $database] = $sites[$instance];
$root = '/www/wwwroot/' . $domain;
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});
if (config('database.connections.mysql.database') !== $database) throw new LogicException('Wrong instance database.');
$out = ['instance' => $instance, 'database' => $database, 'mode' => 'read_only', 'checks' => []];
$check = static function (bool $okay, string $label) use (&$out): void {
    if (!$okay) throw new RuntimeException($label);
    $out['checks'][] = $label;
};
$method = new ReflectionMethod(CashierV3RefundVoidServices::class, 'creditVoidDebitSplit');
$method->setAccessible(true);
$service = new CashierV3RefundVoidServices();
foreach ([[0, 152000, 0, 152000], [152000, 0, 152000, 0], [100000, 52000, 100000, 52000]] as $case) {
    [$principal, $bonus, $expectedPrincipal, $expectedBonus] = $case;
    $split = $method->invoke($service, ['principalCents' => $principal,
        'giftCents' => $bonus, 'totalCents' => $principal + $bonus], 152000);
    $check($split === ['principal' => $expectedPrincipal, 'bonus' => $expectedBonus],
        'debit_split_' . $principal . '_' . $bonus);
}
try {
    $method->invoke($service, ['principalCents' => 0, 'giftCents' => 151900, 'totalCents' => 151900], 152000);
    throw new RuntimeException('Insufficient balance accepted.');
} catch (CashierV3CommandException $error) {
    $check(($error->getDetail()['reason'] ?? '') === 'refund_void_balance_insufficient', 'insufficient_total_rejected');
}
// 用真实运行实例的只读事务验证现有订单中心入口及账户结构，而非仅核对文件哈希。
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $check(!empty((new CashierV3MemberBalanceProvider())->readinessStatus()['ready']), 'member_balance_authority_ready');
    $staff = [];
    $resolver = new CashierV3FeatureResolver();
    foreach (Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)
        ->where('employee_id', '>', 0)->order('id')->select()->toArray() as $candidate) {
        if (in_array('cashier.v3.order_center', $resolver->resolveGrantedFeatures($candidate), true)) {
            $staff = $candidate;
            break;
        }
    }
    $check($staff !== [], 'authorized_order_center_operator_available');
    $store = (int)$staff['store_id'];
    $org = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
    $operator = new CashierV3OperatorScope($store, (int)$staff['id'], $org, '0');
    $scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $store, '0', $org,
        [$store], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r25-release-readonly',
        ['cashier.v3.order_center'], $staff);
    $rows = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'dataScope' => 'all', 'businessDateFrom' => '2026-10-01',
        'businessDateTo' => '2026-10-31', 'page' => 1, 'pageSize' => 20,
    ], $operator, $scope);
    $check(isset($rows['records'], $rows['total']) && is_array($rows['records']), 'existing_refund_list_readable');
    $out['refund_list_total'] = (int)$rows['total'];
    $check(Db::name('cashier_v3_debt_repayment_item_settlement')->count() >= 0, 'existing_debt_settlement_schema_ready');
} finally {
    Db::rollback();
}
$out['result'] = 'PASS';
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

<?php
declare(strict_types=1);

/** Local fact-reader fixtures only: every inserted member/fact is rolled back. */
$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
(new think\App($backend . '/'))->initialize();
if (getenv('R50_LOCAL_ONLY') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local rollback test only');
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', $e->getMessage() . "\n" . $e->getTraceAsString()); exit(1);
});
$tag = 'R50-RT-' . bin2hex(random_bytes(6)); $checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    $checks++; echo 'PASS ' . $message . "\n";
};
Db::startTrans();
try {
    $member = (int)Db::name('user')->insertGetId(['account' => $tag, 'nickname' => $tag,
        'real_name' => $tag, 'belong_store_id' => 23, 'status' => 1, 'is_del' => 0]);
    $reader = new CashierV3MemberDetailQueryServices();
    $method = new ReflectionMethod($reader, 'overview'); $method->setAccessible(true);
    $total = static function () use ($method, $reader, $member): string {
        return $method->invoke($reader, $member, '0')['totalRechargeAmount'];
    };
    $check($total() === '0.00', 'empty history returns zero');
    $sample = (array)Db::name('cashier_v3_balance_fact')->order('id')->find();
    if (!$sample) throw new RuntimeException('Local fact template missing');
    $sequence = 0;
    // Clone a real schema row, replacing all identities. This tests the real
    // aggregate, not a mock ORM or a second frontend arithmetic path.
    $add = static function (string $type, int $principal, int $bonus = 0, array $override = []) use ($sample, $tag, $member, &$sequence): void {
        $row = $sample; unset($row['id']); $id = $tag . '-' . (++$sequence);
        $row = array_replace($row, ['fact_id' => $id, 'natural_key' => $id,
            'command_idempotency_key' => $id, 'business_event_no' => $id,
            'tenant_id' => '0', 'member_id' => $member, 'member_name_snapshot' => $tag,
            'operator_name_snapshot' => $tag, 'store_id' => 23, 'status' => 'effective',
            'balance_change_type' => $type, 'principal_delta_cents' => $principal,
            'bonus_delta_cents' => $bonus, 'reversal_of' => '', 'fact_direction' => 'forward'], $override);
        Db::name('cashier_v3_balance_fact')->insert($row);
    };
    $add('recharge_credit', 100000, 20000);
    $check($total() === '1000.00', 'gift is excluded from recharge principal');
    $add('recharge_credit', 50000, 0, ['store_id' => 1, 'business_date' => '2025-01-01']);
    $check($total() === '1500.00', 'all stores and historical dates are included');
    $add('recharge_credit', 0, 80000);
    $check($total() === '1500.00', 'gift-only recharge contributes no principal');
    $add('recharge_debt_repayment_credit', 20000);
    $check($total() === '1700.00', 'paid recharge-debt principal is included once');
    $add('recharge_credit', -10000, 0, ['fact_direction' => 'reversal', 'reversal_of' => $tag . '-1']);
    $add('recharge_debt_repayment_credit', -5000, 0, ['fact_direction' => 'reversal', 'reversal_of' => $tag . '-4']);
    $check($total() === '1550.00', 'signed refund and void principal reversals reduce the total');
    $add('order_payment', -20000); $add('refund_credit', 30000);
    $check($total() === '1550.00', 'spending and sales refunds do not change recharge total');
    $add('recharge_credit', 99900, 0, ['status' => 'failed']);
    $check($total() === '1550.00', 'non-effective facts are excluded');
    $add('recharge_credit', 99900, 0, ['member_id' => $member + 1]);
    $add('recharge_credit', 99900, 0, ['tenant_id' => 'r50-other']);
    $check($total() === '1550.00', 'other member and tenant facts cannot leak into this total');
    $operator = new CashierV3OperatorScope(23, 1, '1', '0');
    $scope = new CashierV3DataScopeContext(1, 1, 23, '0', '1', [23],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', $tag, [], ['id' => 1]);
    $detail = $reader->read($member, $operator, $scope, ['tab' => 'profile']);
    $check($detail['summary']['totalRechargeAmount'] === '1550.00'
        && $detail['summary']['totalConsumptionAmount'] === '0.00', 'public detail returns separate authoritative recharge and sales totals');
    $again = $reader->read($member, $operator, $scope, ['tab' => 'balance']);
    $check($again['summary']['totalRechargeAmount'] === '1550.00', 'changing detail tab does not change lifetime aggregate');
    $none = new CashierV3DataScopeContext(1, 1, 23, '0', '1', [],
        CashierV3DataScopeContext::MODE_NONE, [], false, '', $tag, [], ['id' => 1]);
    $denied = false;
    try { $reader->read($member, $operator, $none, ['tab' => 'profile']); }
    catch (Throwable $e) { $denied = true; }
    $check($denied, 'unauthorized member detail remains denied');
} finally { Db::rollback(); }
$check(Db::name('user')->where('account', $tag)->count() === 0
    && Db::name('cashier_v3_balance_fact')->where('natural_key', 'like', $tag . '%')->count() === 0,
    'all synthetic rows rolled back');
echo 'R50_RECHARGE_TOTAL passed=' . $checks . "\n";

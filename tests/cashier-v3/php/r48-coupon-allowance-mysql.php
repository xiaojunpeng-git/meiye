<?php
declare(strict_types=1);

// Local-only transactional fixtures. DDL is idempotent; every business row rolls back.
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
use app\services\activity\coupon\StoreCouponAllowanceServices as Allowance;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\order\CashierV3SalesOrderReversalServices;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutCouponSettlementServices;
use think\facade\Db;

set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1);
});
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1);
});
$config = Db::connect()->getConfig();
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao'
    || getenv('R48_LOCAL_ONLY') !== '1') throw new RuntimeException('R48_LOCAL_DATABASE_GUARD');
foreach (explode(';', file_get_contents($backend . '/database/upgrades/2026-10-22-多次抵用券/01-schema.sql')) as $sql) {
    if (trim($sql)) Db::execute($sql);
}
$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . "\n"; $passed++;
};
$reject = static function (callable $call, string $name) use ($check): void {
    $caught = false;
    Db::startTrans();
    try { $call(); } catch (\app\services\cashier\v3\CashierV3CommandException | \mohe\exceptions\AdminException $e) { $caught = true; }
    finally { Db::rollback(); }
    $check($caught, $name);
};
$service = new Allowance();
$reject(static fn() => Allowance::wholeCents('88.50'), 'fractional yuan face rejected');
$reject(static fn() => Allowance::afterUse(100000, 8850), 'fractional yuan debit rejected');
$reject(static fn() => Allowance::afterUse(100000, 0), 'zero debit rejected');
$check(Allowance::wholeCents('1000.00') === 100000, 'whole yuan face is exact cents');
$scope = new CashierV3DataScopeContext(1, 1, 1, '0', '', [1], 'stores', [], false, '', 'r48', [], []);
$workspace = (new ReflectionClass(CashierV3CashierWorkspaceServices::class))->newInstanceWithoutConstructor();
$options = new ReflectionMethod($workspace, 'couponOptions'); $options->setAccessible(true);
$reversal = (new ReflectionClass(CashierV3SalesOrderReversalServices::class))->newInstanceWithoutConstructor();
$lockCoupons = new ReflectionMethod($reversal, 'lockConsumedCoupons'); $lockCoupons->setAccessible(true);
$restore = new ReflectionMethod($reversal, 'restoreCoupons'); $restore->setAccessible(true);
$now = time();
$tag = 'R48-' . bin2hex(random_bytes(6));
$member = 900004800;
Db::startTrans();
try {
    $issue = (int)Db::name('store_coupon_issue')->insertGetId(['title' => $tag, 'coupon_title' => $tag,
        'coupon_price' => '1000.00', 'use_min_price' => '0.00', 'coupon_type' => 3, 'type' => 0,
        'use_mode' => 0, 'category' => 1, 'applicable_type' => 1, 'coupon_issue_type' => 0,
        'status' => 1, 'is_del' => 0, 'add_time' => $now]);
    $id = (int)Db::name('store_coupon_user')->insertGetId(['cid' => $issue, 'uid' => $member,
        'coupon_title' => $tag, 'coupon_price' => '1000.00', 'use_min_price' => '0.00',
        'start_time' => $now - 10, 'end_time' => $now + 86400, 'use_time' => 0, 'status' => 0,
        'is_fail' => 0, 'add_time' => $now, 'type' => 'send']);
    $balance = static fn(): int => (int)Db::name(Allowance::ACCOUNT)->where('coupon_user_id', $id)->value('remaining_cents');
    $read = static fn(): array => $options->invoke($workspace, $member, 1,
        ['threshold' => 100000, 'base' => 100000, 'cap' => 100000], false, $tag, 'r48-line', $id);
    $check((int)$read()['available'][0]['remainingAmountCents'] === 100000, 'selector offers initial 1000 yuan');
    $check(!Db::name(Allowance::ACCOUNT)->where('coupon_user_id', $id)->find(), 'opening selector does not write an account');
    $lineTemplate = (array)Db::name('cashier_v3_sales_order_line')->where('line_direction', 'forward')->find();
    if (!$lineTemplate) throw new RuntimeException('local settled sale fixture missing');
    $settle = static function (string $suffix, int $amount, int $selectedId = 0) use ($scope, $id, $member, $tag, $now, $lineTemplate): array {
        $selectedId = $selectedId ?: $id;
        $order = $tag . '-' . $suffix;
        $lineId = $order . '-line';
        $plan = (new ReflectionClass(CashierV3SalesOrderPlanV1::class))->newInstanceWithoutConstructor();
        foreach (['header' => ['order_id' => $order, 'member_id' => $member, 'store_id' => 1,
            'checkout_request_id' => $order, 'tenant_id' => '0'], 'lines' => [[
            'checkout_line_id' => $lineId, 'order_line_id' => $lineId, 'item_id' => 1,
            'coupon_user_id' => $selectedId, 'coupon_name_snapshot' => $tag,
            'coupon_discount_cents' => 0, 'coupon_payment_cents' => $amount,
            'original_amount_cents' => 100000, 'sale_amount_cents' => 100000,
            'discount_amount_cents' => 0, 'debt_amount_cents' => 0,
        ]]] as $key => $value) {
            $property = new ReflectionProperty($plan, $key); $property->setAccessible(true); $property->setValue($plan, $value);
        }
        (new CashierV3CheckoutCouponSettlementServices())->consumeInTx($plan, ['orderId' => $order], $scope, $now);
        $row = $lineTemplate; unset($row['id']);
        $row = array_merge($row, ['tenant_id' => '0', 'order_id' => $order, 'order_line_id' => $lineId,
            'natural_key' => $lineId,
            'checkout_line_id' => $lineId, 'line_direction' => 'forward', 'line_status' => 'settled',
            'coupon_user_id' => $selectedId, 'coupon_name_snapshot' => $tag, 'coupon_discount_cents' => 0,
            'coupon_payment_cents' => $amount, 'sale_amount_cents' => 100000]);
        Db::name('cashier_v3_sales_order_line')->insert($row);
        return ['sourceId' => $order, 'memberId' => $member, 'storeId' => 1];
    };
    $first = $settle('88', 8800);
    $check($balance() === 91200, 'final coupon settlement deducts 88, leaves 912');
    $check((int)$read()['available'][0]['remainingAmountCents'] === 91200, 'partially used coupon remains selectable');
    // A second independent connection cannot update the claim while the first
    // checkout holds its transaction lock. No worker writes a committed fixture.
    $pdo = new PDO('mysql:host=mysql;port=' . ($config['hostport'] ?? 3306) . ';dbname=ruihao;charset=utf8mb4',
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    $locked = false;
    try { $pdo->exec('UPDATE eb_store_coupon_user SET status=1 WHERE id=' . $id); }
    catch (PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1205) throw $e; $locked = true; }
    $check($locked, 'second connection cannot bypass claim lock');
    $second = $settle('200', 20000);
    $check($balance() === 71200, 'second order deducts 200, leaves 712');
    $reject(static fn() => $settle('88', 8800), 'duplicate event cannot spend twice');
    $reject(static fn() => $settle('fraction', 50), 'checkout settlement rejects fractional yuan');
    $reject(static fn() => $settle('overspend', 71300), 'checkout rejects overspend');
    $check($balance() === 71200, 'failed checkouts roll back allowance and facts');
    $void = static function (array $source) use ($lockCoupons, $restore, $reversal, $scope, $tag, $now): void {
        $coupons = $lockCoupons->invoke($reversal, $source, $scope);
        $restore->invoke($reversal, $coupons, $source, $tag . '-void', $now + 1);
    };
    $void($first);
    $check($balance() === 80000, 'void earlier 88 order leaves later 200 redemption intact');
    $reject(static fn() => $void($first), 'same debit cannot be restored twice');
    $void($second);
    $check($balance() === 100000, 'void second order restores original 1000 allowance');
    $check((int)Db::name(Allowance::MOVEMENT)->where('coupon_user_id', $id)->count() === 4, 'two debits and two immutable reversals');
    $check((int)Db::name(Allowance::MOVEMENT)->where('coupon_user_id', $id)->sum('delta_cents') === 0, 'ledger conserves exact total');
    $full = $settle('full', 100000);
    $check($balance() === 0 && (int)Db::name('store_coupon_user')->where('id', $id)->value('status') === 1,
        'exhausted allowance is used and disappears from available choices');
    $check($read()['available'] === [], 'zero remaining coupon is not selectable');
    $reject(static fn() => $settle('after-full', 100), 'exhausted coupon cannot overspend');
    $void($full);
    $check($balance() === 100000 && count($read()['available']) === 1, 'void exhausted coupon restores usability');
    $identity = ['tenant_id' => '0', 'store_id' => 1, 'member_id' => $member + 1,
        'order_id' => $tag . '-wrong', 'order_line_id' => $tag . '-wrong-line'];
    $reject(static fn() => $service->consumeInTx($id, 100, $identity, $now), 'foreign member denied');
    $identity['member_id'] = $member;
    $reject(static fn() => $service->consumeInTx($id, 100, $identity, $now + 90000), 'expired coupon denied');
    Db::name('store_coupon_issue')->where('id', $issue)->update(['applicable_type' => 2, 'applicable_store_id' => '999999']);
    $reject(static fn() => $service->consumeInTx($id, 100, $identity, $now), 'foreign store denied');
    $check((string)Db::name('store_coupon_user')->where('id', $id)->value('coupon_price') === '1000.00', 'original face never overwritten');
    $issueRow = (array)Db::name('store_coupon_issue')->where('id', $issue)->find(); unset($issueRow['id']);
    $issueRow['coupon_type'] = 1; $issueRow['applicable_type'] = 1;
    $ordinaryIssue = (int)Db::name('store_coupon_issue')->insertGetId($issueRow);
    $claim = (array)Db::name('store_coupon_user')->where('id', $id)->find(); unset($claim['id']);
    $claim['cid'] = $ordinaryIssue; $claim['use_time'] = 0; $claim['status'] = 0;
    $ordinaryId = (int)Db::name('store_coupon_user')->insertGetId($claim);
    $ordinaryOrder = $settle('ordinary', 100000, $ordinaryId);
    $check((int)Db::name('store_coupon_user')->where('id', $ordinaryId)->value('status') === 1, 'ordinary coupon remains one-use');
    $void($ordinaryOrder);
    $check((int)Db::name('store_coupon_user')->where('id', $ordinaryId)->value('use_time') === 0,
        'ordinary coupon void restores legacy unused state');
    $check(!Db::name(Allowance::ACCOUNT)->where('coupon_user_id', $ordinaryId)->find(), 'ordinary coupon has no allowance account');
    $dao = app()->make(\app\dao\activity\coupon\StoreCouponUserDao::class);
    $check($dao->useCoupon($id, $member) === 0, 'legacy DAO cannot consume reusable allowance');
    $issuer = app()->make(\app\services\activity\coupon\StoreCouponIssueServices::class);
    $rule = $issueRow;
    $rule['title'] = $rule['coupon_title'] = $tag . '-rule';
    $rule['coupon_type'] = 3; $rule['allow_transfer'] = 1; $rule['product_id'] = '';
    $issuer->saveCoupon($rule, 0, 0);
    $savedRule = (array)Db::name('store_coupon_issue')->where('coupon_title', $rule['coupon_title'])->find();
    $check((int)$savedRule['coupon_type'] === 3 && (int)$savedRule['allow_transfer'] === 0,
        'shared issuer creates reusable rule without transfer');
    $badRule = $rule; $badRule['coupon_price'] = '88.50';
    $reject(static fn() => $issuer->saveCoupon($badRule, 0, 0), 'shared issuer rejects fractional face');
    $badRule['coupon_price'] = '2000.00';
    $reject(static fn() => $issuer->saveCoupon($badRule, (int)$savedRule['id'], 0), 'issued face is immutable across editors');
} finally { Db::rollback(); }
echo 'R48_ALLOWANCE_MYSQL passed=' . $passed . "\n";

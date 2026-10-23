<?php
declare(strict_types=1);

/** Local-only formal service test. Rollback by default; explicit persist mode retains a synthetic receipt and its void. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices as Balance;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;

$app = new think\App('/var/www/html/'); $app->initialize();
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage() . ($e instanceof CashierV3CommandException ? ' ' . json_encode($e->getDetail()) : '') . "\n" . $e->getTraceAsString() . "\n"); exit(1);
});
if (getenv('R47_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT DATABASE() db')[0]['db'] !== 'ruihao') throw new RuntimeException('Local test guard failed');
date_default_timezone_set('Asia/Shanghai');
$checks = 0;
function check47(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; }
function tx47(callable $callback) { Db::startTrans(); try { $result = $callback(); Db::commit(); return $result; } catch (Throwable $e) { Db::rollback(); throw $e; } }

// Allocation checks cover stable ties, integer increments, zero tender and last-yuan conservation.
check47(Balance::allocate(100, [11 => 1, 12 => 1, 13 => 1]) === [11 => 100, 12 => 0, 13 => 0], 'stable whole-yuan remainder');
check47(Balance::allocate(1000, [11 => 3, 12 => 7]) === [11 => 300, 12 => 700], 'whole-yuan total conserved');
check47(Balance::allocate(0, [11 => 3, 12 => 7]) === [11 => 0, 12 => 0], 'zero cash share');
try { Balance::allocate(101, [1 => 1]); throw new RuntimeException('fraction accepted'); }
catch (InvalidArgumentException $e) { check47(true, 'fraction rejected'); }
check47(array_sum(Balance::allocate(999999999900, [1 => 999999999900, 2 => 999999999800])) === 999999999900, 'large weights remain exact');
$parser = new ReflectionMethod(CashierV3DebtRepaymentServices::class, 'paymentLinesFromCheckoutSnapshot'); $parser->setAccessible(true);
$parserService = new CashierV3DebtRepaymentServices();
$oneBalance = ['businessType' => 'debt_repayment', 'memberId' => 1, 'sourceDocument' => ['type' => 'debt_repayment', 'id' => '1'],
    'lines' => [['lineRole' => 'balance_payment', 'amount' => 1]]];
check47($parser->invoke($parserService, $oneBalance, 100, 1, 1)[0]['paymentMethod'] === Balance::METHOD, 'dedicated balance tender parsed');
$invalidSnapshots = [];
foreach ([true, 0, -1, '1.01', '1e2', []] as $value) { $bad = $oneBalance; $bad['lines'][0]['amount'] = $value; $invalidSnapshots[] = [$bad, true]; }
$bad = $oneBalance; $bad['lines'][] = $bad['lines'][0]; $invalidSnapshots[] = [$bad, true];
$bad = $oneBalance; $bad['rechargeDebt'] = true; $invalidSnapshots[] = [$bad, true];
$invalidSnapshots[] = [$oneBalance, false];
$bad = $oneBalance; $bad['memberId'] = 2; $invalidSnapshots[] = [$bad, true];
$bad = $oneBalance; $bad['lines'][0] = ['lineRole' => 'payment', 'method' => Balance::METHOD, 'amount' => 1]; $invalidSnapshots[] = [$bad, true];
foreach ($invalidSnapshots as $index => [$bad, $allow]) {
    $rejected = false;
    try { $parser->invoke($parserService, $bad, 100, 1, 1, $allow); } catch (CashierV3CommandException $e) { $rejected = true; }
    check47($rejected, 'invalid/recharge/duplicate/foreign tender rejected ' . $index);
}

$persist = getenv('R47_PERSIST') === '1';
$seedOnly = getenv('R47_SEED_ONLY') === '1';
if ($seedOnly && !$persist) throw new RuntimeException('Seed-only requires explicit persistence');
$modes = $persist ? ['mixed' => 1000] : ['mixed' => 1000, 'balance_only' => 1380, 'cash_only' => 0];
foreach ($modes as $mode => $balanceYuan) {
    $tag = 'R47-' . $mode . '-' . bin2hex(random_bytes(5));
    $templateQuery = Db::name('store_debt')->alias('d')->join('cashier_v3_debt_authority a', 'a.debt_id=d.id')
        ->where('d.status', 0)->where('a.sales_order_record_id', 0);
    if ((int)getenv('R47_STORE_ID') > 0) $templateQuery->where('d.store_id', (int)getenv('R47_STORE_ID'));
    $template = (array)$templateQuery->field('d.*')->find();
    if (!$template) throw new RuntimeException('No local migration fixture');
    $store = (int)$template['store_id'];
    $staff = (array)Db::name('system_store_staff')->where('store_id', $store)->where('status', 1)->where('is_del', 0)->find();
    $org = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
    $operator = new CashierV3OperatorScope($store, (int)$staff['id'], $org, '0');
    $scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $store, '0', $org, [$store],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', $tag,
        ['cashier.v3.order_center', 'cashier.v3.order.void'], $staff);
    $sourceId = (int)Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('require_secondary', 0)->value('id');
    $source = (new CashierV3BusinessConfigServices())->resolveSourceSnapshot($sourceId, 0, true);
    $recorder = new CashierV3BusinessEventRecorder(); $service = new CashierV3DebtRepaymentServices();
    $makeCommand = static function (string $action, string $key, array $payload) use ($operator, $scope, $recorder, $tag): array {
        return ['operator_scope' => $operator, 'data_scope' => $scope, 'event_recorder' => $recorder,
            'event_execution' => $recorder->newExecution($action, $key, $operator, $scope, $tag),
            'event_contract' => CashierV3ActionManifest::eventContractFor($action), 'idempotency_key' => $key, 'payload' => $payload];
    };
    if (!$persist) Db::startTrans();
    try {
        $seed = static function () use ($template, $tag, $balanceYuan): array {
            // Synthetic member/debt only. Existing members, cards, orders and wallet histories are never edited.
            $user = ['real_name' => $tag, 'nickname' => $tag, 'phone' => '', 'account' => $tag,
                'belong_store_id' => (int)$template['store_id'], 'status' => 1, 'add_time' => time()];
            $user['ben_money'] = '600.00'; $user['give_money'] = number_format(max(400, $balanceYuan - 600), 2, '.', '');
            $user['now_money'] = bcadd($user['ben_money'], $user['give_money'], 2);
            $uid = (int)Db::name('user')->insertGetId($user);
            Db::name('store_user')->insert(['uid' => $uid, 'store_id' => (int)$template['store_id'], 'status' => 1, 'add_time' => time()]);
            $orderId = (int)Db::name('store_order')->insertGetId(['uid' => $uid, 'store_id' => (int)$template['store_id'],
                'order_id' => $tag, 'unique' => md5($tag), 'paid' => 1, 'total_price' => '1380.00', 'pay_price' => '1380.00',
                'debt_amount' => '1380.00', 'repaid_debt_amount' => '0.00', 'add_time' => time()]);
            $debt = $template; unset($debt['id']); $debt['uid'] = $uid; $debt['debt_no'] = $tag;
            $debt['order_id'] = $orderId;
            $debt['total_debt'] = '1380.00'; $debt['repaid_debt'] = '0.00'; $debt['status'] = 0;
            $debtId = (int)Db::name('store_debt')->insertGetId($debt);
            $authority = (array)Db::name('cashier_v3_debt_authority')->where('debt_id', (int)$template['id'])->find();
            unset($authority['id']); $authority['debt_id'] = $debtId; $authority['member_id'] = $uid;
            $authority['debt_no'] = $tag; $authority['sales_order_id'] = $tag; $authority['sales_order_no_snapshot'] = $tag;
            $authority['checkout_request_id'] = $tag; $authority['checkout_command_idempotency_key'] = $tag;
            Db::name('cashier_v3_debt_authority')->insert($authority);
            $item = (array)Db::name('store_debt_item')->where('debt_id', (int)$template['id'])->find(); unset($item['id']);
            $item['debt_id'] = $debtId; $item['debt_amount'] = '1380.00'; $item['repaid_debt'] = '0.00'; $item['product_name'] = $tag;
            $item['order_id'] = $orderId; $item['cart_info_id'] = 0;
            $item['product_type'] = 6; // Synthetic single-project debt, with no issued card to fabricate.
            Db::name('store_debt_item')->insert($item);
            return [$uid, $debtId, $user['ben_money'], $user['give_money']];
        };
        [$uid, $debtId, $benBefore, $giveBefore] = $persist ? tx47($seed) : $seed();
        if ($seedOnly) { echo json_encode(['seedOnly' => true, 'tag' => $tag, 'memberId' => $uid, 'debtId' => $debtId, 'storeId' => $store]) . "\n"; continue; }
        $lines = [['lineRole' => 'sale', 'sourceId' => $debtId, 'lineAmountCents' => 138000, 'debtAmountCents' => 0]];
        if ($balanceYuan > 0) $lines[] = ['lineRole' => 'balance_payment', 'amount' => $balanceYuan];
        if ($balanceYuan < 1380) $lines[] = ['lineRole' => 'payment', 'method' => 'cash', 'amount' => 1380 - $balanceYuan];
        $salesStaff = (array)Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->where('s.store_id', $store)->where('s.status', 1)->where('s.is_del', 0)->where('s.cashier_salesperson_enabled', 1)
            ->where('e.status', 1)->where('e.is_del', 0)->where('e.employment_type_code', 'internal')->field('s.id')->find();
        if (!$salesStaff) throw new RuntimeException('No internal salesperson fixture');
        $command = $makeCommand('submit-debt-repayment', $tag, ['checkoutSnapshot' => [
            'businessType' => 'debt_repayment', 'memberId' => $uid, 'businessDate' => date('Y-m-d'),
            'repaymentAmount' => 1380, 'source' => $source,
            'salespersonAllocations' => [['staffId' => (int)$salesStaff['id'], 'allocationWeight' => 100,
                'performanceAmountManual' => true, 'performanceAmountCents' => 138000]],
            'sourceDocument' => ['type' => 'debt_repayment', 'id' => (string)$debtId], 'lines' => $lines,
        ]]);
        $submit = static fn() => $service->submitInTx($command);
        if (!$persist && $balanceYuan > 0) {
            // Force a failure after wallet/debt writes; the outer transaction must restore every domain.
            $baseline = (array)Db::name('user')->where('uid', $uid)->field('ben_money,give_money,now_money,balance_version')->find();
            Db::execute('SAVEPOINT r47_failure');
            $fault = $command; $fault['idempotency_key'] .= '-fault'; $fault['event_contract'] = [];
            $failed = false;
            try { $service->submitInTx($fault); } catch (Throwable $e) { $failed = true; }
            Db::execute('ROLLBACK TO SAVEPOINT r47_failure'); Db::execute('RELEASE SAVEPOINT r47_failure');
            check47($failed, "$mode injected late failure rejected");
            check47((array)Db::name('user')->where('uid', $uid)->field('ben_money,give_money,now_money,balance_version')->find() === $baseline
                && Db::name('store_debt')->where('id', $debtId)->value('repaid_debt') === '0.00'
                && !Db::name('cashier_v3_debt_repayment')->where('command_idempotency_key', $fault['idempotency_key'])->count(), "$mode late failure rolls back wallet debt and receipt");
            Db::execute('SAVEPOINT r47_insufficient');
            Db::name('user')->where('uid', $uid)->update(['ben_money' => '0.00', 'give_money' => '0.00', 'now_money' => '0.00']);
            $insufficient = $command; $insufficient['idempotency_key'] .= '-insufficient';
            $failed = false;
            try { $service->submitInTx($insufficient); } catch (Throwable $e) { $failed = true; }
            Db::execute('ROLLBACK TO SAVEPOINT r47_insufficient'); Db::execute('RELEASE SAVEPOINT r47_insufficient');
            check47($failed && (array)Db::name('user')->where('uid', $uid)->field('ben_money,give_money,now_money,balance_version')->find() === $baseline,
                "$mode transaction reads current wallet and rejects insufficient funds");
        }
        $result = $persist ? tx47($submit) : $submit();
        $repayment = $result['data']['debtRepayment']; $id = $repayment['repaymentId'];
        check47(Db::name('store_debt')->where('id', $debtId)->value('repaid_debt') === '1380.00', "$mode debt fully settled");
        $userAfter = (array)Db::name('user')->where('uid', $uid)->find();
        check47(bcsub(bcadd($benBefore, $giveBefore, 2), (string)$userAfter['now_money'], 2) === number_format($balanceYuan, 2, '.', ''), "$mode wallet actually deducted");
        check47((int)Db::name('cashier_v3_payment_fact')->where('order_id', $id)->sum('amount_cents') === (1380 - $balanceYuan) * 100, "$mode cash facts exclude wallet");
        check47((int)Db::name('cashier_v3_performance_fact')->where('order_id', $id)->where('performance_type', 'actual_performance_recorded')->sum('amount_cents') === (1380 - $balanceYuan) * 100, "$mode cash performance excludes wallet");
        check47((int)Db::name('cashier_v3_performance_fact')->where('order_id', $id)->where('performance_type', 'sales_performance_allocated')->sum('amount_cents') === (1380 - $balanceYuan) * 100, "$mode salesperson excludes non-cash share including manual gross amount");
        $personnelSource = new ReflectionMethod(\app\services\cashier\v3\order\CashierV3SupplementSalespersonAdjustmentServices::class, 'source'); $personnelSource->setAccessible(true);
        $editSource = $personnelSource->invoke(new \app\services\cashier\v3\order\CashierV3SupplementSalespersonAdjustmentServices(), ['repaymentId' => $id], $operator, $scope, true);
        check47((int)$editSource['repaymentAmountCents'] === (1380 - $balanceYuan) * 100, "$mode later personnel edit retains cash-only base");
        check47((int)Db::name('cashier_v3_payment_fact')->where('order_id', $id)->where('payment_method', Balance::METHOD)->count() === 0, "$mode no fake cash fact");
        $replay = $persist ? tx47($submit) : $submit();
        check47($replay['data']['debtRepayment']['replayed'] === true && Db::name('user')->where('uid', $uid)->value('now_money') === $userAfter['now_money'], "$mode replay never deducts twice");
        $bad = $command; $bad['payload']['checkoutSnapshot']['repaymentAmount'] = 1;
        $conflicted = false; try { $persist ? tx47(static fn() => $service->submitInTx($bad)) : $service->submitInTx($bad); }
        catch (CashierV3CommandException $e) { $conflicted = ($e->getDetail()['reason'] ?? '') === 'debt_repayment_idempotency_conflict'; }
        check47($conflicted, "$mode different amount cannot replay same key");
        if ($balanceYuan > 0) {
            $f = (array)Db::name('cashier_v3_balance_fact')->where('order_id', $id)->where('fact_direction', 'forward')->find();
            check47(-(int)$f['principal_delta_cents'] === 60000 && -(int)$f['bonus_delta_cents'] === ($balanceYuan - 600) * 100, "$mode principal and bonus split frozen");
            $fixtureOrderId = (int)Db::name('store_debt')->where('id', $debtId)->value('order_id');
            check47(Balance::nonCashForSource('0', '', $fixtureOrderId) === $balanceYuan * 100, "$mode cash refund cap excludes active wallet tender");
        }
        $void = $makeCommand('void-order-center-supplement', $tag . '-void',
            ['recordId' => 'v3-sales-supplement:' . $id, 'reason' => 'R47本地合成数据正式补交及作废验证']);
        $doVoid = static fn() => (new CashierV3OrderCenterVoidServices())->executeInTx('void-order-center-supplement', $void);
        $voidResult = $persist ? tx47($doVoid) : $doVoid();
        $afterVoid = (array)Db::name('user')->where('uid', $uid)->find();
        check47($afterVoid['ben_money'] === $benBefore && $afterVoid['give_money'] === $giveBefore, "$mode void restores original principal/bonus");
        check47(Db::name('store_debt')->where('id', $debtId)->value('repaid_debt') === '0.00', "$mode void restores debt");
        foreach (['payment' => ['amount_cents'], 'performance' => ['amount_cents'], 'balance' => ['principal_delta_cents', 'bonus_delta_cents']] as $type => $fields) {
            foreach ($fields as $field) check47((int)Db::name('cashier_v3_' . $type . '_fact')->where('order_id', $id)->sum($field) === 0, "$mode net $type $field zero");
        }
        $voidReplay = $persist ? tx47($doVoid) : $doVoid();
        check47($voidReplay['data']['void']['replayed'] === true && Db::name('user')->where('uid', $uid)->value('now_money') === $afterVoid['now_money'], "$mode void replay never credits twice");
        echo json_encode(['mode' => $mode, 'persisted' => $persist, 'memberId' => $uid, 'debtId' => $debtId,
            'repaymentId' => $id, 'repaymentNo' => $repayment['repaymentNo'], 'void' => $voidResult['business_no'],
            'balanceBefore' => [$benBefore, $giveBefore], 'balanceAfterVoid' => [$afterVoid['ben_money'], $afterVoid['give_money']]], JSON_UNESCAPED_UNICODE) . "\n";
    } finally { if (!$persist) Db::rollback(); }
}
echo "R47 PASS $checks checks\n";

<?php
declare(strict_types=1);

/** Formal V3 original-sale allocation regression. All fixture wallet writes and commands roll back. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices as Balance;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;

(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', $e->getMessage() . ($e instanceof CashierV3CommandException ? json_encode($e->getDetail()) : '') . "\n" . $e->getTraceAsString()); exit(1);
});
if (getenv('R47_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT DATABASE() db')[0]['db'] !== 'ruihao') throw new RuntimeException('Local only');
date_default_timezone_set('Asia/Shanghai');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; };
$fixture = (array)Db::name('cashier_v3_debt_authority')->alias('a')->join('store_debt d', 'd.id=a.debt_id')
    ->where('a.sales_order_record_id', '>', 0)->where('d.status', 0)->where('d.repaid_debt', '0.00')
    ->whereRaw('d.total_debt >= 10')->field('d.*,a.sales_order_id')->order('d.id asc')->find();
if (!$fixture) throw new RuntimeException('No V3 original-sale fixture');
$sourceId = (int)Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('require_secondary', 0)->value('id');
$source = (new CashierV3BusinessConfigServices())->resolveSourceSnapshot($sourceId, 0, true);
foreach ([false, true] as $crossStore) {
    $staffQuery = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0);
    $staffQuery->where('store_id', $crossStore ? '<>' : '=', (int)$fixture['store_id']);
    $staff = (array)$staffQuery->find(); if (!$staff) throw new RuntimeException('No local operator');
    $store = (int)$staff['store_id']; $org = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
    $operator = new CashierV3OperatorScope($store, (int)$staff['id'], $org, '0');
    $scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $store, '0', $org, [$store],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r47-v3', ['cashier.v3.order_center', 'cashier.v3.order.void'], $staff);
    $key = 'R47-V3-' . bin2hex(random_bytes(8)); $uid = (int)$fixture['uid']; $debtId = (int)$fixture['id'];
    $recorder = new CashierV3BusinessEventRecorder(); $service = new CashierV3DebtRepaymentServices();
    $command = static function (string $action, string $key, array $payload) use ($operator, $scope, $recorder): array {
        return ['operator_scope' => $operator, 'data_scope' => $scope, 'event_recorder' => $recorder,
            'event_execution' => $recorder->newExecution($action, $key, $operator, $scope, 'r47-v3'),
            'event_contract' => CashierV3ActionManifest::eventContractFor($action), 'idempotency_key' => $key, 'payload' => $payload];
    };
    $before = (array)Db::name('user')->where('uid', $uid)->find();
    $itemsBefore = Db::name('store_debt_item')->where('debt_id', $debtId)->order('id')->select()->toArray();
    Db::startTrans();
    try {
        // Deliberately gift-only wallet proves bonus may settle sales debt without a fabricated cash receipt.
        Db::name('user')->where('uid', $uid)->update(['ben_money' => '0.00', 'give_money' => '4.00', 'now_money' => '4.00']);
        $snapshot = ['businessType' => 'debt_repayment', 'memberId' => $uid, 'businessDate' => date('Y-m-d'), 'repaymentAmount' => 4,
            'sourceDocument' => ['type' => 'debt_repayment', 'id' => (string)$debtId], 'source' => $source, 'salespersonAllocations' => [],
            'lines' => [['lineRole' => 'sale', 'sourceId' => $debtId, 'lineAmountCents' => 400, 'debtAmountCents' => 0],
                ['lineRole' => 'balance_payment', 'amount' => 2], ['lineRole' => 'payment', 'method' => 'cash', 'amount' => 1],
                ['lineRole' => 'payment', 'method' => 'unionpay', 'amount' => 1]]];
        $submit = $command('submit-debt-repayment', $key, ['checkoutSnapshot' => $snapshot]);
        if ($crossStore && !CashierV3CrossStoreEntitlementPolicy::enabled()) {
            $rejected = false; try { $service->submitInTx($submit); } catch (CashierV3CommandException $e) { $rejected = true; }
            $check($rejected, 'disabled cross-store policy remains enforced'); continue;
        }
        $result = $service->submitInTx($submit); $id = $result['data']['debtRepayment']['repaymentId'];
        $check(count($result['data']['debtRepayment']['paymentResultLines']) === 3
            && array_column($result['data']['debtRepayment']['paymentResultLines'], 'status') === ['succeeded', 'succeeded', 'succeeded'], 'confirmed result carries committed tender statuses');
        $allocations = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')->join('cashier_v3_payment_fact p', 'p.fact_id=a.payment_fact_id AND p.tenant_id=a.tenant_id')
            ->where('p.order_id', $id)->where('a.fact_direction', 'forward')->field('a.*')->select()->toArray();
        $check(array_sum(array_column($allocations, 'amount_cents')) === 200, 'cash allocation equals two cash tenders, not gross debt');
        foreach ($allocations as $allocation) $check((int)$allocation['amount_cents'] % 100 === 0 && (int)$allocation['store_id'] === $store, 'whole-yuan allocation retains handling store');
        $settlements = Db::name(Balance::TABLE)->where('repayment_id', $id)->select()->toArray();
        foreach ($settlements as $settlement) {
            $allocated = array_sum(array_column(array_filter($allocations, static fn(array $a): bool => $a['source_line_id'] === $settlement['order_line_id']), 'amount_cents'));
            $check($allocated === (int)$settlement['amount_cents'] - (int)$settlement['balance_amount_cents'], 'item cash total matches frozen tender matrix');
        }
        // A later cash repayment must not move this gift-funded repayment's item shares.
        $next = $snapshot; $next['repaymentAmount'] = 1;
        $next['lines'] = [['lineRole' => 'sale', 'sourceId' => $debtId, 'lineAmountCents' => 100, 'debtAmountCents' => 0], ['lineRole' => 'payment', 'method' => 'cash', 'amount' => 1]];
        $second = $service->submitInTx($command('submit-debt-repayment', $key . '-next', ['checkoutSnapshot' => $next]));
        $void = static function (string $id, string $suffix) use ($command, $key): void {
            (new CashierV3OrderCenterVoidServices())->executeInTx('void-order-center-supplement', $command('void-order-center-supplement', $key . $suffix,
                ['recordId' => 'v3-sales-supplement:' . $id, 'reason' => 'R47 V3原销售分摊回滚测试']));
        };
        $void($id, '-void');
        $check(Db::name('store_debt')->where('id', $debtId)->value('repaid_debt') === '1.00', 'out-of-order void preserves later repayment');
        $check(Db::name('user')->where('uid', $uid)->value('give_money') === '4.00', 'gift-only debit returned as gift, not principal');
        $void($second['data']['debtRepayment']['repaymentId'], '-void-next');
        $check(Db::name('store_debt_item')->where('debt_id', $debtId)->order('id')->select()->toArray() !== [], 'debt items remain traceable');
        $check((int)Db::name('cashier_v3_payment_fact')->where('order_id', $id)->sum('amount_cents') === 0, 'cash facts net zero after void');
    } finally { Db::rollback(); }
    $check((array)Db::name('user')->where('uid', $uid)->find() === $before
        && Db::name('store_debt_item')->where('debt_id', $debtId)->order('id')->select()->toArray() === $itemsBefore, 'all original local fixture data restored');
}
echo "R47_V3 PASS $checks checks\n";

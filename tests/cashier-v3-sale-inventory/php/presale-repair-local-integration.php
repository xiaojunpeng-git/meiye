<?php
declare(strict_types=1);

/** Real local sample, every mutation (including order-void probe) rolls back. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderInventoryReversalServices;
use app\services\cashier\v3\settlement\CashierV3PresaleStockRepairServices;
use app\services\report\ProductManagementDashboardServices;
use think\facade\Db;

try {
    (new \think\App($backend . '/'))->initialize();
    $config = config('database.connections.mysql');
    if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao'
        || !is_file('/.dockerenv')) throw new RuntimeException('local_database_required');
    $check = static function (string $name, bool $ok): void {
        if (!$ok) throw new RuntimeException($name);
        echo 'PASS ' . $name . PHP_EOL;
    };
    $id = 'CSO-13a9cb70037ed6777316e8ed11b10b8138848b55';
    $snapshot = static function () use ($id): array {
        return [
            Db::name('inventory_stock')->where('id', 6551)->find(),
            Db::name('inventory_batch')->where('id', 6066)->find(),
            Db::name('inventory_batch_movement_fact')->where('source_id', $id)->order('id')->select()->toArray(),
            Db::name('cashier_v3_sales_order')->where('order_id', $id)->find(),
            Db::name('cashier_v3_sales_order_line')->where('order_id', $id)->order('id')->select()->toArray(),
            Db::name('cashier_v3_sale_inventory_receipt')->where('sales_order_id', $id)->find(),
        ];
    };
    $before = $snapshot();
    // This shared local store can have later legitimate sales. Assert repair
    // deltas and exact replay facts, not the balance from yesterday's screenshot.
    $alreadyApplied = count(array_filter($before[2], static function (array $fact): bool {
        return (int)$fact['reversal_of'] === 21719 && $fact['source_type'] === CashierV3PresaleStockRepairServices::SOURCE_TYPE;
    })) === 1;
    $repair = new CashierV3PresaleStockRepairServices();
    Db::startTrans();
    try {
        $now = time();
        $first = $repair->repairInTx('0', 126, $id, '本地回滚自测', $now);
        $check('repair restores only the presale three units',
            $first['restoredQuantityUnits'] + $first['replayedQuantityUnits'] === 3
            && (int)Db::name('inventory_stock')->where('id', 6551)->value('available_quantity_units') === (int)$before[0]['available_quantity_units'] + $first['restoredQuantityUnits']);
        $replay = $repair->repairInTx('0', 126, $id, '重试不能重复退库', $now + 1);
        $check('repair replay does not restore twice', $replay['restoredQuantityUnits'] === 0 && $replay['replayedQuantityUnits'] === 3);
        $current = $snapshot();
        $check('sales/lines/receipt remain byte-for-byte unchanged', array_slice($current, 3) === array_slice($before, 3));
        $check('original movement facts remain unchanged', array_slice($current[2], 0, 2) === array_slice($before[2], 0, 2));
        $reverse = Db::name('inventory_batch_movement_fact')->where('reversal_of', 21719)->find();
        $check('correction has exact batch/cost link and audit reason',
            CashierV3PresaleStockRepairServices::isExactCorrection($current[2][1], $reverse)
            && $reverse['operator_type'] === 'SYSTEM' && $reverse['detail_remark'] !== '');
        $costReader = new ReflectionMethod(ProductManagementDashboardServices::class, 'saleCostRows');
        $costReader->setAccessible(true);
        $costService = new ProductManagementDashboardServices();
        $costScope = ['tenant_id' => '0', 'store_ids' => [126], 'cost_visible' => true];
        $range = ['start' => '2026-10-09', 'end' => '2026-10-09'];
        $costs = $costReader->invoke($costService, $costScope, $range, [], 'product');
        $costMap = [];
        foreach ($costs as $cost) $costMap[$cost['item_id']] = ($costMap[$cost['item_id']] ?? 0) + $cost['cost_cents'];
        $check('cost read keeps ordinary cost and cancels corrected presale cost on original sale day',
            $costMap['CSL-3087f23d906850296feb9d047ead65da1d7ea052'] === 56400
            && $costMap['CSL-d3cd7c3e269d16678bd19b2458c283e377a9a8d3'] === 0);
        $costScope['cost_visible'] = false;
        $check('cost permission still denies all cost rows', $costReader->invoke($costService, $costScope, $range, [], 'product') === []);
        $costScope['cost_visible'] = true; $costScope['tenant_id'] = 'wrong-tenant';
        $check('cost corrections cannot leak across tenants', $costReader->invoke($costService, $costScope, $range, [], 'product') === []);
        $costScope['tenant_id'] = '0'; $costScope['store_ids'] = [125];
        $foreignCosts = $costReader->invoke($costService, $costScope, $range, [], 'product');
        $check('cost correction cannot leak across stores', !in_array($reverse['source_detail_id'], array_column($foreignCosts, 'item_id'), true));
        $costScope['store_ids'] = [126];
        $laterCosts = $costReader->invoke($costService, $costScope, ['start' => '2026-10-10', 'end' => '2026-10-10'], [], 'product');
        $check('correction cost belongs to original sale day, not repair day', !in_array($reverse['source_detail_id'], array_column($laterCosts, 'item_id'), true));
        $operator = new CashierV3OperatorScope(126, 187, '180', '0');
        $scope = new CashierV3DataScopeContext(187, 187, 126, '0', '180', [126],
            CashierV3DataScopeContext::MODE_STORES, ['mode' => 'stores', 'store_ids' => [126]],
            false, '', 'roles:' . str_repeat('1', 32), ['cashier.v3.cashier'], ['account' => 'local-repair-test']);
        // A malformed opposite movement must never be silently accepted by void.
        Db::execute('SAVEPOINT invalid_correction_probe');
        Db::name('inventory_batch_movement_fact')->where('id', $reverse['id'])->inc('cost_amount_cents')->update();
        $denied = false;
        try {
            (new CashierV3SalesOrderInventoryReversalServices())->reverseForVoidInTx([
                'sourceId' => $id, 'checkoutRequestId' => $before[3]['checkout_request_id'],
            ], 'OLO-' . str_repeat('7', 40), $operator, $scope, $now + 2);
        } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
            $denied = ($error->getDetail()['reason'] ?? '') === 'sales_void_inventory_already_reversed';
        }
        Db::execute('ROLLBACK TO SAVEPOINT invalid_correction_probe');
        $check('void refuses malformed correction facts', $denied);
        $void = (new CashierV3SalesOrderInventoryReversalServices())->reverseForVoidInTx([
            'sourceId' => $id, 'checkoutRequestId' => $before[3]['checkout_request_id'],
        ], 'OLO-' . str_repeat('6', 40), $operator, $scope, $now + 2);
        $check('later void restores only ordinary three units, not corrected presale',
            $void['restoredQuantityUnits'] === 3 && $void['restoredAllocationCount'] === 1
            && (int)Db::name('inventory_stock')->where('id', 6551)->value('available_quantity_units') === (int)$before[0]['available_quantity_units'] + $first['restoredQuantityUnits'] + 3);
    } finally { Db::rollback(); }
    $check('all repair and void probe writes rolled back', $snapshot() === $before);
    foreach ([['other-tenant', 126], ['0', 125]] as $wrongScope) {
        Db::startTrans();
        try {
            $denied = false;
            try { $repair->repairInTx($wrongScope[0], $wrongScope[1], $id, '越权测试', time()); }
            catch (RuntimeException $error) { $denied = $error->getMessage() === 'presale_stock_repair_source_changed'; }
            $check('wrong tenant/store denied', $denied);
        } finally { Db::rollback(); }
    }
    // A balance mismatch must not be hidden by the maintenance writer.
    Db::startTrans();
    try {
        Db::name('inventory_stock')->where('id', 6551)->inc('available_quantity_units')->update();
        $denied = false;
        try { $repair->repairInTx('0', 126, $id, '对账失败测试', time()); }
        catch (RuntimeException $error) { $denied = $error->getMessage() === 'presale_stock_repair_ledger_mismatch'; }
        // After the authorized repair is already applied there are no pending writes.
        if ($alreadyApplied) echo "SKIP ledger mismatch initial-repair check: correction already applied\n";
        else $check('ledger mismatch denied before initial repair', $denied);
    } finally { Db::rollback(); }
    $check('final database identical to pretest', $snapshot() === $before);
    echo "PRESALE_REPAIR_LOCAL=PASS\n";
} catch (Throwable $error) {
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL);
    exit(1);
}

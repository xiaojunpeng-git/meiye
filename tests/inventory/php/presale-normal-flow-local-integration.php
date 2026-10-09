<?php
declare(strict_types=1);

/** Real local audit pair: every negative probe rolls back; no permanent writes. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryNormalMovementScopeServices;
use app\services\product\inventory\InventoryMovementQueryServices;
use app\services\product\inventory\InventoryMovementAnalyticsServices;
use think\facade\Db;

try {
    (new \think\App($backend . '/'))->initialize();
    $config = config('database.connections.mysql');
    if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao'
        || !is_file('/.dockerenv')) throw new RuntimeException('local_fixture_database_required');
    $passed = 0;
    $check = static function (string $name, bool $ok) use (&$passed): void {
        if (!$ok) throw new RuntimeException($name);
        $passed++; echo 'PASS ' . $name . PHP_EOL;
    };
    $orderId = 'CSO-13a9cb70037ed6777316e8ed11b10b8138848b55';
    $rawQuery = static function () use ($orderId) {
        return Db::name('inventory_batch_movement_fact')->alias('f')->where('f.tenant_id', '0')
            ->where('f.store_id', 126)->where('f.source_id', $orderId)->order('f.id');
    };
    $normalQuery = static function () use ($rawQuery) {
        $query = $rawQuery();
        (new InventoryNormalMovementScopeServices())->excludeCorrectedPresalePairs($query);
        return $query;
    };
    $before = $rawQuery()->select()->toArray();
    // Later accepted local checkouts may change this SKU balance legitimately.
    $stockBefore = (int)Db::name('inventory_stock')->where('id', 6551)->value('available_quantity_units');
    $repair = Db::name('inventory_batch_movement_fact')->where('reversal_of', 21719)->find();
    $check('exact audit fixture exists', count($before) === 3 && $repair['source_type'] === 'cashier_presale_stock_repair');
    $check('normal flow contains only ordinary sale', array_map('intval', $normalQuery()->column('f.id')) === [21718]);
    $sql = $normalQuery()->field('f.id')->fetchSql(true)->select();
    $explain = Db::query('EXPLAIN ' . $sql);
    echo 'normal flow EXPLAIN: ' . json_encode(array_map(static function (array $row): array {
        return array_intersect_key($row, array_flip(['table', 'type', 'key', 'rows']));
    }, $explain), JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $listReader = new ReflectionMethod(InventoryMovementQueryServices::class, 'movementRows');
    $listReader->setAccessible(true);
    $page = $listReader->invoke(new InventoryMovementQueryServices(), [72], $orderId, 1, 1, true);
    $check('list count and pagination use normal scope', (int)$page['count'] === 1
        && count($page['list']) === 1 && (int)$page['list'][0]['id'] === 21718);
    $hidden = $listReader->invoke(new InventoryMovementQueryServices(), [72], $orderId, 1, 20, false);
    $check('cost permission remains intact', $hidden['list'][0]['cost_amount_cents'] === null);
    $foreign = $listReader->invoke(new InventoryMovementQueryServices(), [71], $orderId, 1, 20, true);
    $check('other warehouse cannot read this order flow', (int)$foreign['count'] === 0);
    $analytics = new InventoryMovementAnalyticsServices();
    // Compare against formal ordinary-sale facts, independently of the exclusion helper;
    // shared local acceptance may add valid sales on either day in this interval.
    $ordinary = Db::name('inventory_batch_movement_fact')->alias('f')
        ->join('cashier_v3_sales_order_line l', 'l.tenant_id=f.tenant_id AND l.store_id=f.store_id AND l.order_id=f.source_id AND l.order_line_id=f.source_detail_id')
        ->where('f.tenant_id', '0')->where('f.location_id', 72)->where('f.stock_id', 6551)
        ->where('f.source_type', 'cashier_sale')->where('f.direction', -1)->where('f.fact_status', 'SETTLED')
        ->whereBetween('f.business_date', ['2026-10-09', '2026-10-10'])->where('l.is_presale', 0)
        ->field('f.business_date,SUM(f.quantity_units) AS quantity_units,SUM(f.cost_amount_cents) AS cost_amount_cents')
        ->group('f.business_date')->select()->toArray();
    foreach ([false, true] as $period) {
        $outbound = $analytics->listForLocations([72], 'outbound', '2026-10-09', '2026-10-10', true, $period);
        $matched = array_values(array_filter($outbound['list'], static function (array $row): bool {
            return (int)$row['product_id'] === 61705 && $row['source_type'] === 'cashier_sale';
        }));
        $expected = $period ? [['quantity_units' => array_sum(array_column($ordinary, 'quantity_units')),
            'cost_amount_cents' => array_sum(array_column($ordinary, 'cost_amount_cents'))]] : $ordinary;
        $normalize = static function (array $rows) use ($period): array {
            $result = [];
            foreach ($rows as $row) $result[$period ? 'period' : $row['business_date']] = [(int)$row['quantity_units'], (int)$row['cost_amount_cents']];
            ksort($result); return $result;
        };
        $check('daily/period outbound counts ordinary quantity only', $normalize($matched) === $normalize($expected));
    }
    $inbound = $analytics->listForLocations([72], 'inbound', '2026-10-09', '2026-10-10', true);
    $check('correction is not counted as business inbound', !in_array('cashier_presale_stock_repair', array_column($inbound['list'], 'source_type'), true));
    // An invalid or unbalanced correction must not hide evidence of an unresolved stock error.
    foreach ([['cost_amount_cents' => (int)$repair['cost_amount_cents'] + 1],
        ['quantity_units' => 4], ['tenant_id' => 'wrong-tenant'], ['store_id' => 125],
        ['source_detail_id' => 'wrong-line'], ['fact_key' => 'invalid-repair-key']] as $change) {
        Db::startTrans();
        try {
            Db::name('inventory_batch_movement_fact')->where('id', $repair['id'])->update($change);
            $check('unmatched correction cannot hide original deduction', in_array(21719, array_map('intval', $normalQuery()->column('f.id')), true));
        } finally { Db::rollback(); }
    }
    Db::startTrans();
    try {
        // Simulate a real claim source without changing physical quantities or adding fixture facts.
        Db::name('inventory_batch_movement_fact')->where('id', 21719)->update(['source_type' => 'presale_claim_outbound']);
        $check('actual presale claim outbound remains visible', in_array(21719, array_map('intval', $normalQuery()->column('f.id')), true));
    } finally { Db::rollback(); }
    $check('raw audit facts remain unchanged', $rawQuery()->select()->toArray() === $before);
    $check('stock unchanged after all probes', (int)Db::name('inventory_stock')->where('id', 6551)->value('available_quantity_units') === $stockBefore);
    echo "PRESALE_NORMAL_FLOW_LOCAL passed={$passed} failed=0\n";
} catch (Throwable $error) {
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL);
    exit(1);
}

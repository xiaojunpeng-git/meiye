<?php
declare(strict_types=1);

/** Pure projection plus optional local-only read regression; never writes stock or documents. */
$root = dirname(__DIR__, 3);
$backend = getenv('BACKEND_ROOT') ?: $root . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryMovementDisplayServices;
use app\services\product\inventory\InventoryStoreReadModelServices;
use think\facade\Db;

$reader = new InventoryMovementDisplayServices();
$project = new ReflectionMethod($reader, 'project');
$project->setAccessible(true);
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$fact = [
    'tenant_id' => '0', 'store_id' => 126, 'source_type' => 'cashier_sale',
    'source_id' => 'CSO-test', 'source_detail_id' => 'CSL-test',
    'order_sn' => 'CSO-test', 'source_product_name' => '批次旧名称',
    'quantity_units' => 3, 'direction' => -1, 'cost_amount_cents' => 56400, 'reversal_of' => 0,
];
$lines = ['CSO-test:CSL-test' => [
    'order_no' => 'XS26100900006', 'item_name_snapshot' => '销售时名称', 'line_no' => 2, 'is_presale' => 1,
]];
$mapped = $project->invoke($reader, $fact, $lines, [], []);
$check($mapped['order_sn'] === 'XS26100900006' && $mapped['source_type_name'] === '收银结账', 'sale document/type wrong');
$check($mapped['source_detail_name'] === '第2行：销售时名称（预售）', 'immutable sales name not used');
foreach (['source_id', 'source_detail_id', 'quantity_units', 'direction', 'cost_amount_cents', 'reversal_of'] as $key) {
    $check($mapped[$key] === $fact[$key], 'ledger key changed: ' . $key);
}
$missing = $project->invoke($reader, $fact, [], [], []);
$check($missing['order_sn'] === '历史业务单据' && $missing['source_detail_name'] === '历史销售明细', 'missing authority leaked ID/guessed name');
$void = array_merge($fact, ['source_type' => 'cashier_sale_void', 'source_id' => 'OP-test', 'reversal_of' => 7]);
$mappedVoid = $project->invoke($reader, $void, $lines, ['OP-test' => ['source_order_id' => 'CSO-test', 'operation_no' => 'ZF26100900001']], []);
$check($mappedVoid['order_sn'] === 'ZF26100900001' && $mappedVoid['source_type_name'] === '销售订单作废' && $mappedVoid['reversal_of'] === 7, 'void source lost');
$correction = $project->invoke($reader, array_merge($fact, ['source_type' => 'cashier_presale_stock_repair', 'direction' => 1, 'reversal_of' => 7]), $lines, [], []);
$check($correction['order_sn'] === 'XS26100900006' && $correction['source_type_name'] === '预售扣库纠正'
    && $correction['source_detail_name'] === '第2行：销售时名称（预售）', 'correction source display wrong');
foreach ([
    'stock_count_gain' => '盘盈入库', 'manual_inbound' => '手工入库',
    'presale_claim_outbound' => '预售领用', 'gift_product_claim_outbound' => '赠送领用',
    'salon_usage_issue' => '院装领用', 'completion_batch' => '项目耗材核销',
    'unknown_future' => '其他库存业务',
] as $type => $name) {
    $row = $project->invoke($reader, array_merge($fact, ['source_type' => $type, 'document_no_snapshot' => 'RK2610090001']), [], [], []);
    $check($row['source_type_name'] === $name && $row['source_detail_name'] === '批次旧名称' && $row['order_sn'] === 'RK2610090001', 'business source name wrong');
}
$claim = $project->invoke($reader, array_merge($fact, ['source_type' => 'presale_claim_void', 'source_id' => 'PCC-test']), [], [], ['PCC-test' => ['claim_no' => 'PSLY2610090001']]);
$check($claim['order_sn'] === 'PSLY2610090001', 'claim reversal number missing');
echo "movement business display projection: PASS\n";

if (in_array('--local-fixture', $argv, true)) {
    $app = new \think\App($backend . '/'); $app->initialize();
    $config = config('database.connections.mysql');
    $check(($config['hostname'] ?? '') === 'mysql' && ($config['database'] ?? '') === 'ruihao', 'not local test database');
    Db::execute('START TRANSACTION READ ONLY');
    try {
        $source = Db::name('cashier_v3_sales_order')->where('order_no', 'XS26100900006')->find();
        $check(is_array($source), 'fixture missing');
        $original = Db::name('inventory_batch_movement_fact')->where('source_id', $source['order_id'])->order('id')->select()->toArray();
        // Round 6 preserves the two deductions and adds one linked presale correction.
        $check(count($original) === 3, 'fixture movement count wrong');
        $stock = Db::name('inventory_stock')->where('id', $original[0]['stock_id'])->find();
        $detail = (new InventoryStoreReadModelServices())->productDetail((int)$source['store_id'], 71, (int)$stock['consumable_product_id'], true);
        $matched = array_values(array_filter($detail['movements'], static function (array $row) use ($source): bool { return $row['source_id'] === $source['order_id']; }));
        // Shared local stock can include later valid sales; the original order's
        // normal flow and the current authoritative balance must both stay exact.
        $check(count($matched) === 1 && (int)$detail['total_available_quantity'] === (int)$stock['available_quantity_units'], 'normal flow must show only ordinary physical sale');
        $check($matched[0]['source_detail_id'] === 'CSL-3087f23d906850296feb9d047ead65da1d7ea052', 'presale audit pair still visible');
        foreach ($matched as $row) {
            $expectedType = $row['source_type'] === 'cashier_presale_stock_repair' ? '预售扣库纠正' : '收银结账';
            $check($row['order_sn'] === $source['order_no'] && $row['source_type_name'] === $expectedType, 'real source not resolved');
            $check(strpos($row['source_detail_name'], '玻尿酸蚕丝水润面膜') !== false, 'real detail name missing');
        }
        $hidden = (new InventoryStoreReadModelServices())->productDetail((int)$source['store_id'], 71, (int)$stock['consumable_product_id'], false);
        foreach ($hidden['movements'] as $row) $check($row['unit_cost_cents'] === null && $row['cost_amount_cents'] === null, 'cost permission leaked');
        // Even an exact internal ID may not resolve across tenant/store/order boundaries.
        $saleRows = array_values(array_filter($matched, static function (array $row): bool { return $row['source_type'] === 'cashier_sale'; }));
        $probe = array_merge($saleRows[0], ['source_product_name' => '旧批次商品']);
        foreach ([['tenant_id' => 'not-this-tenant'], ['store_id' => 0], ['source_id' => 'different-order']] as $override) {
            $isolated = $reader->decorate([array_merge($probe, $override)])[0];
            $check($isolated['order_sn'] === '历史业务单据' && $isolated['source_detail_name'] === '历史销售明细', 'scope/source leaked');
        }
        $after = Db::name('inventory_batch_movement_fact')->where('source_id', $source['order_id'])->order('id')->select()->toArray();
        $check($after === $original, 'facts changed by display');
        // Inspect the exact bounded lookup shape, not an unscoped history scan.
        $explain = Db::query('EXPLAIN SELECT sl.order_id,sl.order_line_id,sl.item_name_snapshot,sl.line_no,sl.is_presale,so.order_no FROM eb_cashier_v3_sales_order_line sl JOIN eb_cashier_v3_sales_order so ON so.tenant_id=sl.tenant_id AND so.store_id=sl.store_id AND so.order_id=sl.order_id WHERE sl.tenant_id=? AND sl.store_id=? AND sl.order_id=? AND sl.order_line_id IN (?,?) AND sl.line_direction=?',
            [(string)$source['tenant_id'], (int)$source['store_id'], (string)$source['order_id'], $original[0]['source_detail_id'], $original[1]['source_detail_id'], 'forward']);
        echo 'source lookup EXPLAIN: ' . json_encode(array_map(static function (array $row): array {
            return array_intersect_key($row, array_flip(['table', 'type', 'key', 'rows']));
        }, $explain), JSON_UNESCAPED_UNICODE) . "\n";
        echo 'local movement read/cost/scope regression: PASS; document=XS26100900006; normal movements=1; audit facts=3; stock=' . $detail['total_available_quantity'] . "\n";
    } finally { Db::execute('ROLLBACK'); }
}

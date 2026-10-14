<?php
declare(strict_types=1);

// Run only against the local Docker ruihao snapshot; the outer transaction always rolls back.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use think\facade\Db;

function sameNumberCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local ruihao database guard failed');
}

$document = Db::name('inventory_cross_transfer_document')->where('transfer_no', 'DB2610060001')->find();
sameNumberCheck($document && $document['document_status'] === 'DISPATCHED'
    && (int)$document['to_party_id'] === 22 && (int)$document['to_location_id'] === 14,
    'the local snapshot has the expected in-transit document');
$allocation = Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', (int)$document['id'])
    ->where('from_batch_id', 9415)->find();
$source = Db::name('inventory_batch')->where('id', 9415)->find();
$line = $allocation ? Db::name('inventory_cross_transfer_line')->where('id', (int)$allocation['line_id'])->find() : null;
$location = Db::name('inventory_location')->where('id', 14)->find();
sameNumberCheck($allocation && $source && $line && $location
    && (int)$source['origin_batch_id'] === 9415 && (string)$source['batch_no'] === '1'
    && (int)$allocation['received_at'] === 0,
    'the allocated source batch is the colliding batch');

$factCount = (int)Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])
    ->where('source_type', 'cross_transfer_in')->count();
$now = time();
Db::startTrans();
try {
    $stock = Db::name('inventory_stock')->where('tenant_id', (string)$location['tenant_id'])
        ->where('location_id', 14)->where('consumable_product_id', (int)$line['to_product_id'])
        ->where('sku_id', (int)$line['to_sku_id'])->where('product_unique', (string)$line['to_sku_unique'])
        ->where('stock_status', 'GOOD')->find();
    if (!$stock) {
        $stockId = (int)Db::name('inventory_stock')->insertGetId([
            'tenant_id' => (string)$location['tenant_id'], 'organization_id' => (string)$location['organization_id'],
            'organization_path' => (string)$location['organization_path'], 'location_id' => 14,
            'store_id' => 22, 'consumable_product_id' => (int)$line['to_product_id'],
            'sku_id' => (int)$line['to_sku_id'], 'product_unique' => (string)$line['to_sku_unique'],
            'stock_status' => 'GOOD', 'stock_unit' => (string)$line['stock_unit_snapshot'],
            'quantity_scale' => (int)$line['quantity_scale'], 'available_quantity_units' => 0,
            'estimated_unit_cost_cents' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    } else {
        $stockId = (int)$stock['id'];
    }
    sameNumberCheck(!Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_no', '1')->find(),
        'collision fixture does not overwrite an existing local batch');
    Db::name('inventory_batch')->insert([
        'stock_id' => $stockId, 'origin_batch_id' => 1598, 'source_batch_id' => 1598,
        'batch_no' => '1', 'available_quantity_units' => 0, 'unit_cost_cents' => 49800,
        'batch_status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $service = new InventoryCrossSubjectTransferServices();
    $result = $service->receiveForStore(22, 549, (int)$document['id']);
    $received = Db::name('inventory_cross_transfer_batch_allocation')->where('id', (int)$allocation['id'])->find();
    $target = Db::name('inventory_batch')->where('id', (int)$received['to_batch_id'])->find();
    sameNumberCheck($result['document_status'] === 'RECEIVED' && !$result['idempotent']
        && (int)$target['stock_id'] === $stockId && (int)$target['origin_batch_id'] === 9415
        && (int)$target['available_quantity_units'] === 3 && (int)$target['unit_cost_cents'] === 49800
        && (string)$target['batch_no'] === '1~o9415',
        'receipt keeps same-number batches separate and credits only the source lineage');
    sameNumberCheck((int)Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_no', '1')
        ->value('available_quantity_units') === 0
        && (int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === 3,
        'colliding batch remains untouched and stock total equals the receipt');
    $targetMethod = new ReflectionMethod($service, 'targetBatch');
    $targetMethod->setAccessible(true);
    $stock = Db::name('inventory_stock')->where('id', $stockId)->find();
    sameNumberCheck((int)$targetMethod->invoke($service, $stock, $source, $document, $now)['id']
        === (int)$target['id']
        && (int)Db::name('inventory_batch')->where('stock_id', $stockId)
            ->where('origin_batch_id', 9415)->count() === 1,
        'another delivery from the same source lineage reuses its target batch');
    $changedCost = $source;
    $changedCost['unit_cost_cents'] = (int)$source['unit_cost_cents'] + 1;
    try {
        $targetMethod->invoke($service, $stock, $changedCost, $document, $now);
        throw new RuntimeException('same-lineage cost change was accepted');
    } catch (RuntimeException $exception) {
        sameNumberCheck($exception->getMessage() === 'inventory_cross_transfer_target_batch_conflict',
            'a changed cost cannot be merged into the same source lineage');
    }
    $numberMethod = new ReflectionMethod($service, 'disambiguatedTargetBatchNo');
    $numberMethod->setAccessible(true);
    $longNumber = $numberMethod->invoke($service, $stockId, str_repeat('X', 64), 9416);
    sameNumberCheck(strlen($longNumber) === 64 && substr($longNumber, -6) === '~o9416',
        'collision numbering keeps the original prefix within the database length limit');
    $facts = Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])
        ->where('source_type', 'cross_transfer_in')->select()->toArray();
    sameNumberCheck(count($facts) === $factCount + 2
        && count(array_filter($facts, static fn (array $fact): bool => (int)$fact['batch_id'] === (int)$target['id']
            && (int)$fact['quantity_units'] === 3)) === 1,
        'both receipt lines write exactly one batch movement fact');
    sameNumberCheck($service->receiveForStore(22, 549, (int)$document['id'])['idempotent']
        && (int)Db::name('inventory_batch')->where('id', (int)$target['id'])->value('available_quantity_units') === 3
        && (int)Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])
            ->where('source_type', 'cross_transfer_in')->count() === $factCount + 2,
        'receipt retry does not create another batch credit or fact');
} catch (Throwable $exception) {
    Db::rollback();
    echo 'FAIL ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
Db::rollback();

sameNumberCheck((string)Db::name('inventory_cross_transfer_document')->where('id', (int)$document['id'])
    ->value('document_status') === 'DISPATCHED'
    && (int)Db::name('inventory_cross_transfer_batch_allocation')->where('id', (int)$allocation['id'])
        ->value('received_at') === 0
    && (int)Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])
        ->where('source_type', 'cross_transfer_in')->count() === $factCount,
    'rollback leaves the local document and facts unchanged');
echo "CROSS_TRANSFER_SAME_NUMBER_DIFFERENT_ORIGIN_OK\n";

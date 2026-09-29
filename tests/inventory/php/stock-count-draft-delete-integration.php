<?php
declare(strict_types=1);

/** This destructive test is restricted to the dedicated local inventory fixture database. */
if (getenv('DB_DATABASE') !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('inventory_draft_delete_test_database_guard');
}
putenv('INVENTORY_SKIP_SCHEMA_BOOTSTRAP=1');
require __DIR__ . '/manual-test-bootstrap.php';

use app\services\product\inventory\InventoryStockCountDraftServices;
use think\facade\Config;
use think\facade\Db;

if ((string)Config::get('database.connections.mysql.database') !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('inventory_draft_delete_test_database_guard');
}

function draftDeleteReason(callable $action): string
{
    try { $action(); } catch (Throwable $error) { return $error->getMessage(); }
    return '';
}

$service = new InventoryStockCountDraftServices();
$factsBefore = (int)Db::name('inventory_batch_movement_fact')->where('store_id', 99008)->count();
$stockBefore = (int)Db::name('inventory_stock')->where('store_id', 99008)->count();
$createdId = 0;
try {
    $saved = $service->save(99008, 990008, [
        'draft_id' => 0, 'expected_version' => 0, 'business_date' => '2026-09-29',
        'remark' => 'TEST-DELETE-' . time(), 'lines' => [[
            'product_id' => 990081, 'sku_id' => 9900811, 'sku_unique' => 'testsku9900811',
            'product_name' => 'TEST-盘点精华', 'sku_name' => '30ml',
            'book_quantity' => '0', 'counted_quantity' => '1',
        ]],
    ]);
    $createdId = (int)$saved['draft_id'];
    if ($createdId <= 0 || (int)$saved['version'] !== 1) throw new RuntimeException('draft_create_failed');
    if (draftDeleteReason(static fn() => $service->delete(99008, 990008, $createdId, 2)) !== 'inventory_stock_count_draft_changed') {
        throw new RuntimeException('stale_version_must_not_delete');
    }
    if (draftDeleteReason(static fn() => $service->delete(99008, 990009, $createdId, 1)) === '') {
        throw new RuntimeException('other_operator_must_not_delete');
    }
    $deleted = $service->delete(99008, 990008, $createdId, 1);
    if ($deleted !== ['draft_id' => $createdId, 'deleted' => true]
        || Db::name('inventory_stock_count_draft')->where('id', $createdId)->find()) {
        throw new RuntimeException('draft_was_not_physically_deleted');
    }
    if (draftDeleteReason(static fn() => $service->detail(99008, 990008, $createdId)) !== 'inventory_stock_count_draft_missing') {
        throw new RuntimeException('deleted_draft_remains_readable');
    }
    if ((int)Db::name('inventory_batch_movement_fact')->where('store_id', 99008)->count() !== $factsBefore
        || (int)Db::name('inventory_stock')->where('store_id', 99008)->count() !== $stockBefore) {
        throw new RuntimeException('draft_delete_changed_stock_or_facts');
    }
    echo "STOCK_COUNT_DRAFT_DELETE_INTEGRATION_OK\n";
} finally {
    // If an assertion fails, remove only this test's own unconfirmed draft from the isolated fixture DB.
    if ($createdId > 0) Db::name('inventory_stock_count_draft')->where('id', $createdId)
        ->where('store_id', 99008)->where('operator_id', 990008)->where('document_status', 'DRAFT')->delete();
}

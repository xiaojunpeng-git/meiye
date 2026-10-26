<?php
declare(strict_types=1);

// Run only against the local ruihao Docker database. Every business write is enclosed by an outer rollback.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\query\InventoryTransferUnifiedQueryProvider;
use think\facade\Db;

function withdrawalCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

function withdrawalReject(callable $operation, string $expected, string $label): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        withdrawalCheck($exception->getMessage() === $expected, $label . ': ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException($label . ': unexpectedly allowed');
}

/** Query/export shares this source projection, so recipient isolation must hold before pagination. */
function withdrawalUnifiedRows(int $storeId, int $locationId): array
{
    $provider = (new ReflectionClass(InventoryTransferUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($provider, 'transferRows');
    if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    return $method->invoke($provider, '0', $storeId, [$locationId], false, false);
}

function withdrawalHasDocument(array $rows, string $transferNo): bool
{
    foreach ($rows as $row) if ((string)($row['order_sn'] ?? '') === $transferNo) return true;
    return false;
}

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local ruihao database guard failed');
}

$service = new InventoryCrossSubjectTransferServices();
$initialDraft = Db::name('inventory_cross_transfer_document')->where('from_party_type', 'STORE')
    ->where('to_party_type', 'STORE')->where('document_status', 'DRAFT')->where('withdrawal_count', 0)->find();
if (!$initialDraft) throw new RuntimeException('A local store-to-store draft is required');
withdrawalCheck($service->listForStore((int)$initialDraft['to_party_id'], 0, (string)$initialDraft['transfer_no'])['count'] === 0,
    'recipient list hides never-dispatched draft');
withdrawalCheck(!withdrawalHasDocument(withdrawalUnifiedRows((int)$initialDraft['to_party_id'],
    (int)$initialDraft['to_location_id']), (string)$initialDraft['transfer_no']),
    'recipient unified query hides never-dispatched draft');
withdrawalReject(fn () => $service->detailForStore((int)$initialDraft['to_party_id'], 0, (int)$initialDraft['id']),
    'inventory_cross_transfer_not_found', 'recipient detail hides never-dispatched draft');
$headquartersDraft = Db::name('inventory_cross_transfer_document')->where('from_party_type', 'STORE')
    ->where('to_party_type', 'HQ')->where('document_status', 'DRAFT')->find();
if (!$headquartersDraft) throw new RuntimeException('A local store-to-HQ draft is required');
$platformAdmin = ['id' => 1, 'roles' => [], 'real_name' => 'admin'];
withdrawalCheck($service->listForHeadquarters($platformAdmin, (int)$headquartersDraft['to_location_id'],
    (string)$headquartersDraft['transfer_no'])['count'] === 0,
    'HQ recipient list hides a store draft by default');
withdrawalCheck($service->listForHeadquarters($platformAdmin, (int)$headquartersDraft['to_location_id'],
    (string)$headquartersDraft['transfer_no'], 1, 20, 'STORE', (int)$headquartersDraft['from_party_id'])['count'] === 1,
    'authorized platform source filter retains store draft editing access');
$document = Db::name('inventory_cross_transfer_document')->where('id', 6)->find();
if (!$document || $document['transfer_no'] !== 'DB2610040002'
    || $document['document_status'] !== 'DISPATCHED' || (int)$document['received_at'] !== 0
    || (int)$document['withdrawal_count'] !== 0
    || (int)$document['from_party_id'] !== 109 || (int)$document['to_party_id'] !== 126) {
    throw new RuntimeException('Expected local in-transit fixture changed');
}
$sourceId = 109;
$sourceOperatorId = 952;
$targetId = 126;
$allocation = Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', 6)->find();
if (!$allocation || (int)$allocation['received_at'] !== 0 || (int)$allocation['withdrawn_at'] !== 0) {
    throw new RuntimeException('Expected local allocation changed');
}
$stockId = (int)$allocation['from_stock_id'];
$batchId = (int)$allocation['from_batch_id'];
$initialStockUnits = (int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units');
$initialBatchUnits = (int)Db::name('inventory_batch')->where('id', $batchId)->value('available_quantity_units');
$initialFactCount = (int)Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])->count();
$initialLineCount = (int)Db::name('inventory_cross_transfer_line')->where('document_id', 6)->count();
$key = 'transfer-withdraw-local-' . bin2hex(random_bytes(6));

Db::startTrans();
try {
    $sourceBefore = $service->detailForStore($sourceId, $sourceOperatorId, 6);
    withdrawalCheck($service->listForStore($targetId, 0, $document['transfer_no'])['count'] === 1,
        'recipient sees the dispatched transfer');
    $result = $service->withdrawForStore($sourceId, $sourceOperatorId, 6, ['idempotency_key' => $key]);
    withdrawalCheck($result['document_status'] === 'DRAFT' && !$result['idempotent'], 'source withdraws before receipt');
    withdrawalCheck($service->listForStore($targetId, 0, $document['transfer_no'])['count'] === 0,
        'recipient list hides unshipped draft');
    withdrawalCheck(!withdrawalHasDocument(withdrawalUnifiedRows($targetId, (int)$document['to_location_id']),
        (string)$document['transfer_no']), 'recipient unified query hides withdrawn draft');
    withdrawalReject(fn () => $service->detailForStore($targetId, 0, 6),
        'inventory_cross_transfer_not_found', 'recipient detail hides unshipped draft');
    withdrawalCheck($service->listForStore($sourceId, $sourceOperatorId, $document['transfer_no'])['count'] === 1,
        'source retains withdrawn draft');
    withdrawalCheck((int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units')
        === $initialStockUnits + (int)$allocation['quantity_units'], 'withdraw restores source stock');
    withdrawalCheck((int)Db::name('inventory_batch')->where('id', $batchId)->value('available_quantity_units')
        === $initialBatchUnits + (int)$allocation['quantity_units'], 'withdraw restores original batch');
    withdrawalCheck((int)Db::name('inventory_batch_movement_fact')->where('source_type', 'cross_transfer_withdraw')
        ->where('source_id', $document['transfer_no'])->where('source_detail_id', (string)$allocation['id'])->count() === 1,
        'withdraw appends linked positive fact');
    withdrawalCheck((int)Db::name('inventory_cross_transfer_batch_allocation')->where('id', $allocation['id'])->value('withdrawn_at') > 0,
        'historical allocation is retained as withdrawn');
    withdrawalCheck($service->withdrawForStore($sourceId, $sourceOperatorId, 6, ['idempotency_key' => $key])['idempotent'],
        'withdraw retry does not restore stock twice');
    withdrawalReject(fn () => $service->receiveForStore($targetId, 0, 6),
        'inventory_cross_transfer_scope_denied', 'recipient cannot receive withdrawn draft');

    $draft = $service->detailForStore($sourceId, $sourceOperatorId, 6);
    withdrawalReject(fn () => $service->deleteDraftForStore($sourceId, $sourceOperatorId, 6, $draft['edit_token']),
        'inventory_cross_transfer_delete_facts_exist', 'withdrawn draft cannot be physically deleted');
    $line = $draft['lines'][0];
    $payload = [
        'idempotency_key' => 'transfer-edit-local-' . bin2hex(random_bytes(6)),
        'business_date' => (string)$document['business_date'],
        'remark' => '撤回后调整明细',
        'target_party_type' => 'STORE', 'target_store_id' => $targetId,
        'request_document_id' => 0,
        'lines' => [[
            'product_id' => (int)$line['from_product_id'], 'sku_id' => (int)$line['from_sku_id'],
            'sku_unique' => (string)$line['from_sku_unique'], 'quantity' => '2', 'request_line_id' => 0,
            'detail_remark' => '重发前已编辑',
        ]],
        'expected_edit_token' => $draft['edit_token'],
    ];
    $service->updateDraftForStore($sourceId, $sourceOperatorId, 6, $payload);
    $edited = $service->detailForStore($sourceId, $sourceOperatorId, 6);
    withdrawalCheck(count($edited['lines']) === 1 && (int)$edited['lines'][0]['line_generation'] === 2
        && (int)$edited['lines'][0]['requested_quantity_units'] === 2
        && $edited['lines'][0]['detail_remark'] === '重发前已编辑'
        && (int)Db::name('inventory_cross_transfer_line')->where('document_id', 6)->count() === $initialLineCount + 1,
        'edit creates a new detail generation without deleting history');
    withdrawalCheck((int)Db::name('inventory_cross_transfer_line')->where('id', $sourceBefore['lines'][0]['id'])->value('retired_at') > 0,
        'old detail is retired');
    $service->dispatchForStore($sourceId, $sourceOperatorId, 6);
    withdrawalCheck($service->listForStore($targetId, 0, $document['transfer_no'])['count'] === 1,
        'recipient sees transfer only after redispatch');
    withdrawalCheck((int)Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', 6)
        ->where('dispatch_attempt', 2)->where('withdrawn_at', 0)->sum('quantity_units') === 2,
        'redispatch allocates the edited quantity in a new attempt');
    withdrawalCheck((int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units')
        === $initialStockUnits - 1, 'redispatch deducts edited quantity from restored stock');
    withdrawalReject(fn () => $service->withdrawForStore($sourceId, $sourceOperatorId, 6, ['idempotency_key' => $key]),
        'inventory_cross_transfer_idempotency_conflict', 'old withdrawal key cannot affect redispatch');
    withdrawalReject(fn () => $service->reverseForStore($sourceId, $sourceOperatorId, 6,
        ['idempotency_key' => $key . '-void-early', 'reason' => '不允许在途作废']),
        'inventory_cross_transfer_void_state_invalid', 'dispatched transfer cannot be voided');
    $secondKey = $key . '-again';
    $service->withdrawForStore($sourceId, $sourceOperatorId, 6, ['idempotency_key' => $secondKey]);
    $voidKey = $key . '-void';
    $void = $service->reverseForStore($sourceId, $sourceOperatorId, 6,
        ['idempotency_key' => $voidKey, 'reason' => '撤回后确认作废']);
    withdrawalCheck($void['document_status'] === 'REVERSED'
        && $service->reverseForStore($sourceId, $sourceOperatorId, 6,
            ['idempotency_key' => $voidKey, 'reason' => '撤回后确认作废'])['idempotent'],
        'withdrawn draft can be voided with idempotent audit');
    withdrawalCheck($service->listForStore($targetId, 0, $document['transfer_no'])['count'] === 0,
        'recipient list also hides a withdrawn draft after void');
    withdrawalCheck(!withdrawalHasDocument(withdrawalUnifiedRows($targetId, (int)$document['to_location_id']),
        (string)$document['transfer_no']), 'recipient unified query hides voided unreceived transfer');
    withdrawalReject(fn () => $service->detailForStore($targetId, 0, 6),
        'inventory_cross_transfer_not_found', 'recipient detail also hides the voided unreceived transfer');
    withdrawalCheck((int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === $initialStockUnits
        + (int)$allocation['quantity_units'], 'void has no additional stock effect');
    withdrawalCheck((int)Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])->count()
        === $initialFactCount + 3, 'two dispatch attempts retain both reversal facts');
    echo "TRANSFER_WITHDRAWAL_LOCAL_INTEGRATION_OK\n";
} finally {
    Db::rollback();
}

withdrawalCheck((string)Db::name('inventory_cross_transfer_document')->where('id', 6)->value('document_status') === 'DISPATCHED'
    && (int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === $initialStockUnits
    && (int)Db::name('inventory_batch_movement_fact')->where('source_id', $document['transfer_no'])->count() === $initialFactCount,
    'outer rollback leaves original local business data unchanged');

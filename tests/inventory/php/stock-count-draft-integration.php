<?php
declare(strict_types=1);

/** Run after stock-count-integration.php against the isolated inventory fixture database. */
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\query\InventoryCountUnifiedQueryProvider;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([['database.type','mysql'],['DATABASE_TYPE','mysql'],['database.hostname',getenv('DB_HOST') ?: 'mysql'],['DATABASE_HOSTNAME',getenv('DB_HOST') ?: 'mysql'],['database.hostport',getenv('DB_PORT') ?: '3306'],['DATABASE_HOSTPORT',getenv('DB_PORT') ?: '3306'],['database.database',getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730'],['DATABASE_DATABASE',getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730'],['cache.driver','file'],['CACHE_DRIVER','file']] as [$key,$value]) $app->env->set($key,$value);
$envName = new ReflectionProperty($app, 'envName'); $envName->setAccessible(true); $envName->setValue($app, 'inventory_stock_count_draft_test_skip_dotenv');
$app->initialize(); Config::set(['default' => 'file'], 'cache');

function draftCheck(string $name, bool $valid): void { global $failed; echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$valid) $failed++; }
function draftReason(callable $operation): string { try { $operation(); } catch (Throwable $error) { return $error->getMessage(); } return ''; }

$failed = 0;
$stock = Db::name('inventory_stock')->where('store_id', 99008)->where('consumable_product_id', 990081)->where('sku_id', 9900811)->find();
if (!$stock) throw new RuntimeException('inventory_draft_fixture_missing');
$book = (int)$stock['available_quantity_units'];
$factsBefore = (int)Db::name('inventory_batch_movement_fact')->where('store_id', 99008)->count();
$drafts = new InventoryStockCountDraftServices();
$line = ['product_id' => 990081, 'sku_id' => 9900811, 'sku_unique' => 'testsku9900811',
    'product_name' => 'TEST-盘点精华', 'sku_name' => '30ml', 'barcode' => '6909900100081',
    'book_quantity' => (string)$book, 'counted_quantity' => (string)($book + 1),
    'surplus_batch_no' => 'TEST-DRAFT-GAIN-' . time(), 'surplus_unit_cost' => '2.30',
    'surplus_manufactured_date' => '2026-06-07', 'surplus_expire_date' => '2027-07-07'];
$saved = $drafts->save(99008, 990008, ['draft_id' => 0, 'expected_version' => 0,
    'business_date' => '2026-09-28', 'remark' => 'TEST-盘点草稿', 'lines' => [$line]]);
$draftId = (int)$saved['draft_id'];
draftCheck('saving a draft does not change stock or settled movement facts',
    (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units') === $book
    && (int)Db::name('inventory_batch_movement_fact')->where('store_id', 99008)->count() === $factsBefore);
$detail = $drafts->detail(99008, 990008, $draftId);
draftCheck('draft detail restores entered quantity, batch and revision',
    $detail['version'] === 1 && $detail['lines'][0]['counted_quantity'] === (string)($book + 1)
    && $detail['lines'][0]['surplus_batch_no'] === $line['surplus_batch_no']);
$provider = (new ReflectionClass(InventoryCountUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
$source = new ReflectionMethod($provider, 'sourceRows'); $source->setAccessible(true);
$sourceRows = $source->invoke($provider, [
    'tenant_id' => (string)$stock['tenant_id'], 'store_id' => 99008, 'operator_id' => 990008,
    'organization_id' => '99008', 'scope_dimensions' => ['location_id' => [(int)$stock['location_id']]],
    'permissions' => [],
]);
draftCheck('creator sees an editable draft in the count list without a settled cost impact',
    count(array_filter($sourceRows, static fn(array $row): bool => (int)($row['draft_id'] ?? 0) === $draftId
        && (string)$row['status_name'] === 'DRAFT' && (int)$row['detail_count'] === 1
        && $row['change_amount_cents'] === null)) === 1);
draftCheck('another operator cannot read a creator-owned draft',
    draftReason(static fn() => $drafts->detail(99008, 990009, $draftId)) === 'inventory_stock_count_query_scope_denied');
$edited = $drafts->save(99008, 990008, ['draft_id' => $draftId, 'expected_version' => 1,
    'business_date' => '2026-09-28', 'remark' => 'TEST-已修改草稿', 'lines' => [$line]]);
draftCheck('stale editor revision cannot overwrite a newer draft',
    $edited['version'] === 2
    && draftReason(static fn() => $drafts->save(99008, 990008, ['draft_id' => $draftId, 'expected_version' => 1,
        'business_date' => '2026-09-28', 'remark' => 'TEST-过期编辑', 'lines' => [$line]])) === 'inventory_stock_count_draft_changed');

$command = ['idempotency_key' => 'count-draft-' . $draftId, 'draft_id' => $draftId, 'draft_version' => 2,
    'business_date' => '2026-09-28', 'remark' => 'TEST-已修改草稿',
    'lines' => [['product_id' => 990081, 'sku_id' => 9900811, 'sku_unique' => 'testsku9900811',
        'counted_quantity' => (string)($book + 1), 'surplus_batch_no' => $line['surplus_batch_no'],
        'surplus_unit_cost' => '2.30', 'surplus_manufactured_date' => '2026-06-07',
        'surplus_expire_date' => '2027-07-07', 'expected_book_quantity' => (string)$book]]];
$substituted = $command;
$substituted['lines'][0]['counted_quantity'] = (string)($book + 2);
draftCheck('confirmation cannot substitute a different count for the saved draft',
    draftReason(static fn() => (new InventoryStockCountServices())->confirm(99008, 990008, $substituted))
        === 'inventory_stock_count_draft_changed');
$confirmed = (new InventoryStockCountServices())->confirm(99008, 990008, $command);
$replay = (new InventoryStockCountServices())->confirm(99008, 990008, $command);
$storedDraft = Db::name('inventory_stock_count_draft')->where('id', $draftId)->find();
draftCheck('confirmation settles once and atomically closes the draft',
    (string)$storedDraft['document_status'] === 'CONFIRMED'
    && (int)$storedDraft['confirmed_document_id'] === (int)$confirmed['count_document_id']
    && (int)Db::name('inventory_batch_movement_fact')->where('store_id', 99008)->count() === $factsBefore + 1
    && $replay['idempotent'] === true && (int)$replay['count_document_id'] === (int)$confirmed['count_document_id']);
draftCheck('a confirmed draft cannot be edited again',
    draftReason(static fn() => $drafts->save(99008, 990008, ['draft_id' => $draftId, 'expected_version' => 2,
        'business_date' => '2026-09-28', 'remark' => '', 'lines' => [$line]])) === 'inventory_stock_count_draft_changed');

// 大批量已加载商品是可续编快照，不得被前端分页数或历史单据行数限制截断。
$bulkLines = [];
for ($i = 1; $i <= 1001; $i++) {
    $bulkLines[] = array_merge($line, ['sku_id' => 9901000 + $i, 'sku_unique' => 'test-draft-bulk-' . $i,
        'book_quantity' => '0', 'counted_quantity' => $i === 1 ? '1' : '0']);
}
$bulkSaved = $drafts->save(99008, 990008, ['draft_id' => 0, 'expected_version' => 0,
    'business_date' => '2026-09-28', 'remark' => 'TEST-全量商品草稿', 'lines' => $bulkLines]);
$bulkDetail = $drafts->detail(99008, 990008, (int)$bulkSaved['draft_id']);
draftCheck('draft persists all 1001 selected SKUs without turning unchanged rows into differences',
    count($bulkDetail['lines']) === 1001
    && (int)Db::name('inventory_stock_count_draft')->where('id', (int)$bulkSaved['draft_id'])->value('line_count') === 1);

echo "INVENTORY_STOCK_COUNT_DRAFT_RESULT failed={$failed}\n";
exit($failed ? 1 : 0);

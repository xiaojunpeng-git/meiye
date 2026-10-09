<?php
declare(strict_types=1);

use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\InventoryStockCountQueryServices;
use think\facade\Config;
use think\facade\Db;

/** 仅在白名单隔离库生成合成盘点，所有单据、草稿、库存和移动事实最终回滚。 */
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';
$database = getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730';
if ($database !== 'inventory_manual_inbound_test_20260730') throw new RuntimeException('isolated_test_database_required');
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach (['DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_DATABASE' => $database,
    'DATABASE_HOSTPORT' => '3306', 'CACHE_DRIVER' => 'file'] as $key => $value) $app->env->set($key, $value);
$property = new ReflectionProperty($app, 'envName');
$property->setAccessible(true); $property->setValue($app, 'zero_count_test_skip_dotenv');
$app->initialize(); Config::set(['default' => 'file'], 'cache');
if (Config::get('database.connections.mysql.database') !== $database) throw new RuntimeException('database_guard_failed');
$failed = 0;
function zeroCheck(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $failed++;
}
function zeroReason(callable $operation): string
{
    try { $operation(); } catch (Throwable $error) { return $error->getMessage(); }
    return '';
}
/** 比对整个库存与批次行（含版本/时间），不能只比较余额而漏掉无意义写入。 */
function zeroBalances(): array
{
    return [Db::name('inventory_stock')->order('id')->select()->toArray(),
        Db::name('inventory_batch')->order('id')->select()->toArray(),
        (int)Db::name('inventory_batch_movement_fact')->count()];
}
Db::startTrans();
try {
    $storeId = 99504; $productId = 995040; $operatorId = 9950401;
    $suffix = 'D4-zero-' . bin2hex(random_bytes(6));
    foreach (['system_store' => $storeId, 'organization' => $storeId, 'store_product' => $productId] as $table => $id) {
        if (Db::name($table)->where('id', $id)->find()) throw new RuntimeException('test_fixture_identity_collision');
    }
    if (!Db::name('organization')->where('id', 99000)->where('is_del', 0)->find()) throw new RuntimeException('test_parent_fixture_required');
    Db::name('system_store')->insert(['id' => $storeId, 'name' => 'TEST-零差异盘点', 'is_del' => 0, 'is_show' => 1]);
    Db::name('organization')->insert(['id' => $storeId, 'pid' => 99000, 'name' => 'TEST-零差异组织', 'is_del' => 0]);
    Db::name('organization_store')->insert(['store_id' => $storeId, 'org_id' => $storeId]);
    Db::name('inventory_location')->insert(['tenant_id' => '0', 'organization_id' => (string)$storeId,
        'organization_path' => '/99000/' . $storeId . '/', 'organization_name_snapshot' => 'TEST-零差异组织',
        'location_type' => 'STORE', 'owner_id' => $storeId, 'location_code' => $suffix, 'location_name' => 'TEST-默认仓',
        'store_id' => $storeId, 'store_name_snapshot' => 'TEST-零差异盘点', 'is_default' => 1,
        'location_status' => 'ACTIVE', 'version' => 1, 'created_at' => time(), 'updated_at' => time()]);
    Db::name('store_product')->insert(['id' => $productId, 'type' => 1, 'relation_id' => $storeId,
        'is_inventory' => 1, 'is_del' => 0, 'store_name' => 'TEST-零差异商品', 'salon_stock_enabled' => 0]);
    $lines = [];
    for ($i = 0; $i < 151; $i++) {
        $skuId = 995040000 + $i;
        if (Db::name('store_product_attr_value')->where('id', $skuId)->find()) throw new RuntimeException('test_sku_collision');
        $unique = $suffix . '-' . $i;
        Db::name('store_product_attr_value')->insert(['id' => $skuId, 'product_id' => $productId,
            'type' => 0, 'unique' => $unique, 'suk' => 'TEST规格' . $i, 'stock_unit' => '件']);
        $lines[] = ['product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $unique,
            'counted_quantity' => '0', 'surplus_batch_no' => '', 'surplus_unit_cost' => '',
            'surplus_manufactured_date' => '', 'surplus_expire_date' => '', 'expected_book_quantity' => '0'];
    }
    $session = ['id' => $operatorId, 'employee_id' => $operatorId, 'store_id' => $storeId,
        '_cashier_v3_organization' => true, 'session_mode' => 'store_organization', 'real_name' => 'TEST-盘点员',
        'read_only' => false, 'operation_features' => ['cashier.v3.inventory.count']];
    $counts = new InventoryStockCountServices(); $queries = new InventoryStockCountQueryServices();
    $confirm = static function (string $tag, array $submitted) use ($counts, $storeId, $operatorId, $session, $suffix): array {
        return $counts->confirm($storeId, $operatorId, ['idempotency_key' => $suffix . '-' . $tag,
            'business_date' => '2026-10-09', 'remark' => 'TEST-' . $tag, 'lines' => $submitted], $session);
    };
    $before = zeroBalances();
    $zero = $confirm('all-zero', $lines); $replay = $confirm('all-zero', $lines);
    zeroCheck('151 explicit zero rows create a confirmed count with all physical snapshots',
        Db::name('inventory_stock_count_document')->where('id', $zero['count_document_id'])->value('document_status') === 'CONFIRMED'
        && (int)Db::name('inventory_stock_count_line')->where('document_id', $zero['count_document_id'])->count() === 151
        && (int)Db::name('inventory_stock_count_line')->where('document_id', $zero['count_document_id'])->where('stock_id', 0)->count() === 151);
    zeroCheck('zero count leaves stocks batches versions and movements unchanged', zeroBalances() === $before);
    zeroCheck('zero count replay returns the original document only', $replay['idempotent'] === true && $replay['count_document_id'] === $zero['count_document_id']);
    $detail = $queries->detail($storeId, $operatorId, $zero['count_document_id'], true, $session);
    $hidden = $queries->detail($storeId, $operatorId, $zero['count_document_id'], false, $session);
    zeroCheck('count detail reads zero quantities units and zero adjustment amount without stock rows',
        count($detail['lines']) === 151 && $detail['lines'][0]['book_quantity'] === '0'
        && $detail['lines'][0]['counted_quantity'] === '0' && $detail['lines'][0]['difference_quantity'] === '0'
        && $detail['lines'][0]['stock_unit'] === '件' && $detail['lines'][0]['change_amount_cents'] === 0);
    zeroCheck('zero amount still respects cost permissions', $hidden['lines'][0]['change_amount_cents'] === null && $hidden['lines'][0]['surplus_unit_cost_cents'] === null);
    $list = $queries->list($storeId, $operatorId, '', 1, 20, true, $session);
    zeroCheck('confirmed zero count remains visible with 151 details and zero adjustment',
        $list['count'] === 1 && (int)$list['list'][0]['detail_count'] === 151 && $list['list'][0]['change_amount_cents'] === 0);
    // 当前页面及导出复用统一查询来源；“差异项”须为零，不能因保留了盘点明细而变成 151。
    $provider = (new ReflectionClass(\app\services\product\inventory\query\InventoryCountUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
    $source = new ReflectionMethod($provider, 'sourceRows'); $source->setAccessible(true);
    $context = ['tenant_id' => '0', 'store_id' => $storeId, 'operator_id' => $operatorId,
        'organization_id' => (string)$storeId, 'scope_dimensions' => ['location_id' => [
            (int)Db::name('inventory_location')->where('store_id', $storeId)->value('id')]], 'permissions' => []];
    $unifiedRows = array_column($source->invoke($provider, $context), null, 'order_sn');
    zeroCheck('unified list counts no difference items for 151 unchanged rows', (int)$unifiedRows[$zero['count_no']]['detail_count'] === 0);
    // 仓库名与门店名故意不同；优先快照，旧空快照才补读门店，不能显示当前筛选门店或默认仓名。
    zeroCheck('confirmed count exposes its store snapshot separately from warehouse name',
        $unifiedRows[$zero['count_no']]['store_name'] === 'TEST-零差异盘点'
        && $unifiedRows[$zero['count_no']]['location_name'] === 'TEST-默认仓');
    Db::name('system_store')->where('id', $storeId)->update(['name' => 'TEST-门店当前名']);
    $renamedRows = array_column($source->invoke($provider, $context), null, 'order_sn');
    zeroCheck('existing store snapshot is not replaced by a current store rename', $renamedRows[$zero['count_no']]['store_name'] === 'TEST-零差异盘点');
    Db::name('inventory_location')->where('id', $context['scope_dimensions']['location_id'][0])->update(['store_name_snapshot' => '']);
    $fallbackRows = array_column($source->invoke($provider, $context), null, 'order_sn');
    zeroCheck('legacy empty store snapshot resolves the warehouse owning store by ID', $fallbackRows[$zero['count_no']]['store_name'] === 'TEST-门店当前名');
    Db::name('system_store')->where('id', $storeId)->update(['name' => '']);
    $missingRows = array_column($source->invoke($provider, $context), null, 'order_sn');
    zeroCheck('missing store name never falls back to a warehouse name', $missingRows[$zero['count_no']]['store_name'] === '');
    Db::name('inventory_location')->where('id', $context['scope_dimensions']['location_id'][0])->update(['store_name_snapshot' => 'TEST-零差异盘点']);
    $metadata = \app\services\product\inventory\query\InventoryOperationalUnifiedQueryContract::definition('inventory_count');
    $storeField = array_values(array_filter($metadata['fields'], fn($field) => ($field['key'] ?? '') === 'store_name'));
    zeroCheck('unified count metadata names the store field in business language', count($storeField) === 1 && $storeField[0]['label'] === '门店名称');
    $conflict = $lines; $conflict[0]['counted_quantity'] = '1';
    zeroCheck('same idempotency key cannot change the zero count', zeroReason(fn() => $confirm('all-zero', $conflict)) === 'inventory_stock_count_idempotency_conflict');
    zeroCheck('empty submission cannot generate an empty count', zeroReason(fn() => $confirm('empty', [])) === 'inventory_stock_count_input_invalid');
    $readOnly = $session; $readOnly['read_only'] = true;
    zeroCheck('zero count cannot bypass read-only scope', zeroReason(fn() => $counts->confirm($storeId, $operatorId,
        ['idempotency_key' => $suffix . '-readonly', 'business_date' => '2026-10-09', 'remark' => '', 'lines' => [$lines[0]]], $readOnly)) !== '');

    // 先以真实盘盈建立两种规格；随后分别验证正数持平和混合盈亏，不能用零库存特例冒充覆盖。
    $gainLine = static function (array $line, string $quantity, string $batch): array {
        return array_merge($line, ['counted_quantity' => $quantity, 'surplus_batch_no' => $batch, 'surplus_unit_cost' => '2.30',
            'surplus_manufactured_date' => '2026-01-01', 'surplus_expire_date' => '2027-01-01']);
    };
    $confirm('seed', [$gainLine($lines[0], '2', 'TEST-A'), $gainLine($lines[2], '5', 'TEST-C')]);
    $same = array_merge($lines[0], ['counted_quantity' => '2', 'expected_book_quantity' => '2']);
    $before = zeroBalances(); $sameResult = $confirm('positive-same', [$same]);
    zeroCheck('positive unchanged stock confirms without batch metadata or stock writes', zeroBalances() === $before
        && (int)Db::name('inventory_stock_count_line')->where('document_id', $sameResult['count_document_id'])->value('book_quantity_units') === 2);
    $batchSame = array_merge($same, ['surplus_batch_no' => 'TEST-A', 'count_mode' => 'BATCH']);
    $before = zeroBalances(); $batchResult = $confirm('batch-same', [$batchSame]);
    $batchDetail = $queries->detail($storeId, $operatorId, $batchResult['count_document_id'], true, $session);
    zeroCheck('unchanged batch retains authoritative identity cost and dates without movement', zeroBalances() === $before
        && $batchDetail['lines'][0]['surplus_batch_no'] === 'TEST-A'
        && (int)$batchDetail['lines'][0]['surplus_unit_cost_cents'] === 230
        && $batchDetail['lines'][0]['surplus_manufactured_date'] === '2026-01-01');
    $mixed = $confirm('mixed', [$same, $gainLine($lines[1], '4', 'TEST-B'),
        array_merge($lines[2], ['counted_quantity' => '3']), $lines[3]]);
    $mixedLines = Db::name('inventory_stock_count_line')->where('document_id', $mixed['count_document_id'])->order('line_no')->select()->toArray();
    zeroCheck('mixed count keeps all four rows but creates movements for gain and loss only', count($mixedLines) === 4
        && array_map('intval', array_column($mixedLines, 'difference_quantity_units')) === [0, 4, -2, 0]
        && (int)Db::name('inventory_batch_movement_fact')->where('source_id', $mixed['count_no'])->count() === 2);
    zeroCheck('mixed count never creates stock for its zero-zero row', !Db::name('inventory_stock')->where('sku_id', $lines[3]['sku_id'])->find());
    $unifiedRows = array_column($source->invoke($provider, $context), null, 'order_sn');
    zeroCheck('unified mixed list counts the two adjusted rows only', (int)$unifiedRows[$mixed['count_no']]['detail_count'] === 2);
    // 旧快照持平不代表当前持平；缺盘盈资料必须整单回滚，已处理的零差异行也不能残留。
    $before = zeroBalances(); $documentsBefore = (int)Db::name('inventory_stock_count_document')->count();
    $stale = array_merge($lines[0], ['counted_quantity' => '3', 'expected_book_quantity' => '3']);
    zeroCheck('stale equality becoming a gain still requires authoritative batch metadata',
        zeroReason(fn() => $confirm('stale-gain', [$lines[3], $stale])) === 'inventory_stock_count_surplus_batch_required'
        && zeroBalances() === $before && (int)Db::name('inventory_stock_count_document')->count() === $documentsBefore);
    $staleLoss = array_merge($lines[1], ['counted_quantity' => '3', 'expected_book_quantity' => '3']);
    $staleResult = $confirm('stale-loss', [$staleLoss]);
    zeroCheck('stale equality becoming a loss settles against locked current balance',
        (int)Db::name('inventory_stock_count_line')->where('document_id', $staleResult['count_document_id'])->value('book_quantity_units') === 4
        && (int)Db::name('inventory_stock_count_line')->where('document_id', $staleResult['count_document_id'])->value('difference_quantity_units') === -1);

    // 草稿含一条明确零实盘、一条空白未盘；确认不得把空白偷换成零，也不得漏掉明确零。
    $draftLines = [];
    foreach ([$lines[3], array_merge($lines[4], ['counted_quantity' => ''])] as $entered) {
        $draftLine = $entered; unset($draftLine['expected_book_quantity']); $draftLine['book_quantity'] = '0';
        $draftLine['product_name'] = 'TEST-零差异商品'; $draftLine['sku_name'] = 'TEST规格'; $draftLine['stock_unit'] = '件';
        $draftLines[] = $draftLine;
    }
    $drafts = new InventoryStockCountDraftServices();
    $saved = $drafts->save($storeId, $operatorId, ['draft_id' => 0, 'expected_version' => 0,
        'business_date' => '2026-10-09', 'remark' => 'TEST-零差异草稿', 'lines' => $draftLines], $session);
    $draftContext = array_merge($context, ['inventory_count_operator_type' => 'EMPLOYEE', 'inventory_count_creator_id' => $operatorId]);
    $draftRows = array_values(array_filter($source->invoke($provider, $draftContext), fn($row) => (int)($row['draft_id'] ?? 0) === $saved['draft_id']));
    zeroCheck('own count draft exposes the same authoritative store name', count($draftRows) === 1 && $draftRows[0]['store_name'] === 'TEST-零差异盘点');
    $draftCommand = ['idempotency_key' => 'count-draft-' . $saved['draft_id'], 'draft_id' => $saved['draft_id'], 'draft_version' => 1,
        'business_date' => '2026-10-09', 'remark' => 'TEST-零差异草稿', 'lines' => [$lines[3]]];
    $forged = $draftCommand; $forged['lines'][] = $lines[4];
    zeroCheck('draft rejects converting uncounted blank into explicit zero', zeroReason(fn() => $counts->confirm($storeId, $operatorId, $forged, $session)) === 'inventory_stock_count_draft_changed');
    $before = zeroBalances();
    $confirmed = $counts->confirm($storeId, $operatorId, $draftCommand, $session);
    $draftReplay = $counts->confirm($storeId, $operatorId, $draftCommand, $session);
    zeroCheck('zero draft closes atomically with one counted row and idempotent replay', zeroBalances() === $before
        && $draftReplay['idempotent'] === true && $draftReplay['count_document_id'] === $confirmed['count_document_id']
        && Db::name('inventory_stock_count_draft')->where('id', $saved['draft_id'])->value('document_status') === 'CONFIRMED'
        && (int)Db::name('inventory_stock_count_line')->where('document_id', $confirmed['count_document_id'])->count() === 1);
} catch (Throwable $error) {
    $failed++; echo 'FAIL unexpected exception: ' . $error->getMessage() . "\n";
} finally { Db::rollback(); echo "ROLLBACK all zero-difference count fixtures\n"; }
zeroCheck('no synthetic count documents survive rollback', (int)Db::name('inventory_stock_count_document')->whereLike('idempotency_key', $suffix . '%')->count() === 0);
echo "ZERO_DIFFERENCE_COUNT_RESULT failed=$failed\n";
exit($failed ? 1 : 0);

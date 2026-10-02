<?php
declare(strict_types=1);

/** 总部盘点草稿回归仅运行在隔离测试库，所有夹具和库存事件最终回滚。 */
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';
use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\query\InventoryCountUnifiedQueryProvider;
use think\facade\Db;
use think\facade\Config;
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$database = getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730';
if ($database !== 'inventory_manual_inbound_test_20260730') throw new RuntimeException('isolated_test_database_required');
foreach (['DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_DATABASE' => $database,
    'DATABASE_HOSTPORT' => '3306', 'CACHE_DRIVER' => 'file'] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName'); $envName->setAccessible(true); $envName->setValue($app, 'hq_draft_test_skip_dotenv');
$app->initialize(); Config::set(['default' => 'file'], 'cache');
if (Config::get('database.connections.mysql.database') !== $database) throw new RuntimeException('database_guard_failed');
// 精简库存夹具不含后台账号表；只在白名单隔离库补齐权限解析所需的测试表。
Db::execute("CREATE TABLE IF NOT EXISTS eb_system_admin (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account VARCHAR(64) NOT NULL DEFAULT '', pwd VARCHAR(128) NOT NULL DEFAULT '', real_name VARCHAR(128) NOT NULL DEFAULT '',
    level INT NOT NULL DEFAULT 1, status INT NOT NULL DEFAULT 1, is_del INT NOT NULL DEFAULT 0,
    admin_type INT NOT NULL DEFAULT 0, roles VARCHAR(128) NOT NULL DEFAULT '') ENGINE=InnoDB");
// 精简夹具同步 R57 已有审计字段；这是测试库结构准备，不是新业务迁移。
foreach (['eb_inventory_stock_count_document' => ['operator_name_snapshot' => "varchar(128) NOT NULL DEFAULT ''"],
    'eb_inventory_batch_movement_fact' => ['operator_type' => "varchar(32) NOT NULL DEFAULT ''", 'operator_id' => 'bigint unsigned NOT NULL DEFAULT 0',
        'operator_name_snapshot' => "varchar(128) NOT NULL DEFAULT ''"]] as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if (!Db::query("SHOW COLUMNS FROM `$table` LIKE '$column'")) Db::execute("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
$failed = 0;
function hqCheck(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }
function hqReason(callable $operation): string { try { $operation(); } catch (Throwable $error) { return $error->getMessage(); } return ''; }

Db::startTrans();
try {
    // 权限 API 的幂等登记在同一隔离事务内执行两次，不能新增角色或重复菜单。
    $migration = file_get_contents($backend . '/database/upgrades/2026-10-02-总部盘点草稿入口/02-正式升级.sql');
    $migration = preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m', '', $migration);
    for ($attempt = 0; $attempt < 2; $attempt++) foreach (explode(';', $migration) as $statement) if (trim($statement) !== '') Db::execute($statement);
    hqCheck('HQ draft API registration is idempotent and retains warehouse management authority', (int)Db::name('system_menus')
        ->whereIn('api_url', ['product/inventory/v3/hq/count/draft', 'product/inventory/v3/hq/count/draft/:id', 'product/inventory/v3/hq/count/draft/:id/delete'])
        ->where('auth_type', 2)->where('unique_auth', 'inventory-v3-platform-warehouse-manage')->where('is_del', 0)->count() === 3);
    $suffix = 'r58-' . getmypid() . '-' . time();
    $adminId = (int)Db::name('system_admin')->insertGetId(['account' => $suffix, 'pwd' => '', 'real_name' => 'TEST-总部盘点', 'level' => 0, 'status' => 1, 'is_del' => 0]);
    $otherId = (int)Db::name('system_admin')->insertGetId(['account' => $suffix . '-other', 'pwd' => '', 'level' => 0, 'status' => 1, 'is_del' => 0]);
    $admin = ['id' => $adminId, 'real_name' => 'TEST-总部盘点'];
    $location = Db::name('inventory_location')->where('location_type', 'HQ')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
    if (!$location) {
        $locationId = (int)Db::name('inventory_location')->insertGetId(['tenant_id' => '0', 'organization_id' => '99458',
            'organization_path' => '/99458/', 'organization_name_snapshot' => 'TEST-总部', 'location_type' => 'HQ', 'owner_id' => 99458,
            'location_code' => $suffix, 'location_name' => 'TEST-总部仓', 'store_id' => 0, 'store_name_snapshot' => '',
            'is_default' => 1, 'location_status' => 'ACTIVE', 'version' => 1, 'created_at' => time(), 'updated_at' => time()]);
        $location = Db::name('inventory_location')->where('id', $locationId)->find();
    }
    $locationId = (int)$location['id'];
    $productId = 994580; $skuId = 9945801;
    if (Db::name('store_product')->where('id', $productId)->find() || Db::name('store_product_attr_value')->where('id', $skuId)->find()) throw new RuntimeException('test_fixture_identity_collision');
    Db::name('store_product')->insert(['id' => $productId, 'type' => 0, 'relation_id' => 0, 'is_inventory' => 1, 'is_del' => 0, 'store_name' => 'TEST-总部草稿', 'code' => $suffix]);
    Db::name('store_product_attr_value')->insert(['id' => $skuId, 'product_id' => $productId, 'type' => 0, 'unique' => $suffix, 'suk' => '默认', 'stock_unit' => '瓶']);
    $line = ['product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $suffix, 'product_name' => 'TEST-总部草稿',
        'sku_name' => '默认', 'stock_unit' => '瓶', 'barcode' => '', 'book_quantity' => '0', 'counted_quantity' => '2',
        'surplus_batch_no' => $suffix, 'surplus_unit_cost' => '2.30', 'surplus_manufactured_date' => '2026-01-01', 'surplus_expire_date' => '2027-01-01'];
    $input = ['draft_id' => 0, 'expected_version' => 0, 'business_date' => '2026-10-02', 'remark' => 'TEST-HQ草稿', 'lines' => [$line]];
    $drafts = new InventoryStockCountDraftServices(); $counts = new InventoryStockCountServices();
    $factsBefore = (int)Db::name('inventory_batch_movement_fact')->count();
    $saved = $drafts->saveForHeadquarters($admin, $locationId, $input); $draftId = (int)$saved['draft_id'];
    $detail = $drafts->detailForHeadquarters($admin, $locationId, $draftId);
    hqCheck('HQ draft restores entered fields and remains outside settled stock', $detail['version'] === 1 && $detail['lines'][0]['counted_quantity'] === '2'
        && (int)Db::name('inventory_batch_movement_fact')->count() === $factsBefore
        && !Db::name('inventory_stock')->where('consumable_product_id', $productId)->find());
    hqCheck('another authenticated administrator cannot read the creator draft', hqReason(fn() => $drafts->detailForHeadquarters(['id' => $otherId], $locationId, $draftId)) === 'inventory_stock_count_draft_missing');
    hqCheck('unresolved administrator cannot save a headquarters draft', hqReason(fn() => $drafts->saveForHeadquarters(['id' => 0], $locationId, $input)) !== '');
    hqCheck('unauthorized warehouse cannot be substituted', hqReason(fn() => $drafts->detailForHeadquarters($admin, 2147483647, $draftId)) === 'inventory_hq_location_scope_denied');
    $provider = (new ReflectionClass(InventoryCountUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
    $source = new ReflectionMethod($provider, 'sourceRows'); $source->setAccessible(true);
    $context = ['tenant_id' => (string)$location['tenant_id'], 'store_id' => 0, 'operator_id' => $adminId,
        'organization_id' => 'platform', 'inventory_count_operator_type' => 'PLATFORM_ADMIN', 'inventory_count_creator_id' => $adminId,
        'scope_dimensions' => ['location_id' => [$locationId]], 'permissions' => []];
    $rows = $source->invoke($provider, $context);
    hqCheck('HQ list includes own draft without inventing settled cost', count(array_filter($rows, fn($row) => (int)($row['draft_id'] ?? 0) === $draftId && $row['change_amount_cents'] === null)) === 1);
    $context['inventory_count_creator_id'] = $otherId;
    hqCheck('HQ list excludes another creator draft', count(array_filter($source->invoke($provider, $context), fn($row) => (int)($row['draft_id'] ?? 0) === $draftId)) === 0);
    $input['draft_id'] = $draftId; $input['expected_version'] = 1;
    $edited = $drafts->saveForHeadquarters($admin, $locationId, $input);
    hqCheck('stale draft revision cannot overwrite or delete the new revision', $edited['version'] === 2
        && hqReason(fn() => $drafts->saveForHeadquarters($admin, $locationId, $input)) === 'inventory_stock_count_draft_changed'
        && hqReason(fn() => $drafts->deleteForHeadquarters($admin, $locationId, $draftId, 1)) === 'inventory_stock_count_draft_changed');
    $commandLine = array_intersect_key($line, array_flip(['product_id', 'sku_id', 'sku_unique', 'counted_quantity', 'surplus_batch_no', 'surplus_unit_cost', 'surplus_manufactured_date', 'surplus_expire_date']));
    $commandLine['expected_book_quantity'] = '0';
    $command = ['idempotency_key' => 'count-draft-' . $draftId, 'draft_id' => $draftId, 'draft_version' => 2,
        'business_date' => '2026-10-02', 'remark' => 'TEST-HQ草稿', 'lines' => [$commandLine]];
    $changed = $command; $changed['lines'][0]['counted_quantity'] = '3';
    hqCheck('HQ confirmation rejects client-substituted draft quantities', hqReason(fn() => $counts->confirmForHeadquarters($admin, $locationId, $changed)) === 'inventory_stock_count_draft_changed');
    $confirmed = $counts->confirmForHeadquarters($admin, $locationId, $command);
    $replay = $counts->confirmForHeadquarters($admin, $locationId, $command);
    $stock = Db::name('inventory_stock')->where('consumable_product_id', $productId)->where('location_id', $locationId)->find();
    hqCheck('HQ confirmation settles once and atomically closes its draft', !empty($replay['idempotent']) && $confirmed['count_document_id'] === $replay['count_document_id']
        && (int)$stock['available_quantity_units'] === 2 && (int)Db::name('inventory_batch_movement_fact')->count() === $factsBefore + 1
        && Db::name('inventory_stock_count_draft')->where('id', $draftId)->value('document_status') === 'CONFIRMED');
    hqCheck('confirmed headquarters draft cannot be deleted', hqReason(fn() => $drafts->deleteForHeadquarters($admin, $locationId, $draftId, 3)) !== '');
    // 实盘等于旧账面 1，但当前库存为 2：不能跳过，须按当前库存生成盘亏 1。
    $physicalLine = $commandLine;
    $physicalLine['counted_quantity'] = '1'; $physicalLine['expected_book_quantity'] = '1';
    $physicalCommand = ['idempotency_key' => 'TEST-HQ-physical-current-stock', 'business_date' => '2026-10-02',
        'remark' => 'TEST-实盘覆盖当前数', 'lines' => [$physicalLine]];
    $physical = $counts->confirmForHeadquarters($admin, $locationId, $physicalCommand);
    $physicalReplay = $counts->confirmForHeadquarters($admin, $locationId, $physicalCommand);
    $physicalFact = Db::name('inventory_stock_count_line')->where('document_id', (int)$physical['count_document_id'])->find();
    hqCheck('physical equal to stale book settles current inventory once', (int)$physicalFact['book_quantity_units'] === 2
        && (int)$physicalFact['counted_quantity_units'] === 1 && (int)$physicalFact['difference_quantity_units'] === -1
        && !empty($physicalReplay['idempotent'])
        && (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units') === 1);
    // 同规格三批次：分别结算所盘批次，未列出的第三批次必须保持原数量。
    $batchB = $commandLine; $batchB['count_mode'] = 'BATCH'; $batchB['surplus_batch_no'] = 'TEST-HQ-B';
    $batchB['counted_quantity'] = '2';
    $batchC = $batchB; $batchC['surplus_batch_no'] = 'TEST-HQ-C'; $batchC['counted_quantity'] = '3';
    $batchCommand = ['idempotency_key' => 'TEST-HQ-batch-seed', 'business_date' => '2026-10-02', 'remark' => 'TEST-批次', 'lines' => [$batchB, $batchC]];
    $counts->confirmForHeadquarters($admin, $locationId, $batchCommand);
    $batchA = $commandLine; $batchA['count_mode'] = 'BATCH'; $batchA['counted_quantity'] = '0';
    $batchB['counted_quantity'] = '1';
    $batchCommand['idempotency_key'] = 'TEST-HQ-batch-loss'; $batchCommand['lines'] = [$batchA, $batchB];
    $batchLoss = $counts->confirmForHeadquarters($admin, $locationId, $batchCommand);
    $batchReplay = $counts->confirmForHeadquarters($admin, $locationId, $batchCommand);
    $balances = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->column('available_quantity_units', 'batch_no');
    hqCheck('multi-batch physical count targets each batch and preserves uncounted stock', (int)$balances[$commandLine['surplus_batch_no']] === 0
        && (int)$balances['TEST-HQ-B'] === 1 && (int)$balances['TEST-HQ-C'] === 3
        && (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units') === 4
        && !empty($batchReplay['idempotent']) && (int)Db::name('inventory_stock_count_line')->where('document_id', (int)$batchLoss['count_document_id'])->count() === 2);
    $batchCommand['idempotency_key'] = 'TEST-HQ-batch-duplicate'; $batchCommand['lines'] = [$batchB, $batchB];
    hqCheck('duplicate batch is rejected before settlement', hqReason(fn() => $counts->confirmForHeadquarters($admin, $locationId, $batchCommand)) === 'inventory_stock_count_duplicate_sku');
    $batchCommand['idempotency_key'] = 'TEST-HQ-batch-mixed'; $batchCommand['lines'] = [$commandLine, $batchB];
    hqCheck('mixing SKU total and batch counts is rejected', hqReason(fn() => $counts->confirmForHeadquarters($admin, $locationId, $batchCommand)) === 'inventory_stock_count_mixed_granularity');
    $batchCatalog = new \app\services\product\inventory\InventoryCountBatchCatalog();
    $catalogRows = [['product_id' => $productId, 'sku_id' => $skuId]];
    $visible = $batchCatalog->attach($catalogRows, (string)$location['tenant_id'], $locationId, true);
    $hidden = $batchCatalog->attach($catalogRows, (string)$location['tenant_id'], $locationId, false);
    $outside = $batchCatalog->attach($catalogRows, (string)$location['tenant_id'], $locationId + 999999, true);
    hqCheck('current batch import carries quantity dates and protects costs and warehouse scope', count($visible[0]['count_batches']) === 2
        && $visible[0]['count_batches'][0]['quantity'] === '1' && $visible[0]['count_batches'][0]['unit_cost'] !== null
        && $hidden[0]['count_batches'][0]['unit_cost'] === null && $outside[0]['count_batches'] === []);
    $batchB['counted_quantity'] = '2';
    $batchDraftLines = [];
    foreach ([$batchB, $batchC] as $entered) {
        $batchDraftLines[] = array_merge($line, ['count_mode' => 'BATCH', 'surplus_batch_no' => $entered['surplus_batch_no'],
            'book_quantity' => '0', 'counted_quantity' => $entered['counted_quantity']]);
    }
    $batchDraft = $drafts->saveForHeadquarters($admin, $locationId, ['draft_id' => 0, 'expected_version' => 0,
        'business_date' => '2026-10-02', 'remark' => 'TEST-多批次草稿', 'lines' => $batchDraftLines]);
    $restoredBatches = $drafts->detailForHeadquarters($admin, $locationId, (int)$batchDraft['draft_id']);
    hqCheck('multi-batch draft retains both batch identities and physical quantities', count($restoredBatches['lines']) === 2
        && $restoredBatches['lines'][0]['count_mode'] === 'BATCH' && $restoredBatches['lines'][1]['surplus_batch_no'] === 'TEST-HQ-C');
    $batchB['counted_quantity'] = '2';
    $batchB['expected_book_quantity'] = '0'; $batchC['expected_book_quantity'] = '0';
    $batchDraftCommand = ['idempotency_key' => 'count-draft-' . $batchDraft['draft_id'], 'draft_id' => (int)$batchDraft['draft_id'],
        'draft_version' => 1, 'business_date' => '2026-10-02', 'remark' => 'TEST-多批次草稿', 'lines' => [$batchB, $batchC]];
    $counts->confirmForHeadquarters($admin, $locationId, $batchDraftCommand);
    hqCheck('multi-batch draft confirms and replays with saved identities', !empty($counts->confirmForHeadquarters($admin, $locationId, $batchDraftCommand)['idempotent']));
    $input['draft_id'] = 0; $input['expected_version'] = 0;
    $disposable = $drafts->saveForHeadquarters($admin, $locationId, $input);
    $drafts->deleteForHeadquarters($admin, $locationId, (int)$disposable['draft_id'], 1);
    hqCheck('creator can delete only an unconfirmed matching revision', !Db::name('inventory_stock_count_draft')->where('id', (int)$disposable['draft_id'])->find());
    // 共享结算辅助方法必须保留门店组织身份路径，不能把门店草稿变为平台草稿。
    $storeProduct = 994581; $storeSku = 9945811;
    if (Db::name('store_product')->where('id', $storeProduct)->find() || Db::name('store_product_attr_value')->where('id', $storeSku)->find()) throw new RuntimeException('store_fixture_identity_collision');
    Db::name('store_product')->insert(['id' => $storeProduct, 'type' => 1, 'relation_id' => 99008, 'is_inventory' => 1, 'is_del' => 0, 'store_name' => 'TEST-门店回归']);
    Db::name('store_product_attr_value')->insert(['id' => $storeSku, 'product_id' => $storeProduct, 'type' => 0, 'unique' => $suffix . '-store', 'suk' => '默认', 'stock_unit' => '瓶']);
    $session = ['id' => 880058, 'employee_id' => 990058, 'store_id' => 99008, '_cashier_v3_organization' => 1,
        'session_mode' => 'store_organization', 'real_name' => 'TEST-门店员工', 'read_only' => false, 'operation_features' => ['cashier.v3.inventory.count']];
    $storeLine = array_merge($line, ['product_id' => $storeProduct, 'sku_id' => $storeSku, 'sku_unique' => $suffix . '-store']);
    $storeInput = array_merge($input, ['lines' => [$storeLine]]);
    $storeDraft = $drafts->save(99008, 880058, $storeInput, $session);
    hqCheck('store organization draft retains stable employee identity', $drafts->detail(99008, 880058, (int)$storeDraft['draft_id'], $session)['lines'][0]['sku_id'] === $storeSku
        && Db::name('inventory_stock_count_draft')->where('id', (int)$storeDraft['draft_id'])->value('operator_type') === 'EMPLOYEE');
    $storeCommand = array_merge($command, ['idempotency_key' => 'count-draft-' . $storeDraft['draft_id'], 'draft_id' => (int)$storeDraft['draft_id'], 'draft_version' => 1,
        'lines' => [array_merge($commandLine, ['product_id' => $storeProduct, 'sku_id' => $storeSku, 'sku_unique' => $suffix . '-store'])]]);
    $storeConfirm = $counts->confirm(99008, 880058, $storeCommand, $session);
    $storeReplay = $counts->confirm(99008, 880058, $storeCommand, $session);
    hqCheck('store draft confirms and replays through the same authority without regression', $storeReplay['idempotent'] === true
        && $storeConfirm['count_document_id'] === $storeReplay['count_document_id']
        && (int)Db::name('inventory_stock')->where('consumable_product_id', $storeProduct)->value('available_quantity_units') === 2);
} catch (Throwable $error) {
    // 框架的 CLI 异常处理可能返回 0；测试必须明确以失败码退出，不能把夹具异常当通过。
    $failed++; echo 'FAIL unexpected exception: ' . $error->getMessage() . "\n";
} finally { Db::rollback(); echo "ROLLBACK all headquarters test fixtures and stock events\n"; }
echo "HQ_COUNT_DRAFT_RESULT failed=$failed\n";
exit($failed ? 1 : 0);

<?php
/** Database-free contract for legacy catalog writer compatibility locks. */

$backendRoot = getenv('C2_CATALOG_WRITER_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && $backendRoot !== ''
    ? rtrim($backendRoot, '/')
    : __DIR__ . '/../../../后端代码';
$sourceRoot = $backendRoot . '/app';

require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceKindCatalog.php';

$passed = 0;
$failed = 0;

function writerAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : " {$detail}") . "\n";
}

function writerSource(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    return is_string($source) ? $source : '';
}

$guard = writerSource($sourceRoot, 'services/product/product/StoreCatalogWriteLockGuard.php');
$lease = writerSource($sourceRoot, 'services/product/product/StoreCatalogWriteLease.php');
$related = writerSource($sourceRoot, 'services/product/product/StoreCardRelatedServices.php');
$product = writerSource($sourceRoot, 'services/product/product/StoreProductServices.php');
$branch = writerSource($sourceRoot, 'services/product/branch/StoreBranchProductServices.php');
$branchAttr = writerSource($sourceRoot, 'services/product/branch/StoreBranchProductAttrValueServices.php');
$attr = writerSource($sourceRoot, 'services/product/sku/StoreProductAttrServices.php');
$attrValue = writerSource($sourceRoot, 'services/product/sku/StoreProductAttrValueServices.php');
$attrResult = writerSource($sourceRoot, 'services/product/sku/StoreProductAttrResultServices.php');
$import = writerSource($sourceRoot, 'services/other/Import/ImportRecordServices.php');
$erp = writerSource($sourceRoot, 'jobs/product/ProductSyncErp.php');
$syncJob = writerSource($sourceRoot, 'jobs/product/ProductSyncStoreJob.php');
$relatedJob = writerSource($sourceRoot, 'jobs/product/ProductRelatedJob.php');
$stockJob = writerSource($sourceRoot, 'jobs/product/ProductStockTips.php');
$clear = writerSource($sourceRoot, 'services/system/SystemClearServices.php');
$migrate = writerSource($sourceRoot, 'command/Migrate.php');
$readiness = writerSource($sourceRoot, 'services/cashier/v3/cashier/CashierV3CashierReadinessGuard.php');
$migration = writerSource($backendRoot, 'database/upgrades/2026-07-29-收银V3销售购物车权威行/02-正式升级.sql');
$migrationManifest = writerSource($backendRoot, 'database/upgrades/2026-07-29-收银V3销售购物车权威行/00-升级清单.md');

$cardLock = strpos($guard, "lockProductRows('catalog_card_definition'");
$productLock = strpos($guard, "lockProductRows('catalog_product'");
$skuLock = strpos($guard, 'lockSkuRows($skuIds)');

writerAssert('WRITER-GUARD-01 single guard and transaction lease exist',
    $guard !== '' && $lease !== ''
    && strpos($guard, 'withCatalogMutation') !== false
    && strpos($lease, 'Transaction-scoped proof') !== false);
writerAssert('WRITER-GUARD-02 exact global order is card then product then SKU',
    $cardLock !== false && $productLock !== false && $skuLock !== false
    && $cardLock < $productLock && $productLock < $skuLock);
writerAssert('WRITER-GUARD-03 same-kind ordering reuses the cashier comparator',
    strpos($guard, 'CashierV3ResourceKindCatalog::compareResources') !== false
    && \app\services\cashier\v3\CashierV3ResourceKindCatalog::compareResourceIds('2', '10') < 0);
writerAssert('WRITER-GUARD-04 resource locks are exact primary-key loops',
    strpos($guard, "->where('id', \$id)->lock(true)") !== false
    && strpos($guard, "->whereIn('id', \$ids)->lock(true)") === false);
writerAssert('WRITER-GUARD-05 low-order drift rolls back and retries',
    strpos($guard, 'StoreCatalogWritePlanChangedException') !== false
    && strpos($guard, 'MAX_PLAN_ATTEMPTS = 3') !== false
    && strpos($guard, "afterProductLock['cardIds']") !== false);
writerAssert('WRITER-GUARD-06 duplicate product plus unique identity fails closed',
    strpos($guard, 'assertNoDuplicateSkuIdentity') !== false
    && strpos($guard, '重复 SKU identity') !== false);
writerAssert('WRITER-GUARD-07 public guard refuses an inherited transaction',
    strpos($guard, 'assertNotInTransaction') !== false
    && strpos($guard, '必须拥有最外层事务') !== false
    && strpos($lease, 'inTransaction()') !== false
    && strpos($lease, '$connection->getPdo()') !== false
    && strpos($lease, "method_exists(\$connection, 'getPdo')") === false);
writerAssert('WRITER-GUARD-08 component reverse discovery participates in planning',
    strpos($guard, "whereIn('product_id'") !== false
    && strpos($guard, "column('card_product_id')") !== false);
writerAssert('WRITER-GUARD-09 new card creation pre-locks the existing component graph',
    strpos($guard, 'withNewProductCreation(callable $write, array $targetRefs = [])') !== false
    && strpos($guard, 'return $this->withCatalogMutation([], [], $targetRefs, $write);') !== false
    && strpos($product, 'withNewProductCreation($catalogWrite, $targetRefs)') !== false);

writerAssert('WRITER-REL-01 replace remap status append and delete require a lease',
    strpos($related, 'handleCardRelatedInGuard') !== false
    && strpos($related, 'updateCardProductInGuard') !== false
    && strpos($related, 'setStatusInGuard') !== false
    && strpos($related, 'appendCardRelatedRows') !== false
    && strpos($related, 'deleteForProductsInGuard') !== false);
writerAssert('WRITER-REL-02 inherited raw relation writers are blocked',
    strpos($related, "['save', 'saveAll', 'update', 'delete', 'insert', 'insertAll']") !== false
    && strpos($related, '卡项关联写入必须使用目录写锁守卫') !== false);
writerAssert('WRITER-SKU-01 type-zero SKU save path is split into public guard and leased body',
    strpos($attr, 'withComponentCatalogMutation') !== false
    && strpos($attr, 'saveProductAttrInGuard') !== false
    && strpos($attrValue, 'saveAllInGuard') !== false
    && strpos($attrValue, 'updateInGuard') !== false
    && strpos($attrValue, 'deleteInGuard') !== false);
writerAssert('WRITER-SKU-02 deletion checks consume the existing lease instead of locking SKU first',
    strpos($attrValue, 'assertSkusCanBeDeleted') !== false
    && strpos($attrValue, 'assertCoversSkuIds') !== false
    && strpos(substr($attrValue, strpos($attrValue, 'public function assertSkusCanBeDeleted'), 6500), '->lock(true)') === false);
writerAssert('WRITER-SKU-03 newly inserted identities are registered before relation remap',
    strpos($lease, 'registerWrittenSkuRows') !== false
    && strpos($attrValue, '$lease->registerWrittenSkuRows') !== false);
writerAssert('WRITER-SKU-03A transitions into type zero pre-lock source and target products',
    strpos($attrValue, '$touchesCatalogIdentity') !== false
    && strpos($attrValue, "array_key_exists('type', \$data)") !== false
    && strpos($attrValue, "\$data['product_id'] ?? \$row['product_id']") !== false
    && strpos($attrValue, "whereIn('id', array_column(\$rows, 'id'))") !== false);
writerAssert('WRITER-SKU-04 legacy branch attr write surface is fail-closed',
    strpos($branchAttr, '旧门店 SKU 写入口已停用') !== false
    && strpos($erp, 'StoreBranchProductAttrValueServices') === false);
writerAssert('WRITER-SKU-05 read-triggered default SKU repair is guarded atomically',
    strpos($attrResult, 'withComponentCatalogMutation') !== false
    && strpos($attrResult, '$lockedProductInfo = $storeProductServices->get($id)') !== false
    && strpos($attrResult, 'saveProductAttrInGuard') !== false
    && strpos($attrResult, "['spec_type' => 0]") !== false);

writerAssert('WRITER-CALL-01 product save status and physical delete share the outer lease',
    strpos($product, 'withNewProductCreation') !== false
    && strpos($product, 'withCatalogMutation') !== false
    && strpos($product, 'setShowInGuard') !== false
    && strpos($product, 'deleteForProductsInGuard') !== false);
writerAssert('WRITER-CALL-02 branch sync and delete share the outer lease',
    strpos($branch, 'withCatalogMutation') !== false
    && strpos($branch, 'adoptCreatedProduct') !== false
    && strpos($branch, 'saveAllInGuard') !== false
    && strpos($branch, 'deleteForProductsInGuard') !== false);
writerAssert('WRITER-CALL-02A branch sync re-plans stale source and target snapshots',
    strpos($branch, 'StoreBranchProductSyncPlanChangedException') !== false
    && strpos($branch, 'syncProductAttempt') !== false
    && strpos($branch, '$currentBranchProductInfo = $this->dao->get') !== false
    && strpos($branch, "assertSyncSnapshotUnchanged('平台商品 SKU'") !== false
    && strpos($branch, "assertSyncSnapshotUnchanged('平台卡项权益'") !== false);
writerAssert('WRITER-CALL-03 ERP branch sync uses the canonical guarded service',
    strpos($erp, 'return $branchProductServices->syncProduct') !== false
    && strpos($erp, 'branchProductAttrServices->saveAll') === false);
writerAssert('WRITER-CALL-03A ERP creates product and initial SKU in one guarded transaction',
    strpos($erp, 'withNewProductCreation') !== false
    && strpos($erp, 'createErpProductInGuard') !== false
    && strpos($erp, 'saveProductAttrInGuard') !== false
    && strpos($erp, '->ErpProductSave(') === false
    && strpos($product, "if (\$name === 'ErpProductSave')") !== false);
writerAssert('WRITER-CALL-04 import no longer writes relation DAO directly',
    strpos($import, 'StoreCardRelatedDao') === false
    && strpos($import, 'appendCardRelatedRows') !== false);
writerAssert('WRITER-CALL-05 queue writers rethrow guard failures',
    strpos($syncJob, 'throw $e;') !== false
    && strpos($relatedJob, 'throw $e;') !== false
    && strpos($stockJob, 'withComponentCatalogMutation') !== false);
writerAssert('WRITER-CALL-06 raw runtime catalog truncation is fail-closed',
    strpos($clear, 'CATALOG_EXCLUSIVE_TABLES') !== false
    && strpos($clear, '独占迁移中清理') !== false);
writerAssert('WRITER-CALL-07 offline migrate requires explicit exclusive maintenance',
    substr_count($migrate, 'exclusive-catalog-maintenance') >= 2
    && strpos($migrate, '停止目标库 HTTP、队列和定时任务') !== false);

writerAssert('WRITER-MIG-01 both card and reverse discovery indexes are replay-safe',
    strpos($migration, '`idx_c2_card_definition`') !== false
    && strpos($migration, '`idx_c2_component_reverse`') !== false
    && strpos($migration, '(`product_id`,`card_product_id`,`id`)') !== false);
writerAssert('WRITER-MIG-02 manifest freezes rollback-retry instead of late low-order locking',
    strpos($migrationManifest, '41→42→43') !== false
    && strpos($migrationManifest, '整笔回滚') !== false
    && strpos($migrationManifest, '禁止在商品锁后补拿卡锁') !== false);
writerAssert('WRITER-READY-01 readiness exposes both writer contracts',
    strpos($readiness, "CARD_DEFINITION_WRITER_LOCK_CONTRACT = 'ready-v1'") !== false
    && strpos($readiness, "COMPONENT_SKU_IDENTITY_WRITER_LOCK_CONTRACT = 'ready-v1'") !== false
    && strpos($readiness, "GATEWAY_CATALOG_PRELOCK_CONTRACT = 'ready-v1'") !== false
    && strpos($readiness, 'sale_catalog_gateway_lock_not_ready') !== false);

echo 'C2_CATALOG_WRITER_LOCK_CONTRACT assertions=' . ($passed + $failed)
    . " passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

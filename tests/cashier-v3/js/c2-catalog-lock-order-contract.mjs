import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const sourceRoot = path.resolve(here, '../../../后端代码/app/services/product')

function source(relativePath) {
  return fs.readFileSync(path.join(sourceRoot, relativePath), 'utf8')
}

function assertContract(name, condition) {
  if (!condition) {
    throw new Error(`FAIL ${name}`)
  }
  process.stdout.write(`PASS ${name}\n`)
}

const lock = source('product/StoreProductSkuWriteLock.php')
const inventory = source('inventory/ProductInventoryChangeServices.php')
const stockOrder = source('inventory/StoreProductStockOrderServices.php')
const transfer = source('inventory/StoreStockTransferServices.php')
const salon = source('inventory/SalonStockWriteoffServices.php')
const sku = source('sku/StoreProductAttrValueServices.php')
const product = source('product/StoreProductServices.php')
const branch = source('branch/StoreBranchProductServices.php')
const orderRefund = fs.readFileSync(
  path.resolve(sourceRoot, '../order/StoreOrderRefundServices.php'),
  'utf8',
)
const cashierRoot = path.resolve(sourceRoot, '../cashier/v3')
const resourceKinds = fs.readFileSync(
  path.join(cashierRoot, 'CashierV3ResourceKindCatalog.php'),
  'utf8',
)
const gateway = fs.readFileSync(
  path.join(cashierRoot, 'CashierV3CommandGatewayServices.php'),
  'utf8',
)
const cashierModule = fs.readFileSync(
  path.join(cashierRoot, 'cashier/CashierV3CashierModule.php'),
  'utf8',
)
const saleCatalog = fs.readFileSync(
  path.join(cashierRoot, 'cashier/CashierV3SaleCatalogServices.php'),
  'utf8',
)
const checkoutPreparation = fs.readFileSync(
  path.join(cashierRoot, 'settlement/CashierV3CheckoutPreparationServices.php'),
  'utf8',
)

const productLoop = lock.indexOf('foreach ($productIds as $productId)')
const productForUpdate = lock.indexOf("Db::name('store_product')", productLoop)
const skuLoop = lock.indexOf('foreach ($skuIds as $skuId)')
const skuForUpdate = lock.indexOf("Db::name('store_product_attr_value')", skuLoop)

assertContract('CATALOG-ORDER-01 product 42 locks before sku 43',
  productLoop >= 0 && productForUpdate > productLoop && skuLoop > productForUpdate && skuForUpdate > skuLoop)
assertContract('CATALOG-ORDER-02 both resource ids use stable numeric ordering',
  lock.indexOf('sort($productIds, SORT_NUMERIC)') < productLoop
  && lock.indexOf('sort($skuIds, SORT_NUMERIC)') < skuLoop)
assertContract('CATALOG-ORDER-03 row locks are exact primary-key loops',
  lock.includes("->where('id', $productId)")
  && lock.includes("->where('id', $skuId)")
  && !lock.includes("->whereIn('id', $productIds)->lock(true)")
  && !lock.includes("->whereIn('id', $skuIds)->lock(true)"))
assertContract('CATALOG-ORDER-04 platform parent participates in product ordering',
  lock.includes("$platformProductId = (int)($target['platform_pid'] ?? 0)")
  && lock.includes('$productIds[$platformProductId] = $platformProductId'))
assertContract('CATALOG-ORDER-05 required sku and changed identity fail closed',
  lock.includes("if (!empty($target['require_sku'])")
  && lock.includes('商品规格锁定计划已变化'))

assertContract('CATALOG-INVENTORY-01 inventory core delegates to product-before-sku lock',
  inventory.includes('public function lockProductsThenSkus')
  && inventory.includes('StoreProductSkuWriteLock::class')
  && !inventory.includes('lockSkuThenProducts'))
assertContract('CATALOG-INVENTORY-02 paid, refund and void paths prelock batches',
  (inventory.match(/\$this->lockProductsThenSkus\(\$lockTargets\)/g) || []).length >= 3
  && inventory.includes("'assume_locked' => true")
  && inventory.includes("'platform_pid' => (int)$line['resolvedTarget']['platform_pid']"))
assertContract('CATALOG-INVENTORY-03 sales-only writes hold the same transaction lock',
  inventory.includes('Db::transaction(function () use ($productId, $unique, $platformPid, $n)')
  && inventory.indexOf('$this->lockProductsThenSkus([[', inventory.indexOf('protected function applySalesOnly'))
    < inventory.indexOf("Db::name('store_product_attr_value')", inventory.indexOf('protected function applySalesOnly')))

assertContract('CATALOG-STOCK-01 stock order locks full product/sku set before line writes',
  stockOrder.includes('StoreProductSkuWriteLock::class')
  && stockOrder.indexOf('$catalogWriteLock->lock($lockTargets)') < stockOrder.indexOf('foreach ($productDetail as $idx')
  && !stockOrder.includes('lockAttrValuesByUniques'))
assertContract('CATALOG-TRANSFER-01 transfer locks both sides through shared order',
  transfer.includes("'product_id' => (int)$d['from_product_id']")
  && transfer.includes("'product_id' => (int)$d['to_product_id']")
  && transfer.includes('$catalogWriteLock->lock($lockTargets)')
  && !transfer.includes('lockAttrValuesByUniques'))
assertContract('CATALOG-SALON-01 consumable take and return prelock complete batches',
  (salon.match(/\$catalogWriteLock->lock\(\$lockTargets\)/g) || []).length === 2
  && (salon.match(/'assume_locked' => true/g) || []).length >= 2
  && !salon.includes('->lock(true)'))
assertContract('CATALOG-SKU-01 sku stock writer locks product before reading balances',
  sku.indexOf('$catalogWriteLock->lock($lockTargets)', sku.indexOf('public function saveProductAttrsStock'))
    < sku.indexOf("foreach ($lockedCatalog['skus']", sku.indexOf('public function saveProductAttrsStock')))
assertContract('CATALOG-SKU-02 price writer requires a full catalog lease',
  sku.includes('StoreCatalogWriteLease $catalogLease = null')
  && sku.includes('$catalogGuard->withComponentCatalogMutation(')
  && sku.includes('$this->updateInGuard($catalogLease, $item[')
  && !sku.includes("$this->dao->update($item['id'], $updateData)"))
assertContract('CATALOG-SALES-01 direct sales mutations are transaction guarded',
  product.includes('return $assumeLocked ? $write() : $this->transaction($write)')
  && product.includes("'platform_pid' => $platformProductId")
  && product.indexOf('$catalogWriteLock->lock([[', product.indexOf('private function mutateProductSales'))
    < product.indexOf('$skuValueServices->{$method}', product.indexOf('private function mutateProductSales')))
assertContract('CATALOG-SALES-02 shipped refund sales use one batch lock',
  product.includes('public function decProductSalesBatch')
  && product.indexOf('$catalogWriteLock->lock($lockTargets)', product.indexOf('public function decProductSalesBatch'))
    < product.indexOf('$this->mutateProductSales(', product.indexOf('public function decProductSalesBatch'))
  && orderRefund.includes('$productServices->decProductSalesBatch($salesLines, $store_id)')
  && !orderRefund.includes('$productServices->decProductSales('))
assertContract('CATALOG-BRANCH-01 branch product identity writes retain catalog guard',
  branch.includes('withCatalogMutation(')
  && branch.includes('withComponentCatalogMutation(')
  && branch.includes('saveAllInGuard($catalogLease'))
assertContract('CATALOG-BRANCH-02 restoring platform prices prelocks every product and sku',
  branch.includes("$catalogProductIds = array_map('intval', array_keys($pIds))")
  && branch.includes('$catalogGuard->withComponentCatalogMutation(')
  && branch.includes('$productAttrValueServices->updateInGuard(')
  && !branch.includes("$productAttrValueServices->update($item['id'], ['price'"))

const cardDefinitionOrder = resourceKinds.indexOf("'catalog_card_definition' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 41")
const productOrder = resourceKinds.indexOf("'catalog_product' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 42")
const skuOrder = resourceKinds.indexOf("'catalog_sku' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 43")
assertContract('CATALOG-GATEWAY-01 hidden catalog resources lock card definition then product then sku',
  cardDefinitionOrder >= 0 && productOrder > cardDefinitionOrder && skuOrder > productOrder
  && gateway.includes('$contexts = $this->contextServices->sortForLocking(array_values($contextsByPhysical))')
  && gateway.indexOf('$lockedVersions = $this->versionServices->lockAndAssert($contexts)')
    < gateway.indexOf('$contract = $this->revalidateServerResourceDiscovery('))

assertContract('CATALOG-GATEWAY-02 choose, quantity and checkout discover catalog parents on the server',
  cashierModule.includes("'choose-catalog-item'")
  && cashierModule.includes('$saleCatalog->discoverItemResources(')
  && cashierModule.includes("'change-cart-line-quantity'")
  && cashierModule.includes('$saleCatalog->discoverStoredLineResources(')
  && checkoutPreparation.includes("if ($lineRole === 'sale')")
  && checkoutPreparation.includes('$this->saleCatalog->discoverStoredLineResources('))

assertContract('CATALOG-GATEWAY-03 sku-only discovery cannot bypass product or card-definition locks',
  saleCatalog.includes("['kind' => 'catalog_product', 'id' => (string)$productId, 'version' => $productVersion]")
  && saleCatalog.includes("['kind' => 'catalog_sku', 'id' => (string)$skuId, 'version' => $skuVersion]")
  && saleCatalog.includes("'kind' => 'catalog_card_definition'")
  && saleCatalog.includes("foreach ($component['authoritySnapshot']['resourceSources'] as $resource)")
  && saleCatalog.includes('foreach ($this->serverResources($item) as $resource)')
  && cashierModule.includes('$saleCatalog->selectDraftSaleLineAfterGatewayLocksInTx(')
  && cashierModule.includes('$saleCatalog->discoverStoredLineResources(')
  && saleCatalog.includes("'cashier_sale_locked_context_mismatch'"))

process.stdout.write('C2_CATALOG_LOCK_ORDER_CONTRACT=PASS\n')

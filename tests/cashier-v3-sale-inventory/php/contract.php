<?php

$root = dirname(__DIR__, 3);
$cashier = $root . '/后端代码/app/services/cashier/v3';
require $cashier . '/settlement/CashierV3CheckoutSettlementContractException.php';
require $cashier . '/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
require $root . '/后端代码/app/services/product/inventory/completion/InventoryCompletionContractException.php';
require $root . '/后端代码/app/services/product/inventory/completion/InventoryEntitlementCompletionContract.php';
require $cashier . '/settlement/CashierV3SaleInventorySettlementServices.php';

use app\services\cashier\v3\settlement\CashierV3SaleInventorySettlementServices;

$passed = 0;
$failed = 0;
function saleInventoryOk(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}

$reflection = new ReflectionClass(CashierV3SaleInventorySettlementServices::class);
$provider = $reflection->newInstanceWithoutConstructor();
$units = $reflection->getMethod('quantityUnits');
$cost = $reflection->getMethod('scaledCostDelta');
if (PHP_VERSION_ID < 80100) {
    $units->setAccessible(true);
    $cost->setAccessible(true);
}

saleInventoryOk('sales quantity converts to the frozen stock unit scale',
    $units->invoke($provider, 2, 2) === 200
    && $units->invoke($provider, 3, 0) === 3);
saleInventoryOk('batch cost cursor preserves rounded total cost across allocations',
    $cost->invoke($provider, 0, 150, 99, 2) === 149
    && $cost->invoke($provider, 150, 50, 99, 2) === 49);

$providerSource = (string)file_get_contents(
    $cashier . '/settlement/CashierV3SaleInventorySettlementServices.php'
);
$submissionSource = (string)file_get_contents(
    $cashier . '/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'
);
$salesPlanSource = (string)file_get_contents(
    $cashier . '/order/settlement/CashierV3SalesOrderPlanV1.php'
);
$preparationSource = (string)file_get_contents(
    $cashier . '/settlement/CashierV3CheckoutPreparationServices.php'
);
$receiptPrecheck = (string)file_get_contents(
    $root . '/后端代码/database/upgrades/2026-07-30-收银V3正式销售库存批次扣减/01-升级前检查.sql'
);
$receiptPostcheck = (string)file_get_contents(
    $root . '/后端代码/database/upgrades/2026-07-30-收银V3正式销售库存批次扣减/03-升级后验证.sql'
);
$matrixSource = (string)file_get_contents(
    $root . '/tests/cashier-v3-sale-inventory/mysql56-matrix.sh'
);
$runAllSource = (string)file_get_contents(
    $root . '/tests/cashier-v3-sale-inventory/run-all.sh'
);
$gatewayMatrixSource = (string)file_get_contents(
    $root . '/tests/cashier-v3-sale-inventory/gateway-mysql56-matrix.sh'
);
$gatewayIntegrationSource = (string)file_get_contents(
    $root . '/tests/cashier-v3-sale-inventory/php/gateway-mysql-integration.php'
);
$skuMigrationSource = (string)file_get_contents(
    $root . '/后端代码/database/upgrades/2026-07-30-收银V3销售SKU冻结链路/02-正式升级.sql'
);
$manifestSource = (string)file_get_contents(
    $cashier . '/manifest/CashierV3ActionManifest.php'
);
$gatewaySource = (string)file_get_contents(
    $cashier . '/CashierV3CommandGatewayServices.php'
);

saleInventoryOk('retail sale uses its own provider and never pretends to be a service completion',
    strpos($providerSource, 'inventory_consumption_receipt') === false
    && strpos($providerSource, 'CashierV3SaleInventorySettlementServices') !== false
    && strpos($providerSource, "'sourceType' => 'cashier_sale'") !== false);
saleInventoryOk('provider locks real stock and batches in the frozen inventory order',
    strpos($providerSource, 'InventoryEntitlementCompletionContract::LOCK_ORDER_STOCK') === false
    && strpos($providerSource, "Db::name('inventory_stock')") !== false
    && strpos($providerSource, "Db::name('inventory_batch')") !== false
    && strpos($providerSource, 'InventoryBatchMovementFactServices') !== false);
saleInventoryOk('insufficient batch quantity is strict and cannot produce negative batches',
    strpos($providerSource, "'sale_inventory_shortage_denied'") !== false
    && strpos($providerSource, "'available_quantity_units' => (int)\$row['available_quantity_units'] - \$quantity") !== false
    && strpos($providerSource, "(int)\$row['available_quantity_units'] < \$quantity") !== false);
saleInventoryOk('inventory-domain rejection is translated into one bound checkout failure instead of an unknown payment result',
    strpos($providerSource, 'catch (InventoryCompletionContractException $exception)') !== false
    && strpos($providerSource, 'throw $this->failure($exception->reason(), $exception->detail());') !== false
    && strpos($submissionSource, 'catch (CashierV3CheckoutSettlementContractException $exception)') !== false
    && strpos($submissionSource, 'throw self::translatedFailure($exception->reason(), $exception->detail());') !== false);
saleInventoryOk('final sale-only transaction plans before authority writes and persists inventory before events',
    strpos($submissionSource, '$inventoryPlan = $this->saleInventory->planInTx(') < strpos($submissionSource, '$salesResult = $this->salesOrders->persistInTx(')
    && strpos($submissionSource, '$inventoryResult = $this->saleInventory->persistInTx($inventoryPlan);') < strpos($submissionSource, '$eventResult = $eventRecorder->recordInTx(')
    && strpos($submissionSource, "'aggregate_name_snapshot' => (string)\$order['order_no']") !== false);
saleInventoryOk('inventory sales use an explicit Outbox event contract',
    strpos($manifestSource, "'inventory.sale.deducted'") !== false
    && strpos($manifestSource, "'aggregate_type' => 'sales_order_line_inventory_batch'") !== false);
saleInventoryOk('a successful submit checkout replay is returned by the Gateway before inventory planning',
    strpos($gatewaySource, "->where('idempotency_key', \$idempotencyKey)\n                ->lock(true)\n                ->find();") !== false
    && strpos($gatewaySource, 'if ($existing) {') !== false
    && strpos($gatewaySource, 'return $this->replay(') !== false
    && strpos($gatewaySource, '$result = $business([') !== false
    && strpos($gatewaySource, 'if ($existing) {') < strpos($gatewaySource, '$result = $business(['));
saleInventoryOk('new provider validates catalog rows but never writes legacy product stock fields',
    strpos($providerSource, "Db::name('store_product')") !== false
    && strpos($providerSource, "Db::name('store_product_attr_value')") !== false
    && preg_match(
        "/Db::name\\('store_product(?:_attr_value)?'\\)(?:(?!Db::name).)*->update\\(/s",
        $providerSource
    ) === 0);
saleInventoryOk('new product checkout freezes SKU identity through preparation and authoritative sales persistence',
    strpos($preparationSource, "'catalogSkuId' => (int)(\$line['skuId'] ?? 0)") !== false
    && strpos($salesPlanSource, "'catalog_sku_id'") !== false
    && strpos($salesPlanSource, "'sales_order_product_sku_required'") !== false
    && strpos($submissionSource, 'eb_cashier_v3_checkout_line_draft.catalog_sku_id') !== false
    && strpos($submissionSource, 'eb_cashier_v3_sales_order_line.catalog_sku_id') !== false
    && strpos($submissionSource, "COLUMN_TYPE = \\'bigint(20) unsigned\\'") !== false);
saleInventoryOk('inventory settlement locks SKU by frozen ID and cross-checks the product and unique snapshot',
    strpos($providerSource, "->where('id', \$candidate['skuId'])") !== false
    && strpos($providerSource, "(int)\$sku['product_id'] !== \$candidate['productId']") !== false
    && strpos($providerSource, "(string)\$sku['unique'] !== \$candidate['skuUnique']") !== false
    && strpos($providerSource, "->where('sku_id', \$line['skuId'])") !== false);
saleInventoryOk('SKU freeze uses a new additive migration and does not backfill historical checkout lines',
    strpos($skuMigrationSource, 'ADD COLUMN `catalog_sku_id`') !== false
    && strpos($skuMigrationSource, 'UPDATE ') === false
    && strpos($skuMigrationSource, 'eb_cashier_v3_checkout_line_draft') !== false
    && strpos($skuMigrationSource, 'eb_cashier_v3_sales_order_line') !== false);
saleInventoryOk('receipt migration verifies all business columns, their order, and exact unique-index column order',
    strpos($receiptPrecheck, '@siv_target_business_columns=23') !== false
    && strpos($receiptPrecheck, '@siv_target_column_total=23') !== false
    && strpos($receiptPrecheck, '@siv_target_column_order=1') !== false
    && strpos($receiptPrecheck, 'uk_tenant_receipt') !== false
    && strpos($receiptPrecheck, '1:tenant_id:0,2:receipt_id:0') !== false
    && strpos($receiptPostcheck, '@siv_column_order=1') !== false
    && strpos($receiptPostcheck, '@siv_unique_index_total=3') !== false);
saleInventoryOk('MySQL matrix reruns the valid migration and rejects malformed receipt schema variants',
    strpos($matrixSource, "'missing_column'") !== false
    && strpos($matrixSource, "'wrong_unique_index_order'") !== false
    && strpos($matrixSource, 'assert_receipt_postcheck_fails') !== false
    && substr_count($matrixSource, '2026-07-30-收银V3正式销售库存批次扣减/01-升级前检查.sql') >= 3
    && strpos($matrixSource, '2026-07-30-收银V3销售SKU冻结链路/02-正式升级.sql') !== false);
saleInventoryOk('permanent Gateway coverage executes all four inventory checkout business paths',
    strpos($runAllSource, 'gateway-mysql56-matrix.sh') !== false
    && strpos($gatewayMatrixSource, '2026-07-30-收银V3销售SKU冻结链路/02-正式升级.sql') !== false
    && strpos($gatewayMatrixSource, '2026-07-30-收银V3正式销售库存批次扣减/02-正式升级.sql') !== false
    && strpos($gatewayIntegrationSource, 'SALE-INV-GW-MYSQL-01') !== false
    && strpos($gatewayIntegrationSource, 'SALE-INV-GW-MYSQL-08') !== false
    && strpos($gatewayIntegrationSource, 'SALE-INV-GW-MYSQL-09') !== false
    && strpos($gatewayIntegrationSource, 'SALE-INV-GW-MYSQL-10') !== false
    && strpos($gatewayIntegrationSource, 'trg_sale_inventory_post_persist_failure') !== false);

echo "SALE_INVENTORY_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/** Static contract: count catalog failures are atomic and expose only client-safe row identities. */
$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockCountServices.php');
$exception = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockCountCatalogException.php');
$controller = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockCount.php');

if ($service === false || $exception === false || $controller === false) {
    throw new RuntimeException('stock_count_catalog_contract_source_missing');
}

$preflight = strpos($service, '$this->assertCatalogLines($scope, $location, $command[\'lines\']);');
$firstWrite = strpos($service, "Db::name('inventory_stock_count_document')->insertGetId");
if ($preflight === false || $firstWrite === false || $preflight > $firstWrite) {
    throw new RuntimeException('stock_count_catalog_preflight_must_precede_document_write');
}
if (strpos($service, "->where('p.is_del', 0)") === false
    || strpos($service, "->where('p.is_inventory', 1)") === false
    || strpos($service, 'if ($invalid) throw new InventoryStockCountCatalogException($invalid);') === false) {
    throw new RuntimeException('stock_count_catalog_preflight_fail_closed_missing');
}
if (strpos($exception, 'private array $invalidLines;') === false
    || strpos($exception, 'public function invalidLines(): array') === false) {
    throw new RuntimeException('stock_count_catalog_exception_contract_missing');
}
if (strpos($controller, "'invalid_lines' => \$exception->invalidLines()") === false
    || strpos($controller, "'code' => \$exception->getMessage()") === false) {
    throw new RuntimeException('stock_count_catalog_controller_envelope_missing');
}

echo "STOCK_COUNT_CATALOG_VALIDATION_CONTRACT_OK\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$controller = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStoreCatalog.php');
$service = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php');
if ($controller === false || $service === false) {
    throw new RuntimeException('outbound_catalog_source_missing');
}

// 出库选品必须把最后一个参数传给 forOutboundStock，而不是落到 inventoryOnly 上。
if (!preg_match('/bool\s+\$inventoryOnly\s*=\s*true\s*,\s*bool\s+\$forOutboundStock\s*=/', $service)
    || !preg_match('/true\s*,(?:\s|\/\/[^\n]*\n)*\(int\)\$this->request->get\(\x27for_outbound_stock\x27,\s*0\)\s*===\s*1/', $controller)) {
    throw new RuntimeException('outbound_catalog_argument_position_invalid');
}

echo "STORE_OUTBOUND_CATALOG_WIRING_OK\n";

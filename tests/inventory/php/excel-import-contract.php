<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 3);
$service = file_get_contents($repo . '/后端代码/app/services/product/inventory/InventoryV3ExcelImportServices.php');
$controller = file_get_contents($repo . '/后端代码/app/controller/store/product/inventory/InventoryV3ExcelImport.php');
$platformController = file_get_contents($repo . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformExcelImport.php');
$platformRoute = file_get_contents($repo . '/后端代码/route/admin.php');
$route = file_get_contents($repo . '/后端代码/route/store.php');
$migration = file_get_contents($repo . '/后端代码/database/upgrades/2026-08-03-库存V3Excel导入/02-正式升级.sql');
$operationalContract = file_get_contents($repo . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php');
$failed = 0;
function excelImportAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

excelImportAssert('V3 Excel importer calls only the V3 batch authorities',
    strpos($service, 'InventoryManualInboundServices())->createForImport') !== false
    && strpos($service, 'InventoryManualOutboundServices())->createForImport') !== false
    && strpos($service, 'StoreProductStockOrderServices') === false);
excelImportAssert('template and row validation reject defective quantity and require inbound batch evidence',
    strpos($service, '残次品数量必须为0') !== false
    && strpos($service, "'批次号'") !== false
    && strpos($service, "'生产日期'") !== false
    && strpos($service, "'到期日'") !== false
    && strpos($service, "'入库单价'") !== false);
excelImportAssert('file idempotency is store scoped and success is terminal',
    strpos($service, "where('store_id', \$storeId)->where('direction', \$direction)->where('file_hash', \$fileHash)") !== false
    && strpos($service, "status'] === 'SUCCEEDED'") !== false);
excelImportAssert('import endpoints are protected by the store inventory route group',
    strpos($route, "v3/import/template") !== false && strpos($route, "v3/import/:id/detail") !== false
    && strpos($controller, 'extends AuthController') !== false);
excelImportAssert('Excel workbooks use the dedicated V3 upload boundary rather than image attachment upload',
    strpos($service, 'storeUploadedFile') !== false
    && strpos($service, "uploads/inventory-import") !== false
    && strpos($service, 'UPLOAD_MAX_BYTES') !== false
    && strpos($route, "v3/import/upload") !== false
    && strpos($platformRoute, "v3/import/upload") !== false
    && strpos($controller, 'function upload()') !== false
    && strpos($platformController, 'function upload()') !== false);
excelImportAssert('migration owns V3-only import records and errors',
    strpos($migration, 'eb_inventory_v3_import_record') !== false
    && strpos($migration, 'eb_inventory_v3_import_error') !== false
    && strpos($migration, 'stock_import_idempotent') === false);
excelImportAssert('import record page is registered in the inventory unified-query contract',
    strpos($operationalContract, "'inventory_import'") !== false
    && strpos($operationalContract, "'source_file_name'") !== false);
echo "INVENTORY_EXCEL_IMPORT_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

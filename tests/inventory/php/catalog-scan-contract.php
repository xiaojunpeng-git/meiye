<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$store = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php');
$headquarters = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php');
$failed = 0;

function catalogAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

foreach (['门店', '总部'] as $index => $scope) {
    $source = $index === 0 ? $store : $headquarters;
    catalogAssert($scope . '扫码精确识别条码、SKU编码和商品编码',
        strpos($source, "->where('a.bar_code', \$barcode)") !== false
        && strpos($source, "->whereOr('p.bar_code', \$barcode)") !== false
        && strpos($source, "->whereOr('a.code', \$barcode)") !== false
        && strpos($source, "->whereOr('p.code', \$barcode)") !== false);
    catalogAssert($scope . '目录搜索覆盖商品编码',
        strpos($source, 'p.store_name|p.keyword|p.bar_code|p.code') !== false);
}

echo "INVENTORY_CATALOG_SCAN_CONTRACT_RESULT failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

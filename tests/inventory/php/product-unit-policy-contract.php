<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryProductUnitPolicy;

$failed = 0;
$check = static function (string $label, bool $ok) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
};

$check('普通商品使用资料包装单位，忽略旧 SKU 的金额单位',
    InventoryProductUnitPolicy::resolve('包', '元', false) === '包');
$check('院装商品保留独立耗材单位',
    InventoryProductUnitPolicy::resolve('盒', '片', true) === '片');
$check('院装耗材单位缺失时回落包装单位',
    InventoryProductUnitPolicy::resolve('盒', '', true) === '盒');
$check('旧商品包装单位缺失时保留 SKU 单位',
    InventoryProductUnitPolicy::resolve('', '瓶', false) === '瓶');
$check('跨主体目录别名沿用同一单位规则',
    str_contains(InventoryProductUnitPolicy::expression('pa', 'sa'), 'pa.salon_stock_enabled = 1')
    && str_contains(InventoryProductUnitPolicy::expression('pa', 'sa'), 'sa.stock_unit'));
try {
    InventoryProductUnitPolicy::expression('pa;DROP TABLE x', 'sa');
    $check('目录别名拒绝非标识符', false);
} catch (InvalidArgumentException $exception) {
    $check('目录别名拒绝非标识符', true);
}

$sql = InventoryProductUnitPolicy::selectSql();
$check('目录 SQL 与普通/院装的优先级一致',
    str_contains($sql, 'p.salon_stock_enabled = 1')
    && str_contains($sql, "THEN COALESCE(NULLIF(TRIM(a.stock_unit), ''), NULLIF(TRIM(p.unit_name), ''), '')")
    && str_contains($sql, "ELSE COALESCE(NULLIF(TRIM(p.unit_name), ''), NULLIF(TRIM(a.stock_unit), ''), '')"));

// 只在内存中执行目录表达式，保证查询结果与写入时的 PHP 口径一致。
$db = new PDO('sqlite::memory:');
$statement = $db->prepare("SELECT {$sql} FROM (SELECT :sale_unit AS unit_name, :salon_enabled AS salon_stock_enabled) p CROSS JOIN (SELECT :sku_unit AS stock_unit) a");
foreach ([['包', '元', 0], ['盒', '片', 1], ['盒', '', 1], ['', '瓶', 0]] as [$saleUnit, $skuUnit, $salonEnabled]) {
    $statement->bindValue(':sale_unit', $saleUnit);
    $statement->bindValue(':sku_unit', $skuUnit);
    $statement->bindValue(':salon_enabled', $salonEnabled, PDO::PARAM_INT);
    $statement->execute();
    $actual = (string)$statement->fetchColumn();
    $expected = InventoryProductUnitPolicy::resolve($saleUnit, $skuUnit, $salonEnabled === 1);
    $check("目录单位 {$saleUnit}/{$skuUnit}/{$salonEnabled} 与写入规则一致", $actual === $expected);
}

// 历史库存可留有多个旧单位，但同一 SKU 的已结算数量必须聚为一行。
$db->exec('CREATE TABLE movement (product_id INTEGER, sku_id INTEGER, stock_unit TEXT, quantity_units INTEGER)');
$db->exec("INSERT INTO movement VALUES (1, 2, '元', 2), (1, 2, '包', 3)");
$aggregated = $db->query('SELECT product_id, sku_id, MAX(stock_unit) AS stock_unit, SUM(quantity_units) AS quantity_units FROM movement GROUP BY product_id, sku_id')->fetchAll(PDO::FETCH_ASSOC);
$check('不同旧单位不拆分同一 SKU 的库存统计', count($aggregated) === 1 && (int)$aggregated[0]['quantity_units'] === 5);

foreach ([
    '门店选品' => '后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php',
    '总部选品' => '后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php',
    '入库锁定' => '后端代码/app/services/product/inventory/InventoryManualInboundServices.php',
    '请货单新快照' => '后端代码/app/services/product/inventory/InventoryStockRequestServices.php',
    '盘盈新库存' => '后端代码/app/services/product/inventory/InventoryStockCountServices.php',
    '跨主体调拨' => '后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php',
] as $label => $path) {
    $check($label . '复用同一单位口径', str_contains((string)file_get_contents($root . '/' . $path), 'InventoryProductUnitPolicy::selectSql()'));
}
$projectionUsers = [
    '当前库存/批次查询' => '后端代码/app/services/product/inventory/query/InventoryBatchStockQueryProvider.php',
    '库存统计' => '后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php',
    '同仓批次调拨' => '后端代码/app/services/product/inventory/InventoryBatchTransferServices.php',
];
foreach ($projectionUsers as $label => $path) {
    $check($label . '仅装饰当前单位', str_contains((string)file_get_contents($root . '/' . $path), 'InventoryCurrentStockUnitProjection'));
}
$crossSku = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/StoreStockCrossSkuServices.php');
$check('跨店请货调拨的目录与选品共用单位口径',
    substr_count($crossSku, 'InventoryProductUnitPolicy::expression(') === 3
    && str_contains($crossSku, 'InventoryProductUnitPolicy::resolve('));
$analytics = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php');
$check('库存统计不按旧单位分组', !str_contains($analytics, 's.sku_id,s.stock_unit,s.quantity_scale')
    && str_contains($analytics, "'MAX(s.stock_unit)' => 'stock_unit'"));
$legacyStock = (string)file_get_contents($root . '/后端代码/app/services/product/sku/StoreProductAttrValueServices.php');
$check('平台旧版库存明细单仓与全部门店也读取当前商品单位',
    str_contains($legacyStock, 'p.unit_name')
    && str_contains($legacyStock, "InventoryProductUnitPolicy::expression('p', 'a')")
    && str_contains($legacyStock, 'InventoryProductUnitPolicy::resolve('));
$check('商品保存将普通商品单位收敛到资料设置',
    str_contains((string)file_get_contents($root . '/后端代码/app/services/product/product/StoreProductServices.php'),
        'InventoryProductUnitPolicy::resolve($unitName, $stockUnit, $salonEnabledEarly === 1)'));

echo "INVENTORY_PRODUCT_UNIT_RESULT failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

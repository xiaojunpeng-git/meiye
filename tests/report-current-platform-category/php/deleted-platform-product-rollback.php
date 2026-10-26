<?php

declare(strict_types=1);

use app\services\report\StoreReportCurrentProductCategoryServices;
use think\facade\Db;

// Local-only transaction: product visibility must not rewrite historical classification.
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new think\App(rtrim($backend, '/\\') . '/'))->initialize();

$platformId = 91038;
$storeProductId = 91111;
$storeId = 126;
$platform = Db::name('store_product')->where('id', $platformId)->find();
$storeProduct = Db::name('store_product')->where('id', $storeProductId)->find();
if (!$platform || !$storeProduct || (int)$storeProduct['pid'] !== $platformId
    || (int)$storeProduct['relation_id'] !== $storeId) {
    throw new RuntimeException('local platform/store product fixture missing');
}

$resolver = new StoreReportCurrentProductCategoryServices();
Db::startTrans();
try {
    Db::name('store_product')->where('id', $platformId)->update(['cate_id' => '26332', 'is_del' => 1]);
    Db::name('store_product')->where('id', $storeProductId)->update(['is_del' => 1]);
    $categories = $resolver->resolve([
        'same_store' => ['store_id' => $storeId, 'product_id' => $storeProductId],
        'other_store' => ['store_id' => 175, 'product_id' => $storeProductId],
    ]);
    if (($categories['same_store']['path'] ?? '') !== '生美/卡项'
        || isset($categories['other_store'])) {
        throw new RuntimeException('deleted platform category or store scope regression');
    }
    echo "PASS deleted store/platform product retains its current platform category\n";
    echo "PASS cross-store product link remains rejected\n";
} finally {
    Db::rollback();
}

if (Db::name('store_product')->where('id', $platformId)->find() !== $platform
    || Db::name('store_product')->where('id', $storeProductId)->find() !== $storeProduct) {
    throw new RuntimeException('local product fixture did not roll back');
}
echo "PASS local product changes rolled back\n";

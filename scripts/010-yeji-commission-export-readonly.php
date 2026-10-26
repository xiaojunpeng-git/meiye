<?php
declare(strict_types=1);

/**
 * Freeze the 007 project commission configuration in one read-only transaction.
 * The full source row set proves which rows are new without overwriting 010 edits.
 */
if (PHP_SAPI !== 'cli' || getenv('SOURCE_SITE_ROOT') !== '/www/wwwroot/007.cc3798.com') {
    throw new RuntimeException('007 source root and CLI are required');
}
$root = getenv('SOURCE_SITE_ROOT');
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();

use think\facade\Db;

$identity = Db::query('SELECT DATABASE() AS db, @@version AS mysql_version')[0];
if (($identity['db'] ?? '') !== 'linlin') {
    throw new RuntimeException('007 source database identity mismatch');
}

Db::execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $clock = Db::query('SELECT NOW() AS snapshot_at')[0];
    $rows = Db::name('yeji_commission')->order('id')->select()->toArray();
    $byId = array_column($rows, null, 'id');
    $newIds = [222, 223, 224, 225, 226, 227];
    if (count($rows) !== 140 || array_keys(array_intersect_key($byId, array_flip($newIds))) !== $newIds) {
        throw new RuntimeException('007 commission source has changed; rebuild the diff');
    }
    $visibleIds = [222, 223, 225, 226, 227];
    if ((int) $byId[224]['product_id'] !== 0) {
        throw new RuntimeException('The source-only orphan record changed');
    }
    $productIds = array_map(static function (int $id) use ($byId): int {
        return (int) $byId[$id]['product_id'];
    }, $visibleIds);
    if (count(array_unique($productIds)) !== count($productIds) || in_array(0, $productIds, true)) {
        throw new RuntimeException('Source visible commission products are not distinct');
    }
    $products = Db::name('store_product')->whereIn('id', $productIds)
        ->field('id,store_name,pid,type,product_type,is_del')->order('id')->select()->toArray();
    if (count($products) !== 5) {
        throw new RuntimeException('A visible commission has no product');
    }
    foreach ($products as $product) {
        if ((int) $product['pid'] !== 0 || (int) $product['product_type'] !== 6 || (int) $product['is_del'] !== 0) {
            throw new RuntimeException('A commission product is not an active platform project');
        }
    }
    $ranges = Db::name('yeji_range')->order('id')->select()->toArray();
    if (count($ranges) !== 5) {
        throw new RuntimeException('The commission tier definition changed');
    }
    $snapshot = [
        'format' => '010-yeji-commission-increment-v1',
        'source' => ['site' => '007.cc3798.com', 'database' => 'linlin',
            'snapshot_at' => $clock['snapshot_at'], 'mysql_version' => $identity['mysql_version']],
        'expected_target' => ['site' => '010.cc3798.com', 'database' => 'mohe_010_app'],
        'source_rows' => $rows,
        'source_ranges' => $ranges,
        'visible_new_ids' => $visibleIds,
        'excluded_orphan_id' => 224,
        'visible_products' => $products,
    ];
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} finally {
    Db::rollback();
}

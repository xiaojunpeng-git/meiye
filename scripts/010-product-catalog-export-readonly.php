<?php
declare(strict_types=1);

/**
 * Freeze the 007 catalog increment as one read-only database snapshot.
 * The JSON is private migration input, never a second editable product source.
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

// The source remains writable for 007 users; our connection sees one consistent, read-only view.
Db::execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $clock = Db::query('SELECT NOW() AS snapshot_at')[0];
    $maxId = (int) Db::name('store_product')->max('id');
    if ($maxId !== 9307) {
        throw new RuntimeException('007 product catalog changed; rebuild the diff and candidate');
    }
    $ids = range(9212, 9307);
    $tables = [
        'store_product' => ['key' => 'id', 'order' => 'id'],
        'store_product_attr' => ['key' => 'product_id', 'order' => 'id'],
        'store_product_attr_result' => ['key' => 'product_id', 'order' => 'id'],
        'store_product_attr_value' => ['key' => 'product_id', 'order' => 'id'],
        'store_product_description' => ['key' => 'product_id', 'order' => 'product_id,type'],
        'store_product_relation' => ['key' => 'product_id', 'order' => 'id'],
        'store_card_related' => ['key' => 'card_product_id', 'order' => 'id'],
        'store_product_reservation_time' => ['key' => 'product_id', 'order' => 'id'],
    ];
    $rows = [];
    $counts = [];
    foreach ($tables as $table => $spec) {
        $rows[$table] = Db::name($table)->whereIn($spec['key'], $ids)
            ->order($spec['order'])->select()->toArray();
        $counts[$table] = count($rows[$table]);
    }
    $expected = [96, 96, 96, 96, 96, 132, 36, 720];
    if (array_values($counts) !== $expected ||
        array_map('intval', array_column($rows['store_product'], 'id')) !== $ids) {
        throw new RuntimeException('007 catalog graph no longer matches the inspected candidate');
    }
    $categoryIds = array_values(array_unique(array_map('intval',
        array_column($rows['store_product_relation'], 'relation_id'))));
    sort($categoryIds, SORT_NUMERIC);
    $storeIds = array_values(array_unique(array_map('intval',
        array_column(array_filter($rows['store_product'], static function (array $row): bool {
            return (int) $row['type'] === 1;
        }), 'relation_id'))));
    sort($storeIds, SORT_NUMERIC);
    $dependencyIds = array_values(array_unique(array_map('intval',
        array_column($rows['store_card_related'], 'product_id'))));
    sort($dependencyIds, SORT_NUMERIC);
    $references = [
        'categories' => Db::name('store_product_category')->whereIn('id', $categoryIds)
            ->field('id,pid,type,relation_id,cate_name,is_show')->order('id')->select()->toArray(),
        'stores' => Db::name('system_store')->whereIn('id', $storeIds)
            ->field('id,name')->order('id')->select()->toArray(),
        'card_dependency_skus' => Db::name('store_product_attr_value')->whereIn('product_id', $dependencyIds)
            ->field(['product_id', 'unique'])->order('product_id')->select()->toArray(),
    ];
    if (count($references['categories']) !== count($categoryIds) ||
        count($references['stores']) !== count($storeIds)) {
        throw new RuntimeException('007 catalog contains an unresolved category or store');
    }
    $snapshot = [
        'format' => '010-product-catalog-increment-v1',
        'source' => ['site' => '007.cc3798.com', 'database' => 'linlin',
            'snapshot_at' => $clock['snapshot_at'], 'mysql_version' => $identity['mysql_version']],
        'source_max_product_id' => $maxId,
        'expected_target' => ['site' => '010.cc3798.com', 'database' => 'mohe_010_app'],
        'counts' => $counts,
        'references' => $references,
        'rows' => $rows,
    ];
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} finally {
    Db::rollback();
}

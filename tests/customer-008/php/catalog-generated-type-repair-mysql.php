<?php
declare(strict_types=1);

/** MySQL 5.7 隔离回归：修复普通目录类型，不重建 SKU、不改变门店归属或卡项权益。 */
require __DIR__ . '/catalog-import-contract.php';
require __DIR__ . '/../../../后端代码/database/upgrades/2026-10-15-008辅助项目规格目录类型修复/01-执行.php';
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$fixture = catalogFixture();
$fixture['bundle']['label_category'] = [$fixture['bundle']['label_category']];
$originalIds = array_column($fixture['bundle']['product'], 'id');
$db->beginTransaction();
try {
    catalogApply($db, $fixture['bundle'], $fixture['plan'], str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 40));
    $ids = $db->query('SELECT id FROM eb_store_product WHERE type=1 AND id NOT IN (' . implode(',', $originalIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    catalogAssert(count($ids) === 2, 'Both helper creation branches must exist');
    // 模拟旧导入器的具体故障：只改两类辅助项目的四张附属表，主商品归属保持门店。
    foreach (M008_SKU_TYPE_TABLES as $table) {
        $db->exec('UPDATE eb_' . $table . ' SET type=1 WHERE product_id IN (' . implode(',', $ids) . ')');
    }
    $plan = m008SkuTypePlan($db, $fixture['plan']);
    catalogAssert(count($plan['products']) === 2 && count($plan['relations']) === 2, 'Repair scope includes unrelated objects');
    $hash = m008SkuTypeHash($plan);
    $db->exec('SAVEPOINT invalid_identity');
    $db->exec("UPDATE eb_store_product_attr_value SET `unique`='invalid' WHERE product_id=" . $ids[0]);
    try {
        m008SkuTypePlan($db, $fixture['plan']);
        throw new RuntimeException('Changed SKU identity was accepted');
    } catch (RuntimeException $error) {
        catalogAssert(strpos($error->getMessage(), 'Generated SKU identity changed') !== false, 'Wrong identity rejection');
    }
    $db->exec('ROLLBACK TO SAVEPOINT invalid_identity');
    $db->exec('SAVEPOINT mixed_namespace');
    $db->exec('UPDATE eb_store_product_attr_result SET type=0 WHERE product_id=' . $ids[0]);
    try {
        m008SkuTypePlan($db, $fixture['plan']);
        throw new RuntimeException('Mixed namespace was accepted');
    } catch (RuntimeException $error) {
        catalogAssert(strpos($error->getMessage(), 'Missing, duplicate or mixed') !== false, 'Wrong metadata conflict rejection');
    }
    $db->exec('ROLLBACK TO SAVEPOINT mixed_namespace');
    catalogAssert(m008SkuTypeHash(m008SkuTypePlan($db, $fixture['plan'])) === $hash, 'Rollback did not restore fixed plan');
    $db->exec('SAVEPOINT fault_recovery');
    $db->exec('UPDATE eb_store_product SET price=price+1 WHERE id=' . $ids[0]);
    try {
        m008SkuTypeWrite($db, $plan);
        throw new RuntimeException('Write verification missed unrelated field drift');
    } catch (RuntimeException $error) {
        catalogAssert(strpos($error->getMessage(), 'Product changed') !== false, 'Wrong post-write mismatch rejection');
    }
    $db->exec('ROLLBACK TO SAVEPOINT fault_recovery');
    catalogAssert(m008SkuTypeHash(m008SkuTypePlan($db, $fixture['plan'])) === $hash, 'Failed write was not fully reversible');
    $counts = m008SkuTypeWrite($db, $plan);
    foreach ($counts as $count) catalogAssert($count === 2, 'Wrong metadata update count');
    catalogAssert(count(m008SkuTypePlan($db, $fixture['plan'])['products']) === 0, 'Repair left wrong namespace rows');
    catalogAssert((int)$db->query('SELECT COUNT(*) FROM eb_store_card_related r LEFT JOIN eb_store_product_attr_value s ON s.product_id=r.product_id AND s.`unique`=r.product_attr_unique AND s.type=0 WHERE s.id IS NULL')->fetchColumn() === 0,
        'Repaired card still has an unresolved catalogue identity');
    try {
        m008SkuTypeWrite($db, $plan);
        throw new RuntimeException('Stale repair was replayed');
    } catch (RuntimeException $error) {
        catalogAssert(strpos($error->getMessage(), 'Repair row count changed') !== false, 'Wrong replay rejection');
    }
    echo "008 generated catalogue type repair: identity, scope, fixed hash, conflict, rollback, preservation, replay PASS\n";
} finally {
    $db->rollBack();
}

// 直接运行产品源码的写锁守卫，不以 SQL 联结或静态字符串扫描代替实际锁计划验证。
require __DIR__ . '/../../../后端代码/vendor/autoload.php';
$manager = new think\DbManager();
preg_match('/host=([^;]+);port=(\d+);/', $dsn, $connection);
if (($connection[1] ?? '') !== '127.0.0.1') throw new RuntimeException('Guard test requires local isolation');
$manager->setConfig(['default' => 'mysql', 'connections' => ['mysql' => [
    'type' => 'mysql', 'hostname' => $connection[1], 'hostport' => $connection[2],
    'database' => $testDatabase, 'username' => $user, 'password' => $password,
    'charset' => 'utf8mb4', 'prefix' => 'eb_', 'fields_strict' => true,
    'params' => [PDO::ATTR_STRINGIFY_FETCHES => true],
]]]);
think\Container::getInstance()->instance(think\DbManager::class, $manager);
$guard = new app\services\product\product\StoreCatalogWriteLockGuard();
$db->beginTransaction();
catalogApply($db, $fixture['bundle'], $fixture['plan'], str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 40));
$ids = $db->query('SELECT id FROM eb_store_product WHERE type=1 AND id NOT IN (' . implode(',', $originalIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
foreach (M008_SKU_TYPE_TABLES as $table) $db->exec('UPDATE eb_' . $table . ' SET type=1 WHERE product_id IN (' . implode(',', $ids) . ')');
$db->commit();
try {
    try {
        $guard->withComponentCatalogMutation([10, 11, (int)$ids[0], (int)$ids[1]], static function () { return true; });
        throw new RuntimeException('Real guard failed to reproduce imported type error');
    } catch (mohe\exceptions\AdminException $error) {
        catalogAssert($error->getMessage() === 'SKU identity 未纳入当前写锁计划', 'Real guard failed for a different reason');
    }
    $db->beginTransaction();
    $plan = m008SkuTypePlan($db, $fixture['plan']);
    m008SkuTypeWrite($db, $plan);
    $db->commit();
    $result = $guard->withComponentCatalogMutation([10, 11, (int)$ids[0], (int)$ids[1]],
        static function (app\services\product\product\StoreCatalogWriteLease $lease) use ($plan) {
            foreach ($plan['rows']['store_product_attr_value'] as $row) {
                $lease->assertCoversIdentity((int)$row['product_id'], (string)$row['unique']);
            }
            return true;
        });
    catalogAssert($result === true, 'Real guard still rejects repaired catalogue');
    echo "008 real catalogue write guard: original error reproduced; repaired graph and identity lease PASS\n";
} finally {
    // 此库是本任务独立合成测试库；只清理本测试产生的资料，绝不连接真实客户库。
    foreach (['store_card_related', 'store_product_attr', 'store_product_attr_value', 'store_product_attr_result',
        'store_product_description', 'store_product', 'store_product_category', 'category', 'database_upgrade_log'] as $table) {
        $db->exec('DELETE FROM eb_' . $table);
    }
}

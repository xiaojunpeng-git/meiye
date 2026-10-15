<?php
/**
 * Import the reviewed morestore catalogue into the empty 008 catalogue tables.
 *
 * Source rows are read in a consistent snapshot. Every target write is limited
 * to mohe_008:3307, guarded by the reviewed source digest, an empty catalogue,
 * an independent backup, an advisory lock and one InnoDB transaction. Historical
 * stock/sales are never copied; old media and HTML detail bytes are preserved.
 */

declare(strict_types=1);

const CATALOG_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const CATALOG_TARGET_ENV = '/www/wwwroot/008.cc3798.com/.env';
const CATALOG_PLAN_NAME = '008-morestore-catalog-r1';
const CATALOG_UPGRADE_KEY = '20261013-008-morestore-catalog-r1';
const CATALOG_BACKUP_PREFIX = '/www/backups/008.cc3798.com/';
const CATALOG_TABLES = [
    'eb_store_product_category', 'eb_store_brand', 'eb_store_product_label',
    'eb_store_product_unit', 'eb_category', 'eb_store_product',
    'eb_store_product_attr', 'eb_store_product_attr_value',
    'eb_store_product_attr_result', 'eb_store_product_description',
    'eb_store_product_category_brand', 'eb_store_card_related',
];

/** Open only the named instance; credentials stay in server memory. */
function catalogConnect(string $envPath, string $database, int $port): PDO
{
    $env = parse_ini_file($envPath);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== $database
        || (int)($env['HOSTPORT'] ?? 0) !== $port) {
        throw new RuntimeException('Database environment identity changed');
    }
    $dsn = 'mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $env['DATABASE'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $env['USERNAME'], $env['PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $identity = $db->query('SELECT DATABASE() AS db, @@port AS port')->fetch();
    if (($identity['db'] ?? '') !== $database || (int)($identity['port'] ?? 0) !== $port) {
        throw new RuntimeException('Connected database identity changed');
    }
    return $db;
}

/** A plan is data, never executable instructions; validate every allowed choice. */
function catalogPlan(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Reviewed catalogue plan missing');
    }
    $plan = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['plan_version'] ?? '') !== CATALOG_PLAN_NAME
        || ($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008'
        || ($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || count($plan['store_map'] ?? []) !== 22
        || count($plan['roots'] ?? []) !== 160) {
        throw new RuntimeException('Plan identity or coverage changed');
    }
    $seen = [];
    foreach ($plan['roots'] as $root) {
        $id = (int)($root['old_id'] ?? 0);
        if ($id <= 0 || isset($seen[$id]) || !in_array($root['kind'] ?? '',
                ['product', 'project', 'card', 'shell'], true)
            || !in_array((int)($root['target_type'] ?? -1), [0, 5, 6], true)) {
            throw new RuntimeException('Plan contains an invalid or repeated root');
        }
        $expectedType = ['product' => 0, 'project' => 6, 'card' => 5, 'shell' => 5][$root['kind']];
        if ((int)$root['target_type'] !== $expectedType) {
            throw new RuntimeException('Plan kind/type mismatch');
        }
        if ($root['kind'] === 'card') {
            if (!in_array($root['card_rule'] ?? '', ['normal', 'choice_count'], true)
                || empty($root['related'])
                || ($root['card_rule'] === 'normal' && count($root['related']) !== 1)
                || ($root['card_rule'] === 'choice_count' && count($root['related']) < 2)) {
                throw new RuntimeException('An active card lacks a reviewed definition');
            }
            foreach ($root['related'] as $relation) {
                if (array_keys($relation) !== ['old_project_id']
                    && array_keys($relation) !== ['new_tag_project_name']) {
                    throw new RuntimeException('Unreviewed related-project reference');
                }
            }
        } elseif ($root['kind'] === 'shell' && trim((string)($root['reason'] ?? '')) === '') {
            throw new RuntimeException('A hidden card shell lacks a reason');
        }
        $seen[$id] = true;
    }
    foreach ($plan['store_map'] as $old => $new) {
        if ((int)$old <= 0 || (int)$new <= 0) {
            throw new RuntimeException('Invalid store mapping');
        }
    }
    return $plan;
}

/** Fetch rows in stable order so the source digest also covers media/details. */
function catalogRows(PDO $db, string $table, string $where = '1=1'): array
{
    $order = $table === 'eb_store_product_description' ? 'product_id,type' : 'id';
    return $db->query('SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY ' . $order)->fetchAll();
}

/** Read the exact source scope; closed-store children and their orphan root stay out. */
function catalogSource(PDO $source, array $plan): array
{
    $rootIds = array_map(static function ($r) { return (int)$r['old_id']; }, $plan['roots']);
    $oldStores = array_map('intval', array_keys($plan['store_map']));
    $rootSql = implode(',', $rootIds);
    $storeSql = implode(',', $oldStores);
    $sourceStores = catalogRows($source, 'eb_system_store', 'id IN (' . $storeSql . ')');
    if (count($sourceStores) !== 22) {
        throw new RuntimeException('Mapped old store count changed');
    }
    foreach ($sourceStores as $store) {
        if ((int)$store['is_show'] !== 1 || (int)$store['is_del'] !== 0) {
            throw new RuntimeException('A mapped old store is no longer operating');
        }
    }
    $roots = catalogRows($source, 'eb_store_product', 'id IN (' . $rootSql . ')');
    $children = catalogRows($source, 'eb_store_product', 'pid IN (' . $rootSql
        . ') AND type=1 AND is_del=0 AND relation_id IN (' . $storeSql . ')');
    if (count($roots) !== 160 || count($children) !== 3113) {
        throw new RuntimeException('Old catalogue root/active-store child count changed');
    }
    $byRoot = [];
    foreach ($plan['roots'] as $root) {
        $byRoot[(int)$root['old_id']] = $root;
    }
    foreach ($plan['roots'] as $root) {
        foreach ($root['related'] ?? [] as $relation) {
            if (isset($relation['old_project_id'])
                && (($byRoot[(int)$relation['old_project_id']]['kind'] ?? '') !== 'project')) {
                throw new RuntimeException('Card references an unreviewed single project');
            }
        }
    }
    foreach ($roots as $row) {
        $id = (int)$row['id'];
        $planned = $byRoot[$id] ?? null;
        if (!$planned || (int)$row['pid'] !== 0 || (int)$row['type'] !== 0
            || (int)$row['is_del'] !== 0
            || (string)$row['store_name'] !== (string)$planned['old_name']
            || (int)$row['product_type'] !== (int)$planned['old_product_type']
            || (int)$row['is_show'] !== (int)$planned['old_is_show']) {
            throw new RuntimeException('Old root differs from the reviewed classification: ' . $id);
        }
        if (isset($planned['tag_occurrences'])) {
            $expectedTags = [];
            foreach (explode(',', (string)$row['work_tag']) as $index => $tag) {
                if (trim($tag) !== '') {
                    $expectedTags[] = ['index' => $index + 1, 'label' => trim($tag)];
                }
            }
            if ($expectedTags !== $planned['tag_occurrences']) {
                throw new RuntimeException('Card tags changed since review: ' . $id);
            }
        }
    }
    foreach ($children as $row) {
        $parent = $byRoot[(int)$row['pid']] ?? null;
        if (!$parent || !isset($plan['store_map'][(string)$row['relation_id']])
            || (int)$row['product_type'] !== (int)$parent['old_product_type']
            || (string)$row['store_name'] !== (string)$parent['old_name']) {
            throw new RuntimeException('Old child changed parent, store or product type');
        }
    }
    $allProducts = array_merge($roots, $children);
    $ids = implode(',', array_map(static function ($r) { return (int)$r['id']; }, $allProducts));
    $bundle = [
        'source_store' => $sourceStores,
        'category' => catalogRows($source, 'eb_store_product_category'),
        'brand' => catalogRows($source, 'eb_store_brand'),
        'label' => catalogRows($source, 'eb_store_product_label'),
        'unit' => catalogRows($source, 'eb_store_product_unit'),
        'label_category' => catalogRows($source, 'eb_category', 'id=1 AND `group`=2'),
        'product' => $allProducts,
        'attr' => catalogRows($source, 'eb_store_product_attr', 'product_id IN (' . $ids . ')'),
        'sku' => catalogRows($source, 'eb_store_product_attr_value', 'product_id IN (' . $ids . ')'),
        'attr_result' => catalogRows($source, 'eb_store_product_attr_result', 'product_id IN (' . $ids . ')'),
        'description' => catalogRows($source, 'eb_store_product_description', 'product_id IN (' . $ids . ')'),
        'category_brand' => catalogRows($source, 'eb_store_product_category_brand', 'product_id IN (' . $ids . ')'),
    ];
    foreach (['attr', 'sku', 'attr_result', 'description'] as $key) {
        if (count($bundle[$key]) !== 3273) {
            throw new RuntimeException('Old product ' . $key . ' row count changed');
        }
    }
    $skuByProduct = catalogSkuByProduct($bundle['sku']);
    foreach ($plan['roots'] as $planned) {
        $id = (int)$planned['old_id'];
        $sku = $skuByProduct[$id] ?? null;
        if (!$sku || (string)$sku['unique'] !== (string)$planned['old_sku_unique']
            || (int)$sku['write_times'] !== (int)$planned['old_write_times']) {
            throw new RuntimeException('Reviewed old root SKU changed: ' . $id);
        }
    }
    if (count($bundle['category']) !== 32 || count($bundle['brand']) !== 4
        || count($bundle['label']) !== 4 || count($bundle['unit']) !== 4
        || count($bundle['label_category']) !== 1) {
        throw new RuntimeException('Old catalogue supporting data changed');
    }
    $digest = hash('sha256', (string)json_encode($bundle,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return [$bundle, $digest];
}

/** Protect the existing 008 organization, Qiniu media and empty catalogue. */
function catalogTargetReady(PDO $target, array $plan): void
{
    foreach (CATALOG_TABLES as $table) {
        if ((int)$target->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
            throw new RuntimeException('008 catalogue table is no longer empty: ' . $table);
        }
    }
    $stmt = $target->prepare('SELECT upgrade_key FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $stmt->execute([CATALOG_UPGRADE_KEY]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Catalogue import marker already exists');
    }
    $bucket = $target->query("SELECT COUNT(*) FROM eb_system_storage WHERE type=2 AND name='meiyue01' AND status=1 AND is_delete=0")->fetchColumn();
    if ((int)$bucket !== 1) {
        throw new RuntimeException('008 reviewed image bucket is unavailable');
    }
    foreach ($plan['store_map'] as $old => $new) {
        $store = $target->query('SELECT id,is_del,business_status FROM eb_system_store WHERE id=' . (int)$new)->fetch();
        if (!$store || (int)$store['is_del'] !== 0 || (int)$store['business_status'] !== 1) {
            throw new RuntimeException('Mapped 008 store unavailable: old ID ' . $old);
        }
        $orgId = (int)$target->query('SELECT org_id FROM eb_organization_store WHERE store_id=' . (int)$new)->fetchColumn();
        $seen = [];
        while ($orgId > 0 && $orgId !== 3 && !isset($seen[$orgId])) {
            $seen[$orgId] = true;
            $orgId = (int)$target->query('SELECT pid FROM eb_organization WHERE id=' . $orgId . ' AND is_del=0')->fetchColumn();
        }
        if ($orgId !== 3) {
            throw new RuntimeException('Mapped store is not under 渼约: old ID ' . $old);
        }
    }
}

/** Insert source intersection plus explicit target overrides, preserving old IDs. */
function catalogInsert(PDO $target, string $table, array $sourceRow, array $overrides = []): int
{
    static $columnCache = [];
    if (!isset($columnCache[$table])) {
        $columnCache[$table] = array_column($target->query('SHOW COLUMNS FROM ' . $table)->fetchAll(), 'Field');
    }
    $row = array_intersect_key($sourceRow, array_flip($columnCache[$table]));
    foreach ($overrides as $key => $value) {
        if (!in_array($key, $columnCache[$table], true)) {
            throw new RuntimeException('Target column missing: ' . $table . '.' . $key);
        }
        $row[$key] = $value;
    }
    $names = array_keys($row);
    $quoted = array_map(static function ($n) { return '`' . $n . '`'; }, $names);
    $sql = 'INSERT INTO ' . $table . '(' . implode(',', $quoted) . ') VALUES('
        . implode(',', array_fill(0, count($names), '?')) . ')';
    $target->prepare($sql)->execute(array_values($row));
    return isset($row['id']) ? (int)$row['id'] : (int)$target->lastInsertId();
}

/** Stock in the cached editor JSON must agree with the zeroed SKU table. */
function catalogResetResult(string $json, int $newType): string
{
    $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result) || !isset($result['value']) || !is_array($result['value'])) {
        throw new RuntimeException('Old SKU editor result has an unexpected shape');
    }
    foreach ($result['value'] as &$value) {
        if (!is_array($value)) {
            throw new RuntimeException('Old SKU editor value has an unexpected shape');
        }
        $value['stock'] = '0';
        $value['product_type'] = $newType;
    }
    unset($value);
    return (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** A source card has exactly one SKU; its total times determines the rule. */
function catalogSkuByProduct(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $id = (int)$row['product_id'];
        if (isset($map[$id])) {
            throw new RuntimeException('Product has multiple SKUs; separate rule review required: ' . $id);
        }
        $map[$id] = $row;
    }
    return $map;
}

/** Place source categories in the same hierarchy used by the target editor. */
function catalogImportCategories(PDO $target, array $rows): void
{
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $pathFor = static function ($id) use (&$pathFor, $byId) {
        if ($id === 0) {
            return [0, ''];
        }
        if (!isset($byId[$id])) {
            throw new RuntimeException('Category parent missing: ' . $id);
        }
        $parent = $byId[$id];
        [$level, $path] = $pathFor((int)$parent['pid']);
        return [$level + 1, trim($path . ',' . $id, ',')];
    };
    foreach ($rows as $row) {
        [$level, $path] = $pathFor((int)$row['pid']);
        catalogInsert($target, 'eb_store_product_category', $row, ['level' => $level, 'path' => $path]);
    }
}

/** Create a hidden, zero-price service project only when a card tag lacks one. */
function catalogTagProject(PDO $target, string $name, int $storeId, array &$created): array
{
    $key = $name . '|' . $storeId;
    if (isset($created[$key])) {
        return $created[$key];
    }
    if ($storeId !== 0 && !isset($created[$name . '|0'])) {
        catalogTagProject($target, $name, 0, $created);
    }
    $parent = $storeId === 0 ? 0 : $created[$name . '|0']['product_id'];
    $unique = substr(hash('sha256', '008-tag-project|' . $name . '|' . $storeId), 0, 8);
    $product = [
        'pid' => $parent, 'type' => $storeId === 0 ? 0 : 1,
        'relation_id' => $storeId, 'product_type' => 6, 'store_name' => $name,
        'store_info' => '旧商品标签项目', 'unit_name' => '次', 'price' => 0,
        'stock' => 0, 'sales' => 0, 'ficti' => 0, 'browse' => 0,
        'image' => '', 'slider_image' => '', 'is_show' => 0, 'is_del' => 0,
        'is_inventory' => 0, 'allow_negative_stock' => 1,
        'salon_stock_enabled' => 0, 'is_show_stock' => 0, 'add_time' => time(),
        'keyword' => '008_LEGACY_TAG_' . substr(hash('sha256', $key), 0, 24),
    ];
    $productId = catalogInsert($target, 'eb_store_product', $product);
    catalogInsert($target, 'eb_store_product_attr', [
        'product_id' => $productId, 'attr_name' => '规格',
        // SKU 附属表的 type 表示活动目录，不表示门店归属；普通门店商品仍为 0。
        'attr_values' => '默认', 'type' => 0,
    ]);
    $sku = [
        'type' => 0, 'product_id' => $productId,
        'product_type' => 6, 'suk' => '默认', 'stock' => 0,
        'sum_stock' => 0, 'sales' => 0, 'price' => 0, 'cost' => 0,
        'image' => '', 'unique' => $unique, 'write_times' => 1,
        'write_valid' => 1,
    ];
    $skuId = catalogInsert($target, 'eb_store_product_attr_value', $sku);
    $result = ['attr' => [['value' => '规格', 'detail' => ['默认']]],
        'value' => [['pic' => '', 'price' => '0', 'cost' => '0', 'stock' => '0',
            'write_times' => '1', 'write_valid' => '1', 'product_type' => 6,
            'detail' => ['规格' => '默认']]]];
    catalogInsert($target, 'eb_store_product_attr_result', [
        'product_id' => $productId,
        'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'change_time' => time(), 'type' => 0,
    ]);
    catalogInsert($target, 'eb_store_product_description', [
        'product_id' => $productId, 'description' => '',
        'type' => 0,
    ]);
    return $created[$key] = ['product_id' => $productId, 'sku_id' => $skuId, 'unique' => $unique];
}

/** A card child may need a missing store copy of an existing single project. */
function catalogProjectForStore(PDO $target, int $oldRootId, int $storeId,
    array $oldProjectChildren, array &$created): array
{
    if ($storeId === 0) {
        $id = $oldRootId;
    } else {
        $id = (int)($oldProjectChildren[$oldRootId][$storeId] ?? 0);
        if ($id === 0) {
            $key = 'source:' . $oldRootId . '|store:' . $storeId;
            if (!isset($created[$key])) {
                $root = $target->query('SELECT * FROM eb_store_product WHERE id=' . $oldRootId)->fetch();
                if (!$root || (int)$root['product_type'] !== 6) {
                    throw new RuntimeException('Reviewed single project missing');
                }
                unset($root['id']);
                $root['pid'] = $oldRootId;
                $root['type'] = 1;
                $root['relation_id'] = $storeId;
                $root['is_show'] = 0;
                $id = catalogInsert($target, 'eb_store_product', $root);
                $sourceSku = $target->query('SELECT * FROM eb_store_product_attr_value WHERE product_id=' . $oldRootId)->fetch();
                $sourceAttr = $target->query('SELECT * FROM eb_store_product_attr WHERE product_id=' . $oldRootId)->fetch();
                $sourceResult = $target->query('SELECT * FROM eb_store_product_attr_result WHERE product_id=' . $oldRootId)->fetch();
                $sourceDetail = $target->query('SELECT * FROM eb_store_product_description WHERE product_id=' . $oldRootId)->fetch();
                if (!$sourceSku || !$sourceAttr || !$sourceResult || !$sourceDetail) {
                    throw new RuntimeException('Single project copy source incomplete');
                }
                unset($sourceSku['id'], $sourceAttr['id'], $sourceResult['id']);
                $sourceSku['product_id'] = $id;
                // 仅商品主表使用 type=1 表示门店；规格及详情仍属于 type=0 普通目录。
                // 保留确定性 unique，避免卡项关联与后续目录写锁使用不同身份。
                $sourceSku['type'] = 0;
                $sourceSku['unique'] = substr(hash('sha256', '008-source-project|' . $oldRootId . '|' . $storeId), 0, 8);
                $sourceAttr['product_id'] = $id;
                $sourceAttr['type'] = 0;
                $sourceResult['product_id'] = $id;
                $sourceResult['type'] = 0;
                $sourceDetail['product_id'] = $id;
                $sourceDetail['type'] = 0;
                catalogInsert($target, 'eb_store_product_attr', $sourceAttr);
                catalogInsert($target, 'eb_store_product_attr_value', $sourceSku);
                catalogInsert($target, 'eb_store_product_attr_result', $sourceResult);
                catalogInsert($target, 'eb_store_product_description', $sourceDetail);
                $created[$key] = ['product_id' => $id, 'unique' => $sourceSku['unique']];
            }
            return $created[$key];
        }
    }
    $sku = $target->query('SELECT `unique` FROM eb_store_product_attr_value WHERE product_id=' . $id)->fetch();
    if (!$sku || (string)$sku['unique'] === '') {
        throw new RuntimeException('Mapped single project SKU missing: ' . $id);
    }
    return ['product_id' => $id, 'unique' => (string)$sku['unique']];
}

/** Copy only reviewed master data and build card/project links in one transaction. */
function catalogApply(PDO $target, array $bundle, array $plan,
    string $sourceDigest, string $backupSha, string $commit): array
{
    $rootPlan = [];
    foreach ($plan['roots'] as $row) {
        $rootPlan[(int)$row['old_id']] = $row;
    }
    $skuSource = catalogSkuByProduct($bundle['sku']);
    $counts = ['original_products' => 0, 'synthetic_projects' => 0, 'card_relations' => 0];
    catalogImportCategories($target, $bundle['category']);
    foreach ($bundle['brand'] as $row) {
        catalogInsert($target, 'eb_store_brand', $row);
    }
    foreach ($bundle['label'] as $row) {
        catalogInsert($target, 'eb_store_product_label', $row,
            ['type' => 0, 'relation_id' => 0, 'is_show' => 1, 'status' => 1]);
    }
    foreach ($bundle['unit'] as $row) {
        catalogInsert($target, 'eb_store_product_unit', $row, ['type' => 0, 'relation_id' => 0]);
    }
    catalogInsert($target, 'eb_category', $bundle['label_category'][0], ['relation_id' => 0]);
    $oldProjectChildren = [];
    $cardProducts = [];
    foreach ($bundle['product'] as $row) {
        $oldId = (int)$row['id'];
        $oldRoot = (int)$row['pid'] ?: $oldId;
        $planned = $rootPlan[$oldRoot] ?? null;
        if (!$planned || !isset($skuSource[$oldId])) {
            throw new RuntimeException('Unreviewed product or missing SKU: ' . $oldId);
        }
        $storeId = (int)$row['type'] === 0 ? 0 : (int)$plan['store_map'][(string)$row['relation_id']];
        $newType = (int)$planned['target_type'];
        $skuTimes = (int)$skuSource[$oldId]['write_times'];
        $rule = $planned['kind'] === 'card' ? $planned['card_rule'] : '';
        if ($rule !== '' && $skuTimes <= 0) {
            throw new RuntimeException('Reviewed card SKU has no positive total times');
        }
        $overrides = [
            'relation_id' => $storeId, 'product_type' => $newType,
            'stock' => 0, 'sales' => 0, 'ficti' => 0, 'browse' => 0,
            'is_inventory' => 0, 'allow_negative_stock' => 1,
            'salon_stock_enabled' => 0, 'is_show_stock' => 0,
            'is_show' => $planned['kind'] === 'shell' ? 0 : (int)$row['is_show'],
            'card_rule_type' => $rule,
            'card_rule_version' => $rule === '' ? 0 : 1,
            'card_choice_limit' => 0,
            'card_shared_times' => $rule === 'choice_count' ? $skuTimes : 0,
            'card_num' => $rule === 'choice_count' ? $skuTimes : 0,
            'card_num_type' => $rule === 'choice_count' ? 1 : 0,
        ];
        catalogInsert($target, 'eb_store_product', $row, $overrides);
        $counts['original_products']++;
        if ($newType === 6 && $storeId !== 0) {
            $oldProjectChildren[$oldRoot][$storeId] = $oldId;
        }
        if ($newType === 5 && $planned['kind'] === 'card') {
            $cardProducts[] = ['id' => $oldId, 'root' => $oldRoot, 'store' => $storeId, 'times' => $skuTimes];
        }
    }
    foreach ($bundle['attr'] as $row) {
        catalogInsert($target, 'eb_store_product_attr', $row);
    }
    foreach ($bundle['sku'] as $row) {
        $oldRoot = (int)$target->query('SELECT pid FROM eb_store_product WHERE id=' . (int)$row['product_id'])->fetchColumn();
        $root = $oldRoot ?: (int)$row['product_id'];
        catalogInsert($target, 'eb_store_product_attr_value', $row, [
            'product_type' => (int)$rootPlan[$root]['target_type'],
            'stock' => 0, 'sum_stock' => 0, 'sales' => 0,
            'quota' => 0, 'quota_show' => 0,
        ]);
    }
    foreach ($bundle['attr_result'] as $row) {
        $oldRoot = (int)$target->query('SELECT pid FROM eb_store_product WHERE id=' . (int)$row['product_id'])->fetchColumn();
        $root = $oldRoot ?: (int)$row['product_id'];
        catalogInsert($target, 'eb_store_product_attr_result', $row, [
            'result' => catalogResetResult((string)$row['result'], (int)$rootPlan[$root]['target_type']),
        ]);
    }
    foreach ($bundle['description'] as $row) {
        // Do not decode/re-encode the old escaped HTML: its images and details are source bytes.
        catalogInsert($target, 'eb_store_product_description', $row);
    }
    foreach ($bundle['category_brand'] as $row) {
        catalogInsert($target, 'eb_store_product_category_brand', $row);
    }
    $created = [];
    foreach ($cardProducts as $card) {
        $definition = $rootPlan[$card['root']];
        $relatedSeen = [];
        foreach ($definition['related'] as $ref) {
            if (isset($ref['old_project_id'])) {
                $project = catalogProjectForStore($target, (int)$ref['old_project_id'],
                    $card['store'], $oldProjectChildren, $created);
            } else {
                $project = catalogTagProject($target, (string)$ref['new_tag_project_name'],
                    $card['store'], $created);
            }
            $relationKey = $project['product_id'] . ':' . $project['unique'];
            if (isset($relatedSeen[$relationKey])) {
                throw new RuntimeException('Duplicate project relation in fixed card plan');
            }
            $relatedSeen[$relationKey] = true;
            $target->prepare('INSERT INTO eb_store_card_related
                (card_product_id,product_id,product_type,product_attr_unique,cost,price,write_times,writeoff_amount,status,add_time)
                VALUES(?,?,6,?,0,0,?,0,1,?)')->execute([
                $card['id'], $project['product_id'], $project['unique'],
                $definition['card_rule'] === 'normal' ? $card['times'] : 0, time(),
            ]);
            $counts['card_relations']++;
        }
    }
    $counts['synthetic_projects'] = count($created);
    $counts['categories'] = count($bundle['category']);
    $counts['cards_with_rules'] = count($cardProducts);
    $note = json_encode([
        'source_sha256' => $sourceDigest, 'plan_sha256' => hash_file('sha256', $GLOBALS['catalog_plan_path']),
        'backup_sha256' => $backupSha, 'counts' => $counts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $target->prepare('INSERT INTO eb_database_upgrade_log
        (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
        VALUES(?,?,?,?,?,NOW(),?,?)')->execute([
        CATALOG_UPGRADE_KEY, '008 渼约商品分类及商品导入',
        'scripts/import/customer_008_morestore_catalog_apply.php',
        hash_file('sha256', __FILE__), $commit, 'Codex', $note,
    ]);
    return $counts;
}

/** Reconcile the committed batch against source media, card rules and scope. */
function catalogVerify(PDO $target, array $bundle, array $plan, string $digest): array
{
    $statement = $target->prepare('SELECT result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $statement->execute([CATALOG_UPGRADE_KEY]);
    $markers = $statement->fetchAll();
    if (count($markers) !== 1) {
        throw new RuntimeException('Expected one committed 008 catalogue marker');
    }
    $note = json_decode((string)$markers[0]['result_note'], true, 512, JSON_THROW_ON_ERROR);
    if (($note['source_sha256'] ?? '') !== $digest
        || ($note['plan_sha256'] ?? '') !== hash_file('sha256', $GLOBALS['catalog_plan_path'])) {
        throw new RuntimeException('Committed marker differs from reviewed catalogue');
    }
    $sourceProduct = [];
    $sourceSku = [];
    $sourceDetail = [];
    foreach ($bundle['product'] as $row) {
        $sourceProduct[(int)$row['id']] = $row;
    }
    foreach ($bundle['sku'] as $row) {
        $sourceSku[(int)$row['id']] = $row;
    }
    foreach ($bundle['description'] as $row) {
        $sourceDetail[(int)$row['product_id']] = (string)$row['description'];
    }
    $rootPlan = [];
    foreach ($plan['roots'] as $row) {
        $rootPlan[(int)$row['old_id']] = $row;
    }
    $originalCount = 0;
    foreach ($target->query('SELECT id,pid,relation_id,product_type,image,slider_image,is_show,stock,sales,ficti,browse,is_inventory,salon_stock_enabled,is_show_stock FROM eb_store_product') as $row) {
        $old = $sourceProduct[(int)$row['id']] ?? null;
        if ($old === null) {
            if ((int)$row['product_type'] !== 6 || (int)$row['is_show'] !== 0) {
                throw new RuntimeException('Unreviewed synthetic catalogue product is visible');
            }
            continue;
        }
        $root = (int)$old['pid'] ?: (int)$old['id'];
        $planned = $rootPlan[$root];
        $expectedStore = (int)$old['type'] === 0 ? 0 : (int)$plan['store_map'][(string)$old['relation_id']];
        if ((int)$row['pid'] !== (int)$old['pid']
            || (int)$row['relation_id'] !== $expectedStore
            || (int)$row['product_type'] !== (int)$planned['target_type']
            || (string)$row['image'] !== (string)$old['image']
            || (string)$row['slider_image'] !== (string)$old['slider_image']
            || (int)$row['is_show'] !== ($planned['kind'] === 'shell' ? 0 : (int)$old['is_show'])
            || (int)$row['stock'] !== 0 || (int)$row['sales'] !== 0
            || (int)$row['ficti'] !== 0 || (int)$row['browse'] !== 0
            || (int)$row['is_inventory'] !== 0
            || (int)$row['salon_stock_enabled'] !== 0
            || (int)$row['is_show_stock'] !== 0) {
            throw new RuntimeException('Imported product drifted: old ID ' . $old['id']);
        }
        $originalCount++;
    }
    if ($originalCount !== 3273) {
        throw new RuntimeException('Original 008 product coverage differs from source');
    }
    $oldSkuCount = 0;
    foreach ($target->query('SELECT id,product_id,product_type,image,`unique`,stock,sum_stock,sales FROM eb_store_product_attr_value') as $sku) {
        $old = $sourceSku[(int)$sku['id']] ?? null;
        if ($old === null) {
            continue;
        }
        $sourceProductRow = $sourceProduct[(int)$old['product_id']];
        $root = (int)$sourceProductRow['pid'] ?: (int)$sourceProductRow['id'];
        if ((int)$sku['product_id'] !== (int)$old['product_id']
            || (int)$sku['product_type'] !== (int)$rootPlan[$root]['target_type']
            || (string)$sku['image'] !== (string)$old['image']
            || (string)$sku['unique'] !== (string)$old['unique']
            || (int)$sku['stock'] !== 0 || (int)$sku['sum_stock'] !== 0
            || (int)$sku['sales'] !== 0) {
            throw new RuntimeException('Imported SKU drifted: old SKU ID ' . $old['id']);
        }
        $oldSkuCount++;
    }
    if ($oldSkuCount !== 3273) {
        throw new RuntimeException('Original 008 SKU coverage differs from source');
    }
    $detailCount = 0;
    foreach ($target->query('SELECT product_id,description FROM eb_store_product_description') as $detail) {
        if (!array_key_exists((int)$detail['product_id'], $sourceDetail)) {
            continue;
        }
        if ((string)$detail['description'] !== $sourceDetail[(int)$detail['product_id']]) {
            throw new RuntimeException('Imported detail HTML drifted: old product ID ' . $detail['product_id']);
        }
        $detailCount++;
    }
    if ($detailCount !== 3273) {
        throw new RuntimeException('Original 008 detail coverage differs from source');
    }
    $related = (int)$target->query('SELECT COUNT(*) FROM eb_store_card_related')->fetchColumn();
    $invalidRelated = (int)$target->query('SELECT COUNT(*) FROM eb_store_card_related r
        JOIN eb_store_product c ON c.id=r.card_product_id
        LEFT JOIN eb_store_product p ON p.id=r.product_id
        WHERE p.id IS NULL OR p.product_type<>6 OR c.type<>p.type OR c.relation_id<>p.relation_id')->fetchColumn();
    $badChoice = (int)$target->query("SELECT COUNT(*) FROM eb_store_card_related r
        JOIN eb_store_product c ON c.id=r.card_product_id
        WHERE c.card_rule_type='choice_count' AND r.write_times<>0")->fetchColumn();
    if ($related !== (int)($note['counts']['card_relations'] ?? -1)
        || $invalidRelated !== 0 || $badChoice !== 0
        || (int)$target->query('SELECT COUNT(*) FROM eb_store_product_category')->fetchColumn() !== 32
        || (int)$target->query('SELECT COUNT(*) FROM eb_store_product')->fetchColumn()
            !== 3273 + (int)($note['counts']['synthetic_projects'] ?? -1)) {
        throw new RuntimeException('008 catalogue relationship or batch counts differ');
    }
    return ['original_products' => $originalCount, 'original_skus' => $oldSkuCount,
        'original_details' => $detailCount, 'card_relations' => $related,
        'synthetic_projects' => (int)$note['counts']['synthetic_projects'],
        'invalid_related' => $invalidRelated, 'bad_choice_component' => $badChoice];
}

/** The CLI never echoes source credentials, product detail HTML or member data. */
function catalogMain(array $argv): void
{
    $mode = (string)($argv[1] ?? '');
    $planPath = (string)($argv[2] ?? '');
    if (!in_array($mode, ['fingerprint', 'check', 'apply', 'verify'], true) || $planPath === '') {
        throw new RuntimeException('Usage: php catalog_apply.php fingerprint|check|apply|verify plan.json [backup sha256 commit]');
    }
    $GLOBALS['catalog_plan_path'] = $planPath;
    $plan = catalogPlan($planPath);
    $source = catalogConnect(CATALOG_SOURCE_ENV, 'morestore', 3306);
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->beginTransaction();
    try {
        [$bundle, $digest] = catalogSource($source, $plan);
        if ($mode === 'fingerprint') {
            echo json_encode(['ok' => true, 'source_sha256' => $digest,
                'original_products' => count($bundle['product'])]) . PHP_EOL;
            return;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string)($plan['source_catalog_sha256'] ?? ''))
            || !hash_equals($plan['source_catalog_sha256'], $digest)) {
            throw new RuntimeException('Old catalogue changed since the fixed plan');
        }
        $target = catalogConnect(CATALOG_TARGET_ENV, 'mohe_008', 3307);
        if ($mode === 'verify') {
            $result = catalogVerify($target, $bundle, $plan, $digest);
            echo json_encode(['ok' => true, 'mode' => 'verified', 'result' => $result],
                JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return;
        }
        if ($mode === 'check') {
            catalogTargetReady($target, $plan);
            echo json_encode(['ok' => true, 'mode' => 'ready', 'source_sha256' => $digest,
                'plan_sha256' => hash_file('sha256', $planPath)]) . PHP_EOL;
            return;
        }
        $backup = (string)($argv[3] ?? '');
        $backupSha = (string)($argv[4] ?? '');
        $commit = (string)($argv[5] ?? '');
        if (strpos($backup, CATALOG_BACKUP_PREFIX) !== 0
            || !preg_match('/^[a-f0-9]{64}$/', $backupSha)
            || !preg_match('/^[a-f0-9]{40}$/', $commit)
            || !is_file($backup) || filesize($backup) < 1024
            || !hash_equals($backupSha, (string)hash_file('sha256', $backup))) {
            throw new RuntimeException('Verified 008-only backup and fixed commit are required');
        }
        $got = (int)$target->query("SELECT GET_LOCK('008_morestore_catalog_import',15)")->fetchColumn();
        if ($got !== 1) {
            throw new RuntimeException('008 catalogue import lock unavailable');
        }
        try {
            $target->beginTransaction();
            try {
                catalogTargetReady($target, $plan);
                $counts = catalogApply($target, $bundle, $plan, $digest, $backupSha, $commit);
                $target->commit();
            } catch (Throwable $error) {
                if ($target->inTransaction()) {
                    $target->rollBack();
                }
                throw $error;
            }
        } finally {
            $target->query("SELECT RELEASE_LOCK('008_morestore_catalog_import')");
        }
        echo json_encode(['ok' => true, 'mode' => 'applied', 'counts' => $counts,
            'source_sha256' => $digest, 'plan_sha256' => hash_file('sha256', $planPath)],
            JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } finally {
        if ($source->inTransaction()) {
            $source->rollBack();
        }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        catalogMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, '008 catalogue import rejected: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

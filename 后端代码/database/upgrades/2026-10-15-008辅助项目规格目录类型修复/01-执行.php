<?php
declare(strict_types=1);

/**
 * 008 商品导入辅助项目修复：普通目录附属资料 type=0，不继承主商品门店 type=1。
 * 仅识别固定导入计划和确定性 SKU 标识，保留全部 ID、价格、卡项引用和会员权益。
 * check 只读；apply 比对固定计划、按卡→商品→SKU 加锁、先落独立快照再事务纠正。
 */
const M008_SKU_TYPE_KEY = '20261015-008-generated-project-catalog-type';
const M008_SKU_TYPE_TABLES = [
    'store_product_attr', 'store_product_attr_value',
    'store_product_attr_result', 'store_product_description',
];

/** 计划只接受本次导入生成的门店项目；不把活动商品、原始商品或有冲突的规格混入。 */
function m008SkuTypePlan(PDO $db, array $catalogPlan): array
{
    $stores = array_map('intval', array_values($catalogPlan['store_map']));
    $sourceProjects = [];
    foreach ($catalogPlan['roots'] as $root) {
        if ((int)$root['target_type'] === 6) $sourceProjects[] = (int)$root['old_id'];
    }
    $candidates = $db->query('SELECT p.* FROM eb_store_product p WHERE EXISTS
        (SELECT 1 FROM eb_store_product_attr_value s WHERE s.product_id=p.id AND s.type=1)
        ORDER BY p.id')->fetchAll(PDO::FETCH_ASSOC);
    $plan = ['products' => [], 'rows' => [], 'relations' => [], 'cards' => [], 'parents' => []];
    foreach ($candidates as $product) {
        $id = (int)$product['id'];
        $parentId = (int)$product['pid'];
        $store = (int)$product['relation_id'];
        if ((int)$product['type'] !== 1 || (int)$product['product_type'] !== 6
            || !in_array($store, $stores, true) || $parentId <= 0) {
            throw new RuntimeException('Unexpected activity-typed SKU product: ' . $id);
        }
        $parent = $db->query('SELECT * FROM eb_store_product WHERE id=' . $parentId)->fetch(PDO::FETCH_ASSOC);
        if (!$parent || (int)$parent['type'] !== 0 || (int)$parent['product_type'] !== 6) {
            throw new RuntimeException('Generated project parent changed: ' . $id);
        }
        if (strpos((string)$product['keyword'], '008_LEGACY_TAG_') === 0) {
            $name = (string)$product['store_name'];
            $expectedKeyword = '008_LEGACY_TAG_' . substr(hash('sha256', $name . '|' . $store), 0, 24);
            if ($product['keyword'] !== $expectedKeyword || $parent['store_name'] !== $name
                || $parent['keyword'] !== '008_LEGACY_TAG_' . substr(hash('sha256', $name . '|0'), 0, 24)) {
                throw new RuntimeException('Generated tag provenance changed: ' . $id);
            }
            $expectedUnique = substr(hash('sha256', '008-tag-project|' . $name . '|' . $store), 0, 8);
        } else {
            if (!in_array($parentId, $sourceProjects, true)) {
                throw new RuntimeException('Generated source parent outside fixed plan: ' . $id);
            }
            $expectedUnique = substr(hash('sha256', '008-source-project|' . $parentId . '|' . $store), 0, 8);
        }
        foreach (M008_SKU_TYPE_TABLES as $table) {
            $rows = $db->query('SELECT * FROM eb_' . $table . ' WHERE product_id=' . $id)->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1 || (int)$rows[0]['type'] !== 1) {
                throw new RuntimeException('Missing, duplicate or mixed catalogue metadata: ' . $table . '/' . $id);
            }
            if ($table === 'store_product_attr_value' && ($rows[0]['unique'] !== $expectedUnique
                || $rows[0]['suk'] !== '默认' || (int)$rows[0]['product_type'] !== 6)) {
                throw new RuntimeException('Generated SKU identity changed: ' . $id);
            }
            $plan['rows'][$table][$id] = $rows[0];
        }
        $plan['products'][$id] = $product;
        $plan['parents'][$parentId] = $parent;
    }
    if ($plan['products']) {
        $ids = implode(',', array_keys($plan['products']));
        $plan['relations'] = $db->query('SELECT * FROM eb_store_card_related WHERE product_id IN (' . $ids . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plan['relations'] as $relation) {
            $productId = (int)$relation['product_id'];
            if ($relation['product_attr_unique'] !== $plan['rows']['store_product_attr_value'][$productId]['unique']) {
                throw new RuntimeException('Card relation identity mismatch: ' . $relation['id']);
            }
            $plan['cards'][(int)$relation['card_product_id']] = (int)$relation['card_product_id'];
        }
        sort($plan['cards'], SORT_NUMERIC);
    }
    ksort($plan['parents'], SORT_NUMERIC);
    return $plan;
}

/** 指纹覆盖完整待改行及其归属、卡项引用，不只比较数量。 */
function m008SkuTypeHash(array $plan): string
{
    return hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** 线上审计列仅 varchar(500)：完整回执输出到终端，库内仅存对账及回滚必要摘要。 */
function m008SkuTypeAuditNote(array $result): string
{
    $note = json_encode([
        'projects' => $result['products'], 'rows' => array_sum($result['updated']),
        'plan_sha256' => $result['plan_sha256'], 'backup' => $result['backup'],
        'backup_sha256' => $result['backup_sha256'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($note) > 500) throw new RuntimeException('Repair audit summary exceeds schema limit');
    return $note;
}

/** 只纠正 type；写前快照和写后逐字段对账保证不是重建 SKU 或重新导入商品。 */
function m008SkuTypeWrite(PDO $db, array $plan): array
{
    if (!$db->inTransaction()) throw new RuntimeException('Repair requires owned transaction');
    $counts = [];
    foreach (M008_SKU_TYPE_TABLES as $table) {
        $statement = $db->prepare('UPDATE eb_' . $table . ' SET type=0 WHERE product_id=? AND type=1');
        $counts[$table] = 0;
        foreach (array_keys($plan['products']) as $id) {
            $statement->execute([$id]);
            if ($statement->rowCount() !== 1) throw new RuntimeException('Repair row count changed: ' . $table . '/' . $id);
            $counts[$table]++;
        }
    }
    m008SkuTypeVerify($db, $plan);
    return $counts;
}

/** 与独立快照比对：四表除 type 外、商品主档、父项目和卡项关系必须逐字节不变。 */
function m008SkuTypeVerify(PDO $db, array $plan): void
{
    foreach (['products', 'parents'] as $group) {
        foreach ($plan[$group] as $id => $expected) {
            $actual = $db->query('SELECT * FROM eb_store_product WHERE id=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
            if ($actual !== $expected) throw new RuntimeException('Product changed: ' . $id);
        }
    }
    foreach (M008_SKU_TYPE_TABLES as $table) {
        foreach ($plan['rows'][$table] as $id => $expected) {
            $expected['type'] = '0';
            $actual = $db->query('SELECT * FROM eb_' . $table . ' WHERE product_id=' . (int)$id)->fetchAll(PDO::FETCH_ASSOC);
            if (count($actual) !== 1 || $actual[0] !== $expected) {
                throw new RuntimeException('Metadata changed beyond type: ' . $table . '/' . $id);
            }
        }
    }
    $ids = implode(',', array_map('intval', array_keys($plan['products'])));
    $relations = $db->query('SELECT * FROM eb_store_card_related WHERE product_id IN (' . $ids . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($relations !== $plan['relations']) throw new RuntimeException('Card relations changed');
}

/** CLI 只允许独立 008:3307；没有固定指纹、提交和独立回滚路径不得执行写入。 */
function m008SkuTypeMain(): void
{
    $options = getopt('', ['action:', 'expected-sha:', 'commit:', 'plan:', 'backup-dir:']);
    $action = $options['action'] ?? 'check';
    if (!in_array($action, ['check', 'apply'], true)) throw new RuntimeException('Unknown action');
    $root = '/www/wwwroot/008.cc3798.com';
    $env = parse_ini_file($root . '/.env');
    if (($env['DATABASE'] ?? '') !== 'mohe_008' || (int)($env['HOSTPORT'] ?? 0) !== 3307
        || !in_array($env['HOSTNAME'] ?? '', ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Wrong target instance');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',
        $env['USERNAME'], $env['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => true]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'mohe_008'
        || (int)$db->query('SELECT @@port')->fetchColumn() !== 3307) throw new RuntimeException('Wrong connection');
    $planPath = $options['plan'] ?? dirname(__DIR__, 4) . '/scripts/import/customer_008_morestore_catalog_plan.json';
    $catalogPlan = json_decode(file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
    if (($catalogPlan['target_instance'] ?? '') !== '008.cc3798.com'
        || count($catalogPlan['store_map'] ?? []) !== 22 || count($catalogPlan['roots'] ?? []) !== 160) {
        throw new RuntimeException('Wrong catalogue plan');
    }
    $import = $db->query("SELECT result_note FROM eb_database_upgrade_log WHERE upgrade_key='20261013-008-morestore-catalog-r1'")->fetchColumn();
    $importNote = $import ? json_decode($import, true, 512, JSON_THROW_ON_ERROR) : [];
    // 历史导入记录不改写；本次只接受与该记录逐字节一致的原固定映射计划。
    if ((int)($importNote['counts']['synthetic_projects'] ?? 0) !== 914
        || !hash_equals((string)($importNote['plan_sha256'] ?? ''), hash_file('sha256', $planPath))) {
        throw new RuntimeException('Original import marker missing or changed');
    }
    $existing = $db->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $existing->execute([M008_SKU_TYPE_KEY]);
    if ((int)$existing->fetchColumn() !== 0) throw new RuntimeException('Repair already committed; do not replay');
    $db->exec($action === 'check' ? 'SET TRANSACTION READ ONLY' : 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try {
        $plan = m008SkuTypePlan($db, $catalogPlan);
        $hash = m008SkuTypeHash($plan);
        if (count($plan['products']) !== 870) throw new RuntimeException('Expected exactly 870 generated projects');
        $result = ['action' => $action, 'products' => count($plan['products']), 'relations' => count($plan['relations']), 'plan_sha256' => $hash];
        if ($action === 'apply') {
            if (!hash_equals($hash, (string)($options['expected-sha'] ?? ''))
                || !preg_match('/^[a-f0-9]{40}$/D', (string)($options['commit'] ?? ''))) {
                throw new RuntimeException('Fixed plan or accepted commit required');
            }
            // 与在线目录写入保持全局顺序。禁止边修复边补拿低序卡锁；锁后重读整批计划。
            foreach ($plan['cards'] as $id) $db->query('SELECT id FROM eb_store_product WHERE id=' . (int)$id . ' FOR UPDATE')->fetch();
            $products = array_unique(array_merge(array_keys($plan['products']), array_keys($plan['parents'])));
            sort($products, SORT_NUMERIC);
            foreach ($products as $id) $db->query('SELECT id FROM eb_store_product WHERE id=' . (int)$id . ' FOR UPDATE')->fetch();
            $skus = array_column($plan['rows']['store_product_attr_value'], 'id');
            sort($skus, SORT_NUMERIC);
            foreach ($skus as $id) $db->query('SELECT id FROM eb_store_product_attr_value WHERE id=' . (int)$id . ' FOR UPDATE')->fetch();
            if (!hash_equals($hash, m008SkuTypeHash(m008SkuTypePlan($db, $catalogPlan)))) {
                throw new RuntimeException('Plan changed while locking; no data written');
            }
            $directory = (string)($options['backup-dir'] ?? '');
            if (!preg_match('#^/www/backups/008\.cc3798\.com/[a-zA-Z0-9_-]+$#D', $directory)
                || file_exists($directory) || !mkdir($directory, 0700, true)) {
                throw new RuntimeException('New isolated 008 rollback directory required');
            }
            $backup = $directory . '/catalog-type-before.json';
            $bytes = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $handle = fopen($backup, 'x');
            if (!$handle) throw new RuntimeException('Cannot create exclusive rollback snapshot');
            chmod($backup, 0600);
            $written = fwrite($handle, $bytes);
            fflush($handle);
            fclose($handle);
            if ($written !== strlen($bytes) || hash_file('sha256', $backup) !== hash('sha256', $bytes)) {
                throw new RuntimeException('Rollback snapshot verification failed');
            }
            $result['backup'] = $backup;
            $result['backup_sha256'] = hash_file('sha256', $backup);
            $result['updated'] = m008SkuTypeWrite($db, $plan);
            $result['commit'] = $options['commit'];
            $db->prepare('INSERT INTO eb_database_upgrade_log
                (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
                VALUES(?,?,?,?,?,NOW(),?,?)')->execute([M008_SKU_TYPE_KEY,
                    '008辅助项目普通目录规格类型修复', 'database/upgrades/2026-10-15-008辅助项目规格目录类型修复/01-执行.php',
                    hash_file('sha256', __FILE__), $options['commit'], 'Codex', m008SkuTypeAuditNote($result)]);
            $db->commit();
        } else {
            $db->rollBack();
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) m008SkuTypeMain();

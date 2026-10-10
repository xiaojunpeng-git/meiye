<?php
declare(strict_types=1);

/** Repair only uniquely identified products on the original JSJ/SWXT consumption-debt import. */
function batchOption(array $args, string $name): string
{
    foreach ($args as $arg) if (strpos($arg, $name.'=') === 0) return substr($arg, strlen($name) + 1);
    return '';
}

/** Use the executed import's grouping key; item names alone are never product identity. */
function batchSourceKey(string $source, array $row): string
{
    return hash('sha256', json_encode([$source, (string)$row['ShopId'], (string)$row['CustomerId'],
        (string)$row['CardDetailId'], (string)$row['DebtDetail'], (string)$row['SaleDate']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Money guards compare decimal source and target values in integer cents. */
function batchCents(string $value): int
{
    if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $match)) throw new RuntimeException('invalid amount');
    return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
}

/** Import remarks are provenance only and may not supply SQL fragments. */
function batchProvenance(string $remark): array
{
    $parts = [];
    foreach (explode(';', $remark) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) === 2) $parts[$pair[0]] = $pair[1];
    }
    foreach (['source_db', 'source_kind', 'source_key', 'source_customer', 'source_shop', 'source_amount'] as $key) {
        if (($parts[$key] ?? '') === '') throw new RuntimeException('incomplete provenance');
    }
    if (!in_array($parts['source_db'], ['jsj', 'swxt'], true) || $parts['source_kind'] !== 'CONSUME'
        || !preg_match('/^[a-f0-9]{64}$/D', $parts['source_key'])) throw new RuntimeException('invalid provenance');
    return $parts;
}

/** One source scan reproduces the original import groups while retaining original item IDs. */
function batchSourceGroups(PDO $db, string $source): array
{
    if (!in_array($source, ['jsj', 'swxt'], true)) throw new RuntimeException('invalid source database');
    $scope = $source === 'swxt'
        ? "JOIN swxt.bd_Customers owner ON owner.Id=h.CustomerId AND owner.Shop IN ('720092000110','720092000112','720092000114','720092000115')"
        : '';
    $parts = [];
    foreach ([
        ['SS_ConsumeProjectDetail', 'bd_ProjectSet', 'ProjectId', 'ProjectName'],
        ['SS_ConsumeGoodsDetail', 'bd_goods', 'GoodsId', 'GoodsName'],
    ] as [$detail, $catalog, $id, $name]) {
        $parts[] = "SELECT h.ShopId,h.CustomerId,d.CardDetailId,h.SaleDate,p.$name DebtDetail,
            CONVERT(d.$id USING utf8mb4) source_item_id,'$catalog' source_table,
            COALESCE(d.QianKuan,0)-COALESCE(d.HandBalance,0) NonePay
            FROM $source.SS_Consume h JOIN $source.$detail d ON d.MDID=h.MDID
            LEFT JOIN $source.$catalog p ON p.Id=d.$id $scope
            WHERE h.fstate='1' AND COALESCE(h.Pstate,'0')<>'1'
              AND COALESCE(d.QianKuan,0)-COALESCE(d.HandBalance,0)<>0";
    }
    $sql = 'SELECT ShopId,CustomerId,CardDetailId,SaleDate,DebtDetail,
        ROUND(SUM(NonePay),2) source_amount,ROUND(SUM(NonePay),0) target_amount,
        COUNT(DISTINCT CONCAT(source_table,":",source_item_id)) identity_count,
        MIN(source_table) source_table,MIN(source_item_id) source_item_id
        FROM ('.implode(' UNION ALL ', $parts).') detail
        GROUP BY ShopId,CustomerId,CardDetailId,DebtDetail,SaleDate
        HAVING ROUND(SUM(NonePay),2)<>0';
    $groups = [];
    foreach ($db->query($sql) as $row) {
        $key = batchSourceKey($source, $row);
        if (isset($groups[$key])) throw new RuntimeException('source key collision');
        $groups[$key] = $row;
    }
    return $groups;
}

/** Migration maps must resolve to exactly one active product belonging to the debt's store. */
function batchMappedProduct(PDO $db, string $source, string $table, string $sourceId, int $storeId): array
{
    $type = $table === 'bd_goods' ? 0 : 6;
    if ($source === 'jsj') {
        $query = $db->prepare("SELECT target_id FROM mig_rh_source_map WHERE source_table=? AND source_pk=? AND target_type='store_product'");
        $query->execute([$table, $sourceId]);
        $roots = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($roots) !== 1) throw new RuntimeException('root mapping missing or ambiguous');
        $root = (int)$roots[0];
        $prefix = $table === 'bd_goods' ? 'RH_GOODS:' : 'RH_PROJECT:';
        $query = $db->prepare('SELECT id,pid,type,relation_id,product_type,is_del,cate_id FROM eb_store_product WHERE keyword=?');
        $query->execute([$prefix.$sourceId.':STORE:'.$storeId]);
    } else {
        $query = $db->prepare('SELECT target_product_id FROM mig_swxt_20261003_product WHERE source_table=? AND source_id=?');
        $query->execute([$table, $sourceId]);
        $roots = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($roots) !== 1) throw new RuntimeException('root mapping missing or ambiguous');
        $root = (int)$roots[0];
        $query = $db->prepare('SELECT p.id,p.pid,p.type,p.relation_id,p.product_type,p.is_del,p.cate_id
            FROM mig_swxt_20261003_child m JOIN eb_store_product p ON p.id=m.target_product_id
            WHERE m.source_table=? AND m.source_id=? AND m.target_store_id=?');
        $query->execute([$table, $sourceId, $storeId]);
    }
    $children = $query->fetchAll();
    if (count($children) !== 1) throw new RuntimeException('store mapping missing or ambiguous');
    $child = $children[0];
    if ((int)$child['pid'] !== $root || (int)$child['type'] !== 1 || (int)$child['relation_id'] !== $storeId
        || (int)$child['product_type'] !== $type || (int)$child['is_del'] !== 0) {
        throw new RuntimeException('mapped store product invalid');
    }
    $check = $db->prepare('SELECT COUNT(*) FROM eb_store_product WHERE id=? AND type=0 AND relation_id=0 AND product_type=? AND is_del=0');
    $check->execute([$root, $type]);
    if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('mapped root product invalid');
    return $child;
}

/** Match the salary reader: child category first, then its explicit platform parent. */
function batchCategory(PDO $db, array $product): array
{
    $candidates = [$product];
    if ((int)$product['pid'] > 0) {
        $parentQuery = $db->prepare('SELECT id,pid,type,relation_id,cate_id,is_del FROM eb_store_product WHERE id=?');
        $parentQuery->execute([(int)$product['pid']]);
        $parent = $parentQuery->fetch();
        if ($parent && (int)$parent['type'] === 0 && (int)$parent['relation_id'] === 0
            && (int)$parent['is_del'] === 0) $candidates[] = $parent;
    }
    $lookup = $db->prepare('SELECT id,pid,cate_name FROM eb_store_product_category WHERE id=? AND type=0 AND relation_id=0');
    foreach ($candidates as $candidate) {
        $best = [];
        foreach (explode(',', (string)$candidate['cate_id']) as $raw) {
            $raw = trim($raw);
            if (!preg_match('/^[1-9][0-9]*$/D', $raw)) continue;
            $chain = [];
            $seen = [];
            $id = (int)$raw;
            while ($id > 0 && !isset($seen[$id]) && count($chain) < 12) {
                $seen[$id] = true;
                $lookup->execute([$id]);
                $node = $lookup->fetch();
                if (!$node || trim((string)$node['cate_name']) === '') break;
                $chain[] = $node;
                $id = (int)$node['pid'];
            }
            if ($id !== 0) continue;
            $chain = array_reverse($chain);
            if (count($chain) > count($best)) $best = $chain;
        }
        if ($best !== []) {
            $names = array_column($best, 'cate_name');
            return ['id' => (int)$best[count($best)-1]['id'],
                'path' => implode('/', array_slice($names, 0, 2))];
        }
    }
    throw new RuntimeException('current category missing or invalid');
}

/** Resolve one debt without guessing from its display name or changing its monetary facts. */
function batchResolve(PDO $db, array $item, array $groups): array
{
    if ((int)$item['product_id'] !== 0 || (int)$item['cart_info_id'] !== 0) throw new RuntimeException('already linked');
    if ((int)$item['item_count'] !== 1 || (int)$item['store_id'] !== (int)$item['authority_store_id']
        || (int)$item['uid'] !== (int)$item['authority_member_id']) throw new RuntimeException('debt scope inconsistent');
    $p = batchProvenance((string)$item['remark']);
    $expectedNo = ($p['source_db'] === 'jsj' ? 'JCD' : 'SCD').substr($p['source_key'], 0, 29);
    if (!hash_equals($expectedNo, (string)$item['debt_no'])) throw new RuntimeException('debt number/source mismatch');
    $source = $groups[$p['source_db']][$p['source_key']] ?? null;
    if (!$source) throw new RuntimeException('original source group missing');
    $sourceDay = (new DateTimeImmutable('@'.(string)$item['add_time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    if ((string)$source['ShopId'] !== $p['source_shop'] || (string)$source['CustomerId'] !== $p['source_customer']
        || substr((string)$source['SaleDate'], 0, 10) !== $sourceDay
        || (string)$source['DebtDetail'] !== (string)$item['product_name']
        || (int)$source['identity_count'] !== 1
        || !in_array($source['source_table'], ['bd_ProjectSet', 'bd_goods'], true)
        || trim((string)$source['source_item_id']) === '') throw new RuntimeException('original item identity ambiguous');
    if (batchCents((string)$source['source_amount']) !== batchCents($p['source_amount'])
        || batchCents((string)$source['target_amount']) !== batchCents((string)$item['debt_amount'])) {
        throw new RuntimeException('original and imported amounts differ');
    }
    $product = batchMappedProduct($db, $p['source_db'], $source['source_table'],
        (string)$source['source_item_id'], (int)$item['store_id']);
    $category = batchCategory($db, $product);
    return [
        'debt_item_id' => (int)$item['debt_item_id'], 'debt_id' => (int)$item['debt_id'],
        'store_id' => (int)$item['store_id'], 'old_product_type' => (int)$item['product_type'],
        'target_product_id' => (int)$product['id'], 'target_product_type' => (int)$product['product_type'],
        'category_id' => $category['id'], 'category_path' => $category['path'],
        'source_db' => $p['source_db'], 'source_key' => $p['source_key'],
        'source_table' => $source['source_table'], 'source_id' => (string)$source['source_item_id'],
        'amount_cents' => batchCents((string)$item['debt_amount']),
    ];
}

// Contract tests load validation functions without opening the production database.
if (defined('RH_CONSUME_DEBT_BATCH_FUNCTIONS_ONLY')) return;

$args = array_slice($argv, 1);
$execute = in_array('--execute', $args, true);
if ($execute && (!in_array('--allow-ruihao', $args, true)
    || !preg_match('/^[a-f0-9]{64}$/D', batchOption($args, '--expect')))) {
    throw new RuntimeException('execution requires --allow-ruihao and current --expect fingerprint');
}
$config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('database guard: expected ruihao');
$db = new PDO('mysql:host='.$config['HOSTNAME'].';port='.$config['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone='+08:00'");
$groups = ['jsj' => batchSourceGroups($db, 'jsj'), 'swxt' => batchSourceGroups($db, 'swxt')];
$items = $db->query("SELECT d.id debt_id,d.debt_no,d.uid,d.store_id,d.remark,d.add_time,
    i.id debt_item_id,i.product_id,i.product_type,i.cart_info_id,i.product_name,i.debt_amount,
    a.store_id authority_store_id,a.member_id authority_member_id,
    (SELECT COUNT(*) FROM eb_store_debt_item other WHERE other.debt_id=d.id) item_count
    FROM eb_store_debt d JOIN eb_store_debt_item i ON i.debt_id=d.id
    JOIN eb_cashier_v3_debt_authority a ON a.debt_id=d.id AND a.tenant_id='0'
    WHERE d.debt_no REGEXP '^(JCD|SCD)[a-f0-9]{29}$' AND i.product_id=0
    ORDER BY d.id,i.id")->fetchAll();
$resolved = [];
$skipped = [];
foreach ($items as $item) {
    try {
        $resolved[] = batchResolve($db, $item, $groups);
    } catch (Throwable $error) {
        $skipped[] = ['debt_item_id' => (int)$item['debt_item_id'],
            'source_db' => substr((string)$item['debt_no'], 0, 3) === 'SCD' ? 'swxt' : 'jsj',
            'reason' => $error->getMessage()];
    }
}
usort($resolved, static fn(array $a, array $b): int => $a['debt_item_id'] <=> $b['debt_item_id']);
$fingerprint = hash('sha256', json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$summary = ['mode' => $execute ? 'execute' : 'dry-run', 'candidate_count' => count($items),
    'eligible_count' => count($resolved), 'skipped_count' => count($skipped),
    'eligible_by_source' => array_count_values(array_column($resolved, 'source_db')),
    'skipped_by_source' => array_count_values(array_column($skipped, 'source_db')),
    'eligible_amount_cents' => array_sum(array_column($resolved, 'amount_cents')),
    'skipped' => $skipped, 'fingerprint' => $fingerprint];
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
if (!$execute) exit(0);
if ($resolved === [] || !hash_equals(batchOption($args, '--expect'), $fingerprint)) {
    throw new RuntimeException('preflight fingerprint changed or no eligible rows');
}

// DDL precedes the transaction; audit old values permit exact rollback without restoring a whole live table.
$db->exec("CREATE TABLE IF NOT EXISTS mig_rh_20261010_consume_debt_product_batch (
    debt_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
    debt_id INT UNSIGNED NOT NULL,store_id INT UNSIGNED NOT NULL,
    source_db VARCHAR(8) NOT NULL,source_key CHAR(64) NOT NULL,
    source_table VARCHAR(32) NOT NULL,source_id VARCHAR(128) NOT NULL,
    old_product_id INT UNSIGNED NOT NULL,old_product_type TINYINT NOT NULL,
    target_product_id INT UNSIGNED NOT NULL,target_product_type TINYINT NOT NULL,
    category_id INT NOT NULL,amount_cents BIGINT NOT NULL,batch_hash CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL,reverted_at DATETIME NULL,
    KEY idx_batch_hash (batch_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['rh_consume_debt_product_repair_20261010']);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
try {
    $db->beginTransaction();
    $check = $db->prepare('SELECT product_id,product_type,cart_info_id,debt_amount FROM eb_store_debt_item WHERE id=? AND debt_id=? FOR UPDATE');
    $targetCheck = $db->prepare('SELECT pid,type,relation_id,product_type,is_del,cate_id FROM eb_store_product WHERE id=? FOR UPDATE');
    $audit = $db->prepare('INSERT INTO mig_rh_20261010_consume_debt_product_batch
        (debt_item_id,debt_id,store_id,source_db,source_key,source_table,source_id,
        old_product_id,old_product_type,target_product_id,target_product_type,category_id,amount_cents,batch_hash,applied_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $update = $db->prepare('UPDATE eb_store_debt_item SET product_id=?,product_type=?,update_time=UNIX_TIMESTAMP()
        WHERE id=? AND debt_id=? AND product_id=0 AND cart_info_id=0');
    foreach ($resolved as $row) {
        $check->execute([$row['debt_item_id'], $row['debt_id']]);
        $old = $check->fetch();
        if (!$old || (int)$old['product_id'] !== 0 || (int)$old['product_type'] !== $row['old_product_type']
            || (int)$old['cart_info_id'] !== 0 || batchCents((string)$old['debt_amount']) !== $row['amount_cents']) {
            throw new RuntimeException('debt item changed after preflight: '.$row['debt_item_id']);
        }
        $targetCheck->execute([$row['target_product_id']]);
        $target = $targetCheck->fetch();
        if (!$target || (int)$target['type'] !== 1 || (int)$target['relation_id'] !== $row['store_id']
            || (int)$target['product_type'] !== $row['target_product_type'] || (int)$target['is_del'] !== 0
            || batchCategory($db, $target)['id'] !== $row['category_id']) {
            throw new RuntimeException('target product changed after preflight: '.$row['debt_item_id']);
        }
        $audit->execute([$row['debt_item_id'], $row['debt_id'], $row['store_id'], $row['source_db'],
            $row['source_key'], $row['source_table'], $row['source_id'], 0, $row['old_product_type'],
            $row['target_product_id'], $row['target_product_type'], $row['category_id'], $row['amount_cents'], $fingerprint]);
        $update->execute([$row['target_product_id'], $row['target_product_type'], $row['debt_item_id'], $row['debt_id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('guarded update failed: '.$row['debt_item_id']);
    }
    $db->commit();
    echo json_encode(['committed' => true, 'updated_count' => count($resolved), 'batch_hash' => $fingerprint]).PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('rh_consume_debt_product_repair_20261010')");
}

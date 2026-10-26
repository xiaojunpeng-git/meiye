<?php
declare(strict_types=1);

/**
 * Resolve one imported consumption debt item from its original JSJ/SWXT identity.
 * Dry-run is read-only. Execution only fills a missing product link and writes
 * audit evidence; debt, repayment and performance amounts are never changed.
 */
function optionValue(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (strpos($arg, $name.'=') === 0) return substr($arg, strlen($name) + 1);
    }
    return '';
}

/** Match the exact grouping identity used by the executed consumption-debt import. */
function sourceKey(string $source, array $row): string
{
    return hash('sha256', json_encode([
        $source, (string)$row['ShopId'], (string)$row['CustomerId'],
        (string)$row['CardDetailId'], (string)$row['DebtDetail'], (string)$row['SaleDate'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Decimal comparisons are in cents; no floating-point amount enters a repair guard. */
function cents(string $value): int
{
    if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $match)) {
        throw new RuntimeException('invalid source amount');
    }
    return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
}

/** Legacy remarks are provenance, never SQL or an authority by themselves. */
function sourceRemark(string $remark): array
{
    $fields = [];
    foreach (explode(';', $remark) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) === 2) $fields[$pair[0]] = $pair[1];
    }
    foreach (['source_db', 'source_kind', 'source_key', 'source_customer', 'source_shop', 'source_amount'] as $key) {
        if (($fields[$key] ?? '') === '') throw new RuntimeException('import provenance is incomplete: '.$key);
    }
    if (!in_array($fields['source_db'], ['jsj', 'swxt'], true) || $fields['source_kind'] !== 'CONSUME'
        || !preg_match('/^[a-f0-9]{64}$/D', $fields['source_key'])) {
        throw new RuntimeException('debt is not an approved imported consumption debt');
    }
    return $fields;
}

/** Rebuild only the original member/shop/day/name group; a merged group with two source products is rejected. */
function sourceGroup(PDO $db, array $provenance, string $day, string $name): array
{
    $source = $provenance['source_db']; // Whitelisted by sourceRemark.
    $scope = $source === 'swxt'
        ? "JOIN swxt.bd_Customers owner ON owner.Id=h.CustomerId AND owner.Shop IN ('720092000110','720092000112','720092000114','720092000115')"
        : '';
    $parts = [];
    foreach ([
        ['SS_ConsumeProjectDetail', 'bd_ProjectSet', 'ProjectId', 'ProjectName', 'bd_ProjectSet'],
        ['SS_ConsumeGoodsDetail', 'bd_goods', 'GoodsId', 'GoodsName', 'bd_goods'],
    ] as [$detail, $catalog, $id, $label, $table]) {
        $parts[] = "SELECT h.ShopId,h.CustomerId,d.CardDetailId,h.SaleDate,p.$label DebtDetail,
            CONVERT(d.$id USING utf8mb4) source_item_id,'$table' source_table,
            COALESCE(d.QianKuan,0)-COALESCE(d.HandBalance,0) NonePay
            FROM $source.SS_Consume h JOIN $source.$detail d ON d.MDID=h.MDID
            LEFT JOIN $source.$catalog p ON p.Id=d.$id $scope
            WHERE h.ShopId=? AND h.CustomerId=? AND DATE(h.SaleDate)=? AND p.$label=?
              AND h.fstate='1' AND COALESCE(h.Pstate,'0')<>'1'
              AND COALESCE(d.QianKuan,0)-COALESCE(d.HandBalance,0)<>0";
    }
    $sql = 'SELECT ShopId,CustomerId,CardDetailId,SaleDate,DebtDetail,
        ROUND(SUM(NonePay),2) source_amount,ROUND(SUM(NonePay),0) target_amount,
        COUNT(DISTINCT CONCAT(source_table,":",source_item_id)) identity_count,
        MIN(source_table) source_table,MIN(source_item_id) source_item_id
        FROM ('.implode(' UNION ALL ', $parts).') detail
        GROUP BY ShopId,CustomerId,CardDetailId,DebtDetail,SaleDate
        HAVING ROUND(SUM(NonePay),2)<>0';
    $statement = $db->prepare($sql);
    $params = [];
    foreach ($parts as $_) array_push($params, $provenance['source_shop'], $provenance['source_customer'], $day, $name);
    $statement->execute($params);
    $matched = [];
    foreach ($statement->fetchAll() as $row) {
        if (sourceKey($source, $row) === $provenance['source_key']) $matched[] = $row;
    }
    if (count($matched) !== 1 || (int)$matched[0]['identity_count'] !== 1
        || !in_array($matched[0]['source_table'], ['bd_ProjectSet', 'bd_goods'], true)
        || trim((string)$matched[0]['source_item_id']) === '') {
        throw new RuntimeException('source group is absent or has ambiguous product identity');
    }
    return $matched[0];
}

/** The migration's source-ID map and store child, not product names, own the target identity. */
function mappedProduct(PDO $db, string $source, string $table, string $sourceId, int $storeId): array
{
    $productType = $table === 'bd_goods' ? 0 : 6;
    if ($source === 'jsj') {
        $rootQuery = $db->prepare("SELECT target_id FROM mig_rh_source_map
            WHERE source_table=? AND source_pk=? AND target_type='store_product'");
        $rootQuery->execute([$table, $sourceId]);
        $roots = $rootQuery->fetchAll(PDO::FETCH_COLUMN);
        if (count($roots) !== 1) throw new RuntimeException('JSJ root product mapping is not unique');
        $rootId = (int)$roots[0];
        $prefix = $table === 'bd_goods' ? 'RH_GOODS:' : 'RH_PROJECT:';
        $childQuery = $db->prepare('SELECT id,pid,type,relation_id,product_type,is_del,cate_id FROM eb_store_product WHERE keyword=?');
        $childQuery->execute([$prefix.$sourceId.':STORE:'.$storeId]);
        $children = $childQuery->fetchAll();
    } else {
        $rootQuery = $db->prepare('SELECT target_product_id FROM mig_swxt_20261003_product WHERE source_table=? AND source_id=?');
        $rootQuery->execute([$table, $sourceId]);
        $roots = $rootQuery->fetchAll(PDO::FETCH_COLUMN);
        if (count($roots) !== 1) throw new RuntimeException('SWXT root product mapping is not unique');
        $rootId = (int)$roots[0];
        $childQuery = $db->prepare('SELECT p.id,p.pid,p.type,p.relation_id,p.product_type,p.is_del,p.cate_id
            FROM mig_swxt_20261003_child m JOIN eb_store_product p ON p.id=m.target_product_id
            WHERE m.source_table=? AND m.source_id=? AND m.target_store_id=?');
        $childQuery->execute([$table, $sourceId, $storeId]);
        $children = $childQuery->fetchAll();
    }
    if (count($children) !== 1) throw new RuntimeException('store product mapping is not unique');
    $child = $children[0];
    if ((int)$child['pid'] !== $rootId || (int)$child['type'] !== 1
        || (int)$child['relation_id'] !== $storeId || (int)$child['product_type'] !== $productType
        || (int)$child['is_del'] !== 0) throw new RuntimeException('mapped store product is invalid');
    $rootCheck = $db->prepare('SELECT COUNT(*) FROM eb_store_product WHERE id=? AND type=0 AND relation_id=0 AND product_type=? AND is_del=0');
    $rootCheck->execute([$rootId, $productType]);
    if ((int)$rootCheck->fetchColumn() !== 1) throw new RuntimeException('mapped root product is invalid');
    return $child;
}

/** The present category must be resolvable before claiming the report will show a category. */
function currentCategory(PDO $db, array $product): array
{
    $candidates = array_filter(array_map('trim', explode(',', (string)$product['cate_id'])));
    $matches = [];
    $lookup = $db->prepare('SELECT id,pid,cate_name FROM eb_store_product_category WHERE id=? AND type=0 AND relation_id=0');
    foreach ($candidates as $raw) {
        if (!ctype_digit($raw) || (int)$raw <= 0) continue;
        $lookup->execute([(int)$raw]);
        $leaf = $lookup->fetch();
        if (!$leaf || trim((string)$leaf['cate_name']) === '') continue;
        $path = (string)$leaf['cate_name'];
        if ((int)$leaf['pid'] > 0) {
            $lookup->execute([(int)$leaf['pid']]);
            $root = $lookup->fetch();
            if (!$root || (int)$root['pid'] !== 0 || trim((string)$root['cate_name']) === '') continue;
            $path = $root['cate_name'].' / '.$path;
        }
        $matches[(int)$leaf['id']] = ['id' => (int)$leaf['id'], 'path' => $path];
    }
    if (count($matches) !== 1) throw new RuntimeException('current product category is absent or ambiguous');
    return array_values($matches)[0];
}

// Contract tests load the pure validation functions without opening any database connection.
if (defined('RH_CONSUME_DEBT_REPAIR_FUNCTIONS_ONLY')) return;

$args = array_slice($argv, 1);
$repaymentNo = optionValue($args, '--repayment-no');
$execute = in_array('--execute', $args, true);
if (!preg_match('/^BJ[0-9]{11}$/D', $repaymentNo)) throw new RuntimeException('one exact repayment number is required');
if ($execute && (!in_array('--allow-ruihao', $args, true) || !preg_match('/^[a-f0-9]{64}$/D', optionValue($args, '--expect')))) {
    throw new RuntimeException('execution requires --allow-ruihao and the dry-run --expect fingerprint');
}
$config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('database guard: expected ruihao');
$db = new PDO('mysql:host='.$config['HOSTNAME'].';port='.$config['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone='+08:00'");
$query = $db->prepare("SELECT r.repayment_id,r.debt_id,r.store_id,r.member_id,r.repayment_amount_cents,
    d.debt_no,d.uid,d.store_id debt_store_id,d.remark,d.add_time,
    i.id debt_item_id,i.product_id,i.product_type,i.cart_info_id,i.product_name,i.debt_amount
    FROM eb_cashier_v3_debt_repayment r JOIN eb_store_debt d ON d.id=r.debt_id
    JOIN eb_store_debt_item i ON i.debt_id=d.id
    WHERE r.repayment_no=? AND r.tenant_id='0' AND r.status='succeeded'");
$query->execute([$repaymentNo]);
$items = $query->fetchAll();
if (count($items) !== 1) throw new RuntimeException('repayment must resolve to one imported debt item');
$item = $items[0];
if ((int)$item['store_id'] !== (int)$item['debt_store_id'] || (int)$item['member_id'] !== (int)$item['uid']
    || (int)$item['product_id'] !== 0 || (int)$item['cart_info_id'] !== 0
    || !preg_match('/^(JCD|SCD)[a-f0-9]{29}$/D', $item['debt_no'])) {
    throw new RuntimeException('debt item does not match the missing-link repair scope');
}
$provenance = sourceRemark((string)$item['remark']);
if (!hash_equals($item['debt_no'], ($provenance['source_db'] === 'jsj' ? 'JCD' : 'SCD').substr($provenance['source_key'], 0, 29))) {
    throw new RuntimeException('debt number and source key disagree');
}
$sourceDay = (new DateTimeImmutable('@'.(string)$item['add_time']))
    ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
$source = sourceGroup($db, $provenance, $sourceDay, (string)$item['product_name']);
if (cents((string)$source['source_amount']) !== cents($provenance['source_amount'])
    || cents((string)$source['target_amount']) !== cents((string)$item['debt_amount'])) {
    throw new RuntimeException('source and imported debt amounts disagree');
}
$product = mappedProduct($db, $provenance['source_db'], $source['source_table'], (string)$source['source_item_id'], (int)$item['store_id']);
$category = currentCategory($db, $product);
$evidence = [
    'repayment_no' => $repaymentNo, 'repayment_id' => $item['repayment_id'],
    'debt_id' => (int)$item['debt_id'], 'debt_item_id' => (int)$item['debt_item_id'],
    'source_db' => $provenance['source_db'], 'source_key' => $provenance['source_key'],
    'source_table' => $source['source_table'], 'source_id' => (string)$source['source_item_id'],
    'target_product_id' => (int)$product['id'], 'category_id' => $category['id'],
    'category_path' => $category['path'], 'repayment_cents' => (int)$item['repayment_amount_cents'],
];
$fingerprint = hash('sha256', json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode(['mode' => $execute ? 'execute' : 'dry-run', 'evidence' => $evidence, 'fingerprint' => $fingerprint], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
if (!$execute) exit(0);
if (!hash_equals(optionValue($args, '--expect'), $fingerprint)) throw new RuntimeException('preflight fingerprint changed');

// DDL is separate from the row transaction; an empty audit table is harmless if the guarded update fails.
$db->exec("CREATE TABLE IF NOT EXISTS mig_rh_20261010_consume_debt_product_repair (
    debt_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
    debt_id INT UNSIGNED NOT NULL, repayment_id VARCHAR(64) NOT NULL,
    source_db VARCHAR(8) NOT NULL, source_key CHAR(64) NOT NULL,
    source_table VARCHAR(32) NOT NULL, source_id VARCHAR(128) NOT NULL,
    old_product_id INT UNSIGNED NOT NULL, old_product_type TINYINT NOT NULL,
    target_product_id INT UNSIGNED NOT NULL,
    category_id INT NOT NULL, evidence_hash CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL, reverted_at DATETIME NULL,
    KEY idx_repayment_id (repayment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['rh_consume_debt_product_repair_20261010']);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
try {
    $db->beginTransaction();
    $check = $db->prepare('SELECT product_id,product_type,cart_info_id,debt_amount FROM eb_store_debt_item WHERE id=? AND debt_id=? FOR UPDATE');
    $check->execute([$item['debt_item_id'], $item['debt_id']]);
    $current = $check->fetch();
    if (!$current || (int)$current['product_id'] !== 0 || (int)$current['cart_info_id'] !== 0
        || cents((string)$current['debt_amount']) !== cents((string)$item['debt_amount'])) {
        throw new RuntimeException('debt item changed after preflight');
    }
    // Hold the mapped store product stable until the missing link and audit commit together.
    $targetCheck = $db->prepare('SELECT pid,type,relation_id,product_type,is_del FROM eb_store_product WHERE id=? FOR UPDATE');
    $targetCheck->execute([(int)$product['id']]);
    $target = $targetCheck->fetch();
    if (!$target || (int)$target['pid'] !== (int)$product['pid'] || (int)$target['type'] !== 1
        || (int)$target['relation_id'] !== (int)$item['store_id']
        || (int)$target['product_type'] !== (int)$product['product_type'] || (int)$target['is_del'] !== 0) {
        throw new RuntimeException('mapped store product changed after preflight');
    }
    $audit = $db->prepare('INSERT INTO mig_rh_20261010_consume_debt_product_repair
        (debt_item_id,debt_id,repayment_id,source_db,source_key,source_table,source_id,
        old_product_id,old_product_type,target_product_id,category_id,evidence_hash,applied_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $audit->execute([$item['debt_item_id'], $item['debt_id'], $item['repayment_id'],
        $provenance['source_db'], $provenance['source_key'], $source['source_table'],
        $source['source_item_id'], 0, (int)$item['product_type'], $product['id'], $category['id'], $fingerprint]);
    $update = $db->prepare('UPDATE eb_store_debt_item SET product_id=?,product_type=?,update_time=UNIX_TIMESTAMP()
        WHERE id=? AND debt_id=? AND product_id=0 AND cart_info_id=0');
    $update->execute([(int)$product['id'], (int)$product['product_type'], $item['debt_item_id'], $item['debt_id']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('guarded debt item update did not affect one row');
    $db->commit();
    echo json_encode(['committed' => true, 'debt_item_id' => (int)$item['debt_item_id'],
        'product_id' => (int)$product['id'], 'evidence_hash' => $fingerprint]).PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('rh_consume_debt_product_repair_20261010')");
}

<?php
declare(strict_types=1);

/**
 * 瑞昊 JSJ 历史商品权益的库存身份纠正。只修正尚未领用、原商品没有库存身份、
 * 同一门店/商品主档仅有一条可用库存身份的权益；不创建库存、不改数量或历史流水。
 * 默认只读。生产执行还需要固定清单指纹、独立生产备份和验收后的显式开关。
 */
const R21_BULK_BATCH = 'JSJ-20260917-R1';
const R21_BULK_AUDIT_TABLE = 'mig_r21_bulk_claimable_mapping_audit';

$execute = in_array('--execute', $argv, true);
$list = in_array('--list', $argv, true);
$allowMismatch = in_array('--confirm-unit-label-1to1', $argv, true);
$expected = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--expected-sha256=') === 0) $expected = substr($arg, 18);
}
if (in_array('--selftest', $argv, true)) {
    if ($execute) throw new RuntimeException('Selftest cannot execute a database write');
    // 纯函数夹具验证边界：单位文字不同不代表倍率不同；任何身份、数量或批次异常都必须拒绝。
    $fixture = ['source_kind' => 'PRESALE', 'claimed_quantity' => 0, 'claim_status' => 'OPEN', 'prior_claim_id' => null,
        'old_stock_id' => null, 'keyword' => 'RH_GOODS:0091001538:STORE:1', 'store_id' => 1,
        'pid' => 78887, 'old_relation_id' => 1, 'new_relation_id' => 1, 'old_deleted' => 0,
        'new_deleted' => 0, 'old_name' => '活性玻尿酸胶原液', 'new_name' => '活性玻尿酸胶原液',
        'source_name' => '活性玻尿酸胶原液', 'old_sku_product_id' => 318194, 'old_product_id' => 318194,
        'old_sku_unique' => '00006TIQ', 'old_unique' => '00006TIQ', 'new_sku_product_id' => 121848,
        'new_product_id' => 121848, 'new_sku_unique' => '047b502d', 'new_unique' => '047b502d',
        'source_unit' => '盒', 'source_standard_unit' => '盒', 'source_standard_quantity' => 1,
        'source_rate1' => null, 'source_rate2' => null, 'unit_convert' => 1,
        'quantity_scale' => 0, 'available_quantity_units' => 10, 'active_batch_units' => 10];
    $cases = [[$fixture, ''], [array_replace($fixture, ['new_deleted' => 1]), 'product_master_or_name_mismatch'],
        [array_replace($fixture, ['source_standard_quantity' => 2]), 'unit_conversion_unverified'],
        [array_replace($fixture, ['old_stock_id' => 9]), 'old_product_has_stock_identity'],
        [array_replace($fixture, ['active_batch_units' => 9]), 'stock_batch_balance_mismatch'],
        [array_replace($fixture, ['claimed_quantity' => 1]), 'already_claimed_or_closed']];
    foreach ($cases as [$row, $expectedReason]) {
        if (r21BulkReason($row) !== $expectedReason) throw new RuntimeException('Bulk mapping selftest failed');
    }
    echo "R21_BULK_SELFTEST_OK ", count($cases), PHP_EOL;
    exit(0);
}
if ($execute && (!in_array('--allow-ruihao-production', $argv, true)
        || !in_array('--production-backup-verified', $argv, true)
        || !$allowMismatch || !preg_match('/^[a-f0-9]{64}$/D', $expected))) {
    throw new RuntimeException('Production write requires target, backup, 1:1 unit decision and exact dry-run SHA-256');
}

$envFile = getenv('R21_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env';
if ($execute && $envFile !== '/www/wwwroot/rh.cc3798.com/.env') {
    throw new RuntimeException('Bulk execute is restricted to the named RH production instance');
}
$env = parse_ini_file($envFile, true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
if (($db['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('Expected isolated ruihao database');
$pdo = new PDO('mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'], $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec("SET time_zone = '+08:00'");

/**
 * 返回候选行而非直接更新：一个权益可能有多个库存候选，调用方必须逐项证明唯一性。
 * 只从当前默认门店仓寻找库存；无库存商品不会被虚构为可领用。
 */
function r21BulkCandidates(PDO $pdo): array
{
    $sql = "SELECT l.id line_pk,l.claimable_line_id,l.tenant_id,l.store_id,l.member_id,l.source_kind,l.product_id old_product_id,
        l.sku_id old_sku_id,l.sku_unique_snapshot old_unique,l.product_name_snapshot,l.quantity,l.claimed_quantity,l.claim_status,l.version,
        p.pid,p.relation_id old_relation_id,p.product_type,p.store_name old_name,p.keyword,p.is_del old_deleted,
        ov.product_id old_sku_product_id,ov.`unique` old_sku_unique,
        bg.GoodsName source_name,bg.FUnit source_unit,bg.FStandardUnit source_standard_unit,
        bg.FStandardQuantity source_standard_quantity,bg.Rate1 source_rate1,bg.Rate2 source_rate2,
        p2.id new_product_id,p2.relation_id new_relation_id,p2.store_name new_name,p2.is_del new_deleted,
        s.id stock_id,s.location_id,s.sku_id new_sku_id,s.product_unique new_unique,s.available_quantity_units,
        s.quantity_scale,v2.product_id new_sku_product_id,v2.`unique` new_sku_unique,v2.sale_unit,v2.stock_unit,
        v2.unit_convert,oldstock.id old_stock_id,c.id prior_claim_id
      FROM eb_cashier_v3_presale_claimable_line l
      JOIN eb_store_product p ON p.id=l.product_id
      JOIN eb_store_product_attr_value ov ON ov.id=l.sku_id
      JOIN jsj.bd_goods bg ON bg.Id=SUBSTRING_INDEX(SUBSTRING_INDEX(p.keyword,':',2),':',-1)
      JOIN eb_store_product p2 ON p2.pid=p.pid AND p2.relation_id=l.store_id AND p2.product_type=p.product_type AND p2.id<>p.id
      JOIN eb_inventory_location loc ON loc.tenant_id=l.tenant_id AND loc.store_id=l.store_id
        AND loc.location_type='STORE' AND loc.is_default=1 AND loc.location_status='ACTIVE'
      JOIN eb_inventory_stock s ON s.tenant_id=l.tenant_id AND s.location_id=loc.id AND s.store_id=l.store_id
        AND s.consumable_product_id=p2.id AND s.stock_status='GOOD' AND s.available_quantity_units>0
      JOIN eb_store_product_attr_value v2 ON v2.id=s.sku_id
      LEFT JOIN eb_inventory_stock oldstock ON oldstock.tenant_id=l.tenant_id AND oldstock.store_id=l.store_id
        AND oldstock.consumable_product_id=l.product_id
      LEFT JOIN eb_cashier_v3_presale_claim c ON c.tenant_id=l.tenant_id AND c.claimable_line_id=l.claimable_line_id
      WHERE l.tenant_id='0' AND l.source_checkout_request_id=? AND l.quantity>l.claimed_quantity
      ORDER BY l.id,s.id";
    $query = $pdo->prepare($sql);
    $query->execute([R21_BULK_BATCH]);
    return $query->fetchAll();
}

/** Formal batch facts must reconcile with the stock balance before a right is rebound. */
function r21BulkBatchBalances(PDO $pdo, array $rows): array
{
    $stockIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['stock_id'], $rows)));
    $balances = [];
    foreach (array_chunk($stockIds, 400) as $chunk) {
        $query = $pdo->prepare('SELECT stock_id,SUM(available_quantity_units) units FROM eb_inventory_batch
            WHERE batch_status=\'ACTIVE\' AND stock_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY stock_id');
        $query->execute($chunk);
        foreach ($query->fetchAll() as $balance) $balances[(int)$balance['stock_id']] = (int)$balance['units'];
    }
    return $balances;
}

/** Each source and target count is already expressed in standard units by its own 1:1 metadata. */
function r21BulkReason(array $row): string
{
    if (!in_array((string)$row['source_kind'], ['PRESALE', 'GIFT'], true)
        || (int)$row['claimed_quantity'] !== 0 || !in_array((string)$row['claim_status'], ['OPEN', 'AVAILABLE'], true)
        || $row['prior_claim_id'] !== null) return 'already_claimed_or_closed';
    if ($row['old_stock_id'] !== null) return 'old_product_has_stock_identity';
    if (!preg_match('/^RH_GOODS:([0-9]+):STORE:([0-9]+)$/D', (string)$row['keyword'], $match)
        || (int)$match[2] !== (int)$row['store_id']) return 'source_identity_invalid';
    // 历史已售权益可在商品两侧都归档后领用；归档状态不一致才提示人工复核。
    if ((int)$row['pid'] <= 0 || (int)$row['old_relation_id'] !== (int)$row['store_id']
        || (int)$row['new_relation_id'] !== (int)$row['store_id']
        || (int)$row['old_deleted'] !== (int)$row['new_deleted']
        || (string)$row['old_name'] !== (string)$row['new_name']
        || (string)$row['source_name'] !== (string)$row['new_name']) return 'product_master_or_name_mismatch';
    if ((int)$row['old_sku_product_id'] !== (int)$row['old_product_id']
        || (string)$row['old_sku_unique'] !== (string)$row['old_unique']
        || (int)$row['new_sku_product_id'] !== (int)$row['new_product_id']
        || (string)$row['new_sku_unique'] !== (string)$row['new_unique']) return 'sku_identity_mismatch';
    if (trim((string)$row['source_unit']) === ''
        || (string)$row['source_unit'] !== (string)$row['source_standard_unit']
        || (float)$row['source_standard_quantity'] !== 1.0
        || (float)$row['source_rate1'] !== 0.0 || (float)$row['source_rate2'] !== 0.0
        || (float)$row['unit_convert'] !== 1.0 || (int)$row['quantity_scale'] !== 0) return 'unit_conversion_unverified';
    if ((int)$row['available_quantity_units'] < 1
        || (int)$row['active_batch_units'] !== (int)$row['available_quantity_units']) return 'stock_batch_balance_mismatch';
    return '';
}

/** A frozen digest binds approval to every entitlement's old/new identity and 1:1 evidence. */
function r21BulkDigest(array $rows): string
{
    $parts = [];
    foreach ($rows as $row) {
        $fields = ['line_pk','claimable_line_id','store_id','member_id','source_kind','old_product_id','old_sku_id',
            'old_unique','quantity','version','new_product_id','new_sku_id','new_unique','stock_id',
            'source_unit','source_standard_quantity','sale_unit','stock_unit','unit_convert'];
        $parts[] = json_encode(array_map(static fn(string $field): string => (string)$row[$field], $fields), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    return hash('sha256', implode("\n", $parts));
}

$raw = r21BulkCandidates($pdo);
$batchBalances = r21BulkBatchBalances($pdo, $raw);
foreach ($raw as &$row) $row['active_batch_units'] = $batchBalances[(int)$row['stock_id']] ?? 0;
unset($row);
$byLine = [];
foreach ($raw as $row) $byLine[(int)$row['line_pk']][] = $row;
$valid = [];
$rejected = [];
$groups = [];
$unitMismatch = 0;
$byKind = [];
foreach ($byLine as $linePk => $candidates) {
    if (count($candidates) !== 1) {
        $rejected[] = ['line_pk' => $linePk, 'reason' => 'stock_candidate_not_unique', 'count' => count($candidates)];
        continue;
    }
    $row = $candidates[0];
    $reason = r21BulkReason($row);
    if ($reason !== '') {
        $rejected[] = ['line_pk' => $linePk, 'reason' => $reason];
        continue;
    }
    $valid[] = $row;
    $byKind[(string)$row['source_kind']] = ($byKind[(string)$row['source_kind']] ?? 0) + 1;
    $key = $row['store_id'] . ':' . $row['old_product_id'];
    if (!isset($groups[$key])) {
        $groups[$key] = ['store_id' => (int)$row['store_id'], 'source_product_id' => (int)$row['old_product_id'],
            'target_product_id' => (int)$row['new_product_id'], 'source_name' => (string)$row['source_name'],
            'source_unit' => (string)$row['source_unit'], 'target_sale_unit' => (string)$row['sale_unit'],
            'target_stock_unit' => (string)$row['stock_unit'], 'conversion' => '1:1', 'rights' => 0,
            'unclaimed_total' => 0, 'stock_available' => (int)$row['available_quantity_units']];
    }
    $groups[$key]['rights']++;
    $groups[$key]['unclaimed_total'] += (int)$row['quantity'] - (int)$row['claimed_quantity'];
    if ((string)$row['source_unit'] !== (string)$row['sale_unit']) $unitMismatch++;
}
$digest = r21BulkDigest($valid);
$summary = ['mode' => $execute ? 'execute' : 'dry-run', 'batch' => R21_BULK_BATCH,
    'candidate_lines' => count($byLine), 'validated_lines' => count($valid), 'source_kind_lines' => $byKind,
    'product_store_groups' => count($groups),
    'stock_short_groups' => count(array_filter($groups, static fn(array $group): bool => $group['stock_available'] < $group['unclaimed_total'])),
    'unit_label_mismatch_lines' => $unitMismatch, 'rejected_count' => count($rejected),
    'rejected_sample' => array_slice($rejected, 0, 20), 'sha256' => $digest];
if ($list) $summary['product_store_audit'] = array_values($groups);
if (!$execute) {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
if ($rejected || count($valid) === 0 || $digest !== $expected) {
    throw new RuntimeException('Frozen candidate scope changed or some product conversions are unverified; no rows changed');
}

// Write only the entitlement-to-stock identity, with a per-line immutable audit.
// Existing sales, quantities, members, claim history and inventory facts are untouched.
$pdo->exec("CREATE TABLE IF NOT EXISTS " . R21_BULK_AUDIT_TABLE . " (
    line_pk bigint unsigned NOT NULL PRIMARY KEY, claimable_line_id varchar(64) NOT NULL,
    source_batch varchar(64) NOT NULL, store_id bigint unsigned NOT NULL,
    old_product_id bigint unsigned NOT NULL, old_sku_id bigint unsigned NOT NULL, old_unique varchar(64) NOT NULL,
    new_product_id bigint unsigned NOT NULL, new_sku_id bigint unsigned NOT NULL, new_unique varchar(64) NOT NULL,
    source_unit varchar(32) NOT NULL, target_sale_unit varchar(32) NOT NULL, unit_ratio varchar(16) NOT NULL,
    approved_digest char(64) CHARACTER SET ascii NOT NULL, old_version bigint unsigned NOT NULL, repaired_at bigint unsigned NOT NULL,
    UNIQUE KEY uniq_claimable_line (claimable_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$insert = $pdo->prepare('INSERT INTO ' . R21_BULK_AUDIT_TABLE . ' (line_pk,claimable_line_id,source_batch,store_id,
    old_product_id,old_sku_id,old_unique,new_product_id,new_sku_id,new_unique,source_unit,target_sale_unit,
    unit_ratio,approved_digest,old_version,repaired_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$update = $pdo->prepare('UPDATE eb_cashier_v3_presale_claimable_line SET product_id=?,sku_id=?,sku_unique_snapshot=?,
    version=version+1,updated_at=? WHERE id=? AND tenant_id=? AND store_id=? AND source_checkout_request_id=?
    AND member_id=? AND source_kind=? AND quantity=? AND product_id=? AND sku_id=? AND sku_unique_snapshot=?
    AND version=? AND claimed_quantity=0
    AND claim_status IN (\'OPEN\',\'AVAILABLE\')');
$pdo->beginTransaction();
try {
    $now = time();
    foreach ($valid as $row) {
        $insert->execute([(int)$row['line_pk'], (string)$row['claimable_line_id'], R21_BULK_BATCH, (int)$row['store_id'],
            (int)$row['old_product_id'], (int)$row['old_sku_id'], (string)$row['old_unique'],
            (int)$row['new_product_id'], (int)$row['new_sku_id'], (string)$row['new_unique'],
            (string)$row['source_unit'], (string)$row['sale_unit'], '1:1', $digest, (int)$row['version'], $now]);
        $update->execute([(int)$row['new_product_id'], (int)$row['new_sku_id'], (string)$row['new_unique'], $now,
            (int)$row['line_pk'], (string)$row['tenant_id'], (int)$row['store_id'], R21_BULK_BATCH,
            (int)$row['member_id'], (string)$row['source_kind'], (int)$row['quantity'],
            (int)$row['old_product_id'], (int)$row['old_sku_id'], (string)$row['old_unique'], (int)$row['version']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Entitlement changed concurrently; rolled back all mappings');
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
$summary['repaired_lines'] = count($valid);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

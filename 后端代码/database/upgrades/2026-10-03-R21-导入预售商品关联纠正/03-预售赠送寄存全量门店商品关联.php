<?php
declare(strict_types=1);

/**
 * Bind untouched JSJ/SWXT goods rights to the unique existing product/SKU of
 * their own store before inventory arrives. This covers presale, product gifts
 * and stored goods; it never creates stock, changes entitlement quantities or
 * rewrites a line that has already been claimed.
 */
const R21_ALL_AUDIT = 'mig_r21_all_goods_identity_rebind_audit';
const R21_ALL_BATCHES = ['JSJ-20260917-R1', 'SWXT-20261003-R1'];

$execute = in_array('--execute', $argv, true);
$expected = '';
$probeLine = 0;
foreach ($argv as $argument) {
    if (strpos($argument, '--expected-sha256=') === 0) $expected = substr($argument, 18);
    if (strpos($argument, '--probe-line=') === 0) $probeLine = (int)substr($argument, 13);
}
if (in_array('--selftest', $argv, true)) {
    if ($execute) throw new RuntimeException('Selftest cannot write');
    $base = ['source_kind'=>'PRESALE','source_batch'=>'JSJ-20260917-R1','source_order_no'=>'JSJ-STORED-1',
        'claimed_quantity'=>0,'claim_status'=>'OPEN','prior_claim_id'=>null,'old_stock_id'=>null,
        'keyword'=>'RH_GOODS:0091001538:STORE:1','store_id'=>1,'pid'=>78887,
        'old_product_id'=>318194,'old_sku_id'=>302058,'old_relation_id'=>1,'old_name'=>'商品',
        'old_deleted'=>0,'old_type'=>1,'old_product_type'=>0,'old_unit_name'=>'元',
        'old_sku_product_id'=>318194,'old_unique'=>'00006TIQ','old_sku_unique'=>'00006TIQ',
        'old_suk'=>'默认','old_unit_convert'=>1,'old_decimal_scale'=>0,'product_name_snapshot'=>'商品',
        'source_name'=>'商品','source_unit'=>'盒','source_standard_unit'=>'盒','source_standard_quantity'=>1,
        'source_rate1'=>null,'source_rate2'=>null,'new_product_id'=>121848,'new_sku_id'=>120127,
        'new_relation_id'=>1,'new_name'=>'商品','new_deleted'=>0,'new_type'=>1,'new_product_type'=>0,
        'new_unit_name'=>'元','new_sku_product_id'=>121848,'new_unique'=>'047b502d',
        'new_sku_unique'=>'047b502d','new_suk'=>'默认','new_unit_convert'=>1,'new_decimal_scale'=>0];
    $cases = [[$base,''],
        [array_replace($base,['source_kind'=>'GIFT','source_order_no'=>'JSJ-GIFT-1']),''],
        [array_replace($base,['source_kind'=>'PRESALE','source_order_no'=>'JSJ-PRESALE-1']),''],
        [array_replace($base,['source_standard_quantity'=>10]),'unit_conversion_unverified'],
        [array_replace($base,['source_standard_unit'=>'次']),'unit_conversion_unverified'],
        [array_replace($base,['new_relation_id'=>2]),'product_identity_mismatch'],
        [array_replace($base,['old_stock_id'=>7]),'source_stock_exists'],
        [array_replace($base,['prior_claim_id'=>8]),'already_claimed_or_closed'],
        [array_replace($base,['new_unit_convert'=>2]),'unit_conversion_unverified']];
    foreach ($cases as [$row,$reason]) {
        if (r21AllReason($row) !== $reason) throw new RuntimeException('R21 all-goods selftest failed');
    }
    echo 'R21_ALL_GOODS_SELFTEST_OK ',count($cases),PHP_EOL;
    exit(0);
}
if ($execute && (!in_array('--allow-ruihao-production', $argv, true)
    || !in_array('--production-backup-verified', $argv, true)
    || !in_array('--confirm-all-goods-1to1-including-stored', $argv, true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected))) {
    throw new RuntimeException('Production write requires backup, approved 1:1 scope and frozen digest');
}
$envFile = getenv('R21_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env';
if ($execute && $envFile !== '/www/wwwroot/rh.cc3798.com/.env') {
    throw new RuntimeException('Execution is restricted to the named RH production instance');
}
$env = parse_ini_file($envFile, true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
if (($db['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('Expected isolated ruihao database');
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec("SET time_zone = '+08:00'");

/** A missing target is held, while all extant same-master targets are audited for uniqueness. */
function r21AllCandidates(PDO $pdo): array
{
    $sql = "SELECT l.id line_pk,l.claimable_line_id,l.tenant_id,l.store_id,l.member_id,l.source_kind,
        l.source_checkout_request_id source_batch,l.source_order_no_snapshot source_order_no,
        l.product_id old_product_id,l.sku_id old_sku_id,l.sku_unique_snapshot old_unique,
        l.product_name_snapshot,l.quantity,l.claimed_quantity,l.claim_status,l.version,
        p.pid,p.relation_id old_relation_id,p.store_name old_name,p.is_del old_deleted,
        p.type old_type,p.product_type old_product_type,p.unit_name old_unit_name,p.keyword,
        ov.product_id old_sku_product_id,ov.`unique` old_sku_unique,ov.suk old_suk,
        ov.unit_convert old_unit_convert,ov.decimal_scale old_decimal_scale,
        COALESCE(j.GoodsName,w.GoodsName) source_name,COALESCE(j.FUnit,w.FUnit) source_unit,
        COALESCE(j.FStandardUnit,w.FStandardUnit) source_standard_unit,
        COALESCE(j.FStandardQuantity,w.FStandardQuantity) source_standard_quantity,
        COALESCE(j.Rate1,w.Rate1) source_rate1,COALESCE(j.Rate2,w.Rate2) source_rate2,
        p2.id new_product_id,p2.relation_id new_relation_id,p2.store_name new_name,
        p2.is_del new_deleted,p2.type new_type,p2.product_type new_product_type,p2.unit_name new_unit_name,
        v2.id new_sku_id,v2.product_id new_sku_product_id,v2.`unique` new_sku_unique,
        v2.suk new_suk,v2.unit_convert new_unit_convert,v2.decimal_scale new_decimal_scale,
        v2.sale_unit target_sale_unit,oldstock.id old_stock_id,c.id prior_claim_id
      FROM eb_cashier_v3_presale_claimable_line l
      JOIN eb_store_product p ON p.id=l.product_id
      JOIN eb_store_product_attr_value ov ON ov.id=l.sku_id
      LEFT JOIN jsj.bd_goods j ON l.source_checkout_request_id='JSJ-20260917-R1'
        AND j.Id=SUBSTRING_INDEX(SUBSTRING_INDEX(p.keyword,':',2),':',-1)
      LEFT JOIN swxt.bd_goods w ON l.source_checkout_request_id='SWXT-20261003-R1'
        AND w.Id=SUBSTRING_INDEX(SUBSTRING_INDEX(p.keyword,':',3),':',-1)
      JOIN eb_store_product p2 ON p2.pid=p.pid AND p2.relation_id=l.store_id
        AND p2.product_type=p.product_type AND p2.id<>p.id AND p2.store_name=p.store_name
      JOIN eb_store_product_attr_value v2 ON v2.product_id=p2.id AND v2.product_type=p2.product_type
        AND v2.suk=ov.suk
      LEFT JOIN eb_inventory_stock oldstock ON oldstock.tenant_id=l.tenant_id AND oldstock.store_id=l.store_id
        AND oldstock.consumable_product_id=l.product_id
      LEFT JOIN eb_cashier_v3_presale_claim c ON c.tenant_id=l.tenant_id AND c.claimable_line_id=l.claimable_line_id
      WHERE l.tenant_id='0' AND l.source_checkout_request_id IN ('JSJ-20260917-R1','SWXT-20261003-R1')
        AND l.quantity>l.claimed_quantity ORDER BY l.id,p2.id,v2.id";
    return $pdo->query($sql)->fetchAll();
}

/** The source and target identity, standard quantity and 1:1 SKU metadata must all agree. */
function r21AllReason(array $r): string
{
    if ((int)$r['claimed_quantity'] !== 0 || !in_array((string)$r['claim_status'],['OPEN','AVAILABLE'],true)
        || $r['prior_claim_id'] !== null) return 'already_claimed_or_closed';
    if ($r['old_stock_id'] !== null) return 'source_stock_exists';
    $jsj = (string)$r['source_batch'] === 'JSJ-20260917-R1';
    $swxt = (string)$r['source_batch'] === 'SWXT-20261003-R1';
    $prefix = $jsj ? 'RH_GOODS:' : 'RH_SWXT:goods:';
    $orderPrefix = $jsj ? 'JSJ-' : 'SWXT-';
    if ((!$jsj && !$swxt) || !in_array((string)$r['source_kind'],['GIFT','PRESALE'],true)
        || !preg_match('/^'.preg_quote($prefix,'/').'[^:]+:STORE:([0-9]+)$/D',(string)$r['keyword'],$match)
        || (int)$match[1] !== (int)$r['store_id']
        || !preg_match('/^'.preg_quote($orderPrefix,'/').'(?:GIFT|PRESALE|STORED)-/D',(string)$r['source_order_no'])) {
        return 'source_identity_invalid';
    }
    if ((int)$r['pid'] <= 0 || (int)$r['old_relation_id'] !== (int)$r['store_id']
        || (int)$r['new_relation_id'] !== (int)$r['store_id']
        || (int)$r['old_type'] !== 1 || (int)$r['new_type'] !== 1
        || (int)$r['old_product_type'] !== 0 || (int)$r['new_product_type'] !== 0
        || (int)$r['old_deleted'] !== (int)$r['new_deleted']
        || (string)$r['old_name'] !== (string)$r['new_name']
        || (string)$r['source_name'] !== (string)$r['new_name']
        || (string)$r['product_name_snapshot'] !== (string)$r['source_name']) return 'product_identity_mismatch';
    if ((int)$r['old_sku_product_id'] !== (int)$r['old_product_id']
        || (string)$r['old_sku_unique'] !== (string)$r['old_unique']
        || (int)$r['new_sku_product_id'] !== (int)$r['new_product_id']
        || (string)$r['new_sku_unique'] === ''
        || (string)$r['old_suk'] !== (string)$r['new_suk']) return 'sku_identity_mismatch';
    if (trim((string)$r['source_unit']) === '' || (string)$r['source_unit'] !== (string)$r['source_standard_unit']
        || (float)$r['source_standard_quantity'] !== 1.0
        || (float)$r['source_rate1'] !== 0.0 || (float)$r['source_rate2'] !== 0.0
        || (float)$r['old_unit_convert'] !== 1.0 || (int)$r['old_decimal_scale'] !== 0
        || (float)$r['new_unit_convert'] !== 1.0 || (int)$r['new_decimal_scale'] !== 0) {
        return 'unit_conversion_unverified';
    }
    return '';
}

/** Approval binds each unchanged right's version and both product/SKU identities. */
function r21AllDigest(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $fields = ['line_pk','claimable_line_id','source_batch','store_id','member_id','source_kind','old_product_id',
            'old_sku_id','old_unique','quantity','version','new_product_id','new_sku_id','new_sku_unique',
            'source_unit','source_standard_quantity','new_unit_convert'];
        $parts[] = json_encode(array_map(static fn(string $key): string => (string)$r[$key],$fields),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    return hash('sha256',implode("\n",$parts));
}

$raw = r21AllCandidates($pdo);
$byLine = [];
foreach ($raw as $row) $byLine[(int)$row['line_pk']][] = $row;
$valid = [];
$reasons = [];
$counts = [];
$identityFaults = [];
$unitFaults = [];
$unitFaultPairs = [];
$unitLabelMismatch = 0;
foreach ($byLine as $candidates) {
    $row = $candidates[0];
    $reason = count($candidates) === 1 ? r21AllReason($row) : 'ambiguous_target_identity';
    if ($reason !== '') {
        $reasons[$reason] = ($reasons[$reason] ?? 0)+1;
        if ($reason === 'product_identity_mismatch') {
            foreach (['store' => (int)$row['old_relation_id'] === (int)$row['store_id'] && (int)$row['new_relation_id'] === (int)$row['store_id'],
                'product_type' => (int)$row['old_type'] === 1 && (int)$row['new_type'] === 1
                    && (int)$row['old_product_type'] === 0 && (int)$row['new_product_type'] === 0,
                'archive_status' => (int)$row['old_deleted'] === (int)$row['new_deleted'],
                'old_new_name' => (string)$row['old_name'] === (string)$row['new_name'],
                'source_target_name' => (string)$row['source_name'] === (string)$row['new_name'],
                'snapshot_source_name' => (string)$row['product_name_snapshot'] === (string)$row['source_name'],
                'product_unit_label' => (string)$row['old_unit_name'] === (string)$row['new_unit_name']] as $key=>$passed) {
                if (!$passed) $identityFaults[$key] = ($identityFaults[$key] ?? 0)+1;
            }
        }
        if ($reason === 'unit_conversion_unverified') {
            // Aggregate labels only: diagnostics must not disclose members or mutate rights.
            $pair = (string)$row['source_unit'].' → '.(string)$row['source_standard_unit'];
            $unitFaultPairs[$pair] = ($unitFaultPairs[$pair] ?? 0)+1;
            foreach (['source_unit_missing'=>trim((string)$row['source_unit']) !== '',
                'source_standard_unit'=>(string)$row['source_unit'] === (string)$row['source_standard_unit'],
                'source_standard_quantity'=>(float)$row['source_standard_quantity'] === 1.0,
                'source_rates'=>(float)$row['source_rate1'] === 0.0 && (float)$row['source_rate2'] === 0.0,
                'source_sku_ratio'=>(float)$row['old_unit_convert'] === 1.0 && (int)$row['old_decimal_scale'] === 0,
                'target_sku_ratio'=>(float)$row['new_unit_convert'] === 1.0 && (int)$row['new_decimal_scale'] === 0] as $key=>$passed) {
                if (!$passed) $unitFaults[$key] = ($unitFaults[$key] ?? 0)+1;
            }
        }
        continue;
    }
    $valid[] = $row;
    // Product/SKU unit labels are display metadata; the PM approved 1:1 after
    // source standard quantity and both SKU conversion factors are verified.
    if ((string)$row['old_unit_name'] !== (string)$row['new_unit_name']
        || (string)$row['source_unit'] !== (string)$row['target_sale_unit']) $unitLabelMismatch++;
    $kind = (string)$row['source_kind'].'/'.(strpos((string)$row['source_order_no'],'STORED') !== false ? 'STORED' : 'OTHER');
    $counts[$kind] = ($counts[$kind] ?? 0)+1;
}
$digest = r21AllDigest($valid);
$result = ['mode'=>$execute?'execute':'dry-run','raw_candidate_rows'=>count($raw),'candidate_lines'=>count($byLine),
    'validated_lines'=>count($valid),'by_kind'=>$counts,'rejected_reasons'=>$reasons,
    'identity_faults'=>$identityFaults,'unit_faults'=>$unitFaults,
    'unit_fault_pairs'=>$unitFaultPairs,
    'unit_label_mismatch_lines'=>$unitLabelMismatch,'sha256'=>$digest];
if ($probeLine > 0) {
    // A probe exposes only identity and eligibility, never member or customer data.
    $probe = $byLine[$probeLine] ?? [];
    $result['probe'] = ['line_pk'=>$probeLine,'candidate_count'=>count($probe),
        'reason'=>$probe ? (count($probe) === 1 ? r21AllReason($probe[0]) : 'ambiguous_target_identity') : 'no_target_candidate',
        'target_product_id'=>count($probe) === 1 ? (int)$probe[0]['new_product_id'] : null];
}
if (!$execute) { echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL; exit(0); }
if (!hash_equals($expected,$digest) || !$valid) throw new RuntimeException('Frozen candidate list changed or is empty; no rows changed');

// An immutable per-line audit and optimistic version update share one transaction.
$pdo->exec("CREATE TABLE IF NOT EXISTS ".R21_ALL_AUDIT." (
    line_pk bigint unsigned NOT NULL PRIMARY KEY,claimable_line_id varchar(64) NOT NULL,
    source_batch varchar(64) NOT NULL,source_kind varchar(16) NOT NULL,store_id bigint unsigned NOT NULL,
    old_product_id bigint unsigned NOT NULL,old_sku_id bigint unsigned NOT NULL,old_unique varchar(64) NOT NULL,
    new_product_id bigint unsigned NOT NULL,new_sku_id bigint unsigned NOT NULL,new_unique varchar(64) NOT NULL,
    source_unit varchar(32) NOT NULL,target_sale_unit varchar(32) NOT NULL,unit_ratio varchar(16) NOT NULL,
    approved_digest char(64) CHARACTER SET ascii NOT NULL,old_version bigint unsigned NOT NULL,repaired_at bigint unsigned NOT NULL,
    UNIQUE KEY uniq_claimable_line (claimable_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$insert = $pdo->prepare('INSERT INTO '.R21_ALL_AUDIT.' (line_pk,claimable_line_id,source_batch,source_kind,store_id,
    old_product_id,old_sku_id,old_unique,new_product_id,new_sku_id,new_unique,source_unit,target_sale_unit,
    unit_ratio,approved_digest,old_version,repaired_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$update = $pdo->prepare("UPDATE eb_cashier_v3_presale_claimable_line SET product_id=?,sku_id=?,sku_unique_snapshot=?,
    version=version+1,updated_at=? WHERE id=? AND tenant_id=? AND store_id=? AND source_checkout_request_id=?
    AND member_id=? AND source_kind=? AND quantity=? AND product_id=? AND sku_id=? AND sku_unique_snapshot=?
    AND version=? AND claimed_quantity=0 AND claim_status IN ('OPEN','AVAILABLE')");
$pdo->beginTransaction();
try {
    $now = time();
    foreach ($valid as $r) {
        $insert->execute([(int)$r['line_pk'],(string)$r['claimable_line_id'],(string)$r['source_batch'],
            (string)$r['source_kind'],(int)$r['store_id'],(int)$r['old_product_id'],(int)$r['old_sku_id'],
            (string)$r['old_unique'],(int)$r['new_product_id'],(int)$r['new_sku_id'],(string)$r['new_sku_unique'],
            (string)$r['source_unit'],(string)$r['target_sale_unit'],'1:1',$digest,(int)$r['version'],$now]);
        $update->execute([(int)$r['new_product_id'],(int)$r['new_sku_id'],(string)$r['new_sku_unique'],$now,
            (int)$r['line_pk'],(string)$r['tenant_id'],(int)$r['store_id'],(string)$r['source_batch'],
            (int)$r['member_id'],(string)$r['source_kind'],(int)$r['quantity'],(int)$r['old_product_id'],
            (int)$r['old_sku_id'],(string)$r['old_unique'],(int)$r['version']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Entitlement changed concurrently; all mappings rolled back');
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
$result['repaired_lines'] = count($valid);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;

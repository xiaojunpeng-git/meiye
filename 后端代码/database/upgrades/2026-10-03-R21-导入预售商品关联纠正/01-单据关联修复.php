<?php
declare(strict_types=1);

/**
 * R21 one-line imported presale mapping repair. The product manager confirmed
 * that one legacy "盒" entitlement equals one unit of the existing stock SKU.
 * Only this source line may be rebound; the source order, member, quantity,
 * business timestamps and physical stock remain untouched by this script.
 */
const R21_CLAIMABLE_ID = 'RH102-presale-0000045419';
const R21_OLD_PRODUCT = 318194;
const R21_OLD_SKU = 302058;
const R21_OLD_UNIQUE = '00006TIQ';
const R21_NEW_PRODUCT = 121848;
const R21_NEW_SKU = 120127;
const R21_NEW_UNIQUE = '047b502d';

$execute = in_array('--execute', $argv, true);
if ($execute && !in_array('--allow-local-ruihao', $argv, true)) {
    throw new RuntimeException('Local write requires --execute --allow-local-ruihao');
}
$env = parse_ini_file('/var/www/html/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
// The local Docker network exposes mohe-mysql under the "mysql" alias used by
// this app container. Neither a remote hostname nor another database is valid.
if (($db['DATABASE'] ?? '') !== 'ruihao' || !in_array(($db['HOSTNAME'] ?? ''), ['mohe-mysql', 'mysql'], true)
    || !is_file('/var/www/html/.env.local-dev') || ($env['APP_DEBUG'] ?? '') !== 'true') {
    throw new RuntimeException('R21 repair is restricted to the local ruihao development database');
}
$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'], $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

/** A repeated invocation must not demand an unclaimed line after a successful test claim. */
function r21AlreadyApplied(PDO $pdo): bool
{
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mig_r21_presale_mapping_audit'")->fetchColumn();
    if (!$exists) return false;
    $audit = $pdo->prepare('SELECT a.line_pk,a.old_product_id,a.old_sku_id,a.old_sku_unique,a.new_product_id,a.new_sku_id,a.new_sku_unique,l.id,l.product_id,l.sku_id,l.sku_unique_snapshot
        FROM mig_r21_presale_mapping_audit a JOIN eb_cashier_v3_presale_claimable_line l ON l.claimable_line_id=a.claimable_line_id WHERE a.claimable_line_id=?');
    $audit->execute([R21_CLAIMABLE_ID]);
    $row = $audit->fetch();
    if (!$row) return false;
    if ((int)$row['line_pk'] !== (int)$row['id'] || (int)$row['old_product_id'] !== R21_OLD_PRODUCT
        || (int)$row['old_sku_id'] !== R21_OLD_SKU || (string)$row['old_sku_unique'] !== R21_OLD_UNIQUE
        || (int)$row['new_product_id'] !== R21_NEW_PRODUCT || (int)$row['new_sku_id'] !== R21_NEW_SKU
        || (string)$row['new_sku_unique'] !== R21_NEW_UNIQUE || (int)$row['product_id'] !== R21_NEW_PRODUCT
        || (int)$row['sku_id'] !== R21_NEW_SKU || (string)$row['sku_unique_snapshot'] !== R21_NEW_UNIQUE) {
        throw new RuntimeException('Existing repair audit and live claimable mapping disagree');
    }
    return true;
}

if (r21AlreadyApplied($pdo)) {
    echo "R21_MAPPING_ALREADY_APPLIED\n";
    exit(0);
}

/** Validate exact identity and inventory authority before any row is changed. */
function r21Preflight(PDO $pdo, bool $lock): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $line = $pdo->prepare('SELECT id,tenant_id,store_id,source_kind,source_checkout_request_id,source_order_no_snapshot,member_id,product_id,sku_id,sku_unique_snapshot,quantity,claimed_quantity,claim_status,version FROM eb_cashier_v3_presale_claimable_line WHERE claimable_line_id=?' . $suffix);
    $line->execute([R21_CLAIMABLE_ID]);
    $line = $line->fetch();
    if (!$line || (string)$line['tenant_id'] !== '0' || (int)$line['store_id'] !== 1
        || (string)$line['source_kind'] !== 'PRESALE' || (string)$line['source_checkout_request_id'] !== 'JSJ-20260917-R1'
        || (string)$line['source_order_no_snapshot'] !== 'JSJ-PRESALE-0000045419' || (int)$line['member_id'] !== 979248
        || (int)$line['quantity'] !== 4 || (int)$line['claimed_quantity'] !== 0
        || !in_array((string)$line['claim_status'], ['OPEN', 'AVAILABLE'], true)) {
        throw new RuntimeException('Claimable identity or remaining quantity changed');
    }
    $claims = $pdo->prepare('SELECT COUNT(*) FROM eb_cashier_v3_presale_claim WHERE tenant_id=? AND claimable_line_id=?');
    $claims->execute(['0', R21_CLAIMABLE_ID]);
    if ((int)$claims->fetchColumn() !== 0) throw new RuntimeException('Claim already exists; do not rebind');

    $products = $pdo->query('SELECT id,pid,store_name FROM eb_store_product WHERE id IN (' . R21_OLD_PRODUCT . ',' . R21_NEW_PRODUCT . ') AND is_del=0')->fetchAll();
    if (count($products) !== 2 || (int)$products[0]['pid'] !== (int)$products[1]['pid']
        || (int)$products[0]['pid'] !== 78887 || (string)$products[0]['store_name'] !== (string)$products[1]['store_name']) {
        throw new RuntimeException('Products no longer resolve to the same approved master');
    }
    $sku = $pdo->prepare('SELECT id,product_id,`unique` FROM eb_store_product_attr_value WHERE id IN (?,?) ORDER BY id');
    $sku->execute([R21_NEW_SKU, R21_OLD_SKU]);
    $skus = $sku->fetchAll();
    if (count($skus) !== 2 || (int)$skus[0]['id'] !== R21_NEW_SKU || (int)$skus[0]['product_id'] !== R21_NEW_PRODUCT
        || (string)$skus[0]['unique'] !== R21_NEW_UNIQUE || (int)$skus[1]['id'] !== R21_OLD_SKU
        || (int)$skus[1]['product_id'] !== R21_OLD_PRODUCT || (string)$skus[1]['unique'] !== R21_OLD_UNIQUE) {
        throw new RuntimeException('Approved SKUs changed');
    }
    $stock = $pdo->prepare("SELECT s.id,s.available_quantity_units,s.quantity_scale,COALESCE(SUM(CASE WHEN b.batch_status='ACTIVE' THEN b.available_quantity_units ELSE 0 END),0) active_batch_units
        FROM eb_inventory_stock s LEFT JOIN eb_inventory_batch b ON b.stock_id=s.id
        WHERE s.tenant_id='0' AND s.store_id=1 AND s.location_id=1 AND s.consumable_product_id=? AND s.sku_id=? AND s.product_unique=? AND s.stock_status='GOOD'
        GROUP BY s.id,s.available_quantity_units,s.quantity_scale");
    $stock->execute([R21_NEW_PRODUCT, R21_NEW_SKU, R21_NEW_UNIQUE]);
    $stocks = $stock->fetchAll();
    if (count($stocks) !== 1 || (int)$stocks[0]['available_quantity_units'] < 1 || (int)$stocks[0]['active_batch_units'] < 1
        || (int)$stocks[0]['quantity_scale'] !== 0) throw new RuntimeException('Approved stock candidate no longer has one claimable unit');
    return ['line' => $line, 'stock' => $stocks[0]];
}

$before = r21Preflight($pdo, false);
if (!$execute) {
    echo json_encode(['mode' => 'dry-run', 'claimable_line_id' => R21_CLAIMABLE_ID,
        'old_product_id' => $before['line']['product_id'], 'old_sku_id' => $before['line']['sku_id'],
        'new_product_id' => R21_NEW_PRODUCT, 'new_sku_id' => R21_NEW_SKU,
        'stock_id' => $before['stock']['id'], 'available_units' => $before['stock']['available_quantity_units']], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

// The audit table belongs to this new upgrade, not to the already-executed
// JSJ importer. A second run is safe only when the exact approved mapping is
// already in place; otherwise it fails instead of silently remapping again.
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_r21_presale_mapping_audit (
    claimable_line_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    line_pk bigint unsigned NOT NULL, old_product_id bigint unsigned NOT NULL, old_sku_id bigint unsigned NOT NULL,
    old_sku_unique varchar(64) NOT NULL, new_product_id bigint unsigned NOT NULL, new_sku_id bigint unsigned NOT NULL,
    new_sku_unique varchar(64) NOT NULL, old_version bigint unsigned NOT NULL, repaired_at bigint unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->beginTransaction();
try {
    $locked = r21Preflight($pdo, true);
    $line = $locked['line'];
    if ((int)$line['product_id'] === R21_NEW_PRODUCT && (int)$line['sku_id'] === R21_NEW_SKU
        && (string)$line['sku_unique_snapshot'] === R21_NEW_UNIQUE) {
        $audit = $pdo->prepare('SELECT COUNT(*) FROM mig_r21_presale_mapping_audit WHERE claimable_line_id=? AND new_product_id=? AND new_sku_id=?');
        $audit->execute([R21_CLAIMABLE_ID, R21_NEW_PRODUCT, R21_NEW_SKU]);
        if ((int)$audit->fetchColumn() !== 1) throw new RuntimeException('Mapped row lacks exact repair audit');
        $pdo->commit();
        echo "R21_MAPPING_ALREADY_APPLIED\n";
        exit(0);
    }
    if ((int)$line['product_id'] !== R21_OLD_PRODUCT || (int)$line['sku_id'] !== R21_OLD_SKU
        || (string)$line['sku_unique_snapshot'] !== R21_OLD_UNIQUE) throw new RuntimeException('Old mapping changed');
    $now = time();
    $insert = $pdo->prepare('INSERT INTO mig_r21_presale_mapping_audit (claimable_line_id,line_pk,old_product_id,old_sku_id,old_sku_unique,new_product_id,new_sku_id,new_sku_unique,old_version,repaired_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([R21_CLAIMABLE_ID, (int)$line['id'], R21_OLD_PRODUCT, R21_OLD_SKU, R21_OLD_UNIQUE,
        R21_NEW_PRODUCT, R21_NEW_SKU, R21_NEW_UNIQUE, (int)$line['version'], $now]);
    $update = $pdo->prepare('UPDATE eb_cashier_v3_presale_claimable_line SET product_id=?,sku_id=?,sku_unique_snapshot=?,version=version+1,updated_at=? WHERE id=? AND version=? AND product_id=? AND sku_id=? AND sku_unique_snapshot=? AND claimed_quantity=0');
    $update->execute([R21_NEW_PRODUCT, R21_NEW_SKU, R21_NEW_UNIQUE, $now, (int)$line['id'], (int)$line['version'],
        R21_OLD_PRODUCT, R21_OLD_SKU, R21_OLD_UNIQUE]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Claimable changed concurrently');
    $pdo->commit();
    echo "R21_MAPPING_REPAIRED\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

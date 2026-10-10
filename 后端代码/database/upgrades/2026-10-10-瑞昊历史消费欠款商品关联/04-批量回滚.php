<?php
declare(strict_types=1);

/** Revert precisely one audited batch of product links; money and performance remain untouched. */
function rollbackOption(array $args, string $name): string
{
    foreach ($args as $arg) if (strpos($arg, $name.'=') === 0) return substr($arg, strlen($name) + 1);
    return '';
}

/** The rollback guard must compare exact cents, not floating-point approximations. */
function rollbackCents(string $value): int
{
    if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $match)) throw new RuntimeException('invalid amount');
    return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
}

$args = array_slice($argv, 1);
$hash = rollbackOption($args, '--batch-hash');
$execute = in_array('--execute', $args, true);
if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('one exact batch hash is required');
if ($execute && (!in_array('--allow-ruihao', $args, true) || rollbackOption($args, '--expect') !== $hash)) {
    throw new RuntimeException('rollback requires --allow-ruihao and matching --expect');
}
$config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('database guard: expected ruihao');
$db = new PDO('mysql:host='.$config['HOSTNAME'].';port='.$config['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$query = $db->prepare('SELECT a.debt_item_id,a.debt_id,a.store_id,a.old_product_id,a.old_product_type,
    a.target_product_id,a.target_product_type,a.amount_cents,a.reverted_at,i.product_id current_product_id,
    i.product_type current_product_type,i.debt_amount
    FROM mig_rh_20261010_consume_debt_product_batch a
    JOIN eb_store_debt_item i ON i.id=a.debt_item_id AND i.debt_id=a.debt_id
    WHERE a.batch_hash=? ORDER BY a.debt_item_id');
$query->execute([$hash]);
$rows = $query->fetchAll();
if ($rows === []) throw new RuntimeException('batch audit rows absent');
$allCurrent = true;
foreach ($rows as $row) {
    if ($row['reverted_at'] !== null || (int)$row['current_product_id'] !== (int)$row['target_product_id']
        || (int)$row['current_product_type'] !== (int)$row['target_product_type']
        || rollbackCents((string)$row['debt_amount']) !== (int)$row['amount_cents']) {
        $allCurrent = false;
        break;
    }
}
echo json_encode(['mode' => $execute ? 'rollback' : 'dry-run', 'batch_hash' => $hash,
    'audit_count' => count($rows), 'all_rows_match' => $allCurrent], JSON_UNESCAPED_UNICODE).PHP_EOL;
if (!$execute) exit(0);
if (!$allCurrent) throw new RuntimeException('batch changed after application');
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['rh_consume_debt_product_repair_20261010']);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
try {
    $db->beginTransaction();
    $check = $db->prepare('SELECT a.reverted_at,a.target_product_id,a.target_product_type,a.old_product_id,a.old_product_type,
        a.amount_cents,i.product_id,i.product_type,i.debt_amount
        FROM mig_rh_20261010_consume_debt_product_batch a
        JOIN eb_store_debt_item i ON i.id=a.debt_item_id AND i.debt_id=a.debt_id
        WHERE a.debt_item_id=? AND a.batch_hash=? FOR UPDATE');
    $update = $db->prepare('UPDATE eb_store_debt_item SET product_id=?,product_type=?,update_time=UNIX_TIMESTAMP()
        WHERE id=? AND debt_id=? AND product_id=? AND product_type=?');
    $audit = $db->prepare('UPDATE mig_rh_20261010_consume_debt_product_batch SET reverted_at=NOW()
        WHERE debt_item_id=? AND batch_hash=? AND reverted_at IS NULL');
    foreach ($rows as $row) {
        $check->execute([(int)$row['debt_item_id'], $hash]);
        $current = $check->fetch();
        if (!$current || $current['reverted_at'] !== null
            || (int)$current['product_id'] !== (int)$current['target_product_id']
            || (int)$current['product_type'] !== (int)$current['target_product_type']
            || rollbackCents((string)$current['debt_amount']) !== (int)$current['amount_cents']) {
            throw new RuntimeException('batch row changed: '.$row['debt_item_id']);
        }
        $update->execute([(int)$current['old_product_id'], (int)$current['old_product_type'],
            (int)$row['debt_item_id'], (int)$row['debt_id'],
            (int)$current['target_product_id'], (int)$current['target_product_type']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('guarded rollback failed: '.$row['debt_item_id']);
        $audit->execute([(int)$row['debt_item_id'], $hash]);
        if ($audit->rowCount() !== 1) throw new RuntimeException('audit rollback failed: '.$row['debt_item_id']);
    }
    $db->commit();
    echo json_encode(['rolled_back' => true, 'row_count' => count($rows), 'batch_hash' => $hash]).PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('rh_consume_debt_product_repair_20261010')");
}

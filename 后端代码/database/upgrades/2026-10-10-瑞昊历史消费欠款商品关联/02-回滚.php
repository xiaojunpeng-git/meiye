<?php
declare(strict_types=1);

/** Revert only the product link recorded by 01-修复.php; financial facts are untouched. */
function argument(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (strpos($arg, $name.'=') === 0) return substr($arg, strlen($name) + 1);
    }
    return '';
}

$args = array_slice($argv, 1);
$repaymentNo = argument($args, '--repayment-no');
$execute = in_array('--execute', $args, true);
if (!preg_match('/^BJ[0-9]{11}$/D', $repaymentNo)) throw new RuntimeException('one exact repayment number is required');
if ($execute && (!in_array('--allow-ruihao', $args, true)
    || !preg_match('/^[a-f0-9]{64}$/D', argument($args, '--expect')))) {
    throw new RuntimeException('rollback requires --allow-ruihao and the applied evidence hash');
}
$config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('database guard: expected ruihao');
$db = new PDO('mysql:host='.$config['HOSTNAME'].';port='.$config['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$query = $db->prepare('SELECT a.*,i.product_id current_product_id,i.product_type current_product_type
    FROM eb_cashier_v3_debt_repayment r
    JOIN mig_rh_20261010_consume_debt_product_repair a ON a.repayment_id=r.repayment_id
    JOIN eb_store_debt_item i ON i.id=a.debt_item_id AND i.debt_id=a.debt_id
    WHERE r.repayment_no=? AND r.tenant_id=?');
$query->execute([$repaymentNo, '0']);
$rows = $query->fetchAll();
if (count($rows) !== 1) throw new RuntimeException('applied repair record is missing or ambiguous');
$row = $rows[0];
if ($row['reverted_at'] !== null || (int)$row['current_product_id'] !== (int)$row['target_product_id']) {
    throw new RuntimeException('repair has already been reverted or the item changed');
}
echo json_encode(['mode' => $execute ? 'rollback' : 'dry-run', 'debt_item_id' => (int)$row['debt_item_id'],
    'current_product_id' => (int)$row['current_product_id'], 'restore_product_id' => (int)$row['old_product_id'],
    'evidence_hash' => $row['evidence_hash']], JSON_UNESCAPED_UNICODE).PHP_EOL;
if (!$execute) exit(0);
if (!hash_equals(argument($args, '--expect'), (string)$row['evidence_hash'])) {
    throw new RuntimeException('applied evidence hash changed');
}
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['rh_consume_debt_product_repair_20261010']);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
try {
    $db->beginTransaction();
    $check = $db->prepare('SELECT a.reverted_at,a.evidence_hash,a.old_product_id,a.old_product_type,
        a.target_product_id,i.product_id,i.product_type
        FROM mig_rh_20261010_consume_debt_product_repair a
        JOIN eb_store_debt_item i ON i.id=a.debt_item_id AND i.debt_id=a.debt_id
        WHERE a.debt_item_id=? FOR UPDATE');
    $check->execute([(int)$row['debt_item_id']]);
    $current = $check->fetch();
    $expectedType = $row['source_table'] === 'bd_goods' ? 0 : 6;
    if (!$current || $current['reverted_at'] !== null
        || !hash_equals((string)$current['evidence_hash'], (string)$row['evidence_hash'])
        || (int)$current['product_id'] !== (int)$current['target_product_id']
        || (int)$current['product_type'] !== $expectedType) {
        throw new RuntimeException('repair changed after rollback preflight');
    }
    $update = $db->prepare('UPDATE eb_store_debt_item SET product_id=?,product_type=?,update_time=UNIX_TIMESTAMP()
        WHERE id=? AND debt_id=? AND product_id=? AND product_type=?');
    $update->execute([(int)$current['old_product_id'], (int)$current['old_product_type'],
        (int)$row['debt_item_id'], (int)$row['debt_id'], (int)$current['target_product_id'], $expectedType]);
    if ($update->rowCount() !== 1) throw new RuntimeException('guarded rollback did not affect one row');
    $audit = $db->prepare('UPDATE mig_rh_20261010_consume_debt_product_repair SET reverted_at=NOW()
        WHERE debt_item_id=? AND reverted_at IS NULL AND evidence_hash=?');
    $audit->execute([(int)$row['debt_item_id'], $row['evidence_hash']]);
    if ($audit->rowCount() !== 1) throw new RuntimeException('audit rollback marker did not affect one row');
    $db->commit();
    echo json_encode(['rolled_back' => true, 'debt_item_id' => (int)$row['debt_item_id']], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('rh_consume_debt_product_repair_20261010')");
}

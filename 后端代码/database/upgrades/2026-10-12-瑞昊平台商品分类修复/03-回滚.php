<?php
declare(strict_types=1);

/** Restore only values written by this batch; later catalog edits are never overwritten. */
function argument(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (strpos($arg, $name.'=') === 0) return substr($arg, strlen($name) + 1);
    }
    return '';
}

$args = array_slice($argv, 1);
$execute = in_array('--execute', $args, true);
$expected = argument($args, '--expect');
if ($execute && (!in_array('--allow-ruihao', $args, true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected))) {
    throw new RuntimeException('rollback requires instance approval and the applied evidence hash');
}
$config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('database guard: expected ruihao');
$db = new PDO('mysql:host='.$config['HOSTNAME'].';port='.$config['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$query = $db->query('SELECT a.product_id,a.old_cate_id,a.new_cate_id,a.evidence_hash,p.cate_id current_cate_id
    FROM mig_rh_20261012_platform_category_repair a
    JOIN eb_store_product p ON p.id=a.product_id
    WHERE a.reverted_at IS NULL ORDER BY a.product_id');
$rows = $query->fetchAll();
echo json_encode(['mode' => $execute ? 'preflight' : 'dry-run', 'rows' => $rows], JSON_UNESCAPED_UNICODE).PHP_EOL;
if (!$execute) exit(0);
if ($rows === []) throw new RuntimeException('there is no applied repair to rollback');
foreach ($rows as $row) {
    if (!hash_equals($expected, (string)$row['evidence_hash'])
        || (string)$row['current_cate_id'] !== (string)$row['new_cate_id']) {
        throw new RuntimeException('repair hash or current catalog value changed for '.$row['product_id']);
    }
}
$lockName = 'rh_platform_category_repair_20261012';
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
try {
    $db->beginTransaction();
    $current = $db->prepare('SELECT a.old_cate_id,a.new_cate_id,a.evidence_hash,a.reverted_at,p.cate_id
        FROM mig_rh_20261012_platform_category_repair a
        JOIN eb_store_product p ON p.id=a.product_id WHERE a.product_id=? FOR UPDATE');
    $restore = $db->prepare('UPDATE eb_store_product SET cate_id=? WHERE id=? AND cate_id=?');
    $mark = $db->prepare('UPDATE mig_rh_20261012_platform_category_repair
        SET reverted_at=NOW() WHERE product_id=? AND reverted_at IS NULL AND evidence_hash=?');
    foreach ($rows as $row) {
        $current->execute([(int)$row['product_id']]);
        $locked = $current->fetch();
        if (!$locked || $locked['reverted_at'] !== null || !hash_equals($expected, (string)$locked['evidence_hash'])
            || (string)$locked['cate_id'] !== (string)$locked['new_cate_id']) {
            throw new RuntimeException('catalog changed after rollback preflight for '.$row['product_id']);
        }
        $restore->execute([(string)$locked['old_cate_id'], (int)$row['product_id'], (string)$locked['new_cate_id']]);
        $mark->execute([(int)$row['product_id'], $expected]);
        if ($restore->rowCount() !== 1 || $mark->rowCount() !== 1) {
            throw new RuntimeException('guarded rollback failed for '.$row['product_id']);
        }
    }
    $db->commit();
    echo json_encode(['rolled_back' => count($rows), 'evidence_hash' => $expected], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

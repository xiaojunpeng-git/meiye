<?php
declare(strict_types=1);

/**
 * Restore only service source fields changed by this migration, with a full
 * compare-and-set against the audit trail. No argument is read-only.
 */

$execute = in_array('--execute', $argv ?? [], true);
$allowRuihao = in_array('--allow-ruihao', $argv ?? [], true);
$envPath = getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || !is_array($env['DATABASE'] ?? null)) {
    throw new RuntimeException('database environment unavailable');
}
$db = $env['DATABASE'];
$database = (string)($db['DATABASE'] ?? '');
if (!in_array($database, ['ruihao', 'xinruihao'], true)) {
    throw new RuntimeException('refuse: unexpected database');
}
if ($execute && $database === 'ruihao' && !$allowRuihao) {
    throw new RuntimeException('refuse: ruihao write requires --allow-ruihao');
}
$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=' . $database . ';charset=utf8mb4',
    $db['USERNAME'],
    $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4');
$tableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_service_source_backfill_audit'")->fetchColumn();
if ($tableExists === 0) {
    echo json_encode(['database' => $database, 'activeAuditRows' => 0, 'mode' => 'read_only'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}
$active = (int)$pdo->query('SELECT COUNT(*) FROM eb_cashier_v3_service_source_backfill_audit WHERE rolled_back_at=0')->fetchColumn();
if (!$execute) {
    echo json_encode(['database' => $database, 'activeAuditRows' => $active, 'mode' => 'read_only'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$lockName = 'same_checkout_service_source_' . $database;
$lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('migration lock unavailable');
try {
    $pdo->beginTransaction();
    $rows = $pdo->query('SELECT a.*,sf.id AS current_id,sf.immutable_fingerprint AS current_fingerprint,sf.business_source_primary_id AS current_primary_id,sf.business_source_primary_name_snapshot AS current_primary_name,sf.business_source_secondary_id AS current_secondary_id,sf.business_source_secondary_name_snapshot AS current_secondary_name,sf.business_source_label_snapshot AS current_label FROM eb_cashier_v3_service_source_backfill_audit a LEFT JOIN eb_cashier_v3_entitlement_service_fact sf ON sf.id=a.service_fact_id WHERE a.rolled_back_at=0 ORDER BY a.service_fact_id FOR UPDATE')->fetchAll();
    $restore = $pdo->prepare("UPDATE eb_cashier_v3_entitlement_service_fact SET business_source_primary_id=0,business_source_primary_name_snapshot='',business_source_secondary_id=0,business_source_secondary_name_snapshot='',business_source_label_snapshot='' WHERE id=? AND immutable_fingerprint=? AND business_source_primary_id=? AND business_source_primary_name_snapshot=? AND business_source_secondary_id=? AND business_source_secondary_name_snapshot=? AND business_source_label_snapshot=?");
    $mark = $pdo->prepare('UPDATE eb_cashier_v3_service_source_backfill_audit SET rolled_back_at=? WHERE id=? AND rolled_back_at=0');
    foreach ($rows as $row) {
        // Abort the whole rollback if any service was changed after backfill.
        if ($row['current_id'] === null
            || (string)$row['current_fingerprint'] !== (string)$row['original_immutable_fingerprint']
            || (string)$row['current_primary_id'] !== (string)$row['primary_source_id']
            || (string)$row['current_primary_name'] !== (string)$row['primary_source_name_snapshot']
            || (string)$row['current_secondary_id'] !== (string)$row['secondary_source_id']
            || (string)$row['current_secondary_name'] !== (string)$row['secondary_source_name_snapshot']
            || (string)$row['current_label'] !== (string)$row['source_label_snapshot']) {
            throw new RuntimeException('rollback conflict at service fact ' . $row['service_fact_id']);
        }
    }
    foreach ($rows as $row) {
        $restore->execute([
            (int)$row['service_fact_id'], (string)$row['original_immutable_fingerprint'],
            (int)$row['primary_source_id'], (string)$row['primary_source_name_snapshot'],
            (int)$row['secondary_source_id'], (string)$row['secondary_source_name_snapshot'],
            (string)$row['source_label_snapshot'],
        ]);
        if ($restore->rowCount() !== 1) throw new RuntimeException('rollback compare-and-set failed');
        $mark->execute([time(), (int)$row['id']]);
        if ($mark->rowCount() !== 1) throw new RuntimeException('rollback audit update failed');
    }
    $pdo->commit();
    echo json_encode(['database' => $database, 'mode' => 'rolled_back', 'restored' => count($rows)], JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

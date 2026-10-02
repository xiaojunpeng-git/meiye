<?php
declare(strict_types=1);

/**
 * Correct only missing service attribution from the uniquely matched sale in
 * the same checkout. No argument is read-only; production writes require both
 * explicit flags and the named database. Every changed fact is audited.
 */

const SERVICE_SOURCE_BACKFILL_KEY = '20261003-001-same-checkout-service-source-backfill';

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
$columns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME IN ('business_source_primary_id','business_source_primary_name_snapshot','business_source_secondary_id','business_source_secondary_name_snapshot','business_source_label_snapshot','source_attribution_type_snapshot')) OR (TABLE_NAME='eb_cashier_v3_sales_order' AND COLUMN_NAME IN ('business_source_primary_id','business_source_primary_name_snapshot','business_source_secondary_id','business_source_secondary_name_snapshot','business_source_label_snapshot'))) ")->fetchColumn();
if ($columns !== 11) throw new RuntimeException('source snapshot columns missing; apply the reservation source migration first');

$eligibility = "FROM eb_cashier_v3_entitlement_service_fact sf JOIN eb_cashier_v3_sales_order so ON so.tenant_id=sf.tenant_id AND so.checkout_request_id=sf.checkout_request_id AND so.store_id=sf.store_id AND so.member_id=sf.member_id WHERE sf.source_document_type<>'reservation' AND sf.checkout_request_id<>'' AND sf.business_source_primary_id=0 AND sf.business_source_primary_name_snapshot='' AND sf.business_source_secondary_id=0 AND sf.business_source_secondary_name_snapshot='' AND sf.business_source_label_snapshot='' AND sf.source_attribution_type_snapshot='other' AND so.order_status='settled' AND so.order_direction='forward' AND so.business_source_primary_id>0 AND so.business_source_primary_name_snapshot<>'' AND so.business_source_label_snapshot<>'' AND (so.business_source_secondary_id=0 OR so.business_source_secondary_name_snapshot<>'')";
$candidateCount = (int)$pdo->query('SELECT COUNT(*) ' . $eligibility)->fetchColumn();
if (!$execute) {
    echo json_encode(['database' => $database, 'mode' => 'read_only', 'candidates' => $candidateCount], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$lockName = 'same_checkout_service_source_' . $database;
$lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('migration lock unavailable');
try {
    // Schema creation is separate from the row transactions. A failed run can
    // safely retain this empty audit table and resume after the cause is fixed.
    $pdo->exec("CREATE TABLE IF NOT EXISTS eb_cashier_v3_service_source_backfill_audit (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      service_fact_id bigint(20) unsigned NOT NULL,
      tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      checkout_request_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      sales_order_id bigint(20) unsigned NOT NULL,
      original_immutable_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      primary_source_id bigint(20) unsigned NOT NULL,
      primary_source_name_snapshot varchar(64) NOT NULL,
      secondary_source_id bigint(20) unsigned NOT NULL,
      secondary_source_name_snapshot varchar(64) NOT NULL,
      source_label_snapshot varchar(140) NOT NULL,
      applied_at bigint(20) unsigned NOT NULL,
      rolled_back_at bigint(20) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (id), UNIQUE KEY uk_service_fact (service_fact_id),
      KEY idx_sales_order (tenant_id,sales_order_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audited same-checkout service attribution correction'");
    $select = $pdo->prepare("SELECT sf.id AS service_id,sf.tenant_id,sf.checkout_request_id,sf.immutable_fingerprint,so.id AS sales_order_id,so.business_source_primary_id,so.business_source_primary_name_snapshot,so.business_source_secondary_id,so.business_source_secondary_name_snapshot,so.business_source_label_snapshot " . $eligibility . ' AND sf.id>? ORDER BY sf.id LIMIT 200 FOR UPDATE');
    $audit = $pdo->prepare('INSERT INTO eb_cashier_v3_service_source_backfill_audit (service_fact_id,tenant_id,checkout_request_id,sales_order_id,original_immutable_fingerprint,primary_source_id,primary_source_name_snapshot,secondary_source_id,secondary_source_name_snapshot,source_label_snapshot,applied_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $update = $pdo->prepare("UPDATE eb_cashier_v3_entitlement_service_fact SET business_source_primary_id=?,business_source_primary_name_snapshot=?,business_source_secondary_id=?,business_source_secondary_name_snapshot=?,business_source_label_snapshot=? WHERE id=? AND immutable_fingerprint=? AND business_source_primary_id=0 AND business_source_primary_name_snapshot='' AND business_source_secondary_id=0 AND business_source_secondary_name_snapshot='' AND business_source_label_snapshot='' AND source_attribution_type_snapshot='other'");
    $lastId = 0;
    $applied = 0;
    do {
        $pdo->beginTransaction();
        try {
            $select->bindValue(1, $lastId, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll();
            $select->closeCursor();
            foreach ($rows as $row) {
                $lastId = (int)$row['service_id'];
                $source = [
                    (int)$row['business_source_primary_id'],
                    (string)$row['business_source_primary_name_snapshot'],
                    (int)$row['business_source_secondary_id'],
                    (string)$row['business_source_secondary_name_snapshot'],
                    (string)$row['business_source_label_snapshot'],
                ];
                $audit->execute(array_merge([
                    $lastId, (string)$row['tenant_id'], (string)$row['checkout_request_id'],
                    (int)$row['sales_order_id'], (string)$row['immutable_fingerprint'],
                ], $source, [time()]));
                $update->execute(array_merge($source, [$lastId, (string)$row['immutable_fingerprint']]));
                if ($update->rowCount() !== 1) throw new RuntimeException('service source compare-and-set failed at fact ' . $lastId);
                $applied++;
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    } while (count($rows) === 200);

    $remaining = (int)$pdo->query('SELECT COUNT(*) ' . $eligibility)->fetchColumn();
    if ($remaining !== 0) throw new RuntimeException('eligible service facts remain after correction: ' . $remaining);
    $log = $pdo->prepare("INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) SELECT ?,?,?,?,?,NOW(),?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key=?)");
    $log->execute([SERVICE_SOURCE_BACKFILL_KEY, '同次销售服务来源审计回填', 'database/upgrades/2026-10-03-同次销售服务来源回填/02-执行.php', '', 'R48', 'Codex', '只补同次销售订单明确来源的空服务事实；其余保留', SERVICE_SOURCE_BACKFILL_KEY]);
    echo json_encode(['database' => $database, 'mode' => 'executed', 'previewCandidates' => $candidateCount, 'applied' => $applied, 'remaining' => $remaining], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

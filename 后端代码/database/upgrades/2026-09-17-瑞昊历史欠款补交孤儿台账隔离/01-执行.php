<?php
declare(strict_types=1);

/**
 * Archives and removes only provable orphan legacy debt-repayment ledger rows.
 * No argument is read-only. It never turns an orphan ledger into a payment fact.
 */

const ORPHAN_REPAY_KEY = 'historical-debt-repayment-orphan-isolation-v1';

$execute = in_array('--execute', $argv ?? [], true);
$allowRuihao = in_array('--allow-ruihao', $argv ?? [], true);
$envPath = getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || !is_array($env['DATABASE'] ?? null)) {
    throw new RuntimeException('unable to read database environment: ' . $envPath);
}
$db = $env['DATABASE'];
$database = (string)($db['DATABASE'] ?? '');
if ($database !== 'xinruihao' && $database !== 'ruihao') {
    throw new RuntimeException('refuse: target must be xinruihao or ruihao');
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

function cents($value): ?int
{
    $value = trim((string)$value);
    if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $matches) !== 1) return null;
    return ((int)$matches[1]) * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
}

function assertSchema(PDO $pdo): void
{
    $requirements = [
        'eb_store_debt_repay' => ['id', 'repay_no', 'debt_id', 'order_id', 'order_sn', 'repay_order_id', 'uid', 'repay_amount', 'pay_type', 'pay_store_id', 'debt_store_id', 'staff_id', 'combination_info', 'add_time'],
        'eb_store_debt' => ['id', 'total_debt', 'repaid_debt', 'status'],
        'eb_store_order' => ['id'],
        'eb_cashier_v3_debt_repayment' => ['debt_id', 'repayment_amount_cents', 'status', 'tenant_id'],
        'eb_cashier_v3_recharge_debt_repayment' => ['debt_id', 'amount_cents', 'status', 'tenant_id'],
    ];
    $tables = array_keys($requirements);
    $statement = $pdo->prepare('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
    $statement->execute($tables);
    $available = [];
    foreach ($statement as $row) $available[(string)$row['table_name']][(string)$row['column_name']] = true;
    foreach ($requirements as $table => $columns) foreach ($columns as $column) {
        if (!isset($available[$table][$column])) throw new RuntimeException('required_schema_missing:' . $table . '.' . $column);
    }
}

/** @return array<int,array<string,mixed>> */
function sourceRows(PDO $pdo): array
{
    return $pdo->query(<<<'SQL'
SELECT r.id,r.repay_no,r.debt_id,r.order_id,r.order_sn,r.repay_order_id,r.uid,r.repay_amount,r.pay_type,
       r.pay_store_id,r.debt_store_id,r.staff_id,r.combination_info,r.add_time,
       d.total_debt,d.repaid_debt,d.status AS debt_status,ro.id AS repay_order_exists
FROM eb_store_debt_repay r
JOIN eb_store_debt d ON d.id=r.debt_id
LEFT JOIN eb_store_order ro ON ro.id=r.repay_order_id
ORDER BY r.debt_id,r.id
SQL
    )->fetchAll();
}

/** @return array<int,int> */
function successfulV3Totals(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query(<<<'SQL'
SELECT debt_id,SUM(amount_cents) amount_cents FROM (
  SELECT debt_id,repayment_amount_cents AS amount_cents FROM eb_cashier_v3_debt_repayment WHERE tenant_id='0' AND status='succeeded'
  UNION ALL
  SELECT debt_id,amount_cents FROM eb_cashier_v3_recharge_debt_repayment WHERE tenant_id='0' AND status='succeeded'
) v3 GROUP BY debt_id
SQL
    ) as $row) $result[(int)$row['debt_id']] = (int)$row['amount_cents'];
    return $result;
}

/** @return array{candidates:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>} */
function classify(array $rows, array $v3Totals): array
{
    $groups = [];
    foreach ($rows as $row) $groups[(int)$row['debt_id']][] = $row;
    $candidates = [];
    $blocked = [];
    foreach ($groups as $debtId => $group) {
        $first = $group[0];
        $legacyTotal = 0;
        $allMissingOrders = true;
        foreach ($group as $row) {
            $amount = cents($row['repay_amount'] ?? null);
            if ($amount === null || $amount <= 0) $legacyTotal = -1;
            else $legacyTotal += $amount;
            if ((int)($row['repay_order_id'] ?? 0) <= 0 || (int)($row['repay_order_exists'] ?? 0) > 0) $allMissingOrders = false;
        }
        $reason = '';
        if ((int)($first['debt_status'] ?? -1) !== 0 || cents($first['total_debt'] ?? null) === null || cents($first['total_debt'] ?? null) <= 0) $reason = 'debt_not_pending_positive';
        elseif (cents($first['repaid_debt'] ?? null) !== 0) $reason = 'debt_repaid_not_zero';
        elseif (($v3Totals[$debtId] ?? 0) !== 0) $reason = 'successful_v3_repayment_exists';
        elseif ($legacyTotal <= 0) $reason = 'legacy_repayment_amount_invalid';
        elseif (!$allMissingOrders) $reason = 'repayment_order_still_exists_or_missing_link';
        if ($reason !== '') {
            foreach ($group as $row) $blocked[] = ['repay_id' => (int)$row['id'], 'debt_id' => $debtId, 'reason' => $reason];
            continue;
        }
        foreach ($group as $row) $candidates[] = $row;
    }
    return compact('candidates', 'blocked');
}

function createBackupTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS eb_mig_historical_debt_repay_orphan_backup (
  repay_id INT UNSIGNED NOT NULL,
  debt_id INT UNSIGNED NOT NULL,
  repay_no VARCHAR(64) NOT NULL,
  repay_order_id INT UNSIGNED NOT NULL,
  raw_payload JSON NOT NULL,
  debt_total_snapshot DECIMAL(14,2) NOT NULL,
  debt_repaid_snapshot DECIMAL(14,2) NOT NULL,
  debt_status_snapshot TINYINT UNSIGNED NOT NULL,
  isolation_reason VARCHAR(96) NOT NULL,
  migration_key VARCHAR(96) NOT NULL,
  archived_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (repay_id),
  KEY idx_debt_id (debt_id),
  KEY idx_migration_key (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );
}

function stillEligible(PDO $pdo, array $candidate): void
{
    $statement = $pdo->prepare(<<<'SQL'
SELECT r.id,r.debt_id,r.repay_order_id,r.repay_amount,d.total_debt,d.repaid_debt,d.status AS debt_status,ro.id AS repay_order_exists
FROM eb_store_debt_repay r
JOIN eb_store_debt d ON d.id=r.debt_id
LEFT JOIN eb_store_order ro ON ro.id=r.repay_order_id
WHERE r.id=? AND r.debt_id=? FOR UPDATE
SQL
    );
    $statement->execute([(int)$candidate['id'], (int)$candidate['debt_id']]);
    $row = $statement->fetch();
    if (!$row || (int)$row['repay_order_id'] <= 0 || (int)$row['repay_order_exists'] > 0
        || (int)$row['debt_status'] !== 0 || cents($row['repaid_debt']) !== 0
        || cents($row['repay_amount']) !== cents($candidate['repay_amount'])) {
        throw new RuntimeException('candidate_stale_or_ineligible:' . (int)$candidate['id']);
    }
}

function archiveAndDelete(PDO $pdo, array $candidate, int $now): void
{
    stillEligible($pdo, $candidate);
    $payload = json_encode([
        'repay_no' => (string)$candidate['repay_no'], 'order_id' => (int)$candidate['order_id'], 'order_sn' => (string)$candidate['order_sn'],
        'repay_order_id' => (int)$candidate['repay_order_id'], 'uid' => (int)$candidate['uid'], 'repay_amount' => (string)$candidate['repay_amount'],
        'pay_type' => (string)$candidate['pay_type'], 'pay_store_id' => (int)$candidate['pay_store_id'], 'debt_store_id' => (int)$candidate['debt_store_id'],
        'staff_id' => (int)$candidate['staff_id'], 'combination_info' => (string)$candidate['combination_info'], 'add_time' => (int)$candidate['add_time'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('backup_payload_encode_failed');
    $backup = $pdo->prepare(<<<'SQL'
INSERT IGNORE INTO eb_mig_historical_debt_repay_orphan_backup
(repay_id,debt_id,repay_no,repay_order_id,raw_payload,debt_total_snapshot,debt_repaid_snapshot,debt_status_snapshot,isolation_reason,migration_key,archived_at)
VALUES(?,?,?,?,?,?,?,?,?,?,?)
SQL
    );
    $backup->execute([
        (int)$candidate['id'], (int)$candidate['debt_id'], (string)$candidate['repay_no'], (int)$candidate['repay_order_id'], $payload,
        (string)$candidate['total_debt'], (string)$candidate['repaid_debt'], (int)$candidate['debt_status'],
        'orphan_repayment_order_missing', ORPHAN_REPAY_KEY, $now,
    ]);
    $delete = $pdo->prepare('DELETE FROM eb_store_debt_repay WHERE id=? AND debt_id=? AND repay_order_id=?');
    $delete->execute([(int)$candidate['id'], (int)$candidate['debt_id'], (int)$candidate['repay_order_id']]);
    if ($delete->rowCount() !== 1) throw new RuntimeException('orphan_repay_delete_conflict:' . (int)$candidate['id']);
}

$lock = ORPHAN_REPAY_KEY . ':' . $database;
if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 30)')->fetchColumn() !== 1) throw new RuntimeException('could not obtain migration lock');
try {
    assertSchema($pdo);
    $result = classify(sourceRows($pdo), successfulV3Totals($pdo));
    $summary = [
        'database' => $database,
        'status' => $execute ? 'ready_to_execute' : 'precheck',
        'candidate_rows' => count($result['candidates']),
        'candidate_amount_cents' => array_sum(array_map(static fn(array $row): int => cents($row['repay_amount']) ?? 0, $result['candidates'])),
        'blocked_rows' => count($result['blocked']),
        'blocked_reason_counts' => array_count_values(array_column($result['blocked'], 'reason')),
        'blocked_samples' => array_slice($result['blocked'], 0, 20),
    ];
    if (!$execute) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }
    createBackupTable($pdo);
    $written = 0;
    foreach (array_chunk($result['candidates'], 200) as $batch) {
        $pdo->beginTransaction();
        try {
            $now = time();
            foreach ($batch as $candidate) {
                archiveAndDelete($pdo, $candidate, $now);
                $written++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
    echo json_encode($summary + ['status' => 'ok', 'archived_and_deleted_rows' => $written], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
}

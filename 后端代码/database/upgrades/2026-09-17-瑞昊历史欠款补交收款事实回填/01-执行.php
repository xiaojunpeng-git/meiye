<?php
declare(strict_types=1);

/**
 * Historical legacy debt-repayment payment-fact backfill.
 * No argument is always read-only.  The script intentionally refuses to
 * convert an inconsistent legacy ledger into a current cash-performance fact.
 */

const BACKFILL_KEY = 'historical-debt-repayment-fact-backfill-v1';
const TENANT_ID = '0';

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
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

/** @return int|null */
function cents($value): ?int
{
    $value = trim((string)$value);
    if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $matches) !== 1) {
        return null;
    }
    $fraction = str_pad($matches[2] ?? '', 2, '0');
    $whole = (int)$matches[1];
    if ($whole > intdiv(PHP_INT_MAX - 99, 100)) return null;
    return $whole * 100 + (int)$fraction;
}

/** This is a legacy payment-code migration, not a metric/keyword rule. */
function paymentMethod(string $legacyPayType): ?string
{
    $legacyPayType = strtolower(trim($legacyPayType));
    return $legacyPayType === 'cash' ? 'other_collection' : null;
}

function sourceKey(int $repayId): string
{
    return 'historical_debt_repayment:payment:' . $repayId;
}

function sourceLineId(int $repayId): string
{
    return 'legacy:' . $repayId . ':payment:1';
}

/** @return array{fact_id:string,event_no:string,event_key:string,command_key:string} */
function identity(int $repayId): array
{
    $digest = hash('sha256', BACKFILL_KEY . '|' . TENANT_ID . '|' . $repayId);
    return [
        'fact_id' => 'CFP-HDR-' . substr($digest, 0, 56),
        'event_no' => 'EVH-DR-' . substr($digest, 0, 48),
        'event_key' => BACKFILL_KEY . ':debt.repaid:' . $repayId . ':1',
        'command_key' => BACKFILL_KEY . ':' . $repayId,
    ];
}

function assertRequiredSchema(PDO $pdo): void
{
    $requirements = [
        'eb_store_debt_repay' => ['id', 'repay_no', 'debt_id', 'uid', 'repay_amount', 'pay_type', 'pay_store_id', 'staff_id', 'add_time'],
        'eb_store_debt' => ['id', 'uid', 'repaid_debt', 'status'],
        'eb_cashier_v3_debt_repayment' => ['debt_id', 'repayment_amount_cents', 'status', 'tenant_id'],
        'eb_cashier_v3_recharge_debt_repayment' => ['debt_id', 'amount_cents', 'status', 'tenant_id'],
        // Require every column this migration writes. A precheck must fail before a
        // production run if the fact-table contract is from another release.
        'eb_cashier_v3_business_event' => [
            'event_no', 'event_key', 'event_type', 'event_version', 'aggregate_type', 'aggregate_id', 'aggregate_version',
            'source_type', 'source_id', 'source_detail_id', 'command_idempotency_key', 'reversal_of', 'tenant_id',
            'organization_id', 'organization_path', 'store_id', 'member_id', 'operator_id', 'business_date', 'occurred_at',
            'settled_at', 'recorded_at', 'aggregate_name_snapshot', 'organization_name_snapshot', 'store_name_snapshot',
            'operator_name_snapshot', 'payload', 'payload_sha256', 'route_fingerprint', 'created_at', 'updated_at',
        ],
        'eb_cashier_v3_payment_fact' => [
            'fact_id', 'business_event_no', 'fact_type', 'fact_direction', 'natural_key', 'command_idempotency_key',
            'immutable_fingerprint', 'fact_version', 'reversal_of', 'status', 'tenant_id', 'tenant_name_snapshot',
            'organization_id', 'organization_name_snapshot', 'organization_path_snapshot', 'store_id', 'store_name_snapshot',
            'member_id', 'member_name_snapshot', 'operator_id', 'operator_name_snapshot', 'business_date',
            'business_timezone', 'occurred_at', 'settled_at', 'recorded_at', 'checkout_request_id', 'order_id',
            'order_no_snapshot', 'source_document_type', 'source_line_id', 'payment_method', 'payment_authority_key',
            'collection_reference', 'amount_cents', 'business_source_primary_id', 'business_source_primary_name_snapshot',
            'business_source_secondary_id', 'business_source_secondary_name_snapshot', 'business_source_label_snapshot',
            'source_attribution_type_snapshot',
        ],
    ];
    $tables = array_keys($requirements);
    $marks = implode(',', array_fill(0, count($tables), '?'));
    $statement = $pdo->prepare('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN (' . $marks . ')');
    $statement->execute($tables);
    $available = [];
    foreach ($statement as $row) $available[(string)$row['table_name']][(string)$row['column_name']] = true;
    foreach ($requirements as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($available[$table][$column])) throw new RuntimeException('required_schema_missing:' . $table . '.' . $column);
        }
    }
}

/** @return array<int,array<string,mixed>> */
function rows(PDO $pdo): array
{
    return $pdo->query(<<<'SQL'
SELECT r.id,r.repay_no,r.debt_id,r.order_id,r.order_sn,r.uid,r.repay_amount,r.pay_type,
       r.pay_store_id,r.debt_store_id,r.staff_id,r.add_time,
       d.uid AS debt_uid,d.total_debt,d.repaid_debt,d.status AS debt_status,
       COALESCE(u.real_name,u.nickname,u.phone,CONCAT('会员#',r.uid)) AS member_name,
       COALESCE(s.name,CONCAT('门店#',r.pay_store_id)) AS store_name
FROM eb_store_debt_repay r
JOIN eb_store_debt d ON d.id=r.debt_id
LEFT JOIN eb_user u ON u.uid=r.uid
LEFT JOIN eb_system_store s ON s.id=r.pay_store_id
ORDER BY r.debt_id ASC,r.id ASC
SQL
    )->fetchAll();
}

/** @return array<int,int> */
function legacyTotals(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT debt_id,SUM(repay_amount) amount FROM eb_store_debt_repay GROUP BY debt_id') as $row) {
        $amount = cents($row['amount'] ?? null);
        if ($amount !== null) $out[(int)$row['debt_id']] = $amount;
    }
    return $out;
}

/** @return array<int,int> */
function v3Totals(PDO $pdo): array
{
    $sql = <<<'SQL'
SELECT debt_id,SUM(amount_cents) amount_cents FROM (
  SELECT debt_id,repayment_amount_cents AS amount_cents
  FROM eb_cashier_v3_debt_repayment WHERE tenant_id='0' AND status='succeeded'
  UNION ALL
  SELECT debt_id,amount_cents
  FROM eb_cashier_v3_recharge_debt_repayment WHERE tenant_id='0' AND status='succeeded'
) repayment GROUP BY debt_id
SQL;
    $out = [];
    foreach ($pdo->query($sql) as $row) $out[(int)$row['debt_id']] = (int)$row['amount_cents'];
    return $out;
}

function v3TotalForDebt(PDO $pdo, int $debtId): int
{
    $statement = $pdo->prepare(<<<'SQL'
SELECT COALESCE(SUM(amount_cents),0) FROM (
  SELECT repayment_amount_cents AS amount_cents
  FROM eb_cashier_v3_debt_repayment WHERE tenant_id='0' AND status='succeeded' AND debt_id=?
  UNION ALL
  SELECT amount_cents
  FROM eb_cashier_v3_recharge_debt_repayment WHERE tenant_id='0' AND status='succeeded' AND debt_id=?
) repayment
SQL
    );
    $statement->execute([$debtId, $debtId]);
    return (int)$statement->fetchColumn();
}

/** @return array<string,bool> */
function existingFacts(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT natural_key FROM eb_cashier_v3_payment_fact WHERE tenant_id='0' AND natural_key LIKE 'historical\\_debt\\_repayment:payment:%' ESCAPE '\\\\'") as $row) {
        $out[(string)$row['natural_key']] = true;
    }
    return $out;
}

/** @return array<string,bool> */
function existingEvents(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT event_key FROM eb_cashier_v3_business_event WHERE event_key LIKE 'historical-debt-repayment-fact-backfill-v1:%'") as $row) {
        $out[(string)$row['event_key']] = true;
    }
    return $out;
}

/** @return array{candidates:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>,covered:int} */
function classify(array $sourceRows, array $legacyTotals, array $v3Totals, array $facts, array $events): array
{
    $blocked = [];
    $candidates = [];
    $covered = 0;
    $groupReasons = [];
    foreach ($sourceRows as $row) {
        $debtId = (int)$row['debt_id'];
        $expected = ($legacyTotals[$debtId] ?? 0) + ($v3Totals[$debtId] ?? 0);
        $recorded = cents($row['repaid_debt'] ?? null);
        if ($recorded === null || $recorded !== $expected) {
            $groupReasons[$debtId] = 'debt_repaid_total_mismatch';
        }
        if ((int)($row['debt_status'] ?? -1) === 2) {
            $groupReasons[$debtId] = 'debt_voided';
        }
        if (paymentMethod((string)($row['pay_type'] ?? '')) === null) {
            $groupReasons[$debtId] = 'unsupported_legacy_payment_method';
        }
    }
    foreach ($sourceRows as $row) {
        $repayId = (int)($row['id'] ?? 0);
        $debtId = (int)($row['debt_id'] ?? 0);
        $key = sourceKey($repayId);
        $id = identity($repayId);
        $reason = $groupReasons[$debtId] ?? '';
        $amount = cents($row['repay_amount'] ?? null);
        if ($reason === '' && ($repayId <= 0 || $debtId <= 0 || $amount === null || $amount <= 0)) $reason = 'repay_identity_or_amount_invalid';
        if ($reason === '' && ((int)($row['uid'] ?? 0) <= 0 || (int)($row['uid'] ?? 0) !== (int)($row['debt_uid'] ?? 0))) $reason = 'member_relation_invalid';
        if ($reason === '' && ((int)($row['pay_store_id'] ?? 0) <= 0 || (int)($row['add_time'] ?? 0) <= 0 || trim((string)($row['repay_no'] ?? '')) === '')) $reason = 'store_or_time_or_document_missing';
        if ($reason === '' && isset($events[$id['event_key']]) && !isset($facts[$key])) $reason = 'event_without_payment_fact';
        if ($reason !== '') {
            $blocked[] = ['repay_id' => $repayId, 'debt_id' => $debtId, 'reason' => $reason];
            continue;
        }
        if (isset($facts[$key])) {
            $covered++;
            continue;
        }
        $row['amount_cents'] = $amount;
        $row['payment_method'] = paymentMethod((string)$row['pay_type']);
        $row['identity'] = $id;
        $candidates[] = $row;
    }
    return compact('candidates', 'blocked', 'covered');
}

function assertStillEligible(PDO $pdo, array $candidate): void
{
    $debtId = (int)$candidate['debt_id'];
    $repayId = (int)$candidate['id'];
    $row = $pdo->prepare('SELECT r.id,r.repay_amount,r.uid,r.pay_store_id,r.add_time,r.repay_no,r.pay_type,d.uid debt_uid,d.repaid_debt,d.status debt_status FROM eb_store_debt_repay r JOIN eb_store_debt d ON d.id=r.debt_id WHERE r.id=? AND r.debt_id=? FOR UPDATE');
    $row->execute([$repayId, $debtId]);
    $locked = $row->fetch();
    if (!$locked) throw new RuntimeException('candidate_disappeared:' . $repayId);
    $all = $pdo->prepare('SELECT SUM(repay_amount) amount FROM eb_store_debt_repay WHERE debt_id=? FOR UPDATE');
    $all->execute([$debtId]);
    $legacy = cents($all->fetchColumn());
    $recorded = cents($locked['repaid_debt'] ?? null);
    if ($legacy === null || $recorded === null || $legacy + v3TotalForDebt($pdo, $debtId) !== $recorded
        || (int)$locked['debt_status'] === 2 || paymentMethod((string)$locked['pay_type']) === null
        || cents($locked['repay_amount']) !== (int)$candidate['amount_cents']) {
        throw new RuntimeException('candidate_stale_or_ineligible:' . $repayId);
    }
}

/** @param array<string,mixed> $values */
function insertImmutable(PDO $pdo, string $table, array $values): void
{
    $columns = array_keys($values);
    $holders = array_map(static function (string $column): string { return ':' . $column; }, $columns);
    $statement = $pdo->prepare(
        'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES(' . implode(',', $holders) . ')'
    );
    $statement->execute($values);
}

function writeCandidate(PDO $pdo, array $candidate, int $recordedAt): void
{
    assertStillEligible($pdo, $candidate);
    $repayId = (int)$candidate['id'];
    $id = $candidate['identity'];
    $occurredAt = (int)$candidate['add_time'];
    $businessDate = date('Y-m-d', $occurredAt);
    $eventPayload = [
        'migration' => BACKFILL_KEY,
        'legacy_repay_id' => $repayId,
        'legacy_repay_no' => (string)$candidate['repay_no'],
        'fact_natural_key' => sourceKey($repayId),
        'outbox' => 'not_emitted_historical_backfill',
    ];
    $payload = json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('event_payload_encode_failed');
    insertImmutable($pdo, 'eb_cashier_v3_business_event', [
        'event_no' => $id['event_no'], 'event_key' => $id['event_key'], 'event_type' => 'debt.repaid', 'event_version' => 1,
        'aggregate_type' => 'debt_repayment', 'aggregate_id' => (string)$repayId, 'aggregate_version' => 1,
        'source_type' => 'historical-debt-repayment-backfill', 'source_id' => (string)$repayId, 'source_detail_id' => '',
        'command_idempotency_key' => $id['command_key'], 'reversal_of' => '', 'tenant_id' => TENANT_ID,
        'organization_id' => '0', 'organization_path' => '/', 'store_id' => (int)$candidate['pay_store_id'],
        'member_id' => (int)$candidate['uid'], 'operator_id' => max(0, (int)$candidate['staff_id']),
        'business_date' => $businessDate, 'occurred_at' => $occurredAt, 'settled_at' => $occurredAt, 'recorded_at' => $recordedAt,
        'aggregate_name_snapshot' => '历史欠款补交', 'organization_name_snapshot' => '历史未留存组织快照',
        'store_name_snapshot' => (string)$candidate['store_name'], 'operator_name_snapshot' => '历史未留存操作人',
        'payload' => $payload, 'payload_sha256' => hash('sha256', $payload),
        'route_fingerprint' => hash('sha256', BACKFILL_KEY . ':no-outbox'), 'created_at' => $recordedAt, 'updated_at' => $recordedAt,
    ]);
    $fingerprint = hash('sha256', implode('|', [
        BACKFILL_KEY, $repayId, $candidate['debt_id'], $candidate['repay_no'], $candidate['uid'],
        $candidate['pay_store_id'], $candidate['amount_cents'], $candidate['payment_method'], $occurredAt,
    ]));
    insertImmutable($pdo, 'eb_cashier_v3_payment_fact', [
        'fact_id' => $id['fact_id'], 'business_event_no' => $id['event_no'], 'fact_type' => 'payment_collected',
        'fact_direction' => 'forward', 'natural_key' => sourceKey($repayId), 'command_idempotency_key' => $id['command_key'],
        'immutable_fingerprint' => $fingerprint, 'fact_version' => 1, 'reversal_of' => '', 'status' => 'effective',
        'tenant_id' => TENANT_ID, 'tenant_name_snapshot' => '', 'organization_id' => '0',
        'organization_name_snapshot' => '历史未留存组织快照', 'organization_path_snapshot' => '/',
        'store_id' => (int)$candidate['pay_store_id'], 'store_name_snapshot' => (string)$candidate['store_name'],
        'member_id' => (int)$candidate['uid'], 'member_name_snapshot' => (string)$candidate['member_name'],
        'operator_id' => max(0, (int)$candidate['staff_id']), 'operator_name_snapshot' => '历史未留存操作人',
        'business_date' => $businessDate, 'business_timezone' => 'Asia/Shanghai', 'occurred_at' => $occurredAt,
        'settled_at' => $occurredAt, 'recorded_at' => $recordedAt, 'checkout_request_id' => 'legacy-debt-repay:' . $repayId,
        'order_id' => 'legacy-debt-repay:' . $repayId, 'order_no_snapshot' => (string)$candidate['repay_no'],
        'source_document_type' => 'debt_repayment', 'source_line_id' => sourceLineId($repayId),
        'payment_method' => (string)$candidate['payment_method'], 'payment_authority_key' => 'legacy-store-debt-repay:' . $repayId,
        'collection_reference' => (string)$candidate['repay_no'], 'amount_cents' => (int)$candidate['amount_cents'],
        'business_source_primary_id' => 0, 'business_source_primary_name_snapshot' => '', 'business_source_secondary_id' => 0,
        'business_source_secondary_name_snapshot' => '', 'business_source_label_snapshot' => '历史欠款补交',
        'source_attribution_type_snapshot' => 'other',
    ]);
}

$lockName = 'historical_debt_repayment_fact_backfill_' . $database;
if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 30)')->fetchColumn() !== 1) {
    throw new RuntimeException('could not obtain migration lock');
}
try {
    assertRequiredSchema($pdo);
    $result = classify(rows($pdo), legacyTotals($pdo), v3Totals($pdo), existingFacts($pdo), existingEvents($pdo));
    $summary = [
        'database' => $database,
        'status' => $execute ? 'ready_to_execute' : 'precheck',
        'covered_rows_skipped' => $result['covered'],
        'candidate_rows' => count($result['candidates']),
        'candidate_amount_cents' => array_sum(array_column($result['candidates'], 'amount_cents')),
        'blocked_rows' => count($result['blocked']),
        'blocked_reason_counts' => array_count_values(array_column($result['blocked'], 'reason')),
        'blocked_samples' => array_slice($result['blocked'], 0, 20),
    ];
    if (!$execute) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }
    $written = 0;
    foreach (array_chunk($result['candidates'], 200) as $batch) {
        $pdo->beginTransaction();
        try {
            $now = time();
            foreach ($batch as $candidate) {
                writeCandidate($pdo, $candidate, $now);
                $written++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
    echo json_encode($summary + ['status' => 'ok', 'written_rows' => $written], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
}

<?php
declare(strict_types=1);

/**
 * Repair historical personnel-adjustment facts whose reporting date was set
 * to the edit day instead of the source sales day. The default mode is read-only.
 *
 * This runner deliberately uses PDO instead of booting ThinkPHP: database
 * upgrades must remain executable when optional web-server extensions such as
 * Swoole are unavailable in the maintenance shell.
 */
const R47_DATE_UPGRADE_KEY = '20261013-047-personnel-adjustment-business-date';
const R47_TABLE_PERFORMANCE = 'eb_cashier_v3_performance_fact';
const R47_TABLE_GUIDE = 'eb_cashier_v3_customer_guide_round_fact';
const R47_TABLE_MANAGER = 'eb_cashier_v3_sales_manager_fact';

$mode = $argv[1] ?? '--dry-run';
$expectedDatabase = trim((string)($argv[2] ?? ''));
if (!in_array($mode, ['--dry-run', '--test-rollback', '--apply'], true) || $expectedDatabase === '') {
    throw new RuntimeException('Usage: php 01-执行.php [--dry-run|--test-rollback|--apply] <expected-database>');
}

$backend = dirname(__DIR__, 3);
$environment = parse_ini_file($backend . '/.env', true, INI_SCANNER_RAW);
$databaseConfig = is_array($environment) ? ($environment['DATABASE'] ?? null) : null;
if (!is_array($databaseConfig)) throw new RuntimeException('Cannot read DATABASE configuration');
$configuredDatabase = trim((string)($databaseConfig['DATABASE'] ?? ''));
if ($configuredDatabase !== $expectedDatabase) throw new RuntimeException('Configured database identity mismatch');

// Local Docker verification may override only the connection endpoint; database
// identity and credentials still come from the instance's own protected config.
$host = trim((string)(getenv('R47_DB_HOST') ?: ($databaseConfig['HOSTNAME'] ?? '127.0.0.1')));
$port = (int)(getenv('R47_DB_PORT') ?: ($databaseConfig['HOSTPORT'] ?? 3306));
$charset = trim((string)($databaseConfig['CHARSET'] ?? 'utf8mb4')) ?: 'utf8mb4';
$dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $configuredDatabase . ';charset=' . $charset;
$pdo = new PDO(
    $dsn,
    (string)($databaseConfig['USERNAME'] ?? ''),
    (string)($databaseConfig['PASSWORD'] ?? ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== $expectedDatabase) throw new RuntimeException('Connected database identity mismatch');

/** Exact decimal negation keeps project-count precision out of floating point. */
function r47NegateDecimal($value): ?string
{
    if ($value === null || trim((string)$value) === '') return null;
    $text = trim((string)$value);
    if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $text) !== 1) {
        throw new RuntimeException('Invalid project count decimal');
    }
    if (trim(str_replace(['-', '.', '0'], '', $text)) === '') return '0';
    return $text[0] === '-' ? substr($text, 1) : '-' . $text;
}

/** Restrict dynamic SQL identifiers to the three audited fact tables. */
function r47Table(string $table): string
{
    $allowed = [R47_TABLE_PERFORMANCE, R47_TABLE_GUIDE, R47_TABLE_MANAGER];
    if (!in_array($table, $allowed, true)) throw new RuntimeException('Unsupported correction table');
    return '`' . $table . '`';
}

/** Insert a cloned immutable fact after changing only correction-owned fields. */
function r47Insert(PDO $pdo, string $table, array $row): void
{
    if ($row === []) throw new RuntimeException('Cannot insert an empty correction fact');
    foreach (array_keys($row) as $column) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', (string)$column) !== 1) {
            throw new RuntimeException('Unsupported correction column');
        }
    }
    $columns = array_keys($row);
    $quotedColumns = implode(',', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
    $placeholders = implode(',', array_map(static fn(string $column): string => ':' . $column, $columns));
    $statement = $pdo->prepare('INSERT INTO ' . r47Table($table) . ' (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
    if (!$statement->execute($row) || $statement->rowCount() !== 1) {
        throw new RuntimeException('Cannot insert correction into ' . $table);
    }
}

/** @return array<int,array<string,mixed>> */
function r47PerformancePlan(PDO $pdo, bool $lock): array
{
    $sql = 'SELECT p.*,o.business_date AS source_business_date
        FROM `' . R47_TABLE_PERFORMANCE . '` p
        INNER JOIN `eb_cashier_v3_sales_order` o
          ON o.tenant_id=p.tenant_id AND o.order_id=p.order_id
        WHERE p.status=\'effective\'
          AND p.fact_direction=\'forward\'
          AND p.natural_key LIKE \'order_lifecycle:adjust:%\'
          AND p.business_date<>o.business_date
          AND NOT EXISTS (
            SELECT 1 FROM `' . R47_TABLE_PERFORMANCE . '` r
            WHERE r.tenant_id=p.tenant_id
              AND r.reversal_of=p.fact_id
              AND r.status=\'effective\'
              AND r.fact_direction=\'reversal\'
          )
        ORDER BY p.id ASC' . ($lock ? ' FOR UPDATE' : '');
    return $pdo->query($sql)->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function r47AttributionPlan(PDO $pdo, string $table, bool $lock): array
{
    $sql = 'SELECT a.*,o.business_date AS source_business_date
        FROM ' . r47Table($table) . ' a
        INNER JOIN `eb_cashier_v3_sales_order` o
          ON o.tenant_id=a.tenant_id AND o.order_id=a.order_id
        WHERE a.status=\'effective\'
          AND a.natural_key LIKE \'sales-order-adjust:%\'
          AND a.business_date<>o.business_date
        ORDER BY a.id ASC' . ($lock ? ' FOR UPDATE' : '');
    return $pdo->query($sql)->fetchAll();
}

/** Create signed reversal plus correct-dated forward facts without mutating the source fact. */
function r47RepairPerformance(PDO $pdo, array $source, int $now): array
{
    $oldFactId = (string)$source['fact_id'];
    $identity = hash('sha256', (string)$source['tenant_id'] . '|' . $oldFactId);
    $eventNo = 'R47-DATE-' . strtoupper(substr($identity, 0, 40));
    $commandKey = 'r47_personnel_date:' . $oldFactId;
    $base = $source;
    unset($base['id'], $base['source_business_date']);

    $reversal = $base;
    $reversal['fact_id'] = 'R47R-' . strtoupper(substr($identity, 0, 40));
    $reversal['business_event_no'] = $eventNo;
    $reversal['fact_direction'] = 'reversal';
    $reversal['natural_key'] = 'r47_personnel_date:reversal:' . $oldFactId;
    $reversal['command_idempotency_key'] = $commandKey;
    $reversal['fact_version'] = 1;
    $reversal['reversal_of'] = $oldFactId;
    foreach (['allocation_base_amount_cents', 'amount_cents', 'labor_fee_amount_cents', 'project_count_half_units'] as $field) {
        if (array_key_exists($field, $reversal)) $reversal[$field] = -(int)$source[$field];
    }
    if (array_key_exists('project_count_decimal', $reversal)) {
        $reversal['project_count_decimal'] = r47NegateDecimal($source['project_count_decimal']);
    }
    // The reversal remains on the wrong edit date so date-based net readers
    // clear that day; the replacement forward fact moves the value to the sale day.
    $reversal['business_date'] = (string)$source['business_date'];
    $reversal['occurred_at'] = $now;
    $reversal['settled_at'] = $now;
    $reversal['recorded_at'] = $now;
    $reversal['immutable_fingerprint'] = hash('sha256', json_encode(
        $reversal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));

    $forward = $base;
    $forward['fact_id'] = 'R47F-' . strtoupper(substr($identity, 0, 40));
    $forward['business_event_no'] = $eventNo;
    $forward['fact_direction'] = 'forward';
    $forward['natural_key'] = 'r47_personnel_date:forward:' . $oldFactId;
    $forward['command_idempotency_key'] = $commandKey;
    $forward['fact_version'] = 1;
    $forward['reversal_of'] = '';
    $forward['business_date'] = (string)$source['source_business_date'];
    $forward['occurred_at'] = $now;
    $forward['settled_at'] = $now;
    $forward['recorded_at'] = $now;
    $forward['immutable_fingerprint'] = hash('sha256', json_encode(
        $forward, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));

    r47Insert($pdo, R47_TABLE_PERFORMANCE, $reversal);
    r47Insert($pdo, R47_TABLE_PERFORMANCE, $forward);
    return [$reversal['fact_id'], $forward['fact_id']];
}

/** Follow the attribution table's existing status-replacement contract. */
function r47RepairAttribution(PDO $pdo, string $table, string $prefix, array $source, int $now): string
{
    $oldId = (int)$source['id'];
    $oldFactId = (string)$source['fact_id'];
    $identity = hash('sha256', $table . '|' . (string)$source['tenant_id'] . '|' . $oldFactId);
    $statement = $pdo->prepare('UPDATE ' . r47Table($table) . ' SET status=\'reversed\' WHERE id=:id AND status=\'effective\'');
    $statement->execute(['id' => $oldId]);
    if ($statement->rowCount() !== 1) throw new RuntimeException('Attribution replacement conflict ' . $oldFactId);

    $row = $source;
    unset($row['id'], $row['source_business_date']);
    $row['fact_id'] = $prefix . '-' . strtoupper(substr($identity, 0, 40));
    $row['natural_key'] = 'r47_personnel_date:' . strtolower($prefix) . ':' . $oldFactId;
    $row['business_event_no'] = 'R47-DATE-' . strtoupper(substr($identity, 0, 40));
    $row['command_idempotency_key'] = 'r47_personnel_date:' . $oldFactId;
    $row['business_date'] = (string)$source['source_business_date'];
    $row['occurred_at'] = $now;
    $row['recorded_at'] = $now;
    $row['status'] = 'effective';
    $row['immutable_fingerprint'] = hash('sha256', json_encode(
        $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
    r47Insert($pdo, $table, $row);
    return $row['fact_id'];
}

/** Verify that every deterministic correction identifier exists exactly once. */
function r47VerifyWritten(PDO $pdo, array $written): void
{
    $groups = [R47_TABLE_PERFORMANCE => [], R47_TABLE_GUIDE => [], R47_TABLE_MANAGER => []];
    foreach ($written as $factId) {
        if (strpos($factId, 'R47R-') === 0 || strpos($factId, 'R47F-') === 0) $groups[R47_TABLE_PERFORMANCE][] = $factId;
        elseif (strpos($factId, 'R47G-') === 0) $groups[R47_TABLE_GUIDE][] = $factId;
        elseif (strpos($factId, 'R47M-') === 0) $groups[R47_TABLE_MANAGER][] = $factId;
        else throw new RuntimeException('Unexpected correction identifier');
    }
    $actual = 0;
    foreach ($groups as $table => $ids) {
        if ($ids === []) continue;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare('SELECT COUNT(*) FROM ' . r47Table($table) . ' WHERE fact_id IN (' . $placeholders . ')');
        $statement->execute($ids);
        $actual += (int)$statement->fetchColumn();
    }
    if ($actual !== count($written)) throw new RuntimeException('Correction fact count mismatch');
}

/** Register successful data upgrades without depending on application bootstrap. */
function r47RecordUpgrade(PDO $pdo, int $now, int $performance, int $guides, int $managers): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM `eb_database_upgrade_log` WHERE upgrade_key=:upgrade_key');
    $statement->execute(['upgrade_key' => R47_DATE_UPGRADE_KEY]);
    if ((int)$statement->fetchColumn() !== 0) return;
    $statement = $pdo->prepare('INSERT INTO `eb_database_upgrade_log`
        (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
        VALUES (:upgrade_key,:title,:task_file,:sql_checksum,:code_version,:executed_at,:executed_by,:result_note)');
    $statement->execute([
        'upgrade_key' => R47_DATE_UPGRADE_KEY,
        'title' => '人员调整事实业务日期修复',
        'task_file' => 'database/upgrades/2026-10-13-人员调整事实业务日期修复/01-执行.php',
        'sql_checksum' => hash_file('sha256', __FILE__),
        'code_version' => 'WORKTREE',
        'executed_at' => date('Y-m-d H:i:s', $now),
        'executed_by' => 'Codex',
        'result_note' => 'performance=' . $performance . ',guide=' . $guides . ',sales_manager=' . $managers,
    ]);
}

$write = $mode !== '--dry-run';
$pdo->beginTransaction();
try {
    $performance = r47PerformancePlan($pdo, $write);
    $guides = r47AttributionPlan($pdo, R47_TABLE_GUIDE, $write);
    $managers = r47AttributionPlan($pdo, R47_TABLE_MANAGER, $write);
    echo 'database=' . $database . ' performance=' . count($performance)
        . ' guide=' . count($guides) . ' sales_manager=' . count($managers) . PHP_EOL;
    foreach (array_merge($performance, $guides, $managers) as $row) {
        echo (string)$row['order_no_snapshot'] . ' ' . (string)$row['fact_id'] . ' '
            . (string)$row['business_date'] . ' -> ' . (string)$row['source_business_date'] . PHP_EOL;
    }

    if ($write) {
        $now = time();
        $written = [];
        foreach ($performance as $row) $written = array_merge($written, r47RepairPerformance($pdo, $row, $now));
        foreach ($guides as $row) $written[] = r47RepairAttribution($pdo, R47_TABLE_GUIDE, 'R47G', $row, $now);
        foreach ($managers as $row) $written[] = r47RepairAttribution($pdo, R47_TABLE_MANAGER, 'R47M', $row, $now);
        if (count(r47PerformancePlan($pdo, false)) !== 0
            || count(r47AttributionPlan($pdo, R47_TABLE_GUIDE, false)) !== 0
            || count(r47AttributionPlan($pdo, R47_TABLE_MANAGER, false)) !== 0) {
            throw new RuntimeException('Post-repair mismatched active facts remain');
        }
        r47VerifyWritten($pdo, $written);
        r47RecordUpgrade($pdo, $now, count($performance), count($guides), count($managers));
        if ($mode === '--apply') {
            $pdo->commit();
            echo "R47_PERSONNEL_DATE_REPAIR=COMMITTED\n";
        } else {
            $pdo->rollBack();
            echo "R47_PERSONNEL_DATE_REPAIR=TEST_ROLLED_BACK\n";
        }
    } else {
        $pdo->rollBack();
        echo "R47_PERSONNEL_DATE_REPAIR=DRY_RUN\n";
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

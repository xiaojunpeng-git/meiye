<?php
declare(strict_types=1);

/** R88: one-off, explicitly authorized name refresh; never change source IDs or business values. */
function r88Tables(): array
{
    $tables = [];
    foreach (['balance_fact', 'debt_repayment', 'entitlement_service_fact', 'payment_fact',
        'performance_fact', 'recharge_debt_repayment', 'reservation', 'sale_fact', 'sales_order'] as $suffix) {
        $tables['eb_cashier_v3_' . $suffix] = ['business_source_primary_id', 'business_source_secondary_id',
            'business_source_primary_name_snapshot', 'business_source_secondary_name_snapshot', 'business_source_label_snapshot'];
    }
    $tables['eb_user_recharge'] = $tables['eb_cashier_v3_sales_order'];
    $tables['eb_cashier_v3_checkout_business_source_selection'] = ['primary_source_id', 'secondary_source_id',
        'primary_source_name_snapshot', 'secondary_source_name_snapshot', 'source_label_snapshot'];
    $tables['eb_cashier_v3_customer_lifecycle_fact'] = ['source_primary_id', 'source_secondary_id',
        'source_primary_name_snapshot', 'source_secondary_name_snapshot', 'source_label_snapshot'];
    $tables['eb_cashier_v3_customer_lifecycle_projection'] = ['first_course_source_primary_id', 'first_course_source_secondary_id',
        'first_course_source_primary_name_snapshot', 'first_course_source_secondary_name_snapshot', 'first_course_source_label_snapshot'];
    ksort($tables);
    return $tables;
}

function r88Json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Hash all non-target columns without exporting customer/financial details. */
function r88Protected(array $row, array $fields): string
{
    unset($row[$fields[3]], $row[$fields[4]]);
    ksort($row);
    return hash('sha256', r88Json($row));
}

/** Unknown IDs/parent mismatches stay untouched. Unexpected label formats stop for review. */
function r88Change(array $row, array $fields, array $catalog): ?array
{
    [$primary, $secondary, $primaryName, $secondaryName, $label] = $fields;
    $source = $catalog[(int)$row[$secondary]] ?? null;
    if (!$source || (int)$source['parent_id'] <= 0 || (int)$source['parent_id'] !== (int)$row[$primary]) return null;
    if ((string)$row[$primaryName] === '' || (string)$source['name'] === '') throw new RuntimeException('Empty source name');
    $oldLabel = $row[$primaryName] . ' / ' . $row[$secondaryName];
    $newLabel = $row[$primaryName] . ' / ' . $source['name'];
    if ($row[$label] !== $oldLabel && $row[$label] !== $newLabel) throw new RuntimeException('Unexpected source label');
    $before = [$secondaryName => $row[$secondaryName], $label => $row[$label]];
    $after = [$secondaryName => $source['name'], $label => $newLabel];
    if ($before === $after) return null;
    return ['id' => (string)$row['id'], 'primary_id' => (string)$row[$primary],
        'secondary_id' => (string)$row[$secondary], 'before' => $before, 'after' => $after,
        'protected_sha256' => r88Protected($row, $fields)];
}

function r88Catalog(PDO $db, bool $lock = false): array
{
    $rows = $db->query('SELECT id,parent_id,name FROM eb_cashier_v3_business_source ORDER BY id'
        . ($lock ? ' LOCK IN SHARE MODE' : ''))->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) $result[(int)$row['id']] = $row;
    return $result;
}

/** Schema guard prevents triggers, nontransactional tables or automatic timestamp side effects. */
function r88Schema(PDO $db): void
{
    foreach (r88Tables() as $table => $fields) {
        $meta = $db->query("SHOW TABLE STATUS LIKE '$table'")->fetch(PDO::FETCH_ASSOC);
        if (!$meta || $meta['Engine'] !== 'InnoDB') throw new RuntimeException('Nontransactional target: ' . $table);
        $columns = $db->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $keys = [];
        $names = [];
        foreach ($columns as $column) {
            $names[] = $column['Field'];
            if ($column['Key'] === 'PRI') $keys[] = $column['Field'];
            if (stripos($column['Extra'], 'on update') !== false) throw new RuntimeException('Automatic column update');
        }
        if ($keys !== ['id'] || array_diff($fields, $names)) throw new RuntimeException('Unexpected target schema');
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
        $q->execute([$table]);
        if ((int)$q->fetchColumn()) throw new RuntimeException('Target has triggers');
    }
}

/** Freeze only linked old-name rows; audit/event payloads are historical evidence, not display snapshots. */
function r88Plan(PDO $db): array
{
    r88Schema($db);
    $catalog = r88Catalog($db);
    $plan = ['format' => 'R88-secondary-names-v1', 'database' => 'ruihao', 'catalog' => $catalog, 'tables' => [], 'counts' => []];
    foreach (r88Tables() as $table => $fields) {
        $rows = $db->query("SELECT * FROM `$table` WHERE `{$fields[1]}`>0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $plan['tables'][$table] = [];
        foreach ($rows as $row) {
            $change = r88Change($row, $fields, $catalog);
            if ($change !== null) $plan['tables'][$table][] = $change;
        }
        $plan['counts'][$table] = count($plan['tables'][$table]);
    }
    return $plan;
}

/** Caller owns transaction. Full-row hash + locks protect concurrent edits; rollback never overwrites later changes. */
function r88Apply(PDO $db, array $plan, bool $restore = false): int
{
    if (!$db->inTransaction()) throw new RuntimeException('Transaction required');
    if (($plan['format'] ?? '') !== 'R88-secondary-names-v1' || $plan['database'] !== 'ruihao') throw new RuntimeException('Wrong plan');
    if (r88Catalog($db, true) !== $plan['catalog']) throw new RuntimeException('Catalog changed');
    $tables = r88Tables();
    if (array_keys($plan['tables']) !== array_keys($tables)) throw new RuntimeException('Unexpected table list');
    $count = 0;
    foreach ($tables as $table => $fields) {
        $read = $db->prepare("SELECT * FROM `$table` WHERE id=? FOR UPDATE");
        $write = $db->prepare("UPDATE `$table` SET `{$fields[3]}`=?,`{$fields[4]}`=? WHERE id=?");
        foreach ($plan['tables'][$table] as $change) {
            $read->execute([$change['id']]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!$row || r88Protected($row, $fields) !== $change['protected_sha256']) throw new RuntimeException('Protected columns changed');
            $from = $restore ? $change['after'] : $change['before'];
            $to = $restore ? $change['before'] : $change['after'];
            if (array_keys($from) !== [$fields[3], $fields[4]] || array_keys($to) !== array_keys($from)) throw new RuntimeException('Unexpected write fields');
            foreach ($from as $key => $value) if ($row[$key] !== $value) throw new RuntimeException('Source changed concurrently');
            if (!$restore && r88Change($row, $fields, $plan['catalog']) !== $change) throw new RuntimeException('Plan transformation mismatch');
            $write->execute([$to[$fields[3]], $to[$fields[4]], $change['id']]);
            if ($write->rowCount() !== 1) throw new RuntimeException('Unexpected affected rows');
            $read->execute([$change['id']]);
            $after = $read->fetch(PDO::FETCH_ASSOC);
            $expected = array_replace($row, $to);
            if ($after !== $expected) throw new RuntimeException('Non-name data changed');
            $count++;
        }
    }
    return $count;
}

// Library mode is only for local tests. CLI refuses local mirrors and any database except the RH production instance.
if (defined('R88_LIBRARY_ONLY')) return;
$mode = $argv[1] ?? 'plan';
if (!in_array($mode, ['plan', 'execute', 'restore'], true)) throw new RuntimeException('Unsupported mode');
$env = parse_ini_file('/www/wwwroot/rh.cc3798.com/.env', true)['DATABASE'];
if ($env['DATABASE'] !== 'ruihao' || $env['HOSTNAME'] !== '127.0.0.1' || (string)$env['HOSTPORT'] !== '3306') throw new RuntimeException('Wrong instance');
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=ruihao;charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
$db->exec('SET SESSION innodb_lock_wait_timeout=5');
try {
    if ($mode === 'plan') {
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        $plan = r88Plan($db);
        $db->rollBack();
        echo r88Json($plan), "\n";
    } else {
        $file = $argv[2] ?? '';
        $sha = $argv[3] ?? '';
        if (strpos($file, '/www/backups/rh.cc3798.com/r88-source-names-') !== 0
            || !is_file($file) || !preg_match('/^[a-f0-9]{64}$/', $sha) || hash_file('sha256', $file) !== $sha) {
            throw new RuntimeException('Verified independent recovery file required');
        }
        $plan = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        r88Schema($db);
        if ((int)$db->query("SELECT GET_LOCK('R88-secondary-name-repair',0)")->fetchColumn() !== 1) throw new RuntimeException('Repair is running');
        $db->beginTransaction();
        $count = r88Apply($db, $plan, $mode === 'restore');
        $db->commit();
        echo r88Json(['mode' => $mode, 'changed' => $count, 'counts' => $plan['counts'], 'recovery_sha256' => $sha]), "\n";
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

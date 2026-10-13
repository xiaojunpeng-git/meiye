<?php
/**
 * Materialize the existing 60/30 minute reservation fallback for unset 008 projects.
 *
 * The product editor stores an unset minute value as zero, not NULL. Only project
 * rows (product_type=6) whose respective column is zero may change; positive
 * values, cards, products, orders and historical reservation snapshots stay intact.
 */
declare(strict_types=1);

const M008_DEFAULT_DURATION_BEFORE_SHA = '5a7e55fd7f840b365a67c352b343fb03c3d8550091c58958b351879f03a7ad16';
const M008_DEFAULT_DURATION_AFTER_SHA = 'b1d48324c1ce5633ceddcdb3adb3b235c66aba29c80a95ebca963ec5f2359890';
const M008_DEFAULT_DURATION_UPGRADE_KEY = '20261013-008-project-duration-default-r1';
const M008_DEFAULT_DURATION_LEDGER_DIR = '/www/backups/008.cc3798.com';

/** Refuse credentials pointing anywhere other than the named 008 database. */
function m008DefaultDurationConnect(): PDO
{
    $env = parse_ini_file('/www/wwwroot/008.cc3798.com/.env');
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'mohe_008'
        || (int)($env['HOSTPORT'] ?? 0) !== 3307) {
        throw new RuntimeException('008 database environment identity changed');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=mohe_008;charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $db->query('SELECT DATABASE() AS db, @@port AS port')->fetch();
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)($identity['port'] ?? 0) !== 3307) {
        throw new RuntimeException('Connected database identity changed');
    }
    return $db;
}

/** Use a stable row ordering and scalar representation for fixed-version guards. */
function m008DefaultDurationRows(PDO $db, bool $lock = false): array
{
    $sql = 'SELECT id,pid,type,relation_id,store_name,is_show,is_del,'
        . 'project_service_duration,addon_service_duration FROM eb_store_product '
        . 'WHERE product_type=6 AND is_del=0 ORDER BY id' . ($lock ? ' FOR UPDATE' : '');
    $rows = $db->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        foreach (['id', 'pid', 'type', 'relation_id', 'is_show', 'is_del',
            'project_service_duration', 'addon_service_duration'] as $key) {
            if ($row[$key] === null) {
                throw new RuntimeException('Project row unexpectedly contains NULL: ' . $key);
            }
            $row[$key] = (string)$row[$key];
        }
    }
    unset($row);
    if (count($rows) !== 1373) {
        throw new RuntimeException('008 project scope changed');
    }
    return $rows;
}

/** Preserve each configured positive value and fill only the corresponding zero. */
function m008DefaultDurationPlan(array $rows): array
{
    $after = $rows;
    $changes = [];
    $counts = ['total' => count($rows), 'both_zero' => 0, 'addon_only_zero' => 0,
        'both_positive' => 0, 'changed_rows' => 0, 'project_filled' => 0, 'addon_filled' => 0];
    foreach ($after as $index => &$row) {
        $beforeProject = (int)$row['project_service_duration'];
        $beforeAddon = (int)$row['addon_service_duration'];
        if ($beforeProject < 0 || $beforeProject > 9999 || $beforeAddon < 0 || $beforeAddon > 9999) {
            throw new RuntimeException('Project minutes outside editor range: ' . $row['id']);
        }
        if ($beforeProject === 0 && $beforeAddon === 0) {
            ++$counts['both_zero'];
        } elseif ($beforeProject > 0 && $beforeAddon === 0) {
            ++$counts['addon_only_zero'];
        } elseif ($beforeProject > 0 && $beforeAddon > 0) {
            ++$counts['both_positive'];
        } else {
            throw new RuntimeException('Unexpected project-only zero: ' . $row['id']);
        }
        $project = $beforeProject === 0 ? 60 : $beforeProject;
        $addon = $beforeAddon === 0 ? 30 : $beforeAddon;
        $counts['project_filled'] += $beforeProject === 0 ? 1 : 0;
        $counts['addon_filled'] += $beforeAddon === 0 ? 1 : 0;
        $row['project_service_duration'] = (string)$project;
        $row['addon_service_duration'] = (string)$addon;
        if ($project !== $beforeProject || $addon !== $beforeAddon) {
            ++$counts['changed_rows'];
            $changes[] = ['id' => (int)$row['id'], 'before' => [$beforeProject, $beforeAddon],
                'after' => [$project, $addon]];
        }
    }
    unset($row);
    if ($counts !== ['total' => 1373, 'both_zero' => 982, 'addon_only_zero' => 23,
        'both_positive' => 368, 'changed_rows' => 1005, 'project_filled' => 982,
        'addon_filled' => 1005]) {
        throw new RuntimeException('008 reviewed zero distribution changed');
    }
    return [$after, $changes, $counts];
}

/** Fixed fingerprints guard every project ID, name, relation and both minute values. */
function m008DefaultDurationSha(array $rows): string
{
    return hash('sha256', (string)json_encode($rows,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Store only the changed columns for guarded recovery, never a whole database copy. */
function m008DefaultDurationLedger(array $changes): array
{
    if (!is_dir(M008_DEFAULT_DURATION_LEDGER_DIR)) {
        throw new RuntimeException('008 rollback ledger directory missing');
    }
    $path = M008_DEFAULT_DURATION_LEDGER_DIR . '/project-duration-default-'
        . date('Ymd_His') . '-' . getmypid() . '.json';
    $body = (string)json_encode([
        'upgrade_key' => M008_DEFAULT_DURATION_UPGRADE_KEY,
        'before_sha256' => M008_DEFAULT_DURATION_BEFORE_SHA,
        'after_sha256' => M008_DEFAULT_DURATION_AFTER_SHA,
        'changes' => $changes,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $file = fopen($path, 'x');
    if ($file === false) {
        throw new RuntimeException('Cannot create 008 rollback ledger');
    }
    chmod($path, 0600);
    try {
        if (fwrite($file, $body) !== strlen($body) || !fflush($file)) {
            throw new RuntimeException('008 rollback ledger incomplete');
        }
    } finally {
        fclose($file);
    }
    return [$path, hash_file('sha256', $path)];
}

/** A stale editor write must abort the entire transaction, not silently skip a row. */
function m008DefaultDurationApplyChanges(PDO $db, array $changes): void
{
    $update = $db->prepare('UPDATE eb_store_product '
        . 'SET project_service_duration=?,addon_service_duration=? '
        . 'WHERE id=? AND product_type=6 AND is_del=0 '
        . 'AND project_service_duration=? AND addon_service_duration=?');
    foreach ($changes as $change) {
        $update->execute([$change['after'][0], $change['after'][1], $change['id'],
            $change['before'][0], $change['before'][1]]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('008 project changed during import: ' . $change['id']);
        }
    }
}

/** Compare the marker and its private ledger with the complete post-image. */
function m008DefaultDurationVerify(PDO $db): array
{
    $marker = $db->prepare('SELECT result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $marker->execute([M008_DEFAULT_DURATION_UPGRADE_KEY]);
    $note = $marker->fetchColumn();
    if (!is_string($note) || !preg_match('/^1005 rows; ledger (project-duration-default-[0-9_]+-[0-9]+\\.json); SHA256 ([a-f0-9]{64})$/D', $note, $parts)) {
        throw new RuntimeException('008 default-duration upgrade marker missing or invalid');
    }
    $ledger = M008_DEFAULT_DURATION_LEDGER_DIR . '/' . $parts[1];
    if (!is_file($ledger) || hash_file('sha256', $ledger) !== $parts[2]) {
        throw new RuntimeException('008 default-duration ledger mismatch');
    }
    $rows = m008DefaultDurationRows($db);
    if (m008DefaultDurationSha($rows) !== M008_DEFAULT_DURATION_AFTER_SHA) {
        throw new RuntimeException('008 project duration post-image differs from reviewed version');
    }
    return ['total' => 1373, 'updated' => 1005, 'project_filled' => 982,
        'addon_filled' => 1005, 'mismatch' => 0,
        'ledger' => $ledger, 'ledger_sha256' => $parts[2]];
}

/** check is read-only; apply writes only 008 under one lock and transaction. */
function m008DefaultDurationRun(string $mode): array
{
    if (!in_array($mode, ['check', 'apply', 'verify'], true)) {
        throw new InvalidArgumentException('Expected check, apply or verify');
    }
    $db = m008DefaultDurationConnect();
    if ($mode === 'verify') {
        return ['mode' => $mode, 'database' => 'mohe_008',
            'result' => m008DefaultDurationVerify($db)];
    }
    $locked = false;
    try {
        if ($mode === 'apply') {
            $locked = (int)$db->query("SELECT GET_LOCK('m008_project_duration_default_r1',10)")->fetchColumn() === 1;
            if (!$locked) {
                throw new RuntimeException('008 project duration lock unavailable');
            }
            $db->beginTransaction();
        }
        $marker = $db->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
        $marker->execute([M008_DEFAULT_DURATION_UPGRADE_KEY]);
        if ((int)$marker->fetchColumn() !== 0) {
            throw new RuntimeException('008 project duration defaults already applied');
        }
        $rows = m008DefaultDurationRows($db, $mode === 'apply');
        if (m008DefaultDurationSha($rows) !== M008_DEFAULT_DURATION_BEFORE_SHA) {
            throw new RuntimeException('008 project duration source drifted');
        }
        [$after, $changes, $counts] = m008DefaultDurationPlan($rows);
        if (m008DefaultDurationSha($after) !== M008_DEFAULT_DURATION_AFTER_SHA) {
            throw new RuntimeException('008 project duration expected post-image changed');
        }
        if ($mode === 'check') {
            return ['mode' => $mode, 'database' => 'mohe_008',
                'before_sha256' => M008_DEFAULT_DURATION_BEFORE_SHA,
                'after_sha256' => M008_DEFAULT_DURATION_AFTER_SHA, 'counts' => $counts];
        }
        [$ledger, $ledgerSha] = m008DefaultDurationLedger($changes);
        m008DefaultDurationApplyChanges($db, $changes);
        $db->prepare('INSERT INTO eb_database_upgrade_log '
            . '(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) '
            . 'VALUES (?,?,?,?,?,NOW(),?,?)')->execute([
                M008_DEFAULT_DURATION_UPGRADE_KEY, '008项目时长默认值补齐',
                __FILE__, hash_file('sha256', __FILE__), 'M008-project-duration-default-r1',
                'Codex', '1005 rows; ledger ' . basename($ledger) . '; SHA256 ' . $ledgerSha,
            ]);
        $db->commit();
        return ['mode' => $mode, 'database' => 'mohe_008',
            'result' => m008DefaultDurationVerify($db)];
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($locked) {
            $db->query("SELECT RELEASE_LOCK('m008_project_duration_default_r1')");
        }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        echo json_encode(m008DefaultDurationRun((string)($argv[1] ?? '')),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, '008 project duration defaults stopped: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

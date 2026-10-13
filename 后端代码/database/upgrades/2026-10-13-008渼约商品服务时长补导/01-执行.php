<?php
/**
 * Copy the reviewed morestore service minutes into the already imported 008 catalogue.
 *
 * Source identity is fixed by the catalogue plan and a duration-specific fingerprint.
 * Only existing source-ID products in mohe_008 may change; prices, cards, rights,
 * reservations and unrelated catalogue rows are outside this migration.
 */
declare(strict_types=1);

const M008_DURATION_PLAN_SHA = 'aeeeac75ecab64cd434357e42a12e5d0e42cd464cde08ed2c39982e072d23973';
const M008_DURATION_SOURCE_SHA = '603715d937054c8c9b0a512673a163b761424da7faa94f1a6b74d5906bffa2c6';
const M008_DURATION_UPGRADE_KEY = '20261013-008-morestore-product-duration-r1';
const M008_DURATION_BACKUP_DIR = '/www/backups/008.cc3798.com';

/** Connect to one named database and independently confirm its live identity. */
function m008DurationConnect(string $path, string $database, int $port): PDO
{
    $env = parse_ini_file($path);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== $database
        || (int)($env['HOSTPORT'] ?? 0) !== $port) {
        throw new RuntimeException('Database environment identity changed');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $database . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $db->query('SELECT DATABASE() AS db, @@port AS port')->fetch();
    if (($identity['db'] ?? '') !== $database || (int)($identity['port'] ?? 0) !== $port) {
        throw new RuntimeException('Connected database identity changed');
    }
    return $db;
}

/** The previously accepted catalogue plan is the sole authority for product/store scope. */
function m008DurationPlan(string $path): array
{
    if (!is_file($path) || hash_file('sha256', $path) !== M008_DURATION_PLAN_SHA) {
        throw new RuntimeException('Reviewed catalogue plan changed');
    }
    $plan = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['plan_version'] ?? '') !== '008-morestore-catalog-r1'
        || ($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008'
        || ($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || count($plan['roots'] ?? []) !== 160 || count($plan['store_map'] ?? []) !== 22) {
        throw new RuntimeException('Catalogue plan identity changed');
    }
    return $plan;
}

/** Read old roots and only their previously reviewed, operating-store children. */
function m008DurationSource(PDO $source, array $plan): array
{
    $roots = array_map(static function ($r) { return (int)$r['old_id']; }, $plan['roots']);
    $stores = array_map('intval', array_keys($plan['store_map']));
    $rootSql = implode(',', $roots);
    $storeSql = implode(',', $stores);
    $rows = $source->query('SELECT id,pid,type,relation_id,product_type,store_name,work_time,add_work_time '
        . 'FROM eb_store_product WHERE id IN (' . $rootSql . ') '
        . 'OR (pid IN (' . $rootSql . ') AND type=1 AND relation_id IN (' . $storeSql . ')) '
        . 'ORDER BY id')->fetchAll();
    // mysqlnd may return native integers when prepared emulation is disabled.
    // Canonical strings keep the reviewed fingerprint independent of PDO fetch mode.
    foreach ($rows as &$row) {
        foreach (['id', 'pid', 'type', 'relation_id', 'product_type', 'work_time', 'add_work_time'] as $key) {
            if ($row[$key] !== null) {
                $row[$key] = (string)$row[$key];
            }
        }
    }
    unset($row);
    if (count($rows) !== 3273 || hash('sha256', (string)json_encode($rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== M008_DURATION_SOURCE_SHA) {
        throw new RuntimeException('Old catalogue duration source drifted');
    }
    return $rows;
}

/** NULL in the old editor means unset; retain it as target zero for existing fallback rules. */
function m008DurationMinutes($value): int
{
    if ($value === null) {
        return 0;
    }
    $text = (string)$value;
    if (!preg_match('/^\d+$/D', $text) || (int)$text > 9999) {
        throw new RuntimeException('Old service minutes are outside the editor range');
    }
    return (int)$text;
}

/** Match each old ID to the same imported root/child, organization store and target type. */
function m008DurationExpected(array $plan, array $sourceRows): array
{
    $roots = [];
    foreach ($plan['roots'] as $root) {
        $id = (int)$root['old_id'];
        if ($id <= 0 || isset($roots[$id]) || !in_array((int)$root['target_type'], [0, 5, 6], true)) {
            throw new RuntimeException('Invalid reviewed root');
        }
        $roots[$id] = (int)$root['target_type'];
    }
    $expected = [];
    $rootCount = 0;
    foreach ($sourceRows as $row) {
        $id = (int)$row['id'];
        $pid = (int)$row['pid'];
        $root = $pid ?: $id;
        if (!isset($roots[$root]) || isset($expected[$id])) {
            throw new RuntimeException('Unexpected source product identity');
        }
        $isRoot = $pid === 0;
        $oldStore = (int)$row['relation_id'];
        if (($isRoot && ((int)$row['type'] !== 0 || $oldStore !== 0))
            || (!$isRoot && ((int)$row['type'] !== 1 || !isset($plan['store_map'][(string)$oldStore])))) {
            throw new RuntimeException('Source product/store relationship changed');
        }
        $rootCount += $isRoot ? 1 : 0;
        $expected[$id] = [
            'pid' => $pid,
            'type' => (int)$row['type'],
            'store' => $isRoot ? 0 : (int)$plan['store_map'][(string)$oldStore],
            'product_type' => $roots[$root],
            'name' => (string)$row['store_name'],
            'project' => m008DurationMinutes($row['work_time']),
            'addon' => m008DurationMinutes($row['add_work_time']),
        ];
    }
    if ($rootCount !== 160 || count($expected) !== 3273) {
        throw new RuntimeException('Reviewed root/child count changed');
    }
    return $expected;
}

/** Lock target rows only for apply; check and verify remain read-only. */
function m008DurationTarget(PDO $target, array $expected, bool $lock): array
{
    $ids = implode(',', array_map('intval', array_keys($expected)));
    $sql = 'SELECT id,pid,type,relation_id,product_type,store_name,'
        . 'project_service_duration,addon_service_duration FROM eb_store_product '
        . 'WHERE id IN (' . $ids . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : '');
    $rows = $target->query($sql)->fetchAll();
    if (count($rows) !== 3273) {
        throw new RuntimeException('008 imported product coverage changed');
    }
    $found = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $want = $expected[$id] ?? null;
        if (!$want || (int)$row['pid'] !== $want['pid'] || (int)$row['type'] !== $want['type']
            || (int)$row['relation_id'] !== $want['store']
            || (int)$row['product_type'] !== $want['product_type']
            || (string)$row['store_name'] !== $want['name']) {
            throw new RuntimeException('008 product identity changed: ' . $id);
        }
        $found[$id] = [(int)$row['project_service_duration'], (int)$row['addon_service_duration']];
    }
    return $found;
}

/** A small 008-only before/after ledger makes the data edit reversible without a full DB dump. */
function m008DurationSnapshot(array $expected, array $before): array
{
    if (!is_dir(M008_DURATION_BACKUP_DIR)) {
        throw new RuntimeException('008 rollback directory missing');
    }
    $path = M008_DURATION_BACKUP_DIR . '/morestore-product-duration-'
        . date('Ymd_His') . '-' . getmypid() . '.json';
    $records = [];
    foreach ($expected as $id => $row) {
        $records[] = ['id' => $id, 'before' => $before[$id], 'after' => [$row['project'], $row['addon']]];
    }
    $body = (string)json_encode([
        'upgrade_key' => M008_DURATION_UPGRADE_KEY,
        'source_sha256' => M008_DURATION_SOURCE_SHA,
        'plan_sha256' => M008_DURATION_PLAN_SHA,
        'rows' => $records,
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
    $digest = hash('sha256', $body);
    if (hash_file('sha256', $path) !== $digest) {
        throw new RuntimeException('008 rollback ledger checksum failed');
    }
    return [$path, $digest];
}

/** Summarize the exact two-column change; no booking or rights data is modified. */
function m008DurationCounts(array $expected, array $actual): array
{
    $result = ['scoped' => count($expected), 'project_positive' => 0, 'addon_positive' => 0,
        'changed_rows' => 0, 'unchanged_zero_rows' => 0, 'mismatch' => 0];
    foreach ($expected as $id => $row) {
        $after = [$row['project'], $row['addon']];
        $result['project_positive'] += $row['project'] > 0 ? 1 : 0;
        $result['addon_positive'] += $row['addon'] > 0 ? 1 : 0;
        $result['changed_rows'] += $after !== [0, 0] ? 1 : 0;
        $result['unchanged_zero_rows'] += $after === [0, 0] ? 1 : 0;
        $result['mismatch'] += ($actual[$id] ?? null) === $after ? 0 : 1;
    }
    return $result;
}

/** Update only untouched zero-minute rows; a stale row aborts the whole transaction. */
function m008DurationApplyUpdates(PDO $target, array $expected): int
{
    $update = $target->prepare('UPDATE eb_store_product SET project_service_duration=?,addon_service_duration=? '
        . 'WHERE id=? AND project_service_duration=0 AND addon_service_duration=0');
    $changed = 0;
    foreach ($expected as $id => $row) {
        if ($row['project'] === 0 && $row['addon'] === 0) {
            continue;
        }
        $update->execute([$row['project'], $row['addon'], $id]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('008 duration concurrent edit: ' . $id);
        }
        $changed++;
    }
    if ($changed !== 2825) {
        throw new RuntimeException('008 duration update count changed');
    }
    return $changed;
}

/** Run once under a named lock and a single InnoDB transaction. */
function m008DurationRun(string $mode, string $planPath): array
{
    if (!in_array($mode, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Usage: php 01-执行.php check|apply|verify PLAN_JSON');
    }
    $plan = m008DurationPlan($planPath);
    $source = m008DurationConnect('/www/wwwroot/morestore.cc3798.com/.env', 'morestore', 3306);
    $target = m008DurationConnect('/www/wwwroot/008.cc3798.com/.env', 'mohe_008', 3307);
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->beginTransaction();
    $locked = false;
    try {
        $rows = m008DurationSource($source, $plan);
        $expected = m008DurationExpected($plan, $rows);
        if ($mode === 'apply') {
            $locked = (int)$target->query("SELECT GET_LOCK('m008_morestore_product_duration_r1', 10)")->fetchColumn() === 1;
            if (!$locked) {
                throw new RuntimeException('008 duration migration lock unavailable');
            }
            $target->beginTransaction();
        }
        $actual = m008DurationTarget($target, $expected, $mode === 'apply');
        $markerStmt = $target->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
        $markerStmt->execute([M008_DURATION_UPGRADE_KEY]);
        $marked = (int)$markerStmt->fetchColumn() === 1;
        if ($mode === 'verify') {
            if (!$marked) {
                throw new RuntimeException('008 duration upgrade marker missing');
            }
            $counts = m008DurationCounts($expected, $actual);
            if ($counts['mismatch'] !== 0) {
                throw new RuntimeException('008 duration post-import mismatch: ' . $counts['mismatch']);
            }
        } else {
            if ($marked) {
                throw new RuntimeException('008 duration upgrade already applied');
            }
            foreach ($actual as $values) {
                if ($values !== [0, 0]) {
                    throw new RuntimeException('008 duration editor values changed since preflight');
                }
            }
            $counts = m008DurationCounts($expected, $actual);
            if ($counts['changed_rows'] !== 2825 || $counts['unchanged_zero_rows'] !== 448) {
                throw new RuntimeException('Reviewed duration count changed');
            }
        }
        if ($mode === 'apply') {
            [$snapshot, $snapshotSha] = m008DurationSnapshot($expected, $actual);
            $changed = m008DurationApplyUpdates($target, $expected);
            $target->prepare('INSERT INTO eb_database_upgrade_log '
                . '(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) '
                . 'VALUES (?,?,?,?,?,NOW(),?,?)')->execute([
                    M008_DURATION_UPGRADE_KEY, '008渼约商品服务时长补导', __FILE__,
                    hash_file('sha256', __FILE__), 'M008-product-duration-r1', 'Codex',
                    '2825 rows; rollback ledger ' . basename($snapshot) . '; SHA256 ' . $snapshotSha,
                ]);
            $target->commit();
            $actual = m008DurationTarget($target, $expected, false);
            $counts = m008DurationCounts($expected, $actual);
            if ($counts['mismatch'] !== 0) {
                throw new RuntimeException('008 duration verification after commit failed');
            }
            $counts['rollback_ledger'] = $snapshot;
            $counts['rollback_sha256'] = $snapshotSha;
        }
        $source->rollBack();
        return ['mode' => $mode, 'target_database' => 'mohe_008',
            'source_sha256' => M008_DURATION_SOURCE_SHA, 'counts' => $counts];
    } finally {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        if ($source->inTransaction()) {
            $source->rollBack();
        }
        if ($locked) {
            $target->query("SELECT RELEASE_LOCK('m008_morestore_product_duration_r1')");
        }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $result = m008DurationRun((string)($argv[1] ?? ''), (string)($argv[2] ?? ''));
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, '008 duration migration stopped: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

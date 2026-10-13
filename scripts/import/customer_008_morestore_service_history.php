<?php
/**
 * Copy completed old Meiyue work orders into an 008-only display projection.
 * This script never writes V3 service/writeoff/labor facts or card balances.
 * Usage: php customer_008_morestore_service_history.php preflight|check|apply|verify
 */
declare(strict_types=1);

// Old work timestamps are Unix seconds recorded for China stores; business
// dates must not drift with the server CLI's default timezone.
date_default_timezone_set('Asia/Shanghai');

const SERVICE_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const SERVICE_TARGET_ENV = '/www/wwwroot/008.cc3798.com/.env';
const SERVICE_PLAN_PATH = __DIR__ . '/customer_008_morestore_service_history_plan.json';
const SERVICE_BATCH_KEY = '008-morestore-service-history-r1';
const SERVICE_UPGRADE_KEY = '20261013-008-morestore-service-history-r1';
const SERVICE_TABLE = 'eb_mobile_customer_legacy_service_record';
const SERVICE_BATCH_TABLE = 'mig_morestore_20261013_service_history_batch';

/** Keep credentials in server memory and reject a different instance or port. */
function serviceHistoryDb(string $path, string $name, int $port): PDO
{
    $env = parse_ini_file($path);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== $name
        || (int)($env['HOSTPORT'] ?? 0) !== $port) {
        throw new RuntimeException('Database environment identity changed');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $port
        . ';dbname=' . $name . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $db->query('SELECT DATABASE() db,@@port port')->fetch();
    if (($identity['db'] ?? '') !== $name || (int)($identity['port'] ?? 0) !== $port) {
        throw new RuntimeException('Connected database identity changed');
    }
    return $db;
}

/** The accepted member migration is the only source UID to target UID map. */
function serviceHistoryMembers(PDO $target): array
{
    $map = [];
    foreach ($target->query('SELECT source_uid,target_uid FROM mig_morestore_20261013_member_map') as $row) {
        $map[(int)$row['source_uid']] = (int)$row['target_uid'];
    }
    if (count($map) !== 5729) {
        throw new RuntimeException('008 member map differs from the accepted migration');
    }
    return $map;
}

/** Read old work item snapshots in bounded batches; sales alone never form a service row. */
function serviceHistoryItems(PDO $source, array $works): array
{
    $items = [];
    $serials = [];
    $orders = [];
    foreach ($works as $work) {
        $serial = (string)$work['tmp_sn'];
        if ($serial !== '') $serials[$serial] = true;
        elseif ((int)$work['order_id'] > 0) $orders[(int)$work['order_id']] = true;
    }
    if ($serials !== []) {
        $placeholders = implode(',', array_fill(0, count($serials), '?'));
        $query = $source->prepare('SELECT tmp_sn,id,product_id,buy_num,cart_info FROM eb_order_work_tmp '
            . 'WHERE tmp_sn IN (' . $placeholders . ') AND status=1 AND is_hx=1 ORDER BY id');
        $query->execute(array_keys($serials));
        foreach ($query as $row) $items['s:' . $row['tmp_sn']][] = $row;
    }
    if ($orders !== []) {
        $placeholders = implode(',', array_fill(0, count($orders), '?'));
        $query = $source->prepare('SELECT oid,id,product_id,product_type,cart_num,cart_info '
            . 'FROM eb_store_order_cart_info WHERE oid IN (' . $placeholders . ') ORDER BY id');
        $query->execute(array_keys($orders));
        foreach ($query as $row) $items['o:' . $row['oid']][] = $row;
    }
    return $items;
}

/** Prefer the purchase-time item name; distinguish performed projects from the card used. */
function serviceHistoryItemDetail(array $line): array
{
    $snapshot = json_decode((string)($line['cart_info'] ?? ''), true);
    $product = is_array($snapshot) ? (array)($snapshot['productInfo'] ?? []) : [];
    $name = trim((string)($product['store_name'] ?? ''));
    // Work-item root type can describe a cart line while productInfo carries
    // the sold product classification; use the latter to avoid listing a card
    // as if it were the performed project.
    $kind = (int)($line['product_type'] ?? $product['product_type'] ?? $snapshot['product_type'] ?? -1);
    $quantity = max(0, (int)($line['buy_num'] ?? $line['cart_num'] ?? 0));
    return [$kind, $name, $quantity];
}

/** One completed work order is one visit; its linked item snapshots supply truthful labels. */
function serviceHistoryProject(array $work, array $items): array
{
    $key = (string)$work['tmp_sn'] !== '' ? 's:' . $work['tmp_sn'] : 'o:' . $work['order_id'];
    $projects = [];
    $cards = [];
    $projectQuantity = 0;
    $cardQuantity = 0;
    foreach ($items[$key] ?? [] as $item) {
        [$kind, $name, $quantity] = serviceHistoryItemDetail($item);
        if ($kind === 4) {
            if ($name !== '') $cards[$name] = true;
            $cardQuantity += $quantity;
        } else {
            if ($name !== '') $projects[$name] = true;
            $projectQuantity += $quantity;
        }
    }
    $projectName = implode('、', array_keys($projects));
    $cardName = implode('、', array_keys($cards));
    // Some old work orders contain only card-type items. Their item names are
    // the most specific recorded service labels; do not repeat them as source.
    if ($projectName === '') {
        $projectName = $cardName;
        $cardName = '';
    }
    if ($projectName === '') $projectName = '服务项目未记录';
    return [mb_substr($projectName, 0, 255),
        mb_substr($cardName, 0, 255),
        max(1, $projectQuantity ?: $cardQuantity)];
}

/** One canonical field order makes source and imported target hashes comparable. */
function serviceHistoryCanonical(array $row): string
{
    $normalized = [
        'source_work_id' => (int)$row['source_work_id'],
        'source_member_id' => (int)$row['source_member_id'],
        'member_id' => (int)$row['member_id'],
        'source_store_id' => (int)$row['source_store_id'],
        'store_name_snapshot' => (string)$row['store_name_snapshot'],
        'project_name_snapshot' => (string)$row['project_name_snapshot'],
        'source_card_name_snapshot' => (string)$row['source_card_name_snapshot'],
        'quantity' => (int)$row['quantity'],
        'craftsman_name_snapshot' => (string)$row['craftsman_name_snapshot'],
        'business_date' => (string)$row['business_date'],
        'completed_at' => (int)$row['completed_at'],
    ];
    return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Status 4/6 means completed or awaiting review; a writeoff time is required. */
function serviceHistorySnapshot(PDO $source, array $memberMap, int $cutoffAt): array
{
    if ($cutoffAt <= 0) throw new RuntimeException('Invalid source cutoff');
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $rows = [];
        $hash = hash_init('sha256');
        $lastId = 0;
        do {
            $query = $source->prepare('SELECT w.id,w.uid,w.store_id,w.status,w.hx_time,w.do_time,'
                . 'w.work_time,w.tmp_sn,w.order_id,s.name store_name,u.nickname craftsman_name '
                . 'FROM eb_order_work w LEFT JOIN eb_system_store s ON s.id=w.store_id '
                . 'LEFT JOIN eb_user u ON u.uid=w.technician_id '
                . 'WHERE w.id>? AND w.status IN (4,6) AND w.hx_time>0 '
                . 'AND w.hx_time<=? AND w.uid>0 '
                . 'ORDER BY w.id LIMIT 250');
            $query->execute([$lastId, $cutoffAt]);
            $works = $query->fetchAll();
            if ($works === []) break;
            $items = serviceHistoryItems($source, $works);
            foreach ($works as $work) {
                $lastId = (int)$work['id'];
                $sourceUid = (int)$work['uid'];
                if (!isset($memberMap[$sourceUid])) {
                    throw new RuntimeException('Completed work has no migrated member: ' . $lastId);
                }
                [$project, $card, $quantity] = serviceHistoryProject($work, $items);
                // Old do_time is service start and work_time is minutes; hx_time
                // remains the fallback for older completed rows without a start.
                $completed = (int)$work['do_time'] > 0
                    ? (int)$work['do_time'] + max(0, (int)$work['work_time']) * 60
                    : (int)$work['hx_time'];
                if ($completed <= 0 || $quantity <= 0) {
                    throw new RuntimeException('Invalid completed work snapshot: ' . $lastId);
                }
                $row = [
                    'source_work_id' => $lastId,
                    'source_member_id' => $sourceUid,
                    'member_id' => $memberMap[$sourceUid],
                    'source_store_id' => max(0, (int)$work['store_id']),
                    'store_name_snapshot' => mb_substr((string)($work['store_name'] ?? ''), 0, 120),
                    'project_name_snapshot' => $project,
                    'source_card_name_snapshot' => $card,
                    'quantity' => $quantity,
                    'craftsman_name_snapshot' => mb_substr((string)($work['craftsman_name'] ?? ''), 0, 120),
                    'business_date' => date('Y-m-d', $completed),
                    'completed_at' => $completed,
                ];
                $canonical = serviceHistoryCanonical($row);
                $row['source_row_sha256'] = hash('sha256', $canonical);
                hash_update($hash, $canonical . "\n");
                $rows[] = $row;
            }
        } while (count($works) === 250);
        $source->exec('COMMIT');
        return [$rows, hash_final($hash)];
    } catch (Throwable $error) {
        $source->exec('ROLLBACK');
        throw $error;
    }
}

/** The approved scope stays fixed while the live source count freezes at apply. */
function serviceHistoryPlan(array $rows): void
{
    $plan = json_decode((string)file_get_contents(SERVICE_PLAN_PATH), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || ($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008'
        || ($plan['batch_key'] ?? '') !== SERVICE_BATCH_KEY
        || ($plan['source_cutoff_policy'] ?? '') !== 'consistent_snapshot_at_apply'
        || count($rows) < (int)($plan['minimum_source_rows'] ?? PHP_INT_MAX)
        || (int)($plan['required_sample_work_id'] ?? 0) !== 51080
        || (int)($plan['required_sample_member_id'] ?? 0) !== 421) {
        throw new RuntimeException('Source or scope differs from the accepted service history plan');
    }
    foreach ($rows as $row) {
        if ($row['source_work_id'] === 51080 && $row['member_id'] === 421) return;
    }
    throw new RuntimeException('PoPo completed work is absent from source snapshot');
}

/** Import is isolated and reruns only verify; no current 008 business fact is touched. */
function serviceHistoryApply(PDO $target, array $rows, string $hash, int $cutoffAt): void
{
    $releaseCommit = trim((string)getenv('MOHE_RELEASE_COMMIT'));
    if (!preg_match('/^[0-9a-f]{40}$/', $releaseCommit)) {
        throw new RuntimeException('MOHE_RELEASE_COMMIT must be the accepted local commit');
    }
    $lock = (int)$target->query("SELECT GET_LOCK('m008_morestore_service_history_r1',10)")->fetchColumn();
    if ($lock !== 1) throw new RuntimeException('Cannot acquire 008 service history import lock');
    try {
        $existing = (int)$target->query('SELECT COUNT(*) FROM ' . SERVICE_TABLE)->fetchColumn();
        $batch = $target->query('SELECT batch_key FROM '
            . SERVICE_BATCH_TABLE . " WHERE batch_key='" . SERVICE_BATCH_KEY . "'")->fetch();
        $upgrade = $target->query("SELECT code_version FROM eb_database_upgrade_log WHERE upgrade_key='"
            . SERVICE_UPGRADE_KEY . "'")->fetch();
        if ($batch) throw new RuntimeException('Batch already imported; use verify');
        if ($existing !== 0 || $upgrade) {
            throw new RuntimeException('Legacy service projection or upgrade marker is not empty');
        }
        $target->beginTransaction();
        try {
            $insert = $target->prepare('INSERT INTO ' . SERVICE_TABLE . ' ('
                . 'source_system,source_work_id,source_member_id,member_id,source_store_id,'
                . 'store_name_snapshot,project_name_snapshot,source_card_name_snapshot,quantity,'
                . 'craftsman_name_snapshot,business_date,completed_at,source_row_sha256,created_at) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            foreach ($rows as $row) {
                $insert->execute(['morestore', $row['source_work_id'], $row['source_member_id'],
                    $row['member_id'], $row['source_store_id'], $row['store_name_snapshot'],
                    $row['project_name_snapshot'], $row['source_card_name_snapshot'], $row['quantity'],
                    $row['craftsman_name_snapshot'], $row['business_date'], $row['completed_at'],
                    $row['source_row_sha256']]);
            }
            $insertBatch = $target->prepare('INSERT INTO ' . SERVICE_BATCH_TABLE
                . ' (batch_key,source_sha256,source_rows,target_rows,source_cutoff_at,created_at) '
                . 'VALUES (?,?,?,?,?,NOW())');
            $insertBatch->execute([SERVICE_BATCH_KEY, $hash, count($rows), count($rows), $cutoffAt]);
            $upgradeInsert = $target->prepare('INSERT INTO eb_database_upgrade_log '
                . '(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) '
                . 'VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
            $upgradeInsert->execute([SERVICE_UPGRADE_KEY, '008 渼约历史服务只读投影',
                '后端代码/database/upgrades/2026-10-13-008渼约历史服务读取/02-正式升级.sql',
                hash_file('sha256', __FILE__), $releaseCommit, 'Codex',
                json_encode(['source_sha256' => $hash, 'rows' => count($rows),
                    'source_cutoff_at' => $cutoffAt, 'target' => 'mohe_008'], JSON_THROW_ON_ERROR)]);
            $target->commit();
        } catch (Throwable $error) {
            $target->rollBack();
            throw $error;
        }
    } finally {
        $target->query("SELECT RELEASE_LOCK('m008_morestore_service_history_r1')");
    }
}

/** Rebuild the source digest from imported snapshots; no live old DB is needed. */
function serviceHistoryVerify(PDO $target): array
{
    $batch = $target->query('SELECT source_sha256,source_rows,target_rows,source_cutoff_at FROM '
        . SERVICE_BATCH_TABLE . " WHERE batch_key='" . SERVICE_BATCH_KEY . "'")->fetch();
    $count = (int)$target->query('SELECT COUNT(*) FROM ' . SERVICE_TABLE)->fetchColumn();
    $upgrade = $target->query("SELECT code_version FROM eb_database_upgrade_log WHERE upgrade_key='"
        . SERVICE_UPGRADE_KEY . "'")->fetch();
    if (!$batch || $count !== (int)$batch['source_rows']
        || (int)$batch['target_rows'] !== $count || (int)$batch['source_cutoff_at'] <= 0
        || !$upgrade || !preg_match('/^[0-9a-f]{40}$/', (string)$upgrade['code_version'])) {
        throw new RuntimeException('008 legacy service total or batch differs');
    }
    $query = $target->query('SELECT source_system,source_work_id,source_member_id,member_id,'
        . 'source_store_id,store_name_snapshot,project_name_snapshot,source_card_name_snapshot,'
        . 'quantity,craftsman_name_snapshot,business_date,completed_at,source_row_sha256 FROM '
        . SERVICE_TABLE . " WHERE source_system='morestore' ORDER BY source_work_id");
    $hash = hash_init('sha256');
    $seen = 0;
    foreach ($query as $actual) {
        $json = serviceHistoryCanonical($actual);
        if ($actual['source_system'] !== 'morestore'
            || hash('sha256', $json) !== $actual['source_row_sha256']) {
            throw new RuntimeException('008 legacy service row fingerprint differs');
        }
        hash_update($hash, $json . "\n");
        $seen++;
    }
    if ($seen !== $count || hash_final($hash) !== $batch['source_sha256']) {
        throw new RuntimeException('008 legacy service snapshot digest differs');
    }
    $popo = (int)$target->query(
        'SELECT COUNT(*) FROM ' . SERVICE_TABLE . ' WHERE source_work_id=51080 AND member_id=421'
    )->fetchColumn();
    if ($popo !== 1) throw new RuntimeException('PoPo historical completed work is absent');
    return ['source_sha256' => $batch['source_sha256'], 'rows' => $count,
        'source_cutoff_at' => (int)$batch['source_cutoff_at'], 'popo_work_51080' => $popo];
}

/** The check command is read-only and can run before product acceptance. */
function serviceHistoryMain(array $argv): void
{
    $mode = (string)($argv[1] ?? '');
    if (!in_array($mode, ['preflight', 'check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Usage: preflight|check|apply|verify');
    }
    $target = serviceHistoryDb(SERVICE_TARGET_ENV, 'mohe_008', 3307);
    if ($mode === 'preflight') {
        // Schema presence and upgrade marker are checked without exposing
        // connection secrets or changing any target rows.
        $tables = [];
        $exists = $target->prepare('SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name=?');
        foreach ([SERVICE_TABLE, SERVICE_BATCH_TABLE] as $table) {
            $exists->execute([$table]);
            $tables[$table] = (int)$exists->fetchColumn();
        }
        $marker = $target->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
        $marker->execute([SERVICE_UPGRADE_KEY]);
        echo json_encode(['target' => 'mohe_008:3307', 'tables' => $tables,
            'upgrade_marker' => (int)$marker->fetchColumn()]), PHP_EOL;
        return;
    }
    if ($mode === 'verify') {
        echo json_encode(serviceHistoryVerify($target)), PHP_EOL;
        return;
    }
    if ($mode === 'apply') {
        $existing = $target->query('SELECT batch_key FROM ' . SERVICE_BATCH_TABLE
            . " WHERE batch_key='" . SERVICE_BATCH_KEY . "'")->fetch();
        if ($existing) {
            echo json_encode(serviceHistoryVerify($target)), PHP_EOL;
            return;
        }
    }
    $cutoffAt = time();
    $source = serviceHistoryDb(SERVICE_SOURCE_ENV, 'morestore', 3306);
    [$rows, $hash] = serviceHistorySnapshot($source, serviceHistoryMembers($target), $cutoffAt);
    if ($mode === 'check') {
        $popo = array_values(array_filter($rows, static function (array $row): bool {
            return $row['source_work_id'] === 51080 && $row['member_id'] === 421;
        }));
        $unnamed = count(array_filter($rows, static function (array $row): bool {
            return $row['project_name_snapshot'] === '服务项目未记录';
        }));
        $future = count(array_filter($rows, static function (array $row) use ($cutoffAt): bool {
            return $row['completed_at'] > $cutoffAt;
        }));
        echo json_encode(['source_sha256' => $hash, 'rows' => count($rows),
            'preview_cutoff_at' => $cutoffAt,
            'unnamed_project_rows' => $unnamed,
            'future_completed_rows' => $future,
            'popo_work_51080' => count($popo),
            'popo_project' => $popo[0]['project_name_snapshot'] ?? null], JSON_UNESCAPED_UNICODE), PHP_EOL;
        return;
    }
    serviceHistoryPlan($rows);
    serviceHistoryApply($target, $rows, $hash, $cutoffAt);
    echo json_encode(serviceHistoryVerify($target)), PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        serviceHistoryMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

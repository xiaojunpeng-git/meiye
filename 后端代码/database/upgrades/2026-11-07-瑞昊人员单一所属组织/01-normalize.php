<?php
declare(strict_types=1);

/**
 * 瑞昊历史多所属组织归一化。
 *
 * 当前所属关系以每位员工最后创建的有效 organization_employee 为准；
 * 其他关系只软关闭。组织授权、门店任职和岗位不在本脚本写入范围。
 */
const TARGET_ROOT = '/www/wwwroot/rh.cc3798.com';
const TARGET_DATABASE = 'ruihao';
const TARGET_PORT = 3306;
const LOCK_NAME = 'codex_rh_single_organization_membership_20261107';

function stop(string $message): void
{
    throw new RuntimeException($message);
}

function arg(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $value) {
        if (strpos($value, $prefix) === 0) {
            return substr($value, strlen($prefix));
        }
    }
    return null;
}

function queryRows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function scalarValue(PDO $pdo, string $sql): string
{
    $result = $pdo->query($sql)->fetchColumn();
    return $result === false ? '' : (string)$result;
}

function connectTarget(): PDO
{
    if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== TARGET_ROOT) {
        stop('TARGET_ROOT_MISMATCH');
    }
    require TARGET_ROOT . '/vendor/autoload.php';
    (new think\App(TARGET_ROOT . '/'))->initialize();
    $config = config('database.connections.mysql');
    if (($config['database'] ?? '') !== TARGET_DATABASE
        || (int)($config['hostport'] ?? 0) !== TARGET_PORT
        || !in_array($config['hostname'] ?? '', ['127.0.0.1', 'localhost'], true)) {
        stop('DATABASE_CONFIG_MISMATCH');
    }
    $pdo = new PDO(
        'mysql:host=' . $config['hostname'] . ';port=' . TARGET_PORT . ';dbname=' . TARGET_DATABASE . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if (scalarValue($pdo, 'SELECT DATABASE()') !== TARGET_DATABASE || (int)scalarValue($pdo, 'SELECT @@port') !== TARGET_PORT) {
        stop('CONNECTED_INSTANCE_MISMATCH');
    }
    return $pdo;
}

function scope(PDO $pdo): array
{
    $rows = queryRows($pdo, 'SELECT oe.id,oe.employee_id,oe.org_id,COALESCE(o.name,CONCAT(\'#\',oe.org_id)) org_name,
            IF(o.id IS NOT NULL AND o.is_del=0,1,0) org_valid,oe.status,oe.is_del,
            oe.source,oe.add_time,oe.update_time,e.name employee_name,e.phone
        FROM eb_organization_employee oe
        JOIN eb_employee e ON e.id=oe.employee_id
        LEFT JOIN eb_organization o ON o.id=oe.org_id
        JOIN (
            SELECT employee_id,MAX(id) keep_id,COUNT(*) relation_count
            FROM eb_organization_employee
            WHERE is_del=0 AND status=1
            GROUP BY employee_id HAVING COUNT(*)>1
        ) duplicate ON duplicate.employee_id=oe.employee_id
        WHERE oe.is_del=0 AND oe.status=1 AND e.is_del=0 AND e.status=1
        ORDER BY oe.employee_id,oe.id');
    $people = [];
    foreach ($rows as $row) {
        $employeeId = (int)$row['employee_id'];
        $people[$employeeId]['employee_id'] = $employeeId;
        $people[$employeeId]['employee_name'] = (string)$row['employee_name'];
        $people[$employeeId]['phone'] = (string)$row['phone'];
        $people[$employeeId]['relations'][] = [
            'id' => (int)$row['id'],
            'org_id' => (int)$row['org_id'],
            'org_name' => (string)$row['org_name'],
            'org_valid' => (int)$row['org_valid'] === 1,
            'keep' => false,
        ];
    }
    foreach ($people as &$person) {
        // 已停用/已删除组织的残留关系一律关闭；当前归属取最后创建的有效组织。
        $keepIndex = null;
        foreach ($person['relations'] as $index => $relation) {
            if ($relation['org_valid']) {
                $keepIndex = $index;
            }
        }
        if ($keepIndex === null) {
            stop('VALID_ORGANIZATION_MISSING:' . $person['employee_id']);
        }
        $person['relations'][$keepIndex]['keep'] = true;
    }
    unset($person);
    $people = array_values($people);
    $fingerprint = hash('sha256', json_encode($people, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    return [
        'people_count' => count($people),
        'relation_count' => array_sum(array_map(static fn(array $person): int => count($person['relations']), $people)),
        'close_count' => array_sum(array_map(static fn(array $person): int => count($person['relations']) - 1, $people)),
        'people' => $people,
        'fingerprint' => $fingerprint,
    ];
}

/** 软关闭旧所属关系并留下可追溯日志，不触碰授权或门店任职表。 */
function normalize(PDO $pdo, array $plan): int
{
    $now = time();
    $closed = 0;
    $update = $pdo->prepare('UPDATE eb_organization_employee SET status=0,is_del=1,update_time=? WHERE id=? AND status=1 AND is_del=0');
    $log = $pdo->prepare('INSERT INTO eb_employee_change_log
        (employee_id,action,target_type,target_id,source,before_data,after_data,reason,operator_type,operator_id,operator_name,operator_ip,request_id,add_time)
        VALUES (?,\'organization_employee_normalize\',\'organization_employee\',?,\'data_repair\',?,?,\'归一化当前所属组织\',\'system\',0,\'Codex\',\'\',\'rh-single-org-20261107\',?)');
    foreach ($plan['people'] as $person) {
        $keep = null;
        foreach ($person['relations'] as $relation) {
            if ($relation['keep']) {
                $keep = $relation;
                break;
            }
        }
        if (!$keep) {
            stop('KEEP_RELATION_MISSING:' . $person['employee_id']);
        }
        foreach ($person['relations'] as $relation) {
            if ($relation['keep']) {
                continue;
            }
            $update->execute([$now, $relation['id']]);
            if ($update->rowCount() !== 1) {
                stop('RELATION_CHANGED:' . $relation['id']);
            }
            $before = json_encode($relation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $after = json_encode([
                'status' => 0,
                'is_del' => 1,
                'replacement_org_id' => $keep['org_id'],
                'replacement_relation_id' => $keep['id'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $log->execute([$person['employee_id'], $relation['id'], $before, $after, $now]);
            $closed++;
        }
    }
    return $closed;
}

$mode = $argv[1] ?? 'audit';
if (!in_array($mode, ['audit', 'simulate', 'apply'], true)) {
    stop('USAGE: php 01-normalize.php audit|simulate|apply');
}
$pdo = connectTarget();
$locked = scalarValue($pdo, "SELECT GET_LOCK('" . LOCK_NAME . "',0)") === '1';
if (!$locked) {
    stop('NORMALIZE_LOCK_BUSY');
}
try {
    $plan = scope($pdo);
    if ($mode === 'audit') {
        echo json_encode(['mode' => 'audit', 'database' => TARGET_DATABASE, 'plan' => $plan], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    $expected = (string)arg($argv, 'expected-fingerprint');
    if ($expected === '' || !hash_equals($plan['fingerprint'], $expected)) {
        stop('FINGERPRINT_MISMATCH:' . $plan['fingerprint']);
    }
    if ($mode === 'apply') {
        $backup = (string)arg($argv, 'backup');
        $backupSha = strtolower((string)arg($argv, 'backup-sha256'));
        if (!preg_match('#^/www/backups/rh/.+/database-before\.sql\.gz$#D', $backup)
            || !is_file($backup) || $backupSha === '' || !hash_equals(hash_file('sha256', $backup), $backupSha)) {
            stop('INDEPENDENT_BACKUP_REQUIRED');
        }
    }
    $pdo->beginTransaction();
    try {
        $inside = scope($pdo);
        if (!hash_equals($plan['fingerprint'], $inside['fingerprint'])) {
            stop('TRANSACTION_SCOPE_CHANGED:' . $inside['fingerprint']);
        }
        $closed = normalize($pdo, $inside);
        $remaining = scope($pdo);
        if ($remaining['people_count'] !== 0 || $closed !== $inside['close_count']) {
            stop('POST_NORMALIZE_CHECK_FAILED');
        }
        if ($mode === 'simulate') {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    echo json_encode([
        'mode' => $mode,
        'database' => TARGET_DATABASE,
        'source_fingerprint' => $plan['fingerprint'],
        'closed_relations' => $closed,
        'remaining_multi_membership_people' => 0,
        'rolled_back' => $mode === 'simulate',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('" . LOCK_NAME . "')");
}

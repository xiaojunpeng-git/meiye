<?php
/**
 * Classify the reviewed 008 employee population as internal staff.
 *
 * eb_employee is the authority. The operation advances type versions and
 * records one audit row per employee; it does not edit roles, store assignment,
 * login permissions, payroll flags or historical performance snapshots.
 */

declare(strict_types=1);

const TYPE008_ENV = '/www/wwwroot/008.cc3798.com/.env';
const TYPE008_DB = 'mohe_008';
const TYPE008_PORT = 3307;
const TYPE008_COUNT = 761;
const TYPE008_ID_SET_SHA256 = '5c532b12286f20733a36d30471b6a6a51e52e8cd1140c2f5a475667d6fb37b7d';
const TYPE008_UPGRADE = '20261013-008-employee-type-internal';
const TYPE008_AUDIT_ACTION = 'employee_employment_type_migration_internal';

/** Open only the independent 008 database and verify the server endpoint. */
function type008Connect(): PDO
{
    $env = parse_ini_file(TYPE008_ENV);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== TYPE008_DB
        || (int)($env['HOSTPORT'] ?? 0) !== TYPE008_PORT) {
        throw new RuntimeException('008 connection configuration changed');
    }
    $pdo = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $env['DATABASE'] . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query('SELECT DATABASE() db, @@port port')->fetch(PDO::FETCH_ASSOC);
    if (($identity['db'] ?? '') !== TYPE008_DB || (int)($identity['port'] ?? 0) !== TYPE008_PORT) {
        throw new RuntimeException('Refusing any database other than mohe_008:3307');
    }
    return $pdo;
}

/** Freeze the reviewed employee IDs and enforce the type/version invariant. */
function type008AssertPopulation(PDO $pdo, string $phase, bool $lock = false): void
{
    $sql = 'SELECT id,status,is_del,employment_type_code,employment_type_version '
        . 'FROM eb_employee ORDER BY id' . ($lock ? ' FOR UPDATE' : '');
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== TYPE008_COUNT) {
        throw new RuntimeException('008 employee count changed from reviewed population');
    }
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
        if ((int)$row['status'] !== 1 || (int)$row['is_del'] !== 0) {
            throw new RuntimeException('An 008 employee is inactive or deleted');
        }
        $code = $row['employment_type_code'];
        $version = (int)$row['employment_type_version'];
        if ($phase === 'before' ? ($code !== null || $version !== 0)
            : ($code !== 'internal' || $version !== 1)) {
            throw new RuntimeException('008 employee type/version differs from reviewed state');
        }
    }
    if (!hash_equals(TYPE008_ID_SET_SHA256, hash('sha256', implode(',', $ids)))) {
        throw new RuntimeException('008 employee ID set changed');
    }
}

/** A matching audit must exist for every employee, exactly once. */
function type008AssertApplied(PDO $pdo): array
{
    type008AssertPopulation($pdo, 'after');
    $statement = $pdo->prepare('SELECT sql_checksum,code_version,result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $statement->execute([TYPE008_UPGRADE]);
    $markers = $statement->fetchAll(PDO::FETCH_ASSOC);
    $audit = $pdo->prepare('SELECT COUNT(*) FROM eb_employee_change_log '
        . 'WHERE action=? AND request_id=? AND target_type=?');
    $audit->execute([TYPE008_AUDIT_ACTION, TYPE008_UPGRADE, 'employee_employment_type']);
    if (count($markers) !== 1 || (int)$audit->fetchColumn() !== TYPE008_COUNT) {
        throw new RuntimeException('008 type migration marker or per-person audit incomplete');
    }
    $meta = json_decode((string)$markers[0]['result_note'], true);
    if (($meta['count'] ?? 0) !== TYPE008_COUNT
        || ($meta['id_set_sha256'] ?? '') !== TYPE008_ID_SET_SHA256
        || ($markers[0]['sql_checksum'] ?? '') !== hash_file('sha256', __FILE__)) {
        throw new RuntimeException('008 type migration audit digest changed');
    }
    return $markers[0];
}

/** Single transaction preserves the employee type and its history together. */
function type008Apply(PDO $pdo, string $scriptSha, string $codeVersion): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $scriptSha)
        || !hash_equals($scriptSha, (string)hash_file('sha256', __FILE__))
        || !preg_match('/^[a-f0-9]{40}$/', $codeVersion)) {
        throw new RuntimeException('The reviewed script SHA or local commit is missing');
    }
    $lock = $pdo->query("SELECT GET_LOCK('008_employee_type_internal',15) AS got")->fetch(PDO::FETCH_ASSOC);
    if ((int)($lock['got'] ?? 0) !== 1) {
        throw new RuntimeException('008 employee-type write lock unavailable');
    }
    try {
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
            $check->execute([TYPE008_UPGRADE]);
            if ((int)$check->fetchColumn() !== 0) {
                throw new RuntimeException('008 employee-type upgrade already recorded');
            }
            type008AssertPopulation($pdo, 'before', true);
            $now = time();

            // Each row carries its own before/after version. This preserves the
            // correction trail without rewriting any prior sales or labor fact.
            $audit = $pdo->prepare("INSERT INTO eb_employee_change_log "
                . "(employee_id,action,target_type,target_id,source,before_data,after_data,reason,"
                . "operator_type,operator_id,operator_name,operator_ip,request_id,add_time) "
                . "SELECT id, ?, 'employee_employment_type', id, 'migration', "
                . "'{\"employment_type_code\":null,\"employment_type_version\":0}', "
                . "'{\"employment_type_code\":\"internal\",\"employment_type_version\":1}', "
                . "'008 全部现有员工归类为内部员工', 'system', 0, 'Codex', '', ?, ? "
                . "FROM eb_employee WHERE status=1 AND is_del=0 "
                . "AND employment_type_code IS NULL AND employment_type_version=0");
            $audit->execute([TYPE008_AUDIT_ACTION, TYPE008_UPGRADE, $now]);
            if ($audit->rowCount() !== TYPE008_COUNT) {
                throw new RuntimeException('Per-person type audit count mismatch');
            }
            $update = $pdo->prepare("UPDATE eb_employee SET employment_type_code='internal', "
                . 'employment_type_version=1,update_time=? '
                . 'WHERE status=1 AND is_del=0 AND employment_type_code IS NULL AND employment_type_version=0');
            $update->execute([$now]);
            if ($update->rowCount() !== TYPE008_COUNT) {
                throw new RuntimeException('Employee type update count mismatch');
            }

            // The product manager explicitly chose no full-database backup for
            // this initial 008 data setup. The audit rows retain each old value;
            // failed writes still roll back atomically before commit.
            $meta = json_encode(['count' => TYPE008_COUNT, 'id_set_sha256' => TYPE008_ID_SET_SHA256,
                'backup_policy' => 'explicitly_waived_by_product_manager'], JSON_UNESCAPED_UNICODE);
            $marker = $pdo->prepare('INSERT INTO eb_database_upgrade_log '
                . '(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) '
                . 'VALUES (?,?,?,?,?,?,?,?,?)');
            $marker->execute([TYPE008_UPGRADE, '008 员工人员类型统一内部员工',
                '后端代码/database/upgrades/2026-10-13-008员工人员类型统一内部员工/01-执行.php',
                $scriptSha, $codeVersion, date('Y-m-d H:i:s', $now), 'Codex', $meta,
                date('Y-m-d H:i:s', $now)]);
            type008AssertPopulation($pdo, 'after');
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('008_employee_type_internal')");
    }
}

try {
    $command = (string)($argv[1] ?? '');
    if (!in_array($command, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Usage: php 01-执行.php check|apply|verify [script-sha256 commit]');
    }
    $pdo = type008Connect();
    if ($command === 'check') {
        type008AssertPopulation($pdo, 'before');
        $marker = $pdo->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
        $marker->execute([TYPE008_UPGRADE]);
        if ((int)$marker->fetchColumn() !== 0) {
            throw new RuntimeException('008 employee-type upgrade already recorded');
        }
        echo json_encode(['ok' => true, 'mode' => 'check', 'to_internal' => TYPE008_COUNT,
            'id_set_sha256' => TYPE008_ID_SET_SHA256]) . PHP_EOL;
    } elseif ($command === 'verify') {
        $marker = type008AssertApplied($pdo);
        echo json_encode(['ok' => true, 'mode' => 'verify', 'internal' => TYPE008_COUNT,
            'code_version' => $marker['code_version']]) . PHP_EOL;
    } else {
        type008Apply($pdo, (string)($argv[2] ?? ''), (string)($argv[3] ?? ''));
        type008AssertApplied($pdo);
        echo json_encode(['ok' => true, 'mode' => 'apply', 'changed' => TYPE008_COUNT]) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, '008 employee-type migration stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

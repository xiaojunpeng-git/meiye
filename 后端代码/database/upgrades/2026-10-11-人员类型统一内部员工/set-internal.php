<?php

declare(strict_types=1);

// One-time, per-instance personnel correction. Existing business fact snapshots are never rewritten.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$options = getopt('', ['database:', 'instance:', 'expect:', 'apply']);
$expectedDatabase = (string)($options['database'] ?? '');
$instance = (string)($options['instance'] ?? '');
$expectedFingerprint = (string)($options['expect'] ?? '');
$apply = array_key_exists('apply', $options);
if ($expectedDatabase === '' || !in_array($instance, ['local', 'rh'], true) || ($apply && $expectedFingerprint === '')) {
    fwrite(STDERR, "Usage: php set-internal.php --database=DB --instance=local|rh [--expect=SHA256 --apply]\n");
    exit(1);
}

$appRoot = rtrim((string)(getenv('MOHE_APP_ROOT') ?: dirname(__DIR__, 3)), '/');
require $appRoot . '/vendor/autoload.php';
(new \think\App($appRoot . '/'))->initialize();

use think\facade\Db;

/** The preview fingerprint binds approval to the exact pre-change rows and versions. */
function correctionFingerprint(array $rows): string
{
    $snapshot = array_map(static function (array $row): array {
        return [(int)$row['id'], $row['employment_type_code'], (int)$row['employment_type_version']];
    }, $rows);
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Deleted personnel and already-internal personnel are outside this correction. */
function correctionTargets(bool $lock): array
{
    $query = Db::name('employee')->where('is_del', 0)
        ->where(function ($query): void {
            $query->whereNull('employment_type_code')->whereOr('employment_type_code', '<>', 'internal');
        })->field('id,employment_type_code,employment_type_version')->order('id', 'asc');
    return ($lock ? $query->lock(true) : $query)->select()->toArray();
}

function assertValidTargets(array $rows): void
{
    foreach ($rows as $row) {
        $code = $row['employment_type_code'];
        $version = (int)$row['employment_type_version'];
        if (!(($code === null && $version === 0)
            || (in_array($code, ['partner', 'outsourced'], true) && $version > 0 && $version < PHP_INT_MAX))) {
            throw new RuntimeException('Unexpected personnel type state for employee ' . (int)$row['id']);
        }
    }
}

try {
    $database = (string)(Db::query('SELECT DATABASE() AS db_name')[0]['db_name'] ?? '');
    if ($database !== $expectedDatabase) {
        throw new RuntimeException('Database mismatch: expected ' . $expectedDatabase . ', got ' . $database);
    }
    $requestId = 'r8-personnel-internal-20261011-' . $instance;
    $rows = correctionTargets(false);
    assertValidTargets($rows);
    $fingerprint = correctionFingerprint($rows);
    $types = array_count_values(array_map(static function (array $row): string {
        return $row['employment_type_code'] ?? '<NULL>';
    }, $rows));
    echo json_encode(['database' => $database, 'instance' => $instance, 'count' => count($rows),
        'types' => $types, 'fingerprint' => $fingerprint, 'apply' => $apply], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    if (!$apply) {
        exit(0);
    }
    if (!hash_equals($expectedFingerprint, $fingerprint)) {
        throw new RuntimeException('Preview fingerprint changed; rerun dry run');
    }

    Db::startTrans();
    try {
        $lockedRows = correctionTargets(true);
        assertValidTargets($lockedRows);
        if (!hash_equals($expectedFingerprint, correctionFingerprint($lockedRows))) {
            throw new RuntimeException('Personnel types changed while acquiring locks');
        }
        if ($lockedRows && Db::name('employee_change_log')->where('request_id', $requestId)->count() > 0) {
            throw new RuntimeException('This instance request id already has audit entries');
        }
        foreach ($lockedRows as $row) {
            $id = (int)$row['id'];
            $oldCode = $row['employment_type_code'];
            $oldVersion = (int)$row['employment_type_version'];
            $query = Db::name('employee')->where('id', $id)->where('is_del', 0)
                ->where('employment_type_version', $oldVersion);
            $oldCode === null ? $query->whereNull('employment_type_code') : $query->where('employment_type_code', $oldCode);
            if ((int)$query->update(['employment_type_code' => 'internal', 'employment_type_version' => $oldVersion + 1]) !== 1) {
                throw new RuntimeException('Concurrent personnel change for employee ' . $id);
            }
            // Keep the authoritative before/after pair in the same transaction for targeted rollback.
            Db::name('employee_change_log')->insert([
                'employee_id' => $id,
                'action' => 'employee_employment_type_save',
                'target_type' => 'employee_employment_type',
                'target_id' => $id,
                'source' => 'admin',
                'before_data' => json_encode(['employment_type_code' => $oldCode, 'employment_type_version' => $oldVersion]),
                'after_data' => json_encode(['employment_type_code' => 'internal', 'employment_type_version' => $oldVersion + 1]),
                'reason' => '产品经理确认未删除人员统一为内部员工',
                'operator_type' => 'system',
                'operator_id' => 0,
                'operator_name' => 'Codex (PM approved)',
                'operator_ip' => '',
                'request_id' => $requestId,
                'add_time' => time(),
            ]);
        }
        if (correctionTargets(false)) {
            throw new RuntimeException('Non-internal personnel remain after update');
        }
        Db::commit();
        echo json_encode(['updated' => count($lockedRows), 'request_id' => $requestId], JSON_UNESCAPED_UNICODE), PHP_EOL;
    } catch (Throwable $exception) {
        Db::rollback();
        throw $exception;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

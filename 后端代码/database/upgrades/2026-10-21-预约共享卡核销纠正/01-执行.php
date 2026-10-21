<?php
declare(strict_types=1);

/**
 * Authorized RH historical correction. Plan writes only a protected backup;
 * apply requires its fingerprint, fixed source commit and an independent backup.
 * The shared upgrade log is the once-only receipt, written in the same transaction.
 */
use think\facade\Db;
use app\services\cashier\v3\reservation\CashierV3ReservationSharedPoolRepairServices;

$o = getopt('', ['mode:', 'holder-id:', 'expected:', 'backup:', 'code-version:']);
$mode = $o['mode'] ?? 'plan';
if (!in_array($mode, ['plan', 'apply', 'verify'], true)) throw new InvalidArgumentException('mode');
$root = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
if (config('database.connections.mysql.database') !== 'ruihao') throw new LogicException('Explicit RH repair only');
$key = '20261021-024-reservation-shared-pool';
$holderId = (int)($o['holder-id'] ?? 0);
$backup = (string)($o['backup'] ?? '');
if ($holderId <= 0 || !is_dir($backup) || strpos($backup, '/www/backups/') !== 0) throw new LogicException('Protected independent backup required');
$file = $backup . '/data-before.json';
$service = new CashierV3ReservationSharedPoolRepairServices();
Db::startTrans();
try {
    $receipt = Db::name('database_upgrade_log')->where('upgrade_key', $key)->lock(true)->find();
    if ($receipt) {
        $result = json_decode((string)$receipt['result_note'], true);
        if ((int)($result['holderId'] ?? 0) !== $holderId) throw new LogicException('Receipt target mismatch');
        $state = Db::name('cashier_v3_card_rule_state')->where('card_holder_id', $holderId)->lock(true)->find();
        if ((int)$state['shared_remaining_times'] !== 0 || $state['status'] !== 'exhausted') throw new LogicException('Corrected pool changed; reconcile followups');
        $facts = Db::name('cashier_v3_performance_fact')->where('command_idempotency_key', $key)->lock(true)->select()->toArray();
        if (count($facts) !== 2 || array_sum(array_column($facts, 'amount_cents')) !== (int)$result['deltaCents']) throw new LogicException('Correction receipt/facts mismatch');
        Db::rollback();
        echo json_encode(['status' => 'verified', 'replayed' => true, 'result' => $result], JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    if ($mode === 'verify') throw new LogicException('Repair not applied');
    $plan = $service->planInTx($holderId);
    if ($mode === 'plan') {
        if (file_exists($file)) throw new LogicException('Backup already exists; never overwrite');
        $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents($file, $json, LOCK_EX) === false || !chmod($file, 0600)) throw new LogicException('Backup failed');
        Db::rollback();
        echo json_encode(['status' => 'planned', 'holderId' => $holderId, 'fingerprint' => $plan['fingerprint'],
            'targetCents' => $plan['target_cents'], 'backupSha256' => hash_file('sha256', $file)], JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    $saved = json_decode((string)file_get_contents($file), true);
    $expected = (string)($o['expected'] ?? '');
    $version = (string)($o['code-version'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/D', $version) || strlen($expected) !== 64
        || !hash_equals($expected, (string)($saved['fingerprint'] ?? ''))
        || !hash_equals($expected, $plan['fingerprint'])) throw new LogicException('Fixed repair plan changed');
    $result = $service->applyInTx($holderId, $expected, $key, time());
    $now = date('Y-m-d H:i:s');
    Db::name('database_upgrade_log')->insert([
        'upgrade_key' => $key, 'title' => '预约共享次数及历史核销金额纠正', 'task_file' => basename(__DIR__) . '/01-执行.php',
        'sql_checksum' => hash_file('sha256', __FILE__), 'code_version' => $version,
        'executed_at' => $now, 'executed_by' => 'Codex / 产品经理授权',
        'result_note' => json_encode($result, JSON_UNESCAPED_UNICODE), 'created_at' => $now,
    ]);
    Db::commit();
    echo json_encode(['status' => 'committed', 'result' => $result], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) { Db::rollback(); throw $e; }

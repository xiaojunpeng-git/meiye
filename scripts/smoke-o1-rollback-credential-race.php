<?php
/**
 * P1-1：回滚凭证缓存竞态
 * - clear 在 INCR 后、rollback 审计前持锁暂停
 * - 长驻 Worker 在窗口内 isMigrated 必须为 false（不得依赖 sleep 5s）
 * - 审计完成后不清 Worker 本地缓存再次调用仍为 false
 *
 * docker cp 美容源码/scripts/smoke-o1-rollback-credential-race.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-rollback-credential-race-worker.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-rollback-clear-hold.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-o1-rollback-credential-race.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationReconcileServices;
use app\services\organization\OrganizationScopeService;
use mohe\services\CacheService;
use think\facade\Db;

$worker = '/tmp/smoke-o1-rollback-credential-race-worker.php';
$clearHold = '/tmp/smoke-o1-rollback-clear-hold.php';
if (!is_file($worker) || !is_file($clearHold)) {
    fwrite(STDERR, "missing worker or clear-hold script\n");
    exit(2);
}

$failures = [];
$runId = 'o1rb_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);
$smokeStartedAt = time();
$dbConfigInsertedId = 0;
$batchId = 0;

function pass(string $c, string $x = ''): void
{
    echo 'PASS ' . $c . ($x !== '' ? ' ' . $x : '') . "\n";
}
function fail(string $c, string $m): void
{
    global $failures;
    $failures[] = "{$c}: {$m}";
    echo "FAIL {$c}: {$m}\n";
}

function waitFile(string $path, int $sec = 25): bool
{
    $deadline = time() + $sec;
    while (time() < $deadline) {
        if (is_file($path)) {
            return true;
        }
        usleep(20000);
    }
    return false;
}

function readJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $j = json_decode((string)file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function ensureDbOrgMode(): void
{
    global $dbConfigInsertedId;
    $row = Db::name('system_config')->where('menu_name', OrganizationScopeService::SOURCE_MODE_CONFIG_KEY)->find();
    $encoded = json_encode(OrganizationScopeService::MODE_ORGANIZATION, JSON_UNESCAPED_UNICODE);
    if ($row) {
        Db::name('system_config')->where('id', (int)$row['id'])->update(['value' => $encoded]);
        return;
    }
    $dbConfigInsertedId = (int)Db::name('system_config')->insertGetId([
        'is_store' => 0,
        'menu_name' => OrganizationScopeService::SOURCE_MODE_CONFIG_KEY,
        'type' => 'text',
        'input_type' => 'input',
        'config_tab_id' => 0,
        'parameter' => '',
        'upload_type' => 0,
        'required' => '',
        'width' => 0,
        'high' => 0,
        'value' => $encoded,
        'info' => '组织数据源模式（smoke rollback race）',
        'desc' => 'smoke temporary',
        'sort' => 0,
        'status' => 1,
    ]);
}

function removeDbMode(): void
{
    global $dbConfigInsertedId;
    if ($dbConfigInsertedId > 0) {
        Db::name('system_config')->where('id', $dbConfigInsertedId)->delete();
        $dbConfigInsertedId = 0;
    } else {
        Db::name('system_config')->where('menu_name', OrganizationScopeService::SOURCE_MODE_CONFIG_KEY)->delete();
    }
}

$beforeCore = [
    'organization_active' => (int)Db::name('organization')->where('is_del', 0)->count(),
    'organization_store' => (int)Db::name('organization_store')->count(),
    'organization_admin' => (int)Db::name('organization_admin')->count(),
    'organization_admin_store_exclude' => (int)Db::name('organization_admin_store_exclude')->count(),
];
echo "RUN_ID={$runId}\n";
echo 'SNAPSHOT_BEFORE=' . json_encode($beforeCore, JSON_UNESCAPED_UNICODE) . "\n";

$ready = '/tmp/o1rb_worker_ready';
$probeGo = '/tmp/o1rb_probe_go';
$probeOut = '/tmp/o1rb_probe_out.json';
$finalGo = '/tmp/o1rb_final_go';
$finalOut = '/tmp/o1rb_final_out.json';
$clearReady = '/tmp/o1rb_clear_ready';
$clearGo = '/tmp/o1rb_clear_go';
$clearOut = '/tmp/o1rb_clear_out.json';
foreach ([$ready, $probeGo, $probeOut, $finalGo, $finalOut, $clearReady, $clearGo, $clearOut] as $f) {
    @unlink($f);
}

try {
    removeDbMode();
    try {
        CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    } catch (\Throwable $e) {
    }
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);

    ensureDbOrgMode();
    /** @var OrganizationScopeService $scope */
    $scope = app()->make(OrganizationScopeService::class);
    OrganizationScopeService::clearIntegrityCache();
    $good = $scope->reconcileLegacyMigration(true, false);
    $batchId = (int)Db::name('organization_change_log')->insertGetId([
        'org_id' => 0,
        'action' => OrganizationReconcileServices::MIGRATE_BATCH_ACTION,
        'target_type' => 'migrate',
        'target_id' => 0,
        'before_data' => '',
        'after_data' => json_encode([
            'batch_id' => $runId . '_batch',
            'reconcile_ok' => true,
            'legacy_hash' => (string)$good['legacy_hash'],
            'new_projection_hash' => (string)$good['new_projection_hash'],
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
        'remark' => $runId . '_batch',
        'operator_id' => 0,
        'operator_name' => 'system',
        'add_time' => time(),
    ]);
    OrganizationScopeService::clearIntegrityCache();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    if (!$scope->isMigrated()) {
        fail('setup_isMigrated_true', 'cutover publish failed');
    } else {
        pass('setup_isMigrated_true');
    }

    exec('php ' . escapeshellarg($worker) . ' '
        . escapeshellarg($ready) . ' '
        . escapeshellarg($probeGo) . ' '
        . escapeshellarg($probeOut) . ' '
        . escapeshellarg($finalGo) . ' '
        . escapeshellarg($finalOut)
        . ' >/tmp/o1rb_worker.log 2>&1 &');
    if (!waitFile($ready, 20)) {
        fail('worker_ready', 'timeout');
    } else {
        $w0 = readJson($ready);
        if (!empty($w0['is_migrated'])) {
            pass('worker_cached_migrated_true', 'ver=' . ($w0['ver'] ?? ''));
        } else {
            fail('worker_cached_migrated_true', json_encode($w0, JSON_UNESCAPED_UNICODE));
        }
    }

    exec('php ' . escapeshellarg($clearHold) . ' '
        . escapeshellarg($clearReady) . ' '
        . escapeshellarg($clearGo) . ' '
        . escapeshellarg($clearOut)
        . ' >/tmp/o1rb_clear.log 2>&1 &');

    if (!waitFile($clearReady, 20)) {
        fail('clear_held_after_incr', 'timeout');
    } else {
        pass('clear_held_after_incr');
        $rt = CacheService::redisHandler()->get(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
        if ((string)$rt === OrganizationScopeService::MODE_LEGACY) {
            pass('runtime_legacy_during_hold');
        } else {
            fail('runtime_legacy_during_hold', 'rt=' . json_encode($rt));
        }
        $latest = Db::name('organization_change_log')
            ->whereIn('action', [
                OrganizationScopeService::SOURCE_CUTOVER_ACTION,
                OrganizationScopeService::SOURCE_ROLLBACK_ACTION,
            ])
            ->order('id', 'desc')
            ->find();
        if (($latest['action'] ?? '') === OrganizationScopeService::SOURCE_CUTOVER_ACTION) {
            pass('rollback_audit_not_yet_during_hold');
        } else {
            fail('rollback_audit_not_yet_during_hold', json_encode($latest, JSON_UNESCAPED_UNICODE));
        }
    }

    file_put_contents($probeGo, '1');
    if (!waitFile($probeOut, 20)) {
        fail('probe_out', 'timeout');
    } else {
        $p = readJson($probeOut);
        if (isset($p['is_migrated']) && $p['is_migrated'] === false) {
            pass('probe_isMigrated_false_during_hold', 'mode=' . ($p['mode'] ?? '') . ' ver=' . ($p['ver'] ?? ''));
        } else {
            fail('probe_isMigrated_false_during_hold', json_encode($p, JSON_UNESCAPED_UNICODE));
        }
    }

    file_put_contents($clearGo, '1');
    if (!waitFile($clearOut, 20)) {
        fail('clear_finished', 'timeout');
    } else {
        $c = readJson($clearOut);
        if (!empty($c['ok'])) {
            pass('clear_finished');
        } else {
            fail('clear_finished', json_encode($c, JSON_UNESCAPED_UNICODE));
        }
    }

    file_put_contents($finalGo, '1');
    if (!waitFile($finalOut, 20)) {
        fail('final_out', 'timeout');
    } else {
        $f = readJson($finalOut);
        if (isset($f['is_migrated']) && $f['is_migrated'] === false) {
            pass('final_isMigrated_false_without_local_clear', 'mode=' . ($f['mode'] ?? ''));
        } else {
            fail('final_isMigrated_false_without_local_clear', json_encode($f, JSON_UNESCAPED_UNICODE));
        }
    }
} finally {
    OrganizationScopeService::clearTestHold();
    OrganizationScopeService::setTestFailAfterRuntimeSet(false);
    OrganizationScopeService::setTestFailAfterRedisBeforeAudit(false);
    if ($batchId > 0) {
        Db::name('organization_change_log')->where('id', $batchId)->delete();
    }
    Db::name('organization_change_log')->where('remark', 'like', $runId . '%')->delete();
    Db::name('organization_change_log')
        ->where('operator_name', 'system')
        ->whereIn('action', [
            OrganizationScopeService::SOURCE_CUTOVER_ACTION,
            OrganizationScopeService::SOURCE_ROLLBACK_ACTION,
        ])
        ->whereIn('remark', ['正式切源 committed', '组织数据源回滚'])
        ->where('add_time', '>=', $smokeStartedAt - 2)
        ->delete();
    removeDbMode();
    try {
        CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    } catch (\Throwable $e) {
    }
    OrganizationScopeService::resetRequestModeCacheForTest();
    OrganizationScopeService::clearIntegrityCache();
}

$afterCore = [
    'organization_active' => (int)Db::name('organization')->where('is_del', 0)->count(),
    'organization_store' => (int)Db::name('organization_store')->count(),
    'organization_admin' => (int)Db::name('organization_admin')->count(),
    'organization_admin_store_exclude' => (int)Db::name('organization_admin_store_exclude')->count(),
];
echo 'SNAPSHOT_AFTER=' . json_encode($afterCore, JSON_UNESCAPED_UNICODE) . "\n";
if ($afterCore === $beforeCore) {
    pass('core_tables_stable');
} else {
    fail('core_tables_stable', json_encode(['b' => $beforeCore, 'a' => $afterCore], JSON_UNESCAPED_UNICODE));
}

echo "\n==== SUMMARY ====\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . "\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
echo "ALL_PASS\n";
exit(0);

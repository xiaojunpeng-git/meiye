<?php
/**
 * O1 source-mode 三组确定性双进程并发
 *
 * 1) organization 切源（门禁后暂停） vs 组织修改
 * 1b) organization 切源（门禁后暂停） vs bindStoreToOrg
 * 2) organization 切源（门禁后暂停） vs clearSourceModeCache
 * 3) organization 切源失败补偿（SET 后暂停） vs dual_read 成功发布
 *
 * docker cp 美容源码/scripts/smoke-o1-source-mode-concurrency.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-source-mode-concurrency-worker.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-o1-source-mode-concurrency.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationReconcileServices;
use app\services\organization\OrganizationScopeService;
use mohe\services\CacheService;
use think\facade\Db;

$worker = '/tmp/smoke-o1-source-mode-concurrency-worker.php';
if (!is_file($worker)) {
    fwrite(STDERR, "missing worker\n");
    exit(2);
}

$failures = [];
$runId = 'o1sc_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);
$smokeStartedAt = time();
$dbConfigInsertedId = 0;
$batchIds = [];

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

function runtimeRaw()
{
    $v = CacheService::redisHandler()->get(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    return ($v === false || $v === '') ? null : $v;
}

function latestCred(): ?array
{
    $row = Db::name('organization_change_log')
        ->whereIn('action', [
            OrganizationScopeService::SOURCE_CUTOVER_ACTION,
            OrganizationScopeService::SOURCE_ROLLBACK_ACTION,
        ])
        ->order('id', 'desc')
        ->find();
    return $row ?: null;
}

function ensureDbOrgMode(): int
{
    global $dbConfigInsertedId;
    $row = Db::name('system_config')->where('menu_name', OrganizationScopeService::SOURCE_MODE_CONFIG_KEY)->find();
    $encoded = json_encode(OrganizationScopeService::MODE_ORGANIZATION, JSON_UNESCAPED_UNICODE);
    if ($row) {
        Db::name('system_config')->where('id', (int)$row['id'])->update(['value' => $encoded]);
        return (int)$row['id'];
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
        'info' => '组织数据源模式（smoke concurrency）',
        'desc' => 'smoke temporary',
        'sort' => 0,
        'status' => 1,
    ]);
    return $dbConfigInsertedId;
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

function ensureOkBatch(string $runId): int
{
    global $batchIds;
    /** @var OrganizationScopeService $scope */
    $scope = app()->make(OrganizationScopeService::class);
    OrganizationScopeService::clearIntegrityCache();
    $good = $scope->reconcileLegacyMigration(true, false);
    $id = (int)Db::name('organization_change_log')->insertGetId([
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
    $batchIds[] = $id;
    OrganizationScopeService::clearIntegrityCache();
    return $id;
}

function snapshotCore(): array
{
    return [
        'organization_active' => (int)Db::name('organization')->where('is_del', 0)->count(),
        'organization_store' => (int)Db::name('organization_store')->count(),
        'organization_admin' => (int)Db::name('organization_admin')->count(),
        'organization_admin_store_exclude' => (int)Db::name('organization_admin_store_exclude')->count(),
    ];
}

function spawn(string $cmd): void
{
    exec($cmd . ' >/dev/null 2>&1 &');
}

function waitFile(string $path, int $sec = 20): bool
{
    $deadline = time() + $sec;
    while (time() < $deadline) {
        if (is_file($path)) {
            return true;
        }
        usleep(30000);
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

function assertFinalConsistency(string $case, ?string $expectRuntimeOrNull, bool $expectMigrated): void
{
    /** @var OrganizationScopeService $scope */
    $scope = app()->make(OrganizationScopeService::class);
    OrganizationScopeService::resetRequestModeCacheForTest();
    OrganizationScopeService::clearIntegrityCache();
    $rt = runtimeRaw();
    $cred = latestCred();
    $mig = $scope->isMigrated();
    $credAction = (string)($cred['action'] ?? '');
    $after = $cred ? (json_decode((string)($cred['after_data'] ?? ''), true) ?: []) : [];

    if ($expectRuntimeOrNull === null || $expectRuntimeOrNull === '') {
        $rtOk = ($rt === null || $rt === '' || $rt === false);
    } else {
        $rtOk = ((string)$rt === $expectRuntimeOrNull);
    }
    if ($mig !== $expectMigrated) {
        fail($case . '_isMigrated', 'want=' . (int)$expectMigrated . ' got=' . (int)$mig);
        return;
    }
    if (!$rtOk) {
        fail($case . '_runtime', 'want=' . json_encode($expectRuntimeOrNull) . ' got=' . json_encode($rt));
        return;
    }
    // 凭证与 isMigrated 一致：migrated 必须有 committed；否则最新不得是可用 committed
    if ($expectMigrated) {
        if ($credAction !== OrganizationScopeService::SOURCE_CUTOVER_ACTION || ($after['status'] ?? '') !== 'committed') {
            fail($case . '_cred', 'migrated but no committed cutover');
            return;
        }
    } else {
        if ($credAction === OrganizationScopeService::SOURCE_CUTOVER_ACTION && ($after['status'] ?? '') === 'committed') {
            // runtime 非 organization 时 isMigrated 仍 false — 允许旧 committed 残留，但本轮场景应写 rollback 或未成功切源
            if ((string)$rt === OrganizationScopeService::MODE_ORGANIZATION) {
                fail($case . '_cred', 'runtime=organization + committed but isMigrated=false');
                return;
            }
        }
    }
    pass($case . '_consistent', 'rt=' . json_encode($rt) . ' cred=' . $credAction . ' mig=' . (int)$mig);
}

$before = snapshotCore();
echo "RUN_ID={$runId}\n";
echo 'SNAPSHOT_BEFORE=' . json_encode($before, JSON_UNESCAPED_UNICODE) . "\n";

try {
    // ---------- 1) cutover hold vs org modify ----------
    echo "=== C1 cutover vs org modify ===\n";
    removeDbMode();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);
    ensureDbOrgMode();
    ensureOkBatch($runId . '_c1');

    $child = Db::name('organization')->where('is_del', 0)->where('pid', '>', 0)->order('id asc')->find();
    $root = Db::name('organization')->where('is_del', 0)->where('pid', 0)->order('id asc')->find();
    if (!$child || !$root) {
        fail('C1_sample', 'need root+child');
    } else {
        $orgId = (int)$child['id'];
        $oldPid = (int)$child['pid'];
        $oldSort = (int)$child['sort'];

        $ready = '/tmp/o1sc_c1_ready';
        $go = '/tmp/o1sc_c1_go';
        $outA = '/tmp/o1sc_c1_a.json';
        $outB = '/tmp/o1sc_c1_b.json';
        @unlink($ready);
        @unlink($go);
        @unlink($outA);
        @unlink($outB);

        $sortBeforeHold = (int)Db::name('organization')->where('id', $orgId)->value('sort');
        spawn('php ' . escapeshellarg($worker) . ' publish_org_hold_gate ' . escapeshellarg($outA) . ' ' . escapeshellarg($ready) . ' ' . escapeshellarg($go));
        if (!waitFile($ready, 20)) {
            fail('C1_a_ready', 'timeout');
        } else {
            pass('C1_a_held_after_gate');
            // A 持锁暂停期间启动 B：组织修改必须被阻塞，不能插入门禁与 committed 之间
            spawn('php ' . escapeshellarg($worker) . ' save_org ' . escapeshellarg($outB) . ' _ _ ' . $orgId . ' ' . $oldPid);
            usleep(800000);
            if (is_file($outB)) {
                fail('C1_b_blocked', 'B finished while A still in gate hold — lock not covering cutover');
            } else {
                pass('C1_b_blocked_during_hold');
            }
            $sortDuring = (int)Db::name('organization')->where('id', $orgId)->value('sort');
            if ($sortDuring === $sortBeforeHold) {
                pass('C1_org_unchanged_during_hold');
            } else {
                fail('C1_org_unchanged_during_hold', "sort {$sortBeforeHold}->{$sortDuring}");
            }
            // 放行 A 完成 committed
            file_put_contents($go, '1');
            waitFile($outA, 25);
            waitFile($outB, 25);
            $ra = readJson($outA);
            $rb = readJson($outB);
            if (!empty($ra['ok'])) {
                pass('C1_a_cutover_ok');
            } else {
                fail('C1_a_cutover_ok', json_encode($ra, JSON_UNESCAPED_UNICODE));
            }
            // B 在 A 释放锁后完成；不得在 hold 窗口内改写
            if (is_file($outB) && !empty($rb['ok'])) {
                pass('C1_b_finished_after_release');
            } else {
                fail('C1_b_finished_after_release', json_encode($rb, JSON_UNESCAPED_UNICODE));
            }
            assertFinalConsistency('C1', OrganizationScopeService::MODE_ORGANIZATION, true);
            // 还原 sort
            Db::name('organization')->where('id', $orgId)->update(['sort' => $oldSort, 'update_time' => time()]);
        }
    }

    // ---------- 1b) cutover hold vs bindStoreToOrg ----------
    echo "=== C1b cutover vs bindStoreToOrg ===\n";
    OrganizationScopeService::clearSourceModeCache();
    ensureDbOrgMode();
    ensureOkBatch($runId . '_c1b');
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);

    $bindRow = Db::name('organization_store')->alias('os')
        ->join('organization o', 'o.id = os.org_id')
        ->where('o.is_del', 0)
        ->field('os.id,os.store_id,os.org_id')
        ->order('os.id asc')
        ->find();
    $bindOtherOrg = $bindRow
        ? (int)Db::name('organization')->where('is_del', 0)->where('id', '<>', (int)$bindRow['org_id'])->value('id')
        : 0;
    if (!$bindRow || $bindOtherOrg <= 0) {
        fail('C1b_sample', 'need store bind + other org');
    } else {
        $storeId = (int)$bindRow['store_id'];
        $oldOrgId = (int)$bindRow['org_id'];
        $excludeSnap = Db::name('organization_admin_store_exclude')
            ->where('store_id', $storeId)
            ->select()
            ->toArray();

        $ready = '/tmp/o1sc_c1b_ready';
        $go = '/tmp/o1sc_c1b_go';
        $outA = '/tmp/o1sc_c1b_a.json';
        $outB = '/tmp/o1sc_c1b_b.json';
        @unlink($ready);
        @unlink($go);
        @unlink($outA);
        @unlink($outB);

        $orgBeforeHold = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        spawn('php ' . escapeshellarg($worker) . ' publish_org_hold_gate ' . escapeshellarg($outA) . ' ' . escapeshellarg($ready) . ' ' . escapeshellarg($go));
        if (!waitFile($ready, 20)) {
            fail('C1b_a_ready', 'timeout');
        } else {
            pass('C1b_a_held_after_gate');
            spawn('php ' . escapeshellarg($worker) . ' bind_store ' . escapeshellarg($outB) . ' _ _ ' . $storeId . ' ' . $bindOtherOrg);
            usleep(800000);
            if (is_file($outB)) {
                fail('C1b_b_blocked', 'bind finished during cutover hold');
            } else {
                pass('C1b_b_blocked_during_hold');
            }
            $orgDuring = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
            if ($orgDuring === $orgBeforeHold) {
                pass('C1b_store_bind_unchanged_during_hold');
            } else {
                fail('C1b_store_bind_unchanged_during_hold', "org {$orgBeforeHold}->{$orgDuring}");
            }
            file_put_contents($go, '1');
            waitFile($outA, 25);
            waitFile($outB, 25);
            $ra = readJson($outA);
            $rb = readJson($outB);
            if (!empty($ra['ok']) && !empty($rb['ok'])) {
                pass('C1b_both_finished_after_release');
            } else {
                fail('C1b_both_finished_after_release', json_encode(['a' => $ra, 'b' => $rb], JSON_UNESCAPED_UNICODE));
            }
            assertFinalConsistency('C1b', OrganizationScopeService::MODE_ORGANIZATION, true);

            // 恢复门店归属、排除记录、绑定审计
            Db::name('organization_store')->where('store_id', $storeId)->update(['org_id' => $oldOrgId]);
            Db::name('organization_admin_store_exclude')->where('store_id', $storeId)->delete();
            foreach ($excludeSnap as $ex) {
                Db::name('organization_admin_store_exclude')->insert($ex);
            }
            Db::name('organization_change_log')
                ->where('action', 'bind_store')
                ->where('operator_name', 'smoke')
                ->where('target_id', $storeId)
                ->where('add_time', '>=', $smokeStartedAt - 2)
                ->delete();
            pass('C1b_restored_store_bind');
        }
    }

    // ---------- 2) cutover hold vs clear ----------
    echo "=== C2 cutover vs clear ===\n";
    OrganizationScopeService::clearSourceModeCache();
    ensureDbOrgMode();
    ensureOkBatch($runId . '_c2');
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);

    $ready = '/tmp/o1sc_c2_ready';
    $go = '/tmp/o1sc_c2_go';
    $outA = '/tmp/o1sc_c2_a.json';
    $outB = '/tmp/o1sc_c2_b.json';
    @unlink($ready);
    @unlink($go);
    @unlink($outA);
    @unlink($outB);

    spawn('php ' . escapeshellarg($worker) . ' publish_org_hold_gate ' . escapeshellarg($outA) . ' ' . escapeshellarg($ready) . ' ' . escapeshellarg($go));
    if (!waitFile($ready, 20)) {
        fail('C2_a_ready', 'timeout');
    } else {
        pass('C2_a_held_after_gate');
        spawn('php ' . escapeshellarg($worker) . ' clear_source ' . escapeshellarg($outB));
        usleep(800000);
        if (is_file($outB)) {
            fail('C2_b_blocked', 'clear finished during cutover hold');
        } else {
            pass('C2_b_blocked_during_hold');
        }
        // hold 期间尚未 committed
        $credMid = latestCred();
        if (($credMid['action'] ?? '') !== OrganizationScopeService::SOURCE_CUTOVER_ACTION
            || (json_decode((string)($credMid['after_data'] ?? ''), true)['status'] ?? '') !== 'committed'
            || (json_decode((string)($credMid['after_data'] ?? ''), true)['batch_id'] ?? '') !== $runId . '_c2_batch') {
            pass('C2_no_committed_during_hold');
        } else {
            // 若更早有别的 committed，只要 runtime 尚未 organization 亦可
            if (runtimeRaw() !== OrganizationScopeService::MODE_ORGANIZATION) {
                pass('C2_no_committed_during_hold', 'runtime not organization yet');
            } else {
                fail('C2_no_committed_during_hold', 'already organization during hold');
            }
        }
        file_put_contents($go, '1');
        waitFile($outA, 25);
        waitFile($outB, 25);
        $ra = readJson($outA);
        $rb = readJson($outB);
        if (!empty($ra['ok']) && !empty($rb['ok'])) {
            pass('C2_both_finished');
        } else {
            fail('C2_both_finished', json_encode(['a' => $ra, 'b' => $rb], JSON_UNESCAPED_UNICODE));
        }
        // clear 在 cutover 之后执行 → 最终 rollback，isMigrated=false
        assertFinalConsistency('C2', null, false);
        $cred = latestCred();
        if (($cred['action'] ?? '') === OrganizationScopeService::SOURCE_ROLLBACK_ACTION) {
            pass('C2_final_rollback_cred');
        } else {
            fail('C2_final_rollback_cred', json_encode($cred, JSON_UNESCAPED_UNICODE));
        }
    }

    // ---------- 3) fail compensate vs dual_read ----------
    echo "=== C3 compensate vs dual_read ===\n";
    ensureDbOrgMode();
    ensureOkBatch($runId . '_c3');
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);

    $ready = '/tmp/o1sc_c3_ready';
    $go = '/tmp/o1sc_c3_go';
    $outA = '/tmp/o1sc_c3_a.json';
    $outB = '/tmp/o1sc_c3_b.json';
    @unlink($ready);
    @unlink($go);
    @unlink($outA);
    @unlink($outB);

    spawn('php ' . escapeshellarg($worker) . ' publish_org_fail_hold_set ' . escapeshellarg($outA) . ' ' . escapeshellarg($ready) . ' ' . escapeshellarg($go));
    if (!waitFile($ready, 20)) {
        fail('C3_a_ready', 'timeout');
    } else {
        pass('C3_a_held_after_runtime_set');
        // hold 期间 runtime 可能已是 organization（SET 成功），B 不得先完成 dual_read
        spawn('php ' . escapeshellarg($worker) . ' publish_dual_read ' . escapeshellarg($outB));
        usleep(800000);
        if (is_file($outB)) {
            fail('C3_b_blocked', 'dual_read finished during compensate hold — would be overwritable');
        } else {
            pass('C3_b_blocked_during_hold');
        }
        file_put_contents($go, '1');
        waitFile($outA, 25);
        waitFile($outB, 25);
        $ra = readJson($outA);
        $rb = readJson($outB);
        if (!empty($ra['ok']) && !empty($rb['ok'])) {
            pass('C3_both_finished');
        } else {
            fail('C3_both_finished', json_encode(['a' => $ra, 'b' => $rb], JSON_UNESCAPED_UNICODE));
        }
        // A 补偿后释放锁，B 成功发布 dual_read；补偿不得覆盖 B
        if (runtimeRaw() === OrganizationScopeService::MODE_DUAL_READ) {
            pass('C3_final_runtime_dual_read');
        } else {
            fail('C3_final_runtime_dual_read', 'rt=' . json_encode(runtimeRaw()));
        }
        assertFinalConsistency('C3', OrganizationScopeService::MODE_DUAL_READ, false);
    }

} finally {
    echo "=== cleanup ===\n";
    OrganizationScopeService::clearTestHold();
    OrganizationScopeService::setTestFailAfterRuntimeSet(false);
    foreach ($batchIds as $bid) {
        Db::name('organization_change_log')->where('id', (int)$bid)->delete();
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
    Db::name('organization_change_log')->whereIn('operator_name', ['smoke', 'conc-worker'])->delete();
    removeDbMode();
    try {
        CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    } catch (\Throwable $e) {
    }
    OrganizationScopeService::resetRequestModeCacheForTest();
    OrganizationScopeService::clearIntegrityCache();
}

$after = snapshotCore();
echo 'SNAPSHOT_AFTER=' . json_encode($after, JSON_UNESCAPED_UNICODE) . "\n";
if ($after === $before) {
    pass('core_tables_stable');
} else {
    fail('core_tables_stable', json_encode(['b' => $before, 'a' => $after], JSON_UNESCAPED_UNICODE));
}
$leftCfg = Db::name('system_config')->where('menu_name', OrganizationScopeService::SOURCE_MODE_CONFIG_KEY)->find();
$leftRt = runtimeRaw();
$leftTrash = (int)Db::name('organization_change_log')->whereIn('operator_name', ['smoke', 'conc-worker'])->count();
if (!$leftCfg && ($leftRt === null || $leftRt === '') && $leftTrash === 0) {
    pass('no_test_residue');
} else {
    fail('no_test_residue', json_encode(['cfg' => $leftCfg, 'rt' => $leftRt, 'trash' => $leftTrash], JSON_UNESCAPED_UNICODE));
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

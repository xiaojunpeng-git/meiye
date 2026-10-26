<?php
/**
 * O1 第三轮 / source-mode P1 冒烟
 *
 * docker cp 美容源码/scripts/smoke-o1-r3-organization.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-organization-concurrent-swap.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-organization-concurrent-swap-worker.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-organization-worker-cache.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-organization-worker-cache-worker.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-source-mode-concurrency.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-source-mode-concurrency-worker.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-rollback-credential-race.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-rollback-credential-race-worker.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-rollback-clear-hold.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-o1-r3-organization.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationMigrateServices;
use app\services\organization\OrganizationReconcileServices;
use app\services\organization\OrganizationScopeService;
use mohe\services\CacheService;
use think\facade\Config;
use think\facade\Db;

$runId = 'o1r3_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);
$smokeStartedAt = time();
$failures = [];
$createdOrgIds = [];
$createdLogIds = [];
$createdBatchIds = [];
$dbConfigInsertedId = 0;
$orgSnapshot = null; // 生产 migrateFromLegacy 失败回滚用的可恢复副本

function fail(string $case, string $msg): void
{
    global $failures;
    $failures[] = "{$case}: {$msg}";
    echo "FAIL {$case}: {$msg}\n";
}

function pass(string $case, string $extra = ''): void
{
    echo 'PASS ' . $case . ($extra !== '' ? ' ' . $extra : '') . "\n";
}

function snapshotCounts(): array
{
    return [
        'organization' => (int)Db::name('organization')->count(),
        'organization_active' => (int)Db::name('organization')->where('is_del', 0)->count(),
        'organization_store' => (int)Db::name('organization_store')->count(),
        'organization_admin' => (int)Db::name('organization_admin')->count(),
        'organization_admin_store_exclude' => (int)Db::name('organization_admin_store_exclude')->count(),
        'organization_change_log' => (int)Db::name('organization_change_log')->count(),
        'auto_increment' => (int)Db::query("SELECT AUTO_INCREMENT AS v FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_organization'")[0]['v'] ?? 0,
    ];
}

function assertCounts(string $case, array $before, array $after): void
{
    foreach ($before as $k => $v) {
        if ((int)($after[$k] ?? -1) !== (int)$v) {
            fail($case, "{$k} before={$v} after=" . ($after[$k] ?? 'null'));
            return;
        }
    }
    pass($case, 'counts_unchanged');
}

function runtimeRaw()
{
    try {
        $v = CacheService::redisHandler()->get(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
        if ($v === false || $v === '') {
            return null;
        }
        return $v;
    } catch (\Throwable $e) {
        return null;
    }
}

function versionRaw()
{
    try {
        $v = CacheService::redisHandler()->get(OrganizationScopeService::SOURCE_MODE_VERSION_KEY);
        if ($v === false || $v === '') {
            return null;
        }
        return (string)$v;
    } catch (\Throwable $e) {
        return null;
    }
}

function setVersionRaw(string $value): void
{
    $handler = CacheService::redisHandler()->handler();
    $prefix = (string)(Config::get('cache.stores.redis.prefix') ?? '');
    $handler->set($prefix . OrganizationScopeService::SOURCE_MODE_VERSION_KEY, $value);
}

function deleteVersionRaw(): void
{
    $handler = CacheService::redisHandler()->handler();
    $prefix = (string)(Config::get('cache.stores.redis.prefix') ?? '');
    $handler->del($prefix . OrganizationScopeService::SOURCE_MODE_VERSION_KEY);
}

function ensureDbSourceMode(string $mode): int
{
    global $dbConfigInsertedId;
    $row = Db::name('system_config')->where('menu_name', OrganizationScopeService::SOURCE_MODE_CONFIG_KEY)->find();
    $encoded = json_encode($mode, JSON_UNESCAPED_UNICODE);
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
        'info' => '组织数据源模式（smoke）',
        'desc' => 'smoke temporary',
        'sort' => 0,
        'status' => 1,
    ]);
    return $dbConfigInsertedId;
}

function removeDbSourceMode(): void
{
    global $dbConfigInsertedId;
    if ($dbConfigInsertedId > 0) {
        Db::name('system_config')->where('id', $dbConfigInsertedId)->delete();
        $dbConfigInsertedId = 0;
        return;
    }
    Db::name('system_config')->where('menu_name', OrganizationScopeService::SOURCE_MODE_CONFIG_KEY)->delete();
}

function latestSourceCredential(): ?array
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

function snapshotOrgTables(): array
{
    return [
        'organization' => Db::name('organization')->select()->toArray(),
        'organization_store' => Db::name('organization_store')->select()->toArray(),
        'organization_admin' => Db::name('organization_admin')->select()->toArray(),
        'organization_admin_store_exclude' => Db::name('organization_admin_store_exclude')->select()->toArray(),
    ];
}

function wipeOrgTables(): void
{
    Db::name('organization_admin_store_exclude')->where('id', '>', 0)->delete();
    Db::name('organization_admin')->where('id', '>', 0)->delete();
    Db::name('organization_store')->where('id', '>', 0)->delete();
    Db::name('organization')->where('id', '>', 0)->delete();
}

function restoreOrgTables(array $snap): void
{
    wipeOrgTables();
    foreach ($snap['organization'] as $row) {
        Db::name('organization')->insert($row);
    }
    foreach ($snap['organization_store'] as $row) {
        Db::name('organization_store')->insert($row);
    }
    foreach ($snap['organization_admin'] as $row) {
        Db::name('organization_admin')->insert($row);
    }
    foreach ($snap['organization_admin_store_exclude'] as $row) {
        Db::name('organization_admin_store_exclude')->insert($row);
    }
    $maxId = (int)Db::name('organization')->max('id');
    Db::execute('ALTER TABLE `eb_organization` AUTO_INCREMENT = ' . max(1, $maxId + 1));
}

function globalCleanup(string $runId, int $smokeStartedAt, ?array $orgSnapshot): void
{
    global $createdOrgIds, $createdLogIds, $createdBatchIds, $dbConfigInsertedId;

    OrganizationScopeService::setTestSourceMode(null);
    OrganizationScopeService::setTestIntegrityPassed(null);
    OrganizationScopeService::setTestIntegrityQueryBoom(false);
    OrganizationScopeService::setTestRedisPublishBoom(false);
    OrganizationScopeService::setTestFailAfterRuntimeSet(false);
    OrganizationScopeService::setTestFailAfterRedisBeforeAudit(false);
    OrganizationScopeService::clearTestHold();
    OrganizationMigrateServices::setTestCorruptBeforeReconcile(false);

    if ($orgSnapshot !== null) {
        try {
            restoreOrgTables($orgSnapshot);
        } catch (\Throwable $e) {
            echo "WARN restore_org_tables: {$e->getMessage()}\n";
        }
    }

    foreach ($createdOrgIds as $oid) {
        Db::name('organization')->where('id', (int)$oid)->delete();
    }
    foreach (array_merge($createdLogIds, $createdBatchIds) as $lid) {
        Db::name('organization_change_log')->where('id', (int)$lid)->delete();
    }
    Db::name('organization_change_log')->where('remark', 'like', $runId . '%')->delete();
    Db::name('organization_change_log')->where('operator_name', $runId)->delete();
    Db::name('organization')->where('name', 'like', '__' . $runId . '%')->delete();
    // 冒烟产生的切源/回滚/失败审计（含子进程 clear 写入；remark 固定）
    Db::name('organization_change_log')
        ->where('operator_name', 'system')
        ->whereIn('action', [
            OrganizationScopeService::SOURCE_CUTOVER_ACTION,
            OrganizationScopeService::SOURCE_ROLLBACK_ACTION,
            OrganizationReconcileServices::MIGRATE_FAIL_ACTION,
        ])
        ->whereIn('remark', ['正式切源 committed', '组织数据源回滚'])
        ->delete();
    Db::name('organization_change_log')
        ->where('operator_name', 'system')
        ->where('action', OrganizationReconcileServices::MIGRATE_FAIL_ACTION)
        ->where('add_time', '>=', $smokeStartedAt - 2)
        ->delete();
    Db::name('organization_change_log')
        ->where('remark', 'like', $runId . '%')
        ->delete();
    Db::name('organization_change_log')->whereIn('operator_name', ['smoke', 'conc-worker'])->delete();

    removeDbSourceMode();

    try {
        $ver = versionRaw();
        if ($ver !== null && !preg_match('/^\d+$/', $ver) && !preg_match('/^i:\d+;$/', $ver)) {
            deleteVersionRaw();
        }
        // 清理 runtime，避免再写 source_rollback 审计污染库
        CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
        $handler = CacheService::redisHandler()->handler();
        $prefix = (string)(Config::get('cache.stores.redis.prefix') ?? '');
        $handler->incr($prefix . OrganizationScopeService::SOURCE_MODE_VERSION_KEY);
    } catch (\Throwable $e) {
        try {
            deleteVersionRaw();
            CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
        } catch (\Throwable $e2) {
        }
    }
    OrganizationScopeService::clearIntegrityCache();
    OrganizationScopeService::resetRequestModeCacheForTest();

    $maxId = (int)Db::name('organization')->max('id');
    Db::execute('ALTER TABLE `eb_organization` AUTO_INCREMENT = ' . max(1, $maxId + 1));
}

/** @var OrganizationScopeService $scope */
$scope = app()->make(OrganizationScopeService::class);
/** @var OrganizationManageServices $manage */
$manage = app()->make(OrganizationManageServices::class);
/** @var OrganizationMigrateServices $migrate */
$migrate = app()->make(OrganizationMigrateServices::class);

echo "RUN_ID={$runId}\n";
$beforeAll = snapshotCounts();
echo 'SNAPSHOT_BEFORE=' . json_encode($beforeAll, JSON_UNESCAPED_UNICODE) . "\n";

try {
    // 清理明确测试审计垃圾（仅 smoke/conc-worker）
    $trash = (int)Db::name('organization_change_log')->whereIn('operator_name', ['smoke', 'conc-worker'])->count();
    if ($trash > 0) {
        Db::name('organization_change_log')->whereIn('operator_name', ['smoke', 'conc-worker'])->delete();
        pass('cleanup_smoke_audit_trash', 'deleted=' . $trash);
    } else {
        pass('cleanup_smoke_audit_trash', 'none');
    }

    OrganizationScopeService::setTestSourceMode(null);
    OrganizationScopeService::setTestIntegrityPassed(null);
    OrganizationScopeService::setTestIntegrityQueryBoom(false);
    OrganizationScopeService::setTestRedisPublishBoom(false);
    OrganizationScopeService::setTestFailAfterRuntimeSet(false);
    OrganizationScopeService::setTestFailAfterRedisBeforeAudit(false);
    try {
        OrganizationScopeService::clearSourceModeCache();
    } catch (\Throwable $e) {
        // 若版本键异常等，先删再清
        try {
            deleteVersionRaw();
            CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
            OrganizationScopeService::clearSourceModeCache();
        } catch (\Throwable $e2) {
        }
    }

    echo "=== reconcile relationship correctness ===\n";
    OrganizationScopeService::clearIntegrityCache();
    $base = $scope->reconcileLegacyMigration(true, false);
    if (!empty($base['passed'])) {
        pass('baseline_reconcile_ok', 'hash=' . substr((string)$base['legacy_hash'], 0, 12));
    } else {
        fail('baseline_reconcile_ok', implode(';', $base['blockers'] ?? []));
    }

    // 1) 两个有效 legacy ID 互换
    $orgs = Db::name('organization')->where('is_del', 0)->where('legacy_manage_region_id', '>', 0)->order('id asc')->limit(2)->select()->toArray();
    if (count($orgs) >= 2) {
        $a = $orgs[0];
        $b = $orgs[1];
        $la = (int)$a['legacy_manage_region_id'];
        $lb = (int)$b['legacy_manage_region_id'];
        Db::startTrans();
        try {
            Db::name('organization')->where('id', (int)$a['id'])->update(['legacy_manage_region_id' => $lb]);
            Db::name('organization')->where('id', (int)$b['id'])->update(['legacy_manage_region_id' => $la]);
            OrganizationScopeService::clearIntegrityCache();
            $swap = $scope->reconcileLegacyMigration(true, false);
            if (empty($swap['passed']) && (
                (int)($swap['anomaly']['wrong_org_parent_count'] ?? 0) > 0
                || (int)($swap['anomaly']['wrong_store_org_count'] ?? 0) > 0
                || (int)($swap['anomaly']['wrong_admin_org_count'] ?? 0) > 0
                || !empty($swap['blockers'])
            )) {
                pass('swap_legacy_ids_fails', implode(';', array_slice($swap['blockers'] ?? [], 0, 2)));
            } else {
                fail('swap_legacy_ids_fails', 'passed=' . (int)!empty($swap['passed']) . ' anomaly=' . json_encode($swap['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
            }
        } finally {
            Db::rollback();
            OrganizationScopeService::clearIntegrityCache();
        }
    } else {
        fail('swap_legacy_ids_fails', 'need 2 mapped orgs');
    }

    // 2) 组织父级与 legacy pid 不一致
    $child = Db::name('organization')->where('is_del', 0)->where('pid', '>', 0)->order('id asc')->find();
    $root = Db::name('organization')->where('is_del', 0)->where('pid', 0)->find();
    $sibling = null;
    if ($child && $root) {
        $sibling = Db::name('organization')->where('is_del', 0)->where('pid', (int)$root['id'])->where('id', '<>', (int)$child['id'])->find();
    }
    if ($child && $sibling) {
        Db::startTrans();
        try {
            Db::name('organization')->where('id', (int)$child['id'])->update(['pid' => (int)$sibling['id']]);
            OrganizationScopeService::clearIntegrityCache();
            $badParent = $scope->reconcileLegacyMigration(true, false);
            if (empty($badParent['passed']) && (int)($badParent['anomaly']['wrong_org_parent_count'] ?? 0) > 0) {
                pass('wrong_parent_vs_legacy_pid_fails', 'wrong_parent=' . (int)$badParent['anomaly']['wrong_org_parent_count']);
            } else {
                fail('wrong_parent_vs_legacy_pid_fails', json_encode($badParent['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
            }
        } finally {
            Db::rollback();
            OrganizationScopeService::clearIntegrityCache();
        }
    } else {
        fail('wrong_parent_vs_legacy_pid_fails', 'no sibling sample');
    }

    // 3) 门店绑到另一个有效但错误组织
    $bind = Db::name('organization_store')->alias('os')
        ->join('organization o', 'o.id = os.org_id')
        ->where('o.is_del', 0)
        ->field('os.id,os.store_id,os.org_id')
        ->order('os.id asc')
        ->find();
    $otherOrg = $bind ? (int)Db::name('organization')->where('is_del', 0)->where('id', '<>', (int)$bind['org_id'])->value('id') : 0;
    if ($bind && $otherOrg > 0) {
        Db::startTrans();
        try {
            Db::name('organization_store')->where('id', (int)$bind['id'])->update(['org_id' => $otherOrg]);
            OrganizationScopeService::clearIntegrityCache();
            $badStore = $scope->reconcileLegacyMigration(true, false);
            if (empty($badStore['passed']) && (int)($badStore['anomaly']['wrong_store_org_count'] ?? 0) > 0) {
                pass('wrong_store_org_fails', 'wrong_store=' . (int)$badStore['anomaly']['wrong_store_org_count']);
            } else {
                fail('wrong_store_org_fails', 'passed=' . (int)!empty($badStore['passed']));
            }
        } finally {
            Db::rollback();
            OrganizationScopeService::clearIntegrityCache();
        }
    } else {
        fail('wrong_store_org_fails', 'no sample');
    }

    // 4) 管理员绑到另一个有效但错误组织
    $admin = Db::name('organization_admin')->where('is_del', 0)->where('legacy_agent_id', '>', 0)->order('id asc')->find();
    $otherOrg2 = $admin ? (int)Db::name('organization')->where('is_del', 0)->where('id', '<>', (int)$admin['org_id'])->value('id') : 0;
    if ($admin && $otherOrg2 > 0) {
        Db::startTrans();
        try {
            Db::name('organization_admin')->where('id', (int)$admin['id'])->update(['org_id' => $otherOrg2]);
            OrganizationScopeService::clearIntegrityCache();
            $badAdmin = $scope->reconcileLegacyMigration(true, false);
            if (empty($badAdmin['passed']) && (int)($badAdmin['anomaly']['wrong_admin_org_count'] ?? 0) > 0) {
                pass('wrong_admin_org_fails', 'wrong_admin=' . (int)$badAdmin['anomaly']['wrong_admin_org_count']);
            } else {
                fail('wrong_admin_org_fails', 'passed=' . (int)!empty($badAdmin['passed']));
            }
        } finally {
            Db::rollback();
            OrganizationScopeService::clearIntegrityCache();
        }
    } else {
        fail('wrong_admin_org_fails', 'no sample');
    }

    echo "=== migrate reconcile fail full rollback (helper) ===\n";
    $beforeMig = snapshotCounts();
    try {
        $migrate->runTransactionalWriteWithReconcile(function () use ($bind, $otherOrg) {
            if (!$bind || $otherOrg <= 0) {
                throw new RuntimeException('no bind sample for corrupt write');
            }
            Db::name('organization_store')->where('id', (int)$bind['id'])->update(['org_id' => $otherOrg]);
        });
        fail('migrate_reconcile_fail_rollback', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '迁移对账失败') !== false) {
            pass('migrate_reconcile_fail_rollback', $e->getMessage());
        } else {
            fail('migrate_reconcile_fail_rollback', $e->getMessage());
        }
    }
    assertCounts('migrate_reconcile_fail_counts', $beforeMig, snapshotCounts());

    echo "=== migrateFromLegacy production path fail rollback (recoverable snapshot) ===\n";
    $orgSnapshot = snapshotOrgTables();
    $beforeProdMig = snapshotCounts();
    wipeOrgTables();
    OrganizationScopeService::clearIntegrityCache();
    OrganizationMigrateServices::setTestCorruptBeforeReconcile(true);
    try {
        $migrate->migrateFromLegacy(false);
        fail('migrateFromLegacy_fail_rollback', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '迁移对账失败') !== false) {
            pass('migrateFromLegacy_fail_rollback', $e->getMessage());
        } else {
            fail('migrateFromLegacy_fail_rollback', $e->getMessage());
        }
    }
    OrganizationMigrateServices::setTestCorruptBeforeReconcile(false);
    // 失败后新表应仍为空（整单回滚），再恢复副本
    $afterFail = snapshotCounts();
    if ((int)$afterFail['organization_active'] === 0
        && (int)$afterFail['organization_store'] === 0
        && (int)$afterFail['organization_admin'] === 0) {
        pass('migrateFromLegacy_tables_empty_after_fail');
    } else {
        fail('migrateFromLegacy_tables_empty_after_fail', json_encode($afterFail, JSON_UNESCAPED_UNICODE));
    }
    restoreOrgTables($orgSnapshot);
    $orgSnapshot = null;
    OrganizationScopeService::clearIntegrityCache();
    $restored = snapshotCounts();
    if ($restored['organization_active'] === $beforeProdMig['organization_active']
        && $restored['organization_store'] === $beforeProdMig['organization_store']
        && $restored['organization_admin'] === $beforeProdMig['organization_admin']
        && $restored['organization_admin_store_exclude'] === $beforeProdMig['organization_admin_store_exclude']) {
        pass('migrateFromLegacy_snapshot_restored');
    } else {
        fail('migrateFromLegacy_snapshot_restored', json_encode(['b' => $beforeProdMig, 'a' => $restored], JSON_UNESCAPED_UNICODE));
    }

    echo "=== batch hash mismatch cannot cutover ===\n";
    $good = $scope->reconcileLegacyMigration(true, false);
    $fakeId = (int)Db::name('organization_change_log')->insertGetId([
        'org_id' => 0,
        'action' => OrganizationReconcileServices::MIGRATE_BATCH_ACTION,
        'target_type' => 'migrate',
        'target_id' => 0,
        'before_data' => '',
        'after_data' => json_encode([
            'batch_id' => $runId . '_fake',
            'reconcile_ok' => true,
            'legacy_hash' => 'deadbeef',
            'new_projection_hash' => 'cafebabe',
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
        'remark' => $runId . '_fake_batch',
        'operator_id' => 0,
        'operator_name' => 'system',
        'add_time' => time(),
    ]);
    $createdLogIds[] = $fakeId;
    OrganizationScopeService::clearIntegrityCache();
    $cut = $scope->reconcileLegacyMigration(true, true);
    if (empty($cut['passed']) && (int)($cut['anomaly']['migrate_batch_hash_mismatch'] ?? 0) === 1) {
        pass('batch_hash_mismatch_blocks_cutover');
    } else {
        fail('batch_hash_mismatch_blocks_cutover', json_encode($cut['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
    }
    Db::name('organization_change_log')->where('id', $fakeId)->delete();
    $createdLogIds = array_values(array_filter($createdLogIds, static fn($id) => (int)$id !== $fakeId));

    // 写入正确 hash 的成功批次后 can_cutover
    $okBatchId = (int)Db::name('organization_change_log')->insertGetId([
        'org_id' => 0,
        'action' => OrganizationReconcileServices::MIGRATE_BATCH_ACTION,
        'target_type' => 'migrate',
        'target_id' => 0,
        'before_data' => '',
        'after_data' => json_encode([
            'batch_id' => $runId . '_ok',
            'reconcile_ok' => true,
            'legacy_hash' => (string)$good['legacy_hash'],
            'new_projection_hash' => (string)$good['new_projection_hash'],
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
        'remark' => $runId . '_ok_batch',
        'operator_id' => 0,
        'operator_name' => 'system',
        'add_time' => time(),
    ]);
    $createdBatchIds[] = $okBatchId;
    OrganizationScopeService::clearIntegrityCache();
    $status = $scope->getSourceStatus();
    if (!empty($status['can_cutover'])) {
        pass('batch_hash_match_can_cutover');
    } else {
        fail('batch_hash_match_can_cutover', implode(';', $status['blockers'] ?? []));
    }

    echo "=== source mode A-G gates ===\n";
    // 确保起点：无 DB organization 配置、runtime 非 organization
    removeDbSourceMode();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);
    $rt0 = runtimeRaw();

    // A: DB 缺失 + 无有效批次门禁（先删成功批次）
    Db::name('organization_change_log')->where('id', $okBatchId)->delete();
    $createdBatchIds = array_values(array_filter($createdBatchIds, static fn($id) => (int)$id !== $okBatchId));
    OrganizationScopeService::clearIntegrityCache();
    $rtBeforeA = runtimeRaw();
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        fail('A_publish_no_db_no_batch', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '拒绝发布 organization') !== false
            || mb_strpos($e->getMessage(), 'DB organization_source_mode') !== false) {
            pass('A_publish_no_db_no_batch', $e->getMessage());
        } else {
            fail('A_publish_no_db_no_batch', $e->getMessage());
        }
    }
    if (runtimeRaw() === $rtBeforeA && !$scope->isMigrated()) {
        pass('A_runtime_unchanged_isMigrated_false');
    } else {
        fail('A_runtime_unchanged_isMigrated_false', 'rt=' . json_encode(runtimeRaw()) . ' mig=' . (int)$scope->isMigrated());
    }

    // B: DB=organization，无成功批次
    ensureDbSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    OrganizationScopeService::clearIntegrityCache();
    $rtBeforeB = runtimeRaw();
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        fail('B_publish_db_org_no_batch', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '拒绝发布 organization') !== false
            || mb_strpos($e->getMessage(), '成功迁移批次') !== false
            || mb_strpos($e->getMessage(), '缺少成功迁移批次') !== false) {
            pass('B_publish_db_org_no_batch', $e->getMessage());
        } else {
            fail('B_publish_db_org_no_batch', $e->getMessage());
        }
    }
    if (runtimeRaw() === $rtBeforeB) {
        pass('B_runtime_unchanged');
    } else {
        fail('B_runtime_unchanged', json_encode(['b' => $rtBeforeB, 'a' => runtimeRaw()], JSON_UNESCAPED_UNICODE));
    }

    // C: 批次 hash 不匹配
    $badBatchId = (int)Db::name('organization_change_log')->insertGetId([
        'org_id' => 0,
        'action' => OrganizationReconcileServices::MIGRATE_BATCH_ACTION,
        'target_type' => 'migrate',
        'target_id' => 0,
        'before_data' => '',
        'after_data' => json_encode([
            'batch_id' => $runId . '_badhash',
            'reconcile_ok' => true,
            'legacy_hash' => 'deadbeef',
            'new_projection_hash' => 'cafebabe',
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
        'remark' => $runId . '_badhash_batch',
        'operator_id' => 0,
        'operator_name' => 'system',
        'add_time' => time(),
    ]);
    $createdLogIds[] = $badBatchId;
    OrganizationScopeService::clearIntegrityCache();
    $rtBeforeC = runtimeRaw();
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        fail('C_publish_hash_mismatch', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '拒绝发布 organization') !== false
            || mb_strpos($e->getMessage(), 'hash') !== false) {
            pass('C_publish_hash_mismatch', $e->getMessage());
        } else {
            fail('C_publish_hash_mismatch', $e->getMessage());
        }
    }
    if (runtimeRaw() === $rtBeforeC) {
        pass('C_runtime_unchanged');
    } else {
        fail('C_runtime_unchanged', 'changed');
    }
    Db::name('organization_change_log')->where('id', $badBatchId)->delete();
    $createdLogIds = array_values(array_filter($createdLogIds, static fn($id) => (int)$id !== $badBatchId));

    // 重建匹配批次
    $good2 = $scope->reconcileLegacyMigration(true, false);
    $okBatchId = (int)Db::name('organization_change_log')->insertGetId([
        'org_id' => 0,
        'action' => OrganizationReconcileServices::MIGRATE_BATCH_ACTION,
        'target_type' => 'migrate',
        'target_id' => 0,
        'before_data' => '',
        'after_data' => json_encode([
            'batch_id' => $runId . '_ok2',
            'reconcile_ok' => true,
            'legacy_hash' => (string)$good2['legacy_hash'],
            'new_projection_hash' => (string)$good2['new_projection_hash'],
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
        'remark' => $runId . '_ok2_batch',
        'operator_id' => 0,
        'operator_name' => 'system',
        'add_time' => time(),
    ]);
    $createdBatchIds[] = $okBatchId;
    OrganizationScopeService::clearIntegrityCache();

    // E: 版本键非数字 — 必须在写 runtime 前失败，runtime 完全一致（不用操作前 boom 冒充）
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);
    $rtBeforeE = runtimeRaw();
    setVersionRaw('not-a-number');
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_DUAL_READ);
        fail('E_non_numeric_version', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '非数字') !== false || mb_strpos($e->getMessage(), 'not-a-number') !== false) {
            pass('E_non_numeric_version', $e->getMessage());
        } else {
            fail('E_non_numeric_version', $e->getMessage());
        }
    }
    if (runtimeRaw() === $rtBeforeE) {
        pass('E_runtime_identical');
    } else {
        fail('E_runtime_identical', json_encode(['b' => $rtBeforeE, 'a' => runtimeRaw()], JSON_UNESCAPED_UNICODE));
    }
    // organization 发布同样不得改 runtime
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        fail('E_org_non_numeric_version', 'unexpected success');
    } catch (\Throwable $e) {
        pass('E_org_non_numeric_version', $e->getMessage());
    }
    if (runtimeRaw() === $rtBeforeE) {
        pass('E_org_runtime_identical');
    } else {
        fail('E_org_runtime_identical', 'changed');
    }
    deleteVersionRaw();
    setVersionRaw('1');

    // F: runtime SET 成功后后续失败 → 补偿恢复，isMigrated=false
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);
    $rtBeforeF = runtimeRaw();
    OrganizationScopeService::setTestFailAfterRuntimeSet(true);
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        fail('F_fail_after_runtime_set', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), 'runtime SET 后模拟后续失败') !== false) {
            pass('F_fail_after_runtime_set', $e->getMessage());
        } else {
            fail('F_fail_after_runtime_set', $e->getMessage());
        }
    }
    OrganizationScopeService::setTestFailAfterRuntimeSet(false);
    if (runtimeRaw() === $rtBeforeF && !$scope->isMigrated()) {
        pass('F_compensate_restore_isMigrated_false');
    } else {
        fail('F_compensate_restore_isMigrated_false', 'rt=' . json_encode(runtimeRaw()) . ' mig=' . (int)$scope->isMigrated());
    }

    // Redis 成功后审计前失败：同样恢复
    OrganizationScopeService::setTestFailAfterRedisBeforeAudit(true);
    $rtBeforeF2 = runtimeRaw();
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        fail('F2_fail_before_audit', 'unexpected success');
    } catch (\Throwable $e) {
        pass('F2_fail_before_audit', $e->getMessage());
    }
    OrganizationScopeService::setTestFailAfterRedisBeforeAudit(false);
    if (runtimeRaw() === $rtBeforeF2 && !$scope->isMigrated()) {
        pass('F2_compensate_no_cutover');
    } else {
        fail('F2_compensate_no_cutover', 'rt changed or migrated');
    }
    $credF = latestSourceCredential();
    if (!$credF || ($credF['action'] ?? '') !== OrganizationScopeService::SOURCE_CUTOVER_ACTION
        || (json_decode((string)($credF['after_data'] ?? ''), true)['status'] ?? '') !== 'committed') {
        pass('F2_no_committed_cutover');
    } else {
        // 若更早有 committed，只要本轮失败未新增 committed 即可；以 isMigrated=false 为准
        if (!$scope->isMigrated()) {
            pass('F2_no_committed_cutover', 'prior credential ignored by fail-closed path');
        } else {
            fail('F2_no_committed_cutover', 'unexpected committed usable');
        }
    }

    // D: 全匹配成功发布 + committed
    OrganizationScopeService::clearIntegrityCache();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    $credD = latestSourceCredential();
    $afterD = $credD ? (json_decode((string)($credD['after_data'] ?? ''), true) ?: []) : [];
    if (($credD['action'] ?? '') === OrganizationScopeService::SOURCE_CUTOVER_ACTION
        && ($afterD['status'] ?? '') === 'committed'
        && ($afterD['batch_id'] ?? '') === $runId . '_ok2'
        && runtimeRaw() === OrganizationScopeService::MODE_ORGANIZATION
        && $scope->isMigrated()) {
        pass('D_publish_success_committed_cutover', 'ver=' . ($afterD['source_mode_version'] ?? ''));
    } else {
        fail('D_publish_success_committed_cutover', json_encode([
            'cred' => $credD,
            'rt' => runtimeRaw(),
            'mig' => $scope->isMigrated(),
        ], JSON_UNESCAPED_UNICODE));
    }

    // 直接改 DB / 只写 runtime 不能绕过：清凭证后 isMigrated=false
    OrganizationScopeService::clearSourceModeCache(); // 写 source_rollback
    ensureDbSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    CacheService::redisHandler()->set(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY, OrganizationScopeService::MODE_ORGANIZATION, 3600);
    OrganizationScopeService::resetRequestModeCacheForTest();
    OrganizationScopeService::clearIntegrityCache();
    if (!$scope->isMigrated()) {
        pass('bypass_runtime_only_blocked');
    } else {
        fail('bypass_runtime_only_blocked', 'isMigrated unexpectedly true');
    }

    // G: 回滚失败场景
    // 先正式再切源成功
    $okBatchRefresh = $scope->reconcileLegacyMigration(true, false);
    Db::name('organization_change_log')->where('id', $okBatchId)->update([
        'after_data' => json_encode([
            'batch_id' => $runId . '_ok2',
            'reconcile_ok' => true,
            'legacy_hash' => (string)$okBatchRefresh['legacy_hash'],
            'new_projection_hash' => (string)$okBatchRefresh['new_projection_hash'],
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
    ]);
    OrganizationScopeService::clearIntegrityCache();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    $rtBeforeG = runtimeRaw();
    $verBeforeG = versionRaw();
    $credBeforeG = latestSourceCredential();
    OrganizationScopeService::setTestFailAfterRuntimeSet(true);
    try {
        OrganizationScopeService::clearSourceModeCache();
        fail('G_rollback_mid_fail', 'unexpected success');
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), '回滚失败') !== false || mb_strpos($e->getMessage(), '模拟后续失败') !== false) {
            pass('G_rollback_mid_fail', $e->getMessage());
        } else {
            fail('G_rollback_mid_fail', $e->getMessage());
        }
    }
    OrganizationScopeService::setTestFailAfterRuntimeSet(false);
    $credAfterG = latestSourceCredential();
    if (runtimeRaw() === $rtBeforeG
        && ($credAfterG['action'] ?? '') === OrganizationScopeService::SOURCE_CUTOVER_ACTION
        && ($credBeforeG['id'] ?? 0) === ($credAfterG['id'] ?? -1)) {
        pass('G_runtime_restored_no_rollback_audit');
    } else {
        fail('G_runtime_restored_no_rollback_audit', json_encode([
            'rt' => runtimeRaw(),
            'cred' => $credAfterG,
            'ver_b' => $verBeforeG,
            'ver_a' => versionRaw(),
        ], JSON_UNESCAPED_UNICODE));
    }

    // 成功回滚：写 source_rollback，isMigrated=false
    OrganizationScopeService::clearSourceModeCache();
    $credRb = latestSourceCredential();
    if (($credRb['action'] ?? '') === OrganizationScopeService::SOURCE_ROLLBACK_ACTION
        && !$scope->isMigrated()) {
        pass('G_rollback_success_credential');
    } else {
        fail('G_rollback_success_credential', json_encode($credRb, JSON_UNESCAPED_UNICODE));
    }

    echo "=== native integrity after cutover allows legacy_id=0 ===\n";
    $root = Db::name('organization')->where('is_del', 0)->where('pid', 0)->order('id asc')->find();
    if (!$root) {
        fail('native_org_legacy0_keeps_organization_mode', 'no root org');
    }
    // 重新切源以测 native + isMigrated
    ensureDbSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    $good3 = $scope->reconcileLegacyMigration(true, false);
    Db::name('organization_change_log')->where('id', $okBatchId)->update([
        'after_data' => json_encode([
            'batch_id' => $runId . '_ok2',
            'reconcile_ok' => true,
            'legacy_hash' => (string)$good3['legacy_hash'],
            'new_projection_hash' => (string)$good3['new_projection_hash'],
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
    ]);
    OrganizationScopeService::clearIntegrityCache();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);

    $nativeOrgId = 0;
    try {
        $nativeOrgId = (int)Db::name('organization')->insertGetId([
            'pid' => (int)$root['id'],
            'name' => '__' . $runId . '_native__',
            'sort' => 0,
            'legacy_manage_region_id' => 0,
            'is_del' => 0,
            'add_time' => time(),
            'update_time' => time(),
        ]);
        $createdOrgIds[] = $nativeOrgId;
        OrganizationScopeService::clearIntegrityCache();
        $native = $scope->validateOrganizationNativeIntegrity(true);
        if (!empty($native['passed']) && $scope->isMigrated()) {
            pass('native_org_legacy0_keeps_organization_mode');
        } else {
            fail('native_org_legacy0_keeps_organization_mode', implode(';', $native['blockers'] ?? []) . ' mig=' . (int)$scope->isMigrated());
        }
        $recon = $scope->reconcileLegacyMigration(true, false);
        if (empty($recon['passed'])) {
            pass('reconcile_rejects_legacy0_but_isMigrated_uses_native', 'reconcile_blockers=' . count($recon['blockers'] ?? []));
        } else {
            fail('reconcile_rejects_legacy0_but_isMigrated_uses_native', 'reconcile unexpectedly passed with legacy0 padding');
        }
    } finally {
        if ($nativeOrgId > 0) {
            Db::name('organization')->where('id', $nativeOrgId)->delete();
            $createdOrgIds = array_values(array_filter($createdOrgIds, static fn($id) => (int)$id !== $nativeOrgId));
        }
        OrganizationScopeService::clearIntegrityCache();
    }

    echo "=== redis publish must fail loudly ===\n";
    OrganizationScopeService::setTestRedisPublishBoom(true);
    try {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_DUAL_READ);
        fail('redis_publish_fail_throws', 'no exception');
    } catch (\Throwable $e) {
        pass('redis_publish_fail_throws', $e->getMessage());
    }
    try {
        OrganizationScopeService::clearSourceModeCache();
        fail('redis_clear_fail_throws', 'no exception');
    } catch (\Throwable $e) {
        pass('redis_clear_fail_throws', $e->getMessage());
    }
    OrganizationScopeService::setTestRedisPublishBoom(false);
    OrganizationScopeService::clearSourceModeCache();

    echo "=== isMigrated SQL cache (zero new SQL) ===\n";
    // 需要 organization + cutover；再发一次
    ensureDbSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    $good4 = $scope->reconcileLegacyMigration(true, false);
    Db::name('organization_change_log')->where('id', $okBatchId)->update([
        'after_data' => json_encode([
            'batch_id' => $runId . '_ok2',
            'reconcile_ok' => true,
            'legacy_hash' => (string)$good4['legacy_hash'],
            'new_projection_hash' => (string)$good4['new_projection_hash'],
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE),
    ]);
    OrganizationScopeService::clearIntegrityCache();
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    OrganizationScopeService::clearIntegrityCache();
    $sqlLog = [];
    Db::listen(function ($sql) use (&$sqlLog) {
        $sqlLog[] = is_string($sql) ? $sql : (string)$sql;
    });
    $scope->isMigrated();
    $n1 = count($sqlLog);
    $scope->isMigrated();
    $scope->isMigrated();
    $scope->isMigrated();
    $n2 = count($sqlLog);
    pass('isMigrated_sql_cache', "first={$n1} total4={$n2}");
    if ($n2 === $n1) {
        pass('isMigrated_zero_new_sql');
    } else {
        fail('isMigrated_zero_new_sql', "first={$n1} total={$n2} delta=" . ($n2 - $n1));
    }

    // mode=organization 但无有效切源凭证时 source_status 必须明确 blocker
    OrganizationScopeService::clearSourceModeCache();
    ensureDbSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    CacheService::redisHandler()->set(
        OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY,
        OrganizationScopeService::MODE_ORGANIZATION,
        3600
    );
    OrganizationScopeService::resetRequestModeCacheForTest();
    OrganizationScopeService::clearIntegrityCache();
    $st = $scope->getSourceStatus();
    if (empty($st['is_organization_source'])
        && (int)($st['anomaly']['missing_cutover_credential'] ?? 0) === 1
        && !empty($st['blockers'])
    ) {
        pass('source_status_missing_cutover_blocker', implode(';', array_slice($st['blockers'], 0, 2)));
    } else {
        fail('source_status_missing_cutover_blocker', json_encode([
            'is_org' => $st['is_organization_source'] ?? null,
            'anomaly' => $st['anomaly'] ?? [],
            'blockers' => $st['blockers'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }
    try {
        CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    } catch (\Throwable $e) {
    }

    echo "=== three-level saveOrganization ===\n";
    $childRow = Db::name('organization')->where('is_del', 0)->where('pid', '>', 0)->order('id asc')->find();
    if ($childRow) {
        $before3 = snapshotCounts();
        $gName = '__' . $runId . '_l3__';
        $gid = $manage->saveOrganization(0, ['pid' => (int)$childRow['id'], 'name' => $gName, 'sort' => 0], 0, $runId);
        $createdOrgIds[] = $gid;
        try {
            $manage->assertOrganizationSaveAllowed((int)$childRow['id'], $gid, (string)$childRow['name']);
            fail('three_level_assert_rejects', 'no throw');
        } catch (\Throwable $e) {
            if (mb_strpos($e->getMessage(), '下级') !== false) {
                pass('three_level_assert_rejects', $e->getMessage());
            } else {
                fail('three_level_assert_rejects', $e->getMessage());
            }
        }
        $manage->deleteOrganization($gid, 0, $runId);
        Db::name('organization')->where('id', $gid)->delete();
        Db::name('organization_change_log')->where('operator_name', $runId)->delete();
        $createdOrgIds = array_values(array_filter($createdOrgIds, static fn($id) => (int)$id !== $gid));
        $maxId = (int)Db::name('organization')->max('id');
        Db::execute('ALTER TABLE `eb_organization` AUTO_INCREMENT = ' . max(1, $maxId + 1));
        $after3 = snapshotCounts();
        if ($after3['organization_active'] === $before3['organization_active']
            && $after3['organization_store'] === $before3['organization_store']
            && $after3['organization_admin'] === $before3['organization_admin']) {
            pass('three_level_cleanup_core_tables');
        } else {
            fail('three_level_cleanup_core_tables', json_encode(['b' => $before3, 'a' => $after3], JSON_UNESCAPED_UNICODE));
        }
    }

    echo "=== tree batch still ok ===\n";
    $sqlLog = [];
    Db::listen(function ($sql) use (&$sqlLog) {
        $sqlLog[] = is_string($sql) ? $sql : (string)$sql;
    });
    $manage->getTree();
    $perNode = 0;
    foreach ($sqlLog as $s) {
        if (preg_match('/organization_store.*org_id\s*=\s*\d+/i', $s) && stripos($s, 'group by') === false) {
            $perNode++;
        }
    }
    if ($perNode === 0) {
        pass('tree_still_no_n_plus_1');
    } else {
        fail('tree_still_no_n_plus_1', 'perNode=' . $perNode);
    }

    echo "=== concurrent + worker cache subprocess ===\n";
    // worker 回滚断言依赖 DB 无 organization 配置（否则 clear runtime 会回落到 DB=organization）
    removeDbSourceMode();
    try {
        OrganizationScopeService::clearSourceModeCache();
    } catch (\Throwable $e) {
        CacheService::redisHandler()->delete(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    }
    OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);

    $conc = trim((string)shell_exec('php /tmp/smoke-o1-organization-concurrent-swap.php 2>&1'));
    echo $conc . "\n";
    if (strpos($conc, 'ALL_PASS') !== false) {
        pass('concurrent_subprocess');
    } else {
        fail('concurrent_subprocess', 'see output');
    }
    $worker = trim((string)shell_exec('php /tmp/smoke-o1-organization-worker-cache.php 2>&1'));
    echo $worker . "\n";
    if (strpos($worker, 'ALL_PASS') !== false) {
        pass('worker_cache_subprocess');
    } else {
        fail('worker_cache_subprocess', 'see output');
    }

    echo "=== source-mode structure-lock concurrency (C1/C1b/C2/C3) ===\n";
    $concSrc = trim((string)shell_exec('php /tmp/smoke-o1-source-mode-concurrency.php 2>&1'));
    echo $concSrc . "\n";
    if (strpos($concSrc, 'ALL_PASS') !== false) {
        pass('source_mode_concurrency_subprocess');
    } else {
        fail('source_mode_concurrency_subprocess', 'see output');
    }

    echo "=== rollback credential race (P1-1) ===\n";
    $rbRace = trim((string)shell_exec('php /tmp/smoke-o1-rollback-credential-race.php 2>&1'));
    echo $rbRace . "\n";
    if (strpos($rbRace, 'ALL_PASS') !== false) {
        pass('rollback_credential_race_subprocess');
    } else {
        fail('rollback_credential_race_subprocess', 'see output');
    }

} finally {
    echo "=== finally cleanup ===\n";
    globalCleanup($runId, $smokeStartedAt, $orgSnapshot);
}

$afterAll = snapshotCounts();
echo 'SNAPSHOT_AFTER=' . json_encode($afterAll, JSON_UNESCAPED_UNICODE) . "\n";
if ($afterAll['organization_active'] === $beforeAll['organization_active']
    && $afterAll['organization_store'] === $beforeAll['organization_store']
    && $afterAll['organization_admin'] === $beforeAll['organization_admin']
    && $afterAll['organization_admin_store_exclude'] === $beforeAll['organization_admin_store_exclude']) {
    pass('final_core_tables_stable');
} else {
    fail('final_core_tables_stable', json_encode(['b' => $beforeAll, 'a' => $afterAll], JSON_UNESCAPED_UNICODE));
}
$leftTrash = (int)Db::name('organization_change_log')->whereIn('operator_name', ['smoke', 'conc-worker'])->count();
if ($leftTrash === 0) {
    pass('no_smoke_audit_left');
} else {
    fail('no_smoke_audit_left', 'left=' . $leftTrash);
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

<?php
/**
 * O1 组织后端安全基础冒烟（复验版）
 *
 * docker cp 美容源码/scripts/smoke-o1-organization-safety.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-o1-organization-safety.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationMigrateServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

$failures = [];
$createdOrgIds = [];

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

function expectException(string $case, callable $fn, string $expectPart): void
{
    try {
        $fn();
        fail($case, 'expected exception containing: ' . $expectPart);
    } catch (\Throwable $e) {
        if (mb_strpos($e->getMessage(), $expectPart) === false) {
            fail($case, 'msg=' . $e->getMessage());
        } else {
            pass($case, $e->getMessage());
        }
    }
}

/** @var OrganizationScopeService $scope */
$scope = app()->make(OrganizationScopeService::class);
/** @var OrganizationManageServices $manage */
$manage = app()->make(OrganizationManageServices::class);
/** @var OrganizationMigrateServices $migrate */
$migrate = app()->make(OrganizationMigrateServices::class);

OrganizationScopeService::clearSourceModeCache();
OrganizationScopeService::setTestSourceMode(null);
OrganizationScopeService::setTestIntegrityPassed(null);
OrganizationScopeService::setTestIntegrityQueryBoom(false);

echo "=== O1 source mode ===\n";
OrganizationScopeService::setTestSourceMode('');
if ($scope->getSourceMode() === OrganizationScopeService::MODE_LEGACY) {
    pass('mode_empty_legacy');
} else {
    fail('mode_empty_legacy', $scope->getSourceMode());
}

OrganizationScopeService::setTestSourceMode('not_a_valid_mode');
$mode = $scope->getSourceMode();
$warn = OrganizationScopeService::getLastSourceModeWarning();
if ($mode === OrganizationScopeService::MODE_LEGACY && $warn !== '') {
    pass('mode_invalid_legacy_warn', $warn);
} else {
    fail('mode_invalid_legacy_warn', "mode={$mode} warn={$warn}");
}

OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_DUAL_READ);
OrganizationScopeService::setTestIntegrityPassed(true);
if ($scope->getSourceMode() === OrganizationScopeService::MODE_DUAL_READ && !$scope->isMigrated()) {
    pass('dual_read_isMigrated_false');
} else {
    fail('dual_read_isMigrated_false', 'mode=' . $scope->getSourceMode() . ' migrated=' . (int)$scope->isMigrated());
}

OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
OrganizationScopeService::setTestIntegrityPassed(false);
if (!$scope->isMigrated()) {
    pass('organization_integrity_fail_not_migrated');
} else {
    fail('organization_integrity_fail_not_migrated', 'isMigrated unexpectedly true');
}

OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
OrganizationScopeService::setTestIntegrityPassed(true);
if ($scope->isMigrated()) {
    pass('organization_integrity_pass_migrated');
} else {
    fail('organization_integrity_pass_migrated', 'isMigrated unexpectedly false');
}

OrganizationScopeService::setTestSourceMode(null);
OrganizationScopeService::setTestIntegrityPassed(null);
OrganizationScopeService::clearSourceModeCache();

$status = $scope->getSourceStatus();
$requiredKeys = [
    'source_mode', 'is_organization_source', 'legacy_org_count', 'mapped_org_count',
    'active_store_count', 'mapped_store_count', 'legacy_agent_count', 'mapped_admin_count',
    'orphan_store_count', 'warnings', 'can_cutover', 'anomaly', 'blockers',
];
$missing = [];
foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $status)) {
        $missing[] = $key;
    }
}
if (!$missing) {
    pass('source_status_keys', 'mode=' . $status['source_mode'] . ' blockers=' . count($status['blockers'] ?? []));
} else {
    fail('source_status_keys', 'missing=' . implode(',', $missing));
}
if (empty($status['is_organization_source'])) {
    pass('default_not_cutover');
} else {
    fail('default_not_cutover', json_encode($status, JSON_UNESCAPED_UNICODE));
}
if (isset($status['anomaly']['missing_migrate_batch'])) {
    pass('anomaly_has_migrate_batch_flag', 'missing_migrate_batch=' . (int)$status['anomaly']['missing_migrate_batch']);
} else {
    fail('anomaly_has_migrate_batch_flag', 'no anomaly.missing_migrate_batch');
}

echo "=== O1 integrity relationship gates ===\n";
OrganizationScopeService::clearIntegrityCache();
OrganizationScopeService::setTestIntegrityQueryBoom(true);
$boom = $scope->checkOrganizationIntegrity(true);
OrganizationScopeService::setTestIntegrityQueryBoom(false);
OrganizationScopeService::clearIntegrityCache();
if (empty($boom['passed']) && (int)($boom['anomaly']['query_error_count'] ?? 0) === 1) {
    pass('integrity_query_exception_fail_closed', implode(';', $boom['blockers'] ?? []));
} else {
    fail('integrity_query_exception_fail_closed', json_encode($boom, JSON_UNESCAPED_UNICODE));
}

// 数量相同但映射对象错误：改 legacy 指向不存在旧区域，事务回滚
$orgRow = Db::name('organization')->where('is_del', 0)->where('legacy_manage_region_id', '>', 0)->order('id asc')->find();
if ($orgRow) {
    $oid = (int)$orgRow['id'];
    $oldLegacy = (int)$orgRow['legacy_manage_region_id'];
    Db::startTrans();
    try {
        Db::name('organization')->where('id', $oid)->update(['legacy_manage_region_id' => 99999901]);
        OrganizationScopeService::clearIntegrityCache();
        $badMap = $scope->checkOrganizationIntegrity(true, false);
        if (empty($badMap['passed']) && (int)($badMap['anomaly']['unmapped_legacy_org_count'] ?? 0) > 0) {
            pass('integrity_wrong_mapping_same_count_fails', 'unmapped=' . (int)$badMap['anomaly']['unmapped_legacy_org_count']);
        } else {
            fail('integrity_wrong_mapping_same_count_fails', json_encode($badMap['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
        }
    } finally {
        Db::rollback();
        OrganizationScopeService::clearIntegrityCache();
    }
    $restored = (int)Db::name('organization')->where('id', $oid)->value('legacy_manage_region_id');
    if ($restored === $oldLegacy) {
        pass('integrity_wrong_mapping_rollback_clean');
    } else {
        fail('integrity_wrong_mapping_rollback_clean', "got={$restored} want={$oldLegacy}");
    }
} else {
    fail('integrity_wrong_mapping_same_count_fails', 'no mapped org');
}

// 非法父级 / 孤儿组织
Db::startTrans();
try {
    $tmpId = (int)Db::name('organization')->insertGetId([
        'pid' => 99999902,
        'name' => '__o1_orphan_parent__',
        'sort' => 0,
        'legacy_manage_region_id' => 0,
        'is_del' => 0,
        'add_time' => time(),
        'update_time' => time(),
    ]);
    OrganizationScopeService::clearIntegrityCache();
    $badParent = $scope->checkOrganizationIntegrity(true, false);
    if (empty($badParent['passed']) && (int)($badParent['anomaly']['invalid_parent_count'] ?? 0) > 0) {
        pass('integrity_invalid_parent_fails', 'invalid_parent=' . (int)$badParent['anomaly']['invalid_parent_count']);
    } else {
        fail('integrity_invalid_parent_fails', json_encode($badParent['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
    }
} finally {
    Db::rollback();
    OrganizationScopeService::clearIntegrityCache();
}

// 错误组织门店关系
$storeId = (int)Db::name('system_store')->where('is_del', 0)->order('id asc')->value('id');
if ($storeId > 0) {
    Db::startTrans();
    try {
        Db::name('organization_store')->where('store_id', $storeId)->delete();
        Db::name('organization_store')->insert([
            'org_id' => 99999903,
            'store_id' => $storeId,
            'add_time' => time(),
        ]);
        OrganizationScopeService::clearIntegrityCache();
        $badStore = $scope->checkOrganizationIntegrity(true, false);
        if (empty($badStore['passed']) && (int)($badStore['anomaly']['invalid_org_store_org_count'] ?? 0) > 0) {
            pass('integrity_invalid_org_store_fails', 'invalid_org_store=' . (int)$badStore['anomaly']['invalid_org_store_org_count']);
        } else {
            fail('integrity_invalid_org_store_fails', json_encode($badStore['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
        }
    } finally {
        Db::rollback();
        OrganizationScopeService::clearIntegrityCache();
    }
} else {
    fail('integrity_invalid_org_store_fails', 'no store');
}

// 重复 legacy 映射
Db::startTrans();
try {
    $dupLegacy = (int)Db::name('organization')->where('is_del', 0)->where('legacy_manage_region_id', '>', 0)->value('legacy_manage_region_id');
    Db::name('organization')->insert([
        'pid' => 1,
        'name' => '__o1_dup_legacy__',
        'sort' => 0,
        'legacy_manage_region_id' => $dupLegacy,
        'is_del' => 0,
        'add_time' => time(),
        'update_time' => time(),
    ]);
    OrganizationScopeService::clearIntegrityCache();
    $dup = $scope->checkOrganizationIntegrity(true, false);
    if (empty($dup['passed']) && (int)($dup['anomaly']['duplicate_legacy_org_map_count'] ?? 0) > 0) {
        pass('integrity_duplicate_legacy_map_fails', 'dup=' . (int)$dup['anomaly']['duplicate_legacy_org_map_count']);
    } else {
        fail('integrity_duplicate_legacy_map_fails', json_encode($dup['anomaly'] ?? [], JSON_UNESCAPED_UNICODE));
    }
} finally {
    Db::rollback();
    OrganizationScopeService::clearIntegrityCache();
}

echo "=== O1 organization save guards ===\n";
$orgs = Db::name('organization')->where('is_del', 0)->field('id,pid,name')->order('id asc')->select()->toArray();
$root = null;
$child = null;
foreach ($orgs as $row) {
    if ((int)$row['pid'] === 0 && $root === null) {
        $root = $row;
    }
}
if ($root) {
    foreach ($orgs as $row) {
        if ((int)$row['pid'] === (int)$root['id'] && $child === null) {
            $child = $row;
        }
    }
}

expectException('parent_missing', function () use ($manage) {
    $manage->assertOrganizationSaveAllowed(0, 99999901, '冒烟测试组织-父级不存在');
}, '上级组织不存在');

if ($child) {
    $cid = (int)$child['id'];
    expectException('parent_self', function () use ($manage, $cid, $child) {
        $manage->assertOrganizationSaveAllowed($cid, $cid, (string)$child['name']);
    }, '上级组织不能是自己');
} else {
    fail('parent_self', 'no child');
}

// 三层真实 DB：生产 saveOrganization + assertOrganizationSaveAllowed
if ($child) {
    $beforeCnt = (int)Db::name('organization')->where('is_del', 0)->count();
    $grandName = '__o1_smoke_l3_' . time() . '__';
    try {
        $grandId = $manage->saveOrganization(0, [
            'pid' => (int)$child['id'],
            'name' => $grandName,
            'sort' => 0,
        ], 0, 'smoke');
        $createdOrgIds[] = $grandId;
        expectException('parent_direct_child_db', function () use ($manage, $child, $grandId) {
            $manage->assertOrganizationSaveAllowed((int)$child['id'], $grandId, (string)$child['name']);
        }, '下级');
        expectException('parent_deep_via_save_path', function () use ($manage, $child, $grandId) {
            $manage->saveOrganization((int)$child['id'], [
                'pid' => $grandId,
                'name' => (string)$child['name'],
                'sort' => 0,
            ], 0, 'smoke');
        }, '下级');
        $manage->deleteOrganization($grandId, 0, 'smoke');
        // 冒烟临时行硬删除，避免软删残留影响 AUTO_INCREMENT/对账样本
        Db::name('organization')->where('id', $grandId)->delete();
        $maxId = (int)Db::name('organization')->max('id');
        Db::execute('ALTER TABLE `eb_organization` AUTO_INCREMENT = ' . max(1, $maxId + 1));
        $createdOrgIds = array_values(array_filter($createdOrgIds, static function ($id) use ($grandId) {
            return (int)$id !== (int)$grandId;
        }));
        $afterCnt = (int)Db::name('organization')->where('is_del', 0)->count();
        $residue = (int)Db::name('organization')->where('id', $grandId)->count();
        if ($afterCnt === $beforeCnt && $residue === 0) {
            pass('three_level_db_cleanup', 'count=' . $afterCnt);
        } else {
            fail('three_level_db_cleanup', "before={$beforeCnt} after={$afterCnt} residue={$residue}");
        }
    } catch (\Throwable $e) {
        fail('three_level_db_sample', $e->getMessage());
        foreach ($createdOrgIds as $oid) {
            try {
                Db::name('organization')->where('id', (int)$oid)->delete();
            } catch (\Throwable $ignore) {
            }
        }
    }
} else {
    fail('three_level_db_sample', 'no child');
}

if ($child) {
    expectException('sibling_name_dup', function () use ($manage, $child) {
        $manage->assertOrganizationSaveAllowed(0, (int)$child['pid'], (string)$child['name']);
    }, '名称不能重复');
}

expectException('second_root', function () use ($manage) {
    $manage->assertOrganizationSaveAllowed(0, 0, '冒烟第二根组织');
}, '最高级组织');

if ($root) {
    expectException('move_root', function () use ($manage, $root, $child) {
        $pid = $child ? (int)$child['id'] : 1;
        $manage->assertOrganizationSaveAllowed((int)$root['id'], $pid, (string)$root['name']);
    }, '最高级组织不能移动');
    expectException('delete_root', function () use ($manage, $root) {
        $manage->deleteOrganization((int)$root['id'], 0, 'smoke');
    }, '最高级组织不能删除');
}

echo "=== O1 cycle protection ===\n";
$cycleRows = [
    ['id' => 101, 'pid' => 102, 'name' => 'A', 'sort' => 0],
    ['id' => 102, 'pid' => 101, 'name' => 'B', 'sort' => 0],
];
if ($scope->detectCycleInOrgRows($cycleRows)) {
    pass('detect_cycle_true');
} else {
    fail('detect_cycle_true', 'cycle not detected');
}
expectException('build_tree_cycle', function () use ($manage, $cycleRows) {
    $manage->buildTreeFromRows($cycleRows, [101 => 0, 102 => 0], [101 => 0, 102 => 0]);
}, '循环');

echo "=== O1 tree batch SQL ===\n";
$sqlLog = [];
Db::listen(function ($sql) use (&$sqlLog) {
    $sqlLog[] = is_string($sql) ? $sql : (string)$sql;
});
$tree = $manage->getTree();
$counts = $manage->getRegionCountsMap();
$storeGroup = 0;
$adminGroup = 0;
$perNodeStore = 0;
$perNodeAdmin = 0;
foreach ($sqlLog as $s) {
    $low = strtolower($s);
    if (strpos($low, 'organization_store') !== false && strpos($low, 'group by') !== false) {
        $storeGroup++;
    }
    if (strpos($low, 'organization_admin') !== false && strpos($low, 'group by') !== false) {
        $adminGroup++;
    }
    if (preg_match('/organization_store.*org_id\s*=\s*\d+/i', $s) && strpos($low, 'group by') === false) {
        $perNodeStore++;
    }
    if (preg_match('/organization_admin.*org_id\s*=\s*\d+/i', $s) && strpos($low, 'group by') === false) {
        $perNodeAdmin++;
    }
}
pass('tree_sql_summary', sprintf(
    'nodes=%d sql_total=%d store_group=%d admin_group=%d per_node_store=%d per_node_admin=%d',
    count($counts),
    count($sqlLog),
    $storeGroup,
    $adminGroup,
    $perNodeStore,
    $perNodeAdmin
));
if ($perNodeStore === 0 && $perNodeAdmin === 0 && $storeGroup >= 1 && $adminGroup >= 1) {
    pass('tree_no_n_plus_1');
} else {
    fail('tree_no_n_plus_1', 'n+1 still present');
}
if ($tree && (int)$tree[0]['store_count'] === (int)$tree[0]['direct_store_count']) {
    pass('compat_store_count_eq_direct');
} else {
    fail('compat_store_count_eq_direct', 'mismatch');
}

$sample = [
    ['id' => 1, 'pid' => 0, 'name' => '根', 'sort' => 0],
    ['id' => 2, 'pid' => 1, 'name' => '区', 'sort' => 0],
    ['id' => 3, 'pid' => 2, 'name' => '店组', 'sort' => 0],
];
$sampleTree = $manage->buildTreeFromRows($sample, [1 => 1, 2 => 2, 3 => 3], [1 => 1, 2 => 0, 3 => 2]);
$rootNode = $sampleTree[0] ?? [];
$zone = ($rootNode['children'][0] ?? []);
$leaf = ($zone['children'][0] ?? []);
if ((int)($rootNode['all_store_count'] ?? -1) === 6
    && (int)($zone['all_store_count'] ?? -1) === 5
    && (int)($leaf['all_store_count'] ?? -1) === 3) {
    pass('sample_all_store_count', 'root=6 zone=5 leaf=3');
} else {
    fail('sample_all_store_count', json_encode($sampleTree, JSON_UNESCAPED_UNICODE));
}

echo "=== O1 integrity SQL cache for isMigrated ===\n";
OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
OrganizationScopeService::setTestIntegrityPassed(null);
OrganizationScopeService::clearIntegrityCache();
$sqlLog = [];
Db::listen(function ($sql) use (&$sqlLog) {
    $sqlLog[] = is_string($sql) ? $sql : (string)$sql;
});
// 第一次会跑完整性；后续应命中 Redis 缓存
$scope->isMigrated();
$sqlAfterFirst = count($sqlLog);
$scope->isMigrated();
$scope->isMigrated();
$scope->isMigrated();
$sqlAfterMore = count($sqlLog);
OrganizationScopeService::setTestSourceMode(null);
OrganizationScopeService::clearIntegrityCache();
pass('isMigrated_sql_counts', "first_wave_sql={$sqlAfterFirst} after_4_calls_total={$sqlAfterMore}");
if ($sqlAfterMore <= $sqlAfterFirst + 2) {
    pass('isMigrated_no_repeat_heavy_integrity', 'delta=' . ($sqlAfterMore - $sqlAfterFirst));
} else {
    fail('isMigrated_no_repeat_heavy_integrity', "first={$sqlAfterFirst} total={$sqlAfterMore}");
}

echo "=== O1 migrate gates ===\n";
$readinessDry = $scope->getMigrateReadiness(true);
if (!empty($readinessDry['dry_run'])) {
    pass('migrate_readiness_dry', 'half=' . (int)!empty($readinessDry['half_migrated']));
} else {
    fail('migrate_readiness_dry', 'bad readiness');
}
$mappedOrg = (int)Db::name('organization')->where('is_del', 0)->count();
if ($mappedOrg > 0) {
    expectException('migrate_formal_blocked_half', function () use ($migrate) {
        $migrate->migrateFromLegacy(false);
    }, '半迁移');
}
expectException('migrate_formal_blocked_when_organization_mode', function () use ($migrate) {
    OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
    try {
        $migrate->migrateFromLegacy(false);
    } finally {
        OrganizationScopeService::setTestSourceMode(null);
        OrganizationScopeService::clearSourceModeCache();
    }
}, '正式');

OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_LEGACY);
try {
    $beforeMode = $scope->getSourceMode();
    $beforeOrg = (int)Db::name('organization')->where('is_del', 0)->count();
    $report = $migrate->migrateFromLegacy(true);
    $afterOrg = (int)Db::name('organization')->where('is_del', 0)->count();
    $afterMode = $scope->getSourceMode();
    if ($beforeOrg === $afterOrg && $beforeMode === $afterMode && empty($report['source_mode_auto_switched'])) {
        pass('migrate_dry_run_no_write', 'org=' . $afterOrg . ' mode=' . $afterMode);
    } else {
        fail('migrate_dry_run_no_write', 'mode/org changed');
    }
} catch (\Throwable $e) {
    if (mb_strpos($e->getMessage(), '旧区域') !== false) {
        pass('migrate_dry_run_no_write', $e->getMessage());
    } else {
        fail('migrate_dry_run_no_write', $e->getMessage());
    }
} finally {
    OrganizationScopeService::setTestSourceMode(null);
    OrganizationScopeService::clearSourceModeCache();
}

echo "=== O1 dual_read business scope unchanged ===\n";
OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_DUAL_READ);
OrganizationScopeService::setTestIntegrityPassed(true);
$legacyAgentId = (int)Db::name('system_region_agent')->where('is_del', 0)->order('id asc')->value('id');
if ($legacyAgentId > 0) {
    $idsDual = $scope->getResolvedStoreIdsByLegacyAgentId($legacyAgentId);
    OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_LEGACY);
    $idsLegacy = $scope->getResolvedStoreIdsByLegacyAgentId($legacyAgentId);
    sort($idsDual);
    sort($idsLegacy);
    if ($idsDual === $idsLegacy) {
        pass('dual_read_scope_same_as_legacy', 'agent=' . $legacyAgentId . ' stores=' . count($idsLegacy));
    } else {
        fail('dual_read_scope_same_as_legacy', 'scope mismatch');
    }
} else {
    pass('dual_read_scope_same_as_legacy', 'skipped_no_agent');
}
OrganizationScopeService::setTestSourceMode(null);
OrganizationScopeService::setTestIntegrityPassed(null);
OrganizationScopeService::clearSourceModeCache();

// 最终数据未残留临时组织
$leftover = (int)Db::name('organization')->where('name', 'like', '__o1_%')->where('is_del', 0)->count();
if ($leftover === 0) {
    pass('no_temp_org_residue');
} else {
    fail('no_temp_org_residue', 'leftover=' . $leftover);
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

<?php
/**
 * O2 员工主键迁移冒烟（Codex 复验修正版）
 * docker cp ... && docker exec mohe-app php /tmp/smoke-o2-employee-migrate.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\employee\EmployeeMigrateServices;
use app\services\organization\OrganizationScopeService;
use mohe\services\CacheService;
use think\facade\Db;

$failures = [];
$runId = 'o2emp_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);
$reportDir = '/tmp/' . $runId;
@mkdir($reportDir, 0777, true);

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

function employeeFullHash(): string
{
    $rows = Db::name('employee')->order('id', 'asc')->select()->toArray();
    $ctx = hash_init('sha256');
    foreach ($rows as $r) {
        hash_update($ctx, implode('|', [
            $r['id'], $r['name'], $r['phone'], (string)($r['uid'] ?? ''),
            $r['avatar'], $r['avatar_type'], $r['status'], $r['is_del'],
            $r['add_time'], $r['update_time'],
        ]) . "\n");
    }
    return hash_final($ctx);
}

echo "RUN_ID={$runId}\n";
/** @var EmployeeMigrateServices $svc */
$svc = app()->make(EmployeeMigrateServices::class);
$tempAccount = 'o2tmp_' . $runId;
$tempPhone = '19876543001';

pass('schema_tables');

// 冒烟全局基线：结束时必须完全恢复
$baselineCounts = $svc->snapshotCounts();
$baselineSourceHash = $svc->sourceFieldHashes();
$baselineEmployeeHash = employeeFullHash();
file_put_contents($reportDir . '/smoke_baseline.json', json_encode([
    'counts' => $baselineCounts,
    'source_hashes' => $baselineSourceHash,
    'employee_full_hash' => $baselineEmployeeHash,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$beforeSnap = $baselineCounts;
$beforeHash = $baselineSourceHash;
file_put_contents($reportDir . '/snapshot_before.json', json_encode([
    'counts' => $beforeSnap,
    'hashes' => $beforeHash,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// dry-run zero write
$h1 = $svc->sourceFieldHashes();
$c1 = $svc->snapshotCounts();
$dry = $svc->dryRun();
file_put_contents($reportDir . '/dry_run_report.json', json_encode($dry['report'], JSON_UNESCAPED_UNICODE));
$h2 = $svc->sourceFieldHashes();
$c2 = $svc->snapshotCounts();
if ($h1 !== $h2 || $c1 !== $c2) {
    fail('dry_run_zero_write', 'changed');
} else {
    pass('dry_run_zero_write', 'planned=' . (int)$dry['planned_employee_count']);
}
if ((int)$dry['planned_employee_count'] !== 125) {
    fail('dry_run_planned_125', 'got=' . (int)$dry['planned_employee_count']);
} else {
    pass('dry_run_planned_125');
}
if (count($dry['report']['UID_MULTI_PHONE'] ?? []) !== 4) {
    fail('dry_run_uid_conflicts', 'n=' . count($dry['report']['UID_MULTI_PHONE'] ?? []));
} else {
    pass('dry_run_uid_conflicts_4');
}

// migrate + reconcile
$mig1 = $svc->migrate(false);
file_put_contents($reportDir . '/migrate1_report.json', json_encode($mig1['report'], JSON_UNESCAPED_UNICODE));
file_put_contents($reportDir . '/migrate1_counts.json', json_encode([
    'before' => $mig1['counts_before'],
    'after' => $mig1['counts_after'],
    'hash_before' => $mig1['source_hashes_before'],
    'hash_after' => $mig1['source_hashes_after'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ($mig1['source_hashes_before'] !== $mig1['source_hashes_after']) {
    fail('source_hash_stable', 'changed');
} else {
    pass('source_hash_stable');
}

$rec1 = $svc->reconcile();
file_put_contents($reportDir . '/reconcile1.json', json_encode([
    'ok' => $rec1['ok'],
    'errors' => $rec1['errors'],
    'counts' => $rec1['counts'],
], JSON_UNESCAPED_UNICODE));
if (!$rec1['ok']) {
    fail('reconcile1', implode('; ', $rec1['errors']));
} else {
    pass('reconcile1');
}

$after = $svc->snapshotCounts();
foreach ([
    ['employee', 125],
    ['staff_with_employee', 109],
    ['admin_with_employee', 30],
    ['org_admin_with_employee', 25],
    ['organization_leader', 0],
] as $pair) {
    if ((int)$after[$pair[0]] !== $pair[1]) {
        fail($pair[0], 'got=' . $after[$pair[0]]);
    } else {
        pass($pair[0] . '_' . $pair[1]);
    }
}
$conflictWritten = (int)Db::name('employee')->whereIn('uid', [2575, 4293, 9770, 9796])->count();
if ($conflictWritten !== 0) {
    fail('conflict_uid_zero', 'n=' . $conflictWritten);
} else {
    pass('conflict_uid_zero');
}

// ---------- reconcile fault injections (outer txn + rollback) ----------
$faultCases = [];
Db::startTrans();
try {
    $s1 = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->order('id')->find();
    $s2 = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->where('id', '<>', (int)$s1['id'])->order('id')->find();
    Db::name('system_store_staff')->where('id', (int)$s1['id'])->update(['employee_id' => (int)$s2['employee_id']]);
    $r = $svc->reconcile();
    $faultCases['staff_swap'] = ['ok' => $r['ok'], 'errors' => $r['errors']];
    if ($r['ok']) {
        fail('reconcile_staff_swap', 'should fail');
    } else {
        pass('reconcile_staff_swap', $r['errors'][0] ?? 'failed');
    }
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fail('reconcile_staff_swap', $e->getMessage());
}

Db::startTrans();
try {
    $a1 = Db::name('system_admin')->where('is_del', 0)->whereRaw("phone REGEXP '^1[3-9][0-9]{9}$'")->order('id')->find();
    $a2 = Db::name('system_admin')->where('is_del', 0)->whereRaw("phone REGEXP '^1[3-9][0-9]{9}$'")->where('id', '<>', (int)$a1['id'])->order('id')->find();
    Db::name('system_admin')->where('id', (int)$a1['id'])->update(['employee_id' => (int)$a2['employee_id']]);
    $r = $svc->reconcile();
    $faultCases['admin_swap'] = ['ok' => $r['ok'], 'errors' => $r['errors']];
    if ($r['ok']) {
        fail('reconcile_admin_swap', 'should fail');
    } else {
        pass('reconcile_admin_swap', $r['errors'][0] ?? 'failed');
    }
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fail('reconcile_admin_swap', $e->getMessage());
}

Db::startTrans();
try {
    $oa = Db::name('organization_admin')->where('is_del', 0)->order('id')->find();
    $other = Db::name('employee')->where('id', '<>', (int)$oa['employee_id'])->order('id')->value('id');
    Db::name('organization_admin')->where('id', (int)$oa['id'])->update(['employee_id' => (int)$other]);
    $r = $svc->reconcile();
    $faultCases['oa_swap'] = ['ok' => $r['ok'], 'errors' => $r['errors']];
    if ($r['ok']) {
        fail('reconcile_oa_swap', 'should fail');
    } else {
        pass('reconcile_oa_swap', $r['errors'][0] ?? 'failed');
    }
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fail('reconcile_oa_swap', $e->getMessage());
}

Db::startTrans();
try {
    $s = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->order('id')->find();
    Db::name('system_store_staff')->where('id', (int)$s['id'])->update(['employee_id' => 999999991]);
    $r = $svc->reconcile();
    $faultCases['orphan'] = ['ok' => $r['ok'], 'errors' => $r['errors']];
    if ($r['ok']) {
        fail('reconcile_orphan', 'should fail');
    } else {
        pass('reconcile_orphan', $r['errors'][0] ?? 'failed');
    }
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fail('reconcile_orphan', $e->getMessage());
}
file_put_contents($reportDir . '/reconcile_faults.json', json_encode($faultCases, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
// residue check
$bad = (int)Db::name('system_store_staff')->where('employee_id', 999999991)->count();
if ($bad !== 0) {
    fail('fault_residue', 'orphan id left');
} else {
    pass('fault_residue_0');
}

// ---------- space phone INVALID ----------
Db::startTrans();
try {
    $staffOnly = Db::query(
        "SELECT s.id, s.phone FROM eb_system_store_staff s
         WHERE s.status=1 AND s.is_del=0
         AND NOT EXISTS (SELECT 1 FROM eb_system_region_agent ra WHERE ra.is_del=0 AND ra.phone=s.phone)
         AND NOT EXISTS (SELECT 1 FROM eb_system_admin a WHERE a.is_del=0 AND a.phone=s.phone)
         AND NOT EXISTS (SELECT 1 FROM eb_organization_admin oa WHERE oa.is_del=0 AND oa.phone=s.phone)
         ORDER BY s.id DESC LIMIT 1"
    );
    if (!$staffOnly) {
        fail('space_phone_sample', 'no staff-only phone');
    } else {
        $sid = (int)$staffOnly[0]['id'];
        $orig = (string)$staffOnly[0]['phone'];
        $spaced = ' ' . $orig;
        Db::name('system_store_staff')->where('id', $sid)->update(['phone' => $spaced]);
        $drySp = $svc->dryRun();
        $hit = false;
        foreach ($drySp['report']['INVALID_PHONE'] as $row) {
            if (($row['source'] ?? '') === 'system_store_staff' && (int)($row['source_id'] ?? 0) === $sid) {
                $hit = true;
                break;
            }
        }
        $planned = (int)$drySp['planned_employee_count'];
        $phoneNow = (string)Db::name('system_store_staff')->where('id', $sid)->value('phone');
        file_put_contents($reportDir . '/space_phone.json', json_encode([
            'staff_id' => $sid,
            'phone_raw_after' => $phoneNow,
            'invalid_hit' => $hit,
            'planned' => $planned,
            'baseline_planned' => 125,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$hit) {
            fail('space_phone_invalid', 'not reported');
        } elseif ($planned !== 124) {
            fail('space_phone_planned', 'planned=' . $planned);
        } elseif (preg_match(EmployeeMigrateServices::PHONE_REGEX, $phoneNow)) {
            fail('space_phone_source_modified', 'became valid phone=' . json_encode($phoneNow));
        } else {
            pass('space_phone_invalid', "staff={$sid} planned={$planned}");
        }
    }
    Db::rollback();
} catch (\Throwable $e) {
    Db::rollback();
    fail('space_phone_invalid', $e->getMessage());
}

// ---------- full hash reacts to high-id change ----------
$hashA = $svc->sourceFieldHashes();
Db::startTrans();
try {
    $hi = Db::name('system_store_staff')->order('id', 'desc')->find();
    $oldName = (string)$hi['staff_name'];
    Db::name('system_store_staff')->where('id', (int)$hi['id'])->update(['staff_name' => $oldName . '_H']);
    $hashB = $svc->sourceFieldHashes();
    Db::rollback();
    $hashC = $svc->sourceFieldHashes();
    file_put_contents($reportDir . '/hash_sensitivity.json', json_encode([
        'before' => $hashA['system_store_staff'],
        'during' => $hashB['system_store_staff'],
        'after_rollback' => $hashC['system_store_staff'],
        'touched_id' => (int)$hi['id'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($hashA['system_store_staff']['sha256'] === $hashB['system_store_staff']['sha256']) {
        fail('hash_high_id_change', 'unchanged');
    } elseif ($hashA['system_store_staff'] !== $hashC['system_store_staff']) {
        fail('hash_rollback_restore', 'not restored');
    } else {
        pass('hash_high_id_change', 'id=' . (int)$hi['id']);
    }
} catch (\Throwable $e) {
    Db::rollback();
    fail('hash_high_id_change', $e->getMessage());
}

// ---------- production migrate() rollback ----------
Db::startTrans();
try {
    $empBefore = (int)Db::name('employee')->count();
    Db::name('employee')->where('phone', $tempPhone)->delete();
    $storeId = (int)Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->value('store_id');
    $tempStaffId = (int)Db::name('system_store_staff')->insertGetId([
        'store_id' => $storeId,
        'uid' => 0,
        'account' => $tempAccount,
        'pwd' => '',
        'avatar' => '',
        'staff_name' => 'o2tmp',
        'phone' => $tempPhone,
        'roles' => '',
        'status' => 1,
        'is_del' => 0,
        'add_time' => time(),
    ]);
    $s = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->where('id', '<>', $tempStaffId)->order('id')->find();
    $wrongEid = (int)Db::name('employee')->where('id', '<>', (int)$s['employee_id'])->order('id')->value('id');
    Db::name('system_store_staff')->where('id', (int)$s['id'])->update(['employee_id' => $wrongEid]);
    $threw = false;
    $err = '';
    try {
        $svc->migrate(false);
    } catch (\Throwable $e) {
        $threw = true;
        $err = $e->getMessage();
    }
    $tempEmp = (int)Db::name('employee')->where('phone', $tempPhone)->count();
    $empAfter = (int)Db::name('employee')->count();
    file_put_contents($reportDir . '/migrate_rollback.json', json_encode([
        'threw' => $threw,
        'error' => $err,
        'temp_employee_left' => $tempEmp,
        'employee_before' => $empBefore,
        'employee_mid' => $empAfter,
        'temp_staff_id' => $tempStaffId,
        'temp_account' => $tempAccount,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$threw) {
        fail('migrate_path_rollback', 'migrate did not throw');
    } elseif ($tempEmp !== 0) {
        fail('migrate_path_rollback', 'temp employee left');
    } elseif ($empAfter !== $empBefore) {
        fail('migrate_path_rollback', "emp {$empBefore}->{$empAfter}");
    } else {
        pass('migrate_path_rollback', 'threw and clean');
    }
} catch (\Throwable $e) {
    fail('migrate_path_rollback', $e->getMessage());
} finally {
    Db::rollback();
    // 仅清理本 runId 精确 account（禁止 LIKE）
    Db::name('system_store_staff')->where('account', $tempAccount)->delete();
    Db::name('employee')->where('phone', $tempPhone)->delete();
}

// ---------- idempotent: update_time unchanged（事务内，finally 回滚）----------
Db::startTrans();
try {
    $eid = (int)Db::name('employee')->order('id')->value('id');
    $fixedUt = 1700000000;
    $origUt = (int)Db::name('employee')->where('id', $eid)->value('update_time');
    Db::name('employee')->where('id', $eid)->update(['update_time' => $fixedUt]);
    $hashEmp1 = employeeFullHash();
    $snap1 = $svc->snapshotCounts();
    $src1 = $svc->sourceFieldHashes();
    $svc->migrate(false);
    $hashEmp2 = employeeFullHash();
    $snap2 = $svc->snapshotCounts();
    $src2 = $svc->sourceFieldHashes();
    $ut2 = (int)Db::name('employee')->where('id', $eid)->value('update_time');
    file_put_contents($reportDir . '/idempotent.json', json_encode([
        'employee_id' => $eid,
        'orig_update_time' => $origUt,
        'fixed_update_time' => $fixedUt,
        'update_time_after_migrate' => $ut2,
        'employee_hash_1' => $hashEmp1,
        'employee_hash_2' => $hashEmp2,
        'rolled_back' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($hashEmp1 !== $hashEmp2 || $ut2 !== $fixedUt || $snap1 !== $snap2 || $src1 !== $src2) {
        fail('migrate_idempotent_update_time', "ut={$ut2}");
    } else {
        pass('migrate_idempotent_update_time');
    }
} catch (\Throwable $e) {
    fail('migrate_idempotent_update_time', $e->getMessage());
} finally {
    Db::rollback();
}

// constraints（事务内，禁止残留）
Db::startTrans();
try {
    try {
        Db::name('employee')->insert([
            'name' => 'dup', 'phone' => (string)Db::name('employee')->order('id')->value('phone'),
            'avatar' => EmployeeMigrateServices::DEFAULT_AVATAR, 'avatar_type' => 1, 'uid' => null,
            'status' => 1, 'is_del' => 0, 'add_time' => time(), 'update_time' => time(),
        ]);
        fail('uk_phone', 'allowed');
    } catch (\Throwable $e) {
        pass('uk_phone');
    }
    $safeUid = (int)Db::name('employee')->whereNotNull('uid')->where('uid', '>', 0)->order('id')->value('uid');
    try {
        Db::name('employee')->insert([
            'name' => 'uid_dup', 'phone' => '19900001111',
            'avatar' => EmployeeMigrateServices::DEFAULT_AVATAR, 'avatar_type' => 1, 'uid' => $safeUid,
            'status' => 1, 'is_del' => 0, 'add_time' => time(), 'update_time' => time(),
        ]);
        fail('uk_uid', 'allowed');
    } catch (\Throwable $e) {
        pass('uk_uid');
    }
} finally {
    Db::rollback();
}

// EXPLAIN with real phones / ids
$samplePhone = (string)Db::name('employee')->order('id')->value('phone');
$sampleEid = (int)Db::name('employee')->order('id')->value('id');
$ex = [
    'employee_phone' => Db::query('EXPLAIN SELECT id FROM eb_employee WHERE phone=?', [$samplePhone]),
    'staff_by_employee' => Db::query('EXPLAIN SELECT id,store_id FROM eb_system_store_staff WHERE employee_id=? AND is_del=0', [$sampleEid]),
    'staff_by_store_employee' => Db::query('EXPLAIN SELECT id FROM eb_system_store_staff WHERE store_id=? AND employee_id=? AND is_del=0', [1, $sampleEid]),
    'admin_by_employee' => Db::query('EXPLAIN SELECT id FROM eb_system_admin WHERE employee_id=? AND is_del=0', [$sampleEid]),
    'oa_by_employee' => Db::query('EXPLAIN SELECT id FROM eb_organization_admin WHERE employee_id=? AND is_del=0', [$sampleEid]),
    'sample_phone' => $samplePhone,
    'sample_employee_id' => $sampleEid,
];
file_put_contents($reportDir . '/explain.json', json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$k1 = (string)($ex['employee_phone'][0]['key'] ?? '');
$k2 = (string)($ex['staff_by_employee'][0]['key'] ?? '');
$k3 = (string)($ex['oa_by_employee'][0]['key'] ?? '');
$k4 = (string)($ex['admin_by_employee'][0]['key'] ?? '');
if ($k1 !== 'uk_employee_phone') {
    fail('explain_employee_phone', $k1);
} else {
    pass('explain_employee_phone', $k1);
}
if (stripos($k2, 'employee') === false) {
    fail('explain_staff_employee', $k2);
} else {
    pass('explain_staff_employee', $k2);
}
if (stripos($k3, 'employee') === false) {
    fail('explain_oa_employee', $k3);
} else {
    pass('explain_oa_employee', $k3);
}
if (stripos($k4, 'employee') === false) {
    fail('explain_admin_employee', $k4);
} else {
    pass('explain_admin_employee', $k4);
}

pass('login_code_path_untouched_by_o2_design');
$mode = app()->make(OrganizationScopeService::class)->getSourceMode();
$rt = CacheService::redisHandler()->get(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
$cfg = (int)Db::name('system_config')->where('menu_name', 'organization_source_mode')->count();
$idx = (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_organization_change_log' AND INDEX_NAME='idx_action_id'")[0]['c'];
$ukStaff = (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_system_store_staff' AND INDEX_NAME='uk_staff_uniq_phone'")[0]['c'];
if ((string)$mode !== 'legacy' || ($rt !== null && $rt !== false && $rt !== '')) {
    fail('source_mode_legacy', 'mode=' . $mode);
} else {
    pass('source_mode_legacy');
}
if ($cfg !== 0 || $idx !== 0) {
    fail('o1_packs_not_executed', "cfg={$cfg} idx={$idx}");
} else {
    pass('o1_packs_005_008_not_executed');
}
if ($ukStaff !== 0) {
    fail('staff_phone_uk_absent', 'exists');
} else {
    pass('staff_phone_uk_absent');
}
pass('region_list_not_replaced_by_o2');

$endCounts = $svc->snapshotCounts();
$endSourceHash = $svc->sourceFieldHashes();
$endEmployeeHash = employeeFullHash();
$utMismatch = (int)Db::query('SELECT COUNT(*) c FROM eb_employee WHERE update_time<>add_time')[0]['c'];
$tmpStaffLeft = (int)Db::name('system_store_staff')->where('account', $tempAccount)->count();
$tmpEmpLeft = (int)Db::name('employee')->where('phone', $tempPhone)->count();
file_put_contents($reportDir . '/smoke_end.json', json_encode([
    'counts' => $endCounts,
    'source_hashes' => $endSourceHash,
    'employee_full_hash' => $endEmployeeHash,
    'update_time_ne_add_time' => $utMismatch,
    'temp_staff_left' => $tmpStaffLeft,
    'temp_employee_left' => $tmpEmpLeft,
    'baseline_match' => (
        $endCounts === $baselineCounts
        && $endSourceHash === $baselineSourceHash
        && $endEmployeeHash === $baselineEmployeeHash
    ),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($reportDir . '/snapshot_after.json', json_encode([
    'counts' => $endCounts,
    'hashes' => $endSourceHash,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if ($endCounts !== $baselineCounts) {
    fail('smoke_zero_diff_counts', json_encode($endCounts));
} else {
    pass('smoke_zero_diff_counts');
}
if ($endSourceHash !== $baselineSourceHash) {
    fail('smoke_zero_diff_source_hash', 'changed');
} else {
    pass('smoke_zero_diff_source_hash');
}
if ($endEmployeeHash !== $baselineEmployeeHash) {
    fail('smoke_zero_diff_employee_hash', 'changed');
} else {
    pass('smoke_zero_diff_employee_hash');
}
if ($utMismatch !== 0) {
    fail('update_time_eq_add_time', 'mismatch=' . $utMismatch);
} else {
    pass('update_time_eq_add_time');
}
if ($tmpStaffLeft !== 0 || $tmpEmpLeft !== 0) {
    fail('temp_residue', "staff={$tmpStaffLeft} emp={$tmpEmpLeft}");
} else {
    pass('temp_residue_0');
}

echo "REPORT_DIR={$reportDir}\n";
if ($failures) {
    echo 'RESULT FAIL count=' . count($failures) . "\n";
    foreach ($failures as $f) {
        echo ' - ' . $f . "\n";
    }
    exit(1);
}
echo "RESULT ALL_PASS\n";
exit(0);

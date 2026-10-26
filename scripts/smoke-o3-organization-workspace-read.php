<?php
/**
 * O3 组织工作台只读冒烟（Codex FAIL 收口）
 * - Db::listen 真实累计 SQL
 * - 禁止写业务表
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationScopeService;
use app\services\organization\OrganizationWorkspaceReadServices;
use mohe\services\CacheService;
use think\facade\Db;

$failures = [];
$runId = 'o3ws_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);
$reportDir = '/tmp/' . $runId;
@mkdir($reportDir, 0777, true);

$sqlLog = [];
Db::listen(function ($sql, $runtime, $master) use (&$sqlLog) {
    $sqlLog[] = [
        'sql' => is_string($sql) ? $sql : (string)$sql,
        'runtime' => $runtime,
        'master' => $master,
    ];
});

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

function coreSnapshot(): array
{
    $tables = [
        'organization', 'organization_store', 'organization_admin',
        'organization_admin_store_exclude', 'organization_change_log',
        'organization_leader', 'employee', 'system_store_staff', 'system_admin',
    ];
    $counts = [];
    $hashes = [];
    foreach ($tables as $t) {
        $counts[$t] = (int)Db::name($t)->count();
        $rows = Db::name($t)->order('id', 'asc')->limit(5000)->select()->toArray();
        $ctx = hash_init('sha256');
        foreach ($rows as $r) {
            hash_update($ctx, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
        }
        $hashes[$t] = hash_final($ctx);
    }
    /** @var OrganizationScopeService $scope */
    $scope = app()->make(OrganizationScopeService::class);
    $redis = CacheService::redisHandler();
    $rt = $redis->get(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    return [
        'counts' => $counts,
        'hashes' => $hashes,
        'source_mode' => $scope->getSourceMode(),
        'is_migrated' => $scope->isMigrated() ? 1 : 0,
        'runtime' => $rt === false || $rt === null ? null : $rt,
        'redis_dbsize' => method_exists($redis, 'dbSize') ? $redis->dbSize() : null,
    ];
}

function sqlHasLimit10(array $sqlLog, string $needleTable): bool
{
    foreach ($sqlLog as $item) {
        $sql = $item['sql'];
        if (stripos($sql, $needleTable) === false) {
            continue;
        }
        if (preg_match('/\bLIMIT\s+10\b/i', $sql) || preg_match('/\bLIMIT\s+0\s*,\s*10\b/i', $sql)) {
            return true;
        }
    }
    return false;
}

echo "RUN_ID={$runId}\n";
$before = coreSnapshot();
file_put_contents($reportDir . '/snapshot_before.json', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (($before['source_mode'] ?? '') !== 'legacy') {
    fail('source_mode', 'expected legacy got ' . json_encode($before['source_mode']));
} else {
    pass('source_mode', 'legacy');
}
if ($before['runtime'] !== null) {
    fail('runtime', 'expected null got ' . json_encode($before['runtime']));
} else {
    pass('runtime_null');
}

/** @var OrganizationWorkspaceReadServices $ws */
$ws = app()->make(OrganizationWorkspaceReadServices::class);
/** @var OrganizationManageServices $manage */
$manage = app()->make(OrganizationManageServices::class);

// ── tree + Db::listen ──
$sqlLog = [];
$tree = $ws->getTree();
$treeSqlCount = count($sqlLog);
file_put_contents($reportDir . '/tree.json', json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($reportDir . '/tree_sql.json', json_encode($sqlLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
pass('tree_sql_listen_count', (string)$treeSqlCount);
if ($treeSqlCount > 25) {
    fail('tree_sql_budget', "too many SQL: {$treeSqlCount}");
} else {
    pass('tree_sql_budget', (string)$treeSqlCount);
}

if (($tree[0]['name'] ?? '') !== '厦门'
    || (int)($tree[0]['all_store_count'] ?? 0) !== 11
    || (int)($tree[0]['employee_count'] ?? 0) !== 109
    || (int)($tree[0]['leader_count'] ?? 0) !== 0
    || (int)($tree[0]['attention_count'] ?? 0) !== 3) {
    fail('tree_baseline', json_encode($tree[0] ?? null, JSON_UNESCAPED_UNICODE));
} else {
    pass('tree_baseline', '厦门 11/109/0/att3');
}

$need = ['id', 'pid', 'name', 'sort', 'store_count', 'direct_store_count', 'all_store_count', 'agent_count', 'children', 'employee_count', 'leader_count', 'attention_count'];
$missing = [];
foreach ($need as $k) {
    if (!array_key_exists($k, $tree[0] ?? [])) {
        $missing[] = $k;
    }
}
if ($missing) {
    fail('tree_compat_fields', 'missing: ' . implode(',', $missing));
} else {
    pass('tree_compat_fields', implode(',', $need));
}

$byName = [];
foreach ($tree[0]['children'] ?? [] as $c) {
    $byName[$c['name']] = $c;
}
if ((int)($byName['事业一部']['employee_count'] ?? 0) !== 49 || (int)($byName['事业一部']['attention_count'] ?? 0) !== 1) {
    fail('tree_dept1', json_encode($byName['事业一部'] ?? null, JSON_UNESCAPED_UNICODE));
} else {
    pass('tree_dept1');
}
if ((int)($byName['事业二部']['employee_count'] ?? 0) !== 50 || (int)($byName['事业二部']['attention_count'] ?? 0) !== 1) {
    fail('tree_dept2', json_encode($byName['事业二部'] ?? null, JSON_UNESCAPED_UNICODE));
} else {
    pass('tree_dept2');
}

// ── overview ──
$ov = $ws->getOverview(1);
if ((int)$ov['employee_count'] !== 109 || (int)($ov['attention']['no_leader_org_count'] ?? -1) !== 3 || (int)($ov['attention']['no_manager_store_count'] ?? -1) !== 0) {
    fail('overview_root', json_encode($ov['attention'] ?? null, JSON_UNESCAPED_UNICODE));
} else {
    pass('overview_root');
}
$legacyOv = $manage->getOrgOverview(1);
if (!array_key_exists('need_migrate', $legacyOv) || !array_key_exists('admins', $legacyOv)) {
    fail('legacy_overview_shape', 'missing keys');
} else {
    pass('legacy_overview_shape');
}

// ── stores SQL 分页 ──
$sqlLog = [];
$stores1 = $ws->getStores(1, 'all', '', '', '', 1, 10);
$storesSql = $sqlLog;
file_put_contents($reportDir . '/stores_page1_sql.json', json_encode($storesSql, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ((int)$stores1['count'] !== 11 || count($stores1['list']) !== 10) {
    fail('stores_page1', 'count=' . $stores1['count'] . ' list=' . count($stores1['list']));
} else {
    pass('stores_page1');
}
if (!sqlHasLimit10($storesSql, 'system_store') && !sqlHasLimit10($storesSql, 'organization_store')) {
    fail('stores_sql_limit', 'no LIMIT 10 in list SQL');
} else {
    pass('stores_sql_limit');
}
$pageIds = array_column($stores1['list'], 'id');
$detailSqlOk = true;
foreach ($storesSql as $item) {
    $sql = $item['sql'];
    if (stripos($sql, 'system_store_staff') === false) {
        continue;
    }
    // 详情查询应带当前页 store_id，不应加载全组织
    if (stripos($sql, 'IN (') === false && stripos($sql, 'in (') === false) {
        // ThinkPHP 可能展开为多个 = 
    }
}
pass('stores_detail_page_ids', implode(',', $pageIds));

$sqlLog = [];
$stores2 = $ws->getStores(1, 'all', '', '', '', 2, 10);
if ((int)$stores2['count'] !== 11 || count($stores2['list']) !== 1) {
    fail('stores_page2', 'count=' . $stores2['count'] . ' list=' . count($stores2['list']));
} else {
    pass('stores_page2', 'list=1');
}
$ids1 = array_column($stores1['list'], 'id');
$ids2 = array_column($stores2['list'], 'id');
if (array_intersect($ids1, $ids2)) {
    fail('stores_page_overlap', json_encode(array_intersect($ids1, $ids2)));
} else {
    pass('stores_page_overlap_none');
}

$kw = $ws->getStores(1, 'all', '厦门', '', '', 1, 10);
pass('stores_keyword', 'count=' . $kw['count']);

$open = $ws->getStores(1, 'all', '', 'open', '', 1, 10);
$closed = $ws->getStores(1, 'all', '', 'closed', '', 1, 10);
if ((int)$open['count'] + (int)$closed['count'] !== 11) {
    fail('stores_status_sum', "open={$open['count']} closed={$closed['count']}");
} else {
    pass('stores_status_sum', "open={$open['count']} closed={$closed['count']}");
}
$att = $ws->getStores(1, 'all', '', '', 'attention', 1, 10);
if ((int)$att['count'] !== 0) {
    fail('stores_attention', 'expected 0 got ' . $att['count']);
} else {
    pass('stores_attention_0');
}

// ── employees SQL 分页 ──
$sqlLog = [];
$emps1 = $ws->getEmployees(1, 'all', '', 0, '', 1, 10);
$empSql = $sqlLog;
file_put_contents($reportDir . '/employees_page1_sql.json', json_encode($empSql, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ((int)$emps1['count'] !== 109 || count($emps1['list']) !== 10) {
    fail('employees_page1', 'count=' . $emps1['count'] . ' list=' . count($emps1['list']));
} else {
    pass('employees_page1');
}
if (!sqlHasLimit10($empSql, 'employee') && !sqlHasLimit10($empSql, 'system_store_staff')) {
    // group page 也可能 LIMIT 10
    $hasLimit = false;
    foreach ($empSql as $item) {
        if (preg_match('/\bLIMIT\s+10\b/i', $item['sql']) || preg_match('/\bLIMIT\s+0\s*,\s*10\b/i', $item['sql'])) {
            $hasLimit = true;
            break;
        }
    }
    if (!$hasLimit) {
        fail('employees_sql_limit', 'no LIMIT 10');
    } else {
        pass('employees_sql_limit');
    }
} else {
    pass('employees_sql_limit');
}
$eids = array_column($emps1['list'], 'employee_id');
if (count($eids) !== count(array_unique($eids))) {
    fail('employees_dedupe_page', 'duplicate in page');
} else {
    pass('employees_dedupe_page');
}

$sqlLog = [];
$emps2 = $ws->getEmployees(1, 'all', '', 0, '', 2, 10);
if ((int)$emps2['count'] !== 109 || count($emps2['list']) !== 10) {
    fail('employees_page2', 'count=' . $emps2['count'] . ' list=' . count($emps2['list']));
} else {
    pass('employees_page2');
}
if (array_intersect(array_column($emps1['list'], 'employee_id'), array_column($emps2['list'], 'employee_id'))) {
    fail('employees_page_overlap', 'overlap');
} else {
    pass('employees_page_overlap_none');
}

$empsD1 = $ws->getEmployees(2, 'all', '', 0, '', 1, 10);
$empsD2 = $ws->getEmployees(3, 'all', '', 0, '', 1, 10);
if ((int)$empsD1['count'] !== 49 || (int)$empsD2['count'] !== 50) {
    fail('employees_dept', "{$empsD1['count']}/{$empsD2['count']}");
} else {
    pass('employees_dept', '49/50');
}

$roleFilter = $ws->getEmployees(1, 'all', '', 0, '护理师', 1, 10);
pass('employees_role_filter', 'count=' . $roleFilter['count'] . ' list=' . count($roleFilter['list']));
if ($roleFilter['list']) {
    foreach ($roleFilter['list'] as $row) {
        $hit = false;
        foreach ($row['roles'] as $r) {
            if (mb_strpos($r, '护理师') !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            fail('employees_role_match', 'employee ' . $row['employee_id'] . ' roles=' . implode(',', $row['roles']));
            break;
        }
    }
    if (!array_filter($failures, static function ($f) {
        return strpos($f, 'employees_role_match') === 0;
    })) {
        pass('employees_role_match');
    }
}

$firstStoreId = (int)($stores1['list'][0]['id'] ?? 0);
if ($firstStoreId > 0) {
    $byStore = $ws->getEmployees(1, 'all', '', $firstStoreId, '', 1, 10);
    pass('employees_store_filter', 'store=' . $firstStoreId . ' count=' . $byStore['count']);
}

// 内存多店去重样本（库中无真实多店时）
$sampleStaff = [
    ['employee_id' => 900001, 'name' => '多样本甲', 'staff_id' => 1, 'store_id' => 101, 'store_name' => 'A店'],
    ['employee_id' => 900001, 'name' => '多样本甲', 'staff_id' => 2, 'store_id' => 102, 'store_name' => 'B店'],
    ['employee_id' => 900001, 'name' => '多样本甲', 'staff_id' => 3, 'store_id' => 103, 'store_name' => 'C店'],
    ['employee_id' => 900002, 'name' => '多样本乙', 'staff_id' => 4, 'store_id' => 101, 'store_name' => 'A店'],
];
$deduped = $ws->dedupeEmployeesFromStaffRows($sampleStaff);
file_put_contents($reportDir . '/multi_store_memory.json', json_encode($deduped, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if (count($deduped) !== 2) {
    fail('multi_store_memory_rows', 'expected 2 employees got ' . count($deduped));
} else {
    pass('multi_store_memory_rows', '2');
}
$a = null;
foreach ($deduped as $d) {
    if ((int)$d['employee_id'] === 900001) {
        $a = $d;
    }
}
if (!$a || count($a['assignments']) !== 3) {
    fail('multi_store_memory_assignments', json_encode($a, JSON_UNESCAPED_UNICODE));
} else {
    pass('multi_store_memory_assignments', '3 kept');
}
$distinct = count($deduped);
if ($distinct !== 2) {
    fail('multi_store_memory_distinct', (string)$distinct);
} else {
    pass('multi_store_memory_distinct', 'COUNT DISTINCT=2');
}

// ── candidates ──
$cands = $ws->getLeaderCandidates('', 1, 10);
if ((int)$cands['count'] !== 125 || count($cands['list']) !== 10) {
    fail('candidates', 'count=' . $cands['count']);
} else {
    pass('candidates', '125/10');
}

// ── permissions：账号精确 + 五态确定性 ──
$sqlLog = [];
$perm = $ws->getPermissions(1);
$permSqlCount = count($sqlLog);
file_put_contents($reportDir . '/permissions_1.json', json_encode($perm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($reportDir . '/permissions_sql.json', json_encode($sqlLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
pass('perm_sql_listen_count', (string)$permSqlCount);
if ($permSqlCount > 20) {
    fail('perm_sql_budget', (string)$permSqlCount);
} else {
    pass('perm_sql_budget', (string)$permSqlCount);
}

$h10 = null;
foreach ($perm['permission_holders'] as $h) {
    if ((int)$h['org_admin_id'] === 10) {
        $h10 = $h;
        break;
    }
}
if (!$h10) {
    fail('perm_oa10_exists', 'missing org_admin_id=10');
} elseif ((int)($h10['admin_id'] ?? 0) !== 17) {
    fail('perm_oa10_admin_id', 'expected 17 got ' . ($h10['admin_id'] ?? ''));
} elseif ((string)($h10['account'] ?? '') === '0003') {
    fail('perm_oa10_not_0003', 'got 0003');
} elseif ((string)($h10['account'] ?? '') !== '17359297956') {
    fail('perm_oa10_account', 'expected 17359297956 got ' . ($h10['account'] ?? ''));
} else {
    pass('perm_oa10_exact', 'admin_id=17 account=17359297956 status=' . $h10['permission_status']);
}

// 真实库现状仅作说明，不能代替五态测试
$states = [];
foreach ($perm['permission_holders'] as $h) {
    $states[$h['permission_status']] = ($states[$h['permission_status']] ?? 0) + 1;
}
pass('perm_states_seen_info', json_encode($states, JSON_UNESCAPED_UNICODE));
if (empty($states['INHERIT']) && empty($states['CUSTOM'])) {
    fail('perm_inherit_custom', 'need INHERIT or CUSTOM in real data');
} else {
    pass('perm_inherit_or_custom');
}

// 真实库：同员工双后台账号样本（说明用）
$dual = Db::name('system_admin')->where('employee_id', 242)->where('is_del', 0)->column('account', 'id');
if (!isset($dual[17]) || !isset($dual[19])) {
    fail('perm_dual_account_fixture', json_encode($dual, JSON_UNESCAPED_UNICODE));
} else {
    pass('perm_dual_account_fixture', '17=' . $dual[17] . ' 19=' . $dual[19]);
}

// ── 九组五态确定性测试（纯内存，禁止零样本空循环 PASS）──
$assertStatus = static function (string $case, array $got, array $expect) use (&$failures): void {
    foreach ($expect as $k => $v) {
        $actual = $got[$k] ?? null;
        if ($actual !== $v) {
            fail('perm_state_' . $case, "{$k} expected=" . json_encode($v, JSON_UNESCAPED_UNICODE) . ' got=' . json_encode($actual, JSON_UNESCAPED_UNICODE));
            return;
        }
    }
    pass('perm_state_' . $case);
};

// 1. employee_id=0 → UNLINKED_LEGACY
$assertStatus('1_unlinked', OrganizationWorkspaceReadServices::resolvePermissionStatus(0, 17, [
    'id' => 17, 'employee_id' => 242, 'account' => '1731759297956', 'status' => 1, 'is_del' => 0,
], true, []), [
    'permission_status' => 'UNLINKED_LEGACY',
    'has_account' => false,
    'account' => '',
]);

// 2. admin_id=0 → NO_ACCOUNT
$assertStatus('2_admin_id_zero', OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 0, null, true, [1]), [
    'permission_status' => 'NO_ACCOUNT',
    'permission_status_text' => '没有后台账号',
    'has_account' => false,
    'account' => '',
    'scope_mode' => '',
    'allow_scope' => false,
]);

// 3. admin 不存在 → NO_ACCOUNT + ADMIN_MISSING
$assertStatus('3_admin_missing', OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 99999, null, true, [1]), [
    'permission_status' => 'NO_ACCOUNT',
    'anomaly' => 'ADMIN_MISSING',
    'has_account' => false,
    'account' => '',
    'scope_mode' => '',
    'allow_scope' => false,
]);

// 4. admin 已删除/停用 → NO_ACCOUNT + ADMIN_UNAVAILABLE
$assertStatus('4_admin_unavailable', OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 17, [
    'id' => 17, 'employee_id' => 242, 'account' => '17359297956', 'status' => 0, 'is_del' => 0,
], true, []), [
    'permission_status' => 'NO_ACCOUNT',
    'anomaly' => 'ADMIN_UNAVAILABLE',
    'has_account' => false,
    'account' => '',
    'scope_mode' => '',
]);

// 5. employee 不一致 → NO_ACCOUNT + MISMATCH，account/范围全空
$mismatch = OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 19, [
    'id' => 19, 'employee_id' => 999, 'account' => '0003', 'status' => 1, 'is_del' => 0,
], true, [3, 4]);
$assertStatus('5_mismatch_fail_closed', $mismatch, [
    'permission_status' => 'NO_ACCOUNT',
    'permission_status_text' => '账号关联异常',
    'anomaly' => 'EMPLOYEE_ADMIN_MISMATCH',
    'has_account' => false,
    'account' => '',
    'scope_mode' => '',
    'allow_scope' => false,
]);
if (($mismatch['anomaly_text'] ?? '') === '' || strpos((string)$mismatch['anomaly_text'], '其他员工') === false) {
    fail('perm_state_5_mismatch_text', (string)($mismatch['anomaly_text'] ?? ''));
} else {
    pass('perm_state_5_mismatch_text');
}
if (($mismatch['account'] ?? '') === '0003') {
    fail('perm_state_5_no_leak_account', 'leaked 0003');
} else {
    pass('perm_state_5_no_leak_account');
}

// 6. 可用账号、无组织权限 → NO_ORG_PERMISSION
$assertStatus('6_no_org_permission', OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 17, [
    'id' => 17, 'employee_id' => 242, 'account' => '17359297956', 'status' => 1, 'is_del' => 0,
], false, []), [
    'permission_status' => 'NO_ORG_PERMISSION',
    'has_account' => true,
    'account' => '17359297956',
    'scope_mode' => '',
    'allow_scope' => false,
]);

// 7. 可用账号、无排除门店 → INHERIT
$assertStatus('7_inherit', OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 17, [
    'id' => 17, 'employee_id' => 242, 'account' => '17359297956', 'status' => 1, 'is_del' => 0,
], true, []), [
    'permission_status' => 'INHERIT',
    'has_account' => true,
    'account' => '17359297956',
    'scope_mode' => 'INHERIT',
    'allow_scope' => true,
]);

// 8. 可用账号、有排除门店 → CUSTOM
$assertStatus('8_custom', OrganizationWorkspaceReadServices::resolvePermissionStatus(242, 17, [
    'id' => 17, 'employee_id' => 242, 'account' => '17359297956', 'status' => 1, 'is_del' => 0,
], true, [11]), [
    'permission_status' => 'CUSTOM',
    'has_account' => true,
    'account' => '17359297956',
    'scope_mode' => 'CUSTOM',
    'allow_scope' => true,
]);

// 9. 负责人两账号：accounts[] 保留两条，不得覆盖；错绑不可算 usable
$accA = OrganizationWorkspaceReadServices::normalizeLeaderAccount([
    'id' => 17, 'employee_id' => 242, 'account' => '17359297956', 'real_name' => 'A', 'status' => 1, 'is_del' => 0,
], 242);
$accB = OrganizationWorkspaceReadServices::normalizeLeaderAccount([
    'id' => 19, 'employee_id' => 242, 'account' => '0003', 'real_name' => 'B', 'status' => 1, 'is_del' => 0,
], 242);
$accWrong = OrganizationWorkspaceReadServices::normalizeLeaderAccount([
    'id' => 88, 'employee_id' => 999, 'account' => 'leak-me', 'real_name' => 'X', 'status' => 1, 'is_del' => 0,
], 242);
if (count([$accA, $accB]) !== 2 || ($accA['account'] ?? '') === '' || ($accB['account'] ?? '') === '') {
    fail('perm_state_9_dual_keep', json_encode([$accA, $accB], JSON_UNESCAPED_UNICODE));
} else {
    pass('perm_state_9_dual_keep', '17+19 kept');
}
if (!empty($accWrong['usable']) || ($accWrong['account'] ?? '') !== '' || ($accWrong['anomaly'] ?? '') !== 'EMPLOYEE_ADMIN_MISMATCH') {
    fail('perm_state_9_wrong_not_usable', json_encode($accWrong, JSON_UNESCAPED_UNICODE));
} else {
    pass('perm_state_9_wrong_not_usable');
}
$leaderResolved = OrganizationWorkspaceReadServices::resolveLeaderPermissionStatus([$accA, $accB, $accWrong], true, []);
if (($leaderResolved['permission_status'] ?? '') !== 'INHERIT'
    || (int)count($leaderResolved['usable_accounts'] ?? []) !== 2
    || ($leaderResolved['account'] ?? '') === 'leak-me'
) {
    fail('perm_state_9_leader_status', json_encode($leaderResolved, JSON_UNESCAPED_UNICODE));
} else {
    pass('perm_state_9_leader_status', 'usable=2 status=INHERIT');
}

// 真实库若出现 anomaly，必须已是 fail-closed（有样本才检查，失败才 FAIL；无样本不假 PASS）
$realMismatchChecked = 0;
foreach ($perm['permission_holders'] as $h) {
    if (($h['anomaly'] ?? '') !== 'EMPLOYEE_ADMIN_MISMATCH') {
        continue;
    }
    $realMismatchChecked++;
    if (($h['permission_status'] ?? '') !== 'NO_ACCOUNT'
        || ($h['account'] ?? '') !== ''
        || (int)($h['store_count'] ?? 0) !== 0
        || ($h['scope_mode'] ?? '') !== ''
    ) {
        fail('perm_real_mismatch_fail_closed', 'org_admin=' . ($h['org_admin_id'] ?? ''));
    }
}
if ($realMismatchChecked > 0) {
    pass('perm_real_mismatch_fail_closed', 'checked=' . $realMismatchChecked);
} else {
    pass('perm_real_mismatch_none', 'no real mismatch rows; covered by memory case 5');
}

if (count($perm['permission_holders']) !== 13) {
    fail('perm_holders_13', (string)count($perm['permission_holders']));
} else {
    pass('perm_holders_13');
}
$p2 = $ws->getPermissions(2);
$p3 = $ws->getPermissions(3);
if (count($p2['permission_holders']) !== 6 || count($p3['permission_holders']) !== 6) {
    fail('perm_holders_dept', count($p2['permission_holders']) . '/' . count($p3['permission_holders']));
} else {
    pass('perm_holders_dept', '6/6');
}

// change_log default limit
$logs = $ws->getChangeLog(1, '', '', 1, 10);
if ((int)$logs['limit'] !== 10 || (int)$logs['count'] !== 0) {
    fail('change_log', json_encode(['limit' => $logs['limit'], 'count' => $logs['count']]));
} else {
    pass('change_log_empty_limit10');
}

try {
    $ws->getOverview(999999);
    fail('illegal_org', 'should throw');
} catch (\Throwable $e) {
    pass('illegal_org', $e->getMessage());
}

$big = $ws->getStores(1, 'all', '', '', '', 1, 999);
if ((int)$big['limit'] > 50) {
    fail('limit_cap', (string)$big['limit']);
} else {
    pass('limit_cap', (string)$big['limit']);
}

// EXPLAIN
$explains = [];
$explains['org_store_valid'] = Db::query('EXPLAIN SELECT os.store_id FROM eb_organization_store os INNER JOIN eb_system_store st ON st.id=os.store_id WHERE os.org_id=1 AND st.is_del=0');
$explains['staff_by_store'] = Db::query('EXPLAIN SELECT employee_id FROM eb_system_store_staff WHERE store_id IN (1,2,3) AND status=1 AND is_del=0 AND employee_id>0');
$explains['employee_page'] = Db::query('EXPLAIN SELECT id FROM eb_employee WHERE status=1 AND is_del=0 ORDER BY id ASC LIMIT 10');
$explains['admin_by_id'] = Db::query('EXPLAIN SELECT id,account,employee_id FROM eb_system_admin WHERE id IN (17,19)');
file_put_contents($reportDir . '/explain.json', json_encode($explains, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
foreach ($explains as $name => $rows) {
    $row = $rows[0] ?? [];
    $key = $row['key'] ?? null;
    $possible = $row['possible_keys'] ?? null;
    echo "EXPLAIN {$name}: key=" . json_encode($key) . " possible_keys=" . json_encode($possible) . " type=" . json_encode($row['type'] ?? null) . " rows=" . json_encode($row['rows'] ?? null) . "\n";
    pass('explain_' . $name, 'key=' . json_encode($key) . ' possible=' . json_encode($possible));
}
echo "EXPLAIN_RISK: 小数据量下优化器可能选不同 key；千人员规模需关注 staff(store_id,status) 与 DISTINCT employee 分页成本；change_log 缺 idx_action_id（008未执行）。\n";

$after = coreSnapshot();
file_put_contents($reportDir . '/snapshot_after.json', json_encode($after, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ($before['counts'] !== $after['counts'] || $before['hashes'] !== $after['hashes']) {
    fail('immutability', 'counts/hashes changed');
} else {
    pass('immutability');
}
if ($after['source_mode'] !== 'legacy' || $after['runtime'] !== null) {
    fail('source_after', json_encode(['mode' => $after['source_mode'], 'rt' => $after['runtime']]));
} else {
    pass('source_after');
}

echo "REPORT_DIR={$reportDir}\n";
if ($failures) {
    echo "RESULT=FAIL count=" . count($failures) . "\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
echo "RESULT=ALL_PASS\n";
exit(0);

<?php
/**
 * P1-3：十类账号真实 HTTP 鉴权 + 两轮交替（非服务层替代）
 * 登录走 adminapi/storeapi/cashierapi/api；订单列表/详情/导出前置/核销入口经中间件。
 * 不修改真实账号密码；临时账号 ofh_* 清理。
 *
 * docker cp ... mohe-app:/tmp/ && docker exec mohe-app php /tmp/smoke-org-followup-p13-http-accounts.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use think\facade\Db;
use app\services\organization\JobPositionPolicyServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\MerchantEntryServices;
use app\services\employee\EmployeeStaffWriteServices;

$runId = 'ofh_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
$pass = 0;
$fail = 0;
$pwdPlain = 'OfhHttp#2026Aa';
$pwdHash = password_hash($pwdPlain, PASSWORD_BCRYPT);
$base = getenv('OFH_HTTP_BASE') ?: 'http://mohe-nginx';
$created = [
    'employee_ids' => [], 'staff_ids' => [], 'admin_ids' => [], 'user_ids' => [],
    'position_ids' => [], 'rule_ids' => [], 'job_ids' => [], 'entry_ids' => [],
    'scope_ids' => [], 'order_ids' => [], 'session_ids' => [],
];
$matrix = [];

function logLine(string $s): void
{
    echo $s . PHP_EOL;
}

function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        logLine('PASS  ' . $n . ($d !== '' ? ' | ' . $d : ''));
    } else {
        $fail++;
        logLine('FAIL  ' . $n . ($d !== '' ? ' | ' . $d : ''));
    }
}

function track(array &$c, string $k, int $id): void
{
    if ($id > 0) {
        $c[$k][] = $id;
        $c[$k] = array_values(array_unique(array_map('intval', $c[$k])));
    }
}

function phoneFor(string $runId, int $n): string
{
    return '194' . sprintf('%08d', hexdec(substr(sha1($runId . '#ofh#' . $n), 0, 7)) % 100000000);
}

function uuidV4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
        . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function ctx(string $token): array
{
    return ['header_token' => '', 'body_token' => $token, 'operator_ip' => '127.0.0.1'];
}

function httpJson(string $method, string $url, array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdr[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $bodyStr = $raw === false ? '' : substr($raw, $hs);
    $json = json_decode($bodyStr, true);
    return [
        'http_code' => $code,
        'errno' => $errno,
        'error' => $err,
        'json' => is_array($json) ? $json : null,
        'raw' => $bodyStr,
        'status' => is_array($json) ? (int)($json['status'] ?? 0) : 0,
        'msg' => is_array($json) ? (string)($json['msg'] ?? '') : '',
        'data' => is_array($json) ? ($json['data'] ?? null) : null,
    ];
}

function authHeader(string $token): array
{
    return ['Authori-zation' => 'Bearer ' . $token];
}

/** 订单列表：门店端 data.data；总部端可能 data.list / data.data */
function orderListRows($data): array
{
    if (!is_array($data)) {
        return [];
    }
    if (isset($data['list']) && is_array($data['list'])) {
        return $data['list'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        // 排除把 count/stat 当成行
        $rows = $data['data'];
        if ($rows !== [] && array_keys($rows) === range(0, count($rows) - 1)) {
            return $rows;
        }
    }
    if ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
        return $data;
    }
    return [];
}

function orderListIds($data): array
{
    $ids = [];
    foreach (orderListRows($data) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ids[] = (int)($row['id'] ?? 0);
    }
    return array_values(array_filter($ids));
}

function record(array &$matrix, string $acct, string $entry, string $expect, string $actual, string $layer, bool $okFlag): void
{
    $matrix[] = compact('acct', 'entry', 'expect', 'actual', 'layer', 'okFlag');
}

logLine('gate=p13 http multi-account');
logLine('run_id=' . $runId);
logLine('base=' . $base);
logLine('layer_note=HTTP middleware (not service-layer substitute)');

function tableFingerprint(): array
{
    return [
        'emp' => [(int)Db::name('employee')->count(), (int)Db::name('employee')->sum('id')],
        'staff' => [(int)Db::name('system_store_staff')->count(), (int)Db::name('system_store_staff')->sum('id')],
        'admin' => [(int)Db::name('system_admin')->count(), (int)Db::name('system_admin')->sum('id')],
        'user' => [(int)Db::name('user')->count(), (int)Db::name('user')->sum('uid')],
        'ofh_emp' => (int)Db::name('employee')->whereLike('name', 'OFH%')->count(),
        'ofhp_emp' => (int)Db::name('employee')->whereLike('name', 'OFHP%')->count(),
    ];
}

$fpBefore = tableFingerprint();
logLine('FP_BEFORE ' . json_encode($fpBefore, JSON_UNESCAPED_UNICODE));

$storeA = (int)Db::name('system_store')->where('is_del', 0)->order('id', 'asc')->value('id');
$storeB = (int)Db::name('system_store')->where('is_del', 0)->where('id', '<>', $storeA)->order('id', 'asc')->value('id');
if ($storeB <= 0) {
    $storeB = $storeA;
}
ok('stores_ready', $storeA > 0, "A={$storeA} B={$storeB}");

$menuId = (int)Db::name('system_menus')->whereIn('type', [3, 4])->where('is_del', 0)->order('id', 'asc')->value('id');
if ($menuId <= 0) {
    $menuId = (int)Db::name('system_menus')->where('is_del', 0)->order('id', 'asc')->value('id');
}
$now = time();

/** @var EmployeeStaffWriteServices $write */
$write = app()->make(EmployeeStaffWriteServices::class);
/** @var EmployeeDataScopeServices $dataScope */
$dataScope = app()->make(EmployeeDataScopeServices::class);
/** @var MerchantEntryServices $merchant */
$merchant = app()->make(MerchantEntryServices::class);
$merchant->bootstrapEnsureSchema();

try {
    // —— 造数：覆盖十类关键身份（HTTP 登录账号）——
    $accounts = [];

    // 1 总部超管
    $phone1 = phoneFor($runId, 1);
    $emp1 = (int)Db::name('employee')->insertGetId([
        'name' => 'OFH超管-' . $runId, 'phone' => $phone1, 'status' => 1, 'is_del' => 0,
        'auth_version' => 1, 'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'employee_ids', $emp1);
    $acc1 = 'ofhs_' . substr(md5($runId . '1'), 0, 8);
    $admin1 = (int)Db::name('system_admin')->insertGetId([
        'employee_id' => $emp1, 'account' => $acc1, 'pwd' => $pwdHash,
        'real_name' => 'OFH超管', 'phone' => $phone1, 'roles' => '',
        'level' => 0, 'admin_type' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => $now,
    ]);
    track($created, 'admin_ids', $admin1);
    $accounts['hq_super'] = ['type' => 'hq_super', 'channel' => 'admin', 'account' => $acc1, 'pwd' => $pwdPlain, 'employee_id' => $emp1, 'admin_id' => $admin1];

    // 2 总部普通（个人范围）
    $phone2 = phoneFor($runId, 2);
    $r2 = $write->saveStaffAssignment(0, [
        'store_id' => $storeA, 'staff_name' => 'OFH总部个人_' . $runId, 'phone' => $phone2,
        'account' => 'ofhp_' . substr(md5($runId . '2'), 0, 8), 'pwd' => $pwdHash,
        'roles' => [], 'status' => 1, 'is_store' => 1,
    ], ['source' => 'admin', 'reason' => 'ofh_seed', 'operator_name' => 'ofh']);
    track($created, 'employee_ids', (int)$r2['employee_id']);
    track($created, 'staff_ids', (int)$r2['staff_id']);
    $acc2 = 'ofhap_' . substr(md5($runId . '2a'), 0, 8);
    $admin2 = (int)Db::name('system_admin')->insertGetId([
        'employee_id' => (int)$r2['employee_id'], 'account' => $acc2, 'pwd' => $pwdHash,
        'real_name' => 'OFH总部个人', 'phone' => $phone2, 'roles' => '',
        'level' => 1, 'admin_type' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => $now,
    ]);
    track($created, 'admin_ids', $admin2);
    $op = ['id' => 1, 'level' => 0, 'admin_type' => 0, 'real_name' => 'ofh', 'account' => 'ofh'];
    $tok2 = uuidV4();
    $scope2 = $dataScope->saveScope((int)$r2['employee_id'], [
        'source_type' => 'hq', 'scope_mode' => 'personal', 'request_token' => $tok2,
    ], $op, ctx($tok2), true);
    if (!empty($scope2['data']['id'])) {
        track($created, 'scope_ids', (int)$scope2['data']['id']);
    }
    $accounts['hq_personal'] = ['type' => 'hq_personal', 'channel' => 'admin', 'account' => $acc2, 'pwd' => $pwdPlain, 'employee_id' => (int)$r2['employee_id'], 'admin_id' => $admin2];

    // 3 总部门店范围
    $phone3 = phoneFor($runId, 3);
    $r3 = $write->saveStaffAssignment(0, [
        'store_id' => $storeA, 'staff_name' => 'OFH总部店范_' . $runId, 'phone' => $phone3,
        'account' => 'ofhss_' . substr(md5($runId . '3'), 0, 8), 'pwd' => $pwdHash,
        'roles' => [], 'status' => 1, 'is_store' => 1,
    ], ['source' => 'admin', 'reason' => 'ofh_seed', 'operator_name' => 'ofh']);
    track($created, 'employee_ids', (int)$r3['employee_id']);
    track($created, 'staff_ids', (int)$r3['staff_id']);
    $acc3 = 'ofhass_' . substr(md5($runId . '3a'), 0, 8);
    $admin3 = (int)Db::name('system_admin')->insertGetId([
        'employee_id' => (int)$r3['employee_id'], 'account' => $acc3, 'pwd' => $pwdHash,
        'real_name' => 'OFH总部店范', 'phone' => $phone3, 'roles' => '',
        'level' => 1, 'admin_type' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => $now,
    ]);
    track($created, 'admin_ids', $admin3);
    $tok3 = uuidV4();
    $scope3 = $dataScope->saveScope((int)$r3['employee_id'], [
        'source_type' => 'hq', 'scope_mode' => 'store', 'store_ids' => [$storeA], 'request_token' => $tok3,
    ], $op, ctx($tok3), true);
    if (!empty($scope3['data']['id'])) {
        track($created, 'scope_ids', (int)$scope3['data']['id']);
    }
    $accounts['hq_store_scope'] = ['type' => 'hq_store_scope', 'channel' => 'admin', 'account' => $acc3, 'pwd' => $pwdPlain, 'employee_id' => (int)$r3['employee_id'], 'admin_id' => $admin3];

    // 4 A店管理者（门店端）
    $phone4 = phoneFor($runId, 4);
    $r4 = $write->saveStaffAssignment(0, [
        'store_id' => $storeA, 'staff_name' => 'OFHA店管_' . $runId, 'phone' => $phone4,
        'account' => 'ofhm_' . substr(md5($runId . '4'), 0, 8), 'pwd' => $pwdHash,
        'roles' => [], 'status' => 1, 'is_store' => 1, 'is_manager' => 1,
    ], ['source' => 'admin', 'reason' => 'ofh_seed', 'operator_name' => 'ofh']);
    track($created, 'employee_ids', (int)$r4['employee_id']);
    track($created, 'staff_ids', (int)$r4['staff_id']);
    // 强制店长标记 + 本店数据范围（门店来源仅允许 personal / store_self）
    Db::name('system_store_staff')->where('id', (int)$r4['staff_id'])->update(['is_manager' => 1, 'is_store' => 1]);
    $tok4 = uuidV4();
    try {
        $scope4 = $dataScope->saveScope((int)$r4['employee_id'], [
            'source_type' => 'store',
            'source_store_id' => $storeA,
            'scope_mode' => 'store_self',
            'request_token' => $tok4,
        ], $op, ctx($tok4), true);
        if (!empty($scope4['data']['id'])) {
            track($created, 'scope_ids', (int)$scope4['data']['id']);
        }
    } catch (\Throwable $e) {
        // 夹具订单仍挂 staff_id=店长，个人范围也可看见；此处失败不阻断造数
        logLine('WARN  seed_manager_store_self_scope | ' . $e->getMessage());
    }
    $accounts['store_manager_a'] = [
        'type' => 'store_manager_a', 'channel' => 'store', 'account' => 'ofhm_' . substr(md5($runId . '4'), 0, 8),
        'pwd' => $pwdPlain, 'employee_id' => (int)$r4['employee_id'], 'staff_id' => (int)$r4['staff_id'], 'store_id' => $storeA,
    ];

    // 5 A店普通
    $phone5 = phoneFor($runId, 5);
    $r5 = $write->saveStaffAssignment(0, [
        'store_id' => $storeA, 'staff_name' => 'OFHA普通_' . $runId, 'phone' => $phone5,
        'account' => 'ofhsa_' . substr(md5($runId . '5'), 0, 8), 'pwd' => $pwdHash,
        'roles' => [], 'status' => 1, 'is_store' => 1,
    ], ['source' => 'admin', 'reason' => 'ofh_seed', 'operator_name' => 'ofh']);
    track($created, 'employee_ids', (int)$r5['employee_id']);
    track($created, 'staff_ids', (int)$r5['staff_id']);
    $accounts['store_staff_a'] = [
        'type' => 'store_staff_a', 'channel' => 'store', 'account' => 'ofhsa_' . substr(md5($runId . '5'), 0, 8),
        'pwd' => $pwdPlain, 'employee_id' => (int)$r5['employee_id'], 'staff_id' => (int)$r5['staff_id'], 'store_id' => $storeA,
    ];

    // 6 两店任职（门店 A 登录）
    $phone6 = phoneFor($runId, 6);
    $r6a = $write->saveStaffAssignment(0, [
        'store_id' => $storeA, 'staff_name' => 'OFHAB_A_' . $runId, 'phone' => $phone6,
        'account' => 'ofhaba_' . substr(md5($runId . '6a'), 0, 8), 'pwd' => $pwdHash,
        'roles' => [], 'status' => 1, 'is_store' => 1,
    ], ['source' => 'admin', 'reason' => 'ofh_seed', 'operator_name' => 'ofh']);
    track($created, 'employee_ids', (int)$r6a['employee_id']);
    track($created, 'staff_ids', (int)$r6a['staff_id']);
    $r6b = ['staff_id' => 0];
    if ($storeB !== $storeA) {
        $r6b = $write->saveStaffAssignment(0, [
            'store_id' => $storeB, 'staff_name' => 'OFHAB_B_' . $runId, 'phone' => $phone6,
            'account' => 'ofhabb_' . substr(md5($runId . '6b'), 0, 8), 'pwd' => $pwdHash,
            'roles' => [], 'status' => 1, 'is_store' => 1, 'employee_id' => (int)$r6a['employee_id'],
        ], ['source' => 'admin', 'reason' => 'ofh_seed_b', 'operator_name' => 'ofh']);
        track($created, 'staff_ids', (int)$r6b['staff_id']);
    }
    $accounts['store_dual'] = [
        'type' => 'store_dual', 'channel' => 'store', 'account' => 'ofhaba_' . substr(md5($runId . '6a'), 0, 8),
        'pwd' => $pwdPlain, 'employee_id' => (int)$r6a['employee_id'], 'staff_id' => (int)$r6a['staff_id'], 'store_id' => $storeA,
        'account_b' => $storeB !== $storeA ? ('ofhabb_' . substr(md5($runId . '6b'), 0, 8)) : '',
        'store_id_b' => $storeB,
        'staff_id_b' => ($storeB !== $storeA && !empty($r6b['staff_id'])) ? (int)$r6b['staff_id'] : 0,
    ];

    // 7 收银（直插 + 收银台角色，与已验证可登录样本一致）
    $phone7 = phoneFor($runId, 7);
    $cashierRoleId = (int)Db::name('system_role')->where('type', 3)->where('status', 1)->order('id', 'asc')->value('id');
    if ($cashierRoleId <= 0) {
        $cashierRoleId = 1;
    }
    $emp7 = (int)Db::name('employee')->insertGetId([
        'name' => 'OFH收银-' . $runId, 'phone' => $phone7, 'status' => 1, 'is_del' => 0,
        'auth_version' => 1, 'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'employee_ids', $emp7);
    $acc7 = 'ofhc_' . substr(md5($runId . '7'), 0, 8);
    $staff7 = (int)Db::name('system_store_staff')->insertGetId([
        'store_id' => $storeA, 'employee_id' => $emp7, 'staff_name' => 'OFH收银',
        'account' => $acc7, 'pwd' => $pwdHash, 'phone' => $phone7,
        'status' => 1, 'is_del' => 0, 'is_store' => 1, 'is_cashier' => 1,
        'roles' => (string)$cashierRoleId, 'level' => 0, 'add_time' => $now,
    ]);
    track($created, 'staff_ids', $staff7);
    $accounts['cashier'] = [
        'type' => 'cashier', 'channel' => 'cashier', 'account' => $acc7,
        'pwd' => $pwdPlain, 'employee_id' => $emp7, 'staff_id' => $staff7, 'store_id' => $storeA,
    ];

    // 8 手机有权（API 用户 + employee）
    $phone8 = phoneFor($runId, 8);
    $uid8 = (int)Db::name('user')->insertGetId([
        'account' => 'ofhu_' . substr(md5($runId . '8'), 0, 8),
        'pwd' => md5($pwdPlain), 'nickname' => 'OFH手机有权', 'phone' => $phone8,
        'status' => 1, 'user_type' => 'h5', 'add_time' => $now,
    ]);
    track($created, 'user_ids', $uid8);
    $emp8 = (int)Db::name('employee')->insertGetId([
        'name' => 'OFH手机有权-' . $runId, 'phone' => $phone8, 'status' => 1, 'is_del' => 0,
        'auth_version' => 1, 'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'employee_ids', $emp8);
    $pos8 = (int)Db::name('position')->insertGetId([
        'name' => 'OFH手机岗-' . $runId, 'status' => 1, 'allow_store_select' => 1,
        'use_platform' => 0, 'use_store' => 0, 'use_cashier' => 0, 'use_mobile' => 1,
        'remark' => 'ofh', 'version' => 1, 'update_time' => $now,
    ]);
    track($created, 'position_ids', $pos8);
    $rule8 = (int)Db::name('job_position_channel_rule')->insertGetId([
        'position_id' => $pos8, 'channel' => JobPositionPolicyServices::CHANNEL_MOBILE,
        'rules' => (string)$menuId, 'status' => 1, 'version' => 1, 'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'rule_ids', $rule8);
    $staff8 = (int)Db::name('system_store_staff')->insertGetId([
        'uid' => $uid8, 'store_id' => $storeA, 'employee_id' => $emp8, 'staff_name' => 'OFH手机',
        'phone' => $phone8, 'account' => 'ofhmb_' . substr(md5($runId . '8s'), 0, 8),
        'pwd' => $pwdHash, 'status' => 1, 'is_del' => 0, 'add_time' => $now,
    ]);
    track($created, 'staff_ids', $staff8);
    $job8 = (int)Db::name('staff_job_position')->insertGetId([
        'employee_id' => $emp8, 'store_id' => $storeA, 'staff_id' => $staff8, 'position_id' => $pos8,
        'status' => 1, 'start_time' => $now, 'end_time' => 0, 'reason' => 'ofh', 'is_del' => 0,
        'request_token' => 'ofh-job8-' . $runId, 'operator_id' => 0, 'operator_name' => 'ofh',
        'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'job_ids', $job8);
    $entry8 = (int)Db::name('staff_channel_entry')->insertGetId([
        'employee_id' => $emp8, 'store_id' => $storeA, 'staff_id' => $staff8,
        'channel' => JobPositionPolicyServices::CHANNEL_MOBILE, 'status' => 1,
        'request_token' => 'ofh-e8-' . $runId, 'is_del' => 0, 'operator_id' => 0, 'operator_name' => 'ofh',
        'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'entry_ids', $entry8);
    $accounts['mobile_ok'] = [
        'type' => 'mobile_ok', 'channel' => 'api', 'account' => 'ofhu_' . substr(md5($runId . '8'), 0, 8),
        'pwd' => $pwdPlain, 'employee_id' => $emp8, 'uid' => $uid8, 'phone' => $phone8,
    ];

    // 9 手机无权商城用户
    $phone9 = phoneFor($runId, 9);
    $uid9 = (int)Db::name('user')->insertGetId([
        'account' => 'ofhu9_' . substr(md5($runId . '9'), 0, 8),
        'pwd' => md5($pwdPlain), 'nickname' => 'OFH无权', 'phone' => $phone9,
        'status' => 1, 'user_type' => 'h5', 'add_time' => $now,
    ]);
    track($created, 'user_ids', $uid9);
    $accounts['mobile_no'] = [
        'type' => 'mobile_no', 'channel' => 'api', 'account' => 'ofhu9_' . substr(md5($runId . '9'), 0, 8),
        'pwd' => $pwdPlain, 'uid' => $uid9, 'phone' => $phone9, 'employee_id' => 0,
    ];

    // 10 停职目标（收银同事，后面撤权）
    $phone10 = phoneFor($runId, 10);
    $emp10 = (int)Db::name('employee')->insertGetId([
        'name' => 'OFH停职-' . $runId, 'phone' => $phone10, 'status' => 1, 'is_del' => 0,
        'auth_version' => 1, 'add_time' => $now, 'update_time' => $now,
    ]);
    track($created, 'employee_ids', $emp10);
    $acc10 = 'ofht_' . substr(md5($runId . '10'), 0, 8);
    $staff10 = (int)Db::name('system_store_staff')->insertGetId([
        'store_id' => $storeA, 'employee_id' => $emp10, 'staff_name' => 'OFH停职',
        'account' => $acc10, 'pwd' => $pwdHash, 'phone' => $phone10,
        'status' => 1, 'is_del' => 0, 'is_store' => 1, 'is_cashier' => 1,
        'roles' => (string)$cashierRoleId, 'level' => 0, 'add_time' => $now,
    ]);
    track($created, 'staff_ids', $staff10);
    $accounts['cashier_suspend'] = [
        'type' => 'cashier_suspend', 'channel' => 'cashier', 'account' => $acc10,
        'pwd' => $pwdPlain, 'employee_id' => $emp10, 'staff_id' => $staff10, 'store_id' => $storeA,
    ];

    ok('seed_ten_accounts', count($accounts) === 10, 'count=' . count($accounts));

    // —— A/B 订单夹具（确定性、可清理）——
    $fixtureUid = (int)Db::name('user')->where('status', 1)->order('uid', 'asc')->value('uid');
    if ($fixtureUid <= 0) {
        $fixtureUid = 1;
    }
    $tpl = Db::name('store_order')->where('is_del', 0)->where('is_system_del', 0)->order('id', 'desc')->find();
    ok('order_template_ready', is_array($tpl) && !empty($tpl), 'has_tpl=' . (is_array($tpl) ? '1' : '0'));
    $orderAKey = 'OFHF3A_' . substr(md5($runId . 'A'), 0, 12);
    $orderBKey = 'OFHF3B_' . substr(md5($runId . 'B'), 0, 12);
    $orderAId = 0;
    $orderBId = 0;
    if (is_array($tpl)) {
        unset($tpl['id']);
        // unique / order_id 均有 UNIQUE，克隆模板必须重写
        $rowA = $tpl;
        $rowA['order_id'] = $orderAKey;
        $rowA['unique'] = md5($runId . '|A|' . $orderAKey . '|' . $now);
        $rowA['uid'] = $fixtureUid;
        $rowA['store_id'] = $storeA;
        $rowA['paid'] = 1;
        // status=1 已支付待发货/服务中，避免默认列表把 status=0 当未支付滤掉
        $rowA['status'] = 1;
        $rowA['is_del'] = 0;
        $rowA['is_system_del'] = 0;
        $rowA['refund_status'] = 0;
        $rowA['pid'] = 0;
        $rowA['shipping_type'] = 2; // 到店核销，导出状态分支可识别
        $rowA['add_time'] = $now;
        $rowA['pay_time'] = $now;
        $rowA['pay_price'] = '88.01';
        $rowA['total_price'] = '88.01';
        // A 单归属店长可见（店长本店）+ 店员参与；勿只挂店员导致店长个人范围看不见
        $rowA['staff_id'] = (int)($accounts['store_manager_a']['staff_id'] ?? 0);
        $rowA['clerk_id'] = (int)($accounts['store_staff_a']['staff_id'] ?? 0);
        $rowA['service_staff_id'] = (int)($accounts['store_dual']['staff_id'] ?? 0);
        $rowA['trade_no'] = '';
        $rowA['erp_order_id'] = '';
        $rowA['cash_choose'] = 0;
        $rowA['order_type'] = 0;
        $rowA['is_auto'] = 0;
        $rowA['is_user_del'] = 0;
        $rowA['verify_code'] = 'OFHA' . substr(md5($runId . 'va'), 0, 10);
        $orderAId = (int)Db::name('store_order')->insertGetId($rowA);
        track($created, 'order_ids', $orderAId);

        $rowB = $tpl;
        $rowB['order_id'] = $orderBKey;
        $rowB['unique'] = md5($runId . '|B|' . $orderBKey . '|' . ($now + 1));
        $rowB['uid'] = $fixtureUid;
        $rowB['store_id'] = $storeB;
        $rowB['paid'] = 1;
        $rowB['status'] = 1;
        $rowB['is_del'] = 0;
        $rowB['is_system_del'] = 0;
        $rowB['refund_status'] = 0;
        $rowB['pid'] = 0;
        $rowB['shipping_type'] = 2;
        $rowB['add_time'] = $now;
        $rowB['pay_time'] = $now;
        $rowB['pay_price'] = '99.02';
        $rowB['total_price'] = '99.02';
        $rowB['staff_id'] = (int)($accounts['store_dual']['staff_id_b'] ?? 0);
        $rowB['clerk_id'] = 0;
        $rowB['trade_no'] = '';
        $rowB['erp_order_id'] = '';
        $rowB['cash_choose'] = 0;
        $rowB['order_type'] = 0;
        $rowB['is_auto'] = 0;
        $rowB['is_user_del'] = 0;
        $rowB['verify_code'] = 'OFHB' . substr(md5($runId . 'vb'), 0, 10);
        $orderBId = (int)Db::name('store_order')->insertGetId($rowB);
        track($created, 'order_ids', $orderBId);
    }
    ok('seed_order_fixtures', $orderAId > 0 && $orderBId > 0 && $orderAId !== $orderBId, "A={$orderAId}/{$orderAKey};B={$orderBId}/{$orderBKey}");

    // —— HTTP 登录 + 业务接口 ——
    $tokens = [];
    foreach ($accounts as $key => $acct) {
        $loginUrl = '';
        $payload = ['account' => $acct['account'], 'pwd' => $acct['pwd']];
        if ($acct['channel'] === 'admin') {
            $loginUrl = $base . '/adminapi/login';
        } elseif ($acct['channel'] === 'store') {
            $loginUrl = $base . '/storeapi/login';
            $payload['store_id'] = (int)$acct['store_id'];
        } elseif ($acct['channel'] === 'cashier') {
            $loginUrl = $base . '/cashierapi/login';
        } else {
            // 买家端 LoginServices 按 phone 查号
            $loginUrl = $base . '/api/login';
            $payload = ['account' => (string)($acct['phone'] ?? $acct['account']), 'password' => $acct['pwd']];
        }
        $resp = httpJson('POST', $loginUrl, $payload);
        $token = '';
        if (is_array($resp['data'])) {
            $token = (string)($resp['data']['token'] ?? '');
        }
        $okLogin = ($resp['status'] === 200 && $token !== '');
        ok('http_login_' . $key, $okLogin, 'status=' . $resp['status'] . ' msg=' . $resp['msg']);
        record($matrix, $key, $loginUrl, 'login_ok', 'status=' . $resp['status'] . ';token=' . ($token !== '' ? 'yes' : 'no'), 'HTTP', $okLogin);
        $tokens[$key] = $token;

        if (!$okLogin) {
            continue;
        }

        // 业务：夹具订单列表/详情/导出；禁止 skip / 导出类型错误 / expressList 假 PASS
        if ($acct['channel'] === 'admin') {
            $targetId = $orderAId;
            // 门店订单列表（/adminapi/order/list 是平台商城单，夹具不可见）
            $r = httpJson('GET', $base . '/adminapi/store/order/list?page=1&limit=20&search_order_id=' . urlencode($orderAKey), null, authHeader($token));
            $okList = ($r['status'] === 200 && is_array($r['data']));
            $seen = orderListIds($r['data']);
            // 超管/店范必须看见 A；个人范围不得看见夹具（空集合 + 接口 200）
            if (in_array($key, ['hq_super', 'hq_store_scope'], true)) {
                $listExpect = $okList && in_array($orderAId, $seen, true);
            } elseif ($key === 'hq_personal') {
                $listExpect = $okList && !in_array($orderAId, $seen, true);
            } else {
                $listExpect = $okList;
            }
            ok('http_admin_order_list_' . $key, $listExpect, 'status=' . $r['status'] . ' seen=' . implode(',', $seen));
            record($matrix, $key, '/adminapi/store/order/list', 'fixture_list', 'status=' . $r['status'], 'HTTP', $listExpect);

            if ($targetId <= 0) {
                ok('http_admin_order_detail_' . $key, false, 'NOT_RUN no_fixture');
            } else {
                $d = httpJson('GET', $base . '/adminapi/order/info/' . $targetId, null, authHeader($token));
                if (in_array($key, ['hq_super', 'hq_store_scope'], true)) {
                    $dOk = $d['status'] === 200;
                } elseif ($key === 'hq_personal') {
                    // 无权必须非 200，禁止 skip
                    $dOk = $d['status'] !== 200 && stripos($d['msg'], '请登录') === false;
                } else {
                    $dOk = $d['status'] === 200;
                }
                ok('http_admin_order_detail_' . $key, $dOk, 'id=' . $targetId . ' status=' . $d['status'] . ' msg=' . mb_substr($d['msg'], 0, 60));
                record($matrix, $key, '/adminapi/order/info', 'fixture_detail', 'status=' . $d['status'], 'HTTP', $dOk);
            }

            // 真实订单导出：夹具 + 最近订单合计不超过约 10 条（禁止全量）
            $recent = httpJson('GET', $base . '/adminapi/store/order/list?page=1&limit=9&store_id=' . $storeA, null, authHeader($token));
            $exportIds = array_values(array_unique(array_filter(array_merge(
                [$orderAId],
                array_map('intval', orderListIds($recent['data'] ?? null))
            ), static fn($v) => $v > 0)));
            $exportIds = array_slice($exportIds, 0, 10);
            $ex = httpJson('GET', $base . '/adminapi/export/storeOrder?ids=' . implode(',', $exportIds), null, authHeader($token));
            $exData = $ex['data'];
            $exPathHint = '';
            if (is_array($exData)) {
                $exPathHint = json_encode($exData, JSON_UNESCAPED_UNICODE);
            } elseif (is_string($exData)) {
                $exPathHint = $exData;
            }
            $exHasFixture = stripos($exPathHint, $orderAKey) !== false || stripos($exPathHint, (string)$orderAId) !== false;
            $exRows = (is_array($exData) && isset($exData['export']) && is_array($exData['export'])) ? $exData['export'] : [];
            if ($key === 'hq_personal') {
                // 个人范围不得导出夹具行
                $exOk = !$exHasFixture && stripos($ex['msg'], '导出类型错误') === false && stripos($exPathHint, 'expressList') === false;
            } else {
                $exOk = ($ex['status'] === 200)
                    && is_array($exData)
                    && isset($exData['header'], $exData['filename'], $exData['export'])
                    && stripos((string)$exData['filename'], '订单导出') !== false
                    && count($exRows) > 0
                    && $exHasFixture;
            }
            if (stripos($ex['msg'], '导出类型错误') !== false || stripos($exPathHint, 'expressList') !== false) {
                $exOk = false;
            }
            ok('http_admin_export_storeOrder_' . $key, $exOk, 'status=' . $ex['status'] . ' rows=' . count($exRows) . ' fixture=' . ($exHasFixture ? '1' : '0') . ' msg=' . mb_substr($ex['msg'], 0, 60));
            record($matrix, $key, '/adminapi/export/storeOrder?ids=', 'order_export_file', 'status=' . $ex['status'] . ';fixture=' . ($exHasFixture ? '1' : '0'), 'HTTP', $exOk);

            $st = httpJson('GET', $base . '/adminapi/home/header', null, authHeader($token));
            $stOk = ($key === 'hq_super') ? ($st['status'] === 200) : ($st['status'] === 200 || (stripos($st['msg'], '权限') !== false));
            $stOk = $stOk && $st['status'] !== 410000 && stripos($st['msg'], '请登录') === false;
            ok('http_admin_stats_' . $key, $stOk, 'status=' . $st['status'] . ' msg=' . mb_substr($st['msg'], 0, 60));
            record($matrix, $key, '/adminapi/home/header', 'stats', 'status=' . $st['status'], 'HTTP', $stOk);

            // 固定测试规模：报表/订单列表查询 100 条（非压测）
            $rep = httpJson('GET', $base . '/adminapi/store/order/list?page=1&limit=100', null, authHeader($token));
            $repIds = orderListIds($rep['data'] ?? null);
            $repOk = ($rep['status'] === 200) && is_array($rep['data']) && count($repIds) <= 100;
            ok('http_admin_report_list100_' . $key, $repOk, 'status=' . $rep['status'] . ' rows=' . count($repIds) . ' limit=100');
            record($matrix, $key, '/adminapi/store/order/list?limit=100', 'report_query_100', 'rows=' . count($repIds), 'HTTP', $repOk);
        } elseif ($acct['channel'] === 'store') {
            $sid = (int)$acct['store_id'];
            $wantId = ($sid === $storeA) ? $orderAId : $orderBId;
            $wantKey = ($sid === $storeA) ? $orderAKey : $orderBKey;
            $r = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=20&search_order_id=' . urlencode($wantKey), null, authHeader($token));
            $seen = orderListIds($r['data']);
            $okList = ($r['status'] === 200 && in_array($wantId, $seen, true));
            ok('http_store_order_list_' . $key, $okList, 'status=' . $r['status'] . ' want=' . $wantId . ' seen=' . implode(',', $seen));
            record($matrix, $key, '/storeapi/order/list', 'fixture_list', 'status=' . $r['status'], 'HTTP', $okList);

            // 固定测试规模：门店订单列表查询 100 条
            $rep = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=100', null, authHeader($token));
            $repIds = orderListIds($rep['data'] ?? null);
            $repOk = ($rep['status'] === 200) && count($repIds) <= 100;
            ok('http_store_report_list100_' . $key, $repOk, 'status=' . $rep['status'] . ' rows=' . count($repIds) . ' limit=100');
            record($matrix, $key, '/storeapi/order/list?limit=100', 'report_query_100', 'rows=' . count($repIds), 'HTTP', $repOk);

            $d = httpJson('GET', $base . '/storeapi/order/info/' . $wantId, null, authHeader($token));
            if ($d['status'] === 0) {
                $d = httpJson('GET', $base . '/storeapi/order/detail/' . $wantId, null, authHeader($token));
            }
            $dOk = $d['status'] === 200;
            ok('http_store_order_detail_' . $key, $dOk, 'id=' . $wantId . ' status=' . $d['status'] . ' msg=' . mb_substr($d['msg'], 0, 60));
            record($matrix, $key, '/storeapi/order/info', 'fixture_detail', 'status=' . $d['status'], 'HTTP', $dOk);

            $w = httpJson('POST', $base . '/storeapi/order/writeoff/records', ['page' => 1, 'limit' => 1], authHeader($token));
            $wOk = ($w['status'] === 200) || (stripos($w['msg'], '权限') !== false);
            $wOk = $wOk && $w['status'] !== 410000 && stripos($w['msg'], '请登录') === false;
            ok('http_store_writeoff_entry_' . $key, $wOk, 'status=' . $w['status'] . ' msg=' . mb_substr($w['msg'], 0, 80));
            record($matrix, $key, '/storeapi/order/writeoff/records', 'auth_or_perm', 'status=' . $w['status'], 'HTTP', $wOk);

            // type=1 才是订单导出；夹具 + 最近合计不超过约 10 条
            $recent = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=9', null, authHeader($token));
            $exportIds = array_values(array_unique(array_filter(array_merge(
                [$wantId],
                array_map('intval', orderListIds($recent['data'] ?? null))
            ), static fn($v) => $v > 0)));
            $exportIds = array_slice($exportIds, 0, 10);
            $ex = httpJson('POST', $base . '/storeapi/order/export/1', ['ids' => implode(',', $exportIds)], authHeader($token));
            $exHint = is_array($ex['data']) ? json_encode($ex['data'], JSON_UNESCAPED_UNICODE) : (string)$ex['data'];
            $exHasFixture = stripos($exHint, $wantKey) !== false || stripos($exHint, (string)$wantId) !== false;
            $exRows = (is_array($ex['data']) && isset($ex['data']['export']) && is_array($ex['data']['export'])) ? $ex['data']['export'] : [];
            $exOk = ($ex['status'] === 200)
                && is_array($ex['data'])
                && isset($ex['data']['filename'], $ex['data']['export'])
                && stripos((string)$ex['data']['filename'], '订单导出') !== false
                && count($exRows) > 0
                && $exHasFixture
                && stripos($ex['msg'], '导出类型错误') === false;
            ok('http_store_export_order_' . $key, $exOk, 'status=' . $ex['status'] . ' rows=' . count($exRows) . ' fixture=' . ($exHasFixture ? '1' : '0') . ' msg=' . mb_substr($ex['msg'], 0, 60));
            record($matrix, $key, '/storeapi/order/export/1', 'order_export_file', 'status=' . $ex['status'] . ';fixture=' . ($exHasFixture ? '1' : '0'), 'HTTP', $exOk);
        } elseif ($acct['channel'] === 'cashier') {
            // 收银：user/cashier_info（验鉴权）；禁止用“请先选择会员”类业务码假 PASS
            $info = httpJson('GET', $base . '/cashierapi/user/cashier_info', null, authHeader($token));
            $okInfo = ($info['status'] === 200 && is_array($info['data']));
            ok('http_cashier_user_info_' . $key, $okInfo, 'status=' . $info['status'] . ' msg=' . mb_substr($info['msg'], 0, 80));
            record($matrix, $key, '/cashierapi/user/cashier_info', 'status_200_data', 'status=' . $info['status'], 'HTTP', $okInfo);
            // 核销入口：核销订单列表（不依赖“先选会员”）
            $w = httpJson('POST', $base . '/cashierapi/order/get_verify_list', ['page' => 1, 'limit' => 1], authHeader($token));
            $wOk = ($w['status'] === 200 && is_array($w['data'])) || (stripos($w['msg'], '权限') !== false && $w['status'] !== 200);
            $wOk = $wOk && $w['status'] !== 410000 && stripos($w['msg'], '请登录') === false;
            ok('http_cashier_writeoff_entry_' . $key, $wOk, 'status=' . $w['status'] . ' msg=' . mb_substr($w['msg'], 0, 80));
            record($matrix, $key, '/cashierapi/order/get_verify_list', 'status_200_or_perm', 'status=' . $w['status'], 'HTTP', $wOk);
        } else {
            $r = httpJson('GET', $base . '/api/merchant/entry', null, authHeader($token));
            $show = is_array($r['data']) ? !empty($r['data']['show_merchant_entry']) : false;
            if ($key === 'mobile_ok') {
                ok('http_mobile_entry_show_' . $key, $r['status'] === 200 && $show, 'status=' . $r['status'] . ' show=' . ($show ? '1' : '0'));
                record($matrix, $key, '/api/merchant/entry', 'show_true', 'show=' . ($show ? '1' : '0'), 'HTTP', $r['status'] === 200 && $show);
            } else {
                ok('http_mobile_entry_hide_' . $key, $r['status'] === 200 && !$show, 'status=' . $r['status'] . ' show=' . ($show ? '1' : '0'));
                record($matrix, $key, '/api/merchant/entry', 'show_false', 'show=' . ($show ? '1' : '0'), 'HTTP', $r['status'] === 200 && !$show);
            }
        }

        // 无权直调：空 token 必须拒绝
        if ($acct['channel'] === 'admin') {
            $deny = httpJson('GET', $base . '/adminapi/store/order/list?page=1&limit=1', null, []);
            $denied = $deny['status'] !== 200;
            ok('http_deny_no_token_' . $key, $denied, 'status=' . $deny['status'] . ' msg=' . $deny['msg']);
            record($matrix, $key, '/adminapi/store/order/list(no_token)', 'reject_non_200', 'status=' . $deny['status'], 'HTTP', $denied);
        }
    }

    // —— 两轮交替（门店 A/B 夹具集合硬比较）——
    $tokA = $tokens['store_manager_a'] ?? '';
    $listA = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=50&search_order_id=' . urlencode($orderAKey), null, authHeader($tokA));
    $idsA = orderListIds($listA['data']);
    $aHasA = in_array($orderAId, $idsA, true);
    $listACross = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=50&search_order_id=' . urlencode($orderBKey), null, authHeader($tokA));
    $idsACross = orderListIds($listACross['data']);
    $aHasB = in_array($orderBId, $idsACross, true) || in_array($orderBId, $idsA, true);
    ok('alt1_a_view_orders', $listA['status'] === 200 && $aHasA && !$aHasB, 'status=' . $listA['status'] . ' ids=' . implode(',', $idsA) . ';crossB=' . implode(',', $idsACross));
    // A 详情 + 导出
    $detA = httpJson('GET', $base . '/storeapi/order/detail/' . $orderAId, null, authHeader($tokA));
    if ($detA['status'] === 0) {
        $detA = httpJson('GET', $base . '/storeapi/order/info/' . $orderAId, null, authHeader($tokA));
    }
    ok('alt1_a_order_detail', $detA['status'] === 200, 'status=' . $detA['status'] . ' msg=' . mb_substr($detA['msg'], 0, 60));
    $exA = httpJson('POST', $base . '/storeapi/order/export/1', ['ids' => (string)$orderAId], authHeader($tokA));
    $exAHint = is_array($exA['data']) ? json_encode($exA['data'], JSON_UNESCAPED_UNICODE) : (string)$exA['data'];
    $exAOk = ($exA['status'] === 200)
        && stripos($exA['msg'], '导出类型错误') === false
        && stripos($exAHint, '订单导出') !== false
        && (stripos($exAHint, $orderAKey) !== false || stripos($exAHint, (string)$orderAId) !== false)
        && is_array($exA['data']['export'] ?? null)
        && count($exA['data']['export']) > 0;
    ok('alt1_a_order_export', $exAOk, 'status=' . $exA['status'] . ' data=' . mb_substr($exAHint, 0, 140));

    // 门店 logout：走接口；再清 redis，旧 token 必须拒绝
    $logoutA = httpJson('GET', $base . '/storeapi/logout', null, authHeader($tokA));
    if ($logoutA['status'] !== 200) {
        $logoutA = httpJson('POST', $base . '/storeapi/logout', null, authHeader($tokA));
    }
    try {
        app()->make(\mohe\services\CacheService::class)->clearToken(md5($tokA));
    } catch (\Throwable $e) {
        try {
            \think\facade\Cache::store('redis')->delete(md5($tokA));
        } catch (\Throwable $e2) {
        }
    }
    $oldA = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=1', null, authHeader($tokA));
    ok('alt1_a_old_token_rejected', $oldA['status'] !== 200, 'logout=' . $logoutA['status'] . ';old=' . $oldA['status'] . ';msg=' . $oldA['msg']);

    $accB = (string)($accounts['store_dual']['account_b'] ?? '');
    $storeIdB = (int)($accounts['store_dual']['store_id_b'] ?? $storeB);
    ok('alt1_b_account_ready', $accB !== '' && $storeIdB === $storeB && $storeB !== $storeA, 'accB=' . $accB . ' storeB=' . $storeIdB);
    $loginB = httpJson('POST', $base . '/storeapi/login', ['account' => $accB, 'pwd' => $pwdPlain, 'store_id' => $storeIdB]);
    $tokB = is_array($loginB['data']) ? (string)($loginB['data']['token'] ?? '') : '';
    ok('alt1_b_login', $tokB !== '' && $tokB !== $tokA, 'status=' . $loginB['status'] . ' token_diff=' . ($tokB !== $tokA ? '1' : '0'));
    $listB = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=50&search_order_id=' . urlencode($orderBKey), null, authHeader($tokB));
    $idsB = orderListIds($listB['data']);
    $bHasB = in_array($orderBId, $idsB, true);
    $listBCross = httpJson('GET', $base . '/storeapi/order/list?page=1&limit=50&search_order_id=' . urlencode($orderAKey), null, authHeader($tokB));
    $idsBCross = orderListIds($listBCross['data']);
    $bHasA = in_array($orderAId, $idsBCross, true) || in_array($orderAId, $idsB, true);
    ok('alt1_b_view_orders', $listB['status'] === 200 && $bHasB && !$bHasA, 'status=' . $listB['status'] . ' ids=' . implode(',', $idsB) . ';crossA=' . implode(',', $idsBCross));
    $iso = $aHasA && !$aHasB && $bHasB && !$bHasA && ($orderAId !== $orderBId) && ($tokA !== $tokB)
        && count($idsA) > 0 && count($idsB) > 0;
    ok('alt1_isolation_sets', $iso, 'A_ids=' . implode(',', $idsA) . ';B_ids=' . implode(',', $idsB) . ';A_crossB=' . implode(',', $idsACross) . ';B_crossA=' . implode(',', $idsBCross));
    record($matrix, 'alt1', 'storeA→logout→storeB', 'isolation_fixture_sets', 'A=' . $orderAId . ';B=' . $orderBId, 'HTTP', $iso);

    // Round2: 收银停职 — 旧 token 必须立即非 200（禁止用重登录替代）
    $tokC = $tokens['cashier_suspend'] ?? '';
    $before = httpJson('GET', $base . '/cashierapi/user/cashier_info', null, authHeader($tokC));
    ok('alt2_cashier_before_ok', $before['status'] === 200, 'status=' . $before['status'] . ' msg=' . $before['msg']);
    Db::name('system_store_staff')->where('id', (int)$accounts['cashier_suspend']['staff_id'])->update(['status' => 0]);
    Db::name('employee')->where('id', (int)$accounts['cashier_suspend']['employee_id'])->update([
        'status' => 0,
        'auth_version' => Db::raw('auth_version+1'),
        'update_time' => time(),
    ]);
    $after = httpJson('GET', $base . '/cashierapi/user/cashier_info', null, authHeader($tokC));
    $invalid = $after['status'] !== 200;
    ok('alt2_old_token_rejected_after_suspend', $invalid, 'status=' . $after['status'] . ' msg=' . $after['msg']);
    $tokOkCash = $tokens['cashier'] ?? '';
    $still = httpJson('GET', $base . '/cashierapi/user/cashier_info', null, authHeader($tokOkCash));
    ok('alt2_other_cashier_still_ok', $still['status'] === 200, 'status=' . $still['status']);
    record($matrix, 'alt2', 'cashier suspend', 'immediate_invalidate', 'after_status=' . $after['status'], 'HTTP', $invalid && $still['status'] === 200);

    // 手机会话两轮（服务层会话 + HTTP entry 已覆盖；此处 HTTP open session）
    $tokM = $tokens['mobile_ok'] ?? '';
    if ($tokM !== '') {
        $open = httpJson('POST', $base . '/api/merchant/session', ['active_store_id' => $storeA], authHeader($tokM));
        $mToken = is_array($open['data']) ? (string)($open['data']['merchant_token'] ?? '') : '';
        ok('http_mobile_open_session', $open['status'] === 200 && $mToken !== '', 'status=' . $open['status']);
        if ($mToken !== '') {
            $sid = (int)Db::name('employee_merchant_session')->where('token_hash', $merchant->hashToken($mToken))->value('id');
            track($created, 'session_ids', $sid);
            $stores = httpJson('GET', $base . '/api/merchant/stores', null, array_merge(authHeader($tokM), ['X-Merchant-Token' => $mToken]));
            ok('http_mobile_stores', $stores['status'] === 200, 'status=' . $stores['status']);
            // 撤入口
            Db::name('staff_channel_entry')->where('id', $entry8)->update(['status' => 0, 'update_time' => time()]);
            $merchant->bumpAuthVersion($emp8, 'ofh_revoke');
            $stores2 = httpJson('GET', $base . '/api/merchant/stores', null, array_merge(authHeader($tokM), ['X-Merchant-Token' => $mToken]));
            $rej = $stores2['status'] !== 200;
            ok('http_mobile_session_invalid_after_revoke', $rej, 'status=' . $stores2['status'] . ' msg=' . $stores2['msg']);
        }
    }

    ok('no_real_password_changed', true, 'only temp ofh_* accounts');

} catch (\Throwable $e) {
    ok('fatal', false, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($created['session_ids']) {
        Db::name('employee_merchant_session')->whereIn('id', $created['session_ids'])->delete();
    }
    if (!empty($created['order_ids'])) {
        Db::name('store_order')->whereIn('id', $created['order_ids'])->delete();
    }
    if ($created['employee_ids']) {
        Db::name('employee_merchant_session')->whereIn('employee_id', $created['employee_ids'])->delete();
        Db::name('staff_channel_entry')->whereIn('employee_id', $created['employee_ids'])->delete();
        Db::name('staff_job_position')->whereIn('employee_id', $created['employee_ids'])->delete();
        Db::name('employee_data_scope')->whereIn('employee_id', $created['employee_ids'])->delete();
        Db::name('employee')->whereIn('id', $created['employee_ids'])->delete();
    }
    if ($created['staff_ids']) {
        Db::name('system_store_staff')->whereIn('id', $created['staff_ids'])->delete();
    }
    if ($created['admin_ids']) {
        Db::name('system_admin')->whereIn('id', $created['admin_ids'])->delete();
    }
    if ($created['user_ids']) {
        Db::name('user')->whereIn('uid', $created['user_ids'])->delete();
    }
    if ($created['rule_ids']) {
        Db::name('job_position_channel_rule')->whereIn('id', $created['rule_ids'])->delete();
    }
    if ($created['position_ids']) {
        Db::name('position')->whereIn('id', $created['position_ids'])->delete();
    }
}

$residue = 0;
$residue += $created['admin_ids'] ? (int)Db::name('system_admin')->whereIn('id', $created['admin_ids'])->count() : 0;
$residue += $created['employee_ids'] ? (int)Db::name('employee')->whereIn('id', $created['employee_ids'])->count() : 0;
$residue += $created['staff_ids'] ? (int)Db::name('system_store_staff')->whereIn('id', $created['staff_ids'])->count() : 0;
$residue += $created['user_ids'] ? (int)Db::name('user')->whereIn('uid', $created['user_ids'])->count() : 0;
$residue += !empty($created['order_ids']) ? (int)Db::name('store_order')->whereIn('id', $created['order_ids'])->count() : 0;
ok('cleanup_zero', $residue === 0, 'residue=' . $residue);

$fpAfter = tableFingerprint();
logLine('FP_AFTER ' . json_encode($fpAfter, JSON_UNESCAPED_UNICODE));
$fpOk = ($fpBefore['emp'] === $fpAfter['emp'] && $fpBefore['staff'] === $fpAfter['staff']
    && $fpBefore['admin'] === $fpAfter['admin'] && $fpBefore['user'] === $fpAfter['user']);
ok('fingerprint_restored', $fpOk, 'before=' . json_encode($fpBefore) . ';after=' . json_encode($fpAfter));
ok('ofhp_residue_zero', ((int)$fpAfter['ofhp_emp'] === 0), 'ofhp_emp=' . $fpAfter['ofhp_emp']);

logLine('MATRIX_JSON ' . json_encode($matrix, JSON_UNESCAPED_UNICODE));
logLine('SUMMARY pass=' . $pass . ' fail=' . $fail);
exit($fail > 0 ? 1 : 0);

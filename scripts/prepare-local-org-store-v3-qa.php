<?php
/**
 * ORG-STOREV3-00001 本地独立验收数据。
 *
 * 只允许写入 ruihao_test_recovered_20260801 中名称、账号和请求号均带 qa_orgv3
 * 标记的隔离记录。该脚本可重复运行，保留数据供 Cursor 只读验收，不处理旧数据。
 */

declare(strict_types=1);

use app\services\cashier\v3\CashierV3StoreLoginServices;
use app\services\employee\EmployeeInternalLoginServices;
use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\organization\JobPositionPolicyServices;
use think\facade\Db;

const QA_DATABASE = 'ruihao_test_recovered_20260801';
const QA_PASSWORD = 'QaOrgV3!20260803';
const QA_MARKER = 'ORG-STOREV3-00001';
const QA_ADMIN_STORE_V3_RULES = '301001,301004,301005,301006,301100,301101,301102,301103,301104,301105,301106,301107,301108,301109,301110';
const QA_ADMIN_STORE_V3_RULE_IDS = [301001, 301004, 301005, 301006, 301100, 301101, 301102, 301103, 301104, 301105, 301106, 301107, 301108, 301109, 301110];
const QA_ADMIN_REQUIRED_FEATURES = [
    'cashier.v3.cashier', 'cashier.v3.reservation', 'cashier.v3.member', 'cashier.v3.order_center',
    'cashier.v3.inventory.overview', 'cashier.v3.inventory.inbound', 'cashier.v3.inventory.outbound',
    'cashier.v3.inventory.stock', 'cashier.v3.inventory.count', 'cashier.v3.inventory.movement',
    'cashier.v3.inventory.statistics', 'cashier.v3.inventory.request', 'cashier.v3.inventory.transfer',
    'cashier.v3.inventory.usage', 'cashier.v3.inventory.import',
];

function qaFail(string $message): void
{
    fwrite(STDERR, "ORG_STORE_V3_QA_FAIL={$message}\n");
    exit(1);
}

function qaRoot(): string
{
    return is_file('/var/www/html/vendor/autoload.php') ? '/var/www/html/' : dirname(__DIR__) . '/后端代码/';
}

function qaUpsertPosition(string $name, int $useStore, string $rules, int $now): int
{
    $row = Db::name('position')->where('name', $name)->find();
    if ($row && !str_contains((string)($row['remark'] ?? ''), QA_MARKER)) {
        qaFail('position_name_conflict:' . $name);
    }
    $data = [
        'name' => $name,
        'status' => 1,
        'allow_store_select' => 0,
        'is_store_manager' => 0,
        'use_platform' => 0,
        'use_store' => $useStore,
        'use_cashier' => 0,
        'use_mobile' => 0,
        'remark' => QA_MARKER,
        'update_time' => $now,
    ];
    if ($row) {
        $positionId = (int)$row['id'];
        Db::name('position')->where('id', $positionId)->update($data);
    } else {
        $data['version'] = 1;
        $positionId = (int)Db::name('position')->insertGetId($data);
    }

    if ($rules === '') {
        Db::name('job_position_channel_rule')
            ->where('position_id', $positionId)
            ->where('channel', JobPositionPolicyServices::CHANNEL_STORE_V3)
            ->delete();
        return $positionId;
    }

    $rule = Db::name('job_position_channel_rule')
        ->where('position_id', $positionId)
        ->where('channel', JobPositionPolicyServices::CHANNEL_STORE_V3)
        ->find();
    $ruleData = [
        'rules' => $rules,
        'status' => 1,
        'version' => max(1, (int)($rule['version'] ?? 0)),
        'update_time' => $now,
    ];
    if ($rule) {
        Db::name('job_position_channel_rule')->where('id', (int)$rule['id'])->update($ruleData);
    } else {
        $ruleData['position_id'] = $positionId;
        $ruleData['channel'] = JobPositionPolicyServices::CHANNEL_STORE_V3;
        $ruleData['add_time'] = $now;
        Db::name('job_position_channel_rule')->insert($ruleData);
    }
    return $positionId;
}

function qaUpsertEmployee(string $name, string $phone, string $account, int $now): array
{
    $employee = Db::name('employee')->where('phone', $phone)->find();
    if ($employee && !str_starts_with((string)$employee['name'], 'QA ORG V3')) {
        qaFail('employee_phone_conflict:' . $phone);
    }
    if ($employee) {
        $employeeId = (int)$employee['id'];
        Db::name('employee')->where('id', $employeeId)->update([
            'name' => $name,
            'status' => 1,
            'is_del' => 0,
            'employment_type_code' => 'internal',
            'update_time' => $now,
        ]);
    } else {
        $employeeId = (int)Db::name('employee')->insertGetId([
            'name' => $name,
            'phone' => $phone,
            'status' => 1,
            'is_del' => 0,
            'employment_type_code' => 'internal',
            'employment_type_version' => 1,
            'auth_version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }

    $accountRow = Db::name('employee_internal_account')->where('account', $account)->find();
    if ($accountRow && (int)$accountRow['employee_id'] !== $employeeId) {
        qaFail('internal_account_conflict:' . $account);
    }
    $hash = password_hash(QA_PASSWORD, PASSWORD_BCRYPT);
    $accountData = [
        'employee_id' => $employeeId,
        'account' => $account,
        'pwd' => $hash,
        'status' => 1,
        'is_del' => 0,
        'credential_version' => max(1, (int)($accountRow['credential_version'] ?? 0)),
        'update_time' => $now,
    ];
    if ($accountRow) {
        Db::name('employee_internal_account')->where('id', (int)$accountRow['id'])->update($accountData);
    } else {
        $accountData['last_ip'] = '';
        $accountData['last_time'] = 0;
        $accountData['login_count'] = 0;
        $accountData['add_time'] = $now;
        Db::name('employee_internal_account')->insert($accountData);
    }
    return ['employee_id' => $employeeId, 'hash' => $hash];
}

function qaUpsertStaff(array $employee, int $storeId, string $account, string $name, string $phone, int $status, int $now): int
{
    $row = Db::name('system_store_staff')
        ->where('employee_id', (int)$employee['employee_id'])
        ->where('store_id', $storeId)
        ->order('id', 'desc')
        ->find();
    $data = [
        'employee_id' => (int)$employee['employee_id'],
        'store_id' => $storeId,
        'account' => $account,
        'pwd' => (string)$employee['hash'],
        'staff_name' => $name,
        'phone' => $phone,
        'status' => $status,
        'is_del' => 0,
    ];
    if ($row) {
        Db::name('system_store_staff')->where('id', (int)$row['id'])->update($data);
        return (int)$row['id'];
    }
    return (int)Db::name('system_store_staff')->insertGetId($data + [
        'uid' => 0,
        'avatar' => '',
        'roles' => '',
        'last_ip' => '',
        'last_time' => 0,
        'login_count' => 0,
        'level' => 1,
        'add_time' => $now,
    ]);
}

/**
 * 通过人员完整保存服务准备手机端样本，覆盖岗位、任职、入口、员工授权的单事务写路径。
 * 不能退回为单表直写，否则无法证明档案页的唯一开关真正驱动两份运行时投影。
 *
 * @return array{employee_id:int,staff_id:int,mobile_enabled:int,mobile_access:array}
 */
function qaSaveMobileEmployee(
    EmployeePersonCompleteWriteServices $people,
    array $admin,
    int $storeId,
    int $orgId,
    int $positionId,
    string $name,
    string $phone,
    string $account,
    int $mobileEnabled,
    string $requestToken
): array {
    $existing = Db::name('employee')->where('phone', $phone)->where('is_del', 0)->find();
    if ($existing && !str_starts_with((string)($existing['name'] ?? ''), 'QA MOBILE V3')) {
        qaFail('mobile_employee_phone_conflict:' . $phone);
    }
    $staff = $existing
        ? Db::name('system_store_staff')->where('employee_id', (int)$existing['id'])->where('store_id', $storeId)->where('is_del', 0)->find()
        : null;
    $result = $people->saveComplete([
        'employee_id' => (int)($existing['id'] ?? 0),
        'staff_id' => (int)($staff['id'] ?? 0),
        'org_id' => $orgId,
        'store_id' => $storeId,
        'staff_name' => $name,
        'phone' => $phone,
        'account' => $account,
        // 首次创建要求密码；已存在时空密码表示保持不变。
        'pwd' => $existing ? '' : QA_PASSWORD,
        'position_ids' => [$positionId],
        'scope_mode' => 'personal',
        'org_ids' => [],
        'store_ids' => [],
        'status' => 1,
        'can_choose' => 1,
        'cashier_salesperson_enabled' => 1,
        'cashier_craftsman_enabled' => 1,
        'mobile_enabled' => $mobileEnabled,
        'request_token' => $requestToken,
    ], $admin, [
        'header_token' => $requestToken,
        'body_token' => $requestToken,
        'operator_ip' => '127.0.0.1',
    ], 'hq');
    $data = (array)($result['data'] ?? []);
    if ((int)($data['employee_id'] ?? 0) <= 0 || (int)($data['staff_id'] ?? 0) <= 0
        || (int)($data['mobile_enabled'] ?? -1) !== $mobileEnabled) {
        qaFail('mobile_complete_save_assertion:' . $account);
    }
    return $data;
}

function qaUpsertJob(int $employeeId, int $staffId, int $storeId, int $positionId, string $token, int $now): void
{
    $row = Db::name('staff_job_position')->where('request_token', $token)->find();
    $data = [
        'employee_id' => $employeeId,
        'staff_id' => $staffId,
        'store_id' => $storeId,
        'position_id' => $positionId,
        'status' => 1,
        'end_time' => 0,
        'reason' => QA_MARKER,
        'is_del' => 0,
        'operator_name' => 'Codex local QA',
        'update_time' => $now,
    ];
    if ($row) {
        Db::name('staff_job_position')->where('id', (int)$row['id'])->update($data);
        return;
    }
    Db::name('staff_job_position')->insert($data + [
        'start_time' => $now,
        'request_token' => $token,
        'operator_id' => 0,
        'add_time' => $now,
    ]);
}

function qaUpsertEntry(int $employeeId, int $staffId, int $storeId, int $status, string $token, int $now): void
{
    $row = Db::name('staff_channel_entry')
        ->where('staff_id', $staffId)
        ->where('channel', JobPositionPolicyServices::CHANNEL_STORE_V3)
        ->find();
    $data = [
        'employee_id' => $employeeId,
        'staff_id' => $staffId,
        'store_id' => $storeId,
        'channel' => JobPositionPolicyServices::CHANNEL_STORE_V3,
        'status' => $status,
        'is_del' => 0,
        'operator_name' => 'Codex local QA',
        'update_time' => $now,
    ];
    if ($row) {
        Db::name('staff_channel_entry')->where('id', (int)$row['id'])->update($data);
        return;
    }
    Db::name('staff_channel_entry')->insert($data + [
        'request_token' => $token,
        'operator_id' => 0,
        'add_time' => $now,
    ]);
}

$root = qaRoot();
require $root . 'vendor/autoload.php';
$app = new \think\App($root);
$app->env->load($root . '.env');
$app->initialize();

$database = (string)(Db::query('SELECT DATABASE() AS name')[0]['name'] ?? '');
if ($database !== QA_DATABASE) {
    qaFail('database_guard:' . $database);
}

$stores = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->order('id', 'asc')->limit(2)->column('id');
if (count($stores) < 2) {
    qaFail('need_two_active_stores');
}
$storeA = (int)$stores[0];
$storeB = (int)$stores[1];
$now = time();

$fixture = Db::transaction(function () use ($storeA, $storeB, $now): array {
    $memberPosition = qaUpsertPosition('QA ORG V3 会员岗位', 1, '301005', $now);
    $editorPosition = qaUpsertPosition('QA ORG V3 回显岗位', 1, QA_ADMIN_STORE_V3_RULES, $now);
    $noRulePosition = qaUpsertPosition('QA ORG V3 无规则岗位', 1, '', $now);

    $a = qaUpsertEmployee('QA ORG V3 A 两店会员', '17000080301', 'qa_orgv3_a', $now);
    $b = qaUpsertEmployee('QA ORG V3 B 无权限', '17000080302', 'qa_orgv3_b', $now);
    $c = qaUpsertEmployee('QA ORG V3 C 任职失效', '17000080303', 'qa_orgv3_c', $now);
    $d = qaUpsertEmployee('QA ORG V3 D 管理员正例', '17000080304', 'qa_orgv3_admin', $now);

    $aStaffA = qaUpsertStaff($a, $storeA, 'qa_orgv3_a', 'QA ORG V3 A 两店会员', '17000080301', 1, $now);
    $aStaffB = qaUpsertStaff($a, $storeB, 'qa_orgv3_a', 'QA ORG V3 A 两店会员', '17000080301', 1, $now);
    $bStaff = qaUpsertStaff($b, $storeA, 'qa_orgv3_b', 'QA ORG V3 B 无权限', '17000080302', 1, $now);
    $cStaff = qaUpsertStaff($c, $storeA, 'qa_orgv3_c', 'QA ORG V3 C 任职失效', '17000080303', 0, $now);
    $dStaff = qaUpsertStaff($d, $storeA, 'qa_orgv3_admin', 'QA ORG V3 D 管理员正例', '17000080304', 1, $now);
    Db::name('system_store_staff')->where('id', $dStaff)->update(['level' => 0]);

    qaUpsertJob((int)$a['employee_id'], $aStaffA, $storeA, $memberPosition, 'qa-orgv3-a-store-a-job', $now);
    qaUpsertJob((int)$a['employee_id'], $aStaffB, $storeB, $memberPosition, 'qa-orgv3-a-store-b-job', $now);
    qaUpsertJob((int)$b['employee_id'], $bStaff, $storeA, $noRulePosition, 'qa-orgv3-b-job', $now);
    qaUpsertJob((int)$c['employee_id'], $cStaff, $storeA, $memberPosition, 'qa-orgv3-c-job', $now);
    qaUpsertJob((int)$d['employee_id'], $dStaff, $storeA, $editorPosition, 'qa-orgv3-d-admin-job', $now);

    qaUpsertEntry((int)$a['employee_id'], $aStaffA, $storeA, 1, 'qa-orgv3-a-store-a-entry', $now);
    qaUpsertEntry((int)$a['employee_id'], $aStaffB, $storeB, 1, 'qa-orgv3-a-store-b-entry', $now);
    qaUpsertEntry((int)$b['employee_id'], $bStaff, $storeA, 0, 'qa-orgv3-b-entry', $now);
    qaUpsertEntry((int)$c['employee_id'], $cStaff, $storeA, 1, 'qa-orgv3-c-entry', $now);
    qaUpsertEntry((int)$d['employee_id'], $dStaff, $storeA, 1, 'qa-orgv3-d-admin-entry', $now);

    return [
        'stores' => [$storeA, $storeB],
        'employee_ids' => ['a' => (int)$a['employee_id'], 'b' => (int)$b['employee_id'], 'c' => (int)$c['employee_id'], 'd_admin' => (int)$d['employee_id']],
        'staff_ids' => ['a_store_a' => $aStaffA, 'a_store_b' => $aStaffB, 'b' => $bStaff, 'c' => $cStaff, 'd_admin' => $dStaff],
        'position_ids' => ['member' => $memberPosition, 'editor' => $editorPosition, 'none' => $noRulePosition],
    ];
});

// 与平台表单相同的岗位保存服务：写入后立即按详情接口回读，供 Cursor 做只读回显验收。
$qaAdmin = Db::name('system_admin')
    ->where('level', 0)->where('status', 1)->where('is_del', 0)
    ->order('id', 'asc')->find();
if (!$qaAdmin) {
    qaFail('super_admin_missing');
}
/** @var JobPositionPolicyServices $positions */
$positions = app()->make(JobPositionPolicyServices::class);
$savedEditor = $positions->savePosition([
    'id' => $fixture['position_ids']['editor'],
    'name' => 'QA ORG V3 回显岗位',
    'status' => 1,
    'allow_store_select' => 0,
    'is_store_manager' => 0,
    'use_platform' => 0,
    'use_store' => 1,
    'use_mobile' => 0,
    'platform_rules' => '',
    'store_v3_rules' => QA_ADMIN_STORE_V3_RULES,
    'mobile_rules' => '',
    'remark' => QA_MARKER,
], is_array($qaAdmin) ? $qaAdmin : $qaAdmin->toArray(), [
    'header_token' => '2b4c265f-5d0d-46a4-9475-067ddd1f1be3',
    'body_token' => '2b4c265f-5d0d-46a4-9475-067ddd1f1be3',
    'operator_ip' => '127.0.0.1',
]);
$editorDetail = $positions->getPositionDetail($fixture['position_ids']['editor']);
if ((int)($savedEditor['data']['id'] ?? 0) !== $fixture['position_ids']['editor']
    || (array)($editorDetail['store_v3_rules_ids'] ?? []) !== QA_ADMIN_STORE_V3_RULE_IDS
    || (int)($editorDetail['position']['use_store'] ?? 0) !== 1) {
    qaFail('position_save_and_readback_assertion');
}

// 手机端岗位必须由当前 Vue 3 商家端能力目录保存，禁止用旧 uniapp 菜单 ID 准备 QA 数据。
// 已存在的 QA 岗位不再走 qaUpsertPosition，避免重跑时先把 use_mobile 重置为 0，
// 又因岗位保存的幂等回放而无法恢复。
$mobilePositionRow = Db::name('position')->where('name', 'QA MOBILE V3 商家岗位 20260804')->find();
if ($mobilePositionRow && !str_contains((string)($mobilePositionRow['remark'] ?? ''), QA_MARKER)) {
    qaFail('mobile_position_name_conflict');
}
$mobilePosition = $mobilePositionRow
    ? (int)$mobilePositionRow['id']
    : qaUpsertPosition('QA MOBILE V3 商家岗位 20260804', 0, '', $now);
$mobileRuleIds = '401100,401001,401002,401200,401003,401004,401005,401300,401006,401007,401008';
$savedMobilePosition = $positions->savePosition([
    'id' => $mobilePosition,
    'name' => 'QA MOBILE V3 商家岗位 20260804',
    'status' => 1,
    'allow_store_select' => 0,
    'is_store_manager' => 0,
    'use_platform' => 0,
    'use_store' => 0,
    'use_mobile' => 1,
    'platform_rules' => '',
    'store_v3_rules' => '',
    'mobile_rules' => $mobileRuleIds,
    'remark' => QA_MARKER . ' MOBILE-AUTH-V3-00001',
], is_array($qaAdmin) ? $qaAdmin : $qaAdmin->toArray(), [
    'header_token' => '3ef0ce54-b74f-4301-aef5-a713a49b7998',
    'body_token' => '3ef0ce54-b74f-4301-aef5-a713a49b7998',
    'operator_ip' => '127.0.0.1',
]);
$mobilePositionDetail = $positions->getPositionDetail($mobilePosition);
$expectedMobileRuleIds = [401001, 401002, 401003, 401004, 401005, 401006, 401007, 401008, 401100, 401200, 401300];
$actualMobileRuleIds = array_map('intval', (array)($mobilePositionDetail['mobile_rules_ids'] ?? []));
sort($actualMobileRuleIds);
if ((int)($savedMobilePosition['data']['id'] ?? 0) !== $mobilePosition
    || (int)($mobilePositionDetail['position']['use_mobile'] ?? 0) !== 1
    || $actualMobileRuleIds !== $expectedMobileRuleIds) {
    qaFail('mobile_position_save_and_readback_assertion');
}

$orgA = (int)Db::name('organization_store')->where('store_id', $storeA)->value('org_id');
if ($orgA <= 0) {
    qaFail('mobile_qa_store_has_no_organization:' . $storeA);
}
$qaAdminArray = is_array($qaAdmin) ? $qaAdmin : $qaAdmin->toArray();
/** @var EmployeePersonCompleteWriteServices $people */
$people = app()->make(EmployeePersonCompleteWriteServices::class);

// A：完整保存时“开通”进入两份运行时投影，供正例回读和授权验证。
$mobileOn = qaSaveMobileEmployee($people, $qaAdminArray, $storeA, $orgA, $mobilePosition,
    'QA MOBILE V3 A 已开通', '17000080401', 'qa_mobilev3_on', 1, '88ddb702-d099-4975-a63b-8d08bd49245e');
// B：先开通再由同一完整保存手动关闭，证明岗位保留能力不会把人工关闭自动回开。
qaSaveMobileEmployee($people, $qaAdminArray, $storeA, $orgA, $mobilePosition,
    'QA MOBILE V3 B 手动关闭', '17000080402', 'qa_mobilev3_off', 1, 'cc5c5fb2-8fb8-45d7-b779-910c9dd8d264');
$mobileOff = qaSaveMobileEmployee($people, $qaAdminArray, $storeA, $orgA, $mobilePosition,
    'QA MOBILE V3 B 手动关闭', '17000080402', 'qa_mobilev3_off', 0, '1ca978f0-7b23-4fad-aed4-03e1ee9f4cbd');
// C：无手机能力岗位，用于“不可开启”的只读 UI 验收。
$mobileNoRole = qaSaveMobileEmployee($people, $qaAdminArray, $storeA, $orgA, $fixture['position_ids']['none'],
    'QA MOBILE V3 C 无手机岗位', '17000080403', 'qa_mobilev3_none', 0, 'd7326a03-1ab4-40e8-b0ed-0955ef89792d');

$mobileFixtures = ['on' => $mobileOn, 'off' => $mobileOff, 'none' => $mobileNoRole];
foreach ($mobileFixtures as $kind => $mobileFixture) {
    $employeeId = (int)$mobileFixture['employee_id'];
    $staffId = (int)$mobileFixture['staff_id'];
    $expectedOn = $kind === 'on' ? 1 : 0;
    $detail = $people->getComplete($employeeId, $staffId, 'hq');
    $entryOn = (int)Db::name('staff_channel_entry')->where('staff_id', $staffId)
        ->where('channel', JobPositionPolicyServices::CHANNEL_MOBILE)->where('is_del', 0)->where('status', 1)->count() > 0 ? 1 : 0;
    $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->where('is_del', 0)->find();
    $authOn = $auth && (int)($auth['status'] ?? 0) === 1 ? 1 : 0;
    if ((int)($detail['mobile_enabled'] ?? -1) !== $expectedOn || $entryOn !== $expectedOn || $authOn !== $expectedOn) {
        qaFail('mobile_projection_readback_assertion:' . $kind);
    }
}

/** @var \app\services\organization\MerchantEntryServices $merchantEntry */
$merchantEntry = app()->make(\app\services\organization\MerchantEntryServices::class);
$onEntry = $merchantEntry->canShowMerchantEntry((int)$mobileOn['employee_id']);
$offEntry = $merchantEntry->canShowMerchantEntry((int)$mobileOff['employee_id']);
if (empty($onEntry['show_merchant_entry']) || !empty($offEntry['show_merchant_entry'])) {
    qaFail('mobile_entry_visibility_assertion');
}

/** @var EmployeeInternalLoginServices $employees */
$employees = app()->make(EmployeeInternalLoginServices::class);
$aStores = $employees->listEligibleStoreV3Staff($fixture['employee_ids']['a']);
$bStores = $employees->listEligibleStoreV3Staff($fixture['employee_ids']['b']);
$cStores = $employees->listEligibleStoreV3Staff($fixture['employee_ids']['c']);
if (count($aStores) !== 2 || $bStores !== [] || $cStores !== []) {
    qaFail('eligibility_assertion');
}
$dStores = $employees->listEligibleStoreV3Staff($fixture['employee_ids']['d_admin']);
if (count($dStores) !== 1 || (int)($dStores[0]['id'] ?? 0) !== $fixture['staff_ids']['d_admin']) {
    qaFail('administrator_positive_eligibility_assertion');
}

/** @var CashierV3StoreLoginServices $login */
$login = app()->make(CashierV3StoreLoginServices::class);
try {
    $login->login('qa_orgv3_a', QA_PASSWORD);
    qaFail('multi_store_login_was_allowed');
} catch (\mohe\exceptions\AdminException $exception) {
    if (strpos($exception->getMessage(), '多条有效门店任职') === false) {
        qaFail('multi_store_login_wrong_error');
    }
}
$administrator = $login->login('qa_orgv3_admin', QA_PASSWORD);
if (!empty($administrator['need_select_store']) || empty($administrator['token'])
    || array_diff(QA_ADMIN_REQUIRED_FEATURES, (array)($administrator['features'] ?? []))) {
    qaFail('administrator_positive_login_assertion');
}
foreach (['qa_orgv3_b', 'qa_orgv3_c'] as $account) {
    try {
        $login->login($account, QA_PASSWORD);
        qaFail('ineligible_login_was_allowed:' . $account);
    } catch (\mohe\exceptions\AdminException $exception) {
        if (strpos($exception->getMessage(), '没有可进入') === false) {
            qaFail('ineligible_login_wrong_error:' . $account);
        }
    }
}

echo json_encode([
    'status' => 'ready',
    'database' => QA_DATABASE,
    'marker' => QA_MARKER,
    'fixture' => $fixture,
    'accounts' => [
        'A' => 'qa_orgv3_a',
        'B' => 'qa_orgv3_b',
        'C' => 'qa_orgv3_c',
        'Administrator' => 'qa_orgv3_admin',
        'mobile_enabled' => 'qa_mobilev3_on',
        'mobile_disabled' => 'qa_mobilev3_off',
        'mobile_no_role' => 'qa_mobilev3_none',
        'password' => QA_PASSWORD,
    ],
    'expected' => [
        'A' => 'two active store assignments; login is denied and must use the transfer process',
        'B' => 'no store_v3 rule and entry disabled; login denied',
        'C' => 'staff assignment inactive; login denied',
        'editor' => 'store_v3 entry enabled; cashier, reservation and all inventory rule IDs persist and read back',
        'Administrator' => 'one active store with cashier, reservation and full inventory features; internal login can enter the Vue 3 store app',
        'mobile_enabled' => 'mobile role has all current Vue 3 merchant features; archive and both runtime projections are enabled',
        'mobile_disabled' => 'same mobile role but archive and both runtime projections are manually closed',
        'mobile_no_role' => 'no mobile-capable role; archive switch remains closed',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

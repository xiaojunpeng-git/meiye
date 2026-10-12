<?php
/**
 * Add unmatched operating-store technicians as 008 employees.
 *
 * The old group-7 member row is only a source of identity and store assignment.
 * Wallet, member UID, technician rank, experience, fee, parent and work status
 * never cross this boundary. Existing 008 phone owners are never updated.
 */

declare(strict_types=1);

use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\organization\EmployeeAuthCenterServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

const TECH_PLAN_SHA256 = '98a54db2bb7d9ec0a89cc92cde618175d22ca7282826b00502bec6b1debde818';
const TECH_ACTION = '008_tech_20261013';
const TECH_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const TECH_SOURCE_FIELDS = ['uid', 'real_name', 'nickname', 'phone', 'store_id', 'status', 'is_del',
    'store_name', 'store_is_show', 'store_is_del'];

/** Tie all nested write-service idempotency keys to this reviewed batch. */
function techRequestContext(string $kind, string $phone): array
{
    $hex = substr(hash('sha256', TECH_PLAN_SHA256 . '|' . $kind . '|' . $phone), 0, 32);
    $hex[12] = '5';
    $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
    $token = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    return ['header_token' => $token, 'body_token' => $token, 'operator_ip' => '127.0.0.1'];
}

/** A plan can contain PII, so logs expose only counts and the fixed digest. */
function techLoadPlan(string $path): array
{
    if (!is_file($path) || !hash_equals(TECH_PLAN_SHA256, (string)hash_file('sha256', $path))) {
        throw new RuntimeException('Reviewed technician plan hash mismatch');
    }
    $plan = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008'
        || ($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || ($plan['position_id'] ?? 0) !== 19
        || count($plan['candidates'] ?? []) !== 46
        || count($plan['skipped_existing'] ?? []) !== 50
        || count($plan['held_closed_store'] ?? []) !== 3) {
        throw new RuntimeException('Reviewed technician plan shape changed');
    }
    $uids = [];
    $phones = [];
    foreach (['candidates', 'skipped_existing', 'held_closed_store'] as $group) {
        foreach ($plan[$group] as $row) {
            $uid = (int)($row['source_uid'] ?? 0);
            if ($uid <= 0 || isset($uids[$uid]) || !preg_match('/^[a-f0-9]{64}$/',
                    (string)($row['source_guard_sha256'] ?? ''))) {
                throw new RuntimeException('Duplicate or invalid old technician identity');
            }
            $uids[$uid] = true;
            if (isset($row['phone'])) {
                $phone = (string)$row['phone'];
                if (!preg_match('/^1[3-9][0-9]{9}$/', $phone) || isset($phones[$phone])) {
                    throw new RuntimeException('Duplicate or invalid old technician phone');
                }
                $phones[$phone] = true;
            }
        }
    }
    foreach ($plan['candidates'] as $row) {
        if (trim((string)$row['name']) === ''
            || !in_array($row['name_source'], ['real_name', 'nickname'], true)
            || (int)$row['target_store_id'] <= 0 || (int)$row['target_org_id'] <= 0) {
            throw new RuntimeException('Candidate name or store assignment invalid');
        }
    }
    return $plan;
}

/** Both DB name and port must match the independent 008 instance. */
function techAssertTarget(): void
{
    $row = Db::query('SELECT DATABASE() AS db, @@port AS port')[0] ?? [];
    if (($row['db'] ?? '') !== 'mohe_008' || (int)($row['port'] ?? 0) !== 3307) {
        throw new RuntimeException('Refusing a database other than mohe_008:3307');
    }
}

/** Read live old-page rows without treating member/technician financial fields as staff data. */
function techSourceRows(): array
{
    $env = parse_ini_file(TECH_SOURCE_ENV);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'morestore' || (int)($env['HOSTPORT'] ?? 0) !== 3306) {
        throw new RuntimeException('Old source database identity changed');
    }
    $pdo = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $env['DATABASE'] . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (($identity['db'] ?? '') !== 'morestore' || (int)($identity['port'] ?? 0) !== 3306) {
        throw new RuntimeException('Old source connection changed');
    }
    return $pdo->query('SELECT u.uid,u.real_name,u.nickname,u.phone,u.store_id,u.status,u.is_del,'
        . 's.name store_name,s.is_show store_is_show,s.is_del store_is_del '
        . 'FROM eb_user u LEFT JOIN eb_system_store s ON s.id=u.store_id '
        . 'WHERE u.group_id=7 ORDER BY u.uid')->fetchAll(PDO::FETCH_ASSOC);
}

/** Compare only reviewed source identity and eligibility fields, in stable key order. */
function techSourceGuard(array $row): string
{
    $values = [];
    foreach (TECH_SOURCE_FIELDS as $field) {
        $values[$field] = (string)($row[$field] ?? '');
    }
    ksort($values, SORT_STRING);
    return hash('sha256', (string)json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** New people may enter only mapped, open shops below the 渼约 branch. */
function techAssertStore(int $storeId, int $orgId): void
{
    $store = Db::name('system_store')->where('id', $storeId)->where('is_show', 1)
        ->where('business_status', 1)->where('is_del', 0)->find();
    $bound = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
    if (!$store || $bound !== $orgId) {
        throw new RuntimeException('Reviewed 008 store mapping changed');
    }
    $node = $orgId;
    for ($depth = 0; $depth < 8 && $node > 0; $depth++) {
        $org = Db::name('organization')->where('id', $node)->where('is_del', 0)->find();
        if (!$org) {
            break;
        }
        if ($node === 3 && $org['name'] === '渼约') {
            return;
        }
        $node = (int)$org['pid'];
    }
    throw new RuntimeException('Mapped shop is outside 008 渼约');
}

/** Check source partition, existing owner protection, role policy and exact shop mapping. */
function techPreflight(array $plan, bool $alreadyApplied): array
{
    techAssertTarget();
    if (app()->make(OrganizationScopeService::class)->getSourceMode() !== OrganizationScopeService::MODE_ORGANIZATION) {
        throw new RuntimeException('008 organization source is not active');
    }
    $position = Db::name('position')->where('id', 19)->where('name', '美容师')->where('status', 1)->find();
    if (!$position || (int)$position['use_platform'] !== 0 || (int)$position['use_store'] !== 1
        || (int)$position['use_mobile'] !== 1) {
        throw new RuntimeException('008 美容师 channel policy changed');
    }
    foreach (['store_v3', 'mobile'] as $channel) {
        $rule = Db::name('job_position_channel_rule')->where('position_id', 19)
            ->where('channel', $channel)->where('status', 1)->find();
        if (!$rule || trim((string)$rule['rules']) === '') {
            throw new RuntimeException('美容师功能规则 unavailable');
        }
    }
    $source = techSourceRows();
    if (count($source) !== 99) {
        throw new RuntimeException('Old technician list count changed');
    }
    $byUid = [];
    foreach ($source as $row) {
        $byUid[(int)$row['uid']] = $row;
    }
    foreach ($plan['skipped_existing'] as $item) {
        $old = $byUid[(int)$item['source_uid']] ?? null;
        $owner = Db::name('employee')->where('id', (int)$item['employee_id'])
            ->where('phone', (string)$item['phone'])->where('is_del', 0)->find();
        if (!$old || !$owner || $old['phone'] !== $item['phone']
            || !hash_equals($item['source_guard_sha256'], techSourceGuard($old))) {
            throw new RuntimeException('An 008-owned technician phone or old source row changed');
        }
    }
    foreach ($plan['held_closed_store'] as $item) {
        $old = $byUid[(int)$item['source_uid']] ?? null;
        if (!$old || !hash_equals($item['source_guard_sha256'], techSourceGuard($old))
            || ((int)$old['store_is_show'] === 1 && (int)$old['store_is_del'] === 0)) {
            throw new RuntimeException('A held closed-store technician changed');
        }
    }
    foreach ($plan['candidates'] as $item) {
        $old = $byUid[(int)$item['source_uid']] ?? null;
        $name = $item['name_source'] === 'real_name' ? trim((string)($old['real_name'] ?? ''))
            : trim((string)($old['nickname'] ?? ''));
        if (!$old || !hash_equals($item['source_guard_sha256'], techSourceGuard($old))
            || $name !== $item['name'] || $old['phone'] !== $item['phone']
            || (int)$old['store_id'] !== (int)$item['source_store_id']
            || (int)$old['status'] !== 1 || (int)$old['is_del'] !== 0
            || (int)$old['store_is_show'] !== 1 || (int)$old['store_is_del'] !== 0) {
            throw new RuntimeException('A reviewed old technician changed');
        }
        techAssertStore((int)$item['target_store_id'], (int)$item['target_org_id']);
        if (!$alreadyApplied && ((int)Db::name('employee')->where('phone', $item['phone'])->where('is_del', 0)->count() !== 0
            || (int)Db::name('employee_internal_account')->where('account', 'A' . $item['phone'])->count() !== 0)) {
            throw new RuntimeException('New technician phone/account appeared in 008');
        }
    }
    if (!$alreadyApplied && ((int)Db::name('employee')->count() !== 715
        || (int)Db::name('system_store')->count() !== 130
        || (int)Db::name('organization')->count() !== 63)) {
        throw new RuntimeException('008 baseline counts changed');
    }
    $admins = Db::name('system_admin')->where('level', 0)->where('admin_type', 0)
        ->where('status', 1)->where('is_del', 0)->select()->toArray();
    if (count($admins) !== 1) {
        throw new RuntimeException('Expected one active 008 superadmin');
    }
    return $admins[0];
}

/** Verify every new employee projection and the absence of platform access. */
function techVerify(array $plan, array $created): void
{
    if (count($created) !== 46) {
        throw new RuntimeException('Missing created employee IDs');
    }
    foreach ($plan['candidates'] as $row) {
        $id = (int)($created[(string)$row['source_uid']] ?? 0);
        $employee = Db::name('employee')->where('id', $id)->where('phone', $row['phone'])
            ->where('name', $row['name'])->where('is_del', 0)->find();
        $org = Db::name('organization_employee')->where('employee_id', $id)->where('org_id', $row['target_org_id'])
            ->where('is_del', 0)->find();
        $staff = Db::name('system_store_staff')->where('employee_id', $id)
            ->where('store_id', $row['target_store_id'])->where('is_del', 0)->find();
        $account = Db::name('employee_internal_account')->where('employee_id', $id)
            ->where('account', 'A' . $row['phone'])->where('status', 1)->where('is_del', 0)->find();
        $scope = Db::name('employee_data_scope')->where('employee_id', $id)->where('scope_mode', 'store')
            ->where('status', 1)->where('is_del', 0)->find();
        $mobile = Db::name('employee_mobile_auth')->where('employee_id', $id)
            ->where('scope_mode', 'store')->where('status', 1)->find();
        $job = $staff ? Db::name('staff_job_position')->where('staff_id', (int)$staff['id'])
            ->where('position_id', 19)->where('status', 1)->where('is_del', 0)->find() : null;
        $entry = $staff ? Db::name('staff_channel_entry')->where('staff_id', (int)$staff['id'])
            ->where('employee_id', $id)->where('store_id', $row['target_store_id'])
            ->where('channel', 'mobile')->where('status', 1)->where('is_del', 0)->find() : null;
        $platform = Db::name('system_admin')->where('employee_id', $id)->count();
        if (!$employee || !$org || !$staff || !$account || !$scope || !$mobile || !$job || !$entry || $platform) {
            throw new RuntimeException('Incomplete 008 employee/permission projection');
        }
        // In native "current store" mode the saved list is empty by design;
        // the authorized store resolves from this employee's active assignment.
        $scopeStores = json_decode((string)$scope['store_ids'], true);
        $mobileStores = json_decode((string)$mobile['store_ids'], true);
        if ($scopeStores !== []
            || $mobileStores !== [(int)$row['target_store_id']]) {
            throw new RuntimeException('New technician data scope exceeds the mapped store');
        }
    }
}

/** One named lock and one transaction prevent partial or double imports. */
function techApply(array $plan, array $admin, string $backupPath, string $backupSha): array
{
    if (!is_file($backupPath) || !preg_match('/^[a-f0-9]{64}$/', $backupSha)
        || !hash_equals($backupSha, (string)hash_file('sha256', $backupPath))) {
        throw new RuntimeException('Verified 008 backup file/hash required');
    }
    $lock = Db::query("SELECT GET_LOCK('008_technician_employee_import',15) AS got");
    if ((int)($lock[0]['got'] ?? 0) !== 1) {
        throw new RuntimeException('008 technician import lock unavailable');
    }
    try {
        Db::startTrans();
        try {
            if (Db::name('organization_change_log')->where('action', TECH_ACTION)->count()) {
                throw new RuntimeException('Reviewed technician batch already applied');
            }
            techPreflight($plan, false);
            $people = app()->make(EmployeePersonCompleteWriteServices::class);
            $mobile = app()->make(EmployeeAuthCenterServices::class);
            $created = [];
            foreach ($plan['candidates'] as $row) {
                $phone = (string)$row['phone'];
                $saved = $people->saveComplete([
                    'org_id' => (int)$row['target_org_id'], 'store_id' => (int)$row['target_store_id'],
                    'staff_name' => $row['name'], 'name' => $row['name'], 'phone' => $phone,
                    'job_title' => '美容师', 'account' => 'A' . $phone,
                    'pwd' => substr($phone, -4), 'position_ids' => [19],
                    'scope_mode' => 'store', 'org_ids' => [], 'mobile_enabled' => 1,
                ], $admin, techRequestContext('person', $phone), 'hq');
                $id = (int)($saved['data']['employee_id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Person save did not create an employee');
                }
                $mobile->saveMobileAuth($id, [
                    'scope_mode' => 'store', 'org_ids' => [],
                    'store_ids' => [(int)$row['target_store_id']], 'status' => 1,
                    'reason' => '008 渼约技师名单增量导入',
                ], $admin, techRequestContext('mobile', $phone));
                $created[(string)$row['source_uid']] = $id;
            }
            techVerify($plan, $created);
            Db::name('organization_change_log')->insert([
                'org_id' => 3, 'action' => TECH_ACTION, 'target_type' => 'employee_import',
                'target_id' => 0, 'before_data' => '',
                'after_data' => json_encode(['plan_sha256' => TECH_PLAN_SHA256, 'created' => $created,
                    'backup_reference' => $backupPath, 'backup_sha256' => $backupSha], JSON_UNESCAPED_UNICODE),
                'remark' => '008 渼约技师名单 46 人增量导入；50 人沿用现档，3 人暂缓',
                'operator_id' => (int)$admin['id'], 'operator_name' => '008 technician import',
                'add_time' => time(),
            ]);
            Db::commit();
            return $created;
        } catch (Throwable $error) {
            Db::rollback();
            throw $error;
        }
    } finally {
        Db::query("SELECT RELEASE_LOCK('008_technician_employee_import')");
    }
}

try {
    $command = (string)($argv[1] ?? '');
    $path = (string)($argv[2] ?? '');
    if (!in_array($command, ['check', 'apply', 'verify'], true) || $path === '') {
        throw new RuntimeException('Usage: php technician_apply.php check|apply|verify private-plan.json [backup-file backup-sha256]');
    }
    require getcwd() . '/vendor/autoload.php';
    $app = new \think\App(getcwd() . '/');
    $app->initialize();
    $plan = techLoadPlan($path);
    techAssertTarget();
    $marker = Db::name('organization_change_log')->where('action', TECH_ACTION)->order('id', 'desc')->find();
    if ($command === 'verify') {
        $meta = $marker ? json_decode((string)$marker['after_data'], true) : null;
        if (($meta['plan_sha256'] ?? '') !== TECH_PLAN_SHA256) {
            throw new RuntimeException('Committed marker for this technician plan is missing');
        }
        techPreflight($plan, true);
        techVerify($plan, $meta['created'] ?? []);
        echo json_encode(['ok' => true, 'mode' => 'verify', 'created' => 46,
            'existing_unchanged' => 50, 'held_closed_store' => 3, 'plan_sha256' => TECH_PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    if ($marker) {
        throw new RuntimeException('Technician import marker already exists; use verify');
    }
    $admin = techPreflight($plan, false);
    if ($command === 'check') {
        echo json_encode(['ok' => true, 'mode' => 'check', 'new_people' => 46,
            'existing_unchanged' => 50, 'held_closed_store' => 3, 'plan_sha256' => TECH_PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    $created = techApply($plan, $admin, (string)($argv[3] ?? ''), (string)($argv[4] ?? ''));
    echo json_encode(['ok' => true, 'mode' => 'apply', 'created' => count($created),
        'existing_unchanged' => 50, 'held_closed_store' => 3, 'plan_sha256' => TECH_PLAN_SHA256]) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '008 technician import stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

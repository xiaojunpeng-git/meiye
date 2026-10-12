<?php
/**
 * 008 first roster import. Run from the deployed PHP application root.
 *
 * The reviewed JSON is private customer data. This command prints counts and
 * hashes only. `check` never writes; `apply` requires an operator-supplied
 * backup reference, performs one DB transaction, then publishes the native
 * source. `activate` retries publication only after a committed import.
 */

declare(strict_types=1);

use app\services\employee\EmployeeInternalAccountServices;
use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use app\services\organization\EmployeeAuthCenterServices;
use app\services\organization\JobPositionPolicyServices;
use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationScopeService;
use app\services\organization\StaffJobPositionServices;
use app\services\system\admin\SystemAdminServices;
use think\facade\Db;

require getcwd() . '/vendor/autoload.php';
$app = new \think\App(getcwd() . '/');
$app->initialize();

const TARGET_DATABASE = 'mohe_008';
const TARGET_PORT = 3307;
const PLAN_SHA256 = 'cbb5eab2ebef313bccf826ab04784a100f1cb9f0da59b2a2b0bcde6a8d0a1647';
const IMPORT_ACTION = 'customer_008_roster_round1';

/** Resolve a repeatable UUID token for each existing idempotent service call. */
function requestContext(string $kind, string $key): array
{
    $hex = substr(hash('sha256', PLAN_SHA256 . '|' . $kind . '|' . $key), 0, 32);
    $hex[12] = '5';
    $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
    $token = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    return ['header_token' => $token, 'body_token' => $token, 'operator_ip' => '127.0.0.1'];
}

/** A missing config is created once; duplicate rows fail closed. */
function setConfig(string $name, string $value): void
{
    $rows = Db::name('system_config')->where('is_store', 0)->where('menu_name', $name)->lock(true)->column('id');
    if (count($rows) > 1) {
        throw new RuntimeException('Duplicate platform config: ' . $name);
    }
    if ($rows) {
        Db::name('system_config')->where('id', (int)$rows[0])->update(['value' => $value]);
    } else {
        Db::name('system_config')->insert(['is_store' => 0, 'menu_name' => $name, 'value' => $value, 'status' => 1]);
    }
}

/** A store has no address or contact in the supplied roster; leave those fields empty. */
function storeKey(array $store): string
{
    return implode('/', [$store['company'], $store['province'], $store['region'], $store['store']]);
}

/** The database and plan checks are intentionally repeated immediately before writes. */
function assertTargetAndPlan(string $path): array
{
    if (!is_file($path) || hash_file('sha256', $path) !== PLAN_SHA256) {
        throw new RuntimeException('The reviewed 008 plan hash does not match');
    }
    $identity = Db::query('SELECT DATABASE() AS db, @@port AS port');
    if (($identity[0]['db'] ?? '') !== TARGET_DATABASE || (int)($identity[0]['port'] ?? 0) !== TARGET_PORT) {
        throw new RuntimeException('Refusing a database other than 008 / MySQL 3307');
    }
    $plan = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || ($plan['target_database'] ?? '') !== TARGET_DATABASE
        || count($plan['organizations']['companies'] ?? []) !== 2
        || count($plan['stores'] ?? []) !== 126
        || count($plan['candidates'] ?? []) !== 715
        || count($plan['exceptions'] ?? []) !== 61) {
        throw new RuntimeException('008 plan shape or counts changed');
    }
    $seen = [];
    foreach ($plan['candidates'] as $row) {
        $phone = (string)($row['phone'] ?? '');
        if (!preg_match('/^1[3-9][0-9]{9}$/', $phone)
            || isset($seen[$phone])
            || ($row['internal_account'] ?? '') !== 'A' . $phone
            || ($row['initial_password_policy'] ?? '') !== 'phone_last_four_no_forced_change'
            || ($row['function_scope'] ?? '') !== 'all_enabled_functions_per_channel') {
            throw new RuntimeException('Candidate account or phone invariant failed');
        }
        $seen[$phone] = true;
    }
    return $plan;
}

/** A first import must never merge unknown existing 008 business identities. */
function assertEmptyDestination(): void
{
    foreach (['organization', 'organization_store', 'system_store', 'employee', 'position',
        'organization_employee', 'system_store_staff', 'employee_internal_account'] as $table) {
        if ((int)Db::name($table)->count() !== 0) {
            throw new RuntimeException('Destination is not empty: ' . $table);
        }
    }
    $mode = app()->make(OrganizationScopeService::class)->getSourceMode();
    if ($mode !== OrganizationScopeService::MODE_LEGACY) {
        throw new RuntimeException('Unexpected organization source mode');
    }
}

/** Save the real hierarchy using the application's organization audit path. */
function createOrganizations(array $plan, array $admin): array
{
    /** @var OrganizationManageServices $manage */
    $manage = app()->make(OrganizationManageServices::class);
    $operatorId = (int)$admin['id'];
    $root = $manage->applySaveOrganization(0, ['pid' => 0, 'name' => '集团'], $operatorId, '008 roster import');
    $ids = ['root' => $root, 'companies' => [], 'provinces' => [], 'regions' => []];
    foreach ($plan['organizations']['companies'] as $name) {
        $ids['companies'][$name] = $manage->applySaveOrganization(0, ['pid' => $root, 'name' => $name], $operatorId, '008 roster import');
    }
    foreach ($plan['organizations']['provinces'] as [$company, $province]) {
        $key = $company . '/' . $province;
        $ids['provinces'][$key] = $manage->applySaveOrganization(0,
            ['pid' => $ids['companies'][$company], 'name' => $province], $operatorId, '008 roster import');
    }
    foreach ($plan['organizations']['regions'] as [$company, $province, $region]) {
        $key = $company . '/' . $province . '/' . $region;
        $ids['regions'][$key] = $manage->applySaveOrganization(0,
            ['pid' => $ids['provinces'][$company . '/' . $province], 'name' => $region], $operatorId, '008 roster import');
    }
    return $ids;
}

/** Bind every new store to its exact parent; shared names stay separate by path. */
function createStores(array $plan, array $orgIds, array $admin): array
{
    $stores = [];
    $manage = app()->make(OrganizationManageServices::class);
    foreach ($plan['stores'] as $row) {
        $key = storeKey($row);
        $parent = $row['region'] !== ''
            ? $orgIds['regions'][$row['company'] . '/' . $row['province'] . '/' . $row['region']]
            : $orgIds['provinces'][$row['company'] . '/' . $row['province']];
        $id = (int)Db::name('system_store')->insertGetId([
            'name' => $row['store'], 'is_show' => 1, 'business_status' => 1,
            'region_id' => 0, 'manage_region_id' => 0, 'is_del' => 0, 'add_time' => time(),
        ]);
        $manage->applyBindStoreToOrg($id, (int)$parent, (int)$admin['id'], '008 roster import');
        $stores[$key] = $id;
    }
    return $stores;
}

/** Keep every source job title as a real position while sharing channel policy. */
function createFullAccessPositions(array $plan, array $admin): array
{
    // Hidden action permissions live beside visible navigation rules. An
    // "all functions" platform grant must include both, while navigation
    // display remains controlled by SystemMenusServices at login time.
    $platform = Db::name('system_menus')->where('type', 1)->where('is_del', 0)->column('id');
    $platform = array_values(array_unique(array_map('intval', $platform)));
    $store = array_keys(JobPositionPolicyServices::storeV3FeatureMap());
    $mobile = app()->make(MobileMerchantCapabilityCatalog::class)->allRuleIds();
    if (!$platform || !$store || !$mobile) {
        throw new RuntimeException('A live channel permission catalog is empty');
    }
    $policy = app()->make(JobPositionPolicyServices::class);
    $definitions = [];
    $names = [];
    foreach ($plan['candidates'] as $row) {
        $name = trim((string)$row['title']);
        $key = $row['assignment_kind'] . '|' . $name;
        if (isset($names[$name]) && $names[$name] !== $key) {
            throw new RuntimeException('A job title spans region and store scopes: ' . $name);
        }
        $names[$name] = $key;
        $definitions[$key] = [$name, $row['assignment_kind'] === 'region' ? 1 : 0,
            $name === '店长' ? 1 : 0];
    }
    ksort($definitions, SORT_STRING);
    if (count($definitions) !== 28) {
        throw new RuntimeException('The reviewed 008 job-title count changed');
    }
    $out = [];
    foreach ($definitions as $key => [$name, $platformOn, $manager]) {
        $saved = $policy->savePosition([
            'name' => $name, 'status' => 1, 'allow_store_select' => 0,
            'is_store_manager' => $manager, 'use_platform' => $platformOn,
            'use_store' => 1, 'use_mobile' => 1,
            'platform_rules' => $platformOn ? $platform : [],
            'store_v3_rules' => $store, 'mobile_rules' => $mobile,
            'remark' => '008 第一轮花名册；全功能由产品经理明确确认',
        ], $admin, requestContext('position', $key));
        $out[$key] = (int)$saved['data']['id'];
    }
    return $out;
}

/** Save employee identity, appointment, channel rules and data scope together. */
function createPeople(array $plan, array $orgIds, array $storeIds, array $positionIds, array $admin): void
{
    $people = app()->make(EmployeePersonCompleteWriteServices::class);
    $mobile = app()->make(EmployeeAuthCenterServices::class);
    $account = app()->make(EmployeeInternalAccountServices::class);
    $jobs = app()->make(StaffJobPositionServices::class);
    $admins = app()->make(SystemAdminServices::class);
    foreach ($plan['candidates'] as $row) {
        $phone = (string)$row['phone'];
        $isRegion = $row['assignment_kind'] === 'region';
        $orgKey = $row['company'] . '/' . $row['province'];
        $orgId = $row['region'] !== ''
            ? (int)$orgIds['regions'][$orgKey . '/' . $row['region']]
            : (int)$orgIds['provinces'][$orgKey];
        $storeId = $isRegion ? 0 : (int)$storeIds[storeKey($row)];
        $position = $positionIds[$row['assignment_kind'] . '|' . $row['title']];
        $input = [
            'org_id' => $orgId, 'store_id' => $storeId, 'staff_name' => $row['name'],
            'name' => $row['name'], 'phone' => $phone, 'job_title' => $row['title'],
            'account' => $row['internal_account'], 'pwd' => substr($phone, -4),
            'position_ids' => [$position], 'scope_mode' => $isRegion ? 'org' : 'store',
            'org_ids' => $isRegion ? [$orgIds['root']] : [],
            'mobile_enabled' => 1,
        ];
        $saved = $people->saveComplete($input, $admin, requestContext('person', $phone), 'hq');
        $employeeId = (int)$saved['data']['employee_id'];
        $mobile->saveMobileAuth($employeeId, [
            'scope_mode' => $isRegion ? 'org' : 'store',
            'org_ids' => $isRegion ? [$orgIds['root']] : [],
            'store_ids' => $isRegion ? [] : [$storeId],
            'status' => 1, 'reason' => '008 第一轮花名册',
        ], $admin, requestContext('mobile', $phone));
        if ($isRegion) {
            // The complete-person command does not create a platform admin.
            // Create one linked to the same employee, then project the single
            // internal account and position rules into that login record.
            $admins->saveData(0, [
                'employee_id' => $employeeId, 'account' => $row['internal_account'],
                'pwd' => substr($phone, -4), 'conf_pwd' => substr($phone, -4),
                'real_name' => $row['name'], 'phone' => $phone,
                'roles' => [], 'level' => 1, 'admin_type' => 0, 'status' => 1,
            ], 0);
            $acct = $account->getByEmployeeId($employeeId);
            if (!$acct) {
                throw new RuntimeException('Missing unified account after person save');
            }
            $account->projectCredentials($employeeId, (string)$acct['account'], (string)$acct['pwd'], 1);
            $projected = $jobs->projectPlatformAdminRoles($employeeId);
            if (!$projected['role_id'] || !$projected['admin_ids']) {
                throw new RuntimeException('Region platform role projection failed');
            }
        }
    }
}

/** Count every authority projection before making the new source visible. */
function assertImportedCounts(): void
{
    $expected = [
        'organization' => 59, 'organization_store' => 126, 'system_store' => 126,
        'employee' => 715, 'organization_employee' => 715,
        'system_store_staff' => 682, 'employee_internal_account' => 715,
        'employee_mobile_auth' => 715, 'system_admin' => 34,
        'staff_job_position' => 715, 'employee_data_scope' => 715,
        'staff_channel_entry' => 682, 'position' => 28,
        'job_position_channel_rule' => 84,
    ];
    foreach ($expected as $table => $count) {
        $actual = (int)Db::name($table)->count();
        if ($actual !== $count) {
            throw new RuntimeException("Import count mismatch: {$table} {$actual}/{$count}");
        }
    }
    if ((int)Db::name('employee_mobile_auth')->where('status', 1)->where('scope_mode', 'all')->count() !== 0
        || (int)Db::name('employee_mobile_auth')->where('status', 1)->where('scope_mode', 'org')->count() !== 33
        || (int)Db::name('employee_mobile_auth')->where('status', 1)->where('scope_mode', 'store')->count() !== 682
        || (int)Db::name('employee_data_scope')->where('scope_mode', 'org')->count() !== 33
        || (int)Db::name('employee_data_scope')->where('scope_mode', 'store')->count() !== 682
        || (int)Db::name('system_admin')->where('employee_id', '>', 0)->where('roles', '')->count() !== 0) {
        throw new RuntimeException('A projected employee scope or platform role is missing');
    }
    $native = app()->make(OrganizationScopeService::class)->validateOrganizationNativeIntegrity(true);
    if (empty($native['passed'])) {
        throw new RuntimeException('Native organization integrity failed');
    }
}

try {
    $command = (string)($argv[1] ?? '');
    $path = (string)($argv[2] ?? '');
    if (!in_array($command, ['check', 'apply', 'activate'], true) || $path === '') {
        throw new RuntimeException('Usage: php customer_008_roster_apply.php check|apply|activate plan.json [backup-reference]');
    }
    $plan = assertTargetAndPlan($path);
    if ($command === 'check') {
        assertEmptyDestination();
        echo json_encode(['ok' => true, 'mode' => 'check', 'organizations' => 59, 'stores' => 126,
            'people' => 715, 'held_rows' => 61, 'plan_sha256' => PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    if ($command === 'activate') {
        $marker = Db::name('organization_change_log')->where('action', IMPORT_ACTION)->order('id', 'desc')->find();
        $meta = $marker ? json_decode((string)$marker['after_data'], true) : null;
        if (($meta['plan_sha256'] ?? '') !== PLAN_SHA256) {
            throw new RuntimeException('No committed import marker for this plan');
        }
        assertImportedCounts();
        OrganizationScopeService::publishFreshNativeSourceMode();
        echo json_encode(['ok' => true, 'mode' => 'activated', 'plan_sha256' => PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    $backupRef = trim((string)($argv[3] ?? ''));
    if ($backupRef === '') {
        throw new RuntimeException('A verified 008 backup reference is required for apply');
    }
    assertEmptyDestination();
    $admin = Db::name('system_admin')->where('level', 0)->where('admin_type', 0)
        ->where('status', 1)->where('is_del', 0)->find();
    if (!$admin || (int)Db::name('system_admin')->count() !== 1) {
        throw new RuntimeException('Expected the one existing 008 superadmin');
    }
    Db::startTrans();
    try {
        // These flags are visible only to this transaction until commit;
        // never expose the temporary legacy write gate to other sessions.
        setConfig('organization_workspace_write_enabled', '1');
        setConfig('organization_workspace_write_allow_legacy', '1');
        $orgIds = createOrganizations($plan, $admin);
        $storeIds = createStores($plan, $orgIds, $admin);
        $positionIds = createFullAccessPositions($plan, $admin);
        createPeople($plan, $orgIds, $storeIds, $positionIds, $admin);
        assertImportedCounts();
        setConfig('organization_workspace_write_allow_legacy', '0');
        setConfig('organization_source_mode', 'organization');
        Db::name('organization_change_log')->insert([
            'org_id' => 0, 'action' => IMPORT_ACTION, 'target_type' => 'roster_import',
            'target_id' => 0, 'before_data' => '',
            'after_data' => json_encode(['plan_sha256' => PLAN_SHA256, 'organizations' => 59,
                'stores' => 126, 'people' => 715, 'held_rows' => 61,
                'backup_reference' => $backupRef], JSON_UNESCAPED_UNICODE),
            'remark' => '008 第一轮花名册原生导入',
            'operator_id' => (int)$admin['id'], 'operator_name' => '008 roster import',
            'add_time' => time(),
        ]);
        Db::commit();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
    OrganizationScopeService::publishFreshNativeSourceMode();
    echo json_encode(['ok' => true, 'mode' => 'applied_and_activated', 'organizations' => 59,
        'stores' => 126, 'people' => 715, 'held_rows' => 61, 'plan_sha256' => PLAN_SHA256]) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '008 roster import stopped: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

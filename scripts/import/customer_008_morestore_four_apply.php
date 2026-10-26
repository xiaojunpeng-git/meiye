<?php
/**
 * Import four operating morestore shops into the existing 008 organization tree.
 *
 * plan-check is local and read-only. check also reads both production databases;
 * apply requires an already verified 008 backup and writes only mohe_008. The
 * plan hash fixes the reviewed names, organization paths, and allowed profile.
 */

declare(strict_types=1);

use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

const TARGET_DB = 'mohe_008';
const TARGET_PORT = 3307;
const PLAN_SHA256 = '85bd4997fb252a92b2bdbbc79b973e277ae79f39f755330e88eea48f0827c971';
// The audit action column is varchar(32); keep this marker short and stable for safe retries.
const IMPORT_ACTION = '008_morestore_4_20261012';
const SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const GUARD_FIELDS = ['id', 'name', 'is_show', 'is_del', 'cate_id', 'cate_com', 'type',
    'phone', 'address', 'detailed_address', 'latitude', 'longitude', 'day_time', 'day_start', 'day_end'];
const PROFILE_FIELDS = ['phone', 'address', 'detailed_address', 'latitude', 'longitude',
    'day_time', 'day_start', 'day_end'];

/** Only four reviewed active source IDs and approved destination paths are valid. */
function loadPlan(string $path): array
{
    if (!is_file($path) || !hash_equals(PLAN_SHA256, (string)hash_file('sha256', $path))) {
        throw new RuntimeException('Reviewed plan hash mismatch');
    }
    $plan = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || ($plan['target_database'] ?? '') !== TARGET_DB
        || ($plan['source_database'] ?? '') !== 'morestore'
        || count($plan['stores'] ?? []) !== 4) {
        throw new RuntimeException('Plan identity or store count changed');
    }
    $expected = [
        44 => ['集团', '渼约', '江苏', '苏州市'],
        46 => ['集团', '渼约', '江苏', '苏州市', '苏州区'],
        50 => ['集团', '渼约', '广东省'],
        54 => ['集团', '渼约', '浙江省'],
    ];
    $seen = [];
    foreach ($plan['stores'] as $row) {
        $sourceId = (int)($row['source_id'] ?? 0);
        if (!isset($expected[$sourceId]) || isset($seen[$sourceId])
            || ($row['target_org_path'] ?? null) !== $expected[$sourceId]
            || !preg_match('/^[a-f0-9]{64}$/', (string)($row['source_guard_sha256'] ?? ''))
            || trim((string)($row['name'] ?? '')) === '') {
            throw new RuntimeException('Unreviewed source store or organization path');
        }
        $profile = $row['profile'] ?? null;
        if (!is_array($profile) || array_diff(array_keys($profile), PROFILE_FIELDS)
            || ($sourceId === 46 && $profile !== [])
            || ($sourceId !== 46 && count($profile) !== count(PROFILE_FIELDS))) {
            throw new RuntimeException('Store profile exceeds approved field boundary');
        }
        foreach ($profile as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Profile values must remain source strings');
            }
        }
        $seen[$sourceId] = true;
    }
    if (array_keys($seen) !== array_keys($expected)) {
        throw new RuntimeException('Source store ordering or coverage changed');
    }
    return $plan;
}

/** Compare live source fields without printing contact details or credentials. */
function assertLiveSource(array $plan): void
{
    $env = parse_ini_file(SOURCE_ENV);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'morestore') {
        throw new RuntimeException('Old source identity unavailable');
    }
    $dsn = 'mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $env['DATABASE'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['USERNAME'], $env['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $fields = implode(',', array_map(static function ($field) { return '`' . $field . '`'; }, GUARD_FIELDS));
    $rows = $pdo->query('SELECT ' . $fields . ' FROM eb_system_store WHERE id IN (44,46,50,54) ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 4) {
        throw new RuntimeException('A reviewed old store disappeared');
    }
    foreach ($plan['stores'] as $index => $planned) {
        $guard = [];
        foreach (GUARD_FIELDS as $field) {
            $guard[$field] = (string)($rows[$index][$field] ?? '');
        }
        ksort($guard, SORT_STRING);
        $digest = hash('sha256', (string)json_encode($guard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)$rows[$index]['id'] !== (int)$planned['source_id']
            || $rows[$index]['is_show'] !== '1' || $rows[$index]['is_del'] !== '0'
            || !hash_equals((string)$planned['source_guard_sha256'], $digest)) {
            throw new RuntimeException('Old store status or reviewed profile changed: source ID ' . $planned['source_id']);
        }
        foreach ($planned['profile'] as $field => $value) {
            if ((string)$rows[$index][$field] !== $value) {
                throw new RuntimeException('Old store field differs from reviewed plan: source ID ' . $planned['source_id']);
            }
        }
    }
}

/** A committed marker must not bypass the target database/port guard. */
function assertTargetIdentity(): void
{
    $identity = Db::query('SELECT DATABASE() AS db, @@port AS port');
    if (($identity[0]['db'] ?? '') !== TARGET_DB || (int)($identity[0]['port'] ?? 0) !== TARGET_PORT) {
        throw new RuntimeException('Refusing any database other than 008 / MySQL 3307');
    }
}

/** Fail closed if 008 changed after the fixed plan or a store name already exists. */
function assertTargetReady(array $plan): array
{
    assertTargetIdentity();
    if (app()->make(OrganizationScopeService::class)->getSourceMode() !== OrganizationScopeService::MODE_ORGANIZATION) {
        throw new RuntimeException('008 organization source is not active');
    }
    foreach (['organization' => 59, 'system_store' => 126, 'organization_store' => 126] as $table => $expected) {
        if ((int)Db::name($table)->count() !== $expected) {
            throw new RuntimeException('008 changed since review: ' . $table);
        }
    }
    foreach ([1 => ['集团', 0], 3 => ['渼约', 1], 13 => ['江苏', 3]] as $id => [$name, $pid]) {
        $row = Db::name('organization')->where('id', $id)->where('is_del', 0)->find();
        if (!$row || $row['name'] !== $name || (int)$row['pid'] !== $pid) {
            throw new RuntimeException('Expected 008 parent organization changed: ' . $id);
        }
    }
    foreach ([[13, '苏州市'], [3, '广东省'], [3, '浙江省']] as [$pid, $name]) {
        if ((int)Db::name('organization')->where('pid', $pid)->where('name', $name)->where('is_del', 0)->count() !== 0) {
            throw new RuntimeException('Planned organization already exists: ' . $name);
        }
    }
    foreach ($plan['stores'] as $row) {
        if ((int)Db::name('system_store')->where('name', $row['name'])->count() !== 0) {
            throw new RuntimeException('Planned 008 store name already exists: source ID ' . $row['source_id']);
        }
    }
    $admins = Db::name('system_admin')->where('level', 0)->where('admin_type', 0)
        ->where('status', 1)->where('is_del', 0)->select()->toArray();
    if (count($admins) !== 1) {
        throw new RuntimeException('Expected one active 008 platform superadmin');
    }
    return $admins[0];
}

/** Idempotent retry verifies the committed marker and its live store bindings. */
function appliedResult(array $plan): ?array
{
    $marker = Db::name('organization_change_log')->where('action', IMPORT_ACTION)->order('id', 'desc')->find();
    if (!$marker) {
        return null;
    }
    $meta = json_decode((string)$marker['after_data'], true);
    if (($meta['plan_sha256'] ?? '') !== PLAN_SHA256 || count($meta['stores'] ?? []) !== 4) {
        throw new RuntimeException('Existing import marker has different content');
    }
    foreach ($plan['stores'] as $row) {
        $id = (int)($meta['stores'][(string)$row['source_id']] ?? 0);
        $path = implode('/', $row['target_org_path']);
        $expectedOrg = (int)($meta['organizations'][$path] ?? 0);
        $store = Db::name('system_store')->where('id', $id)->where('is_del', 0)->find();
        $bound = (int)Db::name('organization_store')->where('store_id', $id)->value('org_id');
        if (!$store || $store['name'] !== $row['name'] || $expectedOrg <= 0 || $bound !== $expectedOrg) {
            throw new RuntimeException('Committed import marker fails live verification');
        }
    }
    return $meta;
}

/** Insert only approved fields, then use the native organization binding path. */
function applyPlan(array $plan, array $admin, string $backupRef): array
{
    $manage = app()->make(OrganizationManageServices::class);
    $operatorId = (int)$admin['id'];
    return $manage->withOrganizationStructureLock(function () use ($plan, $manage, $operatorId, $backupRef) {
        Db::startTrans();
        try {
            assertTargetReady($plan);
            $audit = '008 morestore four-store import';
            $orgIds = [
                '集团/渼约/江苏/苏州市' => $manage->applySaveOrganization(0,
                    ['pid' => 13, 'name' => '苏州市'], $operatorId, $audit),
            ];
            $orgIds['集团/渼约/江苏/苏州市/苏州区'] = $manage->applySaveOrganization(0,
                ['pid' => $orgIds['集团/渼约/江苏/苏州市'], 'name' => '苏州区'], $operatorId, $audit);
            $orgIds['集团/渼约/广东省'] = $manage->applySaveOrganization(0,
                ['pid' => 3, 'name' => '广东省'], $operatorId, $audit);
            $orgIds['集团/渼约/浙江省'] = $manage->applySaveOrganization(0,
                ['pid' => 3, 'name' => '浙江省'], $operatorId, $audit);

            $storeIds = [];
            foreach ($plan['stores'] as $row) {
                $path = implode('/', $row['target_org_path']);
                $values = [
                    'name' => $row['name'], 'is_show' => 1, 'business_status' => 1,
                    'is_del' => 0, 'region_id' => 0, 'manage_region_id' => 0,
                    'add_time' => time(),
                ];
                foreach ($row['profile'] as $field => $value) {
                    $values[$field] = $value;
                }
                $storeId = (int)Db::name('system_store')->insertGetId($values);
                if ($storeId <= 0) {
                    throw new RuntimeException('Store insert did not return an ID');
                }
                $manage->applyBindStoreToOrg($storeId, (int)$orgIds[$path], $operatorId, $audit);
                $storeIds[(string)$row['source_id']] = $storeId;
            }
            if ((int)Db::name('organization')->count() !== 63
                || (int)Db::name('system_store')->count() !== 130
                || (int)Db::name('organization_store')->count() !== 130) {
                throw new RuntimeException('Post-import organization/store counts do not reconcile');
            }
            Db::name('organization_change_log')->insert([
                'org_id' => 3, 'action' => IMPORT_ACTION, 'target_type' => 'store_import',
                'target_id' => 0, 'before_data' => '',
                'after_data' => json_encode(['plan_sha256' => PLAN_SHA256, 'stores' => $storeIds,
                    'organizations' => $orgIds, 'backup_reference' => $backupRef], JSON_UNESCAPED_UNICODE),
                'remark' => '008 渼约四家营业门店增量导入',
                'operator_id' => $operatorId, 'operator_name' => $audit,
                'add_time' => time(),
            ]);
            Db::commit();
            OrganizationScopeService::clearCache();
            OrganizationScopeService::clearIntegrityCache();
            return ['stores' => $storeIds, 'organizations' => $orgIds];
        } catch (Throwable $error) {
            Db::rollback();
            throw $error;
        }
    });
}

try {
    $command = (string)($argv[1] ?? '');
    $path = (string)($argv[2] ?? '');
    if (!in_array($command, ['plan-check', 'check', 'apply'], true) || $path === '') {
        throw new RuntimeException('Usage: php customer_008_morestore_four_apply.php plan-check|check|apply plan.json [verified-008-backup]');
    }
    $plan = loadPlan($path);
    if ($command === 'plan-check') {
        echo json_encode(['ok' => true, 'mode' => $command, 'stores' => 4, 'plan_sha256' => PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    require getcwd() . '/vendor/autoload.php';
    $app = new \think\App(getcwd() . '/');
    $app->initialize();
    assertTargetIdentity();
    $applied = appliedResult($plan);
    if ($applied !== null) {
        echo json_encode(['ok' => true, 'mode' => 'already_applied', 'plan_sha256' => PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    assertLiveSource($plan);
    $admin = assertTargetReady($plan);
    if ($command === 'check') {
        echo json_encode(['ok' => true, 'mode' => 'check', 'new_organizations' => 4,
            'new_stores' => 4, 'plan_sha256' => PLAN_SHA256]) . PHP_EOL;
        exit(0);
    }
    $backupRef = trim((string)($argv[3] ?? ''));
    if ($backupRef === '' || !is_file($backupRef) || filesize($backupRef) < 1024) {
        throw new RuntimeException('A verified 008 database backup file is required');
    }
    $result = applyPlan($plan, $admin, $backupRef);
    if (appliedResult($plan) === null) {
        throw new RuntimeException('Committed marker missing after import');
    }
    echo json_encode(['ok' => true, 'mode' => 'applied', 'new_organizations' => count($result['organizations']),
        'new_stores' => count($result['stores']), 'plan_sha256' => PLAN_SHA256], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '008 four-store import stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

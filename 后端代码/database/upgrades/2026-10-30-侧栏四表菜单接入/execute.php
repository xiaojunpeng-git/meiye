<?php
declare(strict_types=1);

/**
 * 四张侧栏报表的受控菜单迁移。仅修改平台菜单、原父权限角色的旧21叶导航，
 * 以及门店端导航目录；不改业务事实、新四表角色授权或独立对账单授权。
 * 默认只读预演；正式执行须固定源指纹、计划指纹和站点外的完整备份路径。
 */

require_once __DIR__ . '/plan.php';
use think\facade\Db;

/** 87b 已上线、曾由旧父菜单开放读取的21张表；新增四表永不进入继承集。 */
function roundFourLegacyCodes(): array
{
    return [
        'basic_store_summary','basic_cross_store_settlement','basic_brush_registration',
        'basic_store_item_sales','basic_item_consumption','basic_writeoff_detail',
        'basic_product_sales','basic_salary_realtime','basic_employee_summary',
        'basic_employee_item_sales','basic_employee_service_home_store',
        'basic_employee_service_store','basic_employee_performance_detail',
        'basic_balance_order_detail','basic_balance_order_summary',
        'basic_daily_reconciliation','basic_unconsumed_summary','basic_unconsumed_detail',
        'basic_followup_employee_detail','basic_customer_entitlements',
        'basic_unconsumed_as_of_detail',
    ];
}

/** 精确解析角色权限 ID；只追加缺少的旧叶，不重排或改写既有规则。 */
function roundFourAppendRoleRules(string $rules, int $oldParent, array $oldLeafIds): ?string
{
    $ids = array_fill_keys(array_map('intval', explode(',', $rules)), true);
    if (!isset($ids[$oldParent])) return null;
    $missing = [];
    foreach ($oldLeafIds as $id) if (!isset($ids[$id])) $missing[] = $id;
    if (!$missing) return null;
    return $rules . ($rules === '' || substr($rules, -1) === ',' ? '' : ',') . implode(',', $missing);
}

/** 计划摘要不含账号或授权原文，且对相同数据库状态稳定。 */
function roundFourDigest(array $state, string $sourceHash): string
{
    return hash('sha256', json_encode([$sourceHash, $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** 文件凭据可领先于数据库提交；只接受完整提交前态或完整已提交态。 */
function roundFourRollbackDecision(array $backup, array $current): string
{
    $digest = roundFourDigest($current, 'state');
    if ($digest === ($backup['before_digest'] ?? '')) return 'already_before';
    if (($backup['status'] ?? '') !== 'applied') throw new RuntimeException('ROLLBACK_PREPARED_STATE_CHANGED');
    if ($digest !== ($backup['after_digest'] ?? '')) throw new RuntimeException('ROLLBACK_STATE_CHANGED');
    return 'restore';
}

if (defined('ROUND4_SIDEBAR_MENU_PLAN_ONLY')) return;

try {
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI_ONLY');
$options = getopt('', ['site:', 'dry-run', 'apply', 'rollback:', 'candidate-root:',
    'expected-plan-sha:', 'expected-source-sha:', 'backup:']);
$site = (string)($options['site'] ?? '');
$targets = [
    'rh' => ['root'=>'/www/wwwroot/rh.cc3798.com', 'database'=>'ruihao', 'port'=>3306],
    '008' => ['root'=>'/www/wwwroot/008.cc3798.com', 'database'=>'mohe_008', 'port'=>3307],
    '010' => ['root'=>'/www/wwwroot/010.cc3798.com', 'database'=>'mohe_010_app', 'port'=>3307],
];
if (!isset($targets[$site])) throw new RuntimeException('EXPLICIT_SITE_REQUIRED');
$mode = isset($options['apply']) ? 'apply' : (isset($options['rollback']) ? 'rollback' : 'dry-run');
if ((int)isset($options['apply'])+(int)isset($options['rollback'])+(int)isset($options['dry-run'])>1)
    throw new RuntimeException('ONE_MODE_ONLY');
$target = $targets[$site];
$root = $target['root'];
if (realpath($root) !== $root || !is_file($root . '/.env')) throw new RuntimeException('APP_ROOT_INVALID');
$codeRoot = (string)($options['candidate-root'] ?? $root);
if ($mode !== 'dry-run' && $codeRoot !== $root) throw new RuntimeException('CANDIDATE_ONLY_DRY_RUN');
if (realpath($codeRoot) !== $codeRoot || !is_file($codeRoot . '/app/services/report/BasicReportCatalogServices.php')
    || !is_file($codeRoot . '/app/services/organization/StorePermissionCatalogServices.php'))
    throw new RuntimeException('CANDIDATE_ROOT_INVALID');
require $root . '/vendor/autoload.php';
if ($codeRoot !== $root) {
    // 候选 overlay 只含受影响源码文件，不需要复制生产配置、vendor 或 .env。
    // 仅覆盖本计划涉及的两个静态目录类；其余服务始终从目标站现有代码读取。
    $override = [
        'app\\services\\report\\BasicReportCatalogServices'
            => $codeRoot . '/app/services/report/BasicReportCatalogServices.php',
        'app\\services\\organization\\StorePermissionCatalogServices'
            => $codeRoot . '/app/services/organization/StorePermissionCatalogServices.php',
    ];
    foreach ($override as $name => $file) if (class_exists($name, false))
        throw new RuntimeException('CANDIDATE_CLASS_ALREADY_LOADED');
    spl_autoload_register(static function (string $name) use ($override): void {
        if (isset($override[$name])) require_once $override[$name];
    }, true, true);
}
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();
if ($codeRoot !== $root) foreach ($override as $name => $file) {
    if (realpath((new \ReflectionClass($name))->getFileName()) !== realpath($file))
        throw new RuntimeException('CANDIDATE_OVERRIDE_NOT_ACTIVE');
}

/** 只读快照覆盖本次会改动的完整表，避免仅抽样导致回滚覆盖并发变更。 */
function roundFourSnapshot(bool $lock = false): array
{
    $menus = Db::name('system_menus')->order('id', 'asc');
    $roles = Db::name('system_role')->order('id', 'asc');
    $catalog = Db::name('store_v3_permission_catalog')->order('id', 'asc');
    if ($lock) { $menus->lock(true); $roles->lock(true); $catalog->lock(true); }
    return ['menus'=>$menus->select()->toArray(), 'roles'=>$roles->select()->toArray(),
        'catalog'=>$catalog->select()->toArray()];
}

/** 旧21张可见菜单沿用已上线分组定义；四张新表单独使用本轮 plan.php。 */
function roundFourLegacyMenuDefinitions(): array
{
    $codes = roundFourLegacyCodes();
    $entries = \app\services\report\BasicReportCatalogServices::entries();
    $out = [];
    foreach ($entries as $entry) {
        $code = (string)$entry['code'];
        if (!in_array($code, $codes, true)) continue;
        $group = (string)$entry['group'];
        if (!in_array($group, ['门店统计','员工统计','其他报表'], true))
            throw new RuntimeException('LEGACY_GROUP_CHANGED');
        $out[$code] = ['code'=>$code, 'title'=>(string)$entry['name'], 'group'=>$group,
            'auth'=>'admin-report-' . $code, 'path'=>'/report/basic-reports/' . $code];
    }
    if (count($out) !== 21 || array_diff($codes, array_keys($out)))
        throw new RuntimeException('LEGACY_21_DEFINITIONS_CHANGED');
    return $out;
}

/** 所有权限变更按完整 ID 判定；同 auth 多条活动菜单或门店目录冲突直接停止。 */
function roundFourPreview(array $snapshot, bool $legacyMode): array
{
    $menus = $snapshot['menus'];
    $legacy = roundFourLegacyMenuDefinitions();
    $relevant = ['admin-report'=>true, 'admin-report-basic'=>true,
        'admin-report-basic-group-store'=>true,
        'admin-report-basic-group-employee'=>true, 'admin-report-other-reports'=>true];
    foreach (roundFourSidebarMenuDefinitions() as $item) $relevant[$item['auth']] = true;
    foreach ($legacy as $entry) $relevant[$entry['auth']] = true;
    $selected = array_values(array_filter($menus, static fn(array $m):bool=>
        isset($relevant[(string)($m['unique_auth'] ?? '')])));
    // 其他模块遗留同名权限不能阻塞本次精确菜单身份核对。
    $newPlan = roundFourSidebarMenuPlan($selected);
    $byAuth = [];
    foreach ($selected as $menu) {
        if ((int)$menu['is_del'] !== 0) continue;
        $auth = (string)$menu['unique_auth'];
        if (isset($byAuth[$auth])) throw new RuntimeException('ACTIVE_MENU_AUTH_DUPLICATE');
        $byAuth[$auth] = $menu;
    }
    $oldRoot = $byAuth['admin-report-basic'] ?? null;
    if (!$oldRoot || (int)$oldRoot['type'] !== 1) throw new RuntimeException('OLD_BASIC_PARENT_MISSING');
    $missingOld = [];
    foreach ($legacy as $code => $entry) {
        if (!isset($byAuth[$entry['auth']])) $missingOld[] = $code;
        elseif ((int)$byAuth[$entry['auth']]['type'] !== 1 ||
            (string)$byAuth[$entry['auth']]['menu_path'] !== $entry['path'])
            throw new RuntimeException('OLD_LEAF_MENU_CONFLICT');
    }
    // 新源码必须先具备稳定四叶身份；旧门店节点缺失会造成额外扩容，先拒绝。
    $defined = \app\services\organization\StorePermissionCatalogServices::navigationItems();
    $rows = $snapshot['catalog'];
    $catalog = null;
    foreach ($rows as $row) if ((int)$row['id'] === 1) $catalog = $row;
    if (!$catalog) throw new RuntimeException('STORE_CATALOG_MISSING');
    $rules = json_decode((string)$catalog['rules_json'], true);
    if (!is_array($rules)) throw new RuntimeException('STORE_CATALOG_INVALID');
    $byId = array_column($rules, null, 'id');
    for ($id = -33022; $id <= -33001; $id++) if (!isset($byId[$id]))
        throw new RuntimeException('OLD_STORE_REPORT_NAVIGATION_MISSING');
    $newStore = [];
    foreach ([-33023,-33024,-33025,-33026] as $id) {
        if (!isset($defined[$id])) throw new RuntimeException('NEW_STORE_REPORT_ID_MISSING');
        if (isset($byId[$id])) {
            if ((string)($byId[$id]['navigation_key'] ?? '') !== (string)$defined[$id]['navigation_key']
                || (string)($byId[$id]['feature_code'] ?? '') !== (string)$defined[$id]['feature_code'])
                throw new RuntimeException('NEW_STORE_REPORT_ID_CONFLICT');
        } else $newStore[] = $id;
    }
    $parentId = (int)$oldRoot['id'];
    $legacyGroups = ['admin-report-basic-group-store','admin-report-basic-group-employee',
        'admin-report-other-reports'];
    $missingGroups = [];
    if ($legacyMode) foreach ($legacyGroups as $auth) if (!isset($byAuth[$auth])) $missingGroups[] = $auth;
    $missingGrants = 0; $missingGroupGrants = 0; $affectedRoles = 0;
    if ($legacyMode) foreach ($snapshot['roles'] as $role) {
        $set = array_fill_keys(array_map('intval', explode(',', (string)$role['rules'])), true);
        if (!isset($set[$parentId])) continue;
        $missing = 0; $missingGroup = 0;
        foreach ($legacy as $entry) {
            $menu = $byAuth[$entry['auth']] ?? null;
            if (!$menu || !isset($set[(int)$menu['id']])) $missing++;
        }
        foreach ($legacyGroups as $auth) {
            $group = $byAuth[$auth] ?? null;
            if (!$group || !isset($set[(int)$group['id']])) $missingGroup++;
        }
        if ($missing || $missingGroup) {
            $affectedRoles++; $missingGrants += $missing;
            $missingGroupGrants += $missingGroup;
        }
    }
    return ['new_menu_auths'=>array_values(array_map(static fn($r):string=>$r['definition']['auth'],
            array_values(array_filter($newPlan['changes'], static fn($r):bool=>$r['action']==='create')))),
        'missing_old_leaf_codes'=>$legacyMode ? $missingOld : [],
        'missing_legacy_group_auths'=>$missingGroups,
        'store_navigation_ids_to_add'=>$newStore,
        'legacy_parent_roles_to_update'=>$affectedRoles,
        'missing_legacy_leaf_grants'=>$missingGrants,
        'missing_legacy_group_grants'=>$missingGroupGrants,
        'legacy_role_migration_enabled'=>$legacyMode];
}

/** 采用旧平台报表迁移已确认的字段定义；存在时不覆盖人工菜单配置。 */
function roundFourCreateMenu(array $parent, string $auth, string $title, string $path, int $sort): int
{
    return (int)Db::name('system_menus')->insertGetId([
        'pid'=>(int)$parent['id'], 'type'=>1, 'icon'=>'', 'menu_name'=>$title,
        'module'=>'admin', 'controller'=>'', 'action'=>'', 'api_url'=>'',
        'methods'=>'[]', 'params'=>'[]', 'sort'=>$sort, 'is_show'=>1,
        'is_show_path'=>0, 'access'=>1, 'menu_path'=>$path,
        'path'=>trim((string)$parent['path'] . '/' . (int)$parent['id'], '/'),
        'auth_type'=>1, 'header'=>'', 'is_header'=>0, 'unique_auth'=>$auth, 'is_del'=>0,
    ]);
}

/** 备份必须位于站点外且用独占创建；文件存储完整菜单/角色/门店目录供受控回滚。 */
function roundFourBackupPath(string $path, string $root, bool $mustExist): string
{
    if ($path === '' || $path[0] !== '/' || strpos($path, $root . '/') === 0)
        throw new RuntimeException('BACKUP_PATH_OUTSIDE_APP_REQUIRED');
    $parent = realpath(dirname($path));
    if (!$parent || strpos($parent . '/', $root . '/') === 0 || is_link($path))
        throw new RuntimeException('BACKUP_DIRECTORY_INVALID');
    if (is_file($path) !== $mustExist) throw new RuntimeException('BACKUP_EXISTENCE_CONFLICT');
    return $path;
}

function roundFourWriteBackup(string $path, array $payload, bool $replace): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $target = $replace ? $path . '.next' : $path;
    $handle = fopen($target, 'x');
    if (!$handle) throw new RuntimeException('BACKUP_CREATE_FAILED');
    chmod($target, 0600);
    try {
        if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('BACKUP_WRITE_FAILED');
        fflush($handle);
    } finally { fclose($handle); }
    if ($replace && !rename($target, $path)) throw new RuntimeException('BACKUP_REPLACE_FAILED');
}

$actual = (string)Db::query('SELECT DATABASE() AS db')[0]['db'];
$port = (int)Db::query('SELECT @@port AS port')[0]['port'];
$connection = (array)config('database.connections.mysql');
if ($actual !== $target['database'] || $port !== $target['port']
    || !in_array((string)($connection['hostname'] ?? ''), ['localhost','127.0.0.1'], true)
    || (string)($connection['prefix'] ?? '') !== 'eb_')
    throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
$engines = Db::query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN ('eb_system_menus','eb_system_role','eb_store_v3_permission_catalog','eb_store_v3_permission_audit')");
if (count($engines) !== 4) throw new RuntimeException('TRANSACTION_TABLE_MISSING');
foreach ($engines as $engine) if (strtoupper((string)$engine['ENGINE']) !== 'INNODB')
    throw new RuntimeException('TRANSACTION_ENGINE_REQUIRED');
$sourceFiles = [$codeRoot . '/app/services/report/BasicReportCatalogServices.php',
    $codeRoot . '/app/services/organization/StorePermissionCatalogServices.php', __DIR__ . '/plan.php'];
foreach ($sourceFiles as $file) if (!is_file($file)) throw new RuntimeException('SOURCE_FILE_MISSING');
$sourceHash = hash('sha256', implode(':', array_map('hash_file',
    array_fill(0, count($sourceFiles), 'sha256'), $sourceFiles)));

if ($mode === 'rollback') {
    $backupPath = roundFourBackupPath((string)$options['rollback'], $root, true);
    $backup = json_decode((string)file_get_contents($backupPath), true);
    if (!is_array($backup) || ($backup['site'] ?? '') !== $site
        || !in_array(($backup['status'] ?? ''), ['prepared','applied'], true))
        throw new RuntimeException('BACKUP_NOT_PREPARED_OR_APPLIED_OR_WRONG_SITE');
    $result = Db::transaction(static function () use ($backup): array {
        $current = roundFourSnapshot(true);
        // 备份先于数据库提交写为 applied；若提交失败，现库仍是 before。
        // prepared 也只允许在完整 before 状态下做无写入回滚。
        if (roundFourRollbackDecision($backup, $current) === 'already_before')
            return ['roles_restored'=>0, 'menus_retired'=>0, 'already_before'=>true];
        foreach ($backup['changed_roles'] as $id => $before) {
            Db::name('system_role')->where('id', (int)$id)->update(['rules'=>$before]);
        }
        $old = $backup['before']['catalog'];
        $oldOne = null;
        foreach ($old as $row) if ((int)$row['id'] === 1) $oldOne = $row;
        if (!$oldOne) throw new RuntimeException('BACKUP_CATALOG_MISSING');
        $currentOne = null;
        foreach ($current['catalog'] as $row) if ((int)$row['id'] === 1) $currentOne = $row;
        Db::name('store_v3_permission_catalog')->where('id', 1)->update([
            'rules_json'=>$oldOne['rules_json'], 'revision'=>(int)$currentOne['revision']+1,
            'updated_at'=>time(),
        ]);
        Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,
            'action'=>'ROLLBACK_ROUND4_SIDEBAR_MENU',
            'before_json'=>$currentOne['rules_json'], 'after_json'=>$oldOne['rules_json'],
            'created_at'=>time()]);
        foreach ($backup['inserted_menu_ids'] as $id) Db::name('system_menus')->where('id', (int)$id)
            ->update(['is_del'=>1]);
        return ['roles_restored'=>count($backup['changed_roles']),
            'menus_retired'=>count($backup['inserted_menu_ids'])];
    });
    $backup['status'] = 'rolled_back';
    roundFourWriteBackup($backupPath, $backup, true);
    echo json_encode(['site'=>$site, 'mode'=>'rollback', 'result'=>$result], JSON_THROW_ON_ERROR), PHP_EOL;
    return;
}

$snapshot = roundFourSnapshot();
$legacyMode = !(bool)config('basic_report.platform_menu_all_stores');
$preview = roundFourPreview($snapshot, $legacyMode);
$planHash = roundFourDigest([$snapshot, $preview], $sourceHash);
if ($mode === 'dry-run') {
    echo json_encode(['site'=>$site, 'mode'=>'dry-run', 'read_only'=>true,
        'candidate_overlay_verified'=>$codeRoot !== $root,
        'source_sha256'=>$sourceHash, 'plan_sha256'=>$planHash, 'preview'=>$preview,
        'snapshot_sha256'=>roundFourDigest($snapshot, 'state')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    return;
}

if (!preg_match('/^[a-f0-9]{64}$/D', (string)($options['expected-plan-sha'] ?? ''))
    || $options['expected-plan-sha'] !== $planHash
    || (string)($options['expected-source-sha'] ?? '') !== $sourceHash)
    throw new RuntimeException('FIXED_PLAN_OR_SOURCE_CHANGED');
$backupPath = roundFourBackupPath((string)($options['backup'] ?? ''), $root, false);
$backup = ['site'=>$site, 'database'=>$actual, 'source_sha256'=>$sourceHash,
    'plan_sha256'=>$planHash, 'status'=>'prepared', 'before'=>$snapshot,
    'before_digest'=>roundFourDigest($snapshot, 'state')];
roundFourWriteBackup($backupPath, $backup, false);

$result = Db::transaction(static function () use ($snapshot, $preview, $planHash, $sourceHash,
    $legacyMode, $backupPath, &$backup): array {
    $locked = roundFourSnapshot(true);
    if (roundFourDigest([$locked, roundFourPreview($locked, $legacyMode)], $sourceHash) !== $planHash)
        throw new RuntimeException('SOURCE_STATE_CHANGED_BEFORE_APPLY');
    $byAuth = [];
    foreach ($locked['menus'] as $menu) if ((int)$menu['is_del'] === 0)
        $byAuth[(string)$menu['unique_auth']] = $menu;
    $rootMenu = $byAuth['admin-report'];
    $inserted = [];
    // 新四叶不继承旧角色权限，只作为可配置菜单入库。
    foreach (roundFourSidebarMenuDefinitions() as $i => $item) {
        if (isset($byAuth[$item['auth']])) continue;
        $id = roundFourCreateMenu($rootMenu, $item['auth'], $item['title'], $item['path'], 55-$i);
        $inserted[] = $id;
        $byAuth[$item['auth']] = ['id'=>$id];
    }
    $groups = [
        '门店统计'=>['auth'=>'admin-report-basic-group-store','title'=>'门店统计','path'=>'/report/basic-reports','sort'=>60],
        '员工统计'=>['auth'=>'admin-report-basic-group-employee','title'=>'员工统计','path'=>'/report/basic-reports','sort'=>59],
        '其他报表'=>['auth'=>'admin-report-other-reports','title'=>'其他报表','path'=>'/report/other-reports','sort'=>1],
    ];
    if ($legacyMode) foreach ($groups as $g) if (!isset($byAuth[$g['auth']])) {
        $id = roundFourCreateMenu($rootMenu, $g['auth'], $g['title'], $g['path'], $g['sort']);
        $inserted[] = $id;
        $byAuth[$g['auth']] = ['id'=>$id,
            'path'=>trim((string)$rootMenu['path'].'/'.$rootMenu['id'],'/')];
    }
    $order = [];
    foreach (roundFourLegacyMenuDefinitions() as $entry) {
        if (isset($byAuth[$entry['auth']]) || !$legacyMode) continue;
        $g = $groups[$entry['group']];
        $group = $byAuth[$g['auth']];
        $ordinal = ($order[$g['auth']] ?? 0) + 1; $order[$g['auth']] = $ordinal;
        $id = roundFourCreateMenu($group, $entry['auth'], $entry['title'], $entry['path'], 100-$ordinal*10);
        $inserted[] = $id;
        $byAuth[$entry['auth']] = ['id'=>$id];
    }
    $changed = [];
    if ($legacyMode) {
        $oldLeafIds = [];
        foreach (roundFourLegacyCodes() as $code) $oldLeafIds[] = (int)$byAuth['admin-report-'.$code]['id'];
        foreach ($groups as $group) $oldLeafIds[] = (int)$byAuth[$group['auth']]['id'];
        foreach ($locked['roles'] as $role) {
            $before = (string)$role['rules'];
            $after = roundFourAppendRoleRules($before, (int)$byAuth['admin-report-basic']['id'], $oldLeafIds);
            if ($after === null) continue;
            Db::name('system_role')->where('id', (int)$role['id'])->update(['rules'=>$after]);
            $changed[(int)$role['id']] = $before;
        }
    }
    // 既有初始化只补缺失导航，不给岗位发新叶；嵌入同一外层事务。
    (new \app\services\organization\StorePermissionCatalogServices())->initializeBasicReportNavigation();
    // 文件先以原子替换落为完整 applied 凭据，再允许事务提交。
    // 写失败抛错使数据库自动回滚；提交失败后文件仍可按 before_digest 无写入恢复。
    $backup['status'] = 'applied';
    $backup['inserted_menu_ids'] = $inserted;
    $backup['changed_roles'] = $changed;
    $backup['after_digest'] = roundFourDigest(roundFourSnapshot(), 'state');
    roundFourWriteBackup($backupPath, $backup, true);
    return ['inserted_menu_ids'=>$inserted, 'changed_roles'=>$changed,
        'store_navigation_added'=>count($preview['store_navigation_ids_to_add'])];
});
echo json_encode(['site'=>$site, 'mode'=>'apply', 'backup'=>$backupPath,
    'new_menus'=>count($result['inserted_menu_ids']),
    'roles_changed'=>count($result['changed_roles']),
    'store_navigation_added'=>$result['store_navigation_added']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (\Throwable $error) {
    // ThinkPHP 默认 CLI 异常页有机会以0退出，迁移失败必须让发布器得到非零状态。
    $message = (string)$error->getMessage();
    $code = preg_match('/^[A-Z_]+$/D', $message) ? $message : 'ROUND4_MENU_EXECUTION_FAILED';
    fwrite(STDERR, json_encode(['error_code'=>$code],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}

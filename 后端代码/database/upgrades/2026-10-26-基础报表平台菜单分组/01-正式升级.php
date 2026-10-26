<?php
declare(strict_types=1);

/** 从已部署的 R51 权威报表目录派生菜单，不重写任何报表口径或身份。 */
function basicMenuGroups(): array
{
    $settings = [
        // 分组入口使用已部署的基础报表路由；具体功能仍由下层叶子菜单精确定位。
        '门店统计' => ['admin-report-basic-group-store', '/report/basic-reports', 60],
        '员工统计' => ['admin-report-basic-group-employee', '/report/basic-reports', 59],
        '其他报表' => ['admin-report-other-reports', '/report/other-reports', 1],
    ];
    $groups = [];
    foreach ($settings as $title => [$auth, $path, $sort]) {
        $groups[$title] = ['title' => $title, 'auth' => $auth, 'path' => $path,
            'sort' => $sort, 'reports' => []];
    }
    foreach (\app\services\report\BasicReportCatalogServices::entries() as $entry) {
        $group = (string)$entry['group'];
        if (!isset($groups[$group])) throw new LogicException('基础报表分组未配置：' . $group);
        $groups[$group]['reports'][] = [
            'title' => (string)$entry['name'],
            'auth' => 'admin-report-' . (string)$entry['code'],
            'path' => '/report/basic-reports/' . (string)$entry['code'],
            'sort' => 1000 - count($groups[$group]['reports']) * 10,
        ];
    }
    // 本轮范围固定为截图中的三组 21 张表；目录扩容须重新固定候选并验收。
    if (count($groups['门店统计']['reports']) !== 7 || count($groups['员工统计']['reports']) !== 6
        || count($groups['其他报表']['reports']) !== 8) {
        throw new LogicException('基础报表目录已变化，停止本轮菜单迁移');
    }
    return array_values($groups);
}

/** 仅向原本拥有基础报表权限的角色追加分组与叶子导航 ID，保留原规则的完整字节顺序。 */
function inheritedBasicMenuRules(string $rules, int $baseId, array $navigationIds): ?string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $rules)), static function ($id) { return $id > 0; })));
    if (!in_array($baseId, $ids, true)) return null;
    $missing = array_values(array_diff(array_unique($navigationIds), $ids));
    if (!$missing) return null;
    $separator = $rules === '' || substr($rules, -1) === ',' ? '' : ',';
    return $rules . $separator . implode(',', $missing);
}

// 合同测试只加载纯目录与角色规则函数，绝不建立数据库连接或写入。
if (defined('BASIC_MENU_PLAN_ONLY')) return;

// 每个实例须先备份菜单行与角色 rules，并在固定候选验收及该实例迁移授权后执行。
// --expected-database 防止把 010 的操作误落到其他独立客户数据库。
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required after instance backup and authorization');
}
$expected = '';
$codeVersion = '';
$appRoot = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--expected-database=') === 0) $expected = substr($arg, strlen('--expected-database='));
    if (strpos($arg, '--code-version=') === 0) $codeVersion = substr($arg, strlen('--code-version='));
    if (strpos($arg, '--app-root=') === 0) $appRoot = substr($arg, strlen('--app-root='));
}
if ($expected !== 'mohe_010_app' || $appRoot !== '/www/wwwroot/010.cc3798.com' || realpath($appRoot) !== $appRoot) {
    throw new RuntimeException('Explicit 010 app root and database are required');
}
if (!preg_match('/^[0-9a-f]{40}$/', $codeVersion)) {
    throw new RuntimeException('Committed --code-version is required');
}
$root = $appRoot;
// 只对预检时核实的 010 线上 R51 目录生成菜单；线上代码改变须重新做防回退核对。
$catalogFile = $root . '/app/services/report/BasicReportCatalogServices.php';
if (hash_file('sha256', $catalogFile) !== 'c3499b73c1795f5fb5fca1e993d2e69aa167e6c2c02857e1261773a76450d761') {
    throw new RuntimeException('010 线上基础报表目录已变化，需重新固定候选');
}
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();

use think\facade\Db;

/** 菜单身份重复或原有配置被改写时停止，避免把节点挂到错误的报表目录。 */
function uniqueMenu(string $auth, bool $required = true): ?array
{
    $rows = Db::name('system_menus')->where('unique_auth', $auth)->where('is_del', 0)->select()->toArray();
    if (count($rows) > 1 || ($required && count($rows) !== 1)) {
        throw new RuntimeException('菜单身份数量异常：' . $auth);
    }
    return $rows[0] ?? null;
}

/** 新菜单沿用平台菜单字段契约；重复执行只核对身份，不覆盖人工后续排序或授权。 */
function ensureMenu(array $parent, string $auth, string $title, string $path, int $sort): int
{
    $existing = uniqueMenu($auth, false);
    if ($existing) {
        if ((int)$existing['pid'] !== (int)$parent['id'] || (string)$existing['menu_path'] !== $path || (int)$existing['type'] !== 1) {
            throw new RuntimeException('既有菜单位置不符：' . $auth);
        }
        return (int)$existing['id'];
    }
    return (int)Db::name('system_menus')->insertGetId([
        'pid' => (int)$parent['id'], 'type' => 1, 'icon' => '', 'menu_name' => $title,
        'module' => 'admin', 'controller' => '', 'action' => '', 'api_url' => '',
        'methods' => '[]', 'params' => '[]', 'sort' => $sort, 'is_show' => 1,
        'is_show_path' => 0, 'access' => 1, 'menu_path' => $path,
        'path' => trim((string)$parent['path'] . '/' . (int)$parent['id'], '/'),
        'auth_type' => 1, 'header' => '', 'is_header' => 0,
        'unique_auth' => $auth, 'is_del' => 0,
    ]);
}

$actual = (string)Db::query('SELECT DATABASE() AS db')[0]['db'];
if ($actual !== $expected) throw new RuntimeException('数据库不符，实际为：' . $actual);
$result = Db::transaction(static function () use ($codeVersion): array {
    $reportRoot = uniqueMenu('admin-report');
    $legacy = uniqueMenu('admin-report-basic');
    if ((int)$legacy['pid'] !== (int)$reportRoot['id']) throw new RuntimeException('基础报表原入口不在平台报表菜单下');
    $navigationIds = [];
    $reportCount = 0;
    foreach (basicMenuGroups() as $group) {
        ensureMenu($reportRoot, $group['auth'], $group['title'], $group['path'], $group['sort']);
        $groupRow = uniqueMenu($group['auth']);
        if ((int)$groupRow['is_show'] !== 1) throw new RuntimeException('报表分组当前隐藏：' . $group['auth']);
        // 平台前端会按组节点的 unique_auth 裁剪子树；只给叶子授权会导致整组不可见。
        $navigationIds[] = (int)$groupRow['id'];
        foreach ($group['reports'] as $report) {
            $navigationIds[] = ensureMenu($groupRow, $report['auth'], $report['title'], $report['path'], $report['sort']);
            $reportCount++;
        }
    }
    $changedRoles = 0;
    // 先按字符串缩小候选，再用完整 ID 精确判定；锁住候选角色，防止同时改权丢失更新。
    $candidates = Db::name('system_role')->where('rules', 'like', '%' . (int)$legacy['id'] . '%')
        ->field('id,rules')->lock(true)->select()->toArray();
    foreach ($candidates as $role) {
        $next = inheritedBasicMenuRules((string)$role['rules'], (int)$legacy['id'], $navigationIds);
        if ($next === null) continue;
        Db::name('system_role')->where('id', (int)$role['id'])->update(['rules' => $next]);
        $changedRoles++;
    }
    // 旧入口及授权继续保留给原 URL/API；只从左侧菜单隐藏目录卡片入口。
    if ((int)$legacy['is_show'] !== 0) Db::name('system_menus')->where('id', (int)$legacy['id'])->update(['is_show' => 0]);
    $upgradeKey = '20261026-basic-report-platform-menu-groups';
    if (!Db::name('database_upgrade_log')->where('upgrade_key', $upgradeKey)->count()) {
        Db::name('database_upgrade_log')->insert([
            'upgrade_key' => $upgradeKey,
            'title' => '基础报表平台菜单分组',
            'task_file' => '2026-10-26-基础报表平台菜单分组/01-正式升级.php',
            'sql_checksum' => '', 'code_version' => $codeVersion,
            'executed_at' => date('Y-m-d H:i:s'), 'executed_by' => 'codex-release',
            'result_note' => '3 groups and 21 report links; only roles with prior basic report grant inherit navigation',
        ]);
    }
    return ['groups' => 3, 'reports' => $reportCount, 'roles_changed' => $changedRoles];
});
echo json_encode(['database' => $actual] + $result, JSON_UNESCAPED_UNICODE) . PHP_EOL;

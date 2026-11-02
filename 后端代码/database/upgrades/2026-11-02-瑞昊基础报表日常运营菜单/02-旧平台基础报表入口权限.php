<?php
declare(strict_types=1);

/**
 * 瑞昊当前平台构建仍要求 admin-report-basic 才能进入基础报表共用页面。
 * 补一个隐藏的纯路由入口权限给原有六个报表岗位；后端读取始终逐表校验叶子权限。
 */

use think\facade\Db;

require getcwd() . '/vendor/autoload.php';
$app = new \think\App(getcwd() . '/');
$app->initialize();

/** 只接受前一批已完成的瑞昊 26 张菜单和原有六个岗位，防止扩大授权。 */
function r31GateState(): array
{
    $database = (string)(Db::query('SELECT DATABASE() AS db')[0]['db'] ?? '');
    if ($database !== 'ruihao') throw new RuntimeException('R31_GATE_WRONG_DATABASE');
    $daily = Db::name('system_menus')->where('unique_auth','admin-report-sale')->where('is_del',0)->find();
    if (!$daily || (int)$daily['id'] !== 1987) throw new RuntimeException('R31_GATE_DAILY_MISSING');
    $catalog = \app\services\report\BasicReportCatalogServices::entries();
    if (count($catalog) !== 26) throw new RuntimeException('R31_GATE_CATALOG_CHANGED');
    $auths = array_map(static fn(array $entry): string => 'admin-report-' . $entry['code'], $catalog);
    $leaves = Db::name('system_menus')->whereIn('unique_auth',$auths)->where('pid',1987)
        ->where('is_show',1)->where('is_del',0)->select()->toArray();
    if (count($leaves) !== 26 || count(array_unique(array_column($leaves,'unique_auth'))) !== 26)
        throw new RuntimeException('R31_GATE_REPORT_LEAVES_CHANGED');
    $leafIds = array_map('intval',array_column($leaves,'id'));
    $roles = Db::name('system_role')->field('id,rules')->select()->toArray();
    $selected = [];
    foreach ($roles as $role) {
        $ids = array_map('intval',explode(',',(string)$role['rules']));
        if (in_array(1987,$ids,true) && array_intersect($leafIds,$ids)) $selected[] = $role;
    }
    $roleIds = array_map('intval',array_column($selected,'id'));
    sort($roleIds,SORT_NUMERIC);
    if ($roleIds !== [604,631,648,654,655,661])
        throw new RuntimeException('R31_GATE_ROLE_SCOPE_CHANGED');
    $gates = Db::name('system_menus')->where('unique_auth','admin-report-basic')->where('is_del',0)->select()->toArray();
    if (count($gates) > 1) throw new RuntimeException('R31_GATE_DUPLICATE_AUTH');
    $gate = $gates[0] ?? null;
    if ($gate && ((int)$gate['pid'] !== 1987 || (int)$gate['auth_type'] !== 1
        || (int)$gate['type'] !== 1 || (int)$gate['is_show'] !== 0))
        throw new RuntimeException('R31_GATE_EXISTING_INVALID');
    return compact('daily','leaves','selected','roleIds','gate');
}

/** 预演仅打印岗位编号和待补授权数量，不输出客户业务数据或角色规则全文。 */
function r31GatePreview(array $state): array
{
    $gateId = (int)($state['gate']['id'] ?? 0);
    $missing = 0;
    foreach ($state['selected'] as $role) {
        if (!$gateId || !in_array($gateId,array_map('intval',explode(',',(string)$role['rules'])),true)) $missing++;
    }
    return ['gate_id'=>$gateId,'role_ids'=>$state['roleIds'],'roles_missing_gate'=>$missing,
        'report_leaves'=>count($state['leaves']),'scope'=>'瑞昊平台基础报表共用路由'];
}

/** 同一事务补入口授权；查询、导出和写入仍使用既有逐表后端权限。 */
function r31GateApply(): array
{
    $state = r31GateState();
    $gateId = (int)($state['gate']['id'] ?? 0);
    if (!$gateId) {
        $template = null;
        foreach ($state['leaves'] as $leaf) if ($leaf['unique_auth'] === 'admin-report-basic_sales_data') $template = $leaf;
        if (!$template) throw new RuntimeException('R31_GATE_TEMPLATE_MISSING');
        unset($template['id']);
        $template['unique_auth'] = 'admin-report-basic';
        $template['menu_name'] = '基础报表共用路由权限';
        $template['menu_path'] = '/report/basic-reports';
        $template['is_show'] = 0;
        $template['sort'] = 0;
        $gateId = (int)Db::name('system_menus')->insertGetId($template);
    }
    $updated = [];
    foreach ($state['selected'] as $role) {
        $ids = array_map('intval',explode(',',(string)$role['rules']));
        if (in_array($gateId,$ids,true)) continue;
        $rules = rtrim((string)$role['rules'],',') . ',' . $gateId;
        Db::name('system_role')->where('id',(int)$role['id'])->update(['rules'=>$rules]);
        $updated[] = (int)$role['id'];
    }
    $after = r31GatePreview(r31GateState());
    if ($after['roles_missing_gate'] !== 0) throw new RuntimeException('R31_GATE_AFTER_INVALID');
    return ['gate_id'=>$gateId,'roles_updated'=>$updated,'report_leaves'=>26];
}

if (PHP_SAPI !== 'cli') throw new RuntimeException('R31_GATE_CLI_ONLY');
$options = getopt('', ['dry-run','apply','backup:']);
if ((int)isset($options['dry-run']) + (int)isset($options['apply']) !== 1)
    throw new RuntimeException('R31_GATE_MODE_REQUIRED');
if (isset($options['apply'])) {
    $backup = (string)($options['backup'] ?? '');
    if (realpath(getcwd()) !== '/www/wwwroot/rh.cc3798.com'
        || strpos($backup,'/www/backups/rh.cc3798.com/') !== 0
        || !is_file($backup) || filesize($backup) < 100000)
        throw new RuntimeException('R31_GATE_FRESH_BACKUP_REQUIRED');
    echo json_encode(Db::transaction('r31GateApply'),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
} else {
    echo json_encode(r31GatePreview(r31GateState()),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
}

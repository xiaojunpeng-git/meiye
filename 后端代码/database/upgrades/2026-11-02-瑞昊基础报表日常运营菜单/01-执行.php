<?php
declare(strict_types=1);

/**
 * 瑞昊平台基础报表菜单归位：26 张报表直接归入「数据 → 日常运营」。
 * 只调整导航和已获门店统计授权岗位的四张新增报表权限；不读取或改写报表业务数据。
 */

use think\facade\Db;

require getcwd() . '/vendor/autoload.php';
$app = new \think\App(getcwd() . '/');
$app->initialize();

/** 固定 010 已验收的目录顺序；独立列出以防日后新增报表被本迁移意外开放。 */
function r31ReportNames(): array
{
    return [
        'basic_sales_data'=>'销售数据', 'basic_sales_pk'=>'PK数据',
        'basic_sales_analysis'=>'销售分析', 'basic_store_efficiency'=>'门店效能',
        'basic_store_reconciliation'=>'门店对账单', 'basic_store_summary'=>'门店统计表',
        'basic_cross_store_settlement'=>'跨店结算表', 'basic_brush_registration'=>'刷单登记',
        'basic_store_item_sales'=>'门店品项销售分析', 'basic_item_consumption'=>'项目消耗分析表',
        'basic_writeoff_detail'=>'核销明细表', 'basic_product_sales'=>'产品销售表',
        'basic_salary_realtime'=>'工资表', 'basic_employee_summary'=>'员工统计表',
        'basic_employee_item_sales'=>'员工项目销售数分析',
        'basic_employee_service_home_store'=>'员工项目服务数-所属门店',
        'basic_employee_service_store'=>'员工项目服务数-服务门店',
        'basic_employee_performance_detail'=>'员工业绩明细表',
        'basic_balance_order_detail'=>'余额订单明细表',
        'basic_balance_order_summary'=>'余额订单汇总表',
        'basic_daily_reconciliation'=>'每日对账单汇总表',
        'basic_unconsumed_summary'=>'门店未消耗统计表',
        'basic_unconsumed_detail'=>'门店未消耗明细表',
        'basic_followup_employee_detail'=>'跟单人员明细表',
        'basic_customer_entitlements'=>'客户权益报表',
        'basic_unconsumed_as_of_detail'=>'门店未消耗实时明细表',
    ];
}

/** 四张尚无瑞昊菜单的表只继承已有「门店统计」岗位，不扩给其他平台岗位。 */
function r31NewCodes(): array
{
    return ['basic_sales_data','basic_sales_pk','basic_sales_analysis','basic_store_efficiency'];
}

/** 核对目标库、层级和 26 个唯一 auth；异常直接中止，避免半套菜单。 */
function r31State(): array
{
    $database = (string)(Db::query('SELECT DATABASE() AS db')[0]['db'] ?? '');
    if ($database !== 'ruihao') throw new RuntimeException('R31_DATABASE_NOT_RUIHAO');
    $names = r31ReportNames();
    if (count($names) !== 26) throw new RuntimeException('R31_REPORT_COUNT_CHANGED');
    $catalog = \app\services\report\BasicReportCatalogServices::entries();
    $actualCodes = array_column($catalog, 'name', 'code');
    foreach ($names as $code=>$name) if (($actualCodes[$code] ?? null) !== $name)
        throw new RuntimeException('R31_CATALOG_MISMATCH:' . $code);

    $auths = ['admin-report','admin-report-sale','admin-report-basic-group-store',
        'admin-report-basic-group-employee','admin-report-other-reports'];
    foreach (array_keys($names) as $code) $auths[] = 'admin-report-' . $code;
    $rows = Db::name('system_menus')->whereIn('unique_auth', $auths)
        ->where('is_del', 0)->select()->toArray();
    $menus = [];
    foreach ($rows as $row) {
        $key = (string)$row['unique_auth'];
        if (isset($menus[$key])) throw new RuntimeException('R31_DUPLICATE_AUTH:' . $key);
        $menus[$key] = $row;
    }
    foreach (array_slice($auths, 0, 5) as $auth)
        if (!isset($menus[$auth])) throw new RuntimeException('R31_PARENT_MISSING:' . $auth);
    $dataId = (int)$menus['admin-report']['id'];
    $dailyId = (int)$menus['admin-report-sale']['id'];
    if ((int)$menus['admin-report-sale']['pid'] !== $dataId || (int)$menus['admin-report-sale']['is_show'] !== 1)
        throw new RuntimeException('R31_DAILY_PARENT_INVALID');
    $groupIds = [];
    foreach (['admin-report-basic-group-store','admin-report-basic-group-employee','admin-report-other-reports'] as $auth) {
        $group = $menus[$auth];
        if ((int)$group['pid'] !== $dataId) throw new RuntimeException('R31_GROUP_PARENT_INVALID:' . $auth);
        $groupIds[] = (int)$group['id'];
    }
    foreach ($names as $code=>$name) {
        $leaf = $menus['admin-report-' . $code] ?? null;
        if ($leaf === null) {
            if (!in_array($code, r31NewCodes(), true)) throw new RuntimeException('R31_LEGACY_LEAF_MISSING:' . $code);
            continue;
        }
        if (!in_array((int)$leaf['pid'], array_merge($groupIds, [$dailyId]), true)
            || (string)$leaf['menu_path'] !== '/report/basic-reports/' . $code)
            throw new RuntimeException('R31_LEAF_SCOPE_INVALID:' . $code);
    }
    return compact('database','names','menus','dataId','dailyId','groupIds');
}

/** 返回可审计的最小计划，不打印岗位权限原文或任何客户业务数据。 */
function r31Preview(array $state): array
{
    $missing = [];
    $moving = [];
    foreach ($state['names'] as $code=>$name) {
        $leaf = $state['menus']['admin-report-' . $code] ?? null;
        if ($leaf === null) $missing[] = $code;
        elseif ((int)$leaf['pid'] !== $state['dailyId']) $moving[] = $code;
    }
    $storeGroupId = (int)$state['menus']['admin-report-basic-group-store']['id'];
    $roles = Db::name('system_role')->field('id,rules')
        ->whereRaw('FIND_IN_SET(' . $storeGroupId . ',rules)>0')->select()->toArray();
    $roleIds = [];
    foreach ($roles as $role) if (in_array($state['dailyId'], array_map('intval', explode(',', (string)$role['rules'])), true))
        $roleIds[] = (int)$role['id'];
    return ['database'=>$state['database'], 'total'=>26, 'missing'=>$missing,
        'move_count'=>count($moving), 'new_report_role_ids'=>$roleIds,
        'parent'=>'数据/日常运营'];
}

/** 同一事务内新增四表、移动全部叶子并补齐原门店统计岗位；原 22 表授权不变。 */
function r31Apply(): array
{
    $state = r31State();
    $names = $state['names'];
    $menus = $state['menus'];
    $dailyId = $state['dailyId'];
    $path = $state['dataId'] . '/' . $dailyId;
    $template = $menus['admin-report-basic_store_summary'] ?? null;
    if ($template === null) throw new RuntimeException('R31_TEMPLATE_MISSING');
    $newIds = [];
    foreach ($names as $code=>$name) {
        $auth = 'admin-report-' . $code;
        if (!isset($menus[$auth])) {
            $new = $template;
            unset($new['id']);
            $new['unique_auth'] = $auth;
            $new['menu_name'] = $name;
            $new['menu_path'] = '/report/basic-reports/' . $code;
            $new['pid'] = $dailyId;
            $new['path'] = $path;
            $new['is_show'] = 1;
            $new['sort'] = 126 - count($newIds);
            $newIds[$code] = (int)Db::name('system_menus')->insertGetId($new);
            $menus[$auth] = ['id'=>$newIds[$code]];
        }
    }
    $index = 0;
    foreach ($names as $code=>$name) {
        $id = (int)$menus['admin-report-' . $code]['id'];
        Db::name('system_menus')->where('id',$id)->update(['pid'=>$dailyId,
            'path'=>$path, 'menu_name'=>$name, 'is_show'=>1, 'sort'=>126-$index++]);
    }
    // 即使菜单已先由人工创建，重复执行仍补齐应有岗位授权而不重复追加 ID。
    $newLeafIds = [];
    foreach (r31NewCodes() as $code) $newLeafIds[] = (int)$menus['admin-report-' . $code]['id'];
    if (count($newLeafIds) === 4) {
        $storeGroupId = (int)$state['menus']['admin-report-basic-group-store']['id'];
        $roles = Db::name('system_role')->field('id,rules')
            ->whereRaw('FIND_IN_SET(' . $storeGroupId . ',rules)>0')->lock(true)->select()->toArray();
        foreach ($roles as $role) {
            $rules = (string)$role['rules'];
            $ids = array_map('intval', explode(',', $rules));
            if (!in_array($dailyId, $ids, true)) continue;
            foreach ($newLeafIds as $id) if (!in_array($id,$ids,true)) {
                $rules .= ($rules === '' || substr($rules,-1) === ',' ? '' : ',') . $id;
                $ids[] = $id;
            }
            Db::name('system_role')->where('id',(int)$role['id'])->update(['rules'=>$rules]);
        }
    }
    // 旧分组仅在没有其他可见子菜单时隐藏，保留菜单 ID 和既有岗位授权供审计。
    foreach ($state['groupIds'] as $id) if (Db::name('system_menus')->where('pid',$id)
        ->where('is_show',1)->where('is_del',0)->count() === 0)
        Db::name('system_menus')->where('id',$id)->update(['is_show'=>0]);
    $after = r31State();
    $direct = Db::name('system_menus')->where('pid',$dailyId)->where('is_show',1)
        ->where('is_del',0)->whereLike('menu_path','/report/basic-reports/%')->count();
    if ((int)$direct !== 26) throw new RuntimeException('R31_AFTER_COUNT_INVALID');
    $visibleOldGroups = Db::name('system_menus')->whereIn('id',$state['groupIds'])
        ->where('is_show',1)->where('is_del',0)->count();
    return ['created'=>$newIds,'direct_reports'=>(int)$direct,
        'visible_old_groups'=>(int)$visibleOldGroups,'parent'=>'数据/日常运营'];
}

if (PHP_SAPI !== 'cli') throw new RuntimeException('R31_CLI_ONLY');
$options = getopt('', ['dry-run','test-rollback','apply','backup:']);
$modeCount = (int)isset($options['dry-run']) + (int)isset($options['test-rollback']) + (int)isset($options['apply']);
if ($modeCount !== 1) throw new RuntimeException('R31_EXACTLY_ONE_MODE_REQUIRED');
if (isset($options['apply'])) {
    $backup = (string)($options['backup'] ?? '');
    if (realpath(getcwd()) !== '/www/wwwroot/rh.cc3798.com'
        || strpos($backup,'/www/backups/rh.cc3798.com/') !== 0
        || !is_file($backup) || filesize($backup) < 100000)
        throw new RuntimeException('R31_PRODUCTION_BACKUP_REQUIRED');
    echo json_encode(Db::transaction('r31Apply'),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
} elseif (isset($options['test-rollback'])) {
    if (realpath(getcwd()) === '/www/wwwroot/rh.cc3798.com')
        throw new RuntimeException('R31_TEST_MUST_NOT_RUN_ON_PRODUCTION');
    $result = null;
    try {
        Db::transaction(static function () use (&$result): void {
            $result = ['first'=>r31Apply(), 'repeat'=>r31Apply()];
            if ($result['repeat']['created'] !== [] || $result['repeat']['direct_reports'] !== 26)
                throw new RuntimeException('R31_REPEAT_NOT_IDEMPOTENT');
            throw new RuntimeException('R31_EXPECTED_TEST_ROLLBACK');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'R31_EXPECTED_TEST_ROLLBACK') throw $error;
    }
    echo json_encode(['rolled_back'=>true,'test_result'=>$result,
        'after_rollback'=>r31Preview(r31State())],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
} else {
    echo json_encode(r31Preview(r31State()),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
}

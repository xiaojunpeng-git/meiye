<?php
declare(strict_types=1);

define('ROUND4_SIDEBAR_MENU_PLAN_ONLY', true);
require dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-10-30-侧栏四表菜单接入/execute.php';

/** 旧父菜单的已授权角色只补旧21叶，重复计划不重排原规则。 */
$old = roundFourLegacyCodes();
if (count($old) !== 21 || count(array_unique($old)) !== 21 ||
    in_array('basic_store_reconciliation', $old, true) ||
    in_array('basic_sales_data', $old, true)) {
    throw new RuntimeException('旧21报表白名单扩权');
}
$original = '3,9,8';
$after = roundFourAppendRoleRules($original, 9, [8, 20, 21]);
if ($after !== '3,9,8,20,21' || roundFourAppendRoleRules($after, 9, [8,20,21]) !== null)
    throw new RuntimeException('旧权限追加或幂等失败');
if (roundFourAppendRoleRules($original, 7, [20,21]) !== null)
    throw new RuntimeException('非旧父角色被扩权');
$new = array_column(roundFourSidebarMenuDefinitions(), 'auth');
if (count($new) !== 4 || count(array_intersect($new,
    array_map(static fn(string $code):string=>'admin-report-'.$code, $old))) !== 0)
    throw new RuntimeException('新四表与旧继承集重叠');
$beforeState=['menus'=>[['id'=>1]],'roles'=>[],'catalog'=>[]];
$afterState=['menus'=>[['id'=>1],['id'=>2]],'roles'=>[],'catalog'=>[]];
$otherState=['menus'=>[['id'=>1],['id'=>3]],'roles'=>[],'catalog'=>[]];
$backup=['status'=>'prepared','before_digest'=>roundFourDigest($beforeState,'state'),
    'after_digest'=>roundFourDigest($afterState,'state')];
if (roundFourRollbackDecision($backup,$beforeState)!=='already_before')
    throw new RuntimeException('prepared + 未提交前态必须无写入恢复');
$applied=$backup;$applied['status']='applied';
if (roundFourRollbackDecision($applied,$beforeState)!=='already_before'
    || roundFourRollbackDecision($applied,$afterState)!=='restore')
    throw new RuntimeException('applied 文件必须区分提交失败和已提交');
foreach ([[$backup,$afterState],[$applied,$otherState]] as [$receipt,$state]) {
    try { roundFourRollbackDecision($receipt,$state); throw new RuntimeException('异常状态误准许回滚'); }
    catch (RuntimeException $e) {
        if ($e->getMessage()==='异常状态误准许回滚') throw $e;
    }
}
echo "round4-sidebar-menu-execute-plan PASS\n";

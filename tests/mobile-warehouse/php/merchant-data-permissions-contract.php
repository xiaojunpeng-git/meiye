<?php
declare(strict_types=1);

/** 无数据库纯契约：目录身份、旧岗位迁移、独立显隐与父级停用均不依赖生产数据。 */
require_once dirname(__DIR__,3).'/后端代码/app/services/mobile/warehouse/MerchantDataPermissionServices.php';
require_once dirname(__DIR__,3).'/后端代码/app/services/mobile/merchant/MobileMerchantCapabilityCatalog.php';

use app\services\mobile\warehouse\MerchantDataPermissionServices as DataRules;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog as Catalogue;

function expectDataRule(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

$service=new DataRules();$rows=DataRules::definitions();$byCode=array_column($rows,null,'feature_code');
expectDataRule(DataRules::selectedTab(['cash','board','labor'],'','board')==='board','看板可见时首次进入优先看板');
expectDataRule(DataRules::selectedTab(['labor','cash'],'','board')==='labor','看板隐藏时默认第一个可见页签');
expectDataRule(DataRules::selectedTab(['board','cash'],'cash','board')==='cash','用户显式切换页签后保留选择');
expectDataRule(DataRules::selectedTab([],'board','board')==='none','全部页签隐藏时不伪造默认页签');
// 数据总览按后端可见顺序默认；员工详情的显式看板优先口径保持独立。
expectDataRule(DataRules::selectedTab(['organization','store','management_board'],'')==='organization','数据总览默认最左组织排行而非看板');
expectDataRule(DataRules::selectedTab(['store','management_board'],'')==='store','组织排行隐藏时默认最左门店排行');
expectDataRule(DataRules::selectedTab(['staff_cash_performance','management_board'],'')==='staff_cash_performance','目录调序后默认新的最左可见页签');
expectDataRule(DataRules::selectedTab(['store','management_board'],'management_board')==='management_board','数据总览手动选择看板仍保留');
expectDataRule(DataRules::selectedTab(['store','management_board'],'organization')==='store','原选择失去权限后回退最左可见页签');
expectDataRule(DataRules::selectedTab(['management_board'],'')==='management_board','仅剩看板时正常选中看板');
expectDataRule(DataRules::selectedTab([],'')==='none','无可见页签时不伪造页签');
$expectedIds=[
    'warehouse'=>402001,'warehouse.metrics'=>402002,'warehouse.metrics.cash_performance'=>402003,
    'warehouse.metrics.refund_performance'=>402004,'warehouse.metrics.actual_performance'=>402005,
    'warehouse.metrics.consume_amount'=>402006,'warehouse.tabs'=>402007,'warehouse.tabs.organization'=>402008,
    'warehouse.tabs.store'=>402009,'warehouse.tabs.management_board'=>402010,'warehouse.tabs.staff_cash_performance'=>402011,
    'warehouse.tabs.staff_labor_performance'=>402012,'warehouse.tabs.staff_designated_customer'=>402013,
    'warehouse.tabs.project_count'=>402014,'employee'=>402015,'employee.metrics'=>402016,
    'employee.metrics.staff_sales_yeji'=>402017,'employee.metrics.staff_labor_yeji'=>402018,
    'employee.metrics.staff_point_visit_num'=>402019,'employee.metrics.staff_service_num'=>402020,
    'employee.metrics.staff_project_num'=>402021,'employee.metrics.staff_labor_fee'=>402022,
    'employee.metrics.project_labor_commission'=>402023,'employee.tabs'=>402024,
    'employee.tabs.board'=>402025,'employee.tabs.cash'=>402026,'employee.tabs.labor'=>402027,
];
foreach($expectedIds as $code=>$id)expectDataRule(($byCode[$code]['id']??null)===$id,'永久权限身份 '.$code);
expectDataRule(count(array_unique(array_column($rows,'id')))===count($rows),'目录 ID 无重复');
$legacy=$service->resolve([401007],'warehouse',$rows);
expectDataRule($legacy['page']&&count($legacy['metrics'])===4&&count($legacy['tabs'])===7,'旧数据授权保留总览原四指标及七页签');
// 使用真实权限目录验证显隐与排序后，默认选择和页面目录始终同序。
$reordered=$rows;foreach($reordered as &$row)if($row['feature_code']==='warehouse.tabs.project_count')$row['sort']=0;unset($row);
$orderedPolicy=$service->resolve([401007],'warehouse',$reordered);
expectDataRule(DataRules::selectedTab(array_column($orderedPolicy['tabs'],'code'),'')==='project_count','权限目录重新排序后选第一个可见项');
$hidden=$rows;foreach($hidden as &$row)if(in_array($row['feature_code'],['warehouse.tabs.organization','warehouse.tabs.store'],true))$row['enabled']=0;unset($row);
$hiddenPolicy=$service->resolve([401007],'warehouse',$hidden);
expectDataRule(DataRules::selectedTab(array_column($hiddenPolicy['tabs'],'code'),'organization')==='management_board','原最左两项停用后回退剩余可见第一项');
$legacyEmployee=$service->resolve([401007],'employee',$rows);
expectDataRule($legacyEmployee['page']&&count($legacyEmployee['metrics'])===7&&array_column($legacyEmployee['tabs'],'code')===['cash','labor'],'旧岗位保留员工指标且不自动获得新看板');
$explicit=$service->resolve([401007,DataRules::MARKER,402001,402015],'employee',$rows);
expectDataRule($explicit['page']&&$explicit['metrics']===[]&&$explicit['tabs']===[],'显式保存后可全部隐藏，不触发旧岗位补齐');
$single=$service->resolve([DataRules::MARKER,402017],'employee',$rows);
expectDataRule($single['page']&&array_column($single['metrics'],'code')===['staff_sales_yeji']&&$single['tabs']===[],'只选单指标可补祖先但不补兄弟页签');
$tabOnly=$service->resolve([DataRules::MARKER,402026],'employee',$rows);
expectDataRule($tabOnly['page']&&$tabOnly['metrics']===[]&&array_column($tabOnly['tabs'],'code')===['cash'],'顶部现金指标和现金页签独立控制');
$boardOnly=$service->resolve([DataRules::MARKER,402025],'employee',$rows);
expectDataRule(array_column($boardOnly['tabs'],'code')===['board'],'新员工看板只通过显式授权开放');
$disabled=$rows;foreach($disabled as &$row)if($row['id']===402015)$row['enabled']=0;unset($row);
$denied=$service->resolve([401007,DataRules::MARKER,402015,402017,402025],'employee',$disabled);
expectDataRule(!$denied['page']&&$denied['metrics']===[]&&$denied['tabs']===[],'停用员工页面关闭其所有指标和页签');
$disabled=$rows;foreach($disabled as &$row)if($row['id']===402016)$row['enabled']=0;unset($row);
$groupOff=$service->resolve([401007],'employee',$disabled);
expectDataRule($groupOff['page']&&$groupOff['metrics']===[]&&count($groupOff['tabs'])===2,'停用指标组不连带关闭页签');
$none=$service->resolve([],'warehouse',$rows);
expectDataRule(!$none['page']&&$none['metrics']===[]&&$none['tabs']===[],'没有数据授权不能从目录获得页面');
$catalogue=new Catalogue();$known=$catalogue->allRuleIds();
expectDataRule(in_array(DataRules::MARKER,$known,true)&&count($known)===count(array_unique($known)),'能力目录包含标记且无重复 ID');
$normal=$catalogue->normalizeRuleIds([402017]);
expectDataRule(count(array_diff([401007,DataRules::MARKER,402015,402016,402017],$normal))===0&&!in_array(402018,$normal,true),'保存单指标只补必要祖先和格式标记');
foreach([[401007],['401007']] as $legacyInput){
    $saved=$catalogue->normalizeRuleIds($legacyInput);
    $savedPolicy=$service->resolve($saved,'employee',$rows);
    expectDataRule($savedPolicy['page']&&count($savedPolicy['metrics'])===7&&array_column($savedPolicy['tabs'],'code')===['cash','labor'],'旧整数或字符串数据授权保存后仍保留既有显示');
}
$partial=$catalogue->normalizeRuleIds([401007,402017]);
$partialPolicy=$service->resolve($partial,'employee',$rows);
expectDataRule(array_column($partialPolicy['metrics'],'code')===['staff_sales_yeji']&&$partialPolicy['tabs']===[]&&!in_array(402018,$partial,true),'部分新节点即使带旧数据入口也不补兄弟节点');
expectDataRule($catalogue->normalizeRuleIds([])===[],'空授权归一化安全且不伪造入口');
expectDataRule($catalogue->normalizeRuleIds([401007],false)===[401007],'只读宽松归一化不把旧岗位提前标记成新格式');
$oldActions=$catalogue->availableActions([401007],'STORES');
expectDataRule(in_array('MERCHANT_WAREHOUSE_VIEW',$oldActions,true),'旧岗位登录仍具有数据入口动作');
$offActions=$catalogue->availableActions([401007,DataRules::MARKER],'STORES');
expectDataRule(!in_array('MERCHANT_WAREHOUSE_VIEW',$offActions,true),'数据页面全关时不返回数据入口动作');
try{$catalogue->normalizeRuleIds([499999999]);throw new RuntimeException('unknown permission accepted');}
catch(InvalidArgumentException $e){echo 'PASS 拒绝未知权限 ID'.PHP_EOL;}
expectDataRule(count($service->tree())===2&&count($service->snapshot()['rules'])===count($rows),'没有数据库类时默认目录可独立读取');

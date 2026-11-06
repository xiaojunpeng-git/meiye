<?php
declare(strict_types=1);
/** 本地只读：个人看板必须与完整人效事实一致，生美不能误用全部消耗；不改授权或经营事实。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\mobile\warehouse\EmployeeManagementBoardServices;
use app\services\report\HumanEfficiencyDashboardServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\query\metric\MetricMoneyFormatter;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local only');
function checkPersonalBoard(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS ',$label,"\n";}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
    // 用历史分值覆盖整数展示边界，不能因 1 分显示为 0 元就误隐藏非零分类或退款。
    $salesCards=new ReflectionMethod(EmployeeManagementBoardServices::class,'salesCards');$salesCards->setAccessible(true);
    $makeCard=static fn($code,$name,$value,$money)=>['code'=>$code,'value'=>$value,'display'=>MetricMoneyFormatter::integerYuan($value)];
    $categories=[['id'=>1,'name'=>'生美'],['id'=>2,'name'=>'昊美'],['id'=>3,'name'=>'其他']];
    $zero=$salesCards->invoke(null,$categories,[],0,$makeCard);
    checkPersonalBoard(count($zero)===1&&$zero[0]['code']==='cash'&&$zero[0]['value']===0,'零销售隐藏全部分类并保留零合计');
    $fraction=$salesCards->invoke(null,$categories,[1=>1,2=>0,3=>-25],1,$makeCard);$fractionByCode=array_column($fraction,null,'code');
    checkPersonalBoard(!isset($fractionByCode['category:2']),'恰为零的分类隐藏');
    checkPersonalBoard($fractionByCode['category:1']['value']===1&&$fractionByCode['category:1']['display']==='0','历史非零分值显示零元仍保留');
    checkPersonalBoard($fractionByCode['category:3']['value']===-25,'负数分类保留');
    checkPersonalBoard($fractionByCode['unclassified']['value']===25&&$fractionByCode['unclassified']['display']==='0','非零未归类显示零元仍保留');
    $classified=$salesCards->invoke(null,$categories,[1=>100],100,$makeCard);
    checkPersonalBoard(!in_array('unclassified',array_column($classified,'code'),true),'零未归类分类隐藏');
    $stores=[23];$range=['start'=>'2026-10-01','end'=>'2026-11-06'];$service=new EmployeeManagementBoardServices();
    $facts=(new StoreUnifiedReportPhaseSixServices())->humanEfficiencyFacts($stores,$range,false);
    $employees=array_slice(array_values(array_unique(array_map('intval',array_column($facts,'employee_id')))),0,5);
    checkPersonalBoard(count($employees)>=3,'覆盖至少3名真实有业务员工');
    $evidence=[];
    foreach($employees as $employee){
        $board=$service->read($stores,$range,$employee);$cards=[];
        foreach($board['sections'] as $section)foreach($section['cards'] as $card)$cards[$card['code']]=$card;
        $human=(new HumanEfficiencyDashboardServices())->read($stores,$range,['_employee_id'=>$employee]);
        $expected=['cash'=>0,'active'=>0,'visits'=>0,'items'=>0];
        foreach(['beauty','leader_summary','advisor_summary'] as $key)foreach($expected as $field=>$_)$expected[$field]+=$human[$key][$field==='cash'?'cash_cents':$field];
        foreach($expected as $field=>$value)checkPersonalBoard(abs((float)$cards[$field]['value']-$value)<0.000001,"员工 $employee 的 $field 与现有人效一致");
        $bi=0;$all=0;
        foreach($facts as $fact)if((int)$fact['employee_id']===$employee){$all+=(int)($fact['consumption_cents']??0);if(explode('/',(string)($fact['category_path']??''))[0]==='生美')$bi+=(int)($fact['consumption_cents']??0);}
        checkPersonalBoard($cards['beauty_consume']['value']===$bi,"员工 $employee 生美消耗与共享当前分类一致");
        $sum=0;foreach($cards as $code=>$card)if(strpos($code,'category:')===0||$code==='unclassified')$sum+=(int)$card['value'];
        checkPersonalBoard($sum===$cards['cash']['value'],'所有分类及未分类金额与合计守恒');
        $empty=$service->read($stores,['start'=>'1990-01-01','end'=>'1990-01-31'],$employee);
        checkPersonalBoard(count($empty['sections'][0]['cards'])===4&&$empty['sections'][0]['cards'][0]['value']===0&&count($empty['sections'][1]['cards'])===1&&$empty['sections'][1]['cards'][0]['code']==='cash'&&$empty['sections'][1]['cards'][0]['value']===0,'空区间服务指标及合计保留，零销售分类隐藏');
        $evidence[]=['employee'=>$employee,'cash'=>$cards['cash']['value'],'active'=>$cards['active']['value'],'visits'=>$cards['visits']['value'],'items'=>$cards['items']['value'],'beauty_consume'=>$cards['beauty_consume']['value'],'all_consume'=>$all];
    }
    try{$service->read([],$range,$employees[0]);throw new RuntimeException('Empty stores accepted');}catch(InvalidArgumentException $e){echo "PASS 空门店拒绝读取\n";}
    echo 'EVIDENCE ',json_encode($evidence,JSON_UNESCAPED_UNICODE),"\n";
}finally{Db::rollback();}

<?php
declare(strict_types=1);
/** 本地真实数据只读验证：看板、既有人效、分类、顾客去重、明细与范围一致，不连接客户实例。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\report\HumanEfficiencyDashboardServices;
use app\services\mobile\warehouse\MobileManagementBoardServices;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local only');
function checkBoard($ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
try {
    $stores=[23]; $range=['start'=>'2026-10-01','end'=>'2026-11-06']; $service=new MobileManagementBoardServices();
    $start=microtime(true); $board=$service->read($stores,$range,[]);
    $reference=(new HumanEfficiencyDashboardServices())->read($stores,$range);
    foreach (['roleTable','beautyTable'] as $table) {
        checkBoard(count($board[$table]['rows'])>0,"$table 零分类隐藏后仍保留指标定义");
        foreach ($board[$table]['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if (!$cell['drillable'] || (float)$cell['value'] === 0.0 || $cell['key'] !== 'total') continue;
                $detail=$service->read($stores,$range,['boardAction'=>'detail','boardTable'=>$table,'boardRow'=>$row['key'],'boardColumn'=>$cell['key']]);
                $d=$detail['detail']; $key=['cash'=>'cash_cents','consume'=>'consume_cents','items'=>'items','visits'=>'visits'][$row['metric']]??'total';
                $expected=$key==='cash_cents'||$key==='consume_cents'?(float)$cell['value']*100:(float)$cell['value'];
                checkBoard(abs($d[$key]-$expected)<0.00001,"$table {$row['key']} 合计与精确下钻一致");
                checkBoard(count($d['rows'])<=20 && $d['page_size']===20,'明细后端分页最多20条');
            }
        }
    }
    $lookup=[];foreach($board['beautyTable']['rows'] as $r)$lookup[$r['key']]=$r;
    checkBoard(abs((float)end($lookup['cash']['cells'])['value']*100-$reference['beauty']['cash_cents'])<0.00001,'美容师现金与既有人效总额一致');
    checkBoard((int)end($lookup['active']['cells'])['value']===$reference['beauty']['active'],'美容师活客合计重新去重');
    $query=['boardAction'=>'detail','boardTable'=>'beautyTable','boardRow'=>'active','boardColumn'=>'total'];
    $first=$service->read($stores,$range,$query)['detail'];
    if($first['total']>20){
        $second=$service->read($stores,$range,$query+['boardDetailPage'=>2])['detail'];
        $last=$service->read($stores,$range,$query+['boardDetailPage'=>99999])['detail'];
        checkBoard(count($first['rows'])===20 && count($second['rows'])===20 && $first['rows']!==$second['rows'],'前两页各20条且内容不同');
        checkBoard($last['page']===$last['pages'] && count($last['rows'])===$last['total']-20*($last['pages']-1),'末页余数正确且越界页回到末页');
    }
    $columns=$board['beautyTable']['columns'];
    if(count($columns)>1){$id=$columns[0]['key'];$filtered=$service->read($stores,$range,['boardEmployeeIds'=>[$id]]);checkBoard(array_column($filtered['beautyTable']['columns'],'key')===[$id,'total'],'员工筛选由后端缩小');checkBoard($filtered['roleTable']===$board['roleTable'],'员工筛选不改变店长顾问表');}
    $none=$service->read($stores,$range,['boardEmployeeIds'=>['999999:999999']]);
    checkBoard(count($none['beautyTable']['columns'])===1,'越权员工筛选不返回人员');
    try{$service->read($stores,$range,['boardAction'=>'detail','boardTable'=>'beautyTable','boardRow'=>'cash','boardColumn'=>'999999:999999']);throw new RuntimeException('accepted invalid cell');}catch(InvalidArgumentException $e){echo "PASS 越权明细列拒绝\n";}
    $empty=$service->read($stores,['start'=>'1990-01-01','end'=>'1990-01-31'],[]);
    checkBoard(count($empty['beautyTable']['columns'])===1,'空期间不伪造美容师');
    echo 'EVIDENCE ',json_encode(['seconds'=>round(microtime(true)-$start,3),'roleTable'=>$board['roleTable'],'beautyTable'=>$board['beautyTable']],JSON_UNESCAPED_UNICODE),"\n";
} finally { Db::rollback(); }

<?php
declare(strict_types=1);
/** 互斥岗位、完整合计、多人去重、历史贡献、未归类与金额展示边界。 */
require_once dirname(__DIR__,3).'/后端代码/app/services/query/metric/MetricMoneyFormatter.php';
require_once dirname(__DIR__,3).'/后端代码/app/services/report/ManagementBoardProjection.php';
use app\services\report\ManagementBoardProjection as Board;
function assertProjection($ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$base=['store_id'=>1,'employee_id'=>1,'id'=>'1:1','store'=>'测试门店','name'=>'甲','employed'=>true,'_has_facts'=>true,'role_code'=>'beauty','cash_cents'=>10150,'consume_cents'=>15000,'items'=>1.5,'visits'=>0.5,'_active'=>[99=>true],'category_values'=>[10=>['cash_cents'=>10000]]];
$people=[$base,array_replace($base,['id'=>'1:2','employee_id'=>2,'name'=>'乙','employed'=>false,'cash_cents'=>20000,'category_values'=>[10=>['cash_cents'=>20000]]]),array_replace($base,['id'=>'1:3','employee_id'=>3,'role_code'=>'leader']),array_replace($base,['id'=>'1:4','employee_id'=>4,'role_code'=>'advisor'])];
$appointments=['1:1'=>['booked'=>[99=>true],'arrived'=>[99=>true]],'1:2'=>['booked'=>[99=>true,100=>true],'arrived'=>[99=>true]]];
$board=Board::project($people,[['id'=>10,'name'=>'生美']],$appointments);
$rows=array_column($board['beautyTable']['rows'],null,'key');
assertProjection(end($rows['active']['cells'])['value']===1,'活客跨人员合计重新去重');
assertProjection(end($rows['booked']['cells'])['value']===2 && end($rows['arrived']['cells'])['value']===1,'预约与实际到店独立去重');
assertProjection(end($rows['cash']['cells'])['value']==='301.5','已离职人员业务仍计入且金额原精度保留');
assertProjection($rows['cash']['cells'][0]['display']==='102','金额复用整元展示规则');
assertProjection(end($rows['unclassified']['cells'])['value']==='1.5','未归类金额不丢失');
$role=array_column($board['roleTable']['rows'],null,'key');
assertProjection(end($role['cash']['cells'])['value']==='203','店长顾问个人分配仅计一次');
assertProjection(array_column($board['beautyTable']['columns'],'key')===['1:1','1:2','total'],'美容师列使用稳定身份和真实人员');
$filtered=Board::project($people,[['id'=>10,'name'=>'生美']],$appointments,['boardEmployeeIds'=>['1:2']]);
assertProjection($filtered['roleTable']===$board['roleTable'],'员工筛选只缩小美容师表');
$zero=Board::project([], [['id'=>10,'name'=>'生美']], []);
assertProjection(!in_array('category:10',array_column($zero['beautyTable']['rows'],'key'),true),'美容师表全零分类行隐藏');
assertProjection(array_column($zero['roleTable']['rows'],'key')===['cash','consume'],'店长顾问表全零分类隐藏而合计消耗保留');
assertProjection(array_column($zero['beautyTable']['rows'],'key')===['booked','arrived','active','visits','items','consume','cash'],'全零服务指标及合计不隐藏');
$onlyBeauty=Board::project([$base],[['id'=>10,'name'=>'生美']],[]);
assertProjection(!in_array('category:10',array_column($onlyBeauty['roleTable']['rows'],'key'),true)&&in_array('category:10',array_column($onlyBeauty['beautyTable']['rows'],'key'),true),'两个表按各自人员列独立判断分类显隐');
$offset=Board::project([array_replace($base,['cash_cents'=>10000,'category_values'=>[10=>['cash_cents'=>10000]]]),array_replace($base,['id'=>'1:2','employee_id'=>2,'cash_cents'=>-10000,'category_values'=>[10=>['cash_cents'=>-10000]]])],[['id'=>10,'name'=>'生美']],[]);
$offsetRows=array_column($offset['beautyTable']['rows'],null,'key');
assertProjection(isset($offsetRows['category:10'])&&end($offsetRows['category:10']['cells'])['value']==='0','人员正负金额抵消为零仍保留分类');
$tiny=Board::project([array_replace($base,['cash_cents'=>1,'category_values'=>[10=>['cash_cents'=>1]]])],[['id'=>10,'name'=>'生美']],[]);
$tinyRows=array_column($tiny['beautyTable']['rows'],null,'key');
assertProjection(isset($tinyRows['category:10'])&&$tinyRows['category:10']['cells'][0]['display']==='0','历史分值非零即使整元显示零仍保留');

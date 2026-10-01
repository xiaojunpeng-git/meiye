<?php
declare(strict_types=1);
/** Read-only full local copy probe for the current rights amount path. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\ai\presentation\AiMemberDetailAnswerRenderer;
use app\services\ai\presentation\AiMemberRightsExportProjection;
$stores=array_map('intval',Db::name('system_store')->column('id'));
$today=date('Y-m-d');
$condition=['subject'=>'member','relation'=>'all','conditions'=>[['metric_code'=>'member_remaining_project_amount','operator'=>'gt','value'=>5000000]]];
$started=microtime(true);
$result=(new GroupPerformanceMetricReadServices())->conditionMembers('0',$stores,['start'=>$today,'end'=>$today],$condition,1000);
echo json_encode(['stores'=>count($stores),'matched'=>$result['count'],'rows'=>count($result['rows']),'seconds'=>round(microtime(true)-$started,2)]),PHP_EOL;
$ids=array_column($result['rows'],'member_id');
$operator=new CashierV3OperatorScope(1,1,'','0');
$scope=new Scope(1,0,1,'0','',$stores,Scope::MODE_STORES,[],false,'','r57-full-probe',[],[],true);
$detailStarted=microtime(true);
$rights=(new CashierV3MemberDetailQueryServices())->readAiRightsSet($ids,$operator,$scope);
$details=[];
foreach($result['rows'] as $row) {
    $id=$row['member_id'];
    $cents=(int)bcmul((string)$rights[$id]['summary']['remainingProjectAmount'],'100',0);
    if ($cents!==$row['metrics']['member_remaining_project_amount']) {
        throw new RuntimeException('QUERY_DETAIL_AMOUNT_MISMATCH');
    }
    $details[]=['detail'=>$rights[$id],'label'=>$row['member_name'],'selection_ref'=>'member:'.$id];
}
$answer=(new AiMemberDetailAnswerRenderer())->renderSet($details,'summary');
$export=AiMemberRightsExportProjection::capture($details,'summary',[],$answer);
echo json_encode(['detail_seconds'=>round(microtime(true)-$detailStarted,2),
    'export_members'=>count($export['member_refs']),'export_rows'=>count($export['rows']),
    'export_table_rows'=>count($export['tables'][0]['rows']??[])]),PHP_EOL;

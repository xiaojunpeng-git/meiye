<?php
declare(strict_types=1);
/** Read-only full local copy probe for the current rights amount path. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\query\metric\MetricReadTransaction;
use app\services\query\metric\MemberAnalysisObjectServices;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\ai\presentation\AiMemberDetailAnswerRenderer;
use app\services\ai\presentation\AiMemberRightsExportProjection;
$stores=array_map('intval',Db::name('system_store')->column('id'));
$today=date('Y-m-d');
$condition=['subject'=>'member','relation'=>'all','conditions'=>[['metric_code'=>'member_remaining_project_amount','operator'=>'gt','value'=>5000000]]];
$started=microtime(true);
$result=(new MetricReadTransaction(10000))->run(static function(GroupPerformanceMetricReadServices $reader)use($stores,$today,$condition):array {
    return $reader->conditionMembers('0',$stores,['start'=>$today,'end'=>$today],$condition,1000);
});
echo json_encode(['stores'=>count($stores),'matched'=>$result['count'],'rows'=>count($result['rows']),'seconds'=>round(microtime(true)-$started,2)]),PHP_EOL;
$ids=array_column($result['rows'],'member_id');
$selectionScope=['member_authorized'=>true,'store_ids'=>$stores,'scope_mode'=>'stores','permission_version'=>'r57-local-probe'];
$objects=new MemberAnalysisObjectServices(static fn(string $table)=>Db::name($table),static fn()=>$selectionScope);
$selected=$objects->selections(array_map(static fn(int $id):string=>'member:'.$id,$ids));
if (count($selected)!==count($ids)) throw new RuntimeException('MEMBER_SELECTION_SET_INCOMPLETE');
echo json_encode(['signed_selections'=>count($selected)]),PHP_EOL;
$operator=new CashierV3OperatorScope(1,1,'','0');
$scope=new Scope(1,0,1,'0','',$stores,Scope::MODE_STORES,[],false,'','r57-full-probe',[],[],true);
$detailStarted=microtime(true);
$reader=new CashierV3MemberDetailQueryServices();
$rights=$reader->readAiRightsSummarySet($ids,$operator,$scope);
$rightsSeconds=round(microtime(true)-$detailStarted,2);
$fullStarted=microtime(true);
$full=$reader->readAiRightsSet($ids,$operator,$scope);
$fullSeconds=round(microtime(true)-$fullStarted,2);
$details=[];
foreach($result['rows'] as $row) {
    $id=$row['member_id'];
    if ($rights[$id]['summary']!==$full[$id]['summary']
        ||$rights[$id]['member']!==$full[$id]['member']) {
        throw new RuntimeException('QUERY_DETAIL_SUMMARY_MISMATCH');
    }
    $cents=(int)bcmul((string)$rights[$id]['summary']['remainingProjectAmount'],'100',0);
    if ($cents!==$row['metrics']['member_remaining_project_amount']) {
        throw new RuntimeException('QUERY_DETAIL_AMOUNT_MISMATCH');
    }
    $details[]=['detail'=>$rights[$id],'label'=>$row['member_name'],'selection_ref'=>'member:'.$id];
}
$renderStarted=microtime(true);
$answer=(new AiMemberDetailAnswerRenderer())->renderSet($details,'summary');
$renderSeconds=round(microtime(true)-$renderStarted,2);
$export=AiMemberRightsExportProjection::capture($details,'summary',[],$answer);
echo json_encode(['fast_detail_seconds'=>round($rightsSeconds+microtime(true)-$renderStarted,2),
    'rights_seconds'=>$rightsSeconds,'full_rights_seconds'=>$fullSeconds,'render_seconds'=>$renderSeconds,
    'export_members'=>count($export['member_refs']),'export_rows'=>count($export['rows']),
    'export_table_rows'=>count($export['tables'][0]['rows']??[]),
    'export_bytes'=>strlen(json_encode($export,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]),PHP_EOL;

<?php
declare(strict_types=1);

/** Local-only, read-only business-source probe. Private test spools are removed after verification. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

use app\services\ai\presentation\AiMemberDetailAnswerRenderer;
use app\services\ai\presentation\AiMemberRightsDetailStore;
use app\services\ai\presentation\AiMemberRightsExportProjection;
use app\services\ai\presentation\AiStreamingWorkbook;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\query\metric\AiMemberRightsAmountIndex;
use app\services\query\metric\AiMemberRightsPopulationStore;
use app\services\query\metric\MetricReadTransaction;
use app\services\query\metric\MetricReadViewExportRegistrar;
use think\facade\Db;

$stores=array_map('intval',Db::name('system_store')->column('id'));
if (!$stores) throw new RuntimeException('NO_LOCAL_STORES');
$index=new AiMemberRightsAmountIndex();
if (!$index->ready('0')) throw new RuntimeException('LOCAL_RIGHTS_INDEX_NOT_READY');
// Exercise the real read-only query boundary: cashier debt reads must not
// acquire row locks even while a repeatable-read transaction is active.
$page=(new MetricReadTransaction())->run(static function()use($index,$stores):array {
    return $index->query('0',$stores,'gt',3000000,20);
});
if ($page['count']<=1428 || count($page['rows'])!==20 || $page['population_snapshot']['count']!==$page['count']) {
    throw new RuntimeException('FULL_POPULATION_NOT_FROZEN');
}
$population=$page['population_snapshot'];
$operator=new CashierV3OperatorScope(1,1,'','0');
$scope=new CashierV3DataScopeContext(1,0,1,'0','',$stores,
    CashierV3DataScopeContext::MODE_STORES,[],false,'','r61-full-export-probe',[],[],true);
$reader=new CashierV3MemberDetailQueryServices();
$records=(static function()use($population,$reader,$operator,$scope):Generator {
    $batch=[];
    foreach ((new AiMemberRightsPopulationStore())->rows($population) as $row) {
        $batch[]=$row;
        if (count($batch)<100) continue;
        yield from readBatch($batch,$reader,$operator,$scope);$batch=[];
    }
    if ($batch) yield from readBatch($batch,$reader,$operator,$scope);
})();
function readBatch(array $batch,$reader,$operator,$scope):Generator
{
    $details=$reader->readAiRightsSummarySet(array_column($batch,'member_id'),$operator,$scope);
    foreach ($batch as $row) {
        $detail=$details[$row['member_id']]??null;
        if (!is_array($detail) || (int)bcmul($detail['summary']['remainingProjectAmount'],'100',0)!==$row['amount_cents']) {
            throw new RuntimeException('FROZEN_RIGHTS_CHANGED');
        }
        yield ['detail'=>$detail,'label'=>$row['member_name'],
            'selection_ref'=>'member:'.$row['member_id'],'selected_amount_cents'=>$row['amount_cents']];
    }
}
$detailSnapshot=null;$path=tempnam(sys_get_temp_dir(),'r61-all-rights-');
if ($path===false) throw new RuntimeException('TEST_TEMP_FAILED');
try {
    $detailSnapshot=(new AiMemberRightsDetailStore())->put($records,$page['count']);
    $preview=[];
    foreach ((new AiMemberRightsDetailStore())->rows($detailSnapshot) as $record) {
        $preview[]=$record;if (count($preview)===20) break;
    }
    $answer=(new AiMemberDetailAnswerRenderer())->renderSet($preview,'summary',$page['count']);
    $asset=AiMemberRightsExportProjection::captureStreamed($detailSnapshot,$answer);
    $today=date('Y-m-d');$set=['subject'=>'member','relation'=>'all','conditions'=>[
        ['metric_code'=>'member_remaining_project_amount','operator'=>'gt','value'=>3000000]]];
    $view=['query'=>['query_shape'=>'condition_list','start_date'=>$today,'end_date'=>$today,
        'compare_range'=>null,'business_filters'=>['object_kind'=>'member'],
        'metric_codes'=>['member_remaining_project_amount'],'condition_set'=>$set],
        'results'=>[['period'=>'current','metric_code'=>'member_remaining_project_amount','storage_unit'=>'count',
            'object_kind'=>'member','condition_set'=>$set,'count'=>$page['count'],'rows'=>$page['rows'],
            'list_limit'=>20,'has_more'=>true,'population_snapshot'=>$population]],
        'member_rights_export'=>$asset];
    $fields=[];foreach (MetricReadViewExportRegistrar::fields() as $key=>$label) $fields[]=['key'=>$key,'label'=>$label];
    $writer=new AiStreamingWorkbook();
    $written=$writer->write($path,$fields,AiMemberRightsExportProjection::streamedTables($view),
        AiMemberRightsExportProjection::streamedAuditRows($view));
    if ($written!==$page['count']*7) throw new RuntimeException('EXPORTED_ROW_COUNT_MISMATCH');
    $writer->verify($path,$fields,AiMemberRightsExportProjection::streamedTables($view),
        AiMemberRightsExportProjection::streamedAuditRows($view));
    echo json_encode(['members'=>$page['count'],'preview'=>count($preview),'audit_rows'=>$written,
        'xlsx_bytes'=>filesize($path),'status'=>'PASS']),PHP_EOL;
} finally {
    if (is_file($path)) unlink($path);
    $runtime=rtrim((string)app()->getRuntimePath(),DIRECTORY_SEPARATOR).'/private/';
    if (isset($population['ref'])) @unlink($runtime.'ai-member-populations/'.$population['ref'].'.jsonl');
    if ($detailSnapshot!==null) @unlink($runtime.'ai-member-rights-details/'.$detailSnapshot['ref'].'.jsonl');
}

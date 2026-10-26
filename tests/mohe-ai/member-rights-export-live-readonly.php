<?php
/** Read back one explicitly selected local export. No task creation, member
 * mutation or identity data output; the UI must create the actual task first.
 * Run in the configured backend container: php -- uqe_<32 hex> < this file.
 */
require getcwd().'/vendor/autoload.php';
$app=new think\App();$app->initialize();
$taskNo=$argv[1]??'';
if (!preg_match('/^uqe_[a-f0-9]{32}$/D',$taskNo)) throw new RuntimeException('explicit task required');
$task=think\facade\Db::name('unified_query_export_task')->where('task_no',$taskNo)->find();
if (!$task||$task['status']!=='succeeded'||$task['source_type']!=='AI') throw new RuntimeException('successful AI task required');
$binding=json_decode($task['ai_binding'],true);
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
$evidence=$runtime['private']->read($binding['evidence_ref']);
$assets=app\services\ai\presentation\AiMemberRightsExportProjection::validate($evidence['member_rights_export']??null);
if (($evidence['workflow_code']??'')!=='wf_member_detail_read'||$binding['run_id']!==$evidence['run_id']) {
    throw new RuntimeException('asset answer binding mismatch');
}
$path=(new app\services\query\UnifiedQueryExportStorage())->absolutePath($task['storage_key']);
$reflection=new ReflectionClass(app\services\ai\execution\AiExportRuntime::class);
$verify=$reflection->getMethod('verifyFile');$verify->setAccessible(true);
$verify->invoke($reflection->newInstanceWithoutConstructor(),$path,['member_rights_export'=>$assets]);
$populationRows=0;
foreach($assets['rows'] as $row) if(strpos($row['metric_name'],'筛选依据：')===0) $populationRows++;
echo json_encode(['task'=>$taskNo,'exact_readback'=>true,'members'=>count($assets['member_refs']),
    'rows'=>count($assets['rows']),'population_rows'=>$populationRows,'sha256'=>hash_file('sha256',$path)],JSON_UNESCAPED_UNICODE).PHP_EOL;

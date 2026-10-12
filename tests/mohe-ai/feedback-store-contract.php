<?php
declare(strict_types=1);

require_once __DIR__.'/../../后端代码/app/services/ai/feedback/AiFeedbackStore.php';

use app\services\ai\feedback\AiFeedbackStore;

if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) { echo "SKIP: pdo_sqlite unavailable\n"; exit(0); }
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE mohe_ai_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT,instance_id TEXT,run_id TEXT,generation INTEGER,account_id INTEGER,terminal TEXT,rating TEXT,question TEXT,answer_json TEXT,answer_summary TEXT,run_status TEXT,run_reason TEXT,model_version TEXT,run_created_at INTEGER,created_at INTEGER,updated_at INTEGER,UNIQUE(instance_id,run_id))');
$check=static function(bool $valid,string $label):void { if (!$valid) throw new RuntimeException($label); };
$first=new AiFeedbackStore($db,'','one');$other=new AiFeedbackStore($db,'','two');
$owner=['account_id'=>17,'terminal'=>'platform'];
$source=['run_id'=>str_repeat('a',48),'generation'=>1,'status'=>'COMPLETED','reason'=>'','model_version'=>'3','created_at'=>1000];
$answer=['summary'=>'这月现金业绩 123 元','cards'=>[['metric_name'=>'现金业绩','display_value'=>'123','unit'=>'元']]];
$first->save($owner,$source,'useful','这月业绩？',$answer);
$first->save($owner,$source,'not_useful','这月业绩？',$answer);
$check($first->listing([])['total']===1,'one Run has one current evaluation');
$check($first->detail(1)['rating']==='not_useful','changed vote replaces earlier vote');
$check($first->detail(1)['answer']===$answer,'complete structured answer is retained');
$check($other->listing([])['total']===0,'other instance cannot list feedback');
try { $other->detail(1); throw new RuntimeException('cross-instance detail leaked'); }
catch (RuntimeException $error) { $check($error->getMessage()==='AI_FEEDBACK_NOT_FOUND','cross-instance detail is denied'); }
for ($index=1;$index<=21;$index++) {
    $source['run_id']=str_pad(dechex($index),48,'0',STR_PAD_LEFT);
    $first->save($owner,$source,'useful','问题 '.$index,$answer);
}
$page1=$first->listing(['page'=>1]);$page2=$first->listing(['page'=>2]);
$check($page1['total']===22 && count($page1['rows'])===20 && count($page2['rows'])===2,'backend pages full result');
$check($first->listing(['rating'=>'not_useful'])['total']===1,'rating filter counts all matching rows');
$check($first->listing(['keyword'=>'问题 21'])['total']===1,'question keyword filter');
foreach ([['page'=>[]],['rating'=>['useful']],['keyword'=>['问题']]] as $badFilter) {
    try { $first->listing($badFilter); throw new RuntimeException('malformed filter accepted'); }
    catch (RuntimeException $error) { $check($error->getMessage()==='AI_FEEDBACK_INVALID','malformed filter rejected'); }
}
echo "AI feedback store contract: PASS\n";

<?php
require_once __DIR__.'/fixture-autoload.php';
use app\services\query\metric\AnalysisObjectCatalog;
use app\services\ai\model\AiSafeQuestionProjector;
use app\services\ai\config\AiConfigStore;
$checks=0;
$assert=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$objects=[
 ['ref'=>'position:1','kind'=>'position','label'=>'美容顾问','aliases'=>[],'version'=>'1','relations'=>['craftsman']],
 ['ref'=>'position:2','kind'=>'position','label'=>'护理师','aliases'=>[],'version'=>'1','relations'=>['craftsman']],
 ['ref'=>'position:3','kind'=>'position','label'=>'隐藏岗位','aliases'=>['技师'],'version'=>'1','relations'=>['craftsman']],
];
$catalog=new AnalysisObjectCatalog($objects,static function($o){return $o['ref']!=='position:3';});
$result=$catalog->resolve('技师','position','craftsman');
$assert($result['status']==='choose' && count($result['objects'])===2,'unrecognized title offers authorized actual structure, no silent synonym');
$assert(strpos(json_encode($result,JSON_UNESCAPED_UNICODE),'隐藏')===false,'no unauthorized label or alias leak');
$assert($catalog->resolve('护理师','position','craftsman')['objects'][0]['ref']==='position:2','unique exact object resolves');
$objects[1]['label']='美容顾问';
$duplicate=new AnalysisObjectCatalog($objects,static function($o){return $o['ref']!=='position:3';});
$assert($duplicate->resolve('美容顾问','position')['status']==='choose','duplicate labels require choice');
$none=new AnalysisObjectCatalog($objects,static function(){return 1;});
$assert($none->resolve('技师','position')['status']==='unavailable','truthy value not authorization');
$assert($catalog->resolve('护理师','position','learner')['status']==='unavailable','relation mismatch not ignored');
$education=new AnalysisObjectCatalog([['ref'=>'course:1','kind'=>'course','label'=>'课程甲','aliases'=>['基础课'],'version'=>'1','relations'=>['learner']]],static function(){return true;});
$assert($education->resolve('基础课','course','learner')['status']==='resolved','new domain supplied as metadata without new scenario');
$safe=new AiSafeQuestionProjector();
$config=['enabled'=>true,'external_processing_authorized'=>true,'external_scope_supported'=>true,'external_scope_version'=>AiConfigStore::QUESTION_SCOPE];
$question=$safe->project('今天做的最好的技师是谁',$config);
$assert(!$question['outbound']['has_unresolved_conditions'],'public business question reconstructed without opaque spans');
foreach (['张三','18326012588','sk-secret-fixture','88000元','AB-123','foo@example.test','DROP TABLE secrets'] as $private) {
 $projection=$safe->project('今天'.$private.'业绩多少',$config);
 $out=json_encode($projection['outbound'],JSON_UNESCAPED_UNICODE);
 $assert(strpos($out,$private)===false && $projection['outbound']['has_unresolved_conditions'],'private/unknown span remains local: '.$private);
}
$projection=$safe->project('今天护理师业绩多少',$config,['护理师']);
$assert(strpos(json_encode($projection['outbound'],JSON_UNESCAPED_UNICODE),'护理师')===false,'customer name masked before generic interpretation');
$projection=$safe->project('今天业绩排除张三',$config);
$assert(strpos($projection['outbound']['question'],'排除')!==false && $projection['local_conditions'],'exclusion retained alongside private reference');
try {$safe->project('今天现金业绩',array_merge($config,['external_scope_version'=>'']));throw new RuntimeException('scope bypass');}
catch(RuntimeException $e){$assert($e->getMessage()==='AI_EXTERNAL_SCOPE_REQUIRED','versioned consent required');}
echo "PASS object resolution / safe question: $checks checks\n";

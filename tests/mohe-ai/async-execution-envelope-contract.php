<?php
declare(strict_types=1);

require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiExecutionEnvelope.php';
require_once __DIR__.'/../../后端代码/app/services/ai/config/AiPrivateStorage.php';

use app\services\ai\execution\AiExecutionEnvelope;
use app\services\ai\config\AiPrivateStorage;

$input=['question'=>'今天业绩怎么样','history'=>[['answer'=>['summary'=>'上次结果'],'question'=>'昨天呢']],'output_format'=>'screen',
    'context_ref'=>'context.signature','client_session_id'=>'session','window_token'=>'window','run_delivery_token'=>'delivery','generation'=>2];
$projected=AiExecutionEnvelope::project('execute',$input);
if (array_keys($projected)!==['question','history','output_format','context_ref'] || array_intersect(array_keys($projected),['client_session_id','window_token','run_delivery_token','generation'])) throw new RuntimeException('execution envelope retained HTTP/session credentials');
$key=str_repeat('k',32);
$same=['output_format'=>'screen','history'=>[['question'=>'昨天呢','answer'=>['summary'=>'上次结果']]],'question'=>'今天业绩怎么样','context_ref'=>'context.signature'];
if (!hash_equals(AiExecutionEnvelope::hash('execute',$input,$key),AiExecutionEnvelope::hash('execute',$same,$key))) throw new RuntimeException('execution envelope hash is not canonical');
$clarify=['clarification_id'=>'clarification','choices'=>['store'=>'A'],'schema_version'=>'mohe-clarification-v2','step_revision'=>1,'intent_revision'=>1,'client_submission_id'=>'submission','run_delivery_token'=>'delivery'];
if (array_key_exists('run_delivery_token',AiExecutionEnvelope::project('clarify',$clarify))) throw new RuntimeException('clarification envelope retained delivery credential');
if (!preg_match('/^[a-f0-9]{64}$/D',AiExecutionEnvelope::hash('clarify',$clarify,$key))) throw new RuntimeException('clarification envelope hash is not keyed sha256');
$directory=sys_get_temp_dir().'/mohe-ai-envelope-'.bin2hex(random_bytes(8));
$private=new AiPrivateStorage($directory); $ref=$private->put('request',['input'=>'temporary'],time()+60);
$private->discard($ref);
try { $private->read($ref); throw new RuntimeException('discarded execution request stayed readable'); }
catch (RuntimeException $error) { if ($error->getMessage()!=='AI_PRIVATE_OBJECT_UNAVAILABLE') throw $error; }
@rmdir($directory);
echo "PASS async execution envelope minimization/canonical-hash contract\n";

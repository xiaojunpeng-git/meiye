<?php
declare(strict_types=1);

require __DIR__.'/http-guard-contract.php';

use app\services\ai\execution\AiRunExecutionQueue;

$port=(int)getenv('AI_TEST_REDIS_PORT');
if ($port<1024) throw new RuntimeException('ISOLATED_REDIS_PORT_REQUIRED');
$instance='instance-'.str_repeat('a',64);
$name=AiRunExecutionQueue::name($instance);
if ($name!=='MOHE_PRO_AI_EXECUTION:'.substr(hash('sha256',$instance),0,32)) throw new RuntimeException('execution queue must be per instance');
$other=AiRunExecutionQueue::name('instance-'.str_repeat('b',64));
$redis=new Redis(); $redis->connect('127.0.0.1',$port,2);
$base='{queues:'.$name.'}'; $otherBase='{queues:'.$other.'}';
$payload=json_encode(['job'=>app\jobs\ai\AiRunExecutionJob::class,'data'=>['do'=>'doJob','data'=>[str_repeat('a',48)],'errorCount'=>3]]);
try {
    $redis->rPush($base,$payload); $redis->rPush($otherBase,$payload);
    if ($redis->lRange($base,0,-1)!==[$payload] || $redis->lRange($otherBase,0,-1)!==[$payload]) throw new RuntimeException('instance queue isolation failed');
} finally { $redis->del($base,$otherBase); $redis->close(); }

$app->config->set(['default'=>'isolated','connections'=>['isolated'=>['type'=>'redis']]],'queue');
$connector=new class { public $calls=[]; public function push($job,$body,$queue){ $this->calls[]=[$job,$body,$queue]; return 'id'; } };
$app->instance('queue',new class($connector){private $connector;public function __construct($connector){$this->connector=$connector;}public function connection(){return $this->connector;}});
AiRunExecutionQueue::push($instance,str_repeat('c',48));
if (count($connector->calls)!==1 || $connector->calls[0][2]!==$name || $connector->calls[0][1]['data']!==[str_repeat('c',48)]) throw new RuntimeException('queue message must contain exactly one opaque Run id');

// A Redis/driver outage must be observable to the caller.  The gateway keeps
// the already committed Run and the supervisor later discovers it from the
// durable queue state (covered by async-execution-state-contract); it must not
// pretend this enqueue attempt succeeded.
$broken=new class { public function push($job,$body,$queue){ throw new RuntimeException('fixture queue outage'); } };
$app->instance('queue',new class($broken){private $connector;public function __construct($connector){$this->connector=$connector;}public function connection(){return $this->connector;}});
try {
    AiRunExecutionQueue::push($instance,str_repeat('d',48));
    throw new RuntimeException('queue driver outage must be visible');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='AI_EXECUTION_DISPATCH_UNKNOWN') throw $error;
}
echo "PASS dedicated isolated AI execution queue and outage contract\n";

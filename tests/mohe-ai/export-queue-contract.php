<?php
declare(strict_types=1);
require __DIR__.'/http-guard-contract.php';
// Must explicitly point to a disposable local Redis; never reads application Redis credentials.
$port=(int)getenv('AI_TEST_REDIS_PORT'); if($port<1024) throw new RuntimeException('ISOLATED_REDIS_PORT_REQUIRED');
$redis=new Redis(); $redis->connect('127.0.0.1',$port,2);
$instance='isolated.queue.'.bin2hex(random_bytes(8));
$base='{queues:'.\app\services\ai\execution\AiExportQueue::name($instance).'}';
$other='{queues:'.\app\services\ai\execution\AiExportQueue::name($instance.'.other').'}';
$payload=function($id){return json_encode(['job'=>\app\jobs\query\AiUnifiedQueryExportJob::class,'data'=>['do'=>'doJob','data'=>['uqe_'.str_repeat($id,32)],'errorCount'=>3]]);};
$now=time(); $live=$payload('a'); $expired=$payload('b'); $missing=$payload('c');
try {
    $redis->rPush($base,$live,$expired,$missing,'malformed');
    $redis->zAdd($base.':reserved',$now+300,$expired);
    $redis->zAdd($base.':delayed',$now+600,$expired);
    $redis->rPush($other,$expired);
    $stats=\app\services\ai\execution\AiExportQueue::cleanupMessages($redis,$instance,function($no)use($now){
        if($no==='uqe_'.str_repeat('c',32))return null;
        return ['source_type'=>'AI','expires_at'=>$now+($no==='uqe_'.str_repeat('a',32)?1:0)];
    },$now);
    $check($stats['removed_messages']===5 && $stats['invalid_messages']===1,'pending/reserved/delayed original expiry plus orphan/invalid cleanup');
    $check($redis->lRange($base,0,-1)===[$live],'live original-expiry task remains');
    $check($redis->lRange($other,0,-1)===[$expired],'another instance queue never touched');
    $check($redis->zCard($base.':reserved')===0 && $redis->zCard($base.':delayed')===0,'future retry score cannot extend original retention');
} finally { $redis->del($base,$base.':reserved',$base.':delayed',$other); $redis->close(); }
echo "PASS 4 isolated real Redis queue-retention checks.\n";
$app->config->set(['default'=>'isolated','connections'=>['isolated'=>['type'=>'redis']]],'queue');
$connector=new class { public $calls=0; public function push($job,$body,$queue){++$this->calls;throw new RuntimeException('sensitive connector detail');} };
$app->instance('queue',new class($connector){private $connector;public function __construct($connector){$this->connector=$connector;}public function connection(){return $this->connector;}});
try { \app\services\ai\execution\AiExportQueue::push($instance,'uqe_'.str_repeat('e',32)); throw new LogicException('expected unknown'); }
catch(RuntimeException $e){$check($e->getMessage()==='AI_EXPORT_DISPATCH_UNKNOWN' && $connector->calls===1,'unknown connector outcome never resent or leaked');}
echo "PASS 1 actual queue facade single-dispatch/UNKNOWN check (connector failure fixture).\n";

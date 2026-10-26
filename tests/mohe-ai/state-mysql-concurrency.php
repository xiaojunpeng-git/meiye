<?php
declare(strict_types=1);
// Only the disposable fixture launcher may supply this connection. Never boot App/.env.
ini_set('zend.exception_ignore_args', '1');
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRunStore.php';
use app\services\ai\execution\AiRunStore;
$port=getenv('MOHE_QUERY_TEST_PORT'); $password=getenv('MOHE_QUERY_TEST_PASSWORD');
if (getenv('MOHE_QUERY_TEST_DISPOSABLE')!=='yes' || !preg_match('/^[0-9]{4,5}$/D',(string)$port) || strlen((string)$password)<32 || !function_exists('pcntl_fork')) throw new RuntimeException('Disposable MySQL and pcntl required');
$connect=function () use($port,$password) { return new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=mohe_query_fixture;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); };
$db=$connect();
$db->exec(file_get_contents(__DIR__.'/../../后端代码/database/upgrades/2026-09-08-魔核AI运行状态/01-运行状态.sql'));
$db->exec("INSERT INTO eb_mohe_ai_mutex (instance_id,quarantined_slots) VALUES ('lock-regression',7)");
$db=null; // Never inherit an open MySQL socket across fork.
$parallel=function (callable $work) {
    $children=[];
    for($i=0;$i<8;$i++) {
        $pid=pcntl_fork(); if($pid<0) throw new RuntimeException('fork failed');
        if($pid===0) {
            try { $work($i); exit(0); }
            catch(Throwable $e) { fwrite(STDERR,'MYSQL_STATE_CHILD_FAILED '.get_class($e).' '.(string)$e->getCode()."\n"); exit(1); }
        }
        $children[]=$pid;
    }
    $failed=false;
    foreach($children as $pid) { pcntl_waitpid($pid,$status); $failed=$failed || !pcntl_wifexited($status) || pcntl_wexitstatus($status)!==0; }
    if($failed) throw new RuntimeException('concurrent transaction failed');
};
// Exercise the production mutex on an existing row with prolonged overlapping
// transactions. The upsert must not reset existing quarantine bookkeeping.
$parallel(function ($i) use($connect) {
    $db=$connect(); $store=new AiRunStore($db,'eb_','lock-regression');
    $tx=new ReflectionMethod($store,'transaction'); if(PHP_VERSION_ID<80100) $tx->setAccessible(true);
    for($j=0;$j<25;$j++) $tx->invoke($store,function () use($db) {
        $value=(int)$db->query("SELECT quarantined_slots FROM eb_mohe_ai_mutex WHERE instance_id='lock-regression'")->fetchColumn();
        usleep(2000);
        $s=$db->prepare("UPDATE eb_mohe_ai_mutex SET quarantined_slots=? WHERE instance_id='lock-regression'"); $s->execute([$value+1]);
    });
});
$db=$connect();
if((int)$db->query("SELECT quarantined_slots FROM eb_mohe_ai_mutex WHERE instance_id='lock-regression'")->fetchColumn()!==207) throw new RuntimeException('lost update or reset quarantine');
$db=null;
$parallel(function ($i) use($connect) {
    $db=$connect(); $store=new AiRunStore($db,'eb_','capacity-regression');
    $owner=['account_id'=>$i+1,'terminal'=>'store','conversation_id'=>'c'.$i,'window_id'=>'w'.$i];
    $snapshot=['capability_snapshot_ref'=>'cap','capability_snapshot_hash'=>str_repeat('a',64),'budget_profile_version'=>'b','authorization_version'=>'a','model_config_version'=>'m'];
    $store->create($owner,'request',hash('sha256','fixture'),$snapshot,180000,2);
});
$db=$connect();
foreach(['SELECT COUNT(*) FROM eb_mohe_ai_run WHERE instance_id=\'capacity-regression\''=>2,
    'SELECT SUM(slot_held) FROM eb_mohe_ai_run WHERE instance_id=\'capacity-regression\''=>2,
    'SELECT COUNT(*) FROM eb_mohe_ai_receipt WHERE instance_id=\'capacity-regression\''=>8] as $sql=>$expected) {
    if((int)$db->query($sql)->fetchColumn()!==$expected) throw new RuntimeException('capacity invariant failed');
}
echo "state-mysql-concurrency: PASS (8 processes, 200 serialized transactions, counters preserved, 8 creates / exactly 2 slots; isolated MySQL)\n";

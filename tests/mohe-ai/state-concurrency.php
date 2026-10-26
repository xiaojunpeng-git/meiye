<?php
declare(strict_types=1);
require __DIR__.'/state-store-contract.php';

if (!function_exists('pcntl_fork')) { throw new RuntimeException('pcntl is required for actual multi-process testing'); }
$file=tempnam(sys_get_temp_dir(),'mohe-ai-state-');
$setup=new PDO('sqlite:'.$file); installAiStateFixture($setup); $setup=null;
$children=[];
try {
    for ($i=0;$i<8;$i++) {
        $pid=pcntl_fork();
        if ($pid<0) { throw new RuntimeException('Cannot fork'); }
        if ($pid===0) {
            try {
                $connection=new PDO('sqlite:'.$file); $connection->exec('PRAGMA busy_timeout=5000');
                $s=new \app\services\ai\execution\AiRunStore($connection,'','concurrency.instance');
                $who=['account_id'=>$i+1,'terminal'=>'store','conversation_id'=>'c'.$i,'window_id'=>'w'.$i];
                $s->create($who,'request',hash('sha256','same input'),$snapshot,180000,2);
                exit(0);
            } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
        }
        $children[]=$pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid,$status); checkState(pcntl_wexitstatus($status)===0,'child transaction completed'); }
    $verify=new PDO('sqlite:'.$file);
    checkState((int)$verify->query('SELECT COUNT(*) FROM mohe_ai_run')->fetchColumn()===2,'eight simultaneous creates sell exactly two slots');
    checkState((int)$verify->query('SELECT COUNT(*) FROM mohe_ai_receipt')->fetchColumn()===8,'all accepted and rejected requests have receipts');
    checkState((int)$verify->query('SELECT SUM(slot_held) FROM mohe_ai_run')->fetchColumn()===2,'physical reservations not oversold');
    $verify=null;
    $raceConnection=new PDO('sqlite:'.$file);
    $raceStore=new \app\services\ai\execution\AiRunStore($raceConnection,'','race.instance');
    $raceOwner=['account_id'=>99,'terminal'=>'store','conversation_id'=>'race','window_id'=>'race'];
    $raceRun=$raceStore->create($raceOwner,'race',hash('sha256','race'),$snapshot)['run'];
    $raceStore->claim($raceOwner,$raceRun['run_id'],$raceRun['generation'],'race-worker');
    $raceStore=null; $raceConnection=null; $racers=[];
    for ($i=0;$i<2;$i++) {
        $pid=pcntl_fork();
        if ($pid<0) { throw new RuntimeException('Cannot fork race'); }
        if ($pid===0) {
            try {
                $c=new PDO('sqlite:'.$file); $c->exec('PRAGMA busy_timeout=5000');
                $s=new \app\services\ai\execution\AiRunStore($c,'','race.instance');
                if ($i===0) { $s->cancel($raceOwner,$raceRun['run_id'],$raceRun['generation']); }
                else { $s->publish($raceOwner,$raceRun['run_id'],$raceRun['generation'],'race-worker','race-evidence','race-answer'); }
                exit(0);
            } catch (RuntimeException $e) { exit($e->getMessage()==='AI_RUN_TERMINAL'?0:1); }
        }
        $racers[]=$pid;
    }
    foreach ($racers as $pid) { pcntl_waitpid($pid,$status); checkState(pcntl_wexitstatus($status)===0,'cancel/publish racer safe'); }
    $raceConnection=new PDO('sqlite:'.$file);
    $final=$raceConnection->query("SELECT status,answer_ref FROM mohe_ai_run WHERE instance_id='race.instance'")->fetch(PDO::FETCH_ASSOC);
    checkState(($final['status']==='CANCELLED' && $final['answer_ref']==='') || ($final['status']==='COMPLETED' && $final['answer_ref']==='race-answer'),'cancel/publish has exactly one atomic winner');
    $raceConnection=null;
    echo 'PASS '.$checks." total state/concurrency checks (8 processes / 2 slots; SQLite transaction adapter).\n";
} finally { unlink($file); }

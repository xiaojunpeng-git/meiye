<?php
declare(strict_types=1);
require __DIR__.'/state-store-contract.php';
$db=new PDO('sqlite::memory:'); installAiStateFixture($db); $now=100000000;
$store=new \app\services\ai\execution\AiRunStore($db,'','admission.test',function()use(&$now){return $now;});
$serial=0;
$finish=function(string $reason,string $delivery='')use($store,$owner,$hash,$snapshot,&$serial,&$now){
    ++$now; $r=$store->create($owner,'req'.++$serial,$hash,$snapshot)['run'];
    $store->claim($owner,$r['run_id'],$r['generation'],'worker');
    if ($delivery!=='') $store->publish($owner,$r['run_id'],$r['generation'],'worker','evidence','answer',$delivery);
    else $store->fail($owner,$r['run_id'],$r['generation'],'worker',$reason);
    $store->release($owner,$r['run_id'],$r['generation'],'worker');
};
for($i=0;$i<4;$i++) $finish('AI_EXECUTION_FAILED');
$finish('CAPACITY_STOPPED'); $finish('AI_PERMISSION_DENIED'); $finish('METRIC_QUERY_COVERAGE_UNAVAILABLE');
$finish('AI_EXPORT_FAILED');
$finish('AI_EXECUTION_FAILED');
checkState($store->create($owner,'blocked',$hash,$snapshot)['reason']==='CIRCUIT_OPEN','five technical failures count across neutral/export outcomes');
$now+=120001;
$finish('','data_only_export_failed');
for($i=0;$i<4;$i++) $finish('AI_EXECUTION_FAILED');
checkState($store->create($owner,'after-partial',$hash,$snapshot)['accepted'],'partial clears technical consecutive count');
$active=$store->create($owner,'after-partial',$hash,$snapshot)['run']; $store->cancel($owner,$active['run_id'],$active['generation']);
$diag=$store->diagnostics();
checkState($diag['export']===1 && $diag['partial']===1 && $diag['capacity']===1,'independent diagnostics retain root-cause classifications');
// Independent identity, all accepted cancelled Runs still count against new-Run rate.
$rateOwner=$owner; $rateOwner['account_id']=9;
for($i=0;$i<20;$i++){ $r=$store->create($rateOwner,'rate'.$i,$hash,$snapshot)['run']; $store->cancel($rateOwner,$r['run_id'],$r['generation']); }
checkState($store->create($rateOwner,'rate20',$hash,$snapshot)['reason']==='RATE_LIMITED','21st accepted-Run attempt in ten minutes refused');
checkState($store->create($rateOwner,'rate0',$hash,$snapshot)['replayed'],'idempotent replay bypasses new-Run quota');
$now+=600001;
checkState($store->create($rateOwner,'rate-new-window',$hash,$snapshot)['accepted'],'rate window expires');
echo "PASS 6 admission/circuit/diagnostic checks against actual transactional SQLite store.\n";

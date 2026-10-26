<?php
// Real reader-generated SQL on task-owned MySQL only. Worker secrets travel via
// inherited environment/stdin, never command arguments, files or test output.
if (($argv[1]??'')==='--worker') {
    if(getenv('MOHE_QUERY_TEST_DISPOSABLE')!=='yes')exit(2);
    $job=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $connection=new PDO('mysql:host=127.0.0.1;port='.getenv('MOHE_QUERY_TEST_PORT').';dbname=mohe_query_fixture;charset=utf8mb4','root',getenv('MOHE_QUERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $connection->exec('SET SESSION max_execution_time=20000');$samples=[];
    for($i=0;$i<3;$i++){
        $start=microtime(true);$connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');$connection->beginTransaction();
        $sum=(int)$connection->query($job['summary_sql'])->fetchColumn();
        $rows=$connection->query($job['daily_sql'])->fetchAll(PDO::FETCH_ASSOC);$connection->commit();
        if($sum!==$job['expected'] || array_sum(array_column($rows,'amount_cents'))!==$sum)throw new RuntimeException('scale result mismatch');
        $samples[]=(int)round((microtime(true)-$start)*1000);
    }
    echo json_encode(['samples_ms'=>$samples]);exit;
}
if (!isset($pdo,$reader) || getenv('MOHE_QUERY_TEST_DISPOSABLE')!=='yes') throw new RuntimeException('Disposable harness required');
$scaleTenant='scale-cash-fixture';$scaleStores=range(1000,1129);$scaleRange=['start'=>'2026-09-01','end'=>'2026-09-28'];
// Mirror relevant existing production index prefixes, not a claim that minimal
// fixture schemas represent full production row width.
$pdo->exec('ALTER TABLE eb_cashier_v3_payment_fact ADD KEY idx_scope_method_date (tenant_id,store_id,payment_method,business_date,status,id)');
$pdo->exec('ALTER TABLE eb_cashier_v3_recharge_debt_repayment ADD KEY idx_fixture_repayment (repayment_id)');
$pdo->exec('ALTER TABLE eb_cashier_v3_order_lifecycle_operation ADD KEY idx_source (tenant_id,source_type,source_order_id)');
$pdo->exec('ALTER TABLE eb_cashier_v3_order_center_void_operation ADD KEY idx_fixture_tenant (tenant_id)');
$bulk=function(string $table,array $fields,array $rows)use($pdo):void{
    if(!$rows)return;
    $values=[];foreach($rows as $row)foreach($row as $value)$values[]=$value;
    $pdo->prepare('INSERT INTO eb_'.$table.' ('.implode(',',$fields).') VALUES '.implode(',',array_fill(0,count($rows),'('.implode(',',array_fill(0,count($fields),'?')).')')))->execute($values);
};
$facts=[];$repayments=[];$voids=[];$expected=0;
for($n=0;$n<100000;$n++){
    $supplement=$n%2===1;$store=1000+$n%130;$id='scale-'.$n;$order=$supplement?$id:'RCH:'.(1000000+$n);$isVoid=$n%10===1;
    $facts[]=[$id,$scaleTenant,$store,10,'2026-09-'.str_pad((string)(1+intdiv($n,130)%28),2,'0',STR_PAD_LEFT),'effective','payment_collected',$supplement?'recharge_debt_repayment':'recharge','wechat',100,$order,$id,'org','org','规模测试店'];
    if($supplement)$repayments[]=[$scaleTenant,$id,1000000+$n,$store,10,'succeeded'];
    if($isVoid)$voids[]=[$scaleTenant,$id,'recharge_supplement','succeeded'];
    else $expected+=100;
    if(count($facts)===500 || $n===99999){
        $bulk('cashier_v3_payment_fact',['fact_id','tenant_id','store_id','member_id','business_date','status','fact_type','source_document_type','payment_method','amount_cents','order_id','source_line_id','organization_id','organization_path_snapshot','store_name_snapshot'],$facts);
        $bulk('cashier_v3_recharge_debt_repayment',['tenant_id','repayment_id','recharge_id','store_id','member_id','status'],$repayments);
        $bulk('cashier_v3_order_center_void_operation',['tenant_id','source_id','source_kind','status'],$voids);
        $facts=[];$repayments=[];$voids=[];
    }
}
$method=new ReflectionMethod($reader,'rechargeCashQuery');$method->setAccessible(true);
$summarySql=$method->invoke($reader,$scaleTenant,$scaleStores,$scaleRange)->fieldRaw('SUM(CASE WHEN p.amount_cents>0 THEN p.amount_cents ELSE 0 END) amount_cents')->fetchSql(true)->find();
$dailySql=$method->invoke($reader,$scaleTenant,$scaleStores,$scaleRange)->fieldRaw('p.store_id,p.business_date,SUM(CASE WHEN p.amount_cents>0 THEN p.amount_cents ELSE 0 END) amount_cents')->group('p.store_id,p.business_date')->fetchSql(true)->select();
$beforeExplain=$pdo->query('EXPLAIN '.$summarySql)->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('SET SESSION max_execution_time=1000');$beforeStart=microtime(true);$beforeState='completed';
try{$beforeAmount=(int)$pdo->query($summarySql)->fetchColumn();mysqlCheck($beforeAmount===$expected,'scale pre-index result if within budget');}
catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==3024)throw $e;$beforeState='timeout_1000ms';}
$beforeMs=(int)round((microtime(true)-$beforeStart)*1000);$pdo->exec('SET SESSION max_execution_time=20000');
$migration=dirname(__DIR__,2).'/后端代码/database/upgrades/2026-09-08-魔核AI现金查询索引/02-正式升级.sql';
$migrationSql=file_get_contents($migration);
// Migration contains ordinary semicolon-delimited SQL, no stored programs.
foreach(explode(';',$migrationSql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
foreach(explode(';',$migrationSql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
mysqlCheck((int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_order_center_void_operation' AND INDEX_NAME='idx_ai_cash_source_guard'")->fetchColumn()===4,'new migration repeats safely and has exact four-column guard index');
$afterExplain=$pdo->query('EXPLAIN '.$summarySql)->fetchAll(PDO::FETCH_ASSOC);
$jobs=[];$job=json_encode(['summary_sql'=>$summarySql,'daily_sql'=>$dailySql,'expected'=>$expected]);
for($i=0;$i<4;$i++){
    $process=proc_open([PHP_BINARY,__FILE__,'--worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('scale worker start failed');fwrite($pipes[0],$job);fclose($pipes[0]);$jobs[]=[$process,$pipes];
}
$samples=[];
foreach($jobs as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0)throw new RuntimeException('scale worker failed (no secret output): '.substr(hash('sha256',$error),0,12));$result=json_decode($output,true,512,JSON_THROW_ON_ERROR);$samples=array_merge($samples,$result['samples_ms']);}
sort($samples);mysqlCheck(count($samples)===12,'four real concurrent connections each complete three exact summary and daily reads');
$explainProjection=static function(array $rows):array{return array_map(static function($row){return array_intersect_key($row,array_flip(['select_type','table','type','possible_keys','key','rows','Extra']));},$rows);};
echo 'CASH_SCALE_RESULT='.json_encode(['mysql'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'facts'=>100000,'repayments'=>50000,'void_operations'=>10000,'stores'=>130,'days'=>28,'expected_gross_cents'=>$expected,'before_index'=>['status'=>$beforeState,'elapsed_ms'=>$beforeMs],'concurrency'=>4,'samples_ms'=>$samples,'p50_ms'=>$samples[5],'p95_ms'=>$samples[11],'explain_before'=>$explainProjection($beforeExplain),'explain_after'=>$explainProjection($afterExplain),'scope'=>'isolated MySQL 64MiB buffer pool / synthetic data; not production capacity acceptance'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";

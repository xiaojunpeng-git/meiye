<?php
/** Isolated Docker fixture; no customer data, local shared tables or Redis keys are modified. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_cardno_'.bin2hex(random_bytes(5));$prefix=$schema.':';
$admin->exec('CREATE DATABASE `'.$schema.'`');
try {
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t)$admin->exec('CREATE TABLE `'.$schema.'`.`'.$t[0].'` LIKE `'.$c['database'].'`.`'.$t[0].'`');
 $dbc=config('database');$dbc['connections']['mysql']['database']=$schema;$dbc['connections']['mysql']['fields_cache']=false;
 $app->config->set($dbc,'database');
 $cache=config('cache');$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';$app->config->set($cache,'cache');$app->instance('cache',new \think\Cache($app));
 putenv('CARDNO010_TEST=1');
 $insert=function($table,$values)use($admin,$schema){$row=[];foreach($admin->query('SHOW COLUMNS FROM `'.$schema.'`.`eb_'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $col){$k=$col['Field'];if(array_key_exists($k,$values)){$row[$k]=$values[$k];continue;}if($col['Extra']==='auto_increment'||$col['Default']!==null||$col['Null']==='YES')continue;$row[$k]=preg_match('/int|decimal|float|double/',$col['Type'])?0:'';}return Db::name($table)->insertGetId(array_merge($row,$values));};


 $assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
 foreach([1=>null,2=>'',3=>'7654321'] as $id=>$number)$insert('user_card_holder',['id'=>$id,'uid'=>10,'oid'=>100+$id,'card_name'=>'导入卡','card_no'=>$number,'is_del'=>$id===2?1:0,'write_times'=>4,'write_surplus_times'=>3]);
 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/14-历史持卡卡号补齐.php';
 // Force the second update to fail, proving the first card and audit cannot partially commit.
 Db::execute("CREATE TRIGGER cardno_test_fail BEFORE UPDATE ON eb_user_card_holder FOR EACH ROW BEGIN IF NEW.id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'; END IF; END");
 $failed=false;try{fill010ImportedCardNumbers();}catch(Throwable $e){$failed=true;}
 $assert($failed&&Db::name('user_card_holder')->where('id',1)->value('card_no')===null&&(int)Db::table('codex_010_card_no_audit')->count()===0,'failure_rolls_back_cards_and_audit');
 Db::execute('DROP TRIGGER cardno_test_fail');
 $ret=fill010ImportedCardNumbers();$assert($ret['filled']===2&&$ret['audited']===2&&$ret['other_fields_unchanged'],'only_missing_cards_filled_and_audited');
 $rows=Db::name('user_card_holder')->order('id')->select()->toArray();
 $assert(preg_match('/^[1-9][0-9]{6}$/',$rows[0]['card_no'])===1&&preg_match('/^[1-9][0-9]{6}$/',$rows[1]['card_no'])===1,'seven_digit_card_numbers');
 $assert(count(array_unique(array_column($rows,'card_no')))===3,'numbers_unique');
 $assert($rows[2]['card_no']==='7654321','existing_card_number_preserved');
 $assert((int)$rows[1]['is_del']===1&&(int)$rows[0]['write_surplus_times']===3,'historical_status_and_remaining_times_preserved');
 $failed=false;try{fill010ImportedCardNumbers();}catch(Throwable $e){$failed=$e->getMessage()==='ALREADY_APPLIED';}$assert($failed,'repeat_refused_no_number_reassignment');
}finally{$admin->exec('DROP DATABASE `'.$schema.'`');}

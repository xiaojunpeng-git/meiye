<?php
declare(strict_types=1);
/** Named-instance read-only verification; no fixtures, refunds, member changes or credentials leave the server. */
$site=$argv[1]??'';$mode=$argv[2]??'runtime';$targets=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($targets[$site])||!in_array($mode,['candidate','runtime'],true))throw new RuntimeException('Named target required');
[$domain,$database,$port]=$targets[$site];$root='/www/wwwroot/'.$domain;
$c=array_change_key_case(parse_ini_file($root.'/.env',true,INI_SCANNER_RAW)['DATABASE']);
if($c['database']!==$database||(int)($c['hostport']??3306)!==$port)throw new RuntimeException('Wrong database');
require $root.'/vendor/autoload.php';$app=new think\App($root.'/');$app->env->load($root.'/.env');$app->initialize();
set_exception_handler(function(Throwable $e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
if($mode==='candidate'){
 if(class_exists('app\\services\\activity\\combination\\CombinationGroupListServices',false))throw new RuntimeException('Class already loaded');
 $code=base64_decode($argv[3]??'',true);if($code===false||strpos($code,'<?php')!==0)throw new RuntimeException('Candidate required');eval(substr($code,5));
}
$db=think\facade\Db::connect();$db->execute('START TRANSACTION READ ONLY');
try{
 $reader=new app\services\activity\combination\CombinationGroupListServices();$admin=['id'=>1,'level'=>0,'admin_type'=>1];
 $page=$reader->page([],$admin);if(count($page['columns'])!==11||$page['limit']!==20)throw new RuntimeException('Existing list contract changed');
 $none=$reader->page(['keyword'=>'LOCAL_NO_MATCH_'.bin2hex(random_bytes(8))],$admin);if($none['count']!==0)throw new RuntimeException('Empty search failed');
 $sample=think\facade\Db::name('store_pink')->alias('p')->join('store_pink g','g.id=p.k_id')
  ->leftJoin('user u','u.uid=p.uid')->leftJoin('store_order o','o.id=p.order_id_key')
  ->where('p.k_id','>',0)->where('p.is_virtual',0)->where('p.uid','>',0)->where('g.k_id',0)->where('g.is_virtual',0)
  ->field('p.k_id,p.nickname,u.real_name,u.phone,o.user_phone')->limit(30)->select()->toArray();
 $checks=0;
 foreach($sample as$row){
  if(!$reader->page(['group_id'=>$row['k_id']],$admin)['count'])continue;
  foreach(['real_name','phone','user_phone','nickname']as$key){$keyword=trim((string)($row[$key]??''));if($keyword==='')continue;
   $filters=['group_id'=>$row['k_id'],'keyword'=>$keyword];$result=$reader->page($filters,$admin);$export=$reader->page($filters,$admin,true);
   if($result['count']!==1||count($result['list'])!==1||count($export['export'])!==1)throw new RuntimeException('Real participant list/export mismatch');$checks++;
  }
  if($checks)break;
 }
 $reflection=new ReflectionMethod($reader,'query');$reflection->setAccessible(true);$sql=$reflection->invoke($reader,['keyword'=>'LOCAL_SEARCH_PLAN'],null)->field('g.id')->fetchSql()->select();
 $plan=$db->query('EXPLAIN '.$sql);foreach($plan as$row)if($row['select_type']==='DEPENDENT SUBQUERY'&&$row['table']==='search_member'&&$row['type']==='ALL')throw new RuntimeException('Repeated member scan');
 echo json_encode(['result'=>'PASS','site'=>$site,'mode'=>$mode,'listCount'=>$page['count'],'pageRows'=>count($page['list']),
  'realParticipantChecks'=>$checks,'realParticipantAvailable'=>(bool)$sample,'emptySearch'=>true,'listExportSameGroup'=>true,
  'queryPlanValidated'=>true,'businessWrites'=>0,'realRefund'=>false],JSON_UNESCAPED_SLASHES),PHP_EOL;
}finally{$db->execute('ROLLBACK');}

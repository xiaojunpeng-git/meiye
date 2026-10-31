<?php
declare(strict_types=1);
/** 精确来源身份探针：数据库身份固定，事务只读；仅白名单SELECT查询，结果供私有审计。 */
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$targets=['rh'=>['ruihao','3306'],'008'=>['mohe_008','3307'],'010'=>['mohe_010_app','3307']];
$site=$input['site']??'';if(!isset($targets[$site]))throw new RuntimeException('Unapproved site');
require getcwd().'/vendor/autoload.php';(new think\App(getcwd().'/'))->initialize();
$cfg=config('database.connections.mysql');if($cfg['database']!==$targets[$site][0]||(string)$cfg['hostport']!==$targets[$site][1])throw new RuntimeException('Wrong database');
think\facade\Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');think\facade\Db::startTrans();
$out=['site'=>$site,'business_writes'=>0,'queries'=>[]];
try{foreach($input['queries'] as $key=>$sql){
 if(!preg_match('/^SELECT\s/i',$sql)||preg_match('/;|\b(INTO|FOR\s+UPDATE|LOCK|OUTFILE|SLEEP|BENCHMARK)\b/i',$sql))throw new RuntimeException('Only readonly select permitted');
 try{$out['queries'][$key]=think\facade\Db::query($sql);}catch(Throwable $e){$out['queries'][$key]=['error'=>$e->getMessage()];}
}}finally{think\facade\Db::rollback();}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;

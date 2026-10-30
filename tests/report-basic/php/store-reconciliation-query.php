<?php
/** 浏览器自动化的离线业务入口：只读已经通过原服务对账的脱敏收款证据。 */
error_reporting(E_ALL & ~E_DEPRECATED);
$backend=dirname(__DIR__,3).'/后端代码';require $backend.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($backend):void{if(strpos($class,'app\\')!==0)return;$file=$backend.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require$file;},true,true);
$evidence=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$source=$evidence['datasets'][1]['source'];$input=json_decode(base64_decode($argv[2],true),true,64,JSON_THROW_ON_ERROR);
$ids=array_map('intval',$input['store_ids']??array_column($source['stores'],'id'));
$context=['tenant_id'=>'0','visible_store_ids'=>$ids,'basic_scope_mode'=>'all','basic_actor_key'=>'platform:1','query_cutoff_date'=>'2026-10-28','data_as_of'=>1793145600];
$service=new app\services\report\StoreReconciliationReportServices(static function(array $ids,array $range)use($source):array{
 return ['stores'=>array_values(array_filter($source['stores'],static fn($s)=>in_array((int)$s['id'],$ids,true))),
 'methods'=>$source['methods'],'receipts'=>array_values(array_filter($source['receipts'],static fn($r)=>in_array((int)$r['store_id'],$ids,true)&&$r['business_date']>=$range['start_date']&&$r['business_date']<=$range['end_date']))];
});
try{echo json_encode(['status'=>200,'data'=>$service->query($context,$input['query'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
catch(Throwable $error){echo json_encode(['status'=>400,'msg'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit(1);}

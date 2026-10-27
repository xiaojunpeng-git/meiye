<?php
/** R38补漏只读验证：白名单只补单位，统计仍由原统一事实及授权仓库查询，禁止业务写入。 */
$site=$argv[1]??'';$mode=$argv[2]??'';$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];if(!isset($sites[$site]))throw new RuntimeException('INSTANCE_REQUIRED');[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;require $root.'/vendor/autoload.php';
// FIXED_CANDIDATE
// FIXED_REFERENCE
(new think\App($root.'/'))->initialize();set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});$cfg=config('database.connections.mysql');if($cfg['database']!==$database||(string)$cfg['hostport']!==$port)throw new RuntimeException('WRONG_INSTANCE');
use think\facade\Db;use app\services\query\UnifiedQueryRuntime;use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryContract;
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $runtime=UnifiedQueryRuntime::runtime();$factory=new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']);$admin=(int)Db::name('system_admin')->where('level',0)->where('status',1)->where('is_del',0)->value('id');$stats=[];
 foreach(InventoryStatisticsUnifiedQueryContract::PAGE_CODES as $code){
  $definition=InventoryStatisticsUnifiedQueryContract::definition($code);
  if($mode!=='before'){
   $original=app\services\product\inventory\query\R38FollowupReferenceContract::definition($code);$normalized=$definition;
   if(in_array($code,['inventory_statistics_expiry','inventory_statistics_age'],true))$normalized['fields']=array_values(array_filter($normalized['fields'],static function($f){return $f['key']!=='stock_unit';}));
   if($normalized!==$original)throw new RuntimeException('UNRELATED_FIELD_CONTRACT_CHANGE');
   if(!isset($runtime['registry']->page($code)['fields']['stock_unit']))throw new RuntimeException('UNIT_NOT_ALLOWED');
  }
  // 无可用总部仓的客户保留权限拒绝；不能为验证而新建仓库或放开权限。
  try{$context=$factory->make($admin,[],date('Y-m-d'),0,'HQ',$code,0);}catch(app\services\query\UnifiedQueryException $e){if($e->getErrorCode()==='UNIFIED_QUERY_SCOPE_INVALID'&&$e->getMessage()==='当前平台没有可用总部仓，查询已停止。'){$stats[$code]=['authorizedEmptyHeadquarters'=>true];continue;}throw $e;}
  $provider=$runtime['providers']->resolve($code);$payload=['pageCode'=>$code,'page'=>1,'limit'=>20];$a=$provider->query($context,$payload);if(count($a['records'])!==min(20,$a['total']))throw new RuntimeException('PAGE_SIZE');$scope=array_map('intval',$context['scope_dimensions']['location_id']);
  $item=['total'=>$a['total'],'rows'=>count($a['records']),'warehouses'=>count($scope)];
  if(in_array($code,['inventory_statistics_expiry','inventory_statistics_age'],true)){
   $sum=0;foreach($scope as $id){$one=$factory->make($admin,[],date('Y-m-d'),0,'HQ',$code,$id);$sum+=$provider->query($one,$payload)['total'];}if($sum!==$a['total'])throw new RuntimeException('HQ_SCOPE_MISMATCH');
   if($code==='inventory_statistics_expiry'){$safe=$provider->query($context,$payload+['filters'=>[['fieldKey'=>'remaining_shelf_life_days','operator'=>'greater_than','value'=>90]]]);$unknown=$provider->query($context,$payload+['filters'=>[['fieldKey'=>'remaining_shelf_life_days','operator'=>'is_null','value'=>null]]]);$item['over90']=$safe['total'];$item['unknown']=$unknown['total'];}
   if($a['total']>20){$b=$provider->query($context,array_merge($payload,['page'=>2]));if(count($b['records'])!==min(20,$a['total']-20)||array_intersect(array_column($a['records'],'record_id'),array_column($b['records'],'record_id')))throw new RuntimeException('SECOND_PAGE');$item['secondPageRows']=count($b['records']);}
  }
  $stats[$code]=$item;
 }
 echo json_encode(['result'=>'PASS','site'=>$site,'mode'=>$mode,'statistics'=>$stats,'configSHA256'=>hash('sha256',json_encode(Db::name('system_config')->order('id')->select()->toArray())),'parityWithOriginal'=>true,'readOnly'=>true,'businessWrites'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}

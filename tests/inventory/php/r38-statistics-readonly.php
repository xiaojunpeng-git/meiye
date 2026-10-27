<?php
/** 本地真实库只读检查：统计字段白名单、总部全仓/单仓范围和分页结果不能混用默认仓。 */
$root=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;use app\services\query\UnifiedQueryRuntime;use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryContract;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function verify($ok,$name){if(!$ok)throw new RuntimeException($name);}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $runtime=UnifiedQueryRuntime::runtime();$factory=new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']);$adminId=(int)Db::name('system_admin')->where('level',0)->where('status',1)->where('is_del',0)->value('id');$results=[];
 foreach(InventoryStatisticsUnifiedQueryContract::PAGE_CODES as $code){
  $fields=$runtime['registry']->page($code)['fields'];verify(isset($fields['stock_unit']),'stock unit missing '.$code);
  $context=$factory->make($adminId,[],date('Y-m-d'),0,'HQ',$code,0);
  $provider=$runtime['providers']->resolve($code);$payload=['pageCode'=>$code,'page'=>1,'limit'=>20];$a=$provider->query($context,$payload);verify(count($a['records'])===min(20,$a['total']),'first page size');
  $scope=array_map('intval',$context['scope_dimensions']['location_id']);
  if(in_array($code,['inventory_statistics_expiry','inventory_statistics_age'],true))foreach($a['records'] as $row)verify(in_array((int)$row['location_id'],$scope,true),'scope leak');
  $sum=0;foreach($scope as $id){$one=$factory->make($adminId,[],date('Y-m-d'),0,'HQ',$code,$id);$b=$provider->query($one,$payload);$sum+=$b['total'];}
  // 流水按商品跨仓汇总，只有批次快照的完整命中数才可逐仓相加。
  if(in_array($code,['inventory_statistics_expiry','inventory_statistics_age'],true))verify($sum===$a['total'],'all HQ mismatch');
  $results[$code]=['total'=>$a['total'],'pageRows'=>count($a['records']),'warehouses'=>count($scope),'stockUnitAllowed'=>true];
 }
 echo json_encode(['result'=>'PASS','readOnly'=>true,'results'=>$results],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}

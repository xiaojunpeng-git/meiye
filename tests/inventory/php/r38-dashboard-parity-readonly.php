<?php
/** 对比开工固定Git对象与本轮真实库投影，防止显隐/下钻改变已有库存余额、成本和临期排行。 */
$root=getenv('BACKEND_ROOT')?:'/var/www/html';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();set_exception_handler(function(Throwable $e){file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;use app\services\report\ProductManagementDashboardServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
$source=base64_decode(getenv('R38_ORIGINAL_SERVICE')?:'',true);if(!$source||hash('sha256',$source)!=='23b6c4ea0e59e0c31b9e7fd098ffc2277d9698ececa17292b16945e8d9b0461c')throw new RuntimeException('FIXED_BASE_REQUIRED');eval(substr(str_replace('final class ProductManagementDashboardServices','final class R38OriginalProductDashboard',$source),5));
function call($service,$name,$args){$m=new ReflectionMethod($service,$name);$m->setAccessible(true);return $m->invokeArgs($service,$args);}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $scope=['tenant_id'=>'0','store_ids'=>array_map('intval',Db::name('system_store')->column('id')),'cost_visible'=>true];$old=new app\services\report\R38OriginalProductDashboard();$new=new ProductManagementDashboardServices();$a=call($old,'inventoryRows',[$scope]);$b=call($new,'inventoryRows',[$scope]);foreach($b as&$row)unset($row['quantity_scale']);unset($row);if($a!==$b)throw new RuntimeException('INVENTORY_CHANGED');
 $cats=call($new,'categories',[]);$map=call($new,'productCategoryMap',[$cats,$b]);$x=call($old,'expiry',[$a,$map,$scope,$cats,0]);$y=call($new,'expiry',[$b,$map,$scope,$cats,0]);unset($y['cutoff_date'],$y['ranking_limit']);foreach($y['rankings']as&$rows)foreach($rows as&$row)unset($row['drilldown']);unset($rows,$row);if($x!==$y)throw new RuntimeException('EXPIRY_CHANGED');
 echo json_encode(['result'=>'PASS','batchRows'=>count($a),'buckets'=>count($x['buckets']),'inventoryAndCostUnchanged'=>true,'expiryTotalsAndRankingsUnchanged'=>true,'readOnly'=>true]),PHP_EOL;
}finally{Db::rollback();}

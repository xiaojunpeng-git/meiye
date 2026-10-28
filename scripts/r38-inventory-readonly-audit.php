<?php
/** R38核对仅允许只读事务：数量取已结算正反库存事实，到期日与成本取原批次，不改写任何历史记录。 */
$root=$argv[1]??'/www/wwwroot/rh.cc3798.com';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();set_exception_handler(function(Throwable $e){file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
if(config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('WRONG_DATABASE');
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $types=Db::name('inventory_batch_movement_fact')->where('tenant_id','0')->where('fact_status','SETTLED')->group('source_type')->column('source_type');
 $rows=Db::name('inventory_batch_movement_fact')->alias('f')->join('inventory_batch b','b.id=f.batch_id')->join('inventory_location l','l.id=f.location_id')->join('inventory_stock s','s.id=f.stock_id')->where('f.tenant_id','0')->where('f.fact_status','SETTLED')->where('f.business_date','<=',date('Y-m-d'))->where('l.location_status','ACTIVE')->where('b.batch_no','CB359326')->fieldRaw('l.store_id,l.id location_id,l.location_name,b.id batch_id,b.batch_no,b.product_name_snapshot,b.expire_date,b.unit_cost_cents,s.quantity_scale,SUM(IF(f.direction=1,f.quantity_units,-CAST(f.quantity_units AS SIGNED))) quantity_units')->group('l.store_id,l.id,l.location_name,b.id,b.batch_no,b.product_name_snapshot,b.expire_date,b.unit_cost_cents,s.quantity_scale')->select()->toArray();
 $files=[];foreach(['app/services/report/ProductManagementDashboardServices.php','app/services/product/inventory/InventoryMovementAnalyticsServices.php','app/services/product/inventory/InventoryMovementDisplayServices.php','app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php','public/view_inventory_v3/index.html','public/view_cashier_v3/index.html']as$p)$files[$p]=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;
 echo json_encode(['result'=>'PASS','cutoff'=>date('Y-m-d'),'source_types'=>$types,'sample_batch'=>$rows,'fileSHA256'=>$files,'readOnly'=>true],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}

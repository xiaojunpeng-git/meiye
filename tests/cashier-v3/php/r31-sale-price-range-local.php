<?php
/** 本地回滚业务测试：三类商品使用真实改价方法、真实SKU查询；失败必须无草稿/审计/欠款变更。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3CashierMoreActionServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function r31check($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$ref=new ReflectionClass(CashierV3CashierMoreActionServices::class);$service=$ref->newInstanceWithoutConstructor();$method=$ref->getMethod('updateLinePrice');$method->setAccessible(true);
$store=987654321;$workspace='R31-'.bin2hex(random_bytes(8));$scope=new CashierV3OperatorScope($store,1,'','local-test');
$dataScope=new CashierV3DataScopeContext(1,1,$store,'local-test','',[$store],'stores',[],false,'','local-test',[],['name'=>'R31测试员']);
$productSeed=Db::name('store_product')->order('id asc')->find();$skuSeed=Db::name('store_product_attr_value')->where('type',0)->find();$lineSeed=Db::name('cashier_v3_workspace_line')->order('id asc')->find();
if(!$productSeed||!$skuSeed||!$lineSeed)throw new RuntimeException('LOCAL_FIXTURES_REQUIRED');
$ids=[];$skuIds=[];Db::startTrans();
try{
 foreach([0=>'产品',4=>'卡项',5=>'组合卡项',6=>'项目']as $type=>$label){
  $p=$productSeed;unset($p['id']);$p['store_name']='R31'.$label;$p['type']=1;$p['relation_id']=$store;$p['product_type']=$type;$p['is_del']=0;
  $id=(int)Db::name('store_product')->insertGetId($p);$ids[]=$id;
  $sku=$skuSeed;unset($sku['id']);$sku['product_id']=$id;$sku['product_type']=$type;$sku['unique']='R31'.bin2hex(random_bytes(10));$sku['type']=0;$sku['price']='150.00';$sku['cost']='10.00';$sku['write_times']=5;$sku['write_valid']=1;$sku['price_range_min']='100.00';$sku['price_range_max']='200.00';
  $skuId=(int)Db::name('store_product_attr_value')->insertGetId($sku);$skuIds[]=$skuId;
  $line=$lineSeed;unset($line['id']);$line['workspace_id']=$workspace;$line['line_key']='R31-'.$type;$line['line_role']='sale';$line['catalog_product_id']=$id;$line['catalog_sku_id']=$skuId;$line['catalog_product_type']=$type;$line['quantity']=2;$line['unit_price_cents']=15000;$line['authority_snapshot_json']='{}';$line['debt_amount_cents']=12300;
  $lineId=Db::name('cashier_v3_workspace_line')->insertGetId($line);
  // 确认真实目录读取、展示DTO与唯一快照入口使用同一SKU边界。
  $catalogService=new app\services\cashier\v3\cashier\CashierV3SaleCatalogServices();
  $server=$catalogService->selectDraftSaleLineAfterGatewayLocksInTx($skuId,'R31-catalog-'.$type,$scope,$dataScope);
  r31check($server['display_snapshot']['salePriceRange']===['minimumCents'=>10000,'maximumCents'=>20000],'locked SKU range reaches display DTO: '.$label);
  if($type===0){
   $pr=new ReflectionClass(app\services\cashier\v3\settlement\CashierV3CheckoutPreparationServices::class);$preparation=$pr->newInstanceWithoutConstructor();
   foreach(['saleCatalog'=>$catalogService,'workspace'=>app()->make(app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices::class)]as $key=>$value){$prop=$pr->getProperty($key);$prop->setAccessible(true);$prop->setValue($preparation,$value);}
   $prepare=$pr->getMethod('authorityFromCheckoutSnapshot');$prepare->setAccessible(true);$ctx='r31-final-snapshot';$wid=app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id($store,$ctx);
   $final=static function($amount,$discount=0)use($prepare,$preparation,$skuId,$scope,$dataScope,$ctx,$wid){return $prepare->invoke($preparation,['memberId'=>0,'lines'=>[['lineId'=>'r31-final','lineRole'=>'sale','itemId'=>$skuId,'quantity'=>2,'lineAmountCents'=>$amount,'originalLineAmountCents'=>30000,'couponDiscountCents'=>$discount]]],'r31-final',[],$wid,$ctx,$scope,$dataScope);};
   foreach([19800,40200,0]as $amount){try{$final($amount);throw new RuntimeException('FINAL_RANGE_BYPASSED');}catch(CashierV3CommandException $error){r31check($error->getMessage()==='R31产品的售价区间是:最低100元,最高200元','direct unique snapshot rejects bypassed price');}}
   $frozen=$final(20000);r31check($frozen[0]['lines'][0]['lineAmountCents']===20000,'unique snapshot retains confirmed boundary amount');
   $frozen=$final(18000,2000);r31check($frozen[0]['lines'][0]['lineAmountCents']===18000,'coupon discount does not masquerade as manual underpricing');
  }
  $call=static function($amount)use($method,$service,$workspace,$line,$scope,$dataScope){return $method->invoke($service,$workspace,['lineId'=>$line['line_key'],'reason'=>'R31本地回滚测试','lineAmountCents'=>$amount,'price_range_min'=>0,'price_range_max'=>99999999],$scope,$dataScope);};
  $denied=static function($amount,$message)use($call,$workspace){
   $before=Db::name('cashier_v3_workspace_line')->where('workspace_id',$workspace)->order('id')->select()->toArray();
   try{$call($amount);throw new RuntimeException('OUT_OF_RANGE_ACCEPTED');}catch(CashierV3CommandException $e){r31check($e->getMessage()===$message,'exact range prompt');}
   r31check(Db::name('cashier_v3_workspace_line')->where('workspace_id',$workspace)->order('id')->select()->toArray()===$before,'rejected change preserves all cart prices, audit and debt');
  };
  $prompt='R31'.$label.'的售价区间是:最低100元,最高200元';
  $denied(19800,$prompt);$denied(40200,$prompt);$denied(0,$prompt);
  foreach([20000,40000,30000]as $amount){$call($amount);$saved=Db::name('cashier_v3_workspace_line')->where('id',$lineId)->find();r31check((int)$saved['unit_price_cents']===intdiv($amount,2)&&(int)$saved['debt_amount_cents']===0&&(int)$saved['price_changed_by']===1,'inclusive unit range and successful audit/debt handling: '.$label);}
  Db::name('store_product_attr_value')->where('id',$skuId)->update(['price_range_min'=>'2980.00','price_range_max'=>'2980.00']);
  $denied(595800,'R31'.$label.'的售价区间是:最低2980元,最高2980元');$call(596000);
  Db::name('store_product_attr_value')->where('id',$skuId)->update(['price_range_min'=>'0.00','price_range_max'=>'200.00']);$call(0);$denied(40200,'R31'.$label.'的售价区间是:最低0元,最高200元');
  Db::name('store_product_attr_value')->where('id',$skuId)->update(['price_range_min'=>'100.00','price_range_max'=>'0.00']);$denied(19800,'R31'.$label.'的售价区间是:最低100元,最高不限');$call(60000);
  Db::name('store_product_attr_value')->where('id',$skuId)->update(['price_range_min'=>'0.00','price_range_max'=>'0.00']);$call(0);$call(60000);
  try{$call(100);throw new RuntimeException('COST_BYPASSED');}catch(CashierV3CommandException $e){r31check(strpos($e->getMessage(),'不能低于成本价')!==false,'unbounded range preserves positive cost floor: '.$label);}
  try{$call(20001);throw new RuntimeException('DECIMAL_ACCEPTED');}catch(CashierV3CommandException $e){r31check($e->getMessage()==='改价金额必须填写整数元。','whole-yuan rule retained: '.$label);}
 }
}finally{Db::rollback();}
r31check(Db::name('store_product')->whereIn('id',$ids)->count()===0&&Db::name('store_product_attr_value')->whereIn('id',$skuIds)->count()===0&&Db::name('cashier_v3_workspace_line')->where('workspace_id',$workspace)->count()===0,'fixtures fully rolled back');
echo "R31 SALE PRICE RANGE PASS\n";

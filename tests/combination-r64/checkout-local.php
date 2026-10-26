<?php
declare(strict_types=1);
/** Local-only regression for the member's actual cart -> confirm -> create contract.
 * Synthetic rows are rolled back; no payment gateway, SMS or production connection is used. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use think\facade\Event;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderCreateServices;
use app\services\product\shipping\ShippingTemplatesServices;
use app\controller\api\v1\order\StoreOrder;
$app=new think\App('/var/www/html/');$app->initialize();
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker ruihao only');
$app->instance('queue',new class{public function __call($method,$args){return 1;}});
Event::remove('order.create');
// QR generation is external infrastructure; order/store validation remains real.
$app->instance(app\services\store\SystemStoreServices::class,new class(app()->make(app\dao\store\SystemStoreDao::class)) extends app\services\store\SystemStoreServices { public function getStoreQrcode(int $id,int $staff_id=0){return [];} });
function check64(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label,"\n";}
function seed64(string $table,array $data):int{
 foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as $f){$k=$f['Field'];if(array_key_exists($k,$data)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$data[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}
 return (int)Db::name($table)->insertGetId($data);
}
// A connection-local empty table reproduces 008 without deleting any local template.
$ddl=Db::query('SHOW CREATE TABLE eb_shipping_templates')[0]['Create Table'];
Db::execute(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl,1));
check64(!app()->make(ShippingTemplatesServices::class)->get(1,['id']),'no default shipping template fixture');
$redisKeys=[];$stockSkus=[];$quoteResponses=[];
// Explicitly reproduce retired pickup settings; configuration is rolled back
// with the fixture and its cache is cleared after rollback, never left enabled.
$legacySwitchesOff=getenv('R64_LEGACY_SWITCHES_OFF')==='1';
// Reproduce the actual platform activity: catalog/branch stock zero, retail off,
// independent group quota available. Store-owned stock fixtures cannot cover it.
$platformZeroStock=getenv('R64_PLATFORM_ZERO_STOCK')==='1';
$expectedPrice=$platformZeroStock?1:399;$failure=null;Db::startTrans();
try{
 if($legacySwitchesOff){
  Db::name('system_config')->whereIn('menu_name',['store_func_status','store_self_mention'])->update(['value'=>json_encode(0)]);
  SystemConfigService::clear();
  check64(!sys_config('store_func_status')&&!sys_config('store_self_mention'),'legacy global switches are disabled');
 }
 $tag='R64-'.bin2hex(random_bytes(5));
 $delivery=$legacySwitchesOff?'1':'2';
 $store=seed64('system_store',['name'=>$tag,'is_show'=>1,'is_del'=>0,'delivery_type'=>$delivery]);
 $uid=seed64('user',['nickname'=>$tag,'real_name'=>'本地测试','phone'=>'19900000064','belong_store_id'=>$store,'status'=>1,'now_money'=>0,'ben_money'=>0,'give_money'=>0]);
 $user=app()->make(app\services\user\UserServices::class)->get($uid);
 foreach([0,5,6]as $productType){
  $sku=substr(hash('sha256',$tag.$productType),0,7);
  $pid=seed64('store_product',['store_name'=>$tag,'product_type'=>$productType,'type'=>$platformZeroStock?0:1,'relation_id'=>$platformZeroStock?0:$store,'price'=>399,'stock'=>$platformZeroStock?0:10,'sales'=>0,'is_show'=>$platformZeroStock?0:1,'is_del'=>0,'is_verify'=>1,'delivery_type'=>'2','is_inventory'=>0,'allow_negative_stock'=>1,'applicable_type'=>1]);
  if($platformZeroStock){
   $branch=seed64('store_product',['pid'=>$pid,'store_name'=>$tag,'product_type'=>$productType,'type'=>1,'relation_id'=>$store,'price'=>399,'stock'=>0,'is_show'=>0,'is_del'=>0,'is_verify'=>1,'delivery_type'=>'2','is_inventory'=>0,'allow_negative_stock'=>1]);
   seed64('store_product_attr_value',['product_id'=>$branch,'type'=>0,'unique'=>$sku.'b','suk'=>'默认','price'=>399,'stock'=>0]);
  }
  $cid=seed64('store_combination',['title'=>$tag,'product_id'=>$pid,'product_type'=>$productType,'type'=>0,'relation_id'=>$platformZeroStock?0:$store,'stock'=>10,'quota'=>10,'quota_show'=>10,'price'=>$expectedPrice,'people'=>3,'effective_time'=>24,'is_show'=>1,'is_del'=>0,'start_time'=>time()-60,'stop_time'=>time()+3600,'once_num'=>10,'num'=>10,'delivery_type'=>$delivery,'applicable_type'=>1]);
  foreach([[0,$pid],[3,$cid]]as [$type,$id])seed64('store_product_attr_value',['product_id'=>$id,'type'=>$type,'unique'=>$sku.($type?'g':'p'),'suk'=>'默认','price'=>$type?$expectedPrice:399,'stock'=>$platformZeroStock&&!$type?0:10,'quota'=>10,'sum_stock'=>10,'quota_show'=>10,'write_times'=>1,'write_valid'=>1]);
  CacheService::setStock($sku.'g',10,3);$stockSkus[]=$sku.'g';
  $cartService=app()->make(StoreCartServices::class);$cartService->setItem('store_id',$store);
  [$key]=$cartService->setCart($uid,$pid,1,$sku.'g',3,true,$cid);$redisKeys[]=$key;
  $request=(new app\Request())->withServer(['REQUEST_METHOD'=>'POST']);$request->uid=$uid;$request->user=$user;
  $request->withPost(['cartId'=>(string)$key,'new'=>1,'shipping_type'=>2,'store_id'=>$store,'addressId'=>0]);
  $controller=new StoreOrder(app()->make(StoreOrderServices::class));
  $res=$controller->confirm($request,app()->make(ShippingTemplatesServices::class))->getData();
  check64(($res['status']??0)===200,'type '.$productType.' confirm succeeds: '.($res['msg']??''));
  $data=$res['data'];$quoteResponses[]=['productType'=>$productType,'storeId'=>$store,'cartId'=>(string)$key,'expectedPrice'=>$expectedPrice,'quote'=>$data];check64($data['valid_count']===1&&count($data['cartInfo'])===1,'type '.$productType.' selected item remains valid');
  check64((int)$data['priceGroup']['totalPrice']===$expectedPrice&&(float)$data['priceGroup']['storePostage']===0.0,'type '.$productType.' full price and zero postage');
  check64($data['addressInfo']===[]&&$data['upgrade_addr']===0,'type '.$productType.' no shipping address dependency');
  if($legacySwitchesOff&&!$platformZeroStock){
   // Re-run the real cart validator with negative authority contexts; the group
   // exception must not open other stores, forbidden applicability or legacy sales.
   $otherStore=seed64('system_store',['name'=>$tag.'-other','is_show'=>1,'is_del'=>0,'delivery_type'=>'1']);
   [, $allowed,$denied]=$cartService->handleCartList($uid,$data['cartInfo'],[],2,$otherStore);
   check64(count($allowed)===0&&count($denied)===1,'type '.$productType.' other store still rejected');
   $restricted=$data['cartInfo'];$restricted[0]['productInfo']['applicable_type']=2;$restricted[0]['productInfo']['applicable_store_id']=(string)$otherStore;
   [, $allowed,$denied]=$cartService->handleCartList($uid,$restricted,[],2,$store);
   check64(count($allowed)===0&&count($denied)===1,'type '.$productType.' applicability still enforced');
   $legacy=$data['cartInfo'];$legacy[0]['type']=0;$legacy[0]['activity_id']=0;
   [, $allowed,$denied]=$cartService->handleCartList($uid,$legacy,[],2,$store);
   check64(count($allowed)===0&&($denied[0]['invalid_desc']??'')==='平台/门店已关闭核销','type '.$productType.' legacy pickup guard retained');
  }
  if($platformZeroStock){
   $inventory=app()->make(app\services\product\inventory\ProductInventoryChangeServices::class);
   // A live-like platform activity must still respect its allowed stores. The
   // inventory exception is not a blanket exception for all invalid cart rows.
   $restricted=$data['cartInfo'];$restricted[0]['productInfo']['applicable_type']=2;$restricted[0]['productInfo']['applicable_store_id']='2147483647';
   [, $allowed,$denied]=$cartService->handleCartList($uid,$restricted,[],2,$store);
   check64(!$allowed&&count($denied)===1,'type '.$productType.' platform applicability remains enforced');
   if($productType===0){
    // Switch the *same* platform/root/branch fixture through inventory policies;
    // compare confirmation and the real payment stock guard, not copied formulas.
    Db::name('store_product')->where('id',$pid)->update(['is_inventory'=>0,'allow_negative_stock'=>0]);
    [, $allowed]=$cartService->handleCartList($uid,$data['cartInfo'],[],2,$store);
    $inventory->checkOrderPayableStock($data['cartInfo'],$store);
    check64(count($allowed)===1,'inventory disabled works even when negative stock is disabled');
    Db::name('store_product')->where('id',$pid)->update(['is_inventory'=>1,'allow_negative_stock'=>1,'stock'=>10]);
    Db::name('store_product_attr_value')->where('product_id',$pid)->where('type',0)->update(['stock'=>10]);
    [, $allowed]=$cartService->handleCartList($uid,$data['cartInfo'],[],2,$store);
    $inventory->checkOrderPayableStock($data['cartInfo'],$store);
    check64(count($allowed)===1,'negative stock allowed agrees between confirmation and payment');
    Db::name('store_product')->whereIn('id',[$pid,$branch])->update(['is_inventory'=>1,'allow_negative_stock'=>0]);
    Db::name('store_product')->where('id',$branch)->update(['is_show'=>1]);
    [, $allowed,$denied]=$cartService->handleCartList($uid,$data['cartInfo'],[],2,$store);
    $blocked=false;try{$inventory->checkOrderPayableStock($data['cartInfo'],$store);}catch(think\exception\ValidateException $error){$blocked=true;}
    check64(!$allowed&&count($denied)===1&&$blocked,'managed physical shortage blocks confirmation and payment');
    Db::name('store_product_attr_value')->where('product_id',$branch)->where('type',0)->update(['stock'=>10]);
    [, $allowed]=$cartService->handleCartList($uid,$data['cartInfo'],[],2,$store);
    $inventory->checkOrderPayableStock($data['cartInfo'],$store);
    check64(count($allowed)===1,'managed physical product with sufficient branch stock passes both guards');
   }else{
    Db::name('store_product')->where('id',$pid)->update(['is_inventory'=>1,'allow_negative_stock'=>0]);
    [, $allowed]=$cartService->handleCartList($uid,$data['cartInfo'],[],2,$store);
    $inventory->checkOrderPayableStock($data['cartInfo'],$store);
    check64(count($allowed)===1,'type '.$productType.' rights do not become physical stock when flag is enabled');
   }
   // Restore the exact live-like zero-stock/off-retail fixture before actual create.
   Db::name('store_product')->whereIn('id',[$pid,$branch])->update(['is_inventory'=>0,'allow_negative_stock'=>1,'stock'=>0,'is_show'=>0]);
   Db::name('store_product_attr_value')->whereIn('product_id',[$pid,$branch])->where('type',0)->update(['stock'=>0]);
  }
  $orderKey=$data['orderKey'];$redisKeys[]='user_order_'.$uid.$orderKey;
  $request=(new app\Request())->withServer(['REQUEST_METHOD'=>'POST']);$request->uid=$uid;$request->user=$user;
  $request->withPost(['combinationId'=>$cid,'shipping_type'=>2,'store_id'=>$store,'real_name'=>'本地测试','phone'=>'19900000064','new'=>1,'payType'=>'weixin','from'=>'routine']);
  $created=$controller->create($request,app()->make(StoreOrderCreateServices::class),$orderKey)->getData();
  check64(($created['status']??0)===200,'type '.$productType.' order creation succeeds: '.($created['msg']??''));
  $order=Db::name('store_order')->where('order_id',$created['data']['result']['order_id']??'')->find();
  check64($order&&$order['paid']==0&&(int)$order['store_id']===$store&&(int)$order['shipping_type']===2&&(int)$order['pay_price']===$expectedPrice,'type '.$productType.' unpaid order retains selected store and amount');
  app()->make(app\services\product\inventory\ProductInventoryChangeServices::class)->assertOrderCanPay($order);
  check64(true,'type '.$productType.' authoritative pre-payment inventory guard passes');
  check64(Db::name('combination_order_fulfillment')->where('order_id',$order['id'])->value('state')==='pending','type '.$productType.' group rights remain pending');
  if($platformZeroStock){
   check64((int)Db::name('store_product_attr_value')->where('product_id',$pid)->where('type',0)->value('stock')===0&&(int)Db::name('store_product_attr_value')->where('product_id',$branch)->where('type',0)->value('stock')===0,'type '.$productType.' create does not invent catalog stock');
   check64((int)Db::name('store_combination')->where('id',$cid)->value('quota')===9,'type '.$productType.' create consumes exactly one group place');
  }
  $repeat=$controller->create($request,app()->make(StoreOrderCreateServices::class),$orderKey)->getData();
  check64(($repeat['data']['status']??'')==='EXTEND_ORDER'&&Db::name('store_order')->where('unique',$orderKey)->count()===1,'type '.$productType.' repeated submit returns original order');
  if($legacySwitchesOff){
   Db::name('store_product_attr_value')->where('product_id',$cid)->where('type',3)->update(['quota'=>0]);
   $blocked=false;try{$cartService->setCart($uid,$pid,1,$sku.'g',3,true,$cid);}catch(think\exception\ValidateException $error){$blocked=strpos($error->getMessage(),'库存不足')!==false;}
   check64($blocked,'type '.$productType.' exhausted group quota still rejected');
  }
 }
 // Old shipping requests must still fail safely; this fix cannot accept missing freight configuration.
 $request=(new app\Request())->withServer(['REQUEST_METHOD'=>'POST']);$request->uid=$uid;$request->user=$user;
 $request->withPost(['cartId'=>'LOCAL_TEST','new'=>1,'shipping_type'=>1]);
 $res=$controller->confirm($request,app()->make(ShippingTemplatesServices::class))->getData();
 check64(($res['status']??0)===400&&$res['msg']==='默认模板未配置，无法下单','legacy shipping guard preserved');
 if(getenv('R64_EXPORT_QUOTES')==='1')file_put_contents('/tmp/r64-confirm-responses.json',json_encode($quoteResponses,JSON_UNESCAPED_UNICODE));
 echo "R64 CHECKOUT COMPLETE\n";
}catch(Throwable $error){$failure=$error;echo 'FAIL ',get_class($error),' ', $error->getMessage(),"\n";
}finally{Db::rollback();if($legacySwitchesOff)SystemConfigService::clear();foreach($redisKeys as $key)CacheService::redisHandler()->delete((string)$key);foreach($stockSkus as $sku)CacheService::setStock($sku,0,3);}

echo "R64 CLEANUP COMPLETE\n";
// The bundled Swoole emits a deprecation during CLI shutdown. Restore PHP handling
// after every assertion and cleanup so this infrastructure notice is not promoted to a business failure.
restore_error_handler();
// Framework exception rendering may exit zero in CLI; failed assertions must
// explicitly fail the regression process after transaction/cache cleanup.
exit($failure ? 1 : 0);

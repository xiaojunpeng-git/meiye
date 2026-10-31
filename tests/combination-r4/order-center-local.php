<?php
declare(strict_types=1);
/** 本地回滚夹具：执行真正的订单中心统一入口，覆盖商城单合并、分页和权限，不补造财务事实。 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\query\UnifiedQueryModule;
$app=new think\App('/var/www/html/');$app->initialize();
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker only');
// 固定候选从唯一 Git 正本导出的只读类，排除共享工作区另一任务的退款草稿。
$source=getenv('R4_PROVIDER_SOURCE');if($source){if(class_exists(app\services\cashier\v3\order\AbstractCashierV3OrderCenterUnifiedQueryProvider::class,false))throw new RuntimeException('Provider loaded before fixed source');$code=file_get_contents($source);if(hash('sha256',$code)!==getenv('R4_PROVIDER_SHA'))throw new RuntimeException('Fixed source changed');eval(substr($code,5));}
function seedOC4(string$t,array$d):int{foreach(Db::query('SHOW COLUMNS FROM eb_'.$t)as$f)if(!array_key_exists($f['Field'],$d)&&$f['Default']===null&&$f['Null']!=='YES'&&strpos($f['Extra'],'auto_increment')===false)$d[$f['Field']]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';return(int)Db::name($t)->insertGetId($d);}
$checks=[];function checkOC4(bool$b,string$m):void{global$checks;if(!$b)throw new RuntimeException('FAIL '.$m);$checks[]=$m;}
Db::startTrans();$error=null;try{
 $facts=[];foreach(['cashier_v3_sales_order','cashier_v3_payment_fact','cashier_v3_balance_fact']as$t)$facts[$t]=Db::name($t)->count();
 $tag='OC4-'.bin2hex(random_bytes(4));$store=seedOC4('system_store',['name'=>$tag,'is_show'=>1,'is_del'=>0]);$other=seedOC4('system_store',['name'=>$tag.'-other','is_show'=>1,'is_del'=>0]);$uid=seedOC4('user',['nickname'=>$tag,'status'=>1,'is_del'=>0]);$pid=seedOC4('store_product',['store_name'=>$tag,'product_type'=>6,'is_del'=>0]);$now=time()-60;$date=date('Y-m-d',$now);$ids=[];
 for($i=0;$i<23;$i++){
  $oid=seedOC4('store_order',['order_id'=>$tag.'-'.$i,'uid'=>$uid,'store_id'=>$i===22?$other:$store,'order_type'=>0,'type'=>$i===21?3:0,'product_type'=>6,'paid'=>1,'pay_time'=>$now,'add_time'=>$now-10,'pay_type'=>'weixin','trade_no'=>'R4PAY-'.$i,'cash_pay_price'=>1,'yue_pay_price'=>0,'coupon_price'=>$i===0?2:0,'total_postage'=>0,'pay_price'=>1,'total_num'=>1,'total_price'=>$i===0?3:1,'refund_status'=>0,'is_del'=>0,'is_system_del'=>0,'unique'=>$tag.'-'.$i]);$ids[]=$oid;
  seedOC4('store_order_cart_info',['oid'=>$oid,'uid'=>$uid,'cart_id'=>$tag.'-'.$i,'product_id'=>$pid,'product_type'=>6,'cart_type'=>0,'cart_num'=>1,'surplus_num'=>1,'pay_price'=>1,'total_price'=>1,'cart_info'=>json_encode(['product_id'=>$pid,'productInfo'=>['id'=>$pid,'store_name'=>'已购测试项目','attrInfo'=>['suk'=>'默认']]]),'unique'=>md5($tag.$i)]);
  seedOC4('store_pink',['order_id_key'=>$oid,'uid'=>$uid,'status'=>$i===21?1:2,'is_refund'=>0]);seedOC4('combination_order_fulfillment',['order_id'=>$oid,'state'=>$i===21?'pending':'issued','created_at'=>$now,'issued_at'=>$now]);
 }
 seedOC4('suixingpay_product_payment_attempt',['order_id_key'=>$ids[20],'business_order_no'=>$tag.'-20','channel_order_no'=>$tag.'-channel','merchant_id'=>'LOCAL_MERCHANT_R4','amount_cents'=>100,'status'=>'paid','transaction_id'=>'R4PAY-20']);
 $operator=new CashierV3OperatorScope($store,1,'','0');$scope=new CashierV3DataScopeContext(1,0,$store,'0','',[$store],'stores',[],false,'','1',['cashier.v3.order_center'],[]);$rt=UnifiedQueryModule::runtime();
 $query=static function(string$page,array$extra=[])use($rt,$operator,$scope,$date):array{$p=clone$rt['providers']->resolve($page);$ctx=$rt['contextFactory']->make($operator,$scope,['pageCode'=>$page]);return$p->queryPage($ctx,array_replace(['recordType'=>$page==='order_center_all'?'all':'sales','dataScope'=>'normal','dateFrom'=>$date,'dateTo'=>$date,'topFilters'=>[['field'=>'business_date','operator'=>'gte','value'=>$date],['field'=>'business_date','operator'=>'lte','value'=>$date]],'page'=>1,'pageSize'=>20],$extra),$operator,$scope);};
 $first=$query('order_center_all');$second=$query('order_center_all',['page'=>2]);
 checkOC4($first['total']===21&&count($first['records'])===20,'all orders shows twenty completed group purchases and correct total');
 checkOC4($second['total']===21&&count($second['records'])===1,'second page retains one remaining group order');
 $seen=array_merge(array_column($first['records'],'id'),array_column($second['records'],'id'));checkOC4(count(array_unique($seen))===21,'no duplicate purchases across pages');
 checkOC4(!in_array('legacy-sale-'.$ids[21],$seen,true),'pending group stays hidden');checkOC4(!in_array('legacy-sale-'.$ids[22],$seen,true),'another store remains hidden');
 $sales=$query('order_center_sales');checkOC4($sales['total']===21,'sales and all tabs share purchase source');
 $filtered=$query('order_center_all',['keyword'=>$tag.'-20']);checkOC4($filtered['total']===1&&$filtered['records'][0]['id']==='legacy-sale-'.$ids[20],'original order number search finds the purchase');
 checkOC4((float)$filtered['records'][0]['receivableAmount']===1.0&&(float)$filtered['records'][0]['actualReceivedAmount']===1.0,'real paid order exposes receivable and received amounts');
 $moneyRow=$filtered['records'][0];checkOC4((float)$moneyRow['items'][0]['unitPrice']===1.0&&(float)$moneyRow['items'][0]['amountBeforeCoupon']===1.0,'purchase line exposes price and amount without counting rights twice');
 checkOC4($moneyRow['paymentDetails'][0]['methodName']==='微信'&&(float)$moneyRow['paymentDetails'][0]['amount']===1.0&&strpos($moneyRow['paymentDetails'][0]['remark'],'LOCAL_MERCHANT_R4')!==false,'wechat collection retains original transaction and paid merchant');
 checkOC4($moneyRow['storeName']===$tag&&$moneyRow['availableActions']===[],'store is readable without granting V3 financial commands');
 $couponRow=$query('order_center_all',['keyword'=>$tag.'-0'])['records'][0];checkOC4((float)$couponRow['items'][0]['amountBeforeCoupon']===3.0&&(float)$couponRow['receivableAmount']===1.0&&(float)$couponRow['couponDiscountAmount']===2.0,'coupon before and after amounts remain distinct');
 Db::name('store_order')->where('id',$ids[1])->update(['pay_type'=>'yue','cash_pay_price'=>0,'yue_pay_price'=>1]);$balanceRow=$query('order_center_all',['keyword'=>$tag.'-1'])['records'][0];checkOC4((float)$balanceRow['actualReceivedAmount']===0.0&&$balanceRow['paymentDetails']===[]&&$balanceRow['paymentMethod']==='余额支付','balance payment is not fabricated as a cash collection');
 Db::name('store_order')->where('id',$ids[2])->update(['cash_pay_price'=>0]);$badRow=$query('order_center_all',['keyword'=>$tag.'-2'])['records'];$bad=array_values(array_filter($badRow,static function($row)use($ids){return$row['id']==='legacy-sale-'.$ids[2];}))[0];checkOC4($bad['receivableAmount']===null&&$bad['paymentDetails']===[],'inconsistent payment equation remains unknown');
 $miss=$query('order_center_all',['keyword'=>$tag.'-missing']);checkOC4($miss['total']===0&&$miss['records']===[],'empty search returns no fabricated order');
 $denied=$query('order_center_all',['storeIds'=>[$other]]);checkOC4($denied['total']===0,'client store filter cannot expand operator scope');
 // 冻结导出复用同一权限与日期计划，不能只导出第一页或漏掉商城单。
 $provider=clone$rt['providers']->resolve('order_center_all');$ctx=$rt['contextFactory']->make($operator,$scope,['pageCode'=>'order_center_all']);
 $scopeProperty=new ReflectionProperty(get_parent_class($provider),'liveScopes');$scopeProperty->setAccessible(true);$scopeProperty->setValue($provider,[$operator,$scope]);
 $plan=$rt['execution']->validatedPlan('order_center_all',[],['topFilters'=>[['field'=>'business_date','operator'=>'eq','value'=>$date]],'dataScope'=>'normal'],$ctx);
 $export=$provider->executeFrozenPlan($ctx,$plan,'query',['order_no','member_name','receivable_amount','actual_received_amount','payment_method','line_amount','store']);
 checkOC4(count($export['exportRows'])===21&&count(array_unique(array_column($export['exportRows'],'order_no')))===21,'frozen query export includes all twenty-one purchases once');
 $exportMoney=array_values(array_filter($export['exportRows'],static function($row)use($tag){return$row['order_no']===$tag.'-20';}))[0];
 checkOC4((float)$exportMoney['receivable_amount']===1.0&&(float)$exportMoney['actual_received_amount']===1.0&&strpos($exportMoney['payment_method'],'微信')===0&&$exportMoney['store']===$tag,'export and list preserve the same original payment amounts and store: '.json_encode($exportMoney));
 $detail=(new app\services\cashier\v3\order\CashierV3SalesOrderQueryServices())->salesOrderDetail(['orderId'=>'legacy-sale-'.$ids[20]],$operator,$scope);checkOC4((float)$detail['amountSummary']['payableAmount']===1.0&&$detail['paymentDetails'][0]['methodName']==='微信','detail and list expose the same original payment');
 foreach($facts as$t=>$n)checkOC4(Db::name($t)->count()===$n,'read projection does not add facts to '.$t);
 echo json_encode(['result'=>'PASS','count'=>count($checks),'checks'=>$checks],JSON_UNESCAPED_UNICODE),PHP_EOL;
}catch(Throwable$e){$error=$e;fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);}finally{Db::rollback();restore_error_handler();}exit($error?1:0);

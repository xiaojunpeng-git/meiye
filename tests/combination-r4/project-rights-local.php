<?php
declare(strict_types=1);
/** 本地独立夹具、外层事务回滚；验证成团权益及复用读取，不连接支付或发送通知。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\activity\combination\CombinationFulfillmentServices;
use app\services\activity\combination\CombinationProjectEntitlementServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker only');
$checks=[];function check4(bool $ok,string $name):void {global$checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks[]=$name;}
function seed4(string $table,array $data):int {
 foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f)if(!array_key_exists($f['Field'],$data)&&$f['Default']===null&&$f['Null']!=='YES'&&strpos($f['Extra'],'auto_increment')===false)$data[$f['Field']]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';
 return(int)Db::name($table)->insertGetId($data);
}
function fixture4():array {
 $tag='R4P-'.bin2hex(random_bytes(5));$store=seed4('system_store',['name'=>$tag,'is_show'=>1,'is_del'=>0]);$uid=seed4('user',['nickname'=>$tag,'real_name'=>$tag,'phone'=>'19900000000','status'=>1,'is_del'=>0]);$pid=seed4('store_product',['store_name'=>$tag,'product_type'=>6,'is_show'=>1,'is_del'=>0,'price'=>1]);
 $oid=seed4('store_order',['uid'=>$uid,'store_id'=>$store,'order_id'=>$tag,'unique'=>$tag,'type'=>3,'product_type'=>6,'paid'=>1,'status'=>0,'refund_status'=>0,'terminal_action'=>0,'pay_price'=>1,'total_price'=>1,'cash_pay_price'=>1,'pay_type'=>'weixin','pay_time'=>time(),'add_time'=>time(),'total_num'=>1,'source'=>10]);
 $cart=['productInfo'=>['id'=>9898,'product_id'=>$pid,'store_name'=>'购买时项目名称','product_type'=>6,'attrInfo'=>['write_valid'=>1,'write_times'=>3]]];
 $detail=seed4('store_order_cart_info',['oid'=>$oid,'uid'=>$uid,'product_id'=>$pid,'product_type'=>6,'cart_type'=>0,'cart_id'=>$tag,'cart_num'=>1,'surplus_num'=>1,'split_surplus_num'=>1,'write_times'=>3,'write_surplus_times'=>3,'pay_price'=>1,'total_price'=>1,'cash_pay_amount'=>1,'cart_info'=>json_encode($cart),'unique'=>md5($tag),'is_support_refund'=>1]);
 seed4('combination_order_fulfillment',['order_id'=>$oid,'state'=>'pending','created_at'=>time(),'issued_at'=>0]);$pink=seed4('store_pink',['order_id_key'=>$oid,'order_id'=>$tag,'uid'=>$uid,'status'=>2,'is_refund'=>0]);return compact('uid','store','pid','oid','detail','pink');
}
Db::startTrans();try{
 $facts=[];foreach(['cashier_v3_sales_order','cashier_v3_payment_fact','cashier_v3_balance_fact','cashier_v3_outbox']as$t)$facts[$t]=Db::name($t)->count();
 $f=fixture4();$before=Db::name('store_order_cart_info')->where('id',$f['detail'])->find();
 (new CombinationFulfillmentServices())->issue($f['oid']);
 $holder=Db::name('user_card_holder')->where('oid',$f['oid'])->find();$detail=Db::name('store_order_cart_info')->where('oid',$f['oid'])->where('cart_type',2)->find();
 check4((bool)$holder&&(bool)$detail,'completed project creates holder and consumable detail');
 check4((int)$holder['store_id']===$f['store']&&(int)$holder['uid']===$f['uid'],'holder follows original buyer and order store');
 check4((int)$detail['product_id']===$f['pid']&&(int)$detail['write_surplus_times']===3,'base project identity and purchased count preserved');
 check4(bccomp((string)$detail['cash_pay_amount'],'0',2)===0&&bccomp((string)$detail['yue_pay_amount'],'0',2)===0,'entitlement detail is not a duplicate payment');
 check4(Db::name('store_order_cart_info')->where('id',$f['detail'])->find()===$before,'original paid primary line is immutable');
 check4(Db::name('combination_order_fulfillment')->where('order_id',$f['oid'])->value('state')==='issued','receipt follows successful issuance');
 $count=Db::name('user_card_holder')->where('oid',$f['oid'])->count();(new CombinationFulfillmentServices())->issue($f['oid']);(new CombinationProjectEntitlementServices())->issueInTx($f['oid'],time());
 check4(Db::name('user_card_holder')->where('oid',$f['oid'])->count()===$count&&Db::name('store_order_cart_info')->where('oid',$f['oid'])->where('cart_type',2)->count()===1,'replayed receipt and issuer do not duplicate rights');
 $op=new CashierV3OperatorScope($f['store'],1,'','0');$scope=new CashierV3DataScopeContext(1,0,$f['store'],'0','',[$f['store']],'stores',[],false,'','1',[],[]);
 $sources=app()->make(app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices::class)->reservationSources($f['uid'],$op,$scope);
 $found=array_values(array_filter($sources,function($s)use($holder){return(int)$s['id']===(int)$holder['id'];}));
 check4(count($found)===1&&!empty($found[0]['selectable']),'existing entitlement reader exposes usable project');
 check4($found[0]['projects'][0]['purchaseAmount']==='1.00','rights amount uses actual paid price rather than catalog price');
 Db::name('user_card_holder')->where('id',$holder['id'])->update(['write_surplus_times'=>2]);Db::name('store_order_cart_info')->where('id',$detail['id'])->update(['write_surplus_times'=>2]);
 (new CombinationProjectEntitlementServices())->issueInTx($f['oid'],time());check4((int)Db::name('store_order_cart_info')->where('id',$detail['id'])->value('write_surplus_times')===2,'replay never restores already consumed quota');
 $g=fixture4();Db::name('store_pink')->where('id',$g['pink'])->update(['status'=>1]);(new CombinationFulfillmentServices())->issue($g['oid']);check4(Db::name('user_card_holder')->where('oid',$g['oid'])->count()===0,'incomplete group grants no rights');
 $g=fixture4();Db::name('store_order')->where('id',$g['oid'])->update(['refund_status'=>1]);(new CombinationFulfillmentServices())->issue($g['oid']);check4(Db::name('user_card_holder')->where('oid',$g['oid'])->count()===0,'refund lock prevents issuance');
 $g=fixture4();Db::name('store_order_cart_info')->where('id',$g['detail'])->update(['pay_price'=>'0.50']);$failed=false;try{(new CombinationFulfillmentServices())->issue($g['oid']);}catch(Throwable$e){$failed=true;}check4($failed&&Db::name('combination_order_fulfillment')->where('order_id',$g['oid'])->value('state')==='pending'&&Db::name('user_card_holder')->where('oid',$g['oid'])->count()===0,'invalid amount rolls back issuance and receipt');
 $g=fixture4();try{Db::transaction(function()use($g){(new CombinationFulfillmentServices())->issue($g['oid']);throw new RuntimeException('late-fault');});}catch(RuntimeException$e){if($e->getMessage()!=='late-fault')throw$e;}
 check4(Db::name('user_card_holder')->where('oid',$g['oid'])->count()===0&&Db::name('store_order_cart_info')->where('oid',$g['oid'])->where('cart_type',2)->count()===0&&Db::name('combination_order_fulfillment')->where('order_id',$g['oid'])->value('state')==='pending','late transaction failure rolls back holder detail and issued receipt together');
 // 发放后完整退款的权威终态必须继续被共享 Reader 和迟到重放共同阻断。
 Db::name('store_order')->where('id',$f['oid'])->update(['refund_status'=>2]);
 Db::name('user_card_holder')->where('id',$holder['id'])->update(['is_del'=>1]);
 Db::name('store_order_cart_info')->where('id',$detail['id'])->update(['write_surplus_times'=>0,'is_writeoff'=>1]);
 $blocked=false;try{(new CombinationProjectEntitlementServices())->issueInTx($f['oid'],time());}catch(think\exception\ValidateException$e){$blocked=true;}
 $sources=app()->make(app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices::class)->reservationSources($f['uid'],$op,$scope);
 check4($blocked&&$sources===[]&&(int)Db::name('store_order_cart_info')->where('id',$detail['id'])->value('write_surplus_times')===0,'refunded rights remain invisible and cannot be resurrected');
 check4(Db::name('store_reservation_order')->where('oid',$f['oid'])->count()===0&&Db::name('store_order_writeoff')->where('oid',$f['oid'])->count()===0,'issuance does not create appointments or service writeoffs');
 foreach($facts as$t=>$n)check4(Db::name($t)->count()===$n,'issuance does not duplicate facts in '.$t);
 echo json_encode(['result'=>'PASS','count'=>count($checks),'checks'=>$checks],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}

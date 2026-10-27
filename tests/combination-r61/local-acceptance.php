<?php
declare(strict_types=1);
/** Local-only business acceptance: real MySQL services, fabricated members/orders, signed fake provider, rollback. */
require (getenv('BACKEND_ROOT') ?: '/var/www/html') . '/vendor/autoload.php';
use think\facade\Db;
use think\facade\Event;
use app\services\activity\combination\CombinationFulfillmentServices;
use app\services\activity\combination\CombinationRefundServices;
use app\services\activity\combination\CombinationGroupManagementServices;
use app\services\activity\combination\StorePinkServices;
use app\services\pay\FuyouCombinationRefundGateway;
$app = new think\App((getenv('BACKEND_ROOT') ?: '/var/www/html') . '/'); $app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao')
    throw new RuntimeException('Only local Docker ruihao is allowed');
// No worker can consume rollback fixture IDs. These stubs replace infrastructure, not domain logic.
$app->instance('queue', new class { public function __call($name,$args){return 1;} });
foreach (['notice.notice','order.create','order.applyRefund'] as $event) Event::remove($event);
$app->instance(app\services\order\terminal\RefundOutboxRelayGateServices::class,
    new class extends app\services\order\terminal\RefundOutboxRelayGateServices { public function assertReadyForTerminalRefund(): void {} });
class R61Gateway extends FuyouCombinationRefundGateway {
    public $requests=[]; public $delayMicros=0; public $status='SUCCESS'; public $badSign=false; public $timeout=false; public $wrongAmount=false;
    public static function local(): self { return new self(['mchntCd'=>'LOCAL_ONLY_R61','mchnt_key'=>'fabricated-local-key']); }
    protected function postJson(string $url,array $payload): array {
        $this->requests[]=['url'=>$url,'data'=>$payload];
        if($this->delayMicros)usleep($this->delayMicros);
        if ($this->timeout) throw new RuntimeException('local timeout');
        $query=strpos($url,'refundQuery')!==false;
        $response=['result_code'=>'000000','result_msg'=>'ok','trans_stat'=>$this->status,'ins_cd'=>'local',
            'mchnt_cd'=>'LOCAL_ONLY_R61','term_id'=>'88888888','random_str'=>'local-nonce','order_type'=>'WECHAT',
            'mchnt_order_no'=>$this->original,'refund_order_no'=>$payload['refund_order_no'],
            'transaction_id'=>'LOCAL_PAYMENT_61','refund_id'=>'LOCAL_REFUND_61','reserved_fy_settle_dt'=>date('Ymd'),
            'reserved_fy_trace_no'=>'local-trace','reserved_refund_amt'=>$this->wrongAmount?'101':'10000'];
        $fields=$query?['result_code','result_msg','trans_stat','ins_cd','mchnt_cd','term_id','random_str','order_type','mchnt_order_no','refund_order_no','transaction_id','refund_id','reserved_fy_settle_dt','reserved_fy_trace_no','reserved_refund_amt']
            :['result_code','mchnt_cd','mchnt_order_no','refund_order_no','reserved_fy_settle_dt','random_str'];
        $values=array_map(static function($key)use($response){return $response[$key]??'';},$fields);$values[]='fabricated-local-key';
        $response['sign']=$this->badSign?'forged':md5(implode('|',$values));return $response;
    }
    public $original='';
}
function check($ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function seed(string $table,array $data):int {
    // Supply only schema-required fields without borrowing any existing business row.
    foreach(Db::query('SHOW COLUMNS FROM eb_'.$table) as $field){
        $key=$field['Field'];if(array_key_exists($key,$data)||$field['Default']!==null||$field['Null']==='YES'||strpos($field['Extra'],'auto_increment')!==false)continue;
        $data[$key]=preg_match('/int|decimal|float|double/',$field['Type'])?0:'';
    }
    return (int)Db::name($table)->insertGetId($data);
}
function fixture(int $type,int $people=2,string $payment='weixin'):array {
    $tag='R61-'.bin2hex(random_bytes(5));$sku=substr(hash('sha256',$tag),0,8);
    $store=seed('system_store',['name'=>$tag,'is_show'=>1,'is_del'=>0]);
    $uid=seed('user',['nickname'=>$tag,'real_name'=>$tag,'phone'=>'19900000000','now_money'=>0,'ben_money'=>0,'give_money'=>0]);
    $pid=seed('store_product',['store_name'=>$tag,'product_type'=>$type,'price'=>100,'stock'=>10,'sales'=>1,'is_show'=>1,'is_verify'=>1,'delivery_type'=>'2','is_del'=>0]);
    $cid=seed('store_combination',['title'=>$tag,'product_id'=>$pid,'product_type'=>$type,'stock'=>10,'quota'=>10,'quota_show'=>11,'sales'=>1,'people'=>$people,'effective_time'=>1,'virtual'=>100,'is_show'=>1]);
    $oid=seed('store_order',['uid'=>$uid,'store_id'=>$store,'order_id'=>$tag,'unique'=>$tag,'type'=>3,'product_type'=>$type,'source'=>8,'activity_id'=>$cid,'total_num'=>1,
        'total_price'=>100,'pay_price'=>100,'paid'=>1,'pay_type'=>$payment,'pay_time'=>time(),'add_time'=>time(),
        'shipping_type'=>2,'verify_code'=>substr(bin2hex(random_bytes(8)),0,12),'status'=>0,'refund_status'=>0,
        'yue_pay_price'=>$payment==='yue'?100:0,'cash_pay_price'=>$payment==='yue'?0:100,
        'paid_ben_amount'=>$payment==='yue'?100:0,'paid_give_amount'=>0,'paid_balance_ready'=>1,'trade_no'=>'LOCAL_PAYMENT_61']);
    $cart=['id'=>$pid,'cart_num'=>1,'product_id'=>$pid,'product_type'=>$type,'activity_id'=>$cid,'truePrice'=>100,
        'productInfo'=>['id'=>$cid,'product_id'=>$pid,'store_name'=>$tag,'product_type'=>$type,'price'=>100,'image'=>'',
            'attrInfo'=>['unique'=>$sku,'write_valid'=>1,'write_times'=>1,'price'=>100,'suk'=>'默认']]];
    seed('store_order_cart_info',['uid'=>$uid,'oid'=>$oid,'cart_id'=>$pid,'product_id'=>$pid,'product_type'=>$type,'cart_type'=>0,
        'cart_num'=>1,'surplus_num'=>1,'split_surplus_num'=>1,'write_times'=>1,'write_surplus_times'=>1,'pay_price'=>100,
        'sku_unique'=>$sku,'cart_info'=>json_encode($cart),'unique'=>$tag,'is_support_refund'=>1]);
    if($type===5)seed('store_order_cart_info',['uid'=>$uid,'oid'=>$oid,'cart_id'=>$pid+1000000,'product_id'=>$pid,'product_type'=>6,'cart_type'=>2,
        'cart_num'=>1,'surplus_num'=>1,'split_surplus_num'=>1,'write_times'=>1,'write_surplus_times'=>1,'pay_price'=>100,'sku_unique'=>$sku,
        'cart_info'=>json_encode($cart),'unique'=>$tag.'C']);
    seed('store_product_attr_value',['product_id'=>$pid,'type'=>0,'unique'=>$sku,'stock'=>10,'quota'=>10,'quota_show'=>11,'sales'=>1,'price'=>100]);
    seed('store_product_attr_value',['product_id'=>$cid,'type'=>3,'unique'=>$sku,'stock'=>10,'quota'=>10,'quota_show'=>11,'sales'=>1,'price'=>100]);
    (new CombinationFulfillmentServices())->register($oid);
    $pinkId=seed('store_pink',['uid'=>$uid,'nickname'=>$tag,'order_id'=>$tag,'order_id_key'=>$oid,'total_num'=>1,'total_price'=>100,
        'cid'=>$cid,'pid'=>$pid,'people'=>$people,'price'=>100,'add_time'=>time(),'stop_time'=>time()+3600,'status'=>1]);
    Db::name('store_order')->where('id',$oid)->update(['pink_id'=>$pinkId]);
    Db::name('combination_group_origin')->insert(['group_id'=>$pinkId,'expires_at'=>time()+3600,'activity_name'=>$tag]);
    $channel='13883'.substr(hash('sha256',$tag),0,15);
    if($payment!=='yue'){
        seed('fuyou_payment_attempt',['channel_order_no'=>$channel,'business_order_no'=>$tag,'business_type'=>'product','channel'=>'wechat','amount_cents'=>10000,
            'status'=>'paid','transaction_id'=>'LOCAL_PAYMENT_61','add_time'=>time(),'update_time'=>time()]);
        Db::name('combination_payment_origin')->insert(['channel_order_no'=>$channel,'merchant_id'=>'LOCAL_ONLY_R61','settled_on'=>date('Ymd')]);
    }
    return compact('uid','pid','cid','oid','pinkId','cart','channel');
}
/** Each participant buys the same activity SKU; never fabricate unrelated SKU identities when joining. */
function joinFixture(array $root, array &$member): void {
    Db::name('store_pink')->where('id',$member['pinkId'])->update(['k_id'=>$root['pinkId'],'cid'=>$root['cid'],'pid'=>$root['pid']]);
    Db::name('store_order')->where('id',$member['oid'])->update(['pink_id'=>$root['pinkId'],'activity_id'=>$root['cid']]);
    $member['cart']=$root['cart'];
    Db::name('store_order_cart_info')->where('oid',$member['oid'])->where('cart_type',0)->update([
        'product_id'=>$root['pid'],'sku_unique'=>$root['cart']['productInfo']['attrInfo']['unique'],
        'cart_info'=>json_encode($member['cart'])]);
}
if (getenv('R61_MODE') === 'seed-race') {
    Db::startTrans();try{$refundRace=fixture(6);$completeRace=fixture(5,3,'yue');Db::commit();echo json_encode(compact('refundRace','completeRace'));}
    catch(\Throwable $e){Db::rollback();throw $e;}exit;
}
if (getenv('R61_MODE') === 'worker-refund') {
    $id=(int)getenv('R61_ORDER');$order=Db::name('store_order')->where('id',$id)->find();
    if(!$order||strpos($order['order_id'],'R61-')!==0)throw new RuntimeException('Fabricated fixtures only');
    $gateway=R61Gateway::local();$gateway->original=(string)Db::name('fuyou_payment_attempt')->where('business_order_no',$order['order_id'])->value('channel_order_no');$gateway->delayMicros=600000;
    $result=(new CombinationRefundServices($gateway))->refundOrder($id,'R61本地并发退款验收',1);
    echo json_encode(['result'=>$result,'providerRequests'=>count($gateway->requests)]),"\n";exit;
}
if (getenv('R61_MODE') === 'worker-complete') {
    $id=(int)getenv('R61_GROUP');$root=Db::name('store_pink')->where('id',$id)->find();
    if(!$root||strpos($root['order_id'],'R61-')!==0)throw new RuntimeException('Fabricated fixtures only');
    echo json_encode((new CombinationGroupManagementServices())->complete($id,1)),"\n";exit;
}
if (getenv('R61_MODE') === 'stock') {
    Db::startTrans();
    try {
        $f=fixture(0);(new CombinationGroupManagementServices())->complete($f['pinkId'],1);
        $g=R61Gateway::local();$g->original=$f['channel'];
        $r=(new CombinationRefundServices($g))->refundOrder($f['oid'],'本地库存回退诊断',1);
        echo json_encode($r,JSON_UNESCAPED_UNICODE),"\n";
        echo json_encode(Db::name('store_order_refund')->where('store_order_id',$f['oid'])->value('cart_info'),JSON_UNESCAPED_UNICODE),"\n";
        echo json_encode(Db::name('store_product_attr_value')->where('product_id',$f['cid'])->where('type',3)->select()->toArray(),JSON_UNESCAPED_UNICODE),"\n";
    } finally {Db::rollback();}
    exit;
}
if (getenv('R61_MODE') === 'balance') {
    Db::startTrans();
    try {
        $f=fixture(6,2,'yue');
        $r=(new CombinationRefundServices())->refundOrder($f['oid'],'本地余额退款诊断',1);
        if($r['state']!=='success') {
            try {app()->make(app\services\order\StoreOrderRefundDomainServices::class)->refundConfirmedCombination($f['oid'],['operator_id'=>1,'operator_type'=>'admin','request_token'=>'group:'.$f['oid']]);}
            catch(\Throwable $e){echo get_class($e).' '.$e->getMessage()."\n".$e->getTraceAsString()."\n";}
        }
        echo json_encode($r,JSON_UNESCAPED_UNICODE),"\n";
    } finally {Db::rollback();}
    exit;
}
if (getenv('R61_MODE') === 'seed-ui') {
    Db::startTrans();
    try {
        $leader=fixture(6,3,'yue');$member=fixture(6,3,'yue');
        joinFixture($leader,$member);
        $card=fixture(5,2,'yue');$product=fixture(0,2,'yue');
        $refunded=fixture(6,2);(new CombinationGroupManagementServices())->complete($refunded['pinkId'],1);
        $fake=R61Gateway::local();$fake->original=$refunded['channel'];
        $refund=(new CombinationRefundServices($fake))->refundOrder($refunded['oid'],'R61本地模拟富友退款展示验收',1);
        if($refund['state']!=='success')throw new RuntimeException('UI refund fixture did not close');
        $manifest=['localOnly'=>true,'leader'=>$leader,'member'=>$member,'card'=>$card,'product'=>$product,'refunded'=>$refunded];
        Db::commit();echo json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
    } catch (\Throwable $error) {Db::rollback();throw $error;}
    exit;
}
Db::startTrans();
try {
    $fulfill=new CombinationFulfillmentServices();$groups=new CombinationGroupManagementServices();
    // Group orders stay hidden from consumption lists before completion. The visibility flag must
    // not suppress a safe per-member refund, while an ordinary deleted order remains blocked.
    $hidden=fixture(6);Db::name('store_order')->where('id',$hidden['oid'])->update(['is_del'=>1]);
    $gateway=R61Gateway::local();$gateway->original=$hidden['channel'];$service=new CombinationRefundServices($gateway);
    $description=$service->describe($hidden['oid']);
    check($description['refund_action']==='refund','managed hidden group participant exposes per-user refund action');
    $refund=$service->refundOrder($hidden['oid'],'本地隐藏拼团单退款验收',1);
    if($refund['state']!=='success')echo 'HIDDEN REFUND DEBUG '.json_encode($refund,JSON_UNESCAPED_UNICODE)
        .' terminal='.json_encode(Db::name('store_order_terminal_operation')->where('store_order_id',$hidden['oid'])->find(),JSON_UNESCAPED_UNICODE)."\n";
    check($refund['state']==='success','managed hidden group participant refunds through original channel');
    $deleted=fixture(6);Db::name('store_order')->where('id',$deleted['oid'])->update(['is_del'=>1]);
    Db::name('combination_order_fulfillment')->where('order_id',$deleted['oid'])->delete();
    $gateway=R61Gateway::local();$gateway->original=$deleted['channel'];
    check((new CombinationRefundServices($gateway))->describe($deleted['oid'])['refund_action']==='',
        'ordinary deleted order cannot borrow hidden-group refund eligibility');
    foreach([0,5,6] as $type){
        $f=fixture($type,3);$blocked=false;try{$fulfill->assertUsable($f['oid'],true);}catch(think\exception\ValidateException $e){$blocked=true;}
        check($blocked,'type '.$type.' cannot use paid rights before group completion');
        (new app\jobs\order\OrderPayHandelJob())->doJob((array)Db::name('store_order')->where('id',$f['oid'])->find());
        check(!Db::name('user_card_holder')->where('oid',$f['oid'])->count(),'payment job does not issue early');
        $result=$groups->complete($f['pinkId'],1);check($result['virtual_count']===2,'type '.$type.' fills exactly missing virtual seats');
        $fulfill->assertUsable($f['oid'],true);
        $groups->complete($f['pinkId'],1);
        check((int)Db::name('store_pink')->where('k_id',$f['pinkId'])->where('is_virtual',1)->count()===2,'completion replay adds no fake members');
        check((int)Db::name('combination_order_fulfillment')->where('order_id',$f['oid'])->where('state','issued')->count()===1,'rights receipt issued once');
        if($type===5)check((int)Db::name('user_card_holder')->where('oid',$f['oid'])->where('is_del',0)->count()===1,'one usable card holder after completion');
        check(!Db::name('store_reservation_order')->where('oid',$f['oid'])->count()&&!Db::name('store_order_writeoff')->where('oid',$f['oid'])->count(),'group purchase creates no appointment or writeoff');
        check((int)Db::name('store_order_cart_info')->where('oid',$f['oid'])->min('write_surplus_times')===1,'all purchased uses remain unconsumed');
        $gateway=R61Gateway::local();$gateway->original=$f['channel'];$refund=new CombinationRefundServices($gateway);
        $r=$refund->refundOrder($f['oid'],'本地整单退款验收',1);
        if($r['state']!=='success')echo 'DEBUG '.json_encode($r).' terminal='.json_encode(Db::name('store_order_terminal_operation')->where('store_order_id',$f['oid'])->find(),JSON_UNESCAPED_UNICODE)."\n";
        check($r['state']==='success','type '.$type.' signed channel success closes real terminal refund');
        check((int)Db::name('store_combination')->where('id',$f['cid'])->value('stock')===11,'type '.$type.' refunds its original activity quota');
        (new app\jobs\order\OrderPayHandelJob())->doJob((array)Db::name('store_order')->where('id',$f['oid'])->find());
        check(!(int)Db::name('user_card_holder')->where('oid',$f['oid'])->where('is_del',0)->count(),'late payment job cannot resurrect refunded rights');
        check($r['channel_refund_id']==='LOCAL_REFUND_61'&&$r['fuyou_order_no']===$f['channel']&&$r['fuyou_transaction_id']==='LOCAL_PAYMENT_61','original FuYou order/payment/refund references persisted');
        check((int)Db::name('store_order_cart_info')->where('oid',$f['oid'])->sum('write_surplus_times')===0,'refund revokes all unused rights');
        $before=count($gateway->requests);$refund->refundOrder($f['oid'],'重放',1);check(count($gateway->requests)===$before,'refund replay has no channel request');
        check((int)Db::name('store_pink')->where('id',$f['pinkId'])->value('status')===2,'refund leaves completed group completed');
    }
    foreach(['USERPAYING','PAYERROR','forged','timeout','wrongAmount'] as $scenario){
        $f=fixture(6);$gateway=R61Gateway::local();$gateway->original=$f['channel'];
        if($scenario==='forged')$gateway->badSign=true;elseif($scenario==='timeout')$gateway->timeout=true;elseif($scenario==='wrongAmount')$gateway->wrongAmount=true;else $gateway->status=$scenario;
        $service=new CombinationRefundServices($gateway);$r=$service->refundOrder($f['oid'],'异常验收',1);
        check($r['state']===($scenario==='PAYERROR'?'failed':'pending'),'channel '.$scenario.' is not false success');
        check((int)Db::name('store_order')->where('id',$f['oid'])->value('refund_status')===($scenario==='PAYERROR'?0:1),'only signed definitive failure releases rights');
        if($scenario!=='PAYERROR'){
            $before=count(array_filter($gateway->requests,static function($r){return strpos($r['url'],'commonRefund')!==false;}));
            $service->refundOrder($f['oid'],'重复查询',1,false);
            check(count(array_filter($gateway->requests,static function($r){return strpos($r['url'],'commonRefund')!==false;}))===$before,'pending/unknown retry queries only');
        }
    }
    $f=fixture(6);$gateway=R61Gateway::local();$gateway->original=$f['channel'];$service=new CombinationRefundServices($gateway);
    $blocked=false;try{$service->refundOrder($f['oid'],'踢团长',1,true,$f['pinkId']);}catch(think\exception\ValidateException $e){$blocked=true;}
    check($blocked&&count($gateway->requests)===0,'leader cannot be kicked server-side');
    Db::name('store_order_cart_info')->where('oid',$f['oid'])->update(['write_surplus_times'=>0]);
    $blocked=false;try{$service->refundOrder($f['oid'],'使用后退款',1);}catch(think\exception\ValidateException $e){$blocked=true;}
    check($blocked&&count($gateway->requests)===0,'used rights rejected before channel');
    $partial=fixture(6);$gateway=R61Gateway::local();$gateway->original=$partial['channel'];
    $domain=app()->make(app\services\order\StoreOrderRefundDomainServices::class);
    $app->instance(app\services\order\StoreOrderRefundDomainServices::class,new class extends app\services\order\StoreOrderRefundDomainServices {
        public function refundConfirmedCombination(int $orderId,array $input):array{$input['inject_fail_after']='inventory';return parent::refundConfirmedCombination($orderId,$input);}
    });
    $service=new CombinationRefundServices($gateway);$r=$service->refundOrder($partial['oid'],'本地渠道成功后收口故障验收',1);
    check($r['state']==='confirmed','local failure retains signed channel confirmation');
    $before=count($gateway->requests);$app->instance(app\services\order\StoreOrderRefundDomainServices::class,$domain);
    $r=$service->refundOrder($partial['oid'],'恢复本地收口',1,false);
    check($r['state']==='success'&&count($gateway->requests)===$before,'local closing recovery never reapplies or requeries confirmed channel');
    // Genuine payment callbacks create one root and naturally complete with a second real member.
    $natural=fixture(6,2,'yue');
    Db::name('store_pink')->where('id',$natural['pinkId'])->delete();
    Db::name('combination_group_origin')->where('group_id',$natural['pinkId'])->delete();
    Db::name('store_order')->where('id',$natural['oid'])->update(['pink_id'=>0]);
    $pinkService=app()->make(StorePinkServices::class);
    $paid=(array)Db::name('store_order')->where('id',$natural['oid'])->find();$paid['cartInfo']=[$natural['cart']];
    check($pinkService->createPink($paid)===true,'paid callback creates real group');
    $rootId=(int)Db::name('store_pink')->where('order_id_key',$natural['oid'])->value('id');
    $pinkService->createPink($paid);
    check((int)Db::name('store_pink')->where('order_id_key',$natural['oid'])->count()===1,'payment callback replay creates no duplicate participant');
    $join=fixture(6,2,'yue');Db::name('store_pink')->where('id',$join['pinkId'])->delete();
    Db::name('combination_group_origin')->where('group_id',$join['pinkId'])->delete();
    $natural['pinkId']=$rootId;joinFixture($natural,$join);
    $paid=(array)Db::name('store_order')->where('id',$join['oid'])->find();
    $join['cart']['activity_id']=$natural['cid'];$paid['cartInfo']=[$join['cart']];
    $pinkService->createPink($paid);
    check((int)Db::name('store_pink')->where('id',$rootId)->value('status')===2,'two paid real members naturally complete the group');
    check((int)Db::name('combination_order_fulfillment')->whereIn('order_id',[$natural['oid'],$join['oid']])->where('state','issued')->count()===2,'natural completion issues both real members exactly once');
    // Two real participants: kick waits for signed refund success, then only that participant leaves.
    $leader=fixture(6,3);$member=fixture(6,3);
    joinFixture($leader,$member);
    $gateway=R61Gateway::local();$gateway->original=$member['channel'];$gateway->status='USERPAYING';
    $service=new CombinationRefundServices($gateway);
    $kick=$service->refundOrder($member['oid'],'本地踢人验收',1,true,$leader['pinkId']);
    check($kick['state']==='pending'&&!(int)Db::name('store_pink')->where('id',$member['pinkId'])->value('is_refund'),'kick pending keeps participant trace until money succeeds');
    $blocked=false;try{$groups->complete($leader['pinkId'],1);}catch(think\exception\ValidateException $e){$blocked=true;}
    check($blocked,'force completion cannot race an in-flight kick refund');
    $gateway->status='SUCCESS';$kick=$service->refundOrder($member['oid'],'查询踢人退款',1,false);
    check($kick['state']==='success'&&(int)Db::name('store_pink')->where('id',$member['pinkId'])->value('is_refund')>0,'successful kick refund removes only its participant');
    check((int)Db::name('store_pink')->where('id',$leader['pinkId'])->value('status')===1,'kick does not fail the whole group');
    $completion=$groups->complete($leader['pinkId'],1);check($completion['virtual_count']===2,'kicked seat is correctly replaced on force completion');
    $export=(new app\services\activity\combination\CombinationRecordServices())->export($leader['pinkId']);
    check(count($export['header'])===16&&$export['header']===['门店名称','会员编号','会员姓名','手机号码','拼团编号','拼团名称','支付金额','拼团总数','参加人数','状态','是否团长','支付时间','过期时间','订单号','序号','支付状态'],'export matches all 16 template columns in order');
    check(count($export['export'])===2,'export includes refunded participant and excludes virtual placeholders');
    check($export['export'][1]['payment_status']==='已退款'&&$export['export'][0]['expires_at']!=='','export preserves refund state and original expiry after completion');
    $balance=fixture(6,2,'yue');$moneyBefore=(string)Db::name('user')->where('uid',$balance['uid'])->value('now_money');
    $balanceRefund=(new CombinationRefundServices())->refundOrder($balance['oid'],'本地余额原路退款',1);
    if($balanceRefund['state']!=='success')echo 'BALANCE DEBUG '.json_encode($balanceRefund).' terminal='.json_encode(Db::name('store_order_terminal_operation')->where('store_order_id',$balance['oid'])->find(),JSON_UNESCAPED_UNICODE)."\n";
    check($balanceRefund['state']==='success'&&bcsub((string)Db::name('user')->where('uid',$balance['uid'])->value('now_money'),$moneyBefore,2)==='100.00','balance original payment credits once through real terminal ledger');
    $physical=fixture(0);$groups->complete($physical['pinkId'],1);$fulfill->claimDelivery($physical['oid']);
    $blocked=false;try{(new CombinationRefundServices(R61Gateway::local()))->refundOrder($physical['oid'],'发货并发退款',1);}catch(think\exception\ValidateException $e){$blocked=true;}
    check($blocked,'claimed delivery excludes a simultaneous refund before carrier result');
    echo "R61 ACCEPTANCE COMPLETE; all fabricated rows rolled back; no real provider requests.\n";
} finally { Db::rollback(); }

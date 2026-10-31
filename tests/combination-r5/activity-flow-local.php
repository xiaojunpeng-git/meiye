<?php
declare(strict_types=1);
/** 独立本地MySQL事务夹具；所有记录回滚，未调用真实支付/退款或通知。 */
putenv('R61_MODE=library');
require dirname(__DIR__).'/combination-r61/local-acceptance.php';
// 框架默认异常输出可能返回退出码0；验收脚本必须让失败变成非零退出。
set_exception_handler(static function($e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);});
use think\facade\Db;
use app\services\activity\combination\CombinationActivityFlowServices;
use app\services\organization\EmployeeDataScopeServices;

$reader=new CombinationActivityFlowServices();$admin=['id'=>1,'level'=>0,'admin_type'=>1];
Db::startTrans();try {
    $first=fixture(6,2,'yue');$activity=$first['cid'];$orderIds=[$first['oid']];
    for($i=0;$i<24;$i++) {
        $f=fixture(6,2,'yue');Db::name('store_order')->where('id',$f['oid'])->update(['activity_id'=>$activity]);
        Db::name('store_pink')->where('id',$f['pinkId'])->update(['cid'=>$activity]);$orderIds[]=$f['oid'];
    }
    $p=$reader->page($activity,[],$admin);check($p['count']===25&&count($p['list'])===20&&$p['limit']===20,'one original payment per row; first page20 of25');
    if(getenv('R5_FLOW_EVIDENCE'))file_put_contents(getenv('R5_FLOW_EVIDENCE'),json_encode(['page'=>$p,'options'=>$reader->options($admin)],JSON_UNESCAPED_UNICODE));
    check($p['summary']['paid_amount']==='2500.00','summary includes all matches before pagination');
    $second=$reader->page($activity,['page'=>2],$admin);check(count($second['list'])===5&&$second['summary']===$p['summary'],'page2 retains full summary');
    check(!array_intersect(array_column($p['list'],'order_id_key'),array_column($second['list'],'order_id_key')),'stable order identity does not overlap pages');
    $end=$reader->page($activity,['page'=>999],$admin);check($end['page']===2&&count($end['list'])===5,'out of range page clamps to current last page');
    $export=$reader->page($activity,[],$admin,true);check(count($export['export'])===26&&$export['export'][0]['member_name']==='合计金额'&&$export['export'][0]['paid_amount']==='2500.00','export starts with same full-result summary');
    check(count($export['header'])===13&&!in_array('第三方商户号',$export['header'],true)&&in_array('退款追踪号',$export['header'],true),'final requested columns and neutral trace label');
    $filtered=['filters'=>json_encode([['fieldKey'=>'member_name','operator'=>'equal','value'=>Db::name('user')->where('uid',$first['uid'])->value('nickname')]])];
    $one=$reader->page($activity,$filtered,$admin);check($one['count']===1&&$one['summary']['paid_amount']==='100.00','member filter limits rows count and full summary');
    check($reader->page($activity,$filtered,$admin,true)['summary']===$one['summary'],'filtered export shares exact aggregate');
    $none=$reader->page($activity,['filters'=>[['fieldKey'=>'phone','operator'=>'equal','value'=>'NONE']]],$admin);check($none['count']===0&&$none['summary']['paid_amount']==='0.00'&&$none['page']===1,'empty result has explicit zero summary and page1');
    $store=(int)Db::name('store_order')->where('id',$first['oid'])->value('store_id');
    check($reader->page($activity,['store_ids'=>[$store]],$admin)['summary']['paid_amount']==='100.00','own purchase store filtering also narrows totals');
    $original=app()->make(EmployeeDataScopeServices::class);$restricted=new class extends EmployeeDataScopeServices {public $stores=[];public function resolveEffectiveStoreIds(int $employeeId,int $contextStoreId=0,array $adminInfo=[]){return$this->stores;}};
    $restricted->stores=[$store];app()->instance(EmployeeDataScopeServices::class,$restricted);
    try {
        $narrow=$reader->page($activity,[],$admin);check($narrow['count']===1&&$narrow['summary']['paid_amount']==='100.00','injected server store scope precedes count sum and rows');
        check($reader->page($activity,['store_ids'=>[$store+99999]],$admin)['count']===0,'requested store cannot enlarge server scope');
        $restricted->stores=[];Db::name('store_order')->where('id',$first['oid'])->update(['store_id'=>0]);
        check($reader->page($activity,[],$admin,true)['summary']['paid_amount']==='0.00','deny-all scope cannot leak legacy store-zero rows or money');
        Db::name('store_order')->where('id',$first['oid'])->update(['store_id'=>$store]);
    } finally {app()->instance(EmployeeDataScopeServices::class,$original);}
    $blocked=false;try{$reader->page($activity,[],['id'=>2,'level'=>1,'admin_type'=>1]);}catch(Throwable$e){$blocked=true;}check($blocked,'ordinary account without combination grant cannot read new route');
    Db::name('store_order')->where('id',$first['oid'])->update(['refund_status'=>2]);
    $refund=$reader->page($activity,['filters'=>[['fieldKey'=>'payment_status','operator'=>'equal','value'=>'已退款']]],$admin);
    check($refund['count']===1&&$refund['summary']['paid_amount']==='100.00'&&$refund['list'][0]['refund_action']==='','refunded original payment remains traceable without new refund action');
    Db::name('store_order')->where('id',$first['oid'])->update(['refund_status'=>0]);
    Db::name('store_pink')->where('id',$first['pinkId'])->update(['status'=>2]);
    $done=$reader->page($activity,$filtered,$admin)['list'][0];check($done['refund_action']===''&&strpos($done['refund_block_reason'],'整团')!==false,'completed group cannot expose individual refund bypass');
    $blocked=false;try{$reader->assertRefundOrder($activity,$first['oid'],$admin);}catch(Throwable$e){$blocked=true;}check($blocked,'command guard independently rejects completed individual refund');
    $other=fixture(6,2,'yue');$blocked=false;try{$reader->assertRefundOrder($activity,$other['oid'],$admin);}catch(Throwable$e){$blocked=true;}check($blocked,'forged activity/order pairing cannot reach financial command');
    Db::name('store_pink')->where('id',$first['pinkId'])->update(['status'=>1,'stop_time'=>time()+3600]);
    check($reader->assertRefundOrder($activity,$first['oid'],$admin)===$first['pinkId'],'valid own activity resolves original group for existing refund command');
    foreach([
        ['filters'=>[['fieldKey'=>'password','operator'=>'equal','value'=>'x']]],
        ['filters'=>[['fieldKey'=>'member_name','operator'=>'SQL','value'=>'1=1']]],
        ['filters'=>'not-json'],['store_ids'=>'1 OR 1=1'],['date_from'=>'2026-02-30'],
        ['date_from'=>'2026-11-02','date_to'=>'2026-11-01'],
        ['filters'=>[['fieldKey'=>'paid_amount','operator'=>'equal','value'=>'1 OR 1=1']]],
    ]as$bad){$blocked=false;try{$reader->page($activity,$bad,$admin);}catch(Throwable$e){$blocked=true;}check($blocked,'invalid filter input fails closed: '.json_encode($bad));}
    check($reader->page($activity,['date_from'=>'2000-01-01','date_to'=>'2000-01-02'],$admin)['count']===0,'payment date executes on original pay time');
    $midnight=strtotime(date('Y-m-d').' 00:05:00');Db::name('store_order')->where('id',$first['oid'])->update(['pay_time'=>$midnight]);
    $localTime=$reader->page($activity,$filtered+['date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d')],$admin);
    check($localTime['count']===1&&$localTime['list'][0]['paid_at']===date('Y-m-d H:i:s',$midnight),'UTC database cannot shift Shanghai midnight payment into previous date');
    $exact=$reader->page($activity,['filters'=>[['fieldKey'=>'paid_at','operator'=>'between','value'=>[date('Y-m-d').' 00:00:00',date('Y-m-d').' 00:10:00']]]],$admin);
    check($exact['count']===1&&$exact['summary']['paid_amount']==='100.00','datetime field interval uses same business timezone as display');
    check($reader->page($activity,['filters'=>[['fieldKey'=>'paid_amount','operator'=>'between','value'=>['99','101']]]],$admin)['count']===25,'numeric interval filters real full amounts');
    check($reader->page($activity,['filters'=>[['fieldKey'=>'member_name','operator'=>'contains','value'=>'%']]],$admin)['count']===0,'literal wildcard cannot match all members');
    // 成功尝试必须同时匹配原订单号、金额和交易号，重复与失配不会复制订单金额。
    $order=Db::name('store_order')->where('id',$first['oid'])->find();Db::name('store_order')->where('id',$first['oid'])->update(['pay_type'=>'weixin']);
    $a=['order_id_key'=>$first['oid'],'business_order_no'=>$order['order_id'],'channel_order_no'=>'LOCAL-R5-SX-'.bin2hex(random_bytes(6)),'merchant_id'=>'LOCAL-R5','amount_cents'=>10000,'status'=>'paid','transaction_id'=>$order['trade_no']];
    seed('suixingpay_product_payment_attempt',$a);$paid=$reader->page($activity,$filtered,$admin)['list'][0];check($paid['provider_name']==='随行付'&&$paid['channel_order_no']===$a['channel_order_no'],'unique successful original attempt provides channel identity');
    $a['channel_order_no'].='-duplicate';seed('suixingpay_product_payment_attempt',$a);$ambiguous=$reader->page($activity,$filtered,$admin);
    check($ambiguous['count']===1&&$ambiguous['summary']['paid_amount']==='100.00'&&$ambiguous['list'][0]['provider_name']==='待核实'&&$ambiguous['list'][0]['channel_order_no']==='','ambiguous success does not multiply rows or invent channel identity');
    $a['amount_cents']=1;$a['channel_order_no']='WRONG-'.bin2hex(random_bytes(8));seed('suixingpay_product_payment_attempt',$a);check($reader->page($activity,[],$admin)['summary']['paid_amount']==='2500.00','mismatched success cannot affect aggregate');
    // 虚拟名额没有付款原单，不能因成团补位增加流水或合计。
    seed('store_pink',['uid'=>0,'k_id'=>$first['pinkId'],'cid'=>$activity,'is_virtual'=>1,'order_id_key'=>0]);
    check($reader->page($activity,[],$admin)['count']===25,'virtual seat is excluded from original payment grain');
    $before=Db::name('combination_order_refund')->count();$reader->page($activity,[],$admin);check(Db::name('combination_order_refund')->count()===$before,'list never freezes orders or creates refund receipts');
    $m=new ReflectionMethod($reader,'plan');$m->setAccessible(true);$plan=$m->invoke($reader,[]);$qm=new ReflectionMethod($reader,'query');$qm->setAccessible(true);$q=$qm->invoke($reader,$activity,[],null,$plan);
    $sql=$q->field('order_id_key')->order('order_id_key desc')->limit(20)->fetchSql()->select();
    check((bool)Db::query('EXPLAIN '.$sql),'authoritative scoped MySQL query has an executable plan');
    echo "ACTIVITY_FLOW_LOCAL_PASS\n";
}finally{Db::rollback();}

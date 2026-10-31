<?php
declare(strict_types=1);
/** 只验证新入口的团锁保护及原退款领域复用；事务回滚，余额夹具不连接第三方。 */
putenv('R61_MODE=library');require dirname(__DIR__).'/combination-r61/local-acceptance.php';
set_exception_handler(static function($e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);});
use think\facade\Db;
use app\services\activity\combination\CombinationRefundServices;
Db::startTrans();try {
    $gateway=R61Gateway::local();$service=new CombinationRefundServices($gateway);
    $f=fixture(6,2,'yue');
    // 模拟列表/权限检查时尚未成团，但进入退款预留前已被另一条合法流程成团。
    Db::name('store_pink')->where('id',$f['pinkId'])->update(['status'=>2]);
    $failed=false;try{$service->refundOrder($f['oid'],'本地并发成团拦截',1,false,0,true);}catch(think\exception\ValidateException$e){$failed=strpos($e->getMessage(),'整团退款')!==false;}
    check($failed,'locked claim rejects a group completed after list snapshot');
    check(Db::name('combination_order_refund')->where('order_id',$f['oid'])->count()===0&&!$gateway->requests,'rejection creates no receipt and sends no channel request');
    check((int)Db::name('store_order')->where('id',$f['oid'])->value('refund_status')===0,'rejection does not freeze or refund original order');
    Db::name('store_pink')->where('id',$f['pinkId'])->update(['status'=>1]);
    $before=(string)Db::name('user')->where('uid',$f['uid'])->value('now_money');
    $result=$service->refundOrder($f['oid'],'本地活动流水余额退款',1,false,0,true);
    check($result['state']==='success'&&(int)Db::name('store_order')->where('id',$f['oid'])->value('refund_status')===2,'pending activity order uses original successful refund domain');
    $balance=(string)Db::name('user')->where('uid',$f['uid'])->value('now_money');
    check(bccomp(bcsub($balance,$before,2),'100.00',2)===0,'original full paid amount returns once to balance');
    $replay=$service->refundOrder($f['oid'],'本地重放',1,false,0,true);
    check($replay['state']==='success'&&bccomp((string)Db::name('user')->where('uid',$f['uid'])->value('now_money'),$balance,2)===0,'replayed activity refund never credits a second amount');
    check(!$gateway->requests,'balance refund never calls external payment gateway');
    $whole=fixture(6,2,'yue');
    (new app\services\activity\combination\CombinationGroupManagementServices())->complete($whole['pinkId'],1);
    $group=$service->refundGroup($whole['pinkId'],'本地原整团退款回归',['id'=>1,'level'=>0,'admin_type'=>1]);
    check($group['state']==='success'&&$group['success_count']===1,'existing whole-group path keeps original behavior and excludes virtual seats');
    echo "ACTIVITY_FLOW_REFUND_LOCAL_PASS\n";
}finally{Db::rollback();}

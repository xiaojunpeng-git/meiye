<?php
declare(strict_types=1);
/** Real local MySQL/domain tests; all fixtures roll back and all provider traffic stays in the fake adapter. */
putenv('R61_MODE=library');
require dirname(__DIR__) . '/combination-r61/local-acceptance.php';
use think\facade\Db;
use app\services\activity\combination\CombinationGroupListServices;
use app\services\activity\combination\CombinationRefundServices;
use app\services\activity\combination\CombinationGroupManagementServices;

$admin = ['id'=>1, 'level'=>0, 'admin_type'=>1];
class WholeGroupGateway extends R61Gateway
{
    public static function groupLocal(): self { return new self(['mchntCd'=>'LOCAL_ONLY_R61','mchnt_key'=>'fabricated-local-key']); }
    public $orders = [];
    public $failOrder = 0;
    public $failureState = 'USERPAYING';
    public function apply(array $receipt): void {
        if (!$this->requests) check((int)Db::name('store_order')->whereIn('id', $this->orders)->where('refund_status', 1)->count() === count($this->orders),
            'all real orders frozen before first channel application');
        $this->original = $receipt['channel_order_no']; parent::apply($receipt);
    }
    public function query(array $receipt): array {
        $this->original = $receipt['channel_order_no'];
        $this->status = (int)$receipt['order_id'] === $this->failOrder ? $this->failureState : 'SUCCESS';
        return parent::query($receipt);
    }
}
Db::startTrans();
try {
    $reader = new CombinationGroupListServices();
    $first = fixture(6,3,'yue');
    Db::name('user')->where('uid',$first['uid'])->update(['bar_code'=>'000023','user_type'=>'import']);
    $ids = [$first['pinkId']];
    for ($i=0;$i<24;$i++) {
        $f=fixture(6,3,'yue'); $ids[]=$f['pinkId'];
        Db::name('store_pink')->where('id',$f['pinkId'])->update(['cid'=>$first['cid']]);
    }
    $filters=['cid'=>$first['cid']];
    $page=$reader->page($filters,$admin);
    check($page['count']===25 && count($page['list'])===20 && $page['limit']===20,'group grain page one has 20 of 25');
    $second=$reader->page($filters+['page'=>2],$admin);
    check(count($second['list'])===5 && $second['count']===25,'page two keeps complete filtered count');
    check(!array_intersect(array_column($page['list'],'id'),array_column($second['list'],'id')),'stable group IDs do not overlap pages');
    $single=$reader->page($filters+['group_id'=>$first['pinkId']],$admin)['list'][0];
    check(!array_key_exists('member_no',$single) && $single['member_type']==='会员','removed member number is absent while imported identity is retained');
    check($single['product_name']!=='' && $single['group_name']!=='' && $single['deadline']>0,'original item, group name and expiry snapshots visible');
    Db::name('user')->where('uid',$first['uid'])->update(['user_type'=>'h5']);
    seed('cashier_v3_report_member_origin_evidence',['tenant_id'=>'0','member_id'=>$first['uid'],
        'origin_type'=>'SYSTEM_CREATED','evidence_status'=>'PROVEN','evidence_source_type'=>'USER_SET_INFO',
        'evidence_source_id'=>(string)$first['uid'],'idempotency_key'=>'LOCAL-GROUP-ORIGIN-'.$first['uid']]);
    check($reader->page($filters+['group_id'=>$first['pinkId']],$admin)['list'][0]['member_type']==='新客',
        'self-registered H5 source is proven from creation evidence, not current spending status');
    $export=$reader->page($filters,$admin,true);
    check(count($export['export'])===25 && count($export['header'])===11 && !in_array('member_no',$export['filekey'],true),'export covers all matching groups with member-number column removed');
    $denied=$reader->page($filters,['id'=>2,'level'=>1,'admin_type'=>1]);
    check($denied['count']===0,'no employee scope cannot expand to all stores');
    $narrow=$reader->page($filters+['store_ids'=>[Db::name('store_order')->where('id',$first['oid'])->value('store_id')]],$admin);
    check($narrow['count']===1,'store filtering is authoritative and narrows count with rows');
    $empty=$reader->page($filters+['keyword'=>'NO_SUCH_LOCAL_MEMBER'], $admin);
    check($empty['count']===0 && $empty['list']===[],'keyword empty result is an explicit zero');
    $invalid=false; try {$reader->page(['store_ids'=>'1 OR 1=1'],$admin);} catch (think\exception\ValidateException $e) {$invalid=true;}
    check($invalid,'malformed narrowing is rejected, never broadened');
    $range='2000/01/01-2000/01/02';
    check($reader->page($filters+['data'=>$range],$admin)['count']===0,'opening-time filter executes in SQL');
    $reflection=new ReflectionMethod($reader,'query');$reflection->setAccessible(true);
    $sql=$reflection->invoke($reader,$filters,null)->field('g.id')->order('g.id desc')->limit(20)->fetchSql()->select();
    $plan=Db::query('EXPLAIN '.$sql);
    check(count($plan)>0,'actual filtered MySQL query has an executable plan');

    $leader=fixture(6,3); $member=fixture(6,3); joinFixture($leader,$member);
    (new CombinationGroupManagementServices())->complete($leader['pinkId'],1);
    Db::name('store_order_cart_info')->where('oid',$member['oid'])->update(['write_surplus_times'=>0]);
    // R61's factory returns its own class; construct the group-aware adapter with fabricated credentials explicitly.
    $gateway=WholeGroupGateway::groupLocal();
    $service=new CombinationRefundServices($gateway);
    $blocked=false; try {$service->refundGroup($leader['pinkId'],'本地整团拦截验收',$admin);} catch (think\exception\ValidateException $e) {$blocked=true;}
    check($blocked && count($gateway->requests)===0,'one used member prevents EVERY channel request');
    check((int)Db::name('combination_order_refund')->whereIn('order_id',[$leader['oid'],$member['oid']])->count()===0,'failed whole preflight rolls back earlier member receipts');
    check((int)Db::name('store_order')->whereIn('id',[$leader['oid'],$member['oid']])->where('refund_status',0)->count()===2,'failed whole preflight restores both order eligibility states');
    Db::name('store_order_cart_info')->where('oid',$member['oid'])->update(['write_surplus_times'=>1]);
    $scopeClass=app\services\organization\EmployeeDataScopeServices::class;
    $originalScope=app()->make($scopeClass);
    $restricted=new class extends app\services\organization\EmployeeDataScopeServices {
        public $stores=[];
        public function resolveEffectiveStoreIds(int $employeeId,int $contextStoreId=0,array $adminInfo=[]) { return $this->stores; }
    };
    $restricted->stores=[(int)Db::name('store_order')->where('id',$leader['oid'])->value('store_id')];
    app()->instance($scopeClass,$restricted);
    try {
        $blocked=false;try {$service->refundGroup($leader['pinkId'],'越权整团退款拦截',$admin);} catch(think\exception\ValidateException $e) {$blocked=true;}
        check($blocked && !$gateway->requests,'leader-store access cannot refund an unauthorized member store');
    } finally { app()->instance($scopeClass,$originalScope); }
    $gateway->orders=[$leader['oid'],$member['oid']]; $gateway->failOrder=$member['oid'];
    $result=$service->refundGroup($leader['pinkId'],'本地整团退款验收',$admin);
    check($result['state']==='partial' && $result['success_count']===1 && $result['pending_count']===1,'mixed success and pending are never reported as whole success');
    check(count($result['results'])===2,'virtual seats are excluded from refund money');
    $applications=count(array_filter($gateway->requests,static function($r){return strpos($r['url'],'commonRefund')!==false;}));
    $gateway->failOrder=0;
    $result=$service->refundGroup($leader['pinkId'],'查询整团退款',$admin,'query');
    check($result['state']==='success' && $result['success_count']===2,'query closes the remaining original receipt');
    check(count(array_filter($gateway->requests,static function($r){return strpos($r['url'],'commonRefund')!==false;}))===$applications,'query and replay never apply a second channel refund');
    $before=count($gateway->requests);$again=$service->refundGroup($leader['pinkId'],'重复整团退款',$admin);
    check($again['state']==='success' && count($gateway->requests)===$before,'whole success replay is idempotent');
    check((int)Db::name('store_order_cart_info')->whereIn('oid',[$leader['oid'],$member['oid']])->sum('write_surplus_times')===0,'all real original rights revoked through terminal refund');
    $fresh=fixture(6,2,'yue'); (new CombinationGroupManagementServices())->complete($fresh['pinkId'],1);
    $query=$service->refundGroup($fresh['pinkId'],'只查询不发起',$admin,'query');
    check($query['results'][0]['state']==='not_started' && !Db::name('combination_order_refund')->where('order_id',$fresh['oid'])->count(),'query cannot start an unrequested refund');
    $retryLeader=fixture(6,2);$retryMember=fixture(6,2);joinFixture($retryLeader,$retryMember);
    (new CombinationGroupManagementServices())->complete($retryLeader['pinkId'],1);
    $retryGateway=WholeGroupGateway::groupLocal();$retryGateway->orders=[$retryLeader['oid'],$retryMember['oid']];
    $retryGateway->failOrder=$retryMember['oid'];$retryGateway->failureState='PAYERROR';
    $retryService=new CombinationRefundServices($retryGateway);
    $partial=$retryService->refundGroup($retryLeader['pinkId'],'本地失败重试',$admin);
    check($partial['success_count']===1 && $partial['failed_count']===1,'signed definitive failure remains separate from succeeded member');
    $successfulNo=$partial['results'][0]['refund_no'];$failedNo=$partial['results'][1]['refund_no'];
    $retryGateway->failOrder=0;$retryGateway->orders=[$retryMember['oid']];$retryGateway->requests=[];
    $retried=$retryService->refundGroup($retryLeader['pinkId'],'仅重试失败成员',$admin,'retry');
    check($retried['state']==='success' && $retried['results'][0]['refund_no']===$successfulNo
        && $retried['results'][1]['refund_no']!==$failedNo,'explicit retry only replaces definitively failed receipt, preserving prior success');
    echo "COMBINATION_GROUP_LIST_BACKEND_PASS\n";
} finally { Db::rollback(); }

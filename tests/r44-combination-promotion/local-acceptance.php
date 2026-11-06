<?php
declare(strict_types=1);
/** Local-only actual MySQL query/attribution test; fixtures and settings roll back, no provider calls. */
putenv('R61_MODE=library');
require dirname(__DIR__) . '/combination-r61/local-acceptance.php';
use think\facade\Db;
use app\services\activity\combination\CombinationPromotionServices;

$service = new CombinationPromotionServices();
$admin = ['id'=>1,'level'=>0,'roles'=>[],'admin_type'=>1,'employee_id'=>987654321];
// Export owns its read-only snapshot; exercise real existing local rows before fixture rollback transaction.
$readFilters=['start_date'=>'2020-01-01','end_date'=>'2030-01-01'];
$readDetails=$service->details($readFilters,$admin);
$readExport=$service->details($readFilters,$admin,null,true);
check($readExport['count']===$readDetails['count'] && $readExport['summary']===$readDetails['summary'] && count($readExport['export'])===$readDetails['count'],'real MySQL export read-only snapshot matches complete query and totals');
Db::startTrans();
try {
    $one=fixture(6,3,'yue');$two=fixture(6,3,'yue');$three=fixture(6,3,'yue');
    joinFixture($one,$two);joinFixture($one,$three);
    $promotionStore=seed('system_store',['name'=>'R44推广门店','is_del'=>0,'is_show'=>1]);
    $promoter=seed('user',['nickname'=>'R44分享人','real_name'=>'R44分享人','status'=>1,'is_del'=>0,'spread_open'=>1,'is_promoter'=>1,'belong_store_id'=>$promotionStore]);
    $secondPromoter=seed('user',['nickname'=>'R44第二分享人','status'=>1,'is_del'=>0,'spread_open'=>1,'is_promoter'=>1,'belong_store_id'=>$promotionStore]);
    foreach([$one,$two] as $order) $service->registerOrderInTx($order['oid'],$promoter);
    $service->registerOrderInTx($three['oid'],$secondPromoter);
    $snapshot=Db::name('combination_promotion_order')->where('order_id',$one['oid'])->find();
    check((int)$snapshot['store_id']===$promotionStore,'share member store is frozen, distinct from purchasing store');
    Db::name('user')->where('uid',$promoter)->update(['belong_store_id'=>0,'nickname'=>'已改名']);
    $service->registerOrderInTx($one['oid'],$secondPromoter);
    check(Db::name('combination_promotion_order')->where('order_id',$one['oid'])->find()===$snapshot,'replay and member changes cannot rewrite existing attribution');
    $filters=['activity_ids'=>[$one['cid']]];
    $view=$service->dashboard($filters,$admin);
    check($view['metrics']['pending_amount']==='300.00' && $view['metrics']['formed_amount']==='0.00','three paid pending orders produce unformed net sales');
    check($view['metrics']['group_count']===1 && $view['metrics']['order_count']===3,'one group distinct count, three orders');
    $own=$service->memberSummary($filters,$promoter);
    check($own['metrics']['total_amount']==='300.00' && $own['metrics']['group_count']===1,'opening share member receives the whole group');
    $other=$service->details($filters+['uid'=>$promoter],null,$secondPromoter);
    check($other['count']===0,'joining through another share link cannot change the group promoter');
    Db::name('store_pink')->where('id|k_id',$one['pinkId'])->update(['status'=>2]);
    $view=$service->dashboard($filters,$admin);
    check($view['metrics']['formed_amount']==='300.00' && $view['metrics']['pending_amount']==='0.00' && $view['metrics']['total_amount']==='300.00','completion transfers bucket without duplicating total');
    // 旧成团会把订单 type 改成 0；同一查询供概览、明细和导出复用，不能漏单或重复。
    Db::name('store_order')->where('id',$one['oid'])->update(['type'=>0]);
    $view=$service->dashboard($filters,$admin);
    $legacyDetails=$service->details($filters,$admin);
    check($view['metrics']['formed_amount']==='300.00' && $view['metrics']['order_count']===3
        && $legacyDetails['count']===3,
        'legacy type-zero paid group appears once across dashboard and detail');
    $originalOrderNo=(string)Db::name('store_pink')->where('id',$one['pinkId'])->value('order_id');
    Db::name('store_pink')->where('id',$one['pinkId'])->update(['order_id'=>'unrelated-order']);
    check($service->dashboard($filters,$admin)['metrics']['formed_amount']==='200.00',
        'type-zero order requires matching participant identity and cannot leak ordinary sales');
    Db::name('store_pink')->where('id',$one['pinkId'])->update(['order_id'=>$originalOrderNo]);
    check($service->dashboard($filters,$admin)['metrics']['formed_amount']==='300.00',
        'restored participant identity restores the original group total');
    // 卡项拼团发卡后原单会转成 type=11；仍须依赖同一参团身份，不能把普通卡项计入大屏。
    Db::name('store_order')->where('id',$one['oid'])->update(['type'=>11]);
    $cardDashboard=$service->dashboard($filters,$admin);
    $cardDetails=$service->details($filters,$admin);
    $cardPromoterDetails=$service->details($filters,$admin,null,false,true);
    check($cardDashboard['metrics']['formed_amount']==='300.00'
        && $cardDetails['count']===3 && $cardPromoterDetails['count']===3,
        'type-eleven paid card group appears in dashboard and both detail reports');
    Db::name('store_pink')->where('id',$one['pinkId'])->update(['order_id'=>'unrelated-card-order']);
    check($service->dashboard($filters,$admin)['metrics']['formed_amount']==='200.00',
        'type-eleven card order still requires matching participant identity');
    Db::name('store_pink')->where('id',$one['pinkId'])->update(['order_id'=>$originalOrderNo]);
    check($service->dashboard($filters,$admin)['metrics']['formed_amount']==='300.00',
        'restored card-group participant identity restores the total');
    Db::name('store_order')->where('id',$one['oid'])->update(['type'=>3]);
    seed('store_order_terminal_operation',['store_order_id'=>$one['oid'],'business_type'=>1,'action_type'=>1,'state'=>2,'refund_amount'=>100,'operation_no'=>'R44-PENDING-'.$one['oid'],'request_token'=>'R44-pending-'.$one['oid']]);
    check($service->dashboard($filters,$admin)['metrics']['total_amount']==='300.00','pending refund does not prematurely reduce net sales');
    Db::name('store_order_terminal_operation')->where('store_order_id',$one['oid'])->update(['state'=>4]);
    Db::name('store_order')->where('id',$one['oid'])->update(['refund_status'=>2,'refund_price'=>100]);
    check($service->dashboard($filters,$admin)['metrics']['total_amount']==='200.00','successful refund reduces once despite original and terminal both recording it');
    Db::name('store_pink')->where('id|k_id',$one['pinkId'])->update(['status'=>3]);
    $view=$service->dashboard($filters,$admin);
    check($view['metrics']['pending_amount']==='200.00' && $view['metrics']['refund_pending_amount']==='200.00','failed unrefunded net remains explicitly refund-pending');
    $detail=$service->details($filters+['status'=>'unformed'],$admin);
    check($detail['count']===3 && $detail['summary']['net_amount']==='200.00','detail uses same status, complete records, exact sum');
    $denied=false;try{$service->dashboard($filters,['id'=>2,'level'=>1,'admin_type'=>1,'roles'=>[]]);}catch(think\exception\ValidateException $e){$denied=true;}
    check($denied,'dashboard requires explicit feature grant');
    $query=new ReflectionMethod($service,'query');$query->setAccessible(true);
    check($query->invoke($service,$filters,[],null)->count()===0,'empty allowed store set denies every row');
    check($query->invoke($service,$filters,[$promotionStore],null)->count()===3,'authorized promotion store controls attributable orders');
    check($service->details($filters+['start_date'=>'2001-01-01','end_date'=>'2001-01-02'],$admin)['count']===0,'payment date filter applied on backend');
    $legacy=fixture(6,3,'yue');
    // 无授权并且历史订单门店为0时也必须拒绝，不能把0当作一间授权门店。
    Db::name('store_order')->where('id',$legacy['oid'])->update(['store_id'=>0]);
    check($query->invoke($service,['activity_ids'=>[$legacy['cid']]],[],null)->count()===0,'empty scope excludes historical order with store zero');
    $legacyRow=$service->details(['activity_ids'=>[$legacy['cid']]],$admin)['list'][0];
    check($legacyRow['promoter_name']==='未归属' && $legacyRow['store_name']==='未归属','historical missing attribution not guessed from member or staff');
    for($i=0;$i<22;$i++){$f=fixture(6,3,'yue');Db::name('store_order')->where('id',$f['oid'])->update(['activity_id'=>$legacy['cid']]);}
    $first=$service->details(['activity_ids'=>[$legacy['cid']]],$admin);$last=$service->details(['activity_ids'=>[$legacy['cid']],'page'=>2],$admin);
    check($first['count']===23 && count($first['list'])===20 && count($last['list'])===3,'20-row pagination with complete count and stable tail');
    $service->settings($admin,['activity_ids'=>[$one['cid']]]);
    check($service->settings($admin)['activity_ids']===[$one['cid']],'dashboard activity settings persist and read back');
    check($service->dashboard([],$admin)['metrics']['total_amount']==='200.00','saved activity selection drives backend totals');
    $unqualified=fixture(6,3,'yue');
    $buyerStore=(int)Db::name('store_order')->where('id',$unqualified['oid'])->value('store_id');
    Db::name('user')->where('uid',$unqualified['uid'])->update(['belong_store_id'=>$buyerStore,'spread_open'=>0]);
    $service->registerOrderInTx($unqualified['oid'],$unqualified['uid']);
    check((int)Db::name('combination_promotion_order')->where('order_id',$unqualified['oid'])->value('share_uid')===0,'non-promoter cannot gain attribution through forged link');
    // 无合格开团分享人时按团长归属门店统计；个人榜不得生成“未归属推广人”。
    $buyerName=(string)Db::name('user')->where('uid',$unqualified['uid'])->value('real_name');
    $buyerFilter=['activity_ids'=>[$unqualified['cid']]];
    $buyerView=$service->dashboard($buyerFilter,$admin);
    check($buyerView['metrics']['total_amount']==='100.00' && $buyerView['rankings']['members']===[],'unpromoted purchase stays in totals but not personal performance');
    check((int)$buyerView['rankings']['stores'][0]['id']===$buyerStore && $buyerView['rankings']['stores'][0]['buyer_name']===$buyerName,'leader store and single buyer remain visible in store ranking');
    $buyerDetail=$service->details($buyerFilter+['dimension'=>'store','dimension_id'=>$buyerStore],$admin);
    check($buyerDetail['count']===1 && $buyerDetail['list'][0]['member_name']===$buyerName,'store drilldown shares ranking scope and buyer name');
    echo "R44 PROMOTION ACCEPTANCE COMPLETE\n";
} finally { Db::rollback(); }

<?php
declare(strict_types=1);
/** 本地真实领域回归：所有业务夹具在外层事务回滚，无支付、无通知、无线上访问。 */
putenv('R61_MODE=library');
require dirname(__DIR__).'/combination-r61/local-acceptance.php';
use think\facade\Db;
use app\services\activity\combination\CombinationLeaderRewardServices as Rewards;
use app\services\user\MemberSurveyGiftServices;
use app\services\user\MemberRewardCatalogServices;
function r44Reject(callable $call,string $label):void { try { $call(); } catch (think\exception\ValidateException $e) {check(true,$label);return;}throw new RuntimeException('Expected reject: '.$label); }
function r44Fixture(array $config,int $admin):array {
    $f=fixture(6,2); $store=(int)Db::name('store_order')->where('id',$f['oid'])->value('store_id');
    Db::name('user')->where('uid',$f['uid'])->update(['belong_store_id'=>$store,'status'=>1]);
    $org=seed('organization',['name'=>'R44奖励测试组织','is_del'=>0]);seed('organization_store',['store_id'=>$store,'org_id'=>$org]);
    $svc=new Rewards();$svc->saveConfigInTx($f['cid'],$config,$admin);$svc->freezeGroupInTx($f['pinkId']);
    $member=fixture(6,2);joinFixture($f,$member);
    $f['store']=$store;$f['member']=$member;
    return $f;
}
function r44Complete(array $f):void { Db::name('store_pink')->where('id|k_id',$f['pinkId'])->where('status',1)->update(['status'=>2]);(new Rewards())->onGroupCompleted($f['pinkId']); }
function r44Reward(array $f):?array {return Db::name('combination_leader_reward')->where('group_id',$f['pinkId'])->find();}
function r44Items(array $f):array {return Db::name('combination_leader_reward_item')->where('reward_id',r44Reward($f)['id'])->order('item_no')->select()->toArray();}
function r44Status(array $f,string $expected,string $label):void {$row=r44Reward($f);if($row['status']!==$expected)throw new RuntimeException($label.': '.json_encode(r44Items($f),JSON_UNESCAPED_UNICODE));check(true,$label);}
// Tick reads only this new module's receipts. Never process a developer-created business receipt as a fixture.
if(Db::name('combination_leader_reward')->count()!==0)throw new RuntimeException('Run on an empty local reward fixture database');
Db::startTrans();
try {
    $svc=new Rewards();
    $admin=seed('system_admin',['account'=>'r44-reward-'.bin2hex(random_bytes(4)),'status'=>1,'is_del'=>0,'admin_type'=>0,'level'=>0]);
    $coupon=seed('store_coupon_issue',['title'=>'R44团长奖励券','relation_id'=>0,'status'=>1,'is_del'=>0,'is_permanent'=>1,'is_give_subscribe'=>0,'is_full_give'=>0,'total_count'=>100,'remain_count'=>100]);
    $config=['enabled'=>true,'store_policy'=>'member','product'=>[],'coupon'=>[['id'=>$coupon,'num'=>2,'write_days'=>30]]];
    $master=seed('store_product',['store_name'=>'R44总部奖励项目','type'=>0,'relation_id'=>0,'product_type'=>6,'is_del'=>0,'is_show'=>1,'is_verify'=>1]);
    $masterSku=seed('store_product_attr_value',['product_id'=>$master,'type'=>0,'unique'=>'r44master'.bin2hex(random_bytes(4)),'suk'=>'默认','write_times'=>1]);
    $config['product']=[['id'=>$masterSku,'num'=>1,'write_days'=>30]];
    $f=r44Fixture($config,$admin);
    $localProject=seed('store_product',['store_name'=>'R44门店奖励项目','pid'=>$master,'type'=>1,'relation_id'=>$f['store'],'product_type'=>6,'is_del'=>0,'is_show'=>1,'is_verify'=>1]);
    seed('store_product_attr_value',['product_id'=>$localProject,'type'=>0,'unique'=>'r44store'.bin2hex(random_bytes(4)),'suk'=>'默认','write_times'=>1]);
    check($svc->config($f['cid'])['enabled']===true,'奖励设置保存刷新可回读');
    $frozen=json_decode(r44Reward($f)['config_snapshot'],true);
    $svc->saveConfigInTx($f['cid'],['enabled'=>false,'product'=>[],'coupon'=>[]],$admin);
    check(json_decode(r44Reward($f)['config_snapshot'],true)===$frozen,'活动修改不影响已开团奖励快照');
    $before=[];foreach(['cashier_v3_sale_fact','cashier_v3_payment_fact','cashier_v3_performance_fact']as$t)$before[$t]=Db::name($t)->count();
    r44Complete($f);r44Status($f,'issued','真实成团项目和两张券发放成功');
    $items=r44Items($f);check(count($items)===3&&count(array_unique(array_column($items,'gift_id')))===3,'逐份独立赠送权威便于部分安全撤销');
    $giftIds=array_column($items,'gift_id');$eventCount=Db::name('cashier_v3_gift_fact')->whereIn('source_id',$giftIds)->count();
    $svc->onGroupCompleted($f['pinkId']);$svc->freezeGroupInTx($f['pinkId']);
    check(Db::name('cashier_v3_gift_fact')->whereIn('source_id',$giftIds)->count()===$eventCount,'重复回调和冻结不重复发奖');
    foreach($before as$t=>$count)check(Db::name($t)->count()===$count,'奖励不生成 '.$t);
    check(Db::name('user_card_holder')->where('uid',$f['uid'])->where('is_del',0)->count()===1,'项目奖励进入统一可核销权益');
    $coupons=Db::name('cashier_v3_direct_gift_item')->whereIn('gift_id',$giftIds)->where('gift_kind','coupon')->order('id')->select()->toArray();
    $usedId=(int)json_decode($coupons[0]['coupon_user_ids_json'],true)[0];Db::name('store_coupon_user')->where('id',$usedId)->update(['status'=>1]);
    $balance=(string)Db::name('user')->where('uid',$f['uid'])->value('now_money');
    Db::name('store_order')->where('id',$f['member']['oid'])->update(['refund_status'=>2]);$svc->onLeaderOrderRefunded($f['member']['oid']);
    r44Status($f,'issued','普通团员退款不撤销团长奖励');
    // 模拟退款成功后进程在hook前退出，tick必须从权威退款状态补撤销。
    Db::name('store_order')->where('id',$f['oid'])->update(['refund_status'=>2]);$svc->tick();
    r44Status($f,'manual_review','漏退款hook由队列补撤销，已用券只标人工处理');
    $states=array_count_values(array_column(r44Items($f),'status'));
    check(($states['revoked']??0)===2&&($states['manual_review']??0)===1,'撤销未用项目和券，保留已用券');
    check((string)Db::name('user')->where('uid',$f['uid'])->value('now_money')===$balance,'已使用奖励不扣会员余额');
    $facts=Db::name('cashier_v3_gift_fact')->whereIn('source_id',$giftIds)->count();$svc->onLeaderOrderRefunded($f['oid']);$svc->tick();
    check(Db::name('cashier_v3_gift_fact')->whereIn('source_id',$giftIds)->count()===$facts,'重复退款回调不重复撤销');

    $onlyCoupon=['enabled'=>true,'product'=>[],'coupon'=>[['id'=>$coupon,'num'=>1,'write_days'=>30]]];
    $virtual=r44Fixture($onlyCoupon,$admin);Db::name('store_pink')->where('id',$virtual['member']['pinkId'])->update(['is_virtual'=>1,'uid'=>0]);r44Complete($virtual);
    r44Status($virtual,'ineligible','虚拟补齐成团不发奖励');check(!r44Items($virtual),'虚拟团不生成发奖单');
    $disabled=r44Fixture(['enabled'=>false,'product'=>[],'coupon'=>[]],$admin);r44Complete($disabled);r44Status($disabled,'disabled','未开关奖励的团不补奖');
    $legacy=fixture(6,2);$svc->onGroupCompleted($legacy['pinkId']);check(!r44Reward($legacy),'历史无快照团不补发奖励');

    $failed=r44Fixture($onlyCoupon,$admin);Db::name('store_coupon_issue')->where('id',$coupon)->update(['status'=>0]);r44Complete($failed);r44Status($failed,'pending','模板停售保留失败回执可重试');
    check(r44Items($failed)[0]['last_error']!=='','失败回执有具体原因');Db::name('store_coupon_issue')->where('id',$coupon)->update(['status'=>1]);$svc->onGroupCompleted($failed['pinkId']);r44Status($failed,'issued','修复模板后只补发原冻结奖励');
    Db::name('store_pink')->where('id',$failed['pinkId'])->update(['status'=>3]);$svc->tick();r44Status($failed,'revoked','漏整团取消hook由队列补撤销');
    // 待成团回执不占用有界批次，即使其ID更早。
    $wait=r44Fixture($onlyCoupon,$admin);$later=r44Fixture($onlyCoupon,$admin);Db::name('store_pink')->where('id|k_id',$later['pinkId'])->update(['status'=>2]);$svc->tick(1);
    r44Status($wait,'waiting','未成团仍等待');r44Status($later,'issued','限一条队列不被前面的未成团饿死');
    // 已成功团的普通团员后退，不得使延迟发奖失去原成团资格。
    $delayed=r44Fixture($onlyCoupon,$admin);Db::name('store_pink')->where('id|k_id',$delayed['pinkId'])->update(['status'=>2]);
    Db::name('store_order')->where('id',$delayed['member']['oid'])->update(['refund_status'=>2]);
    Db::name('store_pink')->where('id',$delayed['member']['pinkId'])->update(['is_refund'=>1]);
    $svc->onGroupCompleted($delayed['pinkId']);r44Status($delayed,'issued','延迟发奖读取原成功团资格，普通团员后退款不误拒');
    $usedProject=r44Fixture(['enabled'=>true,'product'=>[['id'=>$masterSku,'num'=>1,'write_days'=>30]],'coupon'=>[]],$admin);
    $localUsed=seed('store_product',['store_name'=>'R44已用奖励项目','pid'=>$master,'type'=>1,'relation_id'=>$usedProject['store'],'product_type'=>6,'is_del'=>0,'is_show'=>1,'is_verify'=>1]);
    seed('store_product_attr_value',['product_id'=>$localUsed,'type'=>0,'unique'=>'r44used'.bin2hex(random_bytes(4)),'suk'=>'默认','write_times'=>1]);
    r44Complete($usedProject);$authorityItem=Db::name('cashier_v3_direct_gift_item')->where('gift_id',r44Items($usedProject)[0]['gift_id'])->find();
    // 统一作废服务把已预约也视作使用边界，不能直接清空用户权益。
    seed('store_reservation_order',['uid'=>$usedProject['uid'],'cart_info_id'=>$authorityItem['benefit_detail_id'],'is_del'=>0,'is_system_del'=>0]);
    Db::name('store_order')->where('id',$usedProject['oid'])->update(['refund_status'=>2]);$svc->onLeaderOrderRefunded($usedProject['oid']);
    r44Status($usedProject,'manual_review','已预约项目保留权益并标记人工处理');
    check(Db::name('user_card_holder')->where('id',$authorityItem['card_holder_id'])->value('is_del')==0,'已预约权益未被静默作废');
    $handoff=r44Fixture($onlyCoupon,$admin);$originalSnapshot=r44Reward($handoff)['config_snapshot'];
    Db::name('user')->where('uid',$handoff['member']['uid'])->update(['status'=>1,'belong_store_id'=>$handoff['store']]);
    $svc->saveConfigInTx($handoff['cid'],['enabled'=>false,'product'=>[],'coupon'=>[]],$admin);
    (new app\services\activity\combination\StorePinkServices(app()->make(app\dao\activity\combination\StorePinkDao::class)))->setRefundPink(Db::name('store_order')->where('id',$handoff['oid'])->find());
    $successor=Db::name('combination_leader_reward')->where('group_id',$handoff['member']['pinkId'])->find();
    check($successor['config_snapshot']===$originalSnapshot&&$successor['member_id']==$handoff['member']['uid'],'团长退款接任沿用原开团配置并绑定新团长');
    r44Status($handoff,'revoked','原团长接任后奖励回执关闭');
    check($successor['status']==='waiting'&&!r44Items($handoff),'接任前后未提前发奖');
    $nextRoot=$handoff['member'];$nextRoot['cid']=$handoff['cid'];$nextRoot['pid']=$handoff['pid'];
    $nextMember=fixture(6,2);joinFixture($nextRoot,$nextMember);r44Complete($nextRoot);
    r44Status($nextRoot,'issued','接任后真实成团只向新团长发一次冻结奖励');
    $svc->onGroupCompleted($nextRoot['pinkId']);$svc->onLeaderOrderRefunded($handoff['oid']);
    check(count(r44Items($nextRoot))===1&&!r44Items($handoff),'新旧团长回调重放没有双份奖励');
    $bad=$onlyCoupon;$bad['coupon'][0]['num']=1.5;r44Reject(fn()=>$svc->saveConfigInTx($wait['cid'],$bad,$admin),'奖励数量拒绝小数');
    r44Reject(fn()=>$svc->saveConfigInTx($wait['cid'],['enabled'=>true,'product'=>[],'coupon'=>[]],$admin),'开启空奖励拒绝');
    $spoof=$onlyCoupon;$spoof['store_policy']='fixed';$spoof['store_id']=987654;$svc->saveConfigInTx($wait['cid'],$spoof,$admin);
    check($svc->config($wait['cid'])['store_policy']==='member'&&$svc->config($wait['cid'])['store_id']===0,'客户端不能覆盖会员归属门店策略');
    $survey=(new MemberSurveyGiftServices())->catalog(0,['product_type'=>'6','store_name'=>'R44总部奖励项目']);
    check($survey===(new MemberRewardCatalogServices())->catalog(0,['product_type'=>'6','store_name'=>'R44总部奖励项目']),'调查选品仍代理同一目录契约');
    $status=$svc->adminStatus($later['pinkId'],['id'=>$admin,'admin_type'=>0,'level'=>0]);check($status['status_label']==='已发放'&&count($status['items'])===1,'管理回执返回中文状态及奖励详情');
    r44Reject(fn()=>$svc->adminStatus($later['pinkId'],[]),'匿名不可读取奖励');
    echo "PASS all R44 leader reward scenarios; fixtures rollback\n";
} finally { Db::rollback(); }

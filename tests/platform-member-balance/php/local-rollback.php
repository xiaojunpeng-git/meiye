<?php
declare(strict_types=1);
/** 真实本地钱包/事件/事实事务验证，仅使用合成会员，全部回滚。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\user\PlatformMemberCenterServices;
use app\services\user\PlatformMemberBalanceAdjustmentServices;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('LOCAL ONLY');
function r5Seed(string $table, array $row): array {
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $field) {
        $key = $field['Field'];
        if (array_key_exists($key, $row) || $field['Default'] !== null || $field['Null'] === 'YES' || strpos($field['Extra'], 'auto_increment') !== false) continue;
        $row[$key] = preg_match('/int|decimal|float|double/', $field['Type']) ? 0 : '';
    }
    return $row;
}
$checks = 0;
function r5Check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL ' . $name); $checks++; echo 'PASS ', $name, PHP_EOL; }
function r5Reject(callable $fn, string $name): void { try { $fn(); } catch (Throwable $e) { r5Check(true, $name); return; } throw new RuntimeException('NOT REJECTED ' . $name); }
$admin = (int)Db::name('system_admin')->where(['level'=>0,'status'=>1,'is_del'=>0,'admin_type'=>0])->value('id');
$store = (int)Db::name('organization_store')->alias('o')->join('system_store s','s.id=o.store_id')->where('s.is_del',0)->where('o.org_id','>',0)->value('o.store_id');
$center = new PlatformMemberCenterServices(); $service = new PlatformMemberBalanceAdjustmentServices();
$prefix = 'LOCAL_R5_BALANCE_' . bin2hex(random_bytes(4));
$beforeReceipts = (int)Db::name('member_management_receipt')->count();
Db::startTrans();
try {
    $uid = (int)Db::name('user')->insertGetId(r5Seed('user',['nickname'=>$prefix,'real_name'=>$prefix,'status'=>1,'is_del'=>0,'delete_time'=>null,'belong_store_id'=>$store,'ben_money'=>'100.00','give_money'=>'20.00','now_money'=>'120.00','balance_version'=>1,'add_time'=>time()]));
    Db::name('store_user')->insert(r5Seed('store_user',['uid'=>$uid,'store_id'=>$store,'status'=>1]));
    $open = $center->action($admin,'open-member-balance-adjustment',['memberId'=>$uid]);
    $snapshot = $open['data']['balanceAdjustment'];
    r5Check($snapshot['balance']==='120.00' && $snapshot['principal']==='100.00' && $snapshot['bonus']==='20.00', 'read authority total/principal/bonus');
    $request = ['memberId'=>$uid,'type'=>1,'ben_money'=>'5','give_money'=>'7','mark'=>str_repeat('测',500),'expectedVersion'=>$snapshot['expectedVersion'],'requestId'=>bin2hex(random_bytes(16))];
    $saved = $center->action($admin,'adjust-member-balance',$request)['data'];
    $fresh = $service->read($admin,['memberId'=>$uid]);
    r5Check($fresh['principal']==='105.00' && $fresh['bonus']==='27.00' && $fresh['balance']==='132.00', 'both amounts persisted and reload authority agrees');
    r5Check($fresh['expectedVersion']===$snapshot['expectedVersion']+1, 'real wallet version advances once');
    $fact = Db::name('cashier_v3_balance_fact')->where('fact_id',$saved['adjustment']['operationId'])->find();
    r5Check((int)$fact['principal_delta_cents']===500 && (int)$fact['bonus_delta_cents']===700 && $fact['source_document_type']==='member_balance_adjustment', 'unified immutable fact has exact principal and bonus deltas');
    $ledger = Db::name('user_money')->where('id',$saved['adjustment']['ledgerId'])->find();
    r5Check($ledger['type']==='system_add' && strpos($ledger['mark'],$request['mark'])!==false, 'legacy ledger type and optional note preserved');
    $receipt = Db::name('member_management_receipt')->where('request_key',$request['requestId'])->find();
    $audit = json_decode($receipt['response_json'],true)['adjustment'];
    r5Check($audit['actorType']==='ADMIN' && $audit['operatorId']===$admin && $audit['before']['totalCents']===12000 && $audit['after']['totalCents']===13200, 'receipt preserves platform actor and before/after audit');
    r5Check($center->action($admin,'adjust-member-balance',$request)['data']===$saved && $service->read($admin,['memberId'=>$uid])===$fresh, 'same request replays without changing balance or version');
    $different=$request; $different['give_money']='8';
    r5Reject(function()use($center,$admin,$different){$center->action($admin,'adjust-member-balance',$different);},'same request key cannot carry different amounts');
    $stale=$request;$stale['requestId']=bin2hex(random_bytes(16));
    r5Reject(function()use($center,$admin,$stale){$center->action($admin,'adjust-member-balance',$stale);},'stale balance cannot overwrite concurrent amount');
    $decrease=$request;$decrease['requestId']=bin2hex(random_bytes(16));$decrease['expectedVersion']=$fresh['expectedVersion'];$decrease['type']=2;$decrease['ben_money']='3';$decrease['give_money']='2';$decrease['mark']='';
    $sub=$center->action($admin,'adjust-member-balance',$decrease)['data']['balanceAdjustment'];
    r5Check($sub['principal']==='102.00' && $sub['bonus']==='25.00' && $sub['balance']==='127.00','decrease supports both components and empty note');
    $detail=$center->action($admin,'load-member-detail-tab',['memberId'=>$uid,'tab'=>'balance-changes'])['data'];
    r5Check(strpos(json_encode($detail,JSON_UNESCAPED_UNICODE),'系统减少余额')!==false && strpos(json_encode($detail,JSON_UNESCAPED_UNICODE),'修改余额')!==false,'member detail reads unified adjustment facts with business labels');
    r5Reject(function(){app\services\cashier\v3\manifest\CashierV3ActionManifest::requireAction('adjust-member-balance');},'store command gateway does not expose platform adjustment');
    foreach ([['type'=>3],['ben_money'=>'-1'],['give_money'=>'0.1'],['ben_money'=>'10000000'],['ben_money'=>'0','give_money'=>'0'],['ben_money'=>'9999999'],['give_money'=>'9999999'],['operatorId'=>9999],['mark'=>str_repeat('测',501)]] as $bad) {
        $p=array_merge($decrease,['requestId'=>bin2hex(random_bytes(16)),'expectedVersion'=>$sub['expectedVersion']],$bad);
        r5Reject(function()use($center,$admin,$p){$center->action($admin,'adjust-member-balance',$p);},'reject invalid/insufficient/forged input '.(isset($bad['mark'])?'overlong remark':json_encode($bad)));
    }
    r5Reject(function()use($service,$uid){$service->read(2147483000,['memberId'=>$uid]);},'invalid platform identity rejected');
    r5Reject(function()use($service,$admin,$uid){$service->read($admin,['memberId'=>$uid,'selectedStoreIds'=>[2147483000]]);},'forged organization range rejected');
    $list = $center->action($admin,'query-members',['keyword'=>$prefix,'pageCode'=>'platform_member_list','limit'=>20])['data'];
    r5Check(count($list['records'])===1 && (float)$list['records'][0]['accountBalance']===127.0,'member list reload reads same saved balance');
    r5Check((int)Db::name('cashier_v3_business_event')->where('source_type','adjust-member-balance')->where('member_id',$uid)->count()===2,'each successful adjustment emits one business event');
    r5Check((int)Db::name('cashier_v3_payment_fact')->where('member_id',$uid)->count()===0 && (int)Db::name('cashier_v3_sale_fact')->where('member_id',$uid)->count()===0,'manual adjustment creates no cash/sale performance facts');
    // 在事实写入末尾制造唯一键失败，验证钱包、流水、事件与回执全部回滚。
    $failure=array_merge($decrease,['type'=>1,'ben_money'=>'1','give_money'=>'0','expectedVersion'=>$sub['expectedVersion'],'requestId'=>bin2hex(random_bytes(16))]);
    $collision='MBA-'.substr(hash('sha256','ADMIN|'.$admin.'|'.$failure['requestId']),0,40);
    unset($fact['id']);$fact['fact_id']=$collision;$fact['natural_key']='collision:'.$collision;
    Db::name('cashier_v3_balance_fact')->insert($fact);
    r5Reject(function()use($center,$admin,$failure){$center->action($admin,'adjust-member-balance',$failure);},'late persistence failure aborts full adjustment');
    r5Check($service->read($admin,['memberId'=>$uid])===$sub && !Db::name('member_management_receipt')->where('request_key',$failure['requestId'])->count(),'late failure restores wallet and removes failed receipt');
    // 历史主门店失效时，仅用已有有效 store_user 关系作上下文，不改写旧归属。
    Db::name('user')->where('uid',$uid)->update(['belong_store_id'=>0]);
    r5Check($service->read($admin,['memberId'=>$uid])['balance']==='127.00','historical primary store fallback preserves wallet access');
    $editor=$center->action($admin,'open-member-editor',['memberId'=>$uid])['data']['member'];
    r5Check((int)$editor['storeId']===$store && (int)Db::name('user')->where('uid',$uid)->value('belong_store_id')===0,'existing member editor fallback does not rewrite historical ownership');
    Db::name('user')->where('uid',$uid)->update(['belong_store_id'=>$store]);
    // 会员资格按当前授权；无授权拒绝，有授权账号保留原余额操作及组织筛选口径。
    $readAdmin=(int)Db::name('system_admin')->insertGetId(r5Seed('system_admin',['account'=>'r5_'.bin2hex(random_bytes(6)),'real_name'=>'R5普通后台','roles'=>'','level'=>1,'status'=>1,'is_del'=>0,'admin_type'=>0]));
    r5Reject(function()use($service,$readAdmin,$uid){$service->read($readAdmin,['memberId'=>$uid]);},'ungranted account cannot read member balance');
    $memberMenu=(int)Db::name('system_menus')->where('unique_auth','admin-user-user-index')->where('type',1)->where('is_del',0)->value('id');
    $memberRole=(int)Db::name('system_role')->insertGetId(r5Seed('system_role',['role_name'=>$prefix,'rules'=>(string)$memberMenu,'status'=>1]));
    Db::name('system_admin')->where('id',$readAdmin)->update(['roles'=>(string)$memberRole]);
    $limited=(new app\services\user\PlatformMemberScopeServices())->build($readAdmin);
    r5Check($limited['permissions']['read'] && $limited['permissions']['edit'],'granted platform account retains existing operations');
    $readRequest=array_merge($decrease,['type'=>1,'ben_money'=>'1','give_money'=>'0','expectedVersion'=>$sub['expectedVersion'],'requestId'=>bin2hex(random_bytes(16))]);
    $readSaved=$center->action($readAdmin,'adjust-member-balance',$readRequest)['data'];
    r5Check($readSaved['balanceAdjustment']['balance']==='128.00' && $readSaved['adjustment']['operatorId']===$readAdmin,'granted platform account adjusts balance with its own identity');
    $outside=(int)Db::name('system_store')->where('is_del',0)->where('id','<>',$store)->value('id');
    $foreign=(int)Db::name('user')->insertGetId(r5Seed('user',['nickname'=>$prefix.'_outside','status'=>1,'is_del'=>0,'delete_time'=>null,'belong_store_id'=>$outside,'balance_version'=>1]));
    r5Reject(function()use($service,$readAdmin,$foreign,$store){$service->read($readAdmin,['memberId'=>$foreign,'selectedStoreIds'=>[$store]]);},'ordinary platform identity cannot cross selected organization scope');
} finally { Db::rollback(); }
r5Check((int)Db::name('member_management_receipt')->count()===$beforeReceipts && !Db::name('user')->where('nickname',$prefix)->count(),'synthetic data and audit test changes rolled back');
echo 'R5_MEMBER_BALANCE_PASS ', $checks, ' checks', PHP_EOL;

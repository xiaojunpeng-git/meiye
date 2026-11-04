<?php
declare(strict_types=1);
/** Disposable local MySQL checks of real gift authorization, coupon identities, paging, current status and phone/export projection. */
require '/var/www/html/vendor/autoload.php';$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',(string)$e);exit(1);});
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\order\CashierV3GiftCouponUsageQueryServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;
use app\services\cashier\v3\order\CashierV3GiftRecordUnifiedQueryProvider;

$config=config('database');$default=$config['default'];$connection=$config['connections'][$default];
if(!in_array($connection['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('LOCAL_ONLY');
$source=$connection['database'];$original=Db::connect();$db='r10_gift_'.bin2hex(random_bytes(6));$created=false;$checks=0;$now=time();
function gcCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
/** Fill schema-required fixture fields only; no production customer, password or token is copied. */
function gcInsert(string $table,array $row):void {
    static $counter=0;$counter++;$token='R10-'.$table.'-'.$counter;
    foreach(Db::query('SHOW COLUMNS FROM eb_'.$table) as $c){
        $k=$c['Field'];if(array_key_exists($k,$row)||$c['Null']==='YES'||$c['Default']!==null||strpos($c['Extra'],'auto_increment')!==false)continue;
        $row[$k]=preg_match('/int|decimal|float|double/',$c['Type'])?0
            :(preg_match('/date|time/',$c['Type'])?'2026-11-04':substr(hash('sha256',$token.$k),0,preg_match('/\((\d+)\)/',$c['Type'],$m)?min(64,(int)$m[1]):64));
    }
    Db::name($table)->insert($row);
}
function gcScope(bool $platform=false,array $stores=[901],string $tenant='0',bool $feature=true):CashierV3DataScopeContext {
    return new CashierV3DataScopeContext(1,$platform?0:1,901,$tenant,'1',$stores,CashierV3DataScopeContext::MODE_STORES,
        [],false,'','r10-test',$feature?['cashier.v3.order_center']:[],[]);
}
function gcDenied(callable $call,string $label):void {try{$call();}catch(CashierV3CommandException $e){gcCheck(true,$label);return;}throw new RuntimeException('expected denial: '.$label);}
function gcGift(string $fact,string $kind,int $issue,array $ids):void {
    $base=$kind==='direct_gift'?'cashier_v3_direct_gift':'cashier_v3_recharge_gift';$gid='G-'.$fact;$item='I-'.$fact;
    $authority=['tenant_id'=>'0','gift_id'=>$gid,'gift_no'=>'ZS-'.$fact,'store_id'=>901,'member_id'=>9001,'status'=>'issued'];
    if($kind==='recharge_gift')$authority['recharge_order_no_snapshot']='CZ-TEST';
    gcInsert($base.'_authority',$authority);
    // Only columns actually present may be supplied to the direct authority.
    gcInsert($base.'_item',['gift_id'=>$gid,'item_id'=>$item,'item_no'=>1,'gift_kind'=>'coupon','coupon_issue_id'=>$issue,
        'quantity'=>count($ids),'coupon_user_ids_json'=>json_encode($ids),'content_snapshot_json'=>'{}','status'=>'issued']);
    gcInsert('cashier_v3_gift_fact',['gift_fact_id'=>$fact,'tenant_id'=>'0','store_id'=>901,'member_id'=>9001,'operator_id'=>1,
        'source_type'=>$kind,'source_id'=>$gid,'source_detail_id'=>$item,'gift_kind'=>'coupon','coupon_issue_id'=>$issue,
        'quantity'=>count($ids),'content_name_snapshot'=>'Synthetic voucher','content_snapshot_json'=>'{}','status'=>'effective','settled_at'=>time()]);
}
try {
    $original->execute('CREATE DATABASE `'.$db.'`');$created=true;
    foreach($original->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\'',[$source]) as $r){
        $t=$r['TABLE_NAME'];if(!preg_match('/^eb_[A-Za-z0-9_]+$/D',$t))continue;
        $original->execute('CREATE TABLE `'.$db.'`.`'.$t.'` LIKE `'.$source.'`.`'.$t.'`');
    }
    $config['connections'][$db]=array_replace($connection,['database'=>$db]);$config['default']=$db;config($config,'database');
    gcCheck(Db::query('SELECT DATABASE() db')[0]['db']===$db,'isolated schema');
    gcInsert('user',['uid'=>9001,'account'=>'r10-synthetic','real_name'=>'Synthetic','nickname'=>'Synthetic','phone'=>'13800000001','status'=>1,'is_del'=>0]);
    gcInsert('system_store',['id'=>901,'name'=>'Synthetic store']);
    gcInsert('store_coupon_issue',['id'=>701,'title'=>'Synthetic voucher','coupon_type'=>1]);
    gcInsert('store_coupon_issue',['id'=>703,'title'=>'Allowance','coupon_type'=>3]);
    for($i=1;$i<=25;$i++)gcInsert('store_coupon_user',['id'=>80000+$i,'cid'=>701,'uid'=>$i===5?9002:9001,'original_recipient_uid'=>9001,
        'coupon_title'=>'Synthetic voucher','coupon_price'=>'40.00','use_min_price'=>'0.00','status'=>in_array($i,[2,5,6],true)?1:($i===3?2:0),
        'use_time'=>in_array($i,[2,5,6],true)?$now-10:0,'is_fail'=>$i===3?1:0,'start_time'=>$i===7?$now+3600:0,'end_time'=>$i===4?$now-3600:0]);
    // A second recipient's same-title coupon must never leak into this gift.
    gcInsert('store_coupon_user',['id'=>89999,'cid'=>701,'uid'=>9003,'original_recipient_uid'=>9003,'coupon_title'=>'Synthetic voucher']);
    gcGift('D1','direct_gift',701,range(80001,80025));
    gcGift('R1','recharge_gift',701,[80001,80002]);
    foreach([81001,81002,81003,81004,81005] as $id)gcInsert('store_coupon_user',['id'=>$id,'cid'=>703,'uid'=>9001,'original_recipient_uid'=>9001,
        'coupon_title'=>'Allowance','coupon_price'=>'100.00','use_min_price'=>'0.00','status'=>0,'is_fail'=>0,'use_time'=>$id===81002?0:$now,'start_time'=>0,'end_time'=>0]);
    gcGift('DM','direct_gift',703,[81001,81002,81003,81004,81005]);
    gcInsert('coupon_allowance_account',['coupon_user_id'=>81001,'member_id'=>9001,'total_cents'=>10000,'remaining_cents'=>3000,'version'=>2]);
    gcInsert('coupon_allowance_account',['coupon_user_id'=>81003,'member_id'=>9001,'total_cents'=>10000,'remaining_cents'=>10000,'version'=>3]);
    gcInsert('coupon_allowance_account',['coupon_user_id'=>81004,'member_id'=>9001,'total_cents'=>10000,'remaining_cents'=>0,'version'=>2]);
    // The original issuance stays queryable after void; a missing coupon is reported without borrowing another instance.
    gcGift('VOID','direct_gift',701,[80003]);
    Db::name('cashier_v3_direct_gift_authority')->where('gift_id','G-VOID')->update(['status'=>'voided']);
    Db::name('cashier_v3_direct_gift_item')->where('item_id','I-VOID')->update(['status'=>'voided']);
    gcGift('MISSING','direct_gift',701,[89998]);
    gcInsert('store_coupon_user',['id'=>82001,'cid'=>701,'uid'=>9001,'original_recipient_uid'=>9001,'coupon_title'=>'Legacy use','status'=>1,'use_time'=>$now-100,'oid'=>92001]);
    gcInsert('store_order',['id'=>92001,'uid'=>9001,'order_id'=>'LEGACY-USE','paid'=>1]);
    gcGift('LEGACY','direct_gift',701,[82001]);
    gcInsert('cashier_v3_sales_order',['order_id'=>'O1','tenant_id'=>'0','member_id'=>9001,'store_id'=>901,'order_no'=>'XS-ONE','order_status'=>'settled','settled_at'=>$now-10]);
    gcInsert('cashier_v3_sales_order',['order_id'=>'O2','tenant_id'=>'0','member_id'=>9002,'store_id'=>902,'order_no'=>'XS-TRANSFER','order_status'=>'settled','settled_at'=>$now-5]);
    gcInsert('cashier_v3_sales_order_line',['order_line_id'=>'L1','order_id'=>'O1','tenant_id'=>'0','member_id'=>9001,'coupon_user_id'=>80002]);
    gcInsert('cashier_v3_sales_order_line',['order_line_id'=>'L2','order_id'=>'O2','tenant_id'=>'0','member_id'=>9002,'coupon_user_id'=>80005]);
    gcInsert('cashier_v3_checkout_order_coupon',['tenant_id'=>'0','coupon_user_id'=>80006,'status'=>'consumed','order_id'=>'O1','order_line_id'=>'L1']);
    gcInsert('coupon_allowance_movement',['id'=>91001,'tenant_id'=>'0','coupon_user_id'=>81001,'member_id'=>9001,'order_id'=>'O1','order_line_id'=>'L1','delta_cents'=>-7000,'created_at'=>$now-10]);
    gcInsert('coupon_allowance_movement',['id'=>91002,'tenant_id'=>'0','coupon_user_id'=>81003,'member_id'=>9001,'order_id'=>'O1','order_line_id'=>'L1','delta_cents'=>-5000,'created_at'=>$now-10]);
    gcInsert('coupon_allowance_movement',['id'=>91003,'tenant_id'=>'0','coupon_user_id'=>81003,'member_id'=>9001,'order_id'=>'O1','order_line_id'=>'L1','delta_cents'=>5000,'reversal_of'=>91002,'created_at'=>$now]);
    $service=new CashierV3GiftCouponUsageQueryServices();$op=new CashierV3OperatorScope(901,1,'1','0');
    $before=[];foreach(['store_coupon_user','coupon_allowance_account','coupon_allowance_movement'] as $table)$before[$table]=Db::name($table)->select()->toArray();
    Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
    try {
        $store=$service->query(['recordId'=>'v3-direct-gift:D1'],$op,gcScope());
        $platform=$service->query(['recordId'=>'v3-direct-gift:D1'],$op,gcScope(true));
        unset($store['dataAsOf'],$platform['dataAsOf']);gcCheck($store===$platform,'platform/store identical data');
        gcCheck($store['total']===25&&count($store['records'])===20&&$store['pageSize']===20,'first page and total');
        $page2=$service->query(['recordId'=>'v3-direct-gift:D1','page'=>2],$op,gcScope());gcCheck(count($page2['records'])===5&&$page2['page']===2,'second page remainder');
        $beyond=$service->query(['recordId'=>'v3-direct-gift:D1','page'=>999],$op,gcScope());gcCheck($beyond['page']===2,'out-of-range page clamped');
        $rows=array_column($store['records'],null,'couponNo');
        gcCheck($rows[80001]['usageStatus']==='未使用'&&$rows[80001]['usedAt']===null&&$rows[80001]['orderNo']===null,'unused coupon has no fabricated usage');
        gcCheck($rows[80002]['usageStatus']==='已使用'&&$rows[80002]['orderNo']==='XS-ONE','single use sale identity');
        gcCheck($rows[80003]['availabilityStatus']==='已作废'&&$rows[80003]['usageStatus']==='未使用','void is not consumption');
        gcCheck($rows[80004]['availabilityStatus']==='已过期'&&$rows[80004]['usageStatus']==='未使用','expiry is not consumption');
        gcCheck($rows[80005]['orderNo']==='XS-TRANSFER'&&!isset($rows[80005]['memberName'])&&!isset($rows[80005]['phone']),'transferred coupon usage without recipient PII');
        gcCheck($rows[80006]['orderNo']==='XS-ONE','additional whole-order coupon binding');
        gcCheck($rows[80007]['availabilityStatus']==='未生效','future coupon');
        gcCheck(!isset($rows[89999]),'same-title unrelated coupon excluded');
        $recharge=$service->query(['recordId'=>'v3-gift:R1'],$op,gcScope());gcCheck(count($recharge['records'])===2,'recharge and direct gift same query');
        $multi=$service->query(['recordId'=>'v3-direct-gift:DM'],$op,gcScope());$mr=array_column($multi['records'],null,'couponNo');
        gcCheck($mr[81001]['usageStatus']==='部分使用'&&$mr[81001]['remainingAmount']==='30.00'&&$mr[81001]['orderNo']==='XS-ONE','allowance current partial balance');
        gcCheck($mr[81002]['usageStatus']==='未使用'&&$mr[81002]['remainingAmount']==='100.00','uninitialized allowance read without write');
        gcCheck($mr[81003]['usageStatus']==='未使用'&&$mr[81003]['orderNo']===null&&$mr[81003]['usedAt']===null,'reversed debit does not count as current usage');
        gcCheck($mr[81004]['usageStatus']==='已使用'&&$mr[81004]['remainingAmount']==='0.00','exhausted allowance');
        gcCheck($mr[81005]['usageStatus']==='资料待核实'&&$mr[81005]['remainingAmount']===null,'used allowance without account cannot claim full balance');
        $void=$service->query(['recordId'=>'v3-direct-gift:VOID'],$op,gcScope());
        gcCheck($void['records'][0]['availabilityStatus']==='已作废','voided gift remains traceable');
        $missing=$service->query(['recordId'=>'v3-direct-gift:MISSING'],$op,gcScope());
        gcCheck($missing['records'][0]['couponNo']==='89998'&&$missing['records'][0]['usageStatus']==='资料缺失','missing issued coupon explicitly reported');
        $legacy=$service->query(['recordId'=>'v3-direct-gift:LEGACY'],$op,gcScope());
        gcCheck($legacy['records'][0]['orderNo']==='LEGACY-USE','legacy paid-order usage reference');
        gcDenied(static function()use($service,$op){$service->query(['recordId'=>'v3-direct-gift:D1'],$op,gcScope(false,[902]));},'store boundary');
        gcDenied(static function()use($service,$op){$service->query(['recordId'=>'v3-direct-gift:D1','storeIds'=>[902]],$op,gcScope());},'client scope only narrows');
        gcDenied(static function()use($service,$op){$service->query(['recordId'=>'v3-direct-gift:D1'],$op,gcScope(false,[901],'other'));},'tenant boundary');
        gcDenied(static function()use($service,$op){$service->query(['recordId'=>'v3-gift:D1'],$op,gcScope());},'source discriminator boundary');
        gcDenied(static function()use($service,$op){$service->query(['recordId'=>'v3-direct-gift:D1'],$op,gcScope(false,[901],'0',false));},'existing order-center feature gate');
        $page=(new CashierV3OrderCenterRecordQueryServices())->queryRecords(['recordType'=>'gift'],$op,gcScope());
        $record=current(array_filter($page['records'],static function($r){return $r['id']==='v3-direct-gift:D1';}));
        gcCheck($record&&$record['phone']==='13800000001'&&$record['couponUsageRecordId']==='v3-direct-gift:D1','phone and explicit capability in gift list');
        $contract=CashierV3OrderCenterUnifiedQueryContract::definition('order_center_gift');$keys=array_column($contract['fields'],'key');
        gcCheck($keys[array_search('member_name',$keys,true)+1]==='phone','phone export column immediately follows member');
        $method=new ReflectionMethod(CashierV3GiftRecordUnifiedQueryProvider::class,'projectRows');$method->setAccessible(true);
        $provider=(new ReflectionClass(CashierV3GiftRecordUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
        $projected=$method->invoke($provider,'gift',[$record]);
        gcCheck($projected[0]['phone']===$record['phone']&&$projected[0]['couponUsageRecordId']===$record['couponUsageRecordId'],'unified/export projection retains phone and action identity');
    } finally {Db::rollback();}
    foreach($before as $table=>$rows)gcCheck(Db::name($table)->select()->toArray()===$rows,'read-only tables unchanged '.$table);
    // Corrupt exact mappings fail closed instead of reading someone else's same-number/title coupon.
    Db::name('cashier_v3_direct_gift_item')->where('item_id','I-D1')->update(['coupon_user_ids_json'=>json_encode(array_fill(0,25,80001))]);
    gcDenied(static function()use($service,$op){$service->query(['recordId'=>'v3-direct-gift:D1'],$op,gcScope());},'duplicate issuance mapping rejected');
    echo json_encode(['status'=>'PASS','checks'=>$checks,'schema'=>$db,'productionWrites'=>0],JSON_PRETTY_PRINT),PHP_EOL;
}finally{config(array_replace($config,['default'=>$default]),'database');if($created)$original->execute('DROP DATABASE `'.$db.'`');}

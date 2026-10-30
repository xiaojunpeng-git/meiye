<?php
/** 本地只读核对 007 权益资格人口与 010 当前权威人口；仅输出聚合原因。 */
declare(strict_types=1);
$root=getenv('BACKEND_ROOT')?:getcwd();
require $root.'/vendor/autoload.php';
(new think\App($root.'/'))->initialize();
use think\facade\Db;
use app\services\report\OtherReports007ReadServices;
use app\services\report\BasicEntitlementReportServices;
use app\services\report\BasicReportHostServices as Host;
$connection=config('database.connections.mysql');
if(!in_array($connection['hostname']??'', ['mysql','mohe-mysql'],true))throw new RuntimeException('Local only');
Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
Db::execute('SET SESSION TRANSACTION READ ONLY');
$store=(int)(getenv('REPORT_TEST_STORE')?:105);
$context=Host::context([$store],'stores',999901,'platform',false,0,'basic_customer_entitlements');
$day=$context['query_cutoff_date'];$start=microtime(true);
$source=(new BasicEntitlementReportServices())->read('basic_customer_entitlements',[$store],
    ['_report_scope'=>$context,'as_of_date'=>$day]);
$method=new ReflectionMethod(OtherReports007ReadServices::class,'loadRightsPopulation');
$method->setAccessible(true);
$population=$method->invoke(new OtherReports007ReadServices(),[$store]);
$sourceProjects=[];$allSourceProjects=[];
foreach($source['rows'] as $row)if(($row['asset_type']??'')==='卡内项目'){
    $id=(int)$row['project_detail_id'];
    $allSourceProjects[$id]=(float)($row['remaining_times']??0);
    if($allSourceProjects[$id]>0)$sourceProjects[$id]=true;
}
$missing=array_diff_key($population,$sourceProjects);
$counts=['missing'=>count($missing),'member_inactive'=>0,'member_deleted'=>0,
    'member_store_relation_inactive'=>0,'holder_missing'=>0,'holder_closed'=>0,
    'cart_not_standard_project'=>0,'other'=>0];
$missingCartKinds=[];
$ruleComponentLinks=0;
$ruleLinksByKind=[];
$standardOtherReasons=['holder_not_current_store'=>0,'holder_member_changed'=>0,
    'holder_not_started'=>0,'holder_expired'=>0,'rule_component_missing'=>0,
    'v3_project_zero_times'=>0,'v3_card_disabled'=>0,'legacy_marked_writeoff'=>0,
    'legacy_refund_status_three'=>0,'order_user_deleted'=>0,'order_card_upgraded'=>0,
    'order_terminal_action'=>0,'multiple_holders_same_order'=>0,
    'v3_project_not_projected'=>0];
foreach(array_chunk(array_keys($missing),200) as $chunk){
    $cartRows=Db::name('store_order_cart_info')->whereIn('id',$chunk)
        ->field('id,uid,oid,cart_type,product_type,is_writeoff')->select()->toArray();
    $memberIds=array_values(array_unique(array_map('intval',array_column($cartRows,'uid'))));
    $orderIds=array_values(array_unique(array_map('intval',array_column($cartRows,'oid'))));
    $members=Db::name('user')->whereIn('uid',$memberIds)->column('uid,status,is_del,delete_time,belong_store_id','uid');
    $relations=Db::name('store_user')->whereIn('uid',$memberIds)->where('store_id',$store)
        ->column('uid,status','uid');
    $holders=Db::name('user_card_holder')->whereIn('oid',$orderIds)
        ->column('id,uid,oid,is_del,write_surplus_times,store_id,write_start,write_end','oid');
    $orders=Db::name('store_order')->whereIn('id',$orderIds)
        ->column('id,refund_status,is_user_del,card_upgrade_use_oid,terminal_action','id');
    $holderCounts=Db::name('user_card_holder')->whereIn('oid',$orderIds)
        ->group('oid')->column('COUNT(id)','oid');
    $holderIds=array_values(array_unique(array_map('intval',array_column($holders,'id'))));
    $cardStates=$holderIds?Db::name('cashier_v3_card_state')->where('tenant_id','0')
        ->whereIn('card_holder_id',$holderIds)->column('card_status','card_holder_id'):[];
    $ruleDetails=array_fill_keys(array_map('intval',Db::name('cashier_v3_card_rule_component')
        ->where('tenant_id','0')->whereIn('legacy_detail_id',$chunk)->column('legacy_detail_id')),true);
    foreach($cartRows as $cart){
        $uid=(int)$cart['uid'];$order=(int)$cart['oid'];$member=$members[$uid]??null;
        $kind=(int)$cart['cart_type'].':'.(int)$cart['product_type'];
        $missingCartKinds[$kind]=($missingCartKinds[$kind]??0)+1;
        if(isset($ruleDetails[(int)$cart['id']])){
            ++$ruleComponentLinks;
            $ruleLinksByKind[$kind]=($ruleLinksByKind[$kind]??0)+1;
        }
        $reason='other';
        if(!$member || (int)$member['is_del']!==0)$reason='member_deleted';
        elseif((int)$member['status']!==1)$reason='member_inactive';
        elseif(!isset($relations[$uid]) || (int)$relations[$uid]['status']!==1)$reason='member_store_relation_inactive';
        elseif((int)$cart['cart_type']!==2 || (int)$cart['product_type']!==6)$reason='cart_not_standard_project';
        elseif(!isset($holders[$order]))$reason='holder_missing';
        elseif((int)$holders[$order]['is_del']!==0 || (int)$holders[$order]['write_surplus_times']<=0)$reason='holder_closed';
        if($reason==='other'){
            $holder=$holders[$order];$now=time();
            if((int)$holder['store_id']!==$store)$standardOtherReasons['holder_not_current_store']++;
            elseif((int)$holder['uid']!==$uid)$standardOtherReasons['holder_member_changed']++;
            elseif((int)$holder['write_start']>0 && (int)$holder['write_start']>$now)$standardOtherReasons['holder_not_started']++;
            elseif((int)$holder['write_end']>0 && (int)$holder['write_end']<$now)$standardOtherReasons['holder_expired']++;
            elseif(!isset($ruleDetails[(int)$cart['id']]))$standardOtherReasons['rule_component_missing']++;
            elseif(array_key_exists((int)$cart['id'],$allSourceProjects)
                && $allSourceProjects[(int)$cart['id']]<=0)$standardOtherReasons['v3_project_zero_times']++;
            elseif(($cardStates[(int)$holder['id']]??'enabled')!=='enabled')$standardOtherReasons['v3_card_disabled']++;
            elseif((int)$cart['is_writeoff']!==0)$standardOtherReasons['legacy_marked_writeoff']++;
            elseif((int)($orders[$order]['refund_status']??0)===3)$standardOtherReasons['legacy_refund_status_three']++;
            elseif((int)($orders[$order]['is_user_del']??0)!==0)$standardOtherReasons['order_user_deleted']++;
            elseif((int)($orders[$order]['card_upgrade_use_oid']??0)>0)$standardOtherReasons['order_card_upgraded']++;
            elseif((int)($orders[$order]['terminal_action']??0)!==0)$standardOtherReasons['order_terminal_action']++;
            elseif((int)($holderCounts[$order]??0)>1)$standardOtherReasons['multiple_holders_same_order']++;
            else $standardOtherReasons['v3_project_not_projected']++;
        }
        ++$counts[$reason];
    }
}
echo json_encode(['local_only'=>true,'business_writes'=>0,'store_id'=>$store,
    'source_complete'=>$source['complete']??null,'eligible_007'=>count($population),
    'v3_positive_projects'=>count($sourceProjects),'matching_projects'=>count($population)-count($missing),
    'missing_breakdown_first_reason'=>$counts,'missing_cart_kind_counts'=>$missingCartKinds,
    'missing_rule_component_links'=>$ruleComponentLinks,'missing_rule_links_by_cart_kind'=>$ruleLinksByKind,
    'standard_component_other_reasons'=>$standardOtherReasons,
    'elapsed_ms'=>(int)((microtime(true)-$start)*1000)],
    JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
Db::execute('SET SESSION TRANSACTION READ WRITE');

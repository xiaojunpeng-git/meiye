<?php
declare(strict_types=1);
/** Mirror only the five approved imported graphs in a local outer transaction; roll everything back. */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
require dirname(__DIR__,2).'/scripts/010-history-audit/import_receipt_correction.php';
use think\facade\Db;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('local only');
$target=json_decode(file_get_contents('/target.json'),true,512,JSON_THROW_ON_ERROR);$plan=json_decode(file_get_contents('/plan.json'),true,512,JSON_THROW_ON_ERROR);
// Seed only identities admitted by the fixed correction plan; no unrelated historical graph.
$allowedOrders=[];$allowedRequests=[];foreach($plan['updates']as $r)if($r['table']==='cashier_v3_payment_collection_batch')$allowedOrders[]=$r['after']['sales_order_id'];
foreach($plan['inserts']as $rows)foreach($rows as $r)if(isset($r['checkout_request_id']))$allowedRequests[]=$r['checkout_request_id'];
$target['sales']=array_values(array_filter($target['sales'],static function($r)use($allowedOrders){return in_array($r['order_id'],$allowedOrders,true);}));
$target['saleFacts']=array_values(array_filter($target['saleFacts'],static function($r)use($allowedOrders){return in_array($r['order_id'],$allowedOrders,true);}));
$target['requests']=array_values(array_filter($target['requests'],static function($r)use($allowedRequests){return in_array($r['request_id'],$allowedRequests,true);}));
$target['existingPaymentGraphs']['cashier_v3_payment_collection_batch']=array_values(array_filter($target['existingPaymentGraphs']['cashier_v3_payment_collection_batch'],static function($r)use($allowedOrders){return in_array($r['sales_order_id'],$allowedOrders,true);}));
$stores=[];foreach($plan['updates']as $r)$stores[]=(int)$r['after']['store_id'];$stores=array_values(array_unique($stores));
$seed=['cashier_v3_sales_order'=>$target['sales'],'cashier_v3_sale_fact'=>$target['saleFacts'],'cashier_v3_checkout_request'=>$target['requests'],
    'cashier_v3_payment_fact'=>$target['rechargePaymentFacts'],'cashier_v3_payment_collection_batch'=>$target['existingPaymentGraphs']['cashier_v3_payment_collection_batch']];
Db::startTrans();try{
    foreach($seed as $table=>$rows)foreach($rows as $row){unset($row['id']);Db::name($table)->insert($row);}
    $before=bankRepairStats($stores);
    $a=bankRepairApply($plan,'/tmp/r11-bank-repair-dry-'.bin2hex(random_bytes(5)),false);
    if($a['status']!=='dry_run_rolled_back'||bankRepairStats($stores)!==$before)throw new RuntimeException('dry run not rolled back');
    echo "PASS dry run exact cash/refund deltas and rollback\n";
    $a=bankRepairApply($plan,'/tmp/r11-bank-repair-apply-'.bin2hex(random_bytes(5)),true);
    if($a['operations']!==(int)($plan['expectedOperationCount']??13)||!$a['assetHashUnchanged'])throw new RuntimeException('incorrect repair result');
    echo "PASS exact approved operations, assets unchanged, no refund inflation\n";
    $replay=bankRepairApply($plan,'/tmp/r11-unused-audit',true);
    if($replay['status']!=='already_applied'||$replay['operations']!==0)throw new RuntimeException('not idempotent');
    echo "PASS repeat execution performs zero writes\n";
    $bad=$plan;$bad['updates'][0]['after']['collected_amount_cents']++;
    try{bankRepairApply($bad,'/tmp/r11-never-written',true);throw new RuntimeException('changed source accepted');}
    catch(RuntimeException $e){if(strpos($e->getMessage(),'source changed')===false)throw $e;echo "PASS conflicting or partial repair refused\n";}
}finally{Db::rollback();}
echo "BANK_IMPORT_REPAIR_LOCAL_PASS\n";

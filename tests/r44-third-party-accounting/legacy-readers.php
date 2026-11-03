<?php
declare(strict_types=1);
/** 原单兼容收款的本地回滚验证：仅分类变化，资格、金额、范围、下钻和导出均复用同一Reader。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\report\StoreReconciliationReceiptReader;
use app\services\report\StoreReconciliationReportServices;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local only');
$checks=0;$prefix='R44R'.bin2hex(random_bytes(5));$ids=[];
function ok(bool $pass,string $message):void {global $checks;if(!$pass)throw new RuntimeException($message);++$checks;echo "PASS $message\n";}
Db::startTrans();
try {
    $date='2037-01-01';$stamp=strtotime($date.' 12:00:00');
    $template=['pid'=>0,'store_id'=>118,'paid'=>1,'pay_type'=>'weixin','cash_choose'=>4,
        'cash_pay_price'=>'60.00','yue_pay_price'=>'40.00','pay_price'=>'100.00','total_price'=>'100.00',
        'refund_status'=>0,'is_del'=>0,'is_system_del'=>0,'order_type'=>0,
        'add_time'=>$stamp,'pay_time'=>$stamp,'accounting_method'=>'third_party'];
    foreach(['new'=>[],'old'=>['accounting_method'=>''],'balance'=>['pay_type'=>'yue','cash_pay_price'=>'0.00','yue_pay_price'=>'100.00'],
        'mixed'=>['pay_type'=>'combination'],'pending'=>['paid'=>0],'refunded'=>['refund_status'=>1]]as$key=>$changes){
        $row=array_replace($template,$changes);$row['order_id']=$prefix.'-'.$key;
        $row['unique']=md5($row['order_id']);
        $ids[$key]=(int)Db::name('store_order')->insertGetId($row);
    }
    // 原组合付款保留现金60+余额40两行，只允许原查询选中外部实收行。
    foreach([[2,'60.00'],[1,'40.00']]as$part){
        $row=['order_id'=>$ids['mixed'],'active_pay'=>$part[0],'cash_choose'=>4,'price'=>$part[1],'pay_sub_type'=>''];
        Db::name('combination_order')->insert($row);
    }
    $reader=new StoreReconciliationReceiptReader();$methods=$reader->methods();
    $receipts=$reader->receipts([118],$methods,['start_date'=>$date,'end_date'=>$date],'0');
    $found=[];foreach($receipts as$r)if(strpos($r['document_no'],$prefix)===0)$found[$r['document_no']]=$r;
    ok(count($found)===3,'only eligible new old and mixed receipts retained');
    ok($found[$prefix.'-new']['method_code']==='v3_third_party'&&$found[$prefix.'-new']['amount_cents']===6000,'new original keeps external amount under third-party');
    ok($found[$prefix.'-old']['method_code']==='legacy_4'&&$found[$prefix.'-old']['amount_cents']===6000,'old original keeps WeChat classification');
    ok($found[$prefix.'-mixed']['method_code']==='v3_third_party'&&$found[$prefix.'-mixed']['amount_cents']===6000,'combined original excludes internal balance');
    ok(!isset($found[$prefix.'-balance'])&&!isset($found[$prefix.'-pending'])&&!isset($found[$prefix.'-refunded']),'balance pending refund cannot become external cash');
    $other=$reader->receipts([119],$methods,['start_date'=>$date,'end_date'=>$date],'0');
    ok(!array_filter($other,static function($r)use($prefix){return strpos($r['document_no'],$prefix)===0;}),'authorized store boundary remains narrow');
    $byDate=$reader->receipts([118],$methods,['start_date'=>$date,'end_date'=>$date],'0',true);
    ok(array_sum(array_column($byDate,'amount_cents'))===18000,'business-date adapter keeps same conserved external total');
    ok(Db::name('store_order')->where('id',$ids['new'])->value('pay_type')==='weixin'&&(int)Db::name('store_order')->where('id',$ids['new'])->value('cash_choose')===4,'reading classification never rewrites original channel');
    // 用真实Reader读取，不注入伪造汇总，检查下钻与导出的同口径。
    Db::name('system_store')->where('id',118)->update(['salary_status'=>1]);
    $context=['tenant_id'=>'0','visible_store_ids'=>[118],'basic_scope_mode'=>'stores','query_cutoff_date'=>$date,'permissions'=>[],'data_as_of'=>time()];
    $payload=['start_date'=>$date,'end_date'=>$date,'export'=>['scope'=>'query','fields'=>['payment_name','total_amount','store_118']]];
    $service=new StoreReconciliationReportServices();$result=$service->query($context,$payload);
    $rows=array_column($result['rows'],null,'method_code');
    ok($rows['v3_third_party']['total_amount']==='120.00'&&$rows['legacy_4']['total_amount']==='60.00','financial matrix separates new and historical bookkeeping');
    $export=array_column($result['exportRows'],null,'payment_name');
    ok($export['第三方支付']['total_amount']==='120.00','financial export uses same third-party total');
    unset($payload['export']);$payload['detail']=['method_code'=>'v3_third_party','column_key'=>'store_118'];
    $detail=$service->query($context,$payload);
    ok(count($detail['rows'])===2&&array_sum(array_column($detail['rows'],'amount'))==120,'precise drilldown includes only new original receipts');
    $payload['detail']['column_key']='store_119';
    try{$service->query($context,$payload);throw new RuntimeException('outside drilldown accepted');}
    catch(InvalidArgumentException$e){ok(strpos($e->getMessage(),'不属于当前查询')!==false,'outside-store drilldown rejected');}
} catch(Throwable$e){$failed=true;echo 'FAIL '.get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().' '.(method_exists($e,'getDetail')?json_encode($e->getDetail()):'')."\n";}
finally{Db::rollback();}
if(!empty($failed))exit(1);
ok(Db::name('store_order')->whereIn('id',array_values($ids))->count()===0,'original-order fixtures fully rolled back');
echo "R44_ACCOUNTING_LEGACY_CHECKS=$checks\n";

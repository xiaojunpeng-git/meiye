<?php
declare(strict_types=1);
/** 本地事务与纯投影验证；合成事实只在回滚事务内，不操作客户原始金额。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\order\CashierV3GroupPurchaseFinancialProjection;
use app\services\cashier\v3\order\CashierV3MemberOrderAccountingProjection;
use app\services\statistics\PlatformBusinessDashboardServices;
use app\services\report\StoreReconciliationReceiptReader;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local only');
require dirname(__DIR__).'/cashier-v3-payment-collection-authority/php/fixture.php';
$checks=0;
function ok(bool $b,string $m):void{global$checks;if(!$b)throw new RuntimeException($m);++$checks;echo "PASS $m\n";}
function accountingPlan(array $methods):CashierV3PaymentCollectionPlanV1 {
    $a=paymentCollectionLockedAggregate($methods);
    // 为旧权威夹具补齐当前显式契约：无补单、无欠款、无券、无人员分配。
    $a['request'] += ['order_note'=>'','supplement_enabled'=>0,'supplement_reason'=>'','supplement_operator_id'=>0,'supplement_operator_name_snapshot'=>'','supplement_operated_at'=>0,'resumed_hang_order_id'=>''];
    $a['request']['organization_id']='8';
    $a['request']['cash_performance_amount_cents']=array_sum(array_map(static function(array $p):int{return $p['payment_method']==='other_collection'?0:$p['amount_cents'];},$a['payments']));
    foreach($a['lines'] as &$line){
        $line += ['friend_counts_as_customer'=>1,'is_presale'=>0,'inventory_outbound_required'=>1,'debt_amount_cents'=>0,'coupon_user_id'=>0,'coupon_name_snapshot'=>'','coupon_discount_cents'=>0,'coupon_payment_cents'=>0,'configured_cost_cents'=>0,'price_change_reason'=>'','price_changed_by'=>0,'price_changed_by_name_snapshot'=>'','price_changed_at'=>0,'craftsmen_snapshot_json'=>'[]','salespeople_snapshot_json'=>'[]','guide_selections_json'=>'[]','sales_manager_selections_json'=>'[]','manual_labor_fee_cents'=>null,'card_purchase_snapshot_json'=>'{}','detail_remark_snapshot'=>''];
        $authority=['authorityKey'=>$line['authority_key'],'saleClassification'=>'formal_sale','sourceType'=>'product','sourceId'=>201,'catalogSkuId'=>1201,'sourceVersion'=>5,'quantity'=>1,'originalAmountCents'=>$line['original_amount_cents'],'discountAmountCents'=>500,'couponUserId'=>0,'couponNameSnapshot'=>'','couponDiscountCents'=>0,'saleAmountCents'=>$line['sale_amount_cents'],'debtAmountCents'=>0,'sourceNameSnapshot'=>'护理产品','sourceCodeSnapshot'=>'PRODUCT-201','categoryIdSnapshot'=>11,'categoryNameSnapshot'=>'护理产品','configuredCostCents'=>0,'priceChangeReason'=>'','priceChangedBy'=>0,'priceChangedByNameSnapshot'=>'','priceChangedAt'=>0,'serviceObject'=>'','friendCountsAsCustomer'=>1,'isExperience'=>0,'craftsmen'=>[],'salespeople'=>[],'guideSelections'=>[],'salesManagerSelections'=>[]];
        $line['line_fingerprint']=\app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer::fingerprint($authority);
    } unset($line);
    $key=paymentCollectionIdempotency('CHECKOUT',99);$secret='payment-collection-local-secret-32-bytes';
    return CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate($a,paymentCollectionSalesOrder($a,$key,$secret),$key,1785283250,1785283260,1785283261,$secret);
}

$prefix='r44-accounting-'.bin2hex(random_bytes(5));
Db::startTrans();
try {
    $config=new CashierV3BusinessConfigServices();
    $version=(int)Db::name('cashier_v3_payment_method_config')->where('code','third_party')->value('version');
    foreach([['displayName'=>'改名'],['status'=>0]]as$i=>$change){
        try{$config->updateAccountingMethod('third_party',$change+['idempotencyKey'=>$prefix.'-'.$i,'expectedVersion'=>$version],1);throw new RuntimeException('fixed classification changed');}
        catch(think\exception\ValidateException$e){ok(strpos($e->getMessage(),'固定记账方式')!==false,'automatic class cannot be renamed or disabled');}
    }
    $plan=accountingPlan([['third_party',8500]]);
    ok($plan->collections()[0]['payment_method']==='third_party'&&$plan->collections()[0]['payment_method_name_snapshot']==='第三方支付','final collection has canonical code/name');
    ok($plan->batch()['cash_performance_amount_cents']===8500,'only real third-party external funds count');
    ok($plan->fingerprint()===accountingPlan([['third_party',8500]])->fingerprint(),'stable immutable collection plan');
    try{accountingPlan([['third_party',8501]]);throw new RuntimeException('fraction accepted');}
    catch(app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionAuthorityException|app\services\cashier\v3\order\settlement\CashierV3SalesOrderAuthorityException$e){ok(strpos($e->reason(),'whole_yuan')!==false||$e->reason()==='checkout_sales_amount_invalid','fractional yuan rejected at final fact boundary');}
    ok(accountingPlan([['wechat',8500]])->collections()[0]['payment_method']==='wechat','historical actual channel still supported');
    ok(StoreReconciliationReceiptReader::methodCode('third_party')==='v3_third_party','reconciliation assigns distinct canonical third-party column');
    // 成团投影的金额只从原付款与主购买明细取值，独立核对旧/新、余额、商户凭据。
    $o=['id'=>901,'current_store_name'=>'验证店','pay_type'=>'weixin','trade_no'=>'LOCAL-TRACE','pay_time'=>time(),'total_price'=>'100.00','accounting_method'=>'third_party'];
    $e=['o'=>$o,'l'=>['id'=>902,'cart_num'=>1],'paid'=>10000,'cash'=>6000,'balance'=>4000,'debt'=>0,'coupon'=>0,'merchant'=>'LOCAL-MERCHANT'];
    $mapped=['items'=>[['orderItemId'=>902]],'availableActions'=>[]];
    $group=CashierV3GroupPurchaseFinancialProjection::project($mapped,['_groupFinancial'=>$e]);
    ok($group['payment_method']==='第三方支付' && count($group['paymentDetails'])===2,'new group purchase unifies accounting label');
    ok($group['actualReceivedAmount']===60 && $group['amountSummary']['balancePaymentAmount']===40,'group balance never becomes external cash');
    ok($group['paymentDetails'][0]['externalTransactionNo']==='LOCAL-TRACE' && strpos($group['paymentDetails'][0]['remark'],'LOCAL-MERCHANT')!==false,'group gateway evidence retained');
    ok($group['paymentDetails'][1]['paymentMethodCode']==='member_balance','group balance retains funding classification');
    $e['o']['accounting_method']='';$old=CashierV3GroupPurchaseFinancialProjection::project($mapped,['_groupFinancial'=>$e]);
    ok($old['payment_method']==='微信、余额支付','unmarked old group retains original labels');
    $legacy=$o+['paid'=>1,'pay_price'=>'100.00','cash_pay_price'=>'60.00','yue_pay_price'=>'40.00'];
    $newActivity=CashierV3MemberOrderAccountingProjection::project(['paymentDetails'=>[]],['_memberAccounting'=>$legacy]);
    ok($newActivity['paymentDetails'][0]['amount']===60 && $newActivity['paymentDetails'][1]['amount']===40,'other mobile activities read conserved original payment');
    $legacy['cash_pay_price']='99.00';$invalid=CashierV3MemberOrderAccountingProjection::project(['paymentDetails'=>[]],['_memberAccounting'=>$legacy]);
    ok($invalid['paymentDetails']===[],'inconsistent activity amounts cannot fabricate collection');
    // 同一事实读取验证前向到账与退款冲销，列表与导出同口径、组织范围不扩大。
    $sample=Db::name('cashier_v3_payment_fact')->where('status','effective')->find();unset($sample['id']);
    foreach(['forward'=>8500,'reversal'=>-2500]as$direction=>$amount){
        $row=$sample;
        foreach(['fact_id','natural_key','business_event_no','command_idempotency_key','checkout_request_id','order_id','source_line_id','payment_authority_key']as$key)if(array_key_exists($key,$row))$row[$key]=$prefix.'-'.$direction;
        $row['tenant_id']='0';$row['store_id']=175;$row['business_date']='2099-01-01';$row['payment_method']='third_party';$row['amount_cents']=$amount;
        $row['source_document_type']='recharge';$row['fact_direction']=$direction;$row['reversal_of']=$direction==='reversal'?$prefix.'-forward':'';
        $row['immutable_fingerprint']=hash('sha256',json_encode($row));Db::name('cashier_v3_payment_fact')->insert($row);
    }
    $dashboard=new PlatformBusinessDashboardServices();$ts=strtotime('2099-01-01');
    foreach(['cash_performance'=>'85.00','actual_performance'=>'60.00','refund_amount'=>'25.00']as$metric=>$expected){
        ok($dashboard->moneyMetricDetail([175],$metric,$ts,$ts+86399,'',1,20)['total']===$expected,$metric.' uses third-party forward/refund facts');
        ok($dashboard->exportMoneyDetail([175],$metric,$ts,$ts+86399)['total']===$expected,$metric.' export agrees with detail');
    }
    ok($dashboard->moneyMetricDetail([176],'cash_performance',$ts,$ts+86399,'',1,20)['total']==='0.00','fact report stays inside store scope');
} catch(Throwable$e){$failed=true;echo 'FAIL '.get_class($e).' '.$e->getMessage().' '.(method_exists($e,'reason')?$e->reason():'')."\n";}
finally {Db::rollback();}
if(!empty($failed))exit(1);
ok(Db::name('cashier_v3_payment_fact')->where('fact_id','like',$prefix.'%')->count()===0,'report fixtures fully rolled back');
echo "R44_ACCOUNTING_CONTRACT_CHECKS=$checks\n";

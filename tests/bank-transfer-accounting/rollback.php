<?php
declare(strict_types=1);
/** 仅本地事务：验证渠道开关、真实收款计划与事实报表；不产生客户收款或资产变更。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;
use app\services\cashier\v3\member\CashierV3RechargeModule;
use app\services\statistics\PlatformBusinessDashboardServices;
use app\services\report\StoreReconciliationReceiptReader;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('local database only');
require dirname(__DIR__).'/cashier-v3-payment-collection-authority/php/fixture.php';
$checks=0;
function bankCheck(bool $value,string $label):void {global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function bankPlan(array $methods):CashierV3PaymentCollectionPlanV1 {
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
$prefix='r11-bank-'.bin2hex(random_bytes(6));
Db::startTrans();
try {
    Db::name('cashier_v3_payment_method_config')->where('code','bank_transfer')->delete();
    $migration=dirname(__DIR__,2).'/后端代码/database/upgrades/2026-11-01-收银V3银行转账记账方式/02-正式升级.sql';
    $sql=file_get_contents($migration);preg_match('/INSERT INTO.*?;/s',$sql,$match);Db::execute($match[0]);Db::execute($match[0]);
    bankCheck(Db::name('cashier_v3_payment_method_config')->where('code','bank_transfer')->count()===1,'migration is idempotent');
    $config=new CashierV3BusinessConfigServices();
    try{$config->resolveAccountingMethodSnapshot('bank_transfer',true);throw new RuntimeException('disabled accepted');}
    catch(think\exception\ValidateException $e){bankCheck(true,'disabled bank transfer rejected');}
    bankCheck(!in_array('bank_transfer',array_column($config->accountingMethods(true),'code'),true),'other instances default off');
    Db::name('cashier_v3_payment_method_config')->where('code','bank_transfer')->update(['status'=>1]);
    bankCheck($config->resolveAccountingMethodSnapshot('bank_transfer',true)['displayNameSnapshot']==='银行转账','enabled accounting snapshot keeps bank name');
    try{bankPlan([['bank_transfer',8501]]);throw new RuntimeException('fractional final receipt accepted');}
    catch(app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionAuthorityException $e){bankCheck($e->reason()==='payment_collection_bank_transfer_whole_yuan_required','final receipt authority rejects fractional yuan');}
    Db::name('cashier_v3_payment_method_config')->where('code','other_income')->delete();
    Db::name('cashier_v3_payment_method_config')->insert(['code'=>'other_income','default_name'=>'其他收入','display_name'=>'其他收入','status'=>0,'sort'=>75,'version'=>1,'created_at'=>time(),'updated_at'=>time()]);
    try{$config->resolveAccountingMethodSnapshot('other_income',true);throw new RuntimeException('disabled income accepted');}
    catch(think\exception\ValidateException $e){bankCheck(true,'other income is disabled unless this instance enables it');}
    Db::name('cashier_v3_payment_method_config')->where('code','other_income')->update(['status'=>1]);
    $income=bankPlan([['other_income',8500]]);
    bankCheck($income->collections()[0]['payment_method']==='other_income'&&$income->batch()['cash_performance_amount_cents']===8500,'other income creates actual collection and cash performance');
    Db::name('cashier_v3_payment_method_config')->where('code','other_income')->update(['display_name'=>'临时验证名']);
    $renamed=bankPlan([['other_income',8500]]);
    bankCheck($renamed->collections()[0]['payment_method_name_snapshot']==='临时验证名'&&$renamed->batch()['cash_performance_amount_cents']===8500,'Chinese display name does not decide cash eligibility');
    $plan=bankPlan([['bank_transfer',8500]]);
    bankCheck($plan->collections()[0]['payment_method']==='bank_transfer','final collection preserves independent code');
    bankCheck($plan->collections()[0]['payment_method_name_snapshot']==='银行转账','final collection preserves display snapshot');
    bankCheck($plan->batch()['cash_performance_amount_cents']===8500,'bank receipt counts as cash performance');
    bankCheck($plan->fingerprint()===bankPlan([['bank_transfer',8500]])->fingerprint(),'same final snapshot is stable for replay');
    $mixed=bankPlan([['bank_transfer',8000],['other_collection',500]]);
    bankCheck(count($mixed->collections())===1&&$mixed->batch()['cash_performance_amount_cents']===8000,'other collection does not become a bank receipt');
    Db::name('cashier_v3_payment_method_config')->where('code','other_collection')->update(['status'=>0]);
    try{$config->resolveAccountingMethodSnapshot('other_collection',true);throw new RuntimeException('hidden noncash accepted');}
    catch(think\exception\ValidateException $e){bankCheck(true,'disabled other collection rejected at final confirmation');}
    $method=new ReflectionMethod(CashierV3RechargeModule::class,'resolvePaymentLines');$method->setAccessible(true);
    $lines=$method->invoke(new CashierV3RechargeModule(),[['paymentMethod'=>'bank_transfer','amount'=>'85','collectionReference'=>'BANK-TEST']],8500);
    bankCheck($lines[0]['paymentMethod']==='bank_transfer'&&$lines[0]['amountCents']===8500,'recharge accepts integer-yuan bank receipt');
    try{$method->invoke(new CashierV3RechargeModule(),[['paymentMethod'=>'bank_transfer','amount'=>'85.1']],8510);throw new RuntimeException('fraction accepted');}
    catch(app\services\cashier\v3\CashierV3CommandException $e){bankCheck(true,'fractional new receipt rejected');}
    bankCheck(StoreReconciliationReceiptReader::methodCode('bank_transfer')==='legacy_5','reconciliation joins known historical bank channel without relabeling other receipts');
    $sample=Db::name('cashier_v3_payment_fact')->where('status','effective')->limit(1)->find();unset($sample['id']);
    foreach(['forward'=>8500,'reversal'=>-2500] as $direction=>$amount){
        $row=$sample;
        foreach(['fact_id','natural_key','business_event_no','command_idempotency_key','checkout_request_id','order_id','source_line_id','payment_authority_key'] as $key)if(array_key_exists($key,$row))$row[$key]=$prefix.'-'.$direction;
        $row['tenant_id']='0';$row['store_id']=175;$row['business_date']='2099-01-01';$row['payment_method']='bank_transfer';$row['amount_cents']=$amount;
        $row['source_document_type']='recharge';$row['fact_direction']=$direction;$row['reversal_of']=$direction==='reversal'?$prefix.'-forward':'';
        $row['immutable_fingerprint']=hash('sha256',json_encode($row));Db::name('cashier_v3_payment_fact')->insert($row);
    }
    $dashboard=new PlatformBusinessDashboardServices();$ts=strtotime('2099-01-01');
    foreach(['cash_performance'=>'85.00','actual_performance'=>'60.00','refund_amount'=>'25.00'] as $metric=>$expected){
        $result=$dashboard->moneyMetricDetail([175],$metric,$ts,$ts+86399,'',1,20);
        bankCheck($result['total']===$expected,$metric.' uses bank forward/refund facts');
        $export=$dashboard->exportMoneyDetail([175],$metric,$ts,$ts+86399);
        bankCheck($export['total']===$expected,$metric.' export agrees with detail total');
    }
    // Reclassify only synthetic fixtures inside this rollback test to exercise the new code in the same reader.
    Db::name('cashier_v3_payment_fact')->where('fact_id','like',$prefix.'%')->update(['payment_method'=>'other_income']);
    foreach(['cash_performance'=>'85.00','actual_performance'=>'60.00','refund_amount'=>'25.00'] as $metric=>$expected){
        bankCheck($dashboard->moneyMetricDetail([175],$metric,$ts,$ts+86399,'',1,20)['total']===$expected,'other income '.$metric.' uses actual receipt/refund facts');
        bankCheck($dashboard->exportMoneyDetail([175],$metric,$ts,$ts+86399)['total']===$expected,'other income '.$metric.' export agrees');
    }
    bankCheck($dashboard->moneyMetricDetail([176],'cash_performance',$ts,$ts+86399,'',1,20)['total']==='0.00','store range does not leak bank receipts');
} finally {Db::rollback();}
bankCheck(Db::name('cashier_v3_payment_fact')->where('fact_id','like',$prefix.'%')->count()===0,'synthetic receipts fully rolled back');
echo "BANK_TRANSFER_CHECKS=$checks\n";

<?php
// Included by the disposable MySQL harness, never connects independently.
if (!isset($pdo,$reader,$transaction) || getenv('MOHE_QUERY_TEST_DISPOSABLE')!=='yes') throw new RuntimeException('Disposable harness required');
$cashTenant='cash-recharge-fixture';
$cashDay=['start'=>'2026-09-08','end'=>'2026-09-08'];
$cashInsert=function(string $id,int $amount,array $override=[])use($pdo,$cashTenant):void{
    $row=array_merge(['fact_id'=>$id,'tenant_id'=>$cashTenant,'store_id'=>1,'member_id'=>10,'business_date'=>'2026-09-08','status'=>'effective','fact_type'=>'payment_collected','source_document_type'=>'recharge','payment_method'=>'wechat','amount_cents'=>$amount,'order_id'=>'RCH:101','source_line_id'=>$id,'organization_id'=>'org','organization_path_snapshot'=>'org','store_name_snapshot'=>'测试甲店'],$override);
    $sql='INSERT INTO eb_cashier_v3_payment_fact ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
    $pdo->prepare($sql)->execute(array_values($row));
};
$cashTotals=function(?array $range=null,array $stores=[1])use($reader,$cashTenant,$cashDay):array{return $reader->cashTotals($cashTenant,$stores,$range??$cashDay);};
foreach(app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1::paymentMethods() as $index=>$method)$cashInsert('method-'.$index,101,['payment_method'=>$method,'business_source_primary_id'=>7,'business_source_label_snapshot'=>'发生时来源']);
mysqlCheck($cashTotals()===['gross_cents'=>707,'refund_cents'=>0],'cash recharge seven methods counted exactly once');
foreach(['balance','old_card_entry','cash'] as $index=>$method)$cashInsert('excluded-method-'.$index,99999,['payment_method'=>$method]);
$cashInsert('pending',99999,['status'=>'pending']);
$cashInsert('wrong-fact',99999,['fact_type'=>'balance_changed']);
$cashInsert('sale-must-not-double',99999,['source_document_type'=>'sales_order']);
$cashInsert('foreign-tenant',99999,['tenant_id'=>'other-cash-tenant']);
$cashInsert('no-permission-store',99999,['store_id'=>3]);
$cashInsert('outside-date',99999,['business_date'=>'2026-09-06']);
mysqlCheck($cashTotals()===['gross_cents'=>707,'refund_cents'=>0],'cash excludes balance old cards cash pending wrong fact sales tenant store and date');
$cashInsert('later-refund',-205,['business_date'=>'2026-09-09']);
mysqlCheck($cashTotals()===['gross_cents'=>707,'refund_cents'=>0],'refund does not deduct original-day cash');
mysqlCheck($cashTotals(['start'=>'2026-09-09','end'=>'2026-09-09'])===['gross_cents'=>0,'refund_cents'=>-205],'refund belongs to its successful business day');
$cashInsert('void-forward',1234,['order_id'=>'RCH:102']);
$cashInsert('void-reversal',-1234,['order_id'=>'RCH:102']);
$pdo->prepare('INSERT INTO eb_cashier_v3_order_lifecycle_operation VALUES (?,?,?,?,?)')->execute([$cashTenant,'102','recharge','void','succeeded']);
mysqlCheck($cashTotals()===['gross_cents'=>1941,'refund_cents'=>-1234],'signed recharge reversal is the actual cash refund side of the same facts');
$cashInsert('other-tenant-void-safe',20,['order_id'=>'RCH:103']);
$pdo->prepare('INSERT INTO eb_cashier_v3_order_lifecycle_operation VALUES (?,?,?,?,?)')->execute(['other-cash-tenant','103','recharge','void','succeeded']);
mysqlCheck($cashTotals()['gross_cents']===1961,'foreign tenant void cannot hide recharge');
$cashInsert('repaid',500,['order_id'=>'repayment-ok','source_document_type'=>'recharge_debt_repayment']);
$pdo->prepare('INSERT INTO eb_cashier_v3_recharge_debt_repayment VALUES (?,?,?,?,?,?)')->execute([$cashTenant,'repayment-ok',101,1,10,'succeeded']);
mysqlCheck($cashTotals()===['gross_cents'=>2461,'refund_cents'=>-1234],'successful recharge debt repayment is independent positive cash');
foreach(['missing','wrong-store','wrong-member','wrong-tenant','not-succeeded','void-master','void-operation','parent-void'] as $case){
    $id='repayment-'.$case;$cashInsert($id,7777,['order_id'=>$id,'source_document_type'=>'recharge_debt_repayment']);
    if($case==='missing')continue;
    $pdo->prepare('INSERT INTO eb_cashier_v3_recharge_debt_repayment VALUES (?,?,?,?,?,?)')->execute([$case==='wrong-tenant'?'other-cash-tenant':$cashTenant,$id,$case==='parent-void'?102:101,$case==='wrong-store'?2:1,$case==='wrong-member'?11:10,$case==='not-succeeded'?'pending':($case==='void-master'?'voided':'succeeded')]);
    if($case==='void-operation')$pdo->prepare('INSERT INTO eb_cashier_v3_order_center_void_operation VALUES (?,?,?,?)')->execute([$cashTenant,$id,'recharge_supplement','succeeded']);
}
mysqlCheck($cashTotals()===['gross_cents'=>18015,'refund_cents'=>-1234],'invalid repayment bindings fail closed while signed void facts remain authoritative');
$pdo->prepare('INSERT INTO eb_cashier_v3_order_center_void_operation VALUES (?,?,?,?)')->execute(['other-cash-tenant','repayment-ok','recharge_supplement','succeeded']);
$pdo->prepare('INSERT INTO eb_cashier_v3_order_center_void_operation VALUES (?,?,?,?)')->execute([$cashTenant,'repayment-ok','sales_supplement','succeeded']);
mysqlCheck($cashTotals()['gross_cents']===18015,'wrong tenant or source_kind void does not hide valid repayment');
$cashInsert('store-two',300,['store_id'=>2]);
mysqlCheck($cashTotals(null,[1,2])['gross_cents']===18315,'multistore sum includes only authorized recharge scope');
$points=$reader->dailyStoreTotals($cashTenant,[1,2],$cashDay,'cash_performance');
mysqlCheck(array_column($points,'amount_cents')===[18015,300],'daily store cash agrees with recharge-inclusive summary');
$detail=(new app\services\query\metric\RegisteredMetricReadServices())->categoryRows('actual_performance',$cashTenant,[1],$cashDay);
mysqlCheck(array_sum(array_column($detail,'amount_cents'))===16781 && count($detail)===13,'recharge detail explains exact signed net without duplicates');
mysqlCheck(count(array_filter($detail,static function($r){return (int)$r['category_id']!==0;}))===0,'recharge never invents a product category');
mysqlCheck(count(array_filter($detail,static function($r){return (int)$r['business_source_primary_id']===7 && $r['source_label']==='发生时来源';}))===7,'recharge detail preserves frozen business source labels');
$readBoth=$transaction->run(function($r)use($cashTenant,$cashDay){return [$r->cashTotals($cashTenant,[1],$cashDay),$r->dailyStoreTotals($cashTenant,[1],$cashDay,'cash_performance')];});
mysqlCheck($readBoth[0]['gross_cents']===$readBoth[1][0]['amount_cents'],'real repeatable-read recharge gross summary and daily source agree');
foreach(['cashier_v3_payment_fact','cashier_v3_recharge_debt_repayment','cashier_v3_order_lifecycle_operation'] as $engineTable){
    $pdo->exec('ALTER TABLE eb_'.$engineTable.' ENGINE=MyISAM');
    try {mysqlReject(function()use($transaction){$transaction->run(function(){});},'METRIC_READ_ENGINE_UNVERIFIED');}
    finally {$pdo->exec('ALTER TABLE eb_'.$engineTable.' ENGINE=InnoDB');}
}
$wideStores=range(100,229);
foreach($wideStores as $storeId)$cashInsert('wide-'.$storeId,100,['store_id'=>$storeId]);
$wideStarted=microtime(true);
$wide=$reader->cashTotals($cashTenant,$wideStores,$cashDay);
$wideRows=$reader->dailyStoreTotals($cashTenant,$wideStores,$cashDay,'cash_performance');
$wideMs=(int)round((microtime(true)-$wideStarted)*1000);
mysqlCheck($wide['gross_cents']===13000 && count($wideRows)===130 && array_sum(array_column($wideRows,'amount_cents'))===13000,'130-store scope is complete not capped at first stores');
$registeredReader=new app\services\query\metric\RegisteredMetricReadServices();
$cashMethod=new ReflectionMethod($registeredReader,'rechargeCashQuery');$cashMethod->setAccessible(true);
$cashSql=$cashMethod->invoke($registeredReader,$cashTenant,$wideStores,$cashDay)->fieldRaw('SUM(p.amount_cents) total')->fetchSql(true)->find();
$explained=$pdo->query('EXPLAIN '.$cashSql)->fetchAll(PDO::FETCH_ASSOC);
mysqlCheck(count($explained)>0 && in_array('p',array_column($explained,'table'),true),'real MySQL explains recharge guarded query');
echo 'cash-recharge fixture 130-store summary+daily elapsed_ms='.$wideMs.' explain_rows='.count($explained)."; small fixture, not production capacity acceptance\n";
echo "cash-recharge-mysql: PASS (real isolated payment/recharge/repayment/void/permission source tests)\n";

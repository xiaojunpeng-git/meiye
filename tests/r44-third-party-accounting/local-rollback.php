<?php
declare(strict_types=1);
/** 本地真实领域回滚测试：不连接支付供应商、不发送收款、不保留测试资产与订单。 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
use think\facade\Db;
use app\services\member\MemberCommerceServices;
use app\services\member\MemberCommerceScope;
use app\services\member\MemberOrderAccounting;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\cashier\v3\order\CashierV3SalesOrderExportRows;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local ruihao database only');
$checks=0; $prefix='R44-'.bin2hex(random_bytes(8)); $created=[];
function ok(bool $b,string $s):void {global $checks; if(!$b)throw new RuntimeException($s);++$checks;echo "PASS $s\n";}
function denied(callable $f,string $s):void {try{$f();}catch(think\exception\ValidateException|app\services\cashier\v3\CashierV3CommandException $e){ok(true,$s);return;}throw new RuntimeException($s);}
function stored(string $id):array{return Db::name('member_commerce_order')->where('public_id',$id)->find();}
function seedPending(array $template, int $balance, string $marker):array {
    global $prefix,$created;
    unset($template['id']);$template['public_id']='MC-'.bin2hex(random_bytes(12));
    $template['idempotency_key']=$prefix.'-'.bin2hex(random_bytes(6));$template['accounting_method']=$marker;
    $template['sales_order_id']='';$template['document_no']='';$template['transaction_id']='';$template['result_json']='{}';
    $template['status']='pending';$template['balance_cents']=$balance;$template['external_cents']=(int)$template['amount_cents']-$balance;
    $snapshot=json_decode($template['snapshot_json'],true);$snapshot['onlineProvider']='fuyou';$snapshot['onlineMerchantNo']='LOCAL-ONLY';$snapshot['paymentMethod']='wechat';
    $template['snapshot_json']=json_encode($snapshot,JSON_UNESCAPED_UNICODE);
    $template['id']=Db::name('member_commerce_order')->insertGetId($template);$created[]=$template['public_id'];return$template;
}
Db::startTrans();
try {
    $config=new CashierV3BusinessConfigServices();
    ok($config->resolveAccountingMethodSnapshot('third_party',true)['displayNameSnapshot']==='第三方支付','canonical accounting dictionary');
    ok(!in_array('third_party',array_column($config->checkoutCatalog()['accountingMethods'],'code'),true),'manual cashier catalog excludes automatic member classification');
    foreach(['h5','wechat','routine','app']as$c)ok(MemberOrderAccounting::forChannel($c)==='third_party','trusted member channel '.$c);
    foreach(['','admin','cashier']as$c)ok(MemberOrderAccounting::forChannel($c)==='','nonmember channel preserved '.$c);
    ok(MemberOrderAccounting::collectionMethod([], 'wechat')==='wechat','missing historical marker preserves original channel');
    $uid=913081;$store=118;$service=new MemberCommerceServices();
    // 仅事务中的现有本地验收夹具提高余额，结束完整回滚，不修改全局开关。
    Db::name('user')->where('uid',$uid)->update(['now_money'=>'1000000.00','ben_money'=>'1000000.00','give_money'=>'0.00']);
    $input=['store_id'=>$store,'items'=>[['product_id'=>625093,'quantity'=>1]],'salespeople'=>[],
        'payment_mode'=>'balance','idempotency_key'=>$prefix.'-balance'];
    $input['fingerprint']=$service->quote($uid,$input)['fingerprint'];
    $order=$service->create($uid,$input,'h5');$created[]=$order['id'];$row=stored($order['id']);
    ok($row['accounting_method']==='third_party' && $row['status']==='paid','actual member creation freezes classification');
    ok($service->create($uid,$input,'h5')['id']===$order['id'],'creation replay does not duplicate order');
    $scope=MemberCommerceScope::create($uid,$store);$reader=new CashierV3SalesOrderQueryServices();
    $detail=$reader->salesOrderDetail(['orderId'=>$row['sales_order_id']],$scope['operator'],$scope['data']);
    ok($detail['payment_method']==='第三方支付' && $detail['paymentDetails'][0]['paymentMethodCode']==='member_balance','balance order displays common name but preserves balance nature');
    ok((float)$detail['actualReceivedAmount']===0.0,'balance payment does not become cash performance');
    ok(Db::name('cashier_v3_payment_collection')->where('sales_order_id',$row['sales_order_id'])->count()===0,'balance creates no external collection');
    $export=CashierV3SalesOrderExportRows::project($detail,['item_name'=>'','payment_method'=>'']);
    ok(strpos($export[0]['payment_method'],'第三方支付')!==false,'same order reader supplies export accounting name');
    $template=$row;
    foreach(['new-external'=>[0,'third_party'],'new-mixed'=>[10000,'third_party'],'old-pending'=>[0,'']]as$name=>$case){
        $pending=seedPending($template,$case[0],$case[1]);$tx=$prefix.'-'.$name;
        denied(static function()use($service,$pending,$tx){$service->paid($pending['public_id'],(int)$pending['external_cents'],$tx,'alipay','fuyou','LOCAL-ONLY');},$name.' rejects channel substitution');
        denied(static function()use($service,$pending,$tx){$service->paid($pending['public_id'],1,$tx,'wechat','fuyou','LOCAL-ONLY');},$name.' rejects amount mismatch');
        $service->paid($pending['public_id'],(int)$pending['external_cents'],$tx,'wechat','fuyou','LOCAL-ONLY');
        $settled=stored($pending['public_id']);$count=Db::name('cashier_v3_payment_collection')->where('sales_order_id',$settled['sales_order_id'])->count();
        $service->paid($pending['public_id'],(int)$pending['external_cents'],$tx,'wechat','fuyou','LOCAL-ONLY');
        ok($count===1 && Db::name('cashier_v3_payment_collection')->where('sales_order_id',$settled['sales_order_id'])->count()===1,$name.' callback replay has one collection');
        $collection=Db::name('cashier_v3_payment_collection')->where('sales_order_id',$settled['sales_order_id'])->find();
        ok($collection['payment_method']===($case[1]?'third_party':'wechat'),$name.' records correct frozen accounting code');
        ok($collection['external_transaction_no_snapshot']===$tx && json_decode($settled['snapshot_json'],true)['paymentMethod']==='wechat',$name.' retains gateway channel and trade evidence');
        ok((int)$collection['amount_cents']===(int)$pending['external_cents'],$name.' excludes balance from external cash');
        $detail=$reader->salesOrderDetail(['orderId'=>$settled['sales_order_id']],$scope['operator'],$scope['data']);
        ok($detail['payment_method']===($case[1]?'第三方支付':'微信'),$name.' detail classification consistent');
        ok((int)round((float)$detail['actualReceivedAmount']*100)===(int)$pending['external_cents'],$name.' final detail preserves actual received');
        if($case[0])ok($detail['paymentDetails'][1]['fundsNature']==='member_balance' && $detail['paymentDetails'][1]['methodName']==='第三方支付','mixed balance remains separate funds fact');
        denied(static function()use($service,$pending,$tx){$service->paid($pending['public_id'],(int)$pending['external_cents'],$tx.'-OTHER','wechat','fuyou','LOCAL-ONLY');},$name.' rejects second provider transaction');
    }
    // 已确认的充值支付同样经过真实余额入账与事实事务；从不请求支付供应商。
    $rechargeTemplate=$template;$rechargeTemplate['kind']='recharge';$rechargeTemplate['amount_cents']=10000;
    $rechargeSnapshot=json_decode($rechargeTemplate['snapshot_json'],true);
    $rechargeSnapshot['bonusCents']=0;$rechargeSnapshot['packageId']=0;$rechargeSnapshot['giftConfig']=[];
    $rechargeSnapshot['businessSource']=(new \app\services\member\MemberCommerceSettingsServices())->source('recharge');
    $rechargeTemplate['snapshot_json']=json_encode($rechargeSnapshot,JSON_UNESCAPED_UNICODE);
    foreach(['third_party','']as$marker){
        $pending=seedPending($rechargeTemplate,0,$marker);
        $before=MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
        $tx=$prefix.'-recharge-'.($marker?:'old');
        $service->paid($pending['public_id'],10000,$tx,'wechat','fuyou','LOCAL-ONLY');
        $service->paid($pending['public_id'],10000,$tx,'wechat','fuyou','LOCAL-ONLY');
        $settled=stored($pending['public_id']);
        $after=MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
        ok($after-$before===10000,'recharge callback credits principal once '.$marker);
        $facts=Db::name('cashier_v3_payment_fact')->where('order_id',$settled['sales_order_id'])->select()->toArray();
        ok(count($facts)===1 && $facts[0]['payment_method']===($marker?:'wechat'),'recharge fact classification respects create marker '.$marker);
        ok($facts[0]['collection_reference']===$tx && (int)$facts[0]['amount_cents']===10000,'recharge retains payment reference and funds '.$marker);
    }
    // 当前本地项目为零售价，走真实创建与结账；若验收数据变化，明确失败而非跳过。
    $zeroInput=['store_id'=>$store,'items'=>[['product_id'=>625196,'quantity'=>1]],'salespeople'=>[],
        'payment_mode'=>'balance','idempotency_key'=>$prefix.'-zero'];
    $zeroQuote=$service->quote($uid,$zeroInput);ok((int)$zeroQuote['amountCents']===0,'local zero-price fixture');
    $zeroInput['fingerprint']=$zeroQuote['fingerprint'];$zero=$service->create($uid,$zeroInput,'h5');$created[]=$zero['id'];$zeroRow=stored($zero['id']);
    ok($zeroRow['status']==='paid' && $zeroRow['accounting_method']==='third_party','zero order uses frozen classification');
    ok(Db::name('cashier_v3_payment_collection')->where('sales_order_id',$zeroRow['sales_order_id'])->count()===0,'zero order creates no collection');
    $zeroDetail=$reader->salesOrderDetail(['orderId'=>$zeroRow['sales_order_id']],$scope['operator'],$scope['data']);
    ok((float)$zeroDetail['actualReceivedAmount']===0.0 && $zeroDetail['paymentDetails']===[],'zero order has no synthetic received money');
} catch(Throwable $e){echo 'FAIL '.get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().' '.(method_exists($e,'getDetail')?json_encode($e->getDetail()):'')."\n";$failed=true;}
finally {Db::rollback();}
if(!empty($failed))exit(1);
ok(Db::name('member_commerce_order')->whereIn('public_id',$created)->count()===0,'all test orders and related transaction writes rolled back');
echo "R44_ACCOUNTING_CHECKS=$checks\n";

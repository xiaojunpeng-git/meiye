<?php
declare(strict_types=1);

// Rollback-only local integration; retained user acceptance orders are made in UI.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local only');
use think\facade\Db;
use app\services\member\MemberCommerceServices;
use app\services\member\MemberCommerceSettingsServices;

function expectTrue(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $run, string $message): void {
    try { $run(); } catch (\think\exception\ValidateException | \app\services\cashier\v3\CashierV3CommandException $e) { return; }
    throw new RuntimeException($message);
}
$service = new MemberCommerceServices();
$uid = 913081;
$tests = [];
$tests['legacy-list-excludes-technical-carriers'] = static function () use ($uid): void {
    $query = app()->make(\app\dao\order\StoreOrderDao::class)->search([
        'uid'=>$uid,'exclude_member_commerce_carriers_uid'=>$uid]);
    expectTrue((int)$query->whereIn('id',[2327156,2327157])->count() === 0,'member technical carriers must not duplicate formal sales');
};
$tests['whole-yuan-group-allocation'] = static function (): void {
    $method = new ReflectionMethod(\app\services\cashier\v3\fact\CashierV3SaleOnlyFactAssembler::class,'personnelPerformanceAmounts');
    $method->setAccessible(true);
    $people = [['allocationWeight'=>50],['allocationWeight'=>50]];
    $amounts = $method->invoke(null,10100,$people);
    expectTrue(array_sum($amounts) === 10100 && $amounts[0] % 100 === 0 && $amounts[1] % 100 === 0,'stable whole-yuan conserved allocation');
    expectTrue($method->invoke(null,10100,[['allocationWeight'=>0]]) === [0],'zero weight stays zero');
    expectTrue($method->invoke(null,10100,[['allocationWeight'=>50]]) === [5000],'partial weight not expanded');
};
$tests['defaults-version-audit-replay'] = static function (): void {
    $settings = new MemberCommerceSettingsServices();
    $before = $settings->read();
    $input = array_intersect_key($before, array_flip(['purchase_primary_id','purchase_secondary_id','recharge_primary_id','recharge_secondary_id','version']));
    $input['idempotency_key'] = 'local-settings-' . bin2hex(random_bytes(10));
    $saved = $settings->save($input, 1);
    expectTrue((int)$saved['version'] === (int)$before['version'] + 1, 'version must advance');
    expectTrue((int)$settings->read()['version'] === (int)$saved['version'], 'refresh must read database');
    expectTrue((int)$settings->save($input, 1)['version'] === (int)$saved['version'], 'retry must not advance');
    $input['idempotency_key'] .= 'new';
    rejected(static function () use ($settings,$input) { $settings->save($input,1); }, 'stale settings must fail');
};
$tests['no-affiliation-requires-choice'] = static function () use ($service,$uid): void {
    Db::name('user')->where('uid',$uid)->update(['belong_store_id'=>0]);
    $quote = $service->quote($uid,['items'=>[['product_id'=>625196,'quantity'=>1]]]);
    expectTrue(!$quote['canSubmit'] && $quote['storeId'] === 0, 'missing affiliation cannot pick arbitrary store');
};
$tests['balance-issue-idempotency'] = static function () use ($service,$uid): void {
    $before = MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
    $input = ['store_id'=>118,'items'=>[['product_id'=>625196,'quantity'=>1]],'salespeople'=>[],
        'payment_mode'=>'balance','idempotency_key'=>'local-purchase-'.bin2hex(random_bytes(10))];
    $input['fingerprint'] = $service->quote($uid,$input)['fingerprint'];
    $order = $service->create($uid,$input,'h5');
    expectTrue($order['status'] === 'paid' && strpos($order['orderNo'],'SJ-XS') === 0,'member numbering');
    expectTrue($service->create($uid,$input,'h5')['id'] === $order['id'],'retry creates only one order');
    $after = MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
    expectTrue($before - $after === 56600, 'balance must debit exactly once');
    $stored = Db::name('member_commerce_order')->where('public_id',$order['id'])->find();
    $snapshot = json_decode($stored['snapshot_json'],true);
    expectTrue((int)$snapshot['businessSource']['primarySourceId'] > 0,'source ID frozen');
    $header = Db::name('cashier_v3_sales_order')->where('order_id',$stored['sales_order_id'])->find();
    expectTrue((int)Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id',$header['checkout_request_id'])->count() === 0,'no immediate service');
    $scope = \app\services\member\MemberCommerceScope::create($uid,118);
    $reversal = new \app\services\cashier\v3\order\CashierV3SalesOrderReversalServices();
    $source = ['sourceId'=>$header['order_id'],'storeId'=>118,'memberId'=>$uid,
        'checkoutRequestId'=>$header['checkout_request_id'],'amountCents'=>56600];
    $prepared = $reversal->prepare($source,'void-sales-order',[],$scope['data']);
    expectTrue(count($prepared['cards']) === 1,'whole void must include deferred project right');
    $input['items'][0]['quantity'] = 2;
    rejected(static function () use ($service,$uid,$input) { $service->create($uid,$input,'h5'); }, 'changed retry must fail');
    rejected(static function () use ($service,$order) { $service->detail(1,$order['id']); }, 'cross-member read must fail');
};
$tests['combined-confirmed-callback-once'] = static function () use ($service,$uid): void {
    $before = MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
    $input = ['store_id'=>118,'items'=>[['product_id'=>625196,'quantity'=>1]],'salespeople'=>[],
        'payment_mode'=>'combined','balance_yuan'=>'100','idempotency_key'=>'local-combined-'.bin2hex(random_bytes(10))];
    $input['fingerprint'] = $service->quote($uid,$input)['fingerprint'];
    $order = $service->create($uid,$input,'h5');
    expectTrue($order['status'] === 'pending' && $order['externalCents'] === 46600,'freeze residual');
    expectTrue(MemberCommerceServices::heldBalanceCents($uid) >= 10000,'hold balance until confirmed');
    rejected(static function () use ($service,$order) { $service->paid($order['id'],1,'LOCAL-MOCK','wechat','fuyou','LOCAL-MERCHANT'); },'wrong amount must fail');
    $service->paid($order['id'],46600,'LOCAL-MOCK','wechat','fuyou','LOCAL-MERCHANT');
    $service->paid($order['id'],46600,'LOCAL-MOCK','wechat','fuyou','LOCAL-MERCHANT');
    expectTrue($service->detail($uid,$order['id'])['status'] === 'paid','callback settles');
    $after = MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
    expectTrue($before-$after === 10000,'callback balance debit exactly once');
};
$tests['provider-confirmed-failure-releases-hold'] = static function () use ($service,$uid): void {
    $before = MemberCommerceServices::heldBalanceCents($uid);
    // This transition fixture avoids local payment-switch defaults; the
    // provider query is tested separately with signed production responses.
    $publicId = 'MC-' . bin2hex(random_bytes(12));
    $snapshot = ['onlineProvider'=>'suixingpay','onlineMerchantNo'=>'LOCAL-SXF',
        'paymentMethod'=>'wechat','storeName'=>'测试门店','items'=>[]];
    Db::name('member_commerce_order')->insert([
        'public_id'=>$publicId,'member_id'=>$uid,'store_id'=>118,'kind'=>'purchase','channel'=>'routine',
        'idempotency_key'=>'local-sxf-fail-'.bin2hex(random_bytes(10)),
        'input_fingerprint'=>hash('sha256',$publicId),'snapshot_json'=>json_encode($snapshot),
        'amount_cents'=>56600,'balance_cents'=>10000,'external_cents'=>46600,
        'status'=>'payment_unknown','result_json'=>'{}','created_at'=>time(),'updated_at'=>time()]);
    expectTrue(MemberCommerceServices::heldBalanceCents($uid) === $before + 10000,'unknown payment keeps balance hold');
    rejected(static function () use ($service,$publicId) {
        $service->failedByProvider($publicId,'suixingpay','WRONG-MERCHANT');
    },'wrong merchant cannot close payment');
    expectTrue($service->failedByProvider($publicId,'suixingpay','LOCAL-SXF'),'verified failure must close order');
    expectTrue($service->detail($uid,$publicId)['status'] === 'failed','failed order must be visible');
    expectTrue(MemberCommerceServices::heldBalanceCents($uid) === $before,'terminal failure releases balance hold');
    expectTrue($service->failedByProvider($publicId,'suixingpay','LOCAL-SXF'),'failure replay is idempotent');
    rejected(static function () use ($service,$publicId) {
        $service->paid($publicId,46600,'LATE-SUCCESS','wechat','suixingpay','LOCAL-SXF');
    },'failed order cannot settle silently');
};
$tests['cancel-unsubmitted-releases-hold'] = static function () use ($service,$uid): void {
    $before = MemberCommerceServices::heldBalanceCents($uid);
    $input = ['store_id'=>118,'items'=>[['product_id'=>625196,'quantity'=>1]],'salespeople'=>[],
        'payment_mode'=>'combined','balance_yuan'=>'100','idempotency_key'=>'local-cancel-'.bin2hex(random_bytes(10))];
    $input['fingerprint'] = $service->quote($uid,$input)['fingerprint'];
    $order = $service->create($uid,$input,'h5');
    expectTrue($service->cancel($uid,$order['id'])['status'] === 'cancelled','cancel pending intent');
    expectTrue(MemberCommerceServices::heldBalanceCents($uid) === $before,'release pending hold');
    rejected(static function () use ($service,$order) { $service->paid($order['id'],46600,'LOCAL-CANCEL-MOCK','wechat','fuyou','LOCAL-MERCHANT'); },'cancelled intent cannot settle');
};
$tests['recharge-external-only-and-callback'] = static function () use ($service,$uid): void {
    rejected(static function () use ($service,$uid) {
        $service->quote($uid,['store_id'=>118,'kind'=>'recharge','price'=>'100','package_id'=>2147483647]);
    },'arbitrary config ID cannot act as a recharge package');
    $before = MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'));
    $input = ['store_id'=>118,'kind'=>'recharge','price'=>'100','payment_mode'=>'balance',
        'idempotency_key'=>'local-recharge-'.bin2hex(random_bytes(10))];
    $input['fingerprint'] = $service->quote($uid,$input)['fingerprint'];
    rejected(static function () use ($service,$uid,$input) { $service->create($uid,$input,'h5'); },'balance recharge forbidden');
    $input['payment_mode'] = 'fuyou';
    $order = $service->create($uid,$input,'h5');
    $service->paid($order['id'],10000,'LOCAL-RECHARGE-MOCK','wechat','fuyou','LOCAL-MERCHANT');
    $service->paid($order['id'],10000,'LOCAL-RECHARGE-MOCK','wechat','fuyou','LOCAL-MERCHANT');
    $paid = $service->detail($uid,$order['id']);
    expectTrue(strpos($paid['orderNo'],'SJ-CZ') === 0,'recharge numbering');
    expectTrue(MemberCommerceServices::cents((string)Db::name('user')->where('uid',$uid)->value('now_money'))-$before === 10000,'recharge credits once');
    $recharge = Db::name('user_recharge')->where('order_id',$paid['orderNo'])->find();
    expectTrue((int)$recharge['store_id'] === 118 && (int)$recharge['staff_id'] === 0 && (int)$recharge['business_source_primary_id'] > 0,'recharge scope/source/actor');
};
foreach ($tests as $name=>$test) {
    $failed = false;
    Db::startTrans();
    try { $test(); echo 'PASS '.$name.PHP_EOL; }
    catch (Throwable $e) { $failed = true; echo 'FAIL '.$name.' '.get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().PHP_EOL; }
    finally { Db::rollback(); }
    if ($failed) exit(1);
}

<?php
declare(strict_types=1);
/** Real createOrder/DAO/attribution transaction, driven by executed member-page output.
 * Quote calculation and promoter qualification are isolated test adapters. No payment,
 * notification, queued work or permanent fixture is produced; all MySQL writes roll back.
 */
putenv('R61_MODE=library');
require dirname(__DIR__) . '/combination-r61/local-acceptance.php';
// ThinkPHP's default exception renderer exits successfully; a failed business test must not.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->__toString() . "\n");
    exit(1);
});
use think\facade\Db;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderComputedServices;
use app\services\activity\combination\CombinationPromotionServices;
$payloads=json_decode(file_get_contents(__DIR__.'/share-chain-payloads.json'),true,512,JSON_THROW_ON_ERROR);
check(count($payloads)===6,'six H5/MP open/join/unattributed payloads came from executed Vue methods');
$app->instance(StoreOrderComputedServices::class,new class {
    public function computedOrder(...$args):array {return ['total_postage'=>0,'coupon_price'=>0,'first_order_price'=>0,'promotions_price'=>0,'pay_price'=>100,
        'pay_integral'=>0,'pay_postage'=>0,'deduction_price'=>0,'usedIntegral'=>0];}
});
$originalUser=app()->make(app\services\user\UserServices::class);
$app->instance(app\services\user\UserServices::class,new class($originalUser){
    private $original;public function __construct($original){$this->original=$original;}
    public function checkUserPromoter(int $uid,$member):bool{return (int)$member['spread_open']===1&&(int)$member['is_promoter']===1;}
    public function __call($name,$args){return $this->original->$name(...$args);}
});
// Store lookup is still the real local database; bypass only cross-test cache persistence.
$originalStore=app()->make(app\services\store\SystemStoreServices::class);
$app->instance(app\services\store\SystemStoreServices::class,new class($originalStore){
    private $original;public function __construct($original){$this->original=$original;}
    public function getStoreDisposeCache($id,$field){return Db::name('system_store')->where('id',$id)->value($field);}
    public function __call($name,$args){return $this->original->$name(...$args);}
});
Db::startTrans();
try{
    $purchaseStore=seed('system_store',['name'=>'R44购买店','is_show'=>1,'is_del'=>0]);
    $promotionStore=seed('system_store',['name'=>'R44分享店','is_show'=>1,'is_del'=>0]);
    $promoter=seed('user',['nickname'=>'R44链接分享人','status'=>1,'is_del'=>0,'spread_open'=>1,'is_promoter'=>1,'belong_store_id'=>$promotionStore]);
    $globalPromoter=seed('user',['nickname'=>'R44永久推荐人','status'=>1,'is_del'=>0,'spread_open'=>1,'is_promoter'=>1,'belong_store_id'=>$purchaseStore]);
    $buyer=seed('user',['nickname'=>'R44买家','status'=>1,'is_del'=>0,'user_type'=>'h5','spread_uid'=>$globalPromoter,'belong_store_id'=>$purchaseStore]);
    $user=Db::name('user')->where('uid',$buyer)->find();
    foreach($payloads as $case){
        $f=fixture(6,2,'yue');$cart=$f['cart'];$cart['type']=3;$cart['pay_price']=100;$cart['total_price']=100;
        $cart['product_attr_unique']=$cart['productInfo']['attrInfo']['unique'];
        $cartGroup=['cartInfo'=>[$cart],'priceGroup'=>['useCoupon'=>null,'sumPrice'=>100,'totalPrice'=>100,'costPrice'=>0,'storePostage'=>0],
            'deduction'=>['type'=>3,'activity_id'=>$f['cid'],'collate_code_id'=>0,'product_type'=>6],'other'=>[]];
        $svc=app()->make(StoreOrderCreateServices::class);
        $svc->setItem('channel_type',strpos($case['case'],'MP-')===0?'routine':'h5');
        // Exactly the controller's per-request context injection: never inspect buyer.spread_uid.
        $svc->setItem('group_promotion_uid',$case['spid']===101?$promoter:0);
        try{$order=$svc->createOrder($buyer,'r44-chain-'.bin2hex(random_bytes(10)),$cartGroup,0,'weixin',[
            'province'=>'','city'=>'','district'=>'','street'=>'','detail'=>'','real_name'=>'R44买家','phone'=>'19900000000'],
            $user,false,0,'', $case['pinkId'] ? $f['pinkId'] : 0,0,2,$purchaseStore);}
        finally{$svc->reset();}
        $id=(int)$order['id'];$saved=Db::name('store_order')->where('id',$id)->find();
        $snapshot=Db::name('combination_promotion_order')->where('order_id',$id)->find();
        check((int)$saved['store_id']===$purchaseStore&&$saved['accounting_method']==='third_party',$case['case'].' actual create keeps purchasing store and new accounting classification');
        check((int)$snapshot['share_uid']===($case['spid']===101?$promoter:0),$case['case'].' actual create freezes only explicit current link promoter');
        check((int)$snapshot['store_id']===($case['spid']===101?$promotionStore:0),$case['case'].' promotion store is independently frozen, no permanent-referrer fallback');
        check((int)$saved['pink_id']===($case['pinkId']?$f['pinkId']:0),$case['case'].' group join identity reaches actual order');
        check(Db::name('store_order_cart_info')->where('oid',$id)->count()===1,$case['case'].' real order creation writes its cart fact in same transaction');
        (new CombinationPromotionServices())->registerOrderInTx($id,$globalPromoter);
        check(Db::name('combination_promotion_order')->where('order_id',$id)->find()===$snapshot,$case['case'].' replay cannot replace already-frozen attribution');
        check((int)$svc->getItem('group_promotion_uid',0)===0,$case['case'].' per-request context reset prevents next-order leakage');
    }
    echo "R44 SHARE CREATE LOCAL COMPLETE\n";
}finally{Db::rollback();}

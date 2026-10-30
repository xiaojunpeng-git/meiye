<?php

declare(strict_types=1);

/** 次卡跨店来源必须由同会员持卡人、原购买订单和整数分核销共同证明。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\CrossStoreV3CardPairReadServices;

$reader=new CrossStoreV3CardPairReadServices();
$identity=new ReflectionMethod($reader,'identityGap');$identity->setAccessible(true);
$fact=['entitlement_instance_type'=>'card_holder','member_id'=>18,'holder_id'=>31,
    'origin_order_id'=>501,'verified_holder_id'=>31,'holder_member_id'=>18,
    'holder_order_id'=>501,'holder_store_id'=>9,'verified_order_id'=>501,
    'order_member_id'=>18,'origin_store_id'=>9,'paid'=>1,'order_type'=>0,
    'is_del'=>0,'is_system_del'=>0,'refund_status'=>0,
    'actual_entitlement_amount_cents'=>1500];
if($identity->invoke($reader,$fact)!==null)throw new RuntimeException('verified paid card origin');
$wrong=$fact;$wrong['holder_member_id']=19;
if($identity->invoke($reader,$wrong)!=='CROSS_CARD_HOLDER_IDENTITY_UNVERIFIED')
    throw new RuntimeException('member mismatch must fail closed');
$wrong=$fact;$wrong['holder_store_id']=10;
if($identity->invoke($reader,$wrong)!=='CROSS_CARD_ORIGIN_STORE_UNVERIFIED')
    throw new RuntimeException('holder purchase store mismatch must fail closed');
$wrong=$fact;$wrong['refund_status']=2;
if($identity->invoke($reader,$wrong)!=='CROSS_CARD_ORIGIN_ORDER_UNVERIFIED')
    throw new RuntimeException('fully refunded origin cannot authorize settlement');
$wrong=$fact;$wrong['actual_entitlement_amount_cents']='15.01';
try{$identity->invoke($reader,$wrong);throw new RuntimeException('decimal cents accepted');}
catch(LogicException $expected){}
echo "cross-store-v3-card-pair-read: PASS\n";

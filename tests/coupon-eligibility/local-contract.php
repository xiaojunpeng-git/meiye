<?php
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
use think\facade\Db;
use app\services\activity\coupon\StoreCouponEligibilityRule as Rule;
use app\services\activity\coupon\StoreCouponEligibilityServices as Eligibility;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices as Workspace;
set_exception_handler(static function(Throwable $e): void { file_put_contents('php://stderr', $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
$c=Db::connect()->getConfig();
if ($c['hostname'] !== 'mysql' || $c['database'] !== 'ruihao' || getenv('R23_LOCAL_ONLY') !== '1') throw new RuntimeException('local-only guard');
$n=0;
$check=static function(bool $ok,string $name) use (&$n): void { if(!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name."\n"; $n++; };
$reject=static function(array $rule) use ($check): void { try { Rule::validate($rule); } catch(InvalidArgumentException $e){$check(true,$e->getMessage()); return;} throw new RuntimeException('invalid combination accepted'); };
$reject(['usage_target'=>1,'allow_transfer'=>0]);
$reject(['coupon_type'=>3,'new_user_only'=>1]);
$reject(['coupon_type'=>3,'usage_target'=>1,'allow_transfer'=>1]);
$rule=['usage_target'=>1,'new_user_only'=>1,'applicable_type'=>3,'original_recipient_uid'=>100,'issuance_store_id'=>10];
$check(count(Rule::reasons($rule,100,11,true))===3,'all independent reasons are visible');
$check(Rule::reasons($rule,101,10,false)===[],'transferred new recipient in issuing store qualifies');
$check(count(Rule::reasons($rule,101,11,false))===1,'transfer preserves store restriction');
$check(Rule::reasons([],100,11,true)===[],'legacy rules default unchanged');
Db::startTrans();
try {
    $uid=990002300; $now=time(); $service=new Eligibility();
    $check(!$service->hasConsumed($uid),'unused fixture member has no history');
    $user=(array)Db::name('user')->find(); unset($user['uid']); $user['uid']=$uid; $user['phone']=''; $user['account']='R23-'.bin2hex(random_bytes(4)); $user['belong_store_id']=10;
    Db::name('user')->insert($user);
    $check($service->issuance($uid,99,true)['issuance_store_id']===10,'self-claim records belonging store');
    $check($service->issuance($uid,99,false)['issuance_store_id']===99,'gift records actual business store');
    $issue=Db::name('store_coupon_issue')->insertGetId(['title'=>'R23 fixture','coupon_title'=>'R23 fixture','coupon_price'=>100,'use_min_price'=>0,'coupon_type'=>1,'type'=>0,'use_mode'=>0,'category'=>1,'applicable_type'=>3,'coupon_issue_type'=>0,'usage_target'=>1,'new_user_only'=>1,'allow_transfer'=>1,'status'=>1,'is_del'=>0]);
    $id=Db::name('store_coupon_user')->insertGetId(['cid'=>$issue,'uid'=>$uid,'original_recipient_uid'=>100,'issuance_store_id'=>10,'coupon_title'=>'R23 fixture','coupon_price'=>100,'use_min_price'=>0,'start_time'=>$now-10,'end_time'=>$now+86400,'use_time'=>0,'status'=>0,'is_fail'=>0,'add_time'=>$now,'type'=>'send']);
    $workspace=(new ReflectionClass(Workspace::class))->newInstanceWithoutConstructor();
    $method=new ReflectionMethod($workspace,'couponOptions');$method->setAccessible(true);
    $read=static fn(int $store):array=>$method->invoke($workspace,$uid,$store,['threshold'=>100000,'base'=>100000,'cap'=>100000],false,'r23-fixture','line',$id);
    $check(count($read(10)['available'])===1,'eligible coupon selectable');
    $bad=$read(11);$check(!$bad['available'] && count($bad['belowMinimum'])===1,'wrong-store coupon visible but not selectable');
    Db::name('store_coupon_user')->where('id',$id)->update(['end_time'=>$now-1]);
    $bad=$read(11);$check(count($bad['belowMinimum'][0]['unavailableReasons'])===2,'expired plus wrong-store reasons retained');
    Db::name('store_coupon_user')->where('id',$id)->update(['end_time'=>$now+86400]);
    // Selection and transaction rejection must agree in the active member checkout too.
    $member = (new ReflectionClass(\app\services\member\MemberCommerceBenefitsServices::class))->newInstanceWithoutConstructor();
    $memberOptions = new ReflectionMethod($member, 'couponOptions'); $memberOptions->setAccessible(true);
    $memberLines = [['index'=>0,'amountCents'=>100000,'productId'=>1,'masterProductId'=>1,'categoryIds'=>[],'brandId'=>0]];
    $options = $memberOptions->invoke($member, $uid, 11, $memberLines, false);
    $check(count($options)===1 && !$options[0]['canUse'], 'member checkout retains wrong-store coupon with reason');
    try { $service->assertUsable(array_merge($rule,['original_recipient_uid'=>100]),$uid,11); throw new RuntimeException('wrong store accepted'); }
    catch (\think\exception\ValidateException $e) { $check(strpos($e->getMessage(),'门店')!==false,'final authority rejects wrong store'); }
    Db::name('user')->where('uid',$uid)->update(['belong_store_id'=>11]);
    $check((int)Db::name('store_coupon_user')->where('id',$id)->value('issuance_store_id')===10,'member relocation does not change issued coupon store');
    $legacyOptions = app()->make(\app\services\activity\coupon\StoreCouponUserServices::class)->getUsableCouponList($uid,['valid'=>[]],11);
    $check(count($legacyOptions)===1 && !$legacyOptions[0]['canUse'] && count($legacyOptions[0]['unavailableReasons'])>=2,'legacy member selector accumulates product and store reasons');
    // Legacy recharge alone is not consumption, while paid service history is.
    $legacy=(array)Db::name('store_order')->find(); unset($legacy['id']);
    $legacy['uid']=$uid; $legacy['order_id']='R23LEGACY'.bin2hex(random_bytes(6));
    $legacy['unique']=bin2hex(random_bytes(16)); $legacy['paid']=1;
    $legacy['order_type']=1; $legacy['channel_type']=''; $legacy['type']=0;
    $legacyId=Db::name('store_order')->insertGetId($legacy);
    $check(!$service->hasConsumed($uid),'pure legacy recharge keeps new-customer eligibility');
    Db::name('store_order')->where('id',$legacyId)->update(['order_type'=>2]);
    $check($service->hasConsumed($uid),'legacy service consumption disqualifies');
    Db::name('store_order')->where('id',$legacyId)->update(['is_del'=>1]);
    $check($service->hasConsumed($uid),'historical deleted service still prevents new-customer reuse');
    Db::name('store_order')->where('id',$legacyId)->delete();
    // Exercise the real transfer transaction: owner changes, frozen issuance does not.
    $recipient=$user; $recipient['uid']=$uid+1; $recipient['phone']='19900002301';
    $recipient['account']='R23-recipient-'.bin2hex(random_bytes(4));
    Db::name('user')->insert($recipient);
    $transferService=app()->make(\app\services\activity\coupon\StoreCouponUserServices::class);
    $transferService->transferCoupon($uid,(int)$id,'19900002301','R23-transfer-'.bin2hex(random_bytes(8)));
    $moved=Db::name('store_coupon_user')->where('id',$id)->find();
    $check((int)$moved['uid']===$uid+1 && (int)$moved['original_recipient_uid']===100 && (int)$moved['issuance_store_id']===10,'real transfer preserves original recipient and issuing store');
    // Restore only this disposable transaction fixture for the remaining history cases.
    Db::name('store_coupon_user')->where('id',$id)->update(['uid'=>$uid]);
    $sale=(array)Db::name('cashier_v3_sales_order')->where('order_direction','forward')->find();unset($sale['id']);
    $tag='R23-'.bin2hex(random_bytes(8));
    foreach(['order_id','order_no','natural_key','command_idempotency_key','checkout_request_id'] as $field) $sale[$field]=$tag;
    $sale['member_id']=$uid;Db::name('cashier_v3_sales_order')->insert($sale);
    $check($service->hasConsumed($uid),'settled purchase disqualifies across all stores');
    $check(!$service->hasConsumed($uid,$tag,true),'first checkout excludes its own sale');
    $bad=$read(10);$check(!$bad['available'] && count($bad['belowMinimum'])===1,'existing customer coupon still visible');
    Db::name('cashier_v3_sales_order')->where('order_id',$tag)->update(['order_status'=>'voided']);
    $check($service->hasConsumed($uid),'void does not restore new-customer status');
} finally { Db::rollback(); }
echo "$n checks passed; fixture rolled back\n";

<?php
declare(strict_types=1);
namespace app\services\activity\coupon;
use think\facade\Db;
use think\exception\ValidateException;

/** 分享只签发短期凭据；手机号认证后的首次领取与原转赠、归属事件同事务落账。 */
final class CouponShareServices
{
    public function info(int $uid, int $couponId): array
    {
        $coupon = $this->eligible($uid, $couponId, false);
        return $this->summary($coupon) + ['can_transfer' => true];
    }

    /** 分享动作不扣券、不改UID；打开分享菜单也不会提前转出权益。 */
    public function create(int $uid, int $couponId): array
    {
        return Db::transaction(function () use ($uid, $couponId) {
            $coupon = $this->eligible($uid, $couponId, true);
            $token = bin2hex(random_bytes(32)); $now = time();
            Db::name('store_coupon_share')->insert(['token_hash'=>hash('sha256',$token),
                'coupon_user_id'=>$couponId,'sender_uid'=>$uid,'created_at'=>$now,'existing_member_max_uid'=>(int)Db::name('user')->max('uid'),
                'expires_at'=>min((int)$coupon['end_time'],$now+7*86400)]);
            return ['token'=>$token] + $this->summary($coupon);
        });
    }

    /** 凭据行与原券锁串行化多人领取；重复打开仅返回同一接收人的既有结果。 */
    public function claim(int $uid, string $token): array
    {
        if ($uid <= 0 || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new ValidateException('分享链接无效');
        $result = Db::transaction(function () use ($uid,$token) {
            $grant=Db::name('store_coupon_share')->where('token_hash',hash('sha256',$token))->lock(true)->find();
            if (!$grant) throw new ValidateException('分享链接无效');
            if ((int)$grant['sender_uid']===$uid) throw new ValidateException('这是您分享的优惠券，请发送给好友领取');
            $member=Db::name('user')->where('uid',$uid)->lock(true)->find();
            $proof=(new \app\services\user\MemberPhoneResetServices())->state($uid);
            if (!$member || !(int)$member['status'] || (int)$member['is_del'] || !preg_match('/^1[0-9]{10}$/D',(string)$member['phone'])
                || ($proof && !(int)$proof['phone_verified'])) throw new ValidateException('请先授权手机号后领取');
            $coupon=Db::name('store_coupon_user')->where('id',(int)$grant['coupon_user_id'])->lock(true)->find();
            if ((int)$grant['claimed_uid']>0) {
                if ((int)$grant['claimed_uid']!==$uid) throw new ValidateException('这张优惠券已被其他好友领取');
                return $this->summary($coupon) + ['received'=>true,'replayed'=>true];
            }
            if ((int)$grant['expires_at']<time()) throw new ValidateException('分享链接已过期，请联系好友重新分享');
            $this->eligible((int)$grant['sender_uid'],(int)$grant['coupon_user_id'],true);
            $service=app()->make(StoreCouponUserServices::class);
            $target=$service->getTransferTargetByPhone((int)$grant['sender_uid'],(int)$grant['coupon_user_id'],(string)$member['phone']);
            if ((int)$target['uid']!==$uid) throw new ValidateException('手机号对应多个会员，请联系门店核实');
            // 只有本次分享后注册的新会员继承门店，注册默认门店也留变更历史，不能改写老会员归属。
            if ((int)$member['add_time'] >= (int)$grant['created_at']) {
                $storeId=(int)Db::name('user')->where('uid',(int)$grant['sender_uid'])->value('belong_store_id');
                if ($storeId>0) app()->make(\app\services\user\UserBelongStoreServices::class)->assignUnboundStoreForCouponShareInTx($uid,$storeId);
            }
            $service->transferCoupon((int)$grant['sender_uid'],(int)$grant['coupon_user_id'],(string)$member['phone'],
                'CS'.substr(hash('sha256',$token.':'.$uid),0,60));
            Db::name('store_coupon_share')->where('id',(int)$grant['id'])->update(['claimed_uid'=>$uid,'claimed_at'=>time()]);
            return $this->summary($coupon) + ['received'=>true,'replayed'=>false];
        });
        // 事务成功后失效会员缓存，新会员随后进入个人中心即可看到继承的归属。
        app()->make(\app\services\user\UserServices::class)->cacheTag()->clear();
        return $result;
    }

    /** 与手机号转赠共用相同资格；签发和领取均复核，不信任页面传来的金额或归属。 */
    private function eligible(int $uid, int $id, bool $lock): array
    {
        $query=Db::name('store_coupon_user')->where('id',$id)->where('uid',$uid);
        if ($lock) $query->lock(true);
        $coupon=$query->find();
        if (!$coupon || (int)$coupon['status']!==0 || (int)$coupon['is_fail']!==0 || (int)$coupon['use_time']!==0
            || (int)$coupon['end_time']<time()) throw new ValidateException('优惠券已使用、过期或不属于当前会员');
        if ((int)Db::name('store_coupon_issue')->where('id',(int)$coupon['cid'])->value('allow_transfer')!==1
            || Db::name('store_coupon_transfer')->where('coupon_user_id',$id)->count()) throw new ValidateException('该优惠券当前不可转赠');
        return $coupon;
    }

    /** 接收提示使用真实券规则和原有效期，不承诺额外折扣、不延长权益。 */
    private function summary(array $coupon): array
    {
        $issue=Db::name('store_coupon_issue')->where('id',(int)$coupon['cid'])->find() ?: [];
        $price=(string)($coupon['coupon_price']??'0'); $minimum=(string)($coupon['use_min_price']??'0');
        $benefit=(int)($issue['coupon_type']??1)===2 ? '享'.((float)$price/10).'折优惠' : '抵扣'.$price.'元';
        return ['coupon_user_id'=>(int)$coupon['id'],'coupon_id'=>(int)$coupon['cid'],
            'title'=>(string)$coupon['coupon_title'],'coupon_price'=>$price,'use_min_price'=>$minimum,
            'end_time'=>date('Y-m-d H:i:s',(int)$coupon['end_time']),
            'description'=>((float)$minimum>0?'满'.$minimum.'元可用，':'无门槛，').$benefit,
            'rule'=>(string)($issue['rule']??''),'scope_notice'=>'适用门店及商品以券详情为准'];
    }
}

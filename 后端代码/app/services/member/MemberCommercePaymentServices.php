<?php
declare(strict_types=1);
namespace app\services\member;

use app\services\pay\PayServices;
use app\services\pay\SuixingPayGateway;
use app\services\pay\SuixingPayMemberService;
use app\services\wechat\WechatUserServices;
use think\exception\ValidateException;
use think\facade\Db;

/** One external request per order. Unknown outcomes retain their balance hold. */
final class MemberCommercePaymentServices
{
    public function pay(int $uid, string $id): array
    {
        $claim = Db::transaction(function () use ($uid, $id): array {
            Db::name('user')->where('uid', $uid)->lock(true)->find();
            $row = Db::name('member_commerce_order')->where('public_id', $id)->where('member_id', $uid)->lock(true)->find();
            if (!$row || $row['status'] === 'paid' || (int)$row['external_cents'] <= 0) throw new ValidateException('该订单无需在线支付。');
            $session = Db::name('member_commerce_payment_session')->where('commerce_id', $row['id'])->find();
            if ($session) {
                if ($session['status'] === 'ready') return ['response' => json_decode($session['response_json'], true)];
                throw new ValidateException('支付结果待确认，请勿重复付款，请稍后查询订单。');
            }
            if ($row['status'] !== 'pending') throw new ValidateException('订单当前不能支付。');
            $snapshot = json_decode((string)$row['snapshot_json'], true);
            $provider = (string)($snapshot['onlineProvider'] ?? 'fuyou');
            // The provider is frozen in the order snapshot. A config switch
            // cannot redirect an already created order to another merchant.
            $currentMerchant = (string)sys_config($provider === 'suixingpay' ? 'suixingpay_mno' : 'fuyou_id', '');
            if (!empty($snapshot['onlineMerchantNo'])
                && !hash_equals((string)$snapshot['onlineMerchantNo'], $currentMerchant))
                throw new ValidateException('订单原收款商户配置已变化，请联系门店核对。');
            if ($provider === 'suixingpay' && !SuixingPayGateway::ready())
                throw new ValidateException('随行付配置尚未完成，请联系门店。');
            if ($provider === 'fuyou' && !PayServices::fuyouPayReady())
                throw new ValidateException('富友支付配置尚未完成，请联系门店。');
            Db::name('member_commerce_payment_session')->insert(['commerce_id' => $row['id'],
                'response_json' => '{}', 'status' => 'requesting', 'created_at' => time(), 'updated_at' => time()]);
            Db::name('member_commerce_order')->where('id', $row['id'])->update(['status' => 'payment_requested', 'updated_at' => time()]);
            return ['order' => $row];
        });
        if (isset($claim['response'])) return $claim['response'];
        $row = $claim['order'];
        $snapshot = json_decode($row['snapshot_json'], true);
        try {
            if (($snapshot['onlineProvider'] ?? 'fuyou') === 'suixingpay') {
                $response = (new SuixingPayMemberService())->create($row, $uid);
                Db::name('member_commerce_payment_session')->where('commerce_id', $row['id'])->update([
                    'response_json' => json_encode($response), 'status' => 'ready', 'updated_at' => time()]);
                return $response;
            }
            $channel = $row['channel'];
            $method = $snapshot['paymentMethod'];
            $payType = $method === 'alipay' ? 'alipay'
                : ($channel === 'wechat' ? 'weixin' : ($channel === 'routine' ? 'routine' : 'weixinh5'));
            $openid = $channel === 'wechat'
                ? app()->make(WechatUserServices::class)->uidToOpenid($uid, 'wechat') : '';
            $response = app()->make(PayServices::class)->pay($payType, $openid, $row['public_id'],
                MemberCommerceServices::money((int)$row['external_cents']), 'member_commerce', '会员交易');
            Db::name('member_commerce_payment_session')->where('commerce_id', $row['id'])->update([
                'response_json' => json_encode($response), 'status' => 'ready', 'updated_at' => time()]);
            return $response;
        } catch (\Throwable $error) {
            // Only a recorded Fuyou decline is terminal. A timeout or an
            // unconfirmed SuixingPay response must keep the balance hold.
            $attempt = ($snapshot['onlineProvider'] ?? 'fuyou') === 'fuyou'
                ? Db::name('fuyou_payment_attempt')->where('business_order_no', $row['public_id'])->order('id desc')->find()
                : null;
            $status = $attempt && $attempt['status'] === 'failed' ? 'failed' : 'payment_unknown';
            Db::name('member_commerce_order')->where('id', $row['id'])->where('status', 'payment_requested')
                ->update(['status' => $status, 'updated_at' => time()]);
            throw new ValidateException($status === 'failed' ? '支付渠道已拒绝本次支付，请重新下单。' : '支付结果待确认，请稍后查询订单。');
        }
    }
}

<?php
declare(strict_types=1);

namespace app\services\pay;

use app\services\member\MemberCommerceServices;
use app\services\wechat\WechatUserServices;
use think\exception\ValidateException;
use think\facade\Db;

/** Provider-specific mini-program handoff and signed settlement for member orders. */
final class SuixingPayMemberService
{
    /** Create one provider order from the frozen local order and member OpenID. */
    public function create(array $order, int $uid): array
    {
        $snapshot = json_decode((string)$order['snapshot_json'], true);
        if (($snapshot['onlineProvider'] ?? '') !== 'suixingpay' || $order['channel'] !== 'routine'
            || ($snapshot['paymentMethod'] ?? '') !== 'wechat') {
            throw new ValidateException('随行付当前仅支持微信小程序支付。');
        }
        $openid = (string)app()->make(WechatUserServices::class)->uidToOpenid($uid, 'routine');
        if ($openid === '') throw new ValidateException('请先在微信小程序登录。');
        $appid = (string)sys_config('suixingpay_sub_appid');
        if ($appid !== (string)sys_config('routine_appId')) throw new ValidateException('随行付绑定的小程序 AppID 与会员端不一致。');
        $ip = (string)request()->ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidateException('无法识别支付终端 IPv4 地址。');
        }
        $data = ['mno' => (string)($snapshot['onlineMerchantNo'] ?? ''),
            'ordNo' => self::providerOrderNo((string)$order['public_id']),
            'amt' => MemberCommerceServices::money((int)$order['external_cents']),
            'payType' => 'WECHAT', 'payWay' => '03', 'subject' => '会员交易',
            'trmIp' => $ip, 'subAppid' => $appid, 'userId' => $openid,
            'notifyUrl' => $this->callbackUrl()];
        $reply = (new SuixingPayGateway())->request('/order/jsapiScan', $data);
        $payment = (array)($reply['respData'] ?? []);
        if ((string)($reply['code'] ?? '') !== '0000' || (string)($payment['bizCode'] ?? '') !== '0000') {
            throw new ValidateException('随行付未受理本次支付，请查询订单状态。');
        }
        if ((string)($payment['ordNo'] ?? '') !== $data['ordNo'])
            throw new ValidateException('随行付预支付订单号不匹配。');
        $result = ['timestamp' => (string)($payment['payTimeStamp'] ?? ''),
            'nonceStr' => (string)($payment['paynonceStr'] ?? ''),
            'package' => (string)($payment['payPackage'] ?? ''),
            'signType' => (string)($payment['paySignType'] ?? ''),
            'paySign' => (string)($payment['paySign'] ?? '')];
        if (in_array('', $result, true) || ((string)($payment['payAppId'] ?? $appid)) !== $appid) {
            throw new ValidateException('随行付预支付参数无效，请查询订单状态。');
        }
        // Retain provider references in the payment session for reconciliation;
        // the member UI only consumes the five WeChat SDK parameters.
        $result['providerOrderNo'] = (string)($payment['sxfUuid'] ?? '');
        $result['providerUuid'] = (string)($payment['uuid'] ?? '');
        return $result;
    }

    /** Only a verified success callback may create the cashier payment fact. */
    public function notify(array $fields): bool
    {
        if (!SuixingPayGateway::verify($fields, (string)sys_config('suixingpay_platform_public_key'))) return false;
        if ((string)($fields['bizCode'] ?? '') !== '0000'
            || (string)($fields['payType'] ?? '') !== 'WECHAT'
            || !in_array((string)($fields['payWay'] ?? ''), ['', '03'], true)) return false;
        $orderId = self::localOrderNo((string)($fields['ordNo'] ?? ''));
        $transactionId = (string)($fields['transactionId'] ?? '');
        $row = Db::name('member_commerce_order')->where('public_id', $orderId)->find();
        if (!$row || $transactionId === '') return false;
        $snapshot = json_decode((string)$row['snapshot_json'], true);
        if (($snapshot['onlineProvider'] ?? '') !== 'suixingpay'
            || (string)($snapshot['onlineMerchantNo'] ?? '') === ''
            || (string)($snapshot['onlineMerchantNo'] ?? '') !== (string)($fields['mno'] ?? '')
            || ($snapshot['paymentMethod'] ?? '') !== 'wechat'
            || SuixingPayGateway::cents((string)($fields['amt'] ?? '')) !== (int)$row['external_cents']) return false;
        return (new MemberCommerceServices())->paid($orderId, (int)$row['external_cents'],
            $transactionId, 'wechat', 'suixingpay', (string)$fields['mno']);
    }

    /** Query unknown outcomes by the original order number, never re-order. */
    public function reconcile(array $order): void
    {
        $snapshot = json_decode((string)$order['snapshot_json'], true);
        if (($snapshot['onlineProvider'] ?? '') !== 'suixingpay'
            || !in_array($order['status'], ['payment_requested','payment_unknown'], true)) return;
        $reply = (new SuixingPayGateway())->request('/query/tradeQuery', [
            'mno' => (string)($snapshot['onlineMerchantNo'] ?? ''),
            'ordNo' => self::providerOrderNo((string)$order['public_id'])]);
        $data = (array)($reply['respData'] ?? []);
        if (($reply['code'] ?? '') !== '0000' || ($data['bizCode'] ?? '') !== '0000') return;
        if (!in_array((string)($data['tranSts'] ?? ''), ['SUCCESS', 'FAIL', 'CLOSED', 'CANCELED'], true)) return;
        if ((string)($data['ordNo'] ?? '') !== self::providerOrderNo((string)$order['public_id'])
            || (string)($data['payType'] ?? '') !== 'WECHAT'
            || !in_array((string)($data['payWay'] ?? ''), ['', '03'], true)
            || SuixingPayGateway::cents((string)($data['oriTranAmt'] ?? '')) !== (int)$order['external_cents']) {
            throw new ValidateException('随行付查询结果与订单不一致。');
        }
        // A signed terminal failure releases only this order's reservations;
        // an unknown or paying state must remain held until confirmed.
        if ($data['tranSts'] !== 'SUCCESS') {
            (new MemberCommerceServices())->failedByProvider((string)$order['public_id'],
                'suixingpay', (string)$snapshot['onlineMerchantNo']);
            return;
        }
        (new MemberCommerceServices())->paid((string)$order['public_id'], (int)$order['external_cents'],
            (string)($data['transactionId'] ?? ''), 'wechat', 'suixingpay', (string)$snapshot['onlineMerchantNo']);
    }

    private function callbackUrl(): string
    {
        $base = trim((string)sys_config('site_url', ''));
        $parts = parse_url($base);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidateException('随行付回调域名必须配置为 HTTPS。');
        }
        // A copied site_url from another customer's snapshot must not receive
        // this instance's payment callback.
        if (strcasecmp((string)$parts['host'], (string)request()->host(true)) !== 0)
            throw new ValidateException('随行付回调域名与当前客户站点不一致。');
        // Strip a platform /admin path; the signed callback enters public API.
        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/api/pay/suixingnotify';
    }

    /** Tianque ordNo permits letters, digits and underscores, while local
     * member IDs use one hyphen. This reversible mapping is fixed per order. */
    public static function providerOrderNo(string $localId): string
    {
        if (!preg_match('/^MC-[a-f0-9]{24}$/D', $localId)) throw new ValidateException('会员订单号无效。');
        return 'MC_' . substr($localId, 3);
    }

    public static function localOrderNo(string $providerId): string
    {
        if (!preg_match('/^MC_[a-f0-9]{24}$/D', $providerId)) return '';
        return 'MC-' . substr($providerId, 3);
    }
}

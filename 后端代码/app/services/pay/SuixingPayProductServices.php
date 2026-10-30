<?php
declare(strict_types=1);

namespace app\services\pay;

use app\services\order\StoreOrderSuccessServices;
use app\services\wechat\WechatUserServices;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 拼团商品订单的随行付微信小程序支付。渠道尝试先于网络请求落库；未知结果
 * 只查询原渠道单号，不能由重复点击创建第二笔可能扣款的支付。
 */
final class SuixingPayProductServices
{
    /** @return array<string,string> 微信小程序 requestPayment 所需参数 */
    public function create(array $order, int $uid): array
    {
        if (!request()->isRoutine() || !SuixingPayGateway::ready())
            throw new ValidateException('随行付小程序支付尚未就绪。');
        $appid = trim((string)sys_config('suixingpay_sub_appid', ''));
        if ($appid === '' || $appid !== trim((string)sys_config('routine_appId', '')))
            throw new ValidateException('随行付绑定的小程序与当前会员端不一致。');
        $openid = (string)app()->make(WechatUserServices::class)->uidToOpenid($uid, 'routine');
        if ($openid === '') throw new ValidateException('请先在微信小程序登录。');
        $ip = (string)request()->ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
            throw new ValidateException('无法识别支付终端 IPv4 地址。');
        $amount = SuixingPayGateway::cents((string)$order['pay_price']);
        if ($amount <= 0) throw new ValidateException('支付金额必须大于零。');

        $claim = Db::transaction(function () use ($order, $uid, $amount): array {
            $locked = Db::name('store_order')->where('id', (int)$order['id'])->lock(true)->find();
            if (!$locked || (int)$locked['uid'] !== $uid || (int)$locked['type'] !== 3
                || (int)$locked['paid'] !== 0 || (string)$locked['order_id'] !== (string)$order['order_id']
                || SuixingPayGateway::cents((string)$locked['pay_price']) !== $amount)
                throw new ValidateException('拼团订单支付状态已变化，请刷新订单。');
            // 同一拼团订单若已有富友待确认尝试，禁止切换开关后生成第二笔扣款。
            $fuyouPending = Db::name('fuyou_payment_attempt')
                ->where('business_type', 'product')->where('business_order_no', (string)$locked['order_id'])
                ->whereIn('status', ['created', 'pending', 'paid'])->count();
            if ($fuyouPending) throw new ValidateException('原富友支付结果待确认，请先核对原订单，勿重复付款。');
            $last = Db::name('suixingpay_product_payment_attempt')->where('order_id_key', (int)$locked['id'])
                ->order('id desc')->lock(true)->find();
            if ($last && $last['status'] !== 'failed') return ['existing' => $last];
            $attempt = ['order_id_key' => (int)$locked['id'],
                'business_order_no' => (string)$locked['order_id'],
                'channel_order_no' => 'SP_' . bin2hex(random_bytes(12)),
                'merchant_id' => (string)sys_config('suixingpay_mno', ''),
                'amount_cents' => $amount, 'status' => 'created', 'transaction_id' => '',
                'response_code' => '', 'created_at' => time(), 'updated_at' => time()];
            Db::name('suixingpay_product_payment_attempt')->insert($attempt);
            return ['new' => $attempt];
        });
        if (isset($claim['existing'])) {
            $state = $this->reconcile($claim['existing']);
            throw new ValidateException($state === 'paid' ? '该订单已支付，请刷新订单。'
                : '支付结果待确认，请勿重复付款，请稍后查询订单。');
        }
        $attempt = $claim['new'];
        try {
            $reply = (new SuixingPayGateway())->request('/order/jsapiScan', [
                'mno' => $attempt['merchant_id'], 'ordNo' => $attempt['channel_order_no'],
                'amt' => bcdiv((string)$amount, '100', 2), 'payType' => 'WECHAT', 'payWay' => '03',
                'subject' => '拼团订单', 'trmIp' => $ip, 'subAppid' => $appid,
                'userId' => $openid, 'notifyUrl' => $this->callbackUrl(),
            ]);
            $data = (array)($reply['respData'] ?? []);
            if ((string)($reply['code'] ?? '') !== '0000' || (string)($data['bizCode'] ?? '') !== '0000') {
                // A signed business rejection is terminal for this attempt; transport
                // errors and malformed replies remain unknown and may only be queried.
                Db::name('suixingpay_product_payment_attempt')->where('channel_order_no', $attempt['channel_order_no'])
                    ->where('status', 'created')->update(['status' => 'failed',
                        'response_code' => substr((string)($data['bizCode'] ?? $reply['code'] ?? ''), 0, 32),
                        'updated_at' => time()]);
                throw new ValidateException('随行付未受理本次支付，请刷新后重试。');
            }
            if ((string)($data['ordNo'] ?? '') !== $attempt['channel_order_no']
                || (string)($data['payAppId'] ?? $appid) !== $appid)
                throw new ValidateException('随行付预支付订单与当前订单不一致，请查询支付结果。');
            $result = ['timestamp' => (string)($data['payTimeStamp'] ?? ''),
                'nonceStr' => (string)($data['paynonceStr'] ?? ''),
                'package' => (string)($data['payPackage'] ?? ''),
                'signType' => (string)($data['paySignType'] ?? ''),
                'paySign' => (string)($data['paySign'] ?? '')];
            if (in_array('', $result, true)) throw new ValidateException('随行付预支付参数不完整，请查询支付结果。');
            Db::name('suixingpay_product_payment_attempt')->where('channel_order_no', $attempt['channel_order_no'])
                ->where('status', 'created')->update(['status' => 'pending', 'updated_at' => time()]);
            return $result;
        } catch (\Throwable $error) {
            // Do not print gateway data, user identity or signing material into logs.
            Log::warning('拼团随行付支付结果待确认，渠道单号=' . $attempt['channel_order_no'] . '，异常=' . get_class($error));
            throw $error;
        }
    }

    /** 仅验签、商户、订单、金额与交易号均一致后，调用唯一商品订单入账入口。 */
    public function notify(array $fields): bool
    {
        if (!SuixingPayGateway::verify($fields, (string)sys_config('suixingpay_platform_public_key', ''))
            || (string)($fields['bizCode'] ?? '') !== '0000'
            || (string)($fields['payType'] ?? '') !== 'WECHAT'
            || (string)($fields['payWay'] ?? '') !== '03') return false;
        $attempt = Db::name('suixingpay_product_payment_attempt')
            ->where('channel_order_no', (string)($fields['ordNo'] ?? ''))->find();
        if (!$attempt || (string)$attempt['merchant_id'] !== (string)($fields['mno'] ?? '')
            || (string)$attempt['transaction_id'] !== '' && (string)$attempt['transaction_id'] !== (string)($fields['transactionId'] ?? '')
            || SuixingPayGateway::cents((string)($fields['amt'] ?? '')) !== (int)$attempt['amount_cents']) return false;
        return $this->settle($attempt, (string)($fields['transactionId'] ?? ''));
    }

    /** 查询同一渠道单号；只有验签后的成功或明确失败可改变本地尝试状态。 */
    public function reconcile(array $attempt): string
    {
        if ($attempt['status'] === 'paid') return 'paid';
        if ($attempt['status'] === 'failed') return 'failed';
        if (!SuixingPayGateway::credentialsReady()
            || (string)$attempt['merchant_id'] !== (string)sys_config('suixingpay_mno', ''))
            throw new ValidateException('原随行付商户配置已变化，请联系门店核对。');
        $reply = (new SuixingPayGateway())->request('/query/tradeQuery', [
            'mno' => (string)$attempt['merchant_id'], 'ordNo' => (string)$attempt['channel_order_no']]);
        $data = (array)($reply['respData'] ?? []);
        if ((string)($reply['code'] ?? '') !== '0000' || (string)($data['bizCode'] ?? '') !== '0000') return 'pending';
        if ((string)($data['ordNo'] ?? '') !== (string)$attempt['channel_order_no']
            || (string)($data['payType'] ?? '') !== 'WECHAT'
            || !in_array((string)($data['payWay'] ?? ''), ['', '03'], true)
            || SuixingPayGateway::cents((string)($data['oriTranAmt'] ?? '')) !== (int)$attempt['amount_cents'])
            throw new ValidateException('随行付支付查询与原订单不一致，保留待核对。');
        if ((string)($data['tranSts'] ?? '') === 'SUCCESS')
            return $this->settle($attempt, (string)($data['transactionId'] ?? '')) ? 'paid' : 'pending';
        if (in_array((string)($data['tranSts'] ?? ''), ['FAIL', 'CLOSED', 'CANCELED'], true)) {
            Db::name('suixingpay_product_payment_attempt')->where('id', (int)$attempt['id'])
                ->whereIn('status', ['created', 'pending'])->update(['status' => 'failed', 'updated_at' => time()]);
            return 'failed';
        }
        return 'pending';
    }

    private function settle(array $attempt, string $transactionId): bool
    {
        if ($transactionId === '') return false;
        $order = Db::name('store_order')->where('id', (int)$attempt['order_id_key'])->find();
        if (!$order || (string)$order['order_id'] !== (string)$attempt['business_order_no']
            || !in_array((int)$order['type'], [3, 11], true)
            || SuixingPayGateway::cents((string)$order['pay_price']) !== (int)$attempt['amount_cents']) return false;
        if ((int)$order['paid'] === 1 && (string)$order['trade_no'] !== $transactionId) return false;
        if ((int)$order['paid'] === 0 && (int)$order['type'] !== 3) return false;
        try {
            $paid = app()->make(StoreOrderSuccessServices::class)->paySuccess($order, PayServices::WEIXIN_PAY,
                ['trade_no' => $transactionId]);
        } catch (\Throwable $error) {
            // Side effects may fail after paid commits; inspect authoritative state
            // before acknowledging a provider callback, then leave recovery visible.
            Log::warning('拼团随行付支付收口待重试，渠道单号=' . $attempt['channel_order_no'] . '，异常=' . get_class($error));
            $paid = false;
        }
        if (!$paid && !Db::name('store_order')->where('id', (int)$attempt['order_id_key'])
            ->where('paid', 1)->where('trade_no', $transactionId)->count()) return false;
        Db::name('suixingpay_product_payment_attempt')->where('id', (int)$attempt['id'])
            ->update(['status' => 'paid', 'transaction_id' => $transactionId, 'updated_at' => time()]);
        return true;
    }

    private function callbackUrl(): string
    {
        $parts = parse_url(trim((string)sys_config('site_url', '')));
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || strcasecmp((string)$parts['host'], (string)request()->host(true)) !== 0)
            throw new ValidateException('随行付支付回调域名与当前客户站点不一致。');
        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/api/pay/suixingnotify';
    }
}

<?php
declare(strict_types=1);

namespace app\services\pay;

use think\exception\ValidateException;

/**
 * 拼团随行付原路退款适配。申请受理不等于到账；仅验签后的
 * /query/refundQuery REFUNDSUC 可交给统一退款领域完成事实和权益冲销。
 * 协议：https://paas.tianquetech.com/docs/#/api/sqtk 与 #/api/tkjgcx。
 */
final class SuixingPayCombinationRefundGateway
{
    public function merchantId(): string { return trim((string)sys_config('suixingpay_mno', '')); }

    /** 只从已锁定的原支付凭据与退款回执构建请求，不信任页面金额或商户号。 */
    public function apply(array $receipt): void
    {
        $this->assertMerchant($receipt);
        $reply = (new SuixingPayGateway())->request('/order/refund', [
            'mno' => (string)$receipt['merchant_id'],
            'ordNo' => (string)$receipt['refund_no'],
            'origOrderNo' => (string)$receipt['channel_order_no'],
            'amt' => bcdiv((string)$receipt['amount_cents'], '100', 2),
            'refundReason' => mb_substr((string)$receipt['reason'], 0, 80),
        ]);
        $data = (array)($reply['respData'] ?? []);
        if ((string)($reply['code'] ?? '') !== '0000'
            || (string)($data['bizCode'] ?? '') !== '0000')
            throw new ValidateException('随行付退款申请尚未确认，请查询原退款单结果。');
        if ((isset($data['mno']) && (string)$data['mno'] !== (string)$receipt['merchant_id'])
            || (isset($data['origOrderNo']) && (string)$data['origOrderNo'] !== (string)$receipt['channel_order_no']))
            throw new ValidateException('随行付退款申请与原交易不一致，保留处理中。');
    }

    /** @return array{state:string,channel_refund_id:string,channel_hint:string} */
    public function query(array $receipt): array
    {
        $this->assertMerchant($receipt);
        $reply = (new SuixingPayGateway())->request('/query/refundQuery', [
            'mno' => (string)$receipt['merchant_id'], 'ordNo' => (string)$receipt['refund_no']]);
        return self::interpretQuery($reply, $receipt);
    }

    /** 传输层已验签；只把同商户、同退款号、同原单且全额的终态交给退款领域。 */
    private static function interpretQuery(array $reply, array $receipt): array
    {
        $data = (array)($reply['respData'] ?? []);
        if ((string)($reply['code'] ?? '') !== '0000' || (string)($data['bizCode'] ?? '') !== '0000')
            return ['state' => 'pending', 'channel_refund_id' => '',
                'channel_hint' => '随行付暂未确认退款结果，请查询原退款单，勿重复退款'];
        if ((string)($data['ordNo'] ?? '') !== (string)$receipt['refund_no']
            || (isset($data['mno']) && (string)$data['mno'] !== (string)$receipt['merchant_id'])
            || (isset($data['origOrdNo']) && (string)$data['origOrdNo'] !== ''
                && (string)$data['origOrdNo'] !== (string)$receipt['channel_order_no'])
            || (string)($data['payType'] ?? '') !== 'WECHAT'
            || !array_key_exists('payWay', $data)
            || !in_array((string)$data['payWay'], ['', '05'], true))
            throw new ValidateException('随行付退款回执与原支付不一致，保留处理中。');
        // 即使渠道宣告失败，金额不符的回执也不能释放订单使用权或允许重试。
        foreach (['refundAmount', 'totalAmount'] as $amountField) {
            if (isset($data[$amountField]) && (string)$data[$amountField] !== ''
                && SuixingPayGateway::cents((string)$data[$amountField]) !== (int)$receipt['amount_cents'])
                throw new ValidateException('随行付退款回执金额与原订单不一致，保留处理中。');
        }
        $status = (string)($data['tranSts'] ?? '');
        if ($status === 'REFUNDSUC') {
            if (!isset($data['refundAmount'])
                || SuixingPayGateway::cents((string)$data['refundAmount']) !== (int)$receipt['amount_cents'])
                throw new ValidateException('随行付退款金额与申请不一致，保留处理中。');
            if (!isset($data['totalAmount'])
                || SuixingPayGateway::cents((string)$data['totalAmount']) !== (int)$receipt['amount_cents'])
                throw new ValidateException('随行付原交易金额与订单不一致，保留处理中。');
            if ((string)($data['uuid'] ?? '') === '')
                throw new ValidateException('随行付退款流水号缺失，保留处理中。');
            return ['state' => 'confirmed', 'channel_refund_id' => (string)($data['uuid'] ?? ''),
                'channel_hint' => ''];
        }
        if ($status === 'REFUNDFAIL') return ['state' => 'failed',
            'channel_refund_id' => (string)($data['uuid'] ?? ''), 'channel_hint' => ''];
        return ['state' => 'pending', 'channel_refund_id' => (string)($data['uuid'] ?? ''),
            'channel_hint' => '随行付退款处理中，请稍后查询，勿重复退款'];
    }

    private function assertMerchant(array $receipt): void
    {
        if (!SuixingPayGateway::credentialsReady() || $this->merchantId() === ''
            || !hash_equals($this->merchantId(), (string)($receipt['merchant_id'] ?? ''))
            || ($receipt['provider'] ?? '') !== 'suixingpay')
            throw new ValidateException('原随行付退款商户与当前配置不一致。');
    }
}

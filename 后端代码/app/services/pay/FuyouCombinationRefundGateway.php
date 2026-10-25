<?php
declare(strict_types=1);

namespace app\services\pay;

use mohe\services\wechat\HwcPayService;
use think\exception\ValidateException;

/**
 * AggregatePay commonRefund/refundQuery adapter. A successful application is
 * deliberately not a successful refund. Only a signed query closes money facts.
 * Protocol: https://fundwx.fuiou.com/doc/#/aggregatePay/api (2.4 / 2.13).
 */
class FuyouCombinationRefundGateway extends HwcPayService
{
    public function merchantId(): string { return (string)$this->config['mchntCd']; }

    /** Input comes exclusively from the locked participant order/payment mapping. */
    public function apply(array $receipt): void
    {
        $data = $this->base($receipt);
        $data += ['mchnt_order_no' => $receipt['channel_order_no'],
            'refund_order_no' => $receipt['refund_no'],
            'order_type' => $receipt['channel'] === 'alipay' ? 'ALIPAY' : 'WECHAT',
            'total_amt' => (string)$receipt['amount_cents'], 'refund_amt' => (string)$receipt['amount_cents']];
        if (!empty($receipt['settled_on'])) $data['reserved_origi_dt'] = $receipt['settled_on'];
        $data['sign'] = $this->sign($data, ['mchnt_cd','order_type','mchnt_order_no','refund_order_no',
            'total_amt','refund_amt','term_id','random_str','version']);
        $response = $this->postJson('https://aipay-cloud.fuioupay.com/aggregatePay/commonRefund', $data);
        $this->verify($response, ['result_code','mchnt_cd','mchnt_order_no','refund_order_no',
            'reserved_fy_settle_dt','random_str'], $receipt);
        // Even a verified rejection stays queryable; no new refund number on a timeout.
        if ((string)($response['result_code'] ?? '') !== '000000') {
            throw new ValidateException('富友退款申请未确认，请查询原退款单结果');
        }
    }

    /** Returns pending/confirmed/failed; unsigned or mismatched responses never unlock rights. */
    public function query(array $receipt): array
    {
        $data = $this->base($receipt);
        $data['refund_order_no'] = $receipt['refund_no'];
        $data['sign'] = $this->sign($data, ['mchnt_cd','refund_order_no','term_id','random_str','version']);
        $response = $this->postJson('https://aipay-cloud.fuioupay.com/aggregatePay/refundQuery', $data);
        $this->verify($response, ['result_code','result_msg','trans_stat','ins_cd','mchnt_cd',
            'term_id','random_str','order_type','mchnt_order_no','refund_order_no','transaction_id',
            'refund_id','reserved_fy_settle_dt','reserved_fy_trace_no','reserved_refund_amt'], $receipt);
        $state = 'pending';
        if ((string)($response['result_code'] ?? '') === '000000') {
            if (($response['trans_stat'] ?? '') === 'SUCCESS') {
                // The immutable request/no identifies the whole refund; verify amount when returned by the protocol.
                if (isset($response['reserved_refund_amt']) && (string)$response['reserved_refund_amt'] !== ''
                    && (string)$response['reserved_refund_amt'] !== (string)$receipt['amount_cents']) {
                    throw new ValidateException('富友退款金额核对不一致，保留处理中');
                }
                $state = 'confirmed';
            } elseif (($response['trans_stat'] ?? '') === 'PAYERROR') $state = 'failed';
        }
        return ['state' => $state, 'channel_refund_id' => (string)($response['refund_id'] ?? ''),
            'fuyou_refund_trace_no' => (string)($response['reserved_fy_trace_no'] ?? '')];
    }

    /** Merchant affinity is persisted; a later settings change may not reroute old money. */
    private function base(array $receipt): array
    {
        if ($this->merchantId() === '' || $this->config['mchnt_key'] === ''
            || !hash_equals($this->merchantId(), (string)$receipt['merchant_id'])) {
            throw new ValidateException('原退款商户与当前富友配置不一致');
        }
        return ['version' => '1.0', 'mchnt_cd' => $this->merchantId(),
            'term_id' => '88888888', 'random_str' => $this->getRandom(32)];
    }

    private function sign(array $data, array $fields): string
    {
        $values = array_map(static function ($field) use ($data) { return (string)($data[$field] ?? ''); }, $fields);
        $values[] = $this->config['mchnt_key'];
        return md5(implode('|', $values));
    }

    private function verify(array $response, array $fields, array $receipt): void
    {
        if (empty($response['sign']) || !hash_equals($this->sign($response, $fields), strtolower((string)$response['sign']))
            || (string)($response['mchnt_cd'] ?? '') !== (string)$receipt['merchant_id']
            || (string)($response['mchnt_order_no'] ?? '') !== (string)$receipt['channel_order_no']
            || (string)($response['refund_order_no'] ?? '') !== (string)$receipt['refund_no']) {
            throw new ValidateException('富友退款回执校验失败，保留处理中');
        }
    }
}

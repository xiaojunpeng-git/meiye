<?php

namespace mohe\services\wechat;

use EasyWeChat\Factory;
use mohe\exceptions\PayException;
use mohe\services\wechat\config\MiniProgramConfig;
use mohe\services\wechat\config\OfficialAccountConfig;
use think\facade\Db;
use think\facade\Event;
use think\facade\Log;

/**
 * 富友聚合支付。
 *
 * 会员端继续展示“微信支付 / 支付宝支付”，实际收单渠道统一为富友。
 */
class HwcPayService
{
    private const API_PRECREATE = 'https://aipay.fuioupay.com/aggregatePay/preCreate';
    private const API_WECHAT_JSAPI = 'https://aipay.fuioupay.com/aggregatePay/wxPreCreate';
    private const CALLBACK_CHANNEL_WECHAT = 'wechat';
    private const CALLBACK_CHANNEL_ALIPAY = 'alipay';
    private const CALLBACK_EVENT_ALIPAY = 'aliyun';
    private const LEGACY_ORDER_PREFIX = '13883';

    /** @var array<string, string> */
    protected $config = [
        'mchntCd' => '',
        'mchnt_key' => '',
        'fuyou_name' => '',
        'sub_appid' => '',
    ];

    protected function __construct(array $config = [])
    {
        $this->config = array_merge([
            'mchntCd' => (string)sys_config('fuyou_id'),
            'mchnt_key' => (string)sys_config('fuyou_key'),
            'fuyou_name' => (string)sys_config('fuyou_name'),
            'sub_appid' => (string)sys_config('fuyou_sub_appid'),
        ], $config);
    }

    /**
     * Swoole 是长驻进程，不能缓存支付配置实例，否则后台改配置后仍会使用旧密钥。
     */
    public static function instance(array $config = []): self
    {
        return new static($config);
    }

    public function getRandom(int $length): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($characters) - 1;
        $value = '';
        for ($i = 0; $i < $length; $i++) {
            $value .= $characters[random_int(0, $max)];
        }
        return $value;
    }

    /**
     * APP 通过微信 URL Link 打开承载富友付款码的小程序页。
     */
    public function makeUrl(string $qrCode, string $price): string
    {
        $tokenData = Db::name('wechat_accesstoken')->where('id', 1)->find();
        $now = time();
        $token = '';
        if (!empty($tokenData) && (int)$tokenData['expires_time'] > $now + 300) {
            $token = (string)$tokenData['access_token'];
        }

        if ($token === '') {
            $make = app()->make(MiniProgramConfig::class);
            $app = Factory::miniProgram($make->all());
            $tokenAttr = $app->access_token->getToken(true);
            $token = (string)($tokenAttr['access_token'] ?? '');
            if ($token === '') {
                throw new PayException('生成富友支付跳转链接失败');
            }
            $save = [
                'expires_time' => $now + (int)($tokenAttr['expires_in'] ?? 7200),
                'access_token' => $token,
            ];
            if (empty($tokenData)) {
                Db::name('wechat_accesstoken')->insert($save);
            } else {
                Db::name('wechat_accesstoken')->where('id', (int)$tokenData['id'])->update($save);
            }
        }

        $url = 'https://api.weixin.qq.com/wxa/generate_urllink?access_token=' . rawurlencode($token);
        $response = $this->postJson($url, [
            'path' => '/pages/app_pay/index',
            'query' => http_build_query(['qrcode' => $qrCode, 'price' => $price]),
            'env_version' => 'release',
        ]);
        $link = (string)($response['url_link'] ?? '');
        if ($link === '') {
            Log::error('生成富友支付 URL Link 失败：' . (string)($response['errmsg'] ?? '未知错误'));
            throw new PayException('生成富友支付跳转链接失败');
        }
        return $link;
    }

    /**
     * 支付宝展示方式，底层收单仍为富友。
     */
    public function payAlipay(string $orderId, string $price, string $successAction): array
    {
        $this->assertReady(false);
        $post = $this->baseRequest($orderId, $price, $successAction, self::CALLBACK_CHANNEL_ALIPAY);
        $post['order_type'] = 'ALIPAY';
        $post['sign'] = md5(implode('|', [
            $post['mchnt_cd'], $post['order_type'], $post['order_amt'], $post['mchnt_order_no'],
            $post['txn_begin_ts'], $post['goods_des'], $post['term_id'], $post['term_ip'],
            $post['notify_url'], $post['random_str'], $post['version'], $this->config['mchnt_key'],
        ]));

        $result = $this->postJson(self::API_PRECREATE, $post);
        $this->assertSuccessResponse($result, '支付宝支付');
        $url = (string)($result['qr_code'] ?? '');
        if ($url === '') {
            throw new PayException('支付宝支付失败：富友未返回付款链接');
        }
        return ['url_link' => $url];
    }

    /**
     * @param string $type mini|app|wechat
     */
    public function payWxApp(
        string $orderId,
        string $price,
        string $openid,
        string $successAction,
        string $type = 'mini'
    ): array {
        $this->assertReady($type === 'mini' || $type === 'app');
        $post = $this->baseRequest($orderId, $price, $successAction, self::CALLBACK_CHANNEL_WECHAT);
        $url = self::API_WECHAT_JSAPI;

        if ($type === 'mini' || $type === 'app') {
            $url = self::API_PRECREATE;
            $post['sub_appid'] = $this->config['sub_appid'];
            $post['order_type'] = 'FLYPAY';
            $post['sign'] = md5(implode('|', [
                $post['mchnt_cd'], $post['order_type'], $post['order_amt'], $post['mchnt_order_no'],
                $post['txn_begin_ts'], $post['goods_des'], $post['term_id'], $post['term_ip'],
                $post['notify_url'], $post['random_str'], $post['version'], $this->config['mchnt_key'],
            ]));
        } else {
            if ($openid === '') {
                throw new PayException('微信支付失败：缺少会员 OpenID');
            }
            $make = app()->make(OfficialAccountConfig::class);
            $post['sub_appid'] = (string)$make->get('appId');
            $post['trade_type'] = 'JSAPI';
            $post['sub_openid'] = $openid;
            $post['sign'] = md5(implode('|', [
                $post['mchnt_cd'], $post['trade_type'], $post['order_amt'], $post['mchnt_order_no'],
                $post['txn_begin_ts'], $post['goods_des'], $post['term_id'], $post['term_ip'],
                $post['notify_url'], $post['random_str'], $post['version'], $this->config['mchnt_key'],
            ]));
        }

        $result = $this->postJson($url, $post);
        $this->assertSuccessResponse($result, '微信支付');

        if ($type === 'mini') {
            $qrCode = (string)($result['qr_code'] ?? '');
            if ($qrCode === '') {
                throw new PayException('微信支付失败：富友未返回付款码');
            }
            return ['qr_code' => $qrCode, 'app_id' => $this->config['sub_appid']];
        }
        if ($type === 'app') {
            $qrCode = (string)($result['qr_code'] ?? '');
            if ($qrCode === '') {
                throw new PayException('微信支付失败：富友未返回付款码');
            }
            return ['url_link' => $this->makeUrl($qrCode, $price)];
        }

        $required = ['sdk_appid', 'sdk_timestamp', 'sdk_noncestr', 'sdk_package', 'sdk_signtype', 'sdk_paysign'];
        foreach ($required as $field) {
            if (empty($result[$field])) {
                throw new PayException('微信支付失败：富友返回参数不完整');
            }
        }
        return [
            'app_id' => $result['sdk_appid'],
            'timestamp' => $result['sdk_timestamp'],
            'nonceStr' => $result['sdk_noncestr'],
            'package' => $result['sdk_package'],
            'signType' => $result['sdk_signtype'],
            'paySign' => $result['sdk_paysign'],
        ];
    }

    /**
     * 富友异步通知。只有验签与本地幂等入账都成功才返回 true。
     */
    public function notify(array $data, string $channel, string $successAction): bool
    {
        // 关闭新支付入口后，已发起订单的异步通知仍必须可验签、可入账。
        $this->assertCredentials(false);
        $channel = strtolower(trim($channel));
        $successAction = $this->normalizeAction($successAction);
        if (!in_array($channel, [self::CALLBACK_CHANNEL_WECHAT, self::CALLBACK_CHANNEL_ALIPAY], true)) {
            Log::error('富友支付回调渠道非法');
            return false;
        }

        $required = [
            'result_code', 'mchnt_cd', 'mchnt_order_no', 'settle_order_amt', 'order_amt',
            'txn_fin_ts', 'reserved_fy_settle_dt', 'random_str', 'transaction_id', 'sign',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '') {
                Log::error('富友支付回调缺少字段：' . $field);
                return false;
            }
        }
        if ((string)$data['result_code'] !== '000000') {
            Log::error('富友支付失败回调，订单号：' . $this->maskOrderId((string)$data['mchnt_order_no']));
            return false;
        }
        if (!hash_equals($this->config['mchntCd'], (string)$data['mchnt_cd'])) {
            Log::error('富友支付回调商户号不匹配');
            return false;
        }
        if (!ctype_digit((string)$data['order_amt']) || (int)$data['order_amt'] <= 0) {
            Log::error('富友支付回调金额非法');
            return false;
        }

        $expectedSign = md5(implode('|', [
            $data['mchnt_cd'], $data['mchnt_order_no'], $data['settle_order_amt'], $data['order_amt'],
            $data['txn_fin_ts'], $data['reserved_fy_settle_dt'], $data['random_str'], $this->config['mchnt_key'],
        ]));
        if (!hash_equals(strtolower($expectedSign), strtolower((string)$data['sign']))) {
            Log::error('富友支付回调签名不正确，订单号：' . $this->maskOrderId((string)$data['mchnt_order_no']));
            return false;
        }

        $orderId = $this->normalizeOrderId((string)$data['mchnt_order_no']);
        if (!$this->amountMatchesOrder($successAction, $orderId, (string)$data['order_amt'])) {
            Log::error('富友支付回调金额与本地订单不一致，订单号：' . $this->maskOrderId($orderId));
            return false;
        }
        $tradeNo = (string)$data['transaction_id'];
        $notify = [
            'attach' => $successAction,
            'out_trade_no' => $orderId,
            'transaction_id' => $tradeNo,
            'trade_no' => $tradeNo,
            'order_amt' => (string)$data['order_amt'],
        ];
        $eventChannel = $channel === self::CALLBACK_CHANNEL_ALIPAY
            ? self::CALLBACK_EVENT_ALIPAY
            : self::CALLBACK_CHANNEL_WECHAT;

        return (bool)Event::until('pay.notify', [$notify, $eventChannel]);
    }

    /**
     * 兼容修复前已生成的单参数回调地址。
     */
    public function notifyLegacy(array $data, string $successAction): bool
    {
        $orderType = strtoupper((string)($data['order_type'] ?? ''));
        $channel = $orderType === 'ALIPAY'
            ? self::CALLBACK_CHANNEL_ALIPAY
            : self::CALLBACK_CHANNEL_WECHAT;
        return $this->notify($data, $channel, $successAction);
    }

    /** @return array<string, string> */
    private function baseRequest(string $orderId, string $price, string $successAction, string $channel): array
    {
        $orderId = trim($orderId);
        if ($orderId === '' || strlen($orderId) > 32 || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
            throw new PayException('富友支付订单号不合法');
        }
        $amount = bcmul($price, '100', 0);
        if (bccomp($amount, '0', 0) <= 0) {
            throw new PayException('富友支付金额必须大于0');
        }

        return [
            'version' => '1.10',
            'mchnt_cd' => $this->config['mchntCd'],
            'random_str' => $this->getRandom(32),
            'order_amt' => $amount,
            'mchnt_order_no' => $orderId,
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $this->config['fuyou_name'],
            'term_id' => $this->getRandom(8),
            'term_ip' => (string)app('request')->ip(),
            'notify_url' => $this->callbackUrl($channel, $successAction),
        ];
    }

    private function callbackUrl(string $channel, string $successAction): string
    {
        $successAction = $this->normalizeAction($successAction);
        $make = app()->make(MiniProgramConfig::class);
        $baseUrl = rtrim((string)$make->getConfig(DefaultConfig::COMMENT_URL), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new PayException('富友支付回调域名未配置');
        }
        return $baseUrl . '/api/pay/fynotify/' . rawurlencode($channel) . '/' . rawurlencode($successAction);
    }

    private function normalizeAction(string $successAction): string
    {
        $successAction = strtolower(trim($successAction));
        // 兼容旧版会员购买曾生成的 member_recharge 回调地址。
        if ($successAction === 'member_recharge') {
            $successAction = 'member';
        }
        $allowed = ['product', 'user_recharge', 'member', 'debt_repay'];
        if (!in_array($successAction, $allowed, true)) {
            throw new PayException('富友支付回调业务类型不支持');
        }
        return $successAction;
    }

    private function normalizeOrderId(string $orderId): string
    {
        if (strpos($orderId, self::LEGACY_ORDER_PREFIX) === 0) {
            return substr($orderId, strlen(self::LEGACY_ORDER_PREFIX));
        }
        return $orderId;
    }

    private function assertReady(bool $requireSubAppId): void
    {
        if ((int)sys_config('fuyou_pay_status', 0) !== 1) {
            throw new PayException('富友支付未开启');
        }
        $this->assertCredentials($requireSubAppId);
    }

    private function assertCredentials(bool $requireSubAppId): void
    {
        $required = [
            'mchntCd' => '富友商家ID',
            'mchnt_key' => '富友支付Key',
            'fuyou_name' => '支付名称',
        ];
        if ($requireSubAppId) {
            $required['sub_appid'] = '富友sub_appid';
        }
        foreach ($required as $key => $label) {
            if (trim((string)$this->config[$key]) === '') {
                throw new PayException($label . '未配置');
            }
        }
    }

    private function amountMatchesOrder(string $successAction, string $orderId, string $amountInCents): bool
    {
        switch ($successAction) {
            case 'user_recharge':
                $price = Db::name('user_recharge')->where('order_id', $orderId)->value('price');
                break;
            case 'member':
                $price = Db::name('other_order')->where('order_id', $orderId)->value('pay_price');
                break;
            case 'product':
            case 'debt_repay':
                $price = Db::name('store_order')
                    ->where(function ($query) use ($orderId) {
                        $query->where('order_id', $orderId)->whereOr('unique', $orderId);
                    })
                    ->value('pay_price');
                break;
            default:
                return false;
        }
        if ($price === null || $price === '') {
            return false;
        }
        return hash_equals((string)bcmul((string)$price, '100', 0), $amountInCents);
    }

    private function assertSuccessResponse(array $result, string $label): void
    {
        if ((string)($result['result_code'] ?? '') === '000000') {
            return;
        }
        $message = (string)($result['resultMsg'] ?? $result['result_msg'] ?? '富友接口返回失败');
        Log::error($label . '失败：' . $message);
        throw new PayException($label . '失败：' . $message);
    }

    /** @return array<string, mixed> */
    protected function postJson(string $url, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new PayException('富友支付请求参数编码失败');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($json),
            ],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            Log::error('富友支付网络请求失败：' . $error);
            throw new PayException('富友支付网络请求失败');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error('富友支付接口 HTTP 状态异常：' . $httpCode);
            throw new PayException('富友支付接口暂不可用');
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            throw new PayException('富友支付接口返回格式错误');
        }
        return $result;
    }

    private function maskOrderId(string $orderId): string
    {
        if (strlen($orderId) <= 8) {
            return '***';
        }
        return substr($orderId, 0, 4) . '***' . substr($orderId, -4);
    }
}

<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\services\pay;

use mohe\services\wechat\HwcPayService;
use mohe\services\wechat\Payment;
use think\exception\ValidateException;

/**
 * 支付统一入口
 * Class PayServices
 * @package app\services\pay
 */
class PayServices
{
    /**
     * 微信支付类型
     */
    const WEIXIN_PAY = 'weixin';

    /**
     * 余额支付
     */
    const YUE_PAY = 'yue';
    //组合支付

    const COMBINATION_PAY= 'combination';

	/**
	 * 积分支付
	 */
	const INTEGRAL_PAY = 'integral';

    /**
     * 线下支付
     */
    const OFFLINE_PAY = 'offline';

    /**
     * 支付宝
     */
    const ALIAPY_PAY = 'alipay';

    /**
     * 现金支付
     */
    const CASH_PAY = 'cash';

	/**
	 * 支付方式
	 * @var string[]
	 */
	const PAY_TYPE = [
		PayServices::WEIXIN_PAY => '微信支付',
		PayServices::YUE_PAY => '余额支付',
		PayServices::OFFLINE_PAY => '线下支付',
		PayServices::ALIAPY_PAY => '支付宝',
		PayServices::CASH_PAY => '记账收款',
		PayServices::INTEGRAL_PAY => '积分支付',
		PayServices::COMBINATION_PAY => '组合支付',
	];

    /**
     * 二维码条码值
     * @var string
     */
    protected $authCode;

    /**
     * 会员端在线支付统一由富友收单。
     *
     * 前端仍沿用 pay_weixin_open / ali_pay_status 两个兼容字段展示“微信支付、支付宝支付”，
     * 因此这里提供唯一的就绪口径，避免各业务页面继续读取已经停用的原生支付配置。
     */
    public static function fuyouPayReady(): bool
    {
        if ((int)sys_config('fuyou_pay_status', 0) !== 1) {
            return false;
        }
        foreach (['fuyou_id', 'fuyou_key', 'fuyou_name', 'fuyou_sub_appid'] as $key) {
            if (trim((string)sys_config($key, '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 设置二维码条码值
     * @param string $authCode
     * @return $this
     */
    public function setAuthCode(string $authCode)
    {
        $this->authCode = $authCode;
        return $this;
    }

    /**
     * 发起支付
     * @param string $payType
     * @param string $openid
     * @param string $orderId
     * @param string $price
     * @param string $successAction
     * @param string $body
     * @return array|string
     */
    public function pay(string $payType, string $openid, string $orderId, string $price, string $successAction, string $body, bool $isCode = false)
    {
        try {
			$body = filter_emoji($body);
            switch ($payType) {
                case 'routine':
                    if (request()->isApp()) {
                        return HwcPayService::instance()->payWxApp($orderId, $price, $openid, $successAction, 'app');
                    }
                    return HwcPayService::instance()->payWxApp($orderId, $price, $openid, $successAction, 'mini');
                case 'weixinh5':
                    // 普通浏览器没有公众号 OpenID，使用富友付款码并跳转承载小程序。
                    return HwcPayService::instance()->payWxApp($orderId, $price, $openid, $successAction, 'app');
                case self::WEIXIN_PAY:
                    // 门店付款码属于另一套收银场景，保留既有扫码能力；会员在线支付禁止回退原生接口。
                    if ($this->authCode) {
                        return Payment::microPay($this->authCode, $orderId, $price, $successAction, $body);
                    }
                    $type = request()->isApp() ? 'app' : 'wechat';
                    return HwcPayService::instance()->payWxApp($orderId, $price, $openid, $successAction, $type);
                case self::ALIAPY_PAY:
                    if ($this->authCode) {
                        throw new ValidateException('富友支付宝付款码收款暂未配置');
                    }
                    return HwcPayService::instance()->payAlipay($orderId, $price, $successAction);
                case 'pc':
                case 'store':
                    //方法内部已经做了区分v2和v3
                    return Payment::nativePay($openid, $orderId, $price, $successAction, $body);
                default:
                    throw new ValidateException('支付方式不存在');
            }
        } catch (\Throwable $e) {
            throw new ValidateException($e->getMessage());
        }
    }
}

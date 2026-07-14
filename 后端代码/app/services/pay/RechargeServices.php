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


use app\services\user\UserRechargeServices;
use app\services\wechat\WechatUserServices;
use think\exception\ValidateException;

/**
 *
 * Class RechargeServices
 * @package app\services\pay
 */
class RechargeServices
{
    protected $pay;

    /**
     * RechargeServices constructor.
     * @param PayServices $pay
     */
    public function __construct(PayServices $pay)
    {
        $this->pay = $pay;
    }

	/**
	 * 储值订单支付
	 * @param string $order_id
	 * @param string $authCode
	 * @param string $payType
	 * @return array|string
	 */
    public function recharge(string $order_id, string $authCode = '', string $payType = '')
    {
        /** @var UserRechargeServices $rechargeServices */
        $rechargeServices = app()->make(UserRechargeServices::class);
		$recharge = $rechargeServices->getOne(['order_id' => $order_id]);
        if (!$recharge) {
            throw new ValidateException('订单失效或者不存在');
        }
        if ($recharge['paid'] == 1) {
            throw new ValidateException('订单已支付');
        }
		$payType = $payType ?: $recharge['recharge_type'];
        $openid = '';
        //没有付款码，不是微信H5支付，门店支付，PC支付，不再APP端，需要判断用户openid
        if (!$authCode && !in_array($payType, ['weixinh5', 'store', 'pc', 'alipay']) && !request()->isApp()) {
            $userType = '';
            switch ($payType) {
                case 'weixin':
                case 'weixinh5':
                    $userType = 'wechat';
                    break;
                case 'routine':
                    $userType = 'routine';
                    break;
            }
            if (!$userType) {
                throw new ValidateException('不支持该类型方式');
            }
            /** @var WechatUserServices $wechatUser */
            $wechatUser = app()->make(WechatUserServices::class);
            $openid = $wechatUser->uidToOpenid((int)$recharge['uid'], $userType);
            if (!$openid) {
                throw new ValidateException('获取用户openid失败,无法支付');
            }
        }
		$successAction = "user_recharge";
		$site_name = sys_config('site_name');
		$body = substrUTf8($site_name . '--会员储值', 30);
        return $this->pay->setAuthCode($authCode)->pay($payType, $openid, $recharge['order_id'], $recharge['price'], $successAction, $body);
    }

}

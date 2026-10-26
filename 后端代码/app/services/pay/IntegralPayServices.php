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

namespace app\services\pay;

use app\services\BaseServices;
use app\services\user\UserBillServices;
use app\services\user\UserServices;
use think\exception\ValidateException;

/**
 * 积分支付
 * Class IntegralPayServices
 * @package app\services\pay
 */
class IntegralPayServices extends BaseServices
{

	/**
 	* 验证积分支付
	* @param int $uid
	* @param array $orderInfo
	* @param $userInfo
	* @return bool
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	*/
	public function checkIntegralPay(int $uid, array $orderInfo, $userInfo = [])
	{
		if (!$uid) {
			throw new ValidateException('缺少参数');
		}
		if (!$orderInfo) {
            throw new ValidateException('订单不存在');
        }
        if ($orderInfo['paid']) {
            throw new ValidateException('该订单已支付!');
        }
		if (!$userInfo) {
			/** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
			$userInfo = $userServices->getUserInfo($uid);
		}
		if (!$userInfo) {
			throw new ValidateException('用户信息不存在');
		}
		if ($userInfo['integral'] < $orderInfo['pay_integral']) {
            throw new ValidateException('积分不足' . intval($orderInfo['pay_integral']));
        }
		return true;
	}

	/**
 	* 使用积分
	* @param int $uid
	* @param array $orderInfo
	* @param $userInfo
	* @return bool
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	*/
	public function integralOrderPay(int $uid,  array $orderInfo, $userInfo)
    {
		$this->checkIntegralPay($uid, $orderInfo, $userInfo);
		$priceIntegral = $orderInfo['pay_integral'];
        $res = true;
        if ($userInfo['integral'] > 0) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            if ($userInfo['integral'] > $priceIntegral) {
                $integral = bcsub((string)$userInfo['integral'], (string)$priceIntegral);
            } else {
                $integral = 0;
            }
            $res = $userServices->update($uid, ['integral' => $integral]);
            /** @var UserBillServices $userBillServices */
            $userBillServices = app()->make(UserBillServices::class);
            $res = $res && false !== $userBillServices->income('storeIntegral_use_integral', $uid, (int)$priceIntegral, (int)$integral, $orderInfo['id']);
        }
        return $res;
    }

}

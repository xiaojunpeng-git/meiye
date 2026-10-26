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

use app\services\order\OtherOrderServices;
use app\services\order\StoreDebtServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\user\UserRechargeServices;

/**
 * 支付成功回调
 * Class PayNotifyServices
 * @package app\services\pay
 */
class PayNotifyServices
{

    /**
     * 订单支付成功之后
     * @param string|null $order_id 订单id
     * @return bool
     */
    public function wechatProduct(string $order_id = null, string $trade_no = null)
    {
        try {
            /** @var StoreOrderSuccessServices $services */
            $services = app()->make(StoreOrderSuccessServices::class);
            $orderInfo = $services->getOne(['order_id' => $order_id]);
            if (!$orderInfo) {
                $orderInfo = $services->getOne(['unique' => $order_id]);
                if (!$orderInfo) return true;
            }
            if ($orderInfo->paid) return true;
            return $services->paySuccess($orderInfo->toArray(), PayServices::WEIXIN_PAY, ['trade_no' => $trade_no]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 储值成功后
     * @param string|null $order_id 订单id
     * @return bool
     */
    public function wechatUserRecharge(string $order_id = null, string $trade_no = null)
    {
        try {
            /** @var UserRechargeServices $userRecharge */
            $userRecharge = app()->make(UserRechargeServices::class);
            if ($userRecharge->be(['order_id' => $order_id, 'paid' => 1])) return true;
            return $userRecharge->rechargeSuccess($order_id, ['trade_no' => $trade_no]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 购买会员
     * @param string|null $order_id
     * @return bool
     */
    public function wechatMember(string $order_id = null, string $trade_no = null)
    {
        try {
            /** @var OtherOrderServices $services */
            $services = app()->make(OtherOrderServices::class);
            $orderInfo = $services->getOne(['order_id' => $order_id]);
            if (!$orderInfo) return true;
            if ($orderInfo->paid) return true;
            return $services->paySuccess($orderInfo->toArray(), PayServices::WEIXIN_PAY, ['trade_no' => $trade_no]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 支付宝支付异步回调处理事件
     * @param string|null $order_id
     * @param string|null $trade_no
     * @return bool
     */
    public function aliyunProduct(string $order_id = null, string $trade_no = null)
    {
        if (!$order_id || !$trade_no) {
            return false;
        }
        try {
            /** @var StoreOrderSuccessServices $services */
            $services = app()->make(StoreOrderSuccessServices::class);
            $orderInfo = $services->getOne(['order_id' => $order_id]);
            if (!$orderInfo) return true;
            if ($orderInfo->paid) return true;
            return $services->paySuccess($orderInfo->toArray(), PayServices::ALIAPY_PAY, ['trade_no' => $trade_no]);
        } catch (\Throwable $e) {
            return false;
        }
    }


    /**
     * 购买会员卡支付宝异步回调
     * @param string|null $order_id
     * @param string|null $trade_no
     * @return bool
     */
    public function aliyunMember(string $order_id = null, string $trade_no = null)
    {
        if (!$order_id || !$trade_no) {
            return false;
        }
        try {
            /** @var OtherOrderServices $services */
            $services = app()->make(OtherOrderServices::class);
            $orderInfo = $services->getOne(['order_id' => $order_id]);
            if (!$orderInfo) return true;
            if ($orderInfo->paid) return true;
            return $services->paySuccess($orderInfo->toArray(), PayServices::ALIAPY_PAY, ['trade_no' => $trade_no]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 储值成功后
     * @param string|null $order_id 订单id
     * @return bool
     */
    public function aliyunUserRecharge(string $order_id = null, string $trade_no = null)
    {
        if (!$order_id || !$trade_no) {
            return false;
        }
        try {
            /** @var UserRechargeServices $userRecharge */
            $userRecharge = app()->make(UserRechargeServices::class);
            if ($userRecharge->be(['order_id' => $order_id, 'paid' => 1])) return true;
            return $userRecharge->rechargeSuccess($order_id, ['trade_no' => $trade_no]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 欠款还款微信回调
     */
    public function wechatDebtRepay(string $order_id = null, string $trade_no = null)
    {
        try {
            /** @var StoreDebtServices $debtServices */
            $debtServices = app()->make(StoreDebtServices::class);
            return $debtServices->completeRepayFromNotify((string)$order_id, (string)$trade_no, PayServices::WEIXIN_PAY);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 欠款还款支付宝回调
     */
    public function aliyunDebtRepay(string $order_id = null, string $trade_no = null)
    {
        if (!$order_id) {
            return false;
        }
        try {
            /** @var StoreDebtServices $debtServices */
            $debtServices = app()->make(StoreDebtServices::class);
            return $debtServices->completeRepayFromNotify((string)$order_id, (string)$trade_no, PayServices::ALIAPY_PAY);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

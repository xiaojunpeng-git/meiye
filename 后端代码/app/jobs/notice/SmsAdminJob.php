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

namespace app\jobs\notice;


use app\services\message\sms\SmsSendServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 短信通知管理员
 * Class SmsAdminJob
 * @package app\jobs
 */
class SmsAdminJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 退款发送管理员消息任务
     * @param $switch
     * @param $adminList
     * @param $order
     * @return bool
     */
    public function sendAdminRefund($switch, $adminList, $order)
    {
        if (!$switch) {
            return true;
        }
		/** @var SmsSendServices $smsServices */
		$smsServices = app()->make(SmsSendServices::class);
		foreach ($adminList as $item) {
			$data = ['order_id' => $order['order_id'], 'admin_name' => $item['nickname']];
			$smsServices->send(true, $item['phone'], $data, 'ADMIN_RETURN_GOODS_CODE');
		}
        return true;
    }

    /**
     * 用户确认收货管理员短信提醒
     * @param $switch
     * @param $adminList
     * @param $order
     * @return bool
     */
    public function sendAdminConfirmTakeOver($switch, $adminList, $order)
    {
        if (!$switch) {
            return true;
        }
		/** @var SmsSendServices $smsServices */
		$smsServices = app()->make(SmsSendServices::class);
		foreach ($adminList as $item) {
			$data = ['order_id' => $order['order_id'], 'admin_name' => $item['nickname']];
			$smsServices->send(true, $item['phone'], $data, 'ADMIN_TAKE_DELIVERY_CODE');
		}
        return true;
    }

    /**
     * 下单成功给客服管理员发送短信
     * @param $switch
     * @param $adminList
     * @param $order
     * @return bool
     */
    public function sendAdminPaySuccess($switch, $adminList, $order)
    {
        if (!$switch) {
            return true;
        }
        /** @var SmsSendServices $smsServices */
        $smsServices = app()->make(SmsSendServices::class);
        foreach ($adminList as $item) {
            $data = ['order_id' => $order['order_id'], 'admin_name' => $item['nickname']];
            $smsServices->send(true, $item['phone'], $data, 'ADMIN_PAY_SUCCESS_CODE');
        }
        return true;
    }
}

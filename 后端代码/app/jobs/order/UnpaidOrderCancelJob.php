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

namespace app\jobs\order;


use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 未支付订单到期取消
 * Class UnpaidOrderCancelJob
 * @package app\jobs
 */
class UnpaidOrderCancelJob extends BaseJobs
{

    use QueueTrait;

    /**
     * @param $orderId
     * @return bool|mixed
     */
    public function doJob($orderId)
    {
        /** @var StoreOrderServices $services */
        $services = app()->make(StoreOrderServices::class);
        $orderInfo = $services->get($orderId);
        if (!$orderInfo) {
            return true;
        }
        if ($orderInfo->paid) {
            return true;
        }
        if ($orderInfo->is_del) {
            return true;
        }
        if ($orderInfo->pay_type == 'offline') {
            return true;
        }
        try {
			$services->cancelOrder((int)$orderInfo['id'], 0, '订单未支付已超过系统预设时间');
        } catch (\Throwable $e) {
            Log::error('自动取消订单失败,失败原因:' . $e->getMessage());
        }
		return true;
    }
}

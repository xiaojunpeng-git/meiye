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


use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use app\services\order\StoreOrderTakeServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 自动收货消息队列
 * Class TakeOrderJob
 * @package app\jobs
 */
class TakeOrderJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param $id
     * @return bool
     */
    public function doJob($id)
    {
        /** @var StoreOrderTakeServices $services */
        $services = app()->make(StoreOrderTakeServices::class);
        $orderInfo = $services->get($id);
        if (!$orderInfo) {
            return true;
        }
        if (!$orderInfo->paid) {
            return true;
        }
        if ($orderInfo->status == 2) {
            return true;
        }
        if ($orderInfo->refund_status == 2) {
            return true;
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->tidyOrder($orderInfo);
        if ($order['_status']['_type'] != 2) {
            return true;
        }
        $orderInfo->status = 2;
		$orderInfo->delivery_time = time();
        $orderInfo->save();
        try {
            $services->storeProductOrderUserTakeDelivery($order);
			OrderStatusJob::dispatch([$orderInfo['id'], 'take_delivery', ['change_message' => '已收货[自动收货]', 'change_manager_type' => 'system']]);
        } catch (\Throwable $e) {
            Log::error('自动收货消息队列执行成功,收货后置动作失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

}

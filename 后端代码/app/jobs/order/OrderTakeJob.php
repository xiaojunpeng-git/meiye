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


use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderTakeServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 订单收货任务
 * Class OrderTakeJob
 * @package app\jobs
 */
class OrderTakeJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return string
     */
    protected static function queueName()
    {
        return 'MOHE_PRO_TASK';
    }

    public function doJob($order)
    {
		if (!$order) return true;
        /** @var StoreOrderTakeServices $service */
        $service = app()->make(StoreOrderTakeServices::class);
        $service->update($order['id'], ['status' => 2, 'delivery_time' => time()]);
		$order = $service->get((int)$order['id']);
        $service->storeProductOrderUserTakeDelivery($order);

		OrderStatusJob::dispatch([$order['id'], 'take_delivery', ['change_message' => '已收货[自动收货]', 'change_manager_type' => 'user']]);
        return true;
    }

	/**
	 * 收银台订单自动发货、收货
	 * @param $orderInfo
	 * @return bool
	 */
	public function autoDeliveryAndTake($orderInfo)
	{
		if (!$orderInfo) {
			return true;
		}
		try {
			$type = 4;
			/** @var StoreOrderDeliveryServices $storeOrderDeliveryServices */
			$storeOrderDeliveryServices = app()->make(StoreOrderDeliveryServices::class);
			$data['type'] = $type;
			$storeOrderDeliveryServices->doDelivery((int)$orderInfo['id'], $orderInfo, $data);


			/** @var StoreOrderTakeServices $service */
			$service = app()->make(StoreOrderTakeServices::class);
			$service->update($orderInfo['id'], ['status' => 2, 'delivery_time' => time()]);
			$orderInfo = $service->get((int)$orderInfo['id']);
			$service->storeProductOrderUserTakeDelivery($orderInfo);

			OrderStatusJob::dispatch([$orderInfo['id'], 'take_delivery', ['change_message' => '已收货[自动收货]', 'change_manager_type' => 'user']]);
		} catch (\Throwable $e) {
			Log::error('收银台订单自动发货、收货失败，原因：' . $e->getMessage());
		}
		return true;
	}
}

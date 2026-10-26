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


use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\store\SystemStoreServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 订单发货
 * Class OrderDeliveryJob
 * @package app\jobs
 */
class OrderDeliveryJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 确认收货
     * @param $orderInfo
     * @param $data
     * @param $type
     * @return bool
     */
    public function doJob($orderInfo, $data, $type)
    {
		if (!$orderInfo) {
			return true;
		}
        try {
            /** @var StoreOrderDeliveryServices $storeOrderDeliveryServices */
            $storeOrderDeliveryServices = app()->make(StoreOrderDeliveryServices::class);
            $data['type'] = $type;
            $storeOrderDeliveryServices->doDelivery((int)$orderInfo['id'], $orderInfo, $data);
        } catch (\Throwable $e) {
            Log::error('收银台订单自动发货失败，原因：' . $e->getMessage());
        }
        return true;
    }


    /**
     * UU 达达自动发货
     * @param $orderInfo
     * @return bool
     */
    public function automaticDelivery($orderInfo)
    {
        if (!$orderInfo) {
            return true;
        }
        try {
            /** @var StoreOrderDeliveryServices $orderDeliveryServices */
            $orderDeliveryServices = app()->make(StoreOrderDeliveryServices::class);
            /** @var SystemStoreServices $systemStoreServices */
            $systemStoreServices = app()->make(SystemStoreServices::class);
            /** @var StoreOrderCartInfoServices $orderInfoServices */
            $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
            $systemStore = $systemStoreServices->get($orderInfo['store_id'],['id','city_delivery_type']);
            if($systemStore && $systemStore['city_delivery_type'] > 0) {
                $cargo_weight = $orderInfoServices->getCarIdByProductCargoWeight((int)$orderInfo['id']);
                $data['type'] = 2;
                $data['delivery_type'] = 2;
                $data['sh_delivery_name'] = '';
                $data['sh_delivery_id'] = '';
                $data['sh_delivery_uid'] = '';
                $data['station_type'] = $systemStore['city_delivery_type'];
                $data['cargo_weight'] = $cargo_weight > 0 ? $cargo_weight : 0.1;
                $orderDeliveryServices->delivery((int)$orderInfo['id'], $data);
            }
        } catch (\Throwable $e) {
            Log::error('UU、达达自动发货失败，原因：' . $e->getMessage());
        }
        return true;
    }
}

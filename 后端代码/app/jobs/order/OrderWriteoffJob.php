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
use app\services\order\StoreOrderWriteOffServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 订单核销
 * Class OrderWriteoffJob
 * @package app\jobs
 */
class OrderWriteoffJob extends BaseJobs
{
    use QueueTrait;

	/**
	 * 写入核销记录
	 * @param int $oid
	 * @param array $cartIds
	 * @param array $data
	 * @param array $orderInfo
	 * @param array $cartInfo
	 * @return bool
	 */
    public function doJob(int $oid, array $cartIds, array $data, array $orderInfo = [], array $cartInfo = [])
    {
        try {
			/** @var StoreOrderWriteOffServices $storeOrderWriteoffServices */
			$storeOrderWriteoffServices = app()->make(StoreOrderWriteOffServices::class);
			$reservationOid = (int)($data['reservation_oid'] ?? 0);
			$storeOrderWriteoffServices->saveWriteOff($oid, $reservationOid, $cartIds, $data, $orderInfo, $cartInfo);
        } catch (\Throwable $e) {
            Log::error('写入订单核销记录失败失败，原因：' . $e->getMessage());
        }
        return true;
    }


}

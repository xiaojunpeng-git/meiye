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

namespace app\jobs\reservation;


use app\services\order\StoreReservationOrderServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 预约单消息队列
 * Class ReservationOrderJob
 * @package app\jobs
 */
class ReservationOrderJob extends BaseJobs
{
    use QueueTrait;

	/**
	 * 记录预约单核销，修改原订单状态
	 * @param $id
	 * @return bool
	 */
    public function saveReservationOrderWriteoff($id)
    {
		if (!$id) {
			return true;
		}
        try {
			/** @var StoreReservationOrderServices $reservationOrderService */
			$reservationOrderService = app()->make(StoreReservationOrderServices::class);
			$reservationOrderService->saveReservationOrderWriteoff((int)$id);
        } catch (\Throwable $e) {
//            Log::error('记录预约单核销，修改原订单状态,失败原因:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 预约商品支付成功后创建预约单（延迟重试，幂等）
     */
    public function createReservationFromPaidOrder(int $oid)
    {
        if (!$oid) {
            return true;
        }
        try {
            /** @var \app\services\order\StoreOrderServices $orderServices */
            $orderServices = app()->make(\app\services\order\StoreOrderServices::class);
            $orderInfo = $orderServices->get($oid);
            if (!$orderInfo || !(int)($orderInfo['paid'] ?? 0)) {
                return true;
            }
            /** @var StoreReservationOrderServices $reservationOrderService */
            $reservationOrderService = app()->make(StoreReservationOrderServices::class);
            $reservationOrderService->createReservationOrderFromPaidStoreOrder(
                is_object($orderInfo) ? $orderInfo->toArray() : (array)$orderInfo
            );
        } catch (\Throwable $e) {
            \think\facade\Log::error('预约商品支付后延迟创建预约单失败 oid=' . $oid . ' ' . $e->getMessage());
        }
        return true;
    }

    /**
     * 服务剩余约5分钟：提醒管家（YUYUE_JINDU）
     */
    public function sendYuyueJinduFiveMinute($id)
    {
        if (!$id) {
            return true;
        }
        try {
            /** @var StoreReservationOrderServices $reservationOrderService */
            $reservationOrderService = app()->make(StoreReservationOrderServices::class);
            $reservationOrderService->tryDispatchYuyueJinduNotice((int)$id, 'five');
        } catch (\Throwable $e) {
            \think\facade\Log::error('预约服务5分钟进度提醒失败 id=' . $id . ' ' . $e->getMessage());
        }
        return true;
    }

    /**
     * 服务到时：提醒管家服务已结束（YUYUE_JINDU）
     */
    public function sendYuyueJinduEnd($id)
    {
        if (!$id) {
            return true;
        }
        try {
            /** @var StoreReservationOrderServices $reservationOrderService */
            $reservationOrderService = app()->make(StoreReservationOrderServices::class);
            $reservationOrderService->tryDispatchYuyueJinduNotice((int)$id, 'end');
        } catch (\Throwable $e) {
            \think\facade\Log::error('预约服务结束进度提醒失败 id=' . $id . ' ' . $e->getMessage());
        }
        return true;
    }

}

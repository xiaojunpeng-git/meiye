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

namespace app\jobs\activity;


use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\order\StoreOrderServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 营销：抽奖活动
 * Class LuckLotteryJob
 * @package app\jobs\activity
 */
class LuckLotteryJob extends BaseJobs
{

    use QueueTrait;

	/**
	 * @param $oid
	 * @return bool|void
	 */
	public function updateLotteryRecord($oid)
	{
		if (!$oid) {
			return true;
		}
		try {
			/** @var StoreOrderServices $orderServices */
			$orderServices = app()->make(StoreOrderServices::class);
			$orderInfo = $orderServices->get($oid);
			$data['oid'] = $orderInfo['id'];
			$data['is_receive'] = 1;
			$data['receive_time'] = time();
			$receive_info['name'] = $orderInfo['real_name'];
			$receive_info['phone'] = $orderInfo['user_phone'];
			if ($orderInfo['shipping_type'] == 2) {
				$receive_info['address'] = '';
			} else {
				$receive_info['address'] = $orderInfo['user_address'];
			}
			$data['receive_info'] = $receive_info;
			/** @var LuckLotteryRecordServices $lotteryRecordServices */
			$lotteryRecordServices = app()->make(LuckLotteryRecordServices::class);
			$lotteryRecordServices->update($orderInfo['activity_id'], $data, 'id');
		} catch (\Throwable $e) {
			Log::error('抽奖订单处理中奖记录失败,失败原因:' . $e->getMessage());
		}
		return true;
	}

}

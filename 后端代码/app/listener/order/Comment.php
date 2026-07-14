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
namespace app\listener\order;

use app\jobs\order\OrderStatusJob;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use app\services\system\admin\SystemAdminServices;
use mohe\interfaces\ListenerInterface;

/**
 * 订单评价事件
 * Class PriceRevision
 * @package app\listener\order
 */
class Comment implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$orderInfo] = $event;
		/** @var SystemAdminServices $systemAdmin */
        $systemAdmin = app()->make(SystemAdminServices::class);
        $systemAdmin->adminNewPush();

		//检测主订单 是否全部评价
		if ($orderInfo['pid']) {
			$id = (int)$orderInfo['pid'];
			/** @var StoreOrderServices $orderServices */
			$orderServices = app()->make(StoreOrderServices::class);
			//默认部分收货
			$take_data = [];
			if ($orderServices->count(['pid' => $id]) == $orderServices->count(['pid' => $id, 'status' => 4])) {
				$take_data = ['status' => 3];
				//改变主订单状态
				$orderServices->update($id, $take_data);
				//记录订单状态变化
				OrderStatusJob::dispatch([$id, 'check_order_over', ['change_message' => '用户评价', 'change_manager_type' => 'user']]);
			}
		}
    }
}

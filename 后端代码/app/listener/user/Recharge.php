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

namespace app\listener\user;

use app\jobs\store\StaffFinanceJob;
use app\jobs\system\CapitalFlowJob;
use app\jobs\system\SocketPushJob;
use app\jobs\user\UserBelongStoreJob;
use mohe\interfaces\ListenerInterface;

/**
 * 用户储值事件
 * Class Recharge
 * @package app\listener\user
 */
class Recharge implements ListenerInterface
{
    /**
     * 用户储值事件
     * @param $event
     */
    public function handle($event): void
    {
        [$order, $now_money] = $event;
		//记录资金流水
        CapitalFlowJob::dispatch([$order, 'recharge']);
        if (isset($order['staff_id']) && $order['staff_id'] && $order['store_id']) {
            //记录用户归属店员
            UserBelongStoreJob::dispatchDo('belongStoreStaff', [$order['uid'], 0, 1, $order['staff_id'], $order['store_id']]);
            StaffFinanceJob::dispatch([$order, 2]);

			//发送消息
			SocketPushJob::dispatch([$order['staff_id'], 'changUser', ['uid' => $order['uid']], 'cashier']);
        }
        //提醒推送
        event('notice.notice', [['order' => $order, 'now_money' => $now_money], 'recharge_success']);
		// 小程序订单服务
		event('order.routine.shipping', ['recharge', $order, 3, '', '']);
    }
}

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
use app\jobs\store\StoreFinanceJob;
use app\jobs\system\CapitalFlowJob;
use mohe\interfaces\ListenerInterface;

/**
 * 用户储值退款事件
 * Class RechargeRefund
 * @package app\listener\user
 */
class RechargeRefund implements ListenerInterface
{
    /**
     * 用户储值事件
     * @param $event
     */
    public function handle($event): void
    {
        [$order, $data] = $event;

        if ($order['store_id']) {
            //门店储值退款流水
            StoreFinanceJob::dispatch([$order, 5, $data['refund_price'] ?? 0.00]);
            //店员退款记录
            StaffFinanceJob::dispatch([$order, 5]);
        }
		//记录资金流水
        CapitalFlowJob::dispatch([$order, 'refund_recharge']);

        //提醒推送
        event('notice.notice', [['user_type' => strtolower($order['recharge_type']), 'data' => $data, 'UserRecharge' => $order], 'recharge_order_refund_status']);

    }
}

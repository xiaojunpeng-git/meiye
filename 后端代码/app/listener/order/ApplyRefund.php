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

use app\jobs\order\OrderSyncJob;
use app\jobs\system\SocketPushJob;
use mohe\interfaces\ListenerInterface;

/**
 * 订单申请退款事件
 * Class ApplyRefund
 * @package app\listener\order
 */
class ApplyRefund implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$order, $refundId, $sync] = $event;

        SocketPushJob::dispatchDo('sendApplyRefund', [$order]);

        //退款消息推送
        event('notice.notice', [['order' => $order], 'send_order_apply_refund']);

        //ERP功能开启 同步退款单
        if (sys_config('erp_open') && $sync) {
            OrderSyncJob::dispatchDo('refundOrderUpload', [$refundId]);
        }
    }
}

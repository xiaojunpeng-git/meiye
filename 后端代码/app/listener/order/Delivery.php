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
use mohe\interfaces\ListenerInterface;
use think\facade\Log;


/**
 * 订单发货事件
 * Class Delivery
 * @package app\listener\order
 */
class Delivery implements ListenerInterface
{
    public function handle($event): void
    {
        [$orderInfo, $storeName, $data, $type] = $event;
		$orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : $orderInfo;
        $storeName = substrUTf8($storeName, 20, 'UTF-8', '');
        switch ($type) {
            case 1://快递发货
                //用户推送消息事件
                event('notice.notice', [['orderInfo' => $orderInfo, 'storeName' => $storeName, 'data' => $data], 'order_postage_success']);
                break;
            case 2://配送
                //用户推送消息事件
                event('notice.notice', [['orderInfo' => $orderInfo, 'storeName' => $storeName, 'data' => $data], 'order_deliver_success']);
                event('notice.notice', [['orderInfo' => $orderInfo, 'storeName' => $storeName, 'data' => $data], 'delivery_reminder']);
                break;
            case 3://虚拟发货
            case 4://门店收银订单自动收货
            	//用户推送消息事件
                event('notice.notice', [['orderInfo' => $orderInfo, 'storeName' => $storeName, 'data' => $data], 'order_fictitious_success']);
                break;
            default:
                Log::error('不支持的发货类型');
        }
		// 小程序订单管理
		event('order.routine.shipping', ['product', $orderInfo, $type, $data['delivery_id'] ?? '', $data['delivery_name'] ?? '']);
		//发子订单 修改主订单发货状态
		if ($orderInfo['pid']) {
			$id = (int)$orderInfo['pid'];
			/** @var StoreOrderServices $order */
			$order = app()->make(StoreOrderServices::class);
			//默认部分发货
            $delivery_data = ['delivery_type' => 'split', 'status' => 4];
            //检测原订单商品是否 全部拆分发货完成  改原订单状态
            if (!$order->count(['pid' => $id, 'status' => 1])) {//发货完成
                $delivery_data['status'] = 1;
				$delivery_data['shipping_time'] = time();
                $change_type = 'delivery_split';
                $change_message = '已拆分发货';
            } else {
				$change_type = 'delivery_part_split';
				$change_message = '已拆分部分发货';
            }
            //改变原订单状态
            $order->update($id, $delivery_data);
            //记录原订单状态
			OrderStatusJob::dispatch([$id, $change_type, ['change_message' => $change_message]]);
		}

    }


}

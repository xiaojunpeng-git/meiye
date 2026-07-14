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


use app\jobs\activity\LuckLotteryJob;
use app\jobs\activity\StoreBargainJob;
use app\jobs\notice\PrintJob;
use app\jobs\order\CreateInvoiceJob;
use app\jobs\order\OrderCreateAfterJob;
use app\jobs\order\OrderJob;
use app\jobs\order\OrderStatusJob;
use app\jobs\product\ProductLogJob;
use app\jobs\order\UnpaidOrderCancelJob;
use app\jobs\order\UnpaidOrderSend;
use app\jobs\product\ProductStockJob;
use app\jobs\store\StoreUserJob;
use app\jobs\system\SystemFormDataJob;
use app\jobs\user\UserBelongStoreJob;
use app\jobs\user\UserJob;
use app\jobs\user\UserUpdateJob;
use app\services\order\StoreOrderServices;
use mohe\interfaces\ListenerInterface;

/**
 * 订单创建事件
 * Class Create
 * @package app\listener\order
 */
class Create implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$orderInfo, $group, $activity, $invoice_id] = $event;
        $uid = (int)($orderInfo['uid'] ?? 0);
        $oid = (int)$orderInfo['id'];
		//抽奖中奖
		if (isset($orderInfo['activity_id']) && $orderInfo['activity_id'] && isset($orderInfo['type']) && $orderInfo['type'] == 8) {
			//抽奖订单中奖记录处理
			LuckLotteryJob::dispatchDo('updateLotteryRecord', [$orderInfo['id']]);
		}
		//计算订单实际金额
		OrderJob::dispatchDo('computeOrderProductTruePrice', [$orderInfo['uid'], $orderInfo['id']]);

		//设置默认地址、修改用户核销人电话和姓名
		OrderCreateAfterJob::dispatchDo('updateUser', [$orderInfo, $group]);
		//清理购物车
		OrderCreateAfterJob::dispatchDo('delCart', [$group]);
		//清理订单确认生成缓存
		OrderCreateAfterJob::dispatchDo('delOrderCache', [$uid, $orderInfo['unique']], 120);

        //创建发票信息
        if ($invoice_id) {
            CreateInvoiceJob::dispatch([$uid, $oid, (int)$invoice_id]);
        }
        //下单成功修改砍价状态
        if ($activity['type'] == 2 && $activity['activity_id']) {
            StoreBargainJob::dispatchDo('setBargainUserStatus', [$uid, (int)$activity['activity_id']]);
        }
		//修改用户首单优惠状态
		UserJob::dispatchDo('updateUserNewcomer', [$uid, $orderInfo]);
        if ($uid && $orderInfo['store_id']) {
			//记录门店用户
			StoreUserJob::dispatch([$uid, $orderInfo['store_id']]);
			//记录用户归属门店
			UserBelongStoreJob::dispatch([$uid, $orderInfo['store_id'], 'order']);
        }
		//下单后打印小票
		if ($orderInfo['store_id'] && $orderInfo['type'] != 10) {
			PrintJob::dispatch([(int)$orderInfo['id'], 1]);
		}
		//写入订单记录表
		OrderStatusJob::dispatch([$oid, 'create', ['change_message' => '订单生成', 'change_manager_type' => 'user']]);
		//记录商品下单记录
		ProductLogJob::dispatch(['order', ['uid' => $uid, 'order_id' => $oid]]);
		//收集商品下单系统表单数据
		SystemFormDataJob::dispatch([$oid]);
		//订单创建生成销售出库单
		ProductStockJob::dispatchDo('saveSaleOutOrder', [$orderInfo['id']]);

        //订单自动取消
        $this->pushJob($oid, (int)$activity['type']);
    }

    /**
     * 订单自动取消加入延迟消息队列
     * @param int $orderId
     * @param int $type
     * @return mixed
     */
    public function pushJob(int $orderId, int $type)
    {
        //未支付10分钟后发送短信
        UnpaidOrderSend::dispatchSece(600, [$orderId]);

		//未支付根据系统设置事件取消订单
		/** @var StoreOrderServices $storeOrderServices */
		$storeOrderServices = app()->make(StoreOrderServices::class);
		$secs = $storeOrderServices->getOrderCancelTime($type);
        UnpaidOrderCancelJob::dispatchSece((int)($secs * 3600), [$orderId]);
    }
}

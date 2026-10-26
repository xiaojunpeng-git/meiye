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


use app\jobs\activity\TableQrcodeJob;
use app\jobs\spread\AgentJob;
use app\jobs\order\OrderCreateAfterJob;
use app\jobs\order\OrderDeliveryJob;
use app\jobs\order\OrderJob;
use app\jobs\order\OrderPayHandelJob;
use app\jobs\order\OrderStatusJob;
use app\jobs\order\OrderSyncJob;
use app\jobs\order\OrderTakeJob;
use app\jobs\order\ShareOrderJob;
use app\jobs\activity\pink\PinkJob;
use app\jobs\product\ProductCouponJob;
use app\jobs\product\ProductLogJob;
use app\jobs\activity\StorePromotionsJob;
use app\jobs\store\StaffFinanceJob;
use app\jobs\system\CapitalFlowJob;
use app\jobs\user\UserBelongStoreJob;
use app\jobs\user\UserLevelJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\order\StoreDebtServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderDeliveryServices;
use app\services\order\StoreOrderInvoiceServices;
use app\jobs\reservation\ReservationOrderJob;
use app\services\order\StoreReservationOrderServices;
use app\services\store\SystemStoreServices;
use app\services\yeji\SatffYejiServices;
use mohe\interfaces\ListenerInterface;

/**
 * 订单支付事件
 * Class Pay
 * @package app\listener\order
 */
class Pay implements ListenerInterface
{
    public function handle($event): void
    {
        [$id, $orderInfo] = $event;
        //计算订单佣金
        OrderJob::dispatchDo('computeOrderBrokerage', [$orderInfo['uid'], $orderInfo['id']]);

        if ($orderInfo['activity_id'] && !$orderInfo['refund_status']) {
            switch ($orderInfo['type']) {
                case 3://拼团
                    //创建拼团
                    PinkJob::dispatchDo('createPink', [$orderInfo]);
                    break;
                case 10://桌码
                    //桌码处理
                    TableQrcodeJob::dispatchDo('updateTableQrcode', [$orderInfo['id'], $orderInfo]);
                    break;
				case 12://预约
					break;
            }
        }
        //写入订单支付记录
        OrderStatusJob::dispatch([$orderInfo['id'], 'pay_success', ['change_message' => '用户付款成功', 'change_manager_type' => 'user']]);
        // 预约商品支付成功后创建预约单并扣次
        if (empty($orderInfo['refund_status'])) {
            try {
                /** @var StoreReservationOrderServices $reservationOrderService */
                $reservationOrderService = app()->make(StoreReservationOrderServices::class);
                $reservationOrderService->createReservationOrderFromPaidStoreOrder($orderInfo);
            } catch (\Throwable $e) {
                \think\facade\Log::error('预约商品支付后创建预约单失败 oid=' . ($orderInfo['id'] ?? 0) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            }
            // 事务提交后再试一次，避免余额支付事务内读取不到最新数据
            ReservationOrderJob::dispatchSece(2, 'createReservationFromPaidOrder', [(int)$orderInfo['id']]);
        }
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $storeOrderCartInfoServices->getCartColunm(['oid' => $orderInfo['id'], 'split_status' => [0, 1], 'cart_type' => 0], 'cart_id,product_type', 'cart_id');
        $productType = array_column($cartInfo, 'product_type');
        if (!empty($orderInfo['is_debt_repay'])) {
            // 补交订单不生成卡包/核销数据
        } elseif (count($productType) == 1) {
            //卡密、虚拟、次卡、卡项商品订单处理
            OrderPayHandelJob::dispatch([$orderInfo]);
        }
        //自动分配订单
        ShareOrderJob::dispatch([$id]);
        //用户
        if ($orderInfo['uid']) {
            //订单商品单独营销设置：赠送优惠卷、积分
            OrderJob::dispatchDo('giveOrderProductCouponAndIntegral', [$orderInfo['id']]);
			//优惠活动赠送优惠卷、积分
			StorePromotionsJob::dispatchDo('give', [$orderInfo]);
           $service=app()->make(StoreCouponIssueServices::class);
           $service->orderPayGetCoupon($orderInfo);
			//优惠活动关联用户标签设置
			StorePromotionsJob::dispatchDo('setUserLabel', [$orderInfo]);
            //修改开票数据支付状态
            $orderInvoiceServices = app()->make(StoreOrderInvoiceServices::class);
            $orderInvoiceServices->update(['order_id' => $orderInfo['id']], ['is_pay' => 1]);
			//检测会员等级
			UserLevelJob::dispatch([(int)$orderInfo['uid']]);
            //支付成功处理自己、上级分销等级升级
            AgentJob::dispatch([(int)$orderInfo['uid']]);
			//支付成功后计算商品节省金额
			OrderJob::dispatchDo('setEconomizeMoney', [$orderInfo]);
			//支付成功设置用户购买次数和检测用户成为推广人
			OrderJob::dispatchDo('setUserPayCountAndPromoter', [$orderInfo]);
        }
        // 订单含欠款时创建欠款记录
        if ((float)($orderInfo['debt_amount'] ?? 0) > 0) {
            try {
                app()->make(StoreDebtServices::class)->createFromPaidOrder($orderInfo);
            } catch (\Throwable $e) {
                \think\facade\Log::error('支付成功后创建欠款记录失败 oid=' . ($orderInfo['id'] ?? 0) . ' ' . $e->getMessage());
            }
        }
        //收银台订单 && 不是次卡、卡项、预约商品
        if ($orderInfo['shipping_type'] == 4 && empty($orderInfo['is_debt_repay'])) {
            if (!in_array(4, $productType) && !in_array(5, $productType) && !in_array(6, $productType)) {
                //订单自动发货、收货
                OrderTakeJob::dispatchDo('autoDeliveryAndTake', [$orderInfo]);
            }
            OrderCreateAfterJob::dispatchDo('updateUser', [$orderInfo]);
        }

        // 门店 UU、达达送货自动发货
        if($orderInfo['shipping_type'] == 3 && $orderInfo['store_delivery_type'] == 2 && $orderInfo['store_id']) {
            OrderDeliveryJob::dispatchDo('automaticDelivery', [$orderInfo]);
        }

        //支付记录
        ProductLogJob::dispatch(['pay', ['uid' => $orderInfo['uid'], 'order_id' => $orderInfo['id']]]);
        //记录资金流水队列
        CapitalFlowJob::dispatch([$orderInfo, 'order']);
        // 同步订单
        if (sys_config('erp_open')) {
            OrderSyncJob::dispatchDo('syncOrder', [(int)$orderInfo['id']]);
        }
        // 小程序订单管理 (核销商品 || 卡密自动发货商品)
        if ($orderInfo['shipping_type'] == 2 || $orderInfo['product_type'] == 1) {
			$delivery_type = $orderInfo['shipping_type'] == 2 ? 4 : 3;
            event('order.routine.shipping', ['product', $orderInfo, $delivery_type, '', '']);
        }
        if ($orderInfo['staff_id'] && $orderInfo['store_id']) {
            //记录用户归属店员
            UserBelongStoreJob::dispatchDo('belongStoreStaff', [$orderInfo['uid'], 0, 1, $orderInfo['staff_id'], $orderInfo['store_id']]);
            StaffFinanceJob::dispatch([$orderInfo, 1]);
        }
    }
}

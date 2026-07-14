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
use app\jobs\product\ProductLogJob;
use app\jobs\store\StaffFinanceJob;
use app\jobs\store\StoreFinanceJob;
use app\jobs\supplier\SupplierFinanceJob;
use app\jobs\system\CapitalFlowJob;
use app\model\activity\coupon\StoreCouponUser;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderWriteoff;
use app\model\yeji\StaffYeji;
use app\services\order\StoreDebtServices;
use app\services\order\StoreOrderGiftServices;
use app\services\order\StoreOrderInvoiceServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use mohe\interfaces\ListenerInterface;

/**
 * 订单退款事件
 * Class PriceRevision
 * @package app\listener\order
 */
class Refund implements ListenerInterface
{
    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$data, $order] = $event;
        $order = is_array($order) ? $order : $order->toArray();
        if ((int)($order['refund_status'] ?? 0) === 2) {
            try {
                /** @var StoreDebtServices $debtServices */
                $debtServices = app()->make(StoreDebtServices::class);
                $debtServices->voidDebtByOrderId((int)$order['id'], '订单退款');
                $debtServices->voidRepayOrdersByOriginOrderId((int)$order['id'], '主订单退款');
            } catch (\Throwable $e) {
                // 作废欠款失败不阻断退款
            }
        }
        if (!empty($order['is_debt_repay']) && (float)($data['refund_price'] ?? 0) > 0) {
            try {
                /** @var StoreDebtServices $debtServices */
                $debtServices = app()->make(StoreDebtServices::class);
                $debtServices->reverseRepayOnRefund((int)$order['id'], (float)$data['refund_price']);
            } catch (\Throwable $e) {
                // 恢复欠款失败不阻断退款
            }
        }
        //赠送的优惠劵未使用的退掉
        StoreCouponUser::where("use_time",0)
            ->where('oid',$order['id'])
            ->where('type','order_get')
            ->delete();
        //如果订单类型不是卡项的 关联的所有核销单全部退掉
        if($order['product_type'] != 5){
             $hxOrder=StoreOrder::where("order_type",2)->where("refund_status",0)
                 ->where("link_order",$order['id'])->select();
             foreach ($hxOrder as $k=>$v){
                  StoreOrder::where('id',$v['id'])->update( ['back_reason' =>'主订单退款','refund_status'=>2]);
                 //业绩失效
                 StaffYeji::where("link_id",$v['link_id'])->where("type",3)->update(['status'=>1]);
                 //核销记录
                 StoreOrderWriteoff::where("id",$v['link_id'])->update(['status'=>1]);
             }
        }
        //不管什么项目 订单退款 销售业绩就退掉
        StaffYeji::where("link_id",$order['id'])->where("type",2)->update(['status'=>1]);
        // 主订单（购卡/购买）退款时，联动撤销同批赠送子订单；赠送单单独撤销不影响主单
        try {
            /** @var StoreOrderGiftServices $giftServices */
            $giftServices = app()->make(StoreOrderGiftServices::class);
            $giftServices->revokeLinkedGiftOrders((int)$order['id'], '主订单退款');
        } catch (\Throwable $e) {
            // 联动撤销失败不阻断主单退款
        }
        //修改开票数据退款状态
        $orderInvoiceServices = app()->make(StoreOrderInvoiceServices::class);
        $orderInvoiceServices->update(['order_id' => $order['id']], ['is_refund' => 1]);

        //更新完成时间
        if (isset($order['store_id']) && $order['store_id']) {
			//门店退款流水
			StoreFinanceJob::dispatch([$order, 4, $data['refund_price'] ?? 0.00]);
			//店员退款记录
			StaffFinanceJob::dispatch([$order, 4, $data['refund_price'] ?? 0.00]);

            StoreFinanceJob::dispatchDo('takeDoJob', [$order, time()], 10);
        } else if (isset($order['supplier_id']) && $order['supplier_id']) {
			//供应商退款流水
			SupplierFinanceJob::dispatch([$order['id'],  2]);

            SupplierFinanceJob::dispatchDo('takeDoJob', [$order, time()], 10);
        }
		//记录资金流水队列
		CapitalFlowJob::dispatch([array_merge($order, ['refund_price' => $data['refund_price']]), 'refund']);
		//退款记录
		ProductLogJob::dispatch(['refund', ['uid' => $order['uid'], 'order_id' => $order['id']]]);

        //订单退款消息推送
        event('notice.notice', [['data' => $data, 'order' => $order], 'order_refund']);

		//检测主订单 是否全部退款
		if ($order['pid']) {
			$id = (int)$order['pid'];
			/** @var StoreOrderServices $orderServices */
			$orderServices = app()->make(StoreOrderServices::class);
			//默认部分退款
            $refund_data = ['refund_status' => '3', 'refund_type' => 4];
			if ($orderServices->count(['pid' => $id]) == $orderServices->count(['pid' => $id, 'refund_status' => 2])) {
				$refund_data = ['refund_status' => 2, 'refund_type' => 6];
				$change_type = 'refund_split';
				$change_message = '已拆分退款';
			} else {
				$change_type = 'refund_part_split';
				$change_message = '已拆分部分退款';
			}
			//改变主订单状态
            $orderServices->update($id, $refund_data);
            //记录主订单状态
			OrderStatusJob::dispatch([$id, $change_type, ['change_message' => $change_message]]);
		}
    }
}

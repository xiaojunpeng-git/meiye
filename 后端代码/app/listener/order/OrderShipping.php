<?php

namespace app\listener\order;

use app\jobs\order\MiniOrderJob;
use app\model\order\StoreOrder;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\wechat\WechatUserServices;
use mohe\exceptions\AdminException;
use mohe\interfaces\ListenerInterface;

/**
 * 小程序发货信息管理事件
 * Class OrderShipping
 * @package app\listener\order
 */
class OrderShipping implements ListenerInterface
{
    public function handle($event): void
    {
        /** @var StoreOrder $order */
        [$order_type, $order, $delivery_type, $delivery_id, $delivery_name] = $event;
        $order_shipping_open = sys_config('order_shipping_open', 0);  // 小程序发货信息管理服务开关
        if (!$order || !$order_shipping_open) {
			return;
		}
		//判断订单是否拆单
		$delivery_mode = 1;
		$is_all_delivered = true;
		switch ($order_type) {
			case 'product'://商品订单
				if ($order['is_channel'] == 1 && $order['pay_type'] == 'weixin') {
					$order_id = $order['order_id'];
					$out_trade_no = $order['trade_no'];
					/** @var StoreOrderCartInfoServices $orderInfoServices */
					$orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
					$item_desc = $orderInfoServices->getCarIdByProductTitle((int)$order['id'], true);
					if ($order['pid'] > 0) {
						$delivery_mode = 2;
						// 判断订单是否全部发货
						/** @var StoreOrderServices $orderServices */
						$orderServices = app()->make(StoreOrderServices::class);
						$is_all_delivered = $orderServices->checkSubOrderNotSend((int)$order['pid'], (int)$order['id']);
						$p_order = $orderServices->get((int)$order['pid']);
						if (!$p_order) {
							throw new AdminException('拆单异常');
						}
						$order_id = $p_order['order_id'];
					}
					$pay_uid = $order['uid'];
					$path = 'pages/goods/order_details/index?order_id=' . $order_id;
				} else {
					return;
				}
				break;
			case 'integral'://积分兑换订单
				if ($order['price'] > 0 && $order['pay_type'] == 'weixin') {
					$order_id = $order['order_id'];
					$out_trade_no = $order['trade_no'];
					$item_desc = $order['store_name'] ?? '';
					$pay_uid = $order['uid'];
					$path = 'pages/points_mall/integral_order_details?order_id=' . $order_id;
				} else {
					return;
				}
				break;
			case 'recharge'://储值订单
				if (in_array($order['recharge_type'], ['routine', 'weixin']) && $order['price']) {
					$delivery_type = 3;
					$item_desc = '用户储值' . $order['price'];
					$out_trade_no = $order['trade_no'];
					$pay_uid = $order['uid'];
					$path = '/pages/users/user_bill/index?type=2';
				} else {
					return;
				}
				break;
			case 'member'://会员订单
				if ($order['pay_type'] == 'weixin' && $order['pay_price']) {
					$delivery_type = 3;
					$item_desc = '用户购买' . $order['member_type'] . '会员卡';
					$out_trade_no = $order['trade_no'] ?? '';
					$pay_uid = $order['uid'];
					$path = '/pages/annex/vip_paid/index';
				} else {
					return;
				}
				break;
			default:
				return;
				break;
		}
		// 整理商品信息
		$shipping_list = [
			['item_desc' => $item_desc]
		];
		//判断订单物流模式
		if (!isset($order['shipping_type']) || $order['shipping_type'] == 1) {
			if ($delivery_type == 1) {
				$shipping_list = [
					[
						'tracking_no' => $delivery_id ?? '',
						'express_company' => $delivery_name ?? '',
						'item_desc' => $item_desc,
						'contact' => [
							'receiver_contact' => $order['user_phone']
						]
					]
				];
			}
			$logistics_type = $delivery_type;
		} else {
			$logistics_type = isset($order['product_type']) && $order['product_type'] == 1 ? 3 : 4;;
		}
		//查找支付者openid
		/** @var WechatUserServices $wechatUserService */
		$wechatUserService = app()->make(WechatUserServices::class);
		$payer_openid = $wechatUserService->uidToOpenid($pay_uid, 'routine');
		if (empty($payer_openid)) {
			\think\facade\Log::error('同步小程序发货信息失败，失败原因：订单支付人异常');
			return;
		}
		MiniOrderJob::dispatchSece(60, 'doJob', [$out_trade_no, $logistics_type, $shipping_list, $payer_openid, $path, $delivery_mode, $is_all_delivered]);
    }
}

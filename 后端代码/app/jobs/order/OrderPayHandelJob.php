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

namespace app\jobs\order;


use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use app\services\user\UserCardHolderServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\sku\StoreProductVirtualServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 支付成功:自动发送卡密、处理次卡商品核销信息
 * Class OrderPayHandelJob
 * @package app\jobs\order
 */
class OrderPayHandelJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param $orderInfo
     * @return bool
     */
    public function doJob($orderInfo)
    {
        if (!$orderInfo) {
            return true;
        }
        if (!empty($orderInfo['is_debt_repay'])) {
            return true;
        }
        //待发货状态
        if ($orderInfo['status'] != 0) {
            return true;
        }
        /** @var StoreOrderServices $orderService */
        $orderService = app()->make(StoreOrderServices::class);
        /** @var UserCardHolderServices $holderService */
        $holderService = app()->make(UserCardHolderServices::class);
        /** @var StoreOrderCartInfoServices $services */
        $services = app()->make(StoreOrderCartInfoServices::class);
        $orderInfo['cart_info'] = $cartInfoList = $services->getOrderCartInfoCache((int)$orderInfo['id']);
        try {
			switch ($orderInfo['product_type']) {
				case 1://卡密商品：自动发送
					$title = $content = $disk_info = $unique = '';
					if ($cartInfoList) {
						foreach ($cartInfoList as $cartInfo) {
							$info = $cartInfo['cart_info'] ?? [];
							$disk_info = $info['productInfo']['attrInfo']['disk_info'] ?? '';
							$unique = $info['productInfo']['attrInfo']['unique'] ?? '';
						}
					}
					//活动订单共用原商品规格卡密
					if ($orderInfo['type'] != 0 && $orderInfo['activity_id']) {
						/** @var StoreProductAttrValueServices $skuValueServices */
						$skuValueServices = app()->make(StoreProductAttrValueServices::class);
						$attrValue = $skuValueServices->getUniqueByActivityUnique($unique, (int)$orderInfo['activity_id'], (int)$orderInfo['type'], ['unique', 'disk_info']);
						if ($attrValue) {
							$disk_info = $attrValue['disk_info'] ?? '';
							$unique = $attrValue['unique'] ?? '';
						}
					}
					if ($disk_info) {
						$title = '虚拟密钥发放';
						$content = '您购买的密钥商品已支付成功，支付金额' . $orderInfo['pay_price'] . '元，订单号：' . $orderInfo['order_id'] . '，密钥：' . $disk_info . '，感谢您的光临！';
						$virtual_info = '密钥自动发放：' . $disk_info;
						$value = '密钥:' . $disk_info;
//                        $remark = '密钥自动发放：' . $disk_info;
					} else {
						/** @var StoreProductVirtualServices $virtualService */
						$virtualService = app()->make(StoreProductVirtualServices::class);
						$cardList = $virtualService->getOrderCardList(['store_id' => $orderInfo['store_id'], 'attr_unique' => $unique, 'uid' => 0], (int)$orderInfo['total_num']);
						$title = '虚拟卡密发放';
						$virtual_info = [];
//                        $remark = '卡密已自动发放';
						$value = '';
						if ($cardList) {
							$content = '您购买的卡密商品已支付成功，支付金额' . $orderInfo['pay_price'] . '元，订单号：' . $orderInfo['order_id'] . ',';
							$update = [];
							$update['order_id'] = $orderInfo['order_id'];
							$update['uid'] = $orderInfo['uid'];
							foreach ($cardList as $virtual) {
								$virtualService->update($virtual['id'], $update);
								$content .= '卡号：' . $virtual['card_no'] . '；密码：' . $virtual['card_pwd'] . "\n";
								$virtual_info[] = ['card_no' => $virtual['card_no'], 'card_pwd' => $virtual['card_pwd']];
								$value .= '卡号:' . $virtual['card_no'] . '；密码:' . $virtual['card_pwd'];
//                                $remark .= '，卡号：' . $virtual['card_no'] . '；密码：' . $virtual['card_pwd'] . ';';
							}
							$content .= '，感谢您的光临！';
						}
					}
					//修改订单虚拟备注
					$orderService->update(['id' => $orderInfo['id']], ['status' => 1, 'delivery_type' => 'fictitious', 'virtual_info' => json_encode($virtual_info)]);
					$data['id'] = $orderInfo['id'];
					$data['uid'] = $orderInfo['uid'];
					$data['order_id'] = $orderInfo['order_id'];
					$data['title'] = $title;
					$data['value'] = $value;
					$data['content'] = $content;
					$data['is_integral'] = 0;
					event('notice.notice', [$data, 'kami_deliver_goods_code']);
					//记录订单状态
					OrderStatusJob::dispatch([$orderInfo['id'], 'delivery_fictitious', ['change_message' => '卡密自动发货', 'change_manager_type' => 'system']]);
					break;
				case 4://次卡商品:处理核销数据
					foreach ($cartInfoList as $cart_id => $cartInfo) {
						$info = $cartInfo['cart_info'] ?? [];
						$attrInfo =  $info['productInfo']['attrInfo'] ?? [];
						$data = ['write_start' => 0, 'write_end' => 0, 'write_days' => 0];
						if ($attrInfo) {
							switch ($attrInfo['write_valid'] ?? 1) {
								case 1://永久
									break;
								case 2://购买后n天
									if (isset($attrInfo['write_days']) && $attrInfo['write_days']) {
										$data['write_start'] = time();
										$data['write_end'] = bcadd((string)$data['write_start'], (string)bcmul((string)$attrInfo['write_days'], '86400'));
                                        $data['write_days'] = $attrInfo['write_days'];
                                    }
									break;
								case 3://具体时间段
									if ((isset($attrInfo['write_start']) && $attrInfo['write_start']) || (isset($attrInfo['write_end']) && $attrInfo['write_end'])) {
										$data['write_start'] = $attrInfo['write_start'] ?? 0;
										$data['write_end'] = $attrInfo['write_end'] ?? 0;
									}
									break;
							}
						}
						$services->update(['oid' => $orderInfo['id'], 'cart_id' => $cart_id], $data);
                        $holderService->setCardHolder($orderInfo,$data,$info);
					}
					break;
                case 5://卡项商品:处理核销数据
					foreach ($cartInfoList as $cart_id => $cartInfo) {
						$info = $cartInfo['cart_info'] ?? [];
						$attrInfo =  $info['productInfo']['attrInfo'] ?? [];
						$data = ['write_start' => 0, 'write_end' => 0, 'write_days' => 0];
						if ($attrInfo) {
							switch ($attrInfo['write_valid'] ?? 1) {
								case 1://永久
									break;
								case 2://购买后n天
									if (isset($attrInfo['write_days']) && $attrInfo['write_days']) {
										$data['write_start'] = time();
										$data['write_end'] = bcadd((string)$data['write_start'], (string)bcmul((string)$attrInfo['write_days'], '86400'));
                                        $data['write_days'] = $attrInfo['write_days'];
                                    }
									break;
								case 3://具体时间段
									if ((isset($attrInfo['write_start']) && $attrInfo['write_start']) || (isset($attrInfo['write_end']) && $attrInfo['write_end'])) {
										$data['write_start'] = $attrInfo['write_start'] ?? 0;
										$data['write_end'] = $attrInfo['write_end'] ?? 0;
									}
									break;
							}
						}
						$services->update(['oid' => $orderInfo['id'], 'cart_id' => $cart_id], $data);
                        $holderService->setCardHolder($orderInfo,$data,$info);
					}
					break;
			}
		} catch (\Throwable $e) {
			Log::error('订单虚拟商品自动发放失败，原因：' . $e->getMessage());
		}
		return true;
	}

}

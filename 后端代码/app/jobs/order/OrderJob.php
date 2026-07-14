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

use app\jobs\system\SocketPushJob;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\message\service\StoreServiceServices;
use app\services\message\sms\SmsSendServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderComputedServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderEconomizeServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\member\MemberCardServices;
use app\services\user\label\UserLabelRelationServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use mohe\basic\BaseJobs;
use mohe\services\wechat\Messages;
use mohe\services\wechat\OfficialAccount;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 订单消息队列
 * Class OrderJob
 * @package app\jobs
 */
class OrderJob extends BaseJobs
{
    use QueueTrait;


    /**
     * 计算订单实际金额
     * @param $uid
     * @param $oid
     * @return bool
     */
    public function computeOrderProductTruePrice($uid, $oid)
    {
        if (!$oid) {
            return true;
        }
        /** @var StoreOrderCreateServices $createService */
        $createService = app()->make(StoreOrderCreateServices::class);
        $orderInfo = $createService->get($oid);
        if (!$orderInfo || $uid != $orderInfo['uid']) {
            return true;
        }
        try {
            $uid = (int)$orderInfo['uid'];
            $userInfo = [];
            if ($uid) {
                /** @var UserServices $userService */
                $userService = app()->make(UserServices::class);
                $userInfo = $userService->getUserCacheInfo($uid);
            }
            $orderId = (int)$orderInfo['id'];
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartInfoList = $cartServices->getCartColunm(['oid' => $orderId], 'cart_info');
            if (!$cartInfoList) {
                return true;
            }
            $cartInfo = [];
            foreach ($cartInfoList as $cart) {
                $cartInfo[] = is_string($cart) ? json_decode($cart, true) : $cart;
            }
            $priceData = [
                'usedIntegral' => $orderInfo['use_integral'],
                'deduction_price' => $orderInfo['deduction_price'],
                'first_order_price' => $orderInfo['first_order_price']
            ];
            if ($cartInfo) {
                $cartInfo = $createService->computeOrderProductTruePrice($orderInfo, $cartInfo, $priceData, $uid, $userInfo);
                $cartServices->updateCartInfo($orderId, $cartInfo);
                if ($orderInfo['type'] == 11) {
                    $cartServices->updateCardHolderInfo($orderId, $cartInfo);
                }
            }
        } catch (\Throwable $e) {
            Log::error('计算订单实际优惠、积分、实付金额失败，原因：' . $e->getMessage());
        }

        return true;
    }

	/**
	 * 计算订单佣金
	 * @param $uid
	 * @param $oid
	 * @return bool
	 */
	public function computeOrderBrokerage($uid, $oid)
	{
		if (!$oid) {
			return true;
		}
		/** @var StoreOrderCreateServices $createService */
		$createService = app()->make(StoreOrderCreateServices::class);
		$orderInfo = $createService->get($oid);
		if (!$orderInfo || $uid != $orderInfo['uid']) {
			return true;
		}
		try {
			$uid = (int)$orderInfo['uid'];
			$userInfo = [];
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			if ($uid) {
				$userInfo = $userServices->getUserCacheInfo($uid);
			}
			$orderId = (int)$orderInfo['id'];
			/** @var StoreOrderCartInfoServices $cartServices */
			$cartServices = app()->make(StoreOrderCartInfoServices::class);
			$cartInfoList = $cartServices->getCartColunm(['oid' => $orderId], 'cart_info');
			if (!$cartInfoList) {
				return true;
			}
			$cartInfo = [];
			foreach ($cartInfoList as $cart) {
				$cartInfo[] = is_string($cart) ? json_decode($cart, true) : $cart;
			}
			$spread_ids = [];
			$spread_uid = $spread_two_uid = 0;
			if ($cartInfo) {
				[$cartInfo, $spread_ids] = $createService->computeOrderProductBrokerage($uid, $cartInfo, $userInfo);
				$cartServices->updateCartInfo($orderId, $cartInfo);
			}
			$orderData = [];
			if ($spread_ids) {
				[$spread_uid, $spread_two_uid] = $spread_ids;
			} else {
				$spread_uid = $userServices->getSpreadUid($uid);
				if ($spread_uid > 0 && sys_config('brokerage_level', 2) == 2) {
					$spread_two_uid = $userServices->getSpreadUid($spread_uid, [], false);
				}
			}
			if ($spread_uid > 0) {
				$orderData['spread_uid'] = $spread_uid;
			}
			if ($spread_two_uid > 0) {
				$orderData['spread_two_uid'] = $spread_two_uid;
			}
			if ($cartInfo && (isset($orderInfo['type']) && !in_array($orderInfo['type'], [1, 2, 3, 4, 5, 7, 8]))) {
				/** @var StoreOrderComputedServices $orderComputed */
				$orderComputed = app()->make(StoreOrderComputedServices::class);
				if ($userServices->checkUserPromoter($spread_uid)) $orderData['one_brokerage'] = $orderComputed->getOrderSumPrice($cartInfo, 'one_brokerage', false);
				if ($userServices->checkUserPromoter($spread_two_uid)) $orderData['two_brokerage'] = $orderComputed->getOrderSumPrice($cartInfo, 'two_brokerage', false);
			}
			if ($orderData) $createService->update(['id' => $orderId], $orderData);
		} catch (\Throwable $e) {
			Log::error('计算订单实际优惠、积分、邮费、佣金失败，原因：' . $e->getMessage());
		}

		return true;
	}


    /**
     * 执行订单支付成功发送socket消息
     * @param $order
     * @return bool
     */
    public function newOrderSocketPush($order)
    {
		if (!$order) return true;
        if (isset($order['store_id']) && $order['store_id']) {
            //向门店后台发送新订单消息
			SocketPushJob::dispatch([$order['store_id'], 'NEW_ORDER', ['order_id' => $order['order_id']], 'store']);
			//向收银台发送新订单消息
			/** @var SystemStoreStaffServices $systemStoreStaffServices */
			$systemStoreStaffServices = app()->make(SystemStoreStaffServices::class);
			$staffIds = $systemStoreStaffServices->getColumn(['store_id' => $order['store_id'], 'is_del' => 0], 'id');
			SocketPushJob::dispatch([$staffIds, 'NEW_ORDER', ['order_id' => $order['order_id']], 'cashier']);
        } elseif(isset($order['supplier_id']) && $order['supplier_id']) {
			//向供应商后台发送新订单消息
			SocketPushJob::dispatch([$order['supplier_id'], 'NEW_ORDER', ['order_id' => $order['order_id']], 'supplier']);
		} else {
            //向后台发送新订单消息
			SocketPushJob::dispatch(['', 'NEW_ORDER', ['order_id' => $order['order_id']], 'admin']);
        }
        return true;
    }

    /**
     * 设置用户购买次数和检测时候成为推广人
     * @param $order
     */
    public function setUserPayCountAndPromoter($order)
    {
        try {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->get($order['uid']);
            if ($userInfo) {
                $userInfo->pay_count = $userInfo->pay_count + 1;
                if (!$userInfo->is_promoter) {
                    /** @var StoreOrderServices $orderServices */
                    $orderServices = app()->make(StoreOrderServices::class);
                    $price = $orderServices->sum(['pid' => 0, 'paid' => 1, 'refund_status' => 0, 'uid' => $userInfo['uid']], 'pay_price');
                    $status = is_brokerage_statu($price);
                    if ($status) {
                        $userInfo->is_promoter = 1;
                    }
                }
                $userInfo->save();
            }
        } catch (\Throwable $e) {
            Log::error('更新用户订单数失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

	/**
	 * 订单商品单独营销设置：赠送优惠卷、积分，下单赠送积分
	 * @param $id
	 * @return bool
	 */
	public function giveOrderProductCouponAndIntegral($id)
	{
		$id = (int)$id;
		if (!$id) return true;
		/** @var StoreOrderServices $orderServices */
		$orderServices = app()->make(StoreOrderServices::class);
		$orderInfo = $orderServices->get($id);
		if (!$orderInfo) return true;
		try {
			/** @var StoreProductCouponServices $storeProductCouponServices */
			$storeProductCouponServices = app()->make(StoreProductCouponServices::class);
			$storeProductCouponServices->giveOrderProductCoupon((int)$orderInfo['uid'], $id);
		} catch (\Throwable $e) {
			Log::error('赠送订单商品关联优惠券发生错误,错误原因:' . $e->getMessage());
		}
		try {
			//获取购物车内的商品标题
			/** @var StoreOrderCartInfoServices $orderInfoServices */
			$orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
			$storeName = $orderInfoServices->getCarIdByProductTitle((int)$id);
			$storeTitle = substrUTf8($storeName, 20, 'UTF-8', '');
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			$userInfo = $userServices->get((int)$orderInfo['uid']);
			/** @var StoreOrderTakeServices $takeOrderServices */
			$takeOrderServices = app()->make(StoreOrderTakeServices::class);
			$takeOrderServices->gainUserIntegral($orderInfo, $userInfo, $storeTitle);
		} catch (\Throwable $e) {
			Log::error('订单商品营销设置赠送积分、下单支付赠送积分发生错误,错误原因:' . $e->getMessage());
		}
		return true;
	}

    /**
     * 设置用户购买的标签
     * @param $order
     */
    public function setUserLabel($order)
    {
        try {
            /** @var StoreOrderCartInfoServices $cartInfoServices */
            $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
            $cartInfos = $cartInfoServices->getCartColunm(['oid' => $order['id']], 'cart_info');
            $cartInfo = [];
            foreach ($cartInfos as $cart) {
                $cartInfo[] = is_string($cart) ? json_decode($cart, true) : $cart;
            }
            $productIds = array_unique(array_column($cartInfo, 'product_id'));
            if (isset($order['store_id']) && $order['store_id']) {
                /** @var StoreBranchProductServices $branchProductServices */
                $branchProductServices = app()->make(StoreBranchProductServices::class);
                $label = $branchProductServices->getColumn([['id', 'in', $productIds], ['type', '=', 1], ['relation_id', '=', $order['store_id']]], 'label_id');
            } else {
                /** @var StoreProductServices $productServices */
                $productServices = app()->make(StoreProductServices::class);
                $label = $productServices->getColumn([['id', 'in', $productIds]], 'label_id');
            }

            $labelIds = [];
            if ($label) {
                $labelIds = explode(',', implode(',', $label));
            }
            if ($order['type'] == 5 && $order['activity_id']) {
                /** @var StoreDiscountsServices $storeDiscountsServices */
                $storeDiscountsServices = app()->make(StoreDiscountsServices::class);
                $discounts_label = $storeDiscountsServices->value(['id' => $order['activity_id']], 'link_ids');
                if ($discounts_label) {
                    $labelIds = array_merge($labelIds, explode(',', $discounts_label));
                }
            }
            if (!$labelIds) {
                return true;
            }
            $store_id = $order['store_id'] ?? 0;
            $type = $store_id ? 1 : 0;
            $labelIds = array_unique($labelIds);
            /** @var UserLabelRelationServices $labelServices */
            $labelServices = app()->make(UserLabelRelationServices::class);
            $labelServices->setUserLable([$order['uid']], $labelIds, $type, $store_id);
        } catch (\Throwable $e) {
            Log::error('用户标签添加失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 发送模板消息和客服消息
     * @param $order
     * @return bool
     */
    public function sendServicesAndTemplate($order)
    {
        try {
            if (in_array($order['is_channel'], [1, 2])) {//小程序发送模板消息
                //订单支付成功后给客服发送客服消息
                $this->sendOrderPaySuccessCustomerService($order, 0);
            } else {//公众号发送模板消息
                //订单支付成功后给客服发送客服消息
                $this->sendOrderPaySuccessCustomerService($order, 1);
            }
        } catch (\Exception $e) {
            Log::error('发送客服消息,短信消息失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 订单支付成功后平台客服发送客服消息
     * @param $order
     * @param int $type 1 公众号 0 小程序
     * @return string
     */
    public function sendOrderPaySuccessCustomerService($order, $type = 0)
    {
        /** @var StoreServiceServices $services */
        $services = app()->make(StoreServiceServices::class);
        /** @var WechatUserServices $wechatUserServices */
        $wechatUserServices = app()->make(WechatUserServices::class);
        $serviceOrderNotice = $services->getStoreServiceOrderNotice();
        if (count($serviceOrderNotice)) {
            /** @var StoreProductServices $services */
            $services = app()->make(StoreProductServices::class);
            /** @var StoreSeckillServices $seckillServices */
            $seckillServices = app()->make(StoreSeckillServices::class);
            /** @var StoreCombinationServices $pinkServices */
            $pinkServices = app()->make(StoreCombinationServices::class);
            /** @var StoreBargainServices $bargainServices */
            $bargainServices = app()->make(StoreBargainServices::class);
            /** @var StoreOrderCartInfoServices $cartInfoServices */
            $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
            /** @var SmsSendServices $smsServices */
//            $smsServices = app()->make(SmsSendServices::class);
//            $switch = (bool)sys_config('admin_pay_success_switch');

            $description = '';
            $image = sys_config('site_logo');
            switch ($order['type']) {
                case 1://秒杀
                    $description .= '秒杀商品：' . $seckillServices->value(['id' => $order['activity_id']], 'title');
                    $image = $seckillServices->value(['id' => $order['activity_id']], 'image');
                    break;
                case 2://砍价
                    $description .= '砍价商品：' . $bargainServices->value(['id' => $order['activity_id']], 'title');
                    $image = $bargainServices->value(['id' => $order['activity_id']], 'image');
                    break;
                case 3://拼团
                    $description .= '拼团商品：' . $pinkServices->value(['id' => $order['activity_id']], 'title');
                    $image = $pinkServices->value(['id' => $order['activity_id']], 'image');
                    break;
                default:
                    $productIds = $cartInfoServices->getCartIdsProduct((int)$order['id']);
                    $storeProduct = $services->getProductArray([['id', 'in', $productIds]], 'image,store_name', 'id');
                    if (count($storeProduct)) {
                        foreach ($storeProduct as $value) {
                            $description .= $value['store_name'] . '  ';
                            $image = $value['image'];
                        }
                    }
                    break;
            }
            foreach ($serviceOrderNotice as $key => $item) {
                $userInfo = $wechatUserServices->getOne(['uid' => $item['uid'], 'user_type' => 'wechat']);
                if ($userInfo) {
                    $userInfo = $userInfo->toArray();
                    if ($userInfo['subscribe'] && $userInfo['openid']) {
                        if ($item['customer']) {
                            // 统计管理开启  推送图文消息
                            $head = '订单提醒 订单号：' . $order['order_id'];
                            $url = sys_config('site_url') . '/pages/admin/orderDetail/index?id=' . $order['order_id'];

                            $message = Messages::newsMessage($head, $description, $url, $image);
                            try {
                                OfficialAccount::staffService()->message($message)->to($userInfo['openid'])->send();
                            } catch (\Exception $e) {
                                Log::error($userInfo['nickname'] . '发送失败' . $e->getMessage());
                            }
                        } else {
                            // 推送文字消息
                            $head = "客服提醒：亲,您有一个新订单 \r\n订单单号:{$order['order_id']}\r\n支付金额：￥{$order['pay_price']}\r\n备注信息：{$order['mark']}\r\n订单来源：小程序";
                            if ($type) $head = "客服提醒：亲,您有一个新订单 \r\n订单单号:{$order['order_id']}\r\n支付金额：￥{$order['pay_price']}\r\n备注信息：{$order['mark']}\r\n订单来源：公众号";
                            try {
                                OfficialAccount::staffService()->message($head)->to($userInfo['openid'])->send();
                            } catch (\Exception $e) {
                                Log::error($userInfo['nickname'] . '发送失败' . $e->getMessage());
                            }
                        }
                    }
                }

            }
        }
        return true;
    }

    /**
     * 计算节约金额
     * @param $order
     */
    public function setEconomizeMoney($order)
    {
        try {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            /** @var StoreOrderCartInfoServices $cartInfoService */
            $cartInfoService = app()->make(StoreOrderCartInfoServices::class);
            /** @var StoreCouponUserServices $couponService */
            $couponService = app()->make(StoreCouponUserServices::class);
            /** @var StoreOrderEconomizeServices $economizeService */
            $economizeService = app()->make(StoreOrderEconomizeServices::class);
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $getOne = $economizeService->getOne(['order_id' => $order['order_id']]);
            if ($getOne) return false;
            //看是否是会员
            if ($userService->checkUserIsSvip((int)$order['uid'])) {
                $save = [];
                $save['order_type'] = 1;
                $save['add_time'] = time();
                $save['pay_price'] = $order['pay_price'];
                $save['order_id'] = $order['order_id'];
                $save['uid'] = $order['uid'];
                //计算商品节约金额
                $isOpenVipPrice = $memberCardService->isOpenMemberCardCache('vip_price');
                if ($isOpenVipPrice) {
                    $cartInfo = $cartInfoService->getOrderCartInfoCache($order['id']);
                    $memberPrice = 0.00;
                    if ($cartInfo) {
                        foreach ($cartInfo as $k => $item) {
                            foreach ($item as $value) {
                                if (isset($value['price_type']) && $value['price_type'] == 'member') $memberPrice = bcadd((string)$memberPrice, (string)bcmul((string)$value['vip_truePrice'], (string)$value['cart_num'] ?: '1', 2), 2);
                            }
                        }
                    }
                    $save['member_price'] = $memberPrice;
                }
                //计算邮费节约金额
                $isOpenExpress = $memberCardService->isOpenMemberCardCache('express');
                if ($isOpenExpress) {
                    $expressTotalMoney = bcdiv($order['total_postage'], bcdiv($isOpenExpress, 100, 2), 2);
                    $save['postage_price'] = bcsub($expressTotalMoney, $order['total_postage'], 2);
                }
                //计算会员券节省金额
                if ($order['coupon_id'] && $order['coupon_price']) {
                    $coupon = $couponService->getOne(['id' => $order['coupon_id']], '*', ['issue']);
                    //是会员券
                    if ($coupon && $coupon['category'] == 2) {
                        $save['coupon_price'] = $order['coupon_price'];
                        if($coupon['coupon_type'] == 2 && $coupon['top_discount_price'] > 0 && $save['coupon_price'] > $coupon['top_discount_price']) {
                            $save['coupon_price'] = $coupon['top_discount_price'];
                        }
                    }
                }
                return $economizeService->addEconomize($save);
            }
        } catch (\Throwable $e) {
            Log::error('计算节省金额,失败原因:' . $e->getMessage());
        }
        return true;
    }


    public function setWriteValid()
    {

    }
}

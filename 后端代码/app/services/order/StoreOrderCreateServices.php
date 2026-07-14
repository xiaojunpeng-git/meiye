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

namespace app\services\order;


use app\jobs\activity\StorePromotionsJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\user\UserCardHolder;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\activity\integral\StoreIntegralServices;
use app\services\activity\newcomer\StoreNewcomerServices;
use app\services\spread\AgentLevelServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\pay\PayServices;
use app\services\product\brand\StoreBrandServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\system\form\SystemFormServices;
use app\services\user\UserRechargeServices;
use app\services\wechat\WechatUserServices;
use app\services\BaseServices;
use mohe\exceptions\PayException;
use mohe\services\CacheService;
use app\dao\order\StoreOrderDao;
use app\services\user\UserServices;
use mohe\traits\OptionTrait;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use app\services\user\UserBillServices;
use app\services\user\UserAddressServices;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\store\SystemStoreServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\product\product\StoreProductServices;
use app\services\activity\collage\UserCollageCodeServices;
use app\services\activity\collage\UserCollagePartakeServices;
use think\facade\Cache;
use think\facade\Log;

/**
 * 订单创建
 * Class StoreOrderCreateServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class StoreOrderCreateServices extends BaseServices
{
    use ServicesTrait, OptionTrait;

	/**
	 * 订单来源
	 * @var string[]
	 */
	public $channelType = [
		'h5' => 'H5',
		'weixin' => '微信公众号',
		'routine' => '微信小程序',
		'pc' => 'PC',
		'app' => 'APP',
		'cashier' => '收银台',
		'admin' => '平台代客下单',
		'store' => '门店代客下单'
	];

    /**
     * StoreOrderCreateServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }


    //普通订单判断是否跨店
    public function isKuadian($uid,$storeId,$addTime=0){
        $lastRechargeStoreId=StoreOrder::where("order_type",1)
            ->where("uid",$uid)
            ->when($addTime > 0,function ($query) use ($addTime){
                 $query->where("add_time","<",$addTime);
            })
            ->where("paid",1)
            ->where('refund_status',0)
            ->order("add_time","desc")
            ->value("store_id");
        $result=0;
        if($lastRechargeStoreId != $storeId){
            $result=$lastRechargeStoreId;
        }
        return $result;
    }

    //核销订单判断是否跨店
    public function isKuadianhx($storeId,$orderId){
         $orderStoreId=StoreOrder::where("id",$orderId)->value("store_id");
         $result=0;
         if($orderStoreId != $storeId){
              $result=$orderStoreId;
         }
         return $result;
    }
    /**
     * 核销订单生成核销码
     * @return false|string
     */
    public function getStoreCode()
    {
        mt_srand();
        [$msec, $sec] = explode(' ', microtime());
        $num = time() + mt_rand(10, 999999) . '' . substr($msec, 2, 3);//生成随机数
        if (strlen($num) < 12)
            $num = str_pad((string)$num, 12, 0, STR_PAD_RIGHT);
        else
            $num = substr($num, 0, 12);
        if ($this->dao->count(['verify_code' => $num])) {
            return $this->getStoreCode();
        }
        return $num;
    }

    //秒杀、砍价、拼团成功后 如果订单是卡项或者项目更新可核销状态----秒杀和砍价
    public function changeOrder($productType,$oid){
        if($productType == 5){
            $verify_code=$this->getStoreCode();
            StoreOrder::where("id",$oid)->update(['shipping_type'=>2,'verify_code'=>$verify_code,'type'=>11]);
            $save['is_del']=0;
            UserCardHolder::where("oid",$oid)->update($save);
        }
        if($productType == 6){
            $verify_code=$this->getStoreCode();
            StoreOrder::where("id",$oid)->update(['shipping_type'=>2,'verify_code'=>$verify_code,'type'=>0]);
        }
    }
    /**
     * 创建订单
     * @param int $uid
     * @param string $key
     * @param array $cartGroup
     * @param int $addressId
     * @param string $payType
     * @param array $addressInfo
     * @param array $userInfo
     * @param bool $useIntegral
     * @param int $couponId
     * @param string $mark
     * @param int $pinkId
     * @param int $isChannel
     * @param int $shippingType
     * @param int $storeId
     * @param false $news
     * @param array $customForm
     * @param int $invoice_id
     * @param string $from
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function createOrder(int $uid, string $key, array $cartGroup, int $addressId, string $payType, array $addressInfo, array $userInfo = [], bool $useIntegral = false, $couponId = 0, $mark = '', $pinkId = 0, $isChannel = 0, $shippingType = 1, $storeId = 0, $news = false, $customForm = [], int $invoice_id = 0, string $from = '', int $collate_code_id = 0, $estimate_time = '', int $is_store_delivery_type = 0)
    {
        /** @var StoreOrderComputedServices $computedServices */
        $computedServices = app()->make(StoreOrderComputedServices::class);
        $priceData = $computedServices->computedOrder($uid, $userInfo, $cartGroup, $addressId, $payType, $useIntegral, $couponId, $shippingType,$storeId,$is_store_delivery_type);
        $cartInfo = $cartGroup['cartInfo'];
        $priceGroup = $cartGroup['priceGroup'];
		$useCoupon = $priceGroup['useCoupon'];
		if ($useCoupon && $couponId != $useCoupon['id']) {
			$couponId = $useCoupon['id'];
		}
        $cartIds = [];
        $totalNum = 0;
        $gainIntegral = 0;
		$reservationNum = 0;
        foreach ($cartInfo as $cart) {
            $cartIds[] = $cart['id'];
            $totalNum += $cart['cart_num'];
			if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {//赠品 卡项关联跳过
				continue;
			}
			//订单商品营销设置：赠送积分
			$cartInfoGainIntegral = isset($cart['productInfo']['give_integral']) ? bcmul((string)$cart['cart_num'], (string)$cart['productInfo']['give_integral'], 0) : 0;
			$gainIntegral = bcadd((string)$gainIntegral, (string)$cartInfoGainIntegral, 0);
			if (isset($cart['productInfo']['product_type']) && $cart['productInfo']['product_type'] == 6) {//预约商品
				$reservationNum += $cart['cart_num'];
			}
        }
        $deduction = $cartGroup['deduction'];
        $other = $cartGroup['other'];
		$reservationInfo = $other['reservationInfo'] ?? [];
		$reservationInfo['reservation_num'] = $reservationNum;
        $promotions_give = [
            'give_integral' => $other['give_integral'] ?? 0,
            'give_coupon' => $other['give_coupon'] ?? [],
            'give_product' => $other['give_product'] ?? [],
            'promotions' => $other['promotions'] ?? []
        ];
        $type = (int)$deduction['type'] ?? 0;
        $activity_id = (int)$deduction['activity_id'] ?? 0;
        $collateCodeId = (int)$deduction['collate_code_id'] ?? 0;
        $product_type = (int)$deduction['product_type'] ?? 0;
        /** @var UserCollageCodeServices $collageServices */
        $collageServices = app()->make(UserCollageCodeServices::class);
        if (in_array($type, [1, 2, 3, 5])) {
            $couponId = 0;
            if ($type != 5) $useIntegral = false;
            $systemPayType = PayServices::PAY_TYPE;
            unset($systemPayType['offline']);
            if ($from != 'pc' && !array_key_exists($payType, $systemPayType)) {
                throw new ValidateException('营销商品不能使用线下支付!');
            }
        } else if ($type == 8) {
            $gainIntegral = 0;
        } else if ($type == 9 || $type == 10) {
            if ($collateCodeId != $collate_code_id) {
                foreach ($cartIds as $key) {
                    CacheService::redisHandler()->delete($key);
                }
                throw new ValidateException('拼单/桌码ID有误,请刷新页面!');
            }
            $status = $collageServices->value(['id' => $collate_code_id], 'status');
            if ($status >= 2) throw new ValidateException($type == 10 ? '桌码' : '拼单' . '已生成订单!');
            $activity_id = $collate_code_id;
        } else if($type == 11) {
            $activity_id = array_unique(array_column($cartInfo, 'product_id'))[0] ?? 0;
        }
        //$shipping_type = 1 快递发货 $shipping_type = 2 门店核销 $shipping_type = 3 门店配送
        if (!sys_config('store_func_status', 1) || !sys_config('store_self_mention', 1)) $shippingType = 1;

        $userAddress = $addressInfo['province'] . ' ' . $addressInfo['city'] . ' ' . $addressInfo['district'] . ' ' . $addressInfo['street'] . ' ' . $addressInfo['detail'];
        $userLocation = trim(($addressInfo['longitude'] ?? '') . ' ' . ($addressInfo['latitude'] ?? ''));
		$storeId = (int)$storeId;
        $staffId = $userInfo['salesman_id'] ?? 0;
        $orderInfo = [
            'uid' => $uid,
            'type' => $type,
            'order_id' => $this->getUniqueId(),
            'real_name' => $addressInfo['real_name'],
            'user_phone' => $addressInfo['phone'],
            'user_address' => $userAddress,
            'user_location' => $userLocation,
//            'cart_id' => $cartIds,
            'total_num' => $totalNum,
            'total_price' => $priceGroup['sumPrice'] ?? $priceGroup['totalPrice'],
			'settle_price' => $priceGroup['settlePrice'] ?? 0,
            'total_postage' => $priceData['total_postage'] ?? $priceGroup['storePostage'],
            'coupon_id' => $couponId,
            'coupon_price' => $priceData['coupon_price'],
            'first_order_price' => $priceData['first_order_price'],
            'promotions_price' => $priceData['promotions_price'],
            'pay_price' => $priceData['pay_price'],
            // 现金/余额支付拆分：默认非余额支付全部记入现金支付
            'cash_pay_price' => $payType === PayServices::YUE_PAY ? 0.00 : (float)$priceData['pay_price'],
            'yue_pay_price' => $payType === PayServices::YUE_PAY ? (float)$priceData['pay_price'] : 0.00,
			'pay_integral' => $priceData['pay_integral'],
            'pay_postage' => $priceData['pay_postage'],
            'deduction_price' => $priceData['deduction_price'],
			'change_price' => $priceData['change_price'] ?? 0.00,
			'service_price' => $priceData['service_price'] ?? 0.00,
            'paid' => 0,
            'pay_type' => $payType,
            'use_integral' => $priceData['usedIntegral'],
            'gain_integral' => $gainIntegral,
            'mark' => htmlspecialchars($mark),
            'product_type' => $product_type,
            'activity_id' => $activity_id,
            'pink_id' => $pinkId,
            'cost' => $priceGroup['costPrice'],
            'is_channel' => $isChannel,
            'add_time' => time(),
            'unique' => $key,
            'shipping_type' => $shippingType,
            'channel_type' => $this->getItem('channel_type', '') ?: $userInfo['user_type'],
            'province' => '',
            'spread_uid' => 0,
            'spread_two_uid' => 0,
            'custom_form' => (($type == 12 && (($reservationInfo['reservation_time_id'] ?? 0) || ($reservationInfo['reservation_start'] ?? ''))) || $type != 12) ? json_encode($customForm) : json_encode([[]]),//售后预约
            'promotions_give' => json_encode($promotions_give),
            'give_integral' => $promotions_give['give_integral'] ?? 0,
            'give_coupon' => implode(',', $promotions_give['give_coupon'] ?? []),
            'staff_id' => $staffId,
            'store_id' => $storeId,
			'reservation_type' => $reservationInfo['reservation_type'] ?? 2,
			'reservation_time' => ($reservationInfo['reservation_time'] ?? '') ? strtotime($reservationInfo['reservation_time']) : 0,
			'reservation_time_id' => $reservationInfo['reservation_time_id'] ?? 0,
			'reservation_show_time' => $reservationInfo['reservation_show_time'] ?? 0,
			'service_staff_id' => $reservationInfo['service_staff_id'] ?? 0,
            'estimate_time' => $estimate_time,
            'store_delivery_type' => $is_store_delivery_type,
        ];
        if($orderInfo['type'] == 1){
            //秒杀
            $orderInfo['source']=8;
        }
        if($orderInfo['type'] == 2){
            //砍价
            $orderInfo['source']=9;
        }
        if($orderInfo['type'] == 3){
            //拼团
            $orderInfo['source']=10;
        }
        if ($userInfo['user_type'] == 'wechat' || $userInfo['user_type'] == 'routine') {
            /** @var WechatUserServices $wechatServices */
            $wechatServices = app()->make(WechatUserServices::class);
            $orderInfo['province'] = $wechatServices->value(['uid' => $uid, 'user_type' => $userInfo['user_type']], 'province') ?: '';
        }
        if ($shippingType == 2) {
            $orderInfo['verify_code'] = $this->getStoreCode();
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $orderInfo['store_id'] = $storeServices->getStoreDisposeCache($storeId, 'id');
            if (!$orderInfo['store_id']) {
                throw new ValidateException('暂无门店无法选择门店核销');
            }
        }
		if ($orderInfo['custom_form']) {//有补充信息系统表单
			$customFormTitle = '';
			if (isset($cartInfo[0]['productInfo']['system_form_id']) && $cartInfo[0]['productInfo']['system_form_id']) {
				/** @var SystemFormServices $systemFormServices */
				$systemFormServices = app()->make(SystemFormServices::class);
				$customFormTitle = $systemFormServices->value(['id' => $cartInfo[0]['productInfo']['system_form_id']], 'name') ?? '';
			}
			$orderInfo['custom_form_title'] = $customFormTitle;
			$orderInfo['system_from_type'] = $cartInfo[0]['productInfo']['system_from_type'] ?? 1;
		}

        $order = $this->transaction(function () use ($cartIds, $couponId, $orderInfo, $cartInfo, $key, $userInfo, $useIntegral, $priceData, $type, $activity_id, $uid, $addressId, $promotions_give, $storeId, $reservationInfo,$product_type) {
            //创建订单
            $order = $this->dao->save($orderInfo);
            if ($couponId) {
                /** @var StoreCouponUserServices $couponServices */
                $couponServices = app()->make(StoreCouponUserServices::class);
                $couponServices->useCoupon($couponId, (int)$uid, $cartInfo, [], $storeId);
            }
            //抵扣积分
            $this->deductIntegral($userInfo, $useIntegral, $priceData, (int)$uid, $key);
            //扣库存
            $this->decGoodsStock($cartInfo, $type, $activity_id, $orderInfo['store_id'] ?? 0);
            //保存购物车商品信息
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartServices->setCartInfo($order['id'], $cartInfo, (int)$uid, $promotions_give['promotions'] ?? []);
			if($type == 11 && $activity_id) {
                $cartServices->setCartCartInfo($order['id'], (int)$uid, $activity_id);
            }
            if (in_array($type, [1, 2, 3, 5]) && $product_type == 5) {
                 //营销商品 如果有卡项也要创建才对
                $productId=$cartInfo[0]['productInfo']['product_id'] ?? 0;
                $cartServices->setCartCartInfo($order['id'], (int)$uid, $productId);
            }
            // 预约单：支付成功后再创建并扣次（见 order.pay 事件）
            return $order;
        });

        if (in_array($type, [9, 10]) && $collate_code_id > 0 && $order) {
            //关联订单和拼单、桌码
            $collageServices->update($collate_code_id, ['oid' => $order['id'], 'status' => 2]);

            /** @var UserCollagePartakeServices $partakeService */
            $partakeService = app()->make(UserCollagePartakeServices::class);
            $partakeService->update(['collate_code_id' => $collate_code_id, 'is_settle' => 0], ['status' => 0]);
        }
        //扣除优惠活动赠品限量
        StorePromotionsJob::dispatchDo('changeGiveLimit', [$promotions_give]);
        //订单创建事件
        event('order.create', [$order, compact('cartInfo', 'priceData', 'addressId', 'cartIds', 'news'), compact('type', 'activity_id'), $invoice_id]);
        return $order;
    }

	/**
	 * 订单收银台
	 * @param int $uid
	 * @param string $orderId
	 * @param string $type
	 * @return bool[]
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getCashierInfo(int $uid, string $orderId, string $type)
	{
		//支付类型开关
		$data = [];
		$data['offline_pay_status'] = 2;
		$data['yue_pay_status'] = 2;
		$data['ali_pay_status'] = (int)sys_config('ali_pay_status');//支付宝支付 1 开启 0 关闭
		$data['pay_weixin_open'] = (int)sys_config('pay_weixin_open') ?? 0;//微信支付 1 开启 0 关闭

		$data['order_id'] = $orderId;
		$data['pay_price'] = '0';
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$userInfo = $userServices->get($uid, ['uid', 'now_money', 'integral']);
		$data['now_money'] = $userInfo['now_money'];
		$data['integral'] =  $userInfo['integral'];
		//默认订单取消时间
		$secs = 30;
		switch ($type) {
			case 'order':
				$info = $this->dao->get(['order_id' => $orderId], ['id', 'store_id', 'shipping_type', 'pay_price', 'add_time', 'type', 'pay_postage', 'type']);
				if (!$info) {
					throw new PayException('您支付的订单不存在');
				}
				$data['offline_pay_status'] = (int)sys_config('offline_pay_status') ?? (int)2;
				if ($data['offline_pay_status'] == 1 && sys_config('offline_pay_type', 0)) {//线下支付开启 && 仅核销支持开启
					//验证订单类型 是核销订单可以支持线下支付
					$data['offline_pay_status'] = $info['shipping_type'] == 2 ? 1: 2;
				}
				$data['yue_pay_status'] = (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? (int)1 : (int)2;//余额支付 1 开启 2 关闭
				//验证门店是否开启使用余额支付
				if ($data['yue_pay_status'] == 1 && $info['store_id']) {
					/** @var SystemStoreServices $systemStoreServices */
					$systemStoreServices = app()->make(SystemStoreServices::class);
					$storeInfo = $systemStoreServices->get((int)$info['store_id'], ['id', 'use_system_money']);
					$data['yue_pay_status'] = $storeInfo['use_system_money'] ? $data['yue_pay_status'] : 2;
				}
				//系统预设取消订单时间段
				/** @var StoreOrderServices $storeOrderServices */
				$storeOrderServices = app()->make(StoreOrderServices::class);
				$secs = $storeOrderServices->getOrderCancelTime((int)$info['type']);

				$data['pay_price'] = $info['pay_price'];
				$data['pay_postage'] = $info['pay_postage'];
				$data['offline_postage'] = (int)sys_config('offline_postage', 0);
				/** @var StoreOrderCartInfoServices $cartInfoServices */
				$cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
				$data['cart_info'] = $cartInfoServices->getCartInfoList(['oid' => $info['id']], ['product_id', 'cart_id as id', 'cart_num']);
				break;
			case 'vip':
				/** @var OtherOrderServices $OtherOrderServices */
				$OtherOrderServices = app()->make(OtherOrderServices::class);
				$info = $OtherOrderServices->get(['order_id' => $orderId], ['pay_price', 'add_time']);
				if (!$info) {
					throw new PayException('您支付的订单不存在');
				}
				$data['pay_price'] = $info['pay_price'];
				break;
			case 'recharge':
				/** @var UserRechargeServices $rechargeServices */
				$rechargeServices = app()->make(UserRechargeServices::class);
				$info = $rechargeServices->get(['order_id' => $orderId], ['price', 'add_time']);
				if (!$info) {
					throw new PayException('您支付的订单不存在');
				}
				$data['pay_price'] = $info['price'];
				$data['ali_pay_status'] = 0;
				break;
			default:
				throw new PayException('暂不支持其他类型订单支付');
		}
		$time = $secs * 60 * 60 + (int)$info['add_time'];
		if ($time < 0) {
			$time = 0;
		}
		$data['invalid_time'] = $time;
		return $data;
	}


    /**
     * 抵扣积分
     * @param array $userInfo
     * @param bool $useIntegral
     * @param array $priceData
     * @param int $uid
     * @param string $key
     */
    public function deductIntegral(array $userInfo, bool $useIntegral, array $priceData, int $uid, string $key)
    {
        $res2 = true;
        if (sys_config('integral_ratio_status', 1) && $userInfo && $useIntegral && $userInfo['integral'] > 0) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            if (!$priceData['SurplusIntegral'] && $priceData['usedIntegral'] >= $userInfo['integral']) {
                $integral = 0;
            } else {
                $integral = bcsub((string)$userInfo['integral'], (string)$priceData['usedIntegral']);
            }
            $res2 = false !== $userServices->update($uid, ['integral' => $integral]);
            /** @var UserBillServices $userBillServices */
            $userBillServices = app()->make(UserBillServices::class);
            $res3 = $userBillServices->income('deduction', $uid, [
                'number' => (int)$priceData['usedIntegral'],
                'deductionPrice' => $priceData['deduction_price']
            ], $integral, $key);

            $res2 = $res2 && false != $res3;
        }
        if (!$res2) {
            throw new ValidateException('使用积分抵扣失败!');
        }
    }

    /**
     * 扣库存
     * @param array $cartInfo
     * @param int $type
     * @param int $activity_id
     * @param int $store_id
     */
    public function decGoodsStock(array $cartInfo, int $type, int $activity_id, int $store_id = 0)
    {
        $res5 = true;
        /** @var StoreProductServices $services */
        $services = app()->make(StoreProductServices::class);
        /** @var StoreSeckillServices $seckillServices */
        $seckillServices = app()->make(StoreSeckillServices::class);
        /** @var StoreCombinationServices $pinkServices */
        $pinkServices = app()->make(StoreCombinationServices::class);
        /** @var StoreBargainServices $bargainServices */
        $bargainServices = app()->make(StoreBargainServices::class);
        /** @var StoreDiscountsServices $discountServices */
        $discountServices = app()->make(StoreDiscountsServices::class);
        /** @var StoreNewcomerServices $storeNewcomerServices */
        $storeNewcomerServices = app()->make(StoreNewcomerServices::class);
		/** @var StoreIntegralServices $storeIntegralServices */
		$storeIntegralServices = app()->make(StoreIntegralServices::class);
        try {
            foreach ($cartInfo as $cart) {
                if(isset($cart['productInfo']['attrInfo']['unique'])) {
                    $unique = isset($cart['productInfo']['attrInfo']) ? $cart['productInfo']['attrInfo']['unique'] : '';
                }else{
                    $unique='';
                }
                $cart_num = (int)$cart['cart_num'];
                $productType = (int)($cart['product_type'] ?? ($cart['productInfo']['product_type'] ?? 0));
                $cartItemType = (int)($cart['type'] ?? 0);
                $productId = (int)($cart['productInfo']['id'] ?? $cart['product_id'] ?? 0);
                $skipStock = in_array($productType, [4, 5, 6], true)
                    || in_array($cartItemType, [11, 12], true)
                    || ((int)($cart['cart_type'] ?? 0) > 0)
                    || bccomp((string)($cart['pay_price'] ?? '0'), '0', 2) === 0
                    || (array_key_exists('price', $cart) && bccomp((string)$cart['price'], '0', 2) === 0);
                if ($skipStock) {
                    if ($productId > 0 && $cart_num > 0) {
                        try {
                            $services->incProductSales($cart_num, $productId, $unique, $store_id);
                        } catch (\Throwable $e) {
                        }
                    }
                    continue;
                }
                //减库存加销量
                switch ($type) {
                    case 0://普通
                    case 6://预售
					case 8://抽奖
					case 9://拼单
					case 10://桌码
					case 11://卡项
					case 12://预约 （仅+销量)
                        // 次卡(4)、卡项(5)、项目/预约(6)、收银台卡项/预约单(type 11/12)：无库存概念，仅加销量
                        if (in_array($productType, [4, 5, 6], true) || in_array($cartItemType, [11, 12], true)) {
                            $res5 = $res5 && $services->incProductSales($cart_num, $productId, $unique, $store_id);
                        } else {//+销量-库存
                            $res5 = $res5 && $services->decProductStock($cart_num, $productId, $unique, $store_id);
                        }
                        break;
                    case 1://秒杀
                        $res5 = $res5 && $seckillServices->decSeckillStock($cart_num, $activity_id, $unique, $store_id);
                        break;
                    case 2://砍价
                        $res5 = $res5 && $bargainServices->decBargainStock($cart_num, $activity_id, $unique, $store_id);
                        break;
                    case 3://拼团
                        $res5 = $res5 && $pinkServices->decCombinationStock($cart_num, $activity_id, $unique, $store_id);
                        break;
					case 4://积分
						$res5 = $res5 && $storeIntegralServices->decIntegralStock($cart_num, $activity_id, $unique, $store_id);
						break;
                    case 5://套餐
                        $res5 = $res5 && $discountServices->decDiscountStock($cart_num, $activity_id, (int)($cart['discount_product_id'] ?? 0), (int)($cart['product_id'] ?? 0), $unique, $store_id);
                        break;
                    case 7://新人专享
                        $res5 = $res5 && $storeNewcomerServices->decNewcomerStock($cart_num, $activity_id, $unique, $store_id);
                        break;
                    default:
						$res5 = $res5 && $services->decProductStock($cart_num, (int)$cart['productInfo']['id'], $unique, $store_id);
                        break;
                }
            }
            if ($type == 5 && $activity_id) {
                //改变套餐限量
                $res5 = $res5 && $discountServices->changeDiscountLimit($activity_id);
            }
            if (!$res5) {
                throw new ValidateException('库存不足!');
            }
        } catch (ValidateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ValidateException('库存不足!');
        }
    }

	/**
	 * 设置用户默认地址
	 * @param int $uid
	 * @param array $group
	 * @return bool
	 */
	public function updateUserAddress(int $uid, array $group)
	{
		if (!$uid || !$group) return true;
		//设置用户默认地址
		if (isset($group['addressId']) && $group['addressId']) {
			/** @var UserAddressServices $addressServices */
			$addressServices = app()->make(UserAddressServices::class);
			if (!$addressServices->be(['is_default' => 1, 'uid' => $uid])) {
				$addressServices->setDefaultAddress($group['addressId'], $uid);
			}
		}
		return true;
	}

	/**
	 * 订单创建后的后置事件
	 * @param array $group
	 * @return void
	 */
	public function delCart(array $group)
	{
		try {
			//删除购物车
			if (isset($group['news']) && $group['news']) {
				array_map(function ($key) {
					CacheService::redisHandler()->delete($key);
				}, $group['cartIds'] ?? []);
			} else {
				if (!isset($group['delCart']) || $group['delCart']) {
					/** @var StoreCartServices $cartServices */
					$cartServices = app()->make(StoreCartServices::class);
					$cartServices->deleteCartStatus($group['cartIds'] ?? []);
				}
			}
		} catch (\Throwable $e) {
			throw new ValidateException('删除购物车失败,失败原因:' . $e->getMessage());
		}
	}

    /**
     * 计算订单每个商品真实付款价格
     * @param array $orderInfo
     * @param array $cartInfo
     * @param array $priceData
     * @param $addressId
     * @param int $uid
     * @param $userInfo
     * @return array
     */
    public function computeOrderProductTruePrice($orderInfo, array $cartInfo, array $priceData, int $uid, $userInfo)
    {
        //统一放入默认数据
        foreach ($cartInfo as &$cart) {
            $cart['use_integral'] = 0;
            $cart['integral_price'] = 0.00;
			if (!isset($cart['coupon_price'])) {
				$cart['coupon_price'] = 0.00;
			}
            $cart['first_order_price'] = 0.00;
            $cart['one_brokerage'] = 0.00;
            $cart['two_brokerage'] = 0.00;
        }
        try {
            $promotionsGice = isset($orderInfo['promotions_give']) ? (is_string($orderInfo['promotions_give']) ? json_decode($orderInfo['promotions_give'], true) : $orderInfo['promotions_give']) : [];
            $promotions = [];
            if (isset($promotionsGice['promotions']) && $promotionsGice['promotions']) {
                $promotions = $promotionsGice['promotions'];
            }
            $cartInfo = $this->computeOrderProductIntegral($cartInfo, $priceData);
            $cartInfo = $this->computeOrderProductFirstDiscount($cartInfo, $priceData);
        } catch (\Throwable $e) {
            Log::error('订单商品结算失败,File：' . $e->getFile() . ',Line：' . $e->getLine() . ',Message：' . $e->getMessage());
            throw new ValidateException('订单商品结算失败');
        }
        //truePice实际支付单价（存在）
        //几件商品总体优惠 以及积分抵扣金额
        foreach ($cartInfo as &$cart) {
            $coupon_price = $cart['coupon_price'] ?? 0;
            $integral_price = $cart['integral_price'] ?? 0;
            $first_order_price = $cart['first_order_price'] ?? 0;
            $cart['sum_true_price'] = $cart['pay_price'];
            if ($coupon_price) {
//                $cart['sum_true_price'] = max(bcsub((string)$cart['sum_true_price'], (string)$coupon_price, 2), 0);
//				$cart['pay_price'] = max(bcsub((string)$cart['pay_price'], (string)$coupon_price, 2), 0);
                $uni_coupon_price = (string)bcdiv((string)$coupon_price, (string)$cart['cart_num'], 4);
                $cart['truePrice'] = $cart['truePrice'] > $uni_coupon_price ? bcsub((string)$cart['truePrice'], $uni_coupon_price, 2) : 0;
            }
            if ($integral_price) {
                $cart['sum_true_price'] = max(bcsub((string)$cart['sum_true_price'], (string)$integral_price, 2), 0);
				$cart['pay_price'] = max(bcsub((string)$cart['pay_price'], (string)$integral_price, 2), 0);
                $uni_integral_price = (string)bcdiv((string)$integral_price, (string)$cart['cart_num'], 4);
                $cart['truePrice'] = $cart['truePrice'] > $uni_integral_price ? bcsub((string)$cart['truePrice'], $uni_integral_price, 2) : 0;
            }
            if ($first_order_price) {
                $cart['sum_true_price'] = bcsub((string)$cart['sum_true_price'], (string)$first_order_price, 2);
				$cart['pay_price'] = bcsub((string)$cart['pay_price'], (string)$first_order_price, 2);
                $uni_first_order_price = (string)bcdiv((string)$first_order_price, (string)$cart['cart_num'], 4);
                $cart['truePrice'] = $cart['truePrice'] > $uni_first_order_price ? bcsub((string)$cart['truePrice'], $uni_first_order_price, 2) : 0;
            }
        }
        return $cartInfo;
    }

    /**
     * 计算订单商品积分实际抵扣金额
     * @param array $cartInfo
     * @param array $priceData
     * @return array
     */
    public function computeOrderProductIntegral(array $cartInfo, array $priceData)
    {
        $usedIntegral = $priceData['usedIntegral'] ?? 0;
        $deduction_price = $priceData['deduction_price'] ?? 0;
        if ($deduction_price) {
            $count = 0;
            $total_price = 0.00;
            $compute_price = 0.00;
            $integral_price = 0.00;
            $use_integral = 0;
            $compute_integral = 0;
            foreach ($cartInfo as $cart) {
                if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {
                    continue;
                }
                $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                $count++;
            }
            if ($total_price == $deduction_price) {
                $ratio = 1;
            } else {
                $ratio = (float)$total_price > 0 ? bcdiv((string)$deduction_price, (string)$total_price, 4) : 0;
            }
            foreach ($cartInfo as &$cart) {
				if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {
					continue;
				}
                if ($count > 1) {
                    $integral_price = bcmul((string)$cart['pay_price'], (string)$ratio, 2);
                    $compute_price = bcadd((string)$compute_price, (string)$integral_price, 2);
                    $use_integral = ((float)$total_price) ? bcmul((string)bcdiv((string)$cart['pay_price'], (string)$total_price, 4), (string)$usedIntegral, 0) : 0;
                    $compute_integral = bcadd((string)$compute_integral, $use_integral, 0);
                } else {
                    $integral_price = bcsub((string)$deduction_price, $compute_price, 2);
                    $use_integral = bcsub((string)$usedIntegral, $compute_integral, 0);
                }
                $count--;
                $cart['integral_price'] = $integral_price;
                $cart['use_integral'] = $use_integral;
            }
        }
        return $cartInfo;
    }

    /**
     * 计算订单商品优惠券实际抵扣金额
     * @param array $cartInfo
     * @param array $priceData
     * @return array
     */
    public function computeOrderProductCoupon(array $cartInfo, array $priceData, array $promotions = [], int $store_id = 0)
    {
        if ($priceData['coupon_id'] && $priceData['coupon_price'] ?? 0) {
            $count = 0;
            $total_price = 0.00;
            $compute_price = 0.00;
            $coupon_price = 0.00;
            /** @var StoreCouponUserServices $couponServices */
            $couponServices = app()->make(StoreCouponUserServices::class);
            $couponInfo = $couponServices->getOne(['id' => $priceData['coupon_id']], '*', ['issue']);
            if ($couponInfo) {
				//验证是否适用门店
//				if (isset($couponInfo['applicable_type']) && isset($couponInfo['applicable_store_id']) && isset($couponInfo['coupon_issue_type']) && isset($couponInfo['relation_id'])) {
//					$applicable_store_id = is_array($couponInfo['applicable_store_id']) ? $couponInfo['applicable_store_id'] : explode(',', $couponInfo['applicable_store_id']);
//                    if (!$store_id && in_array($couponInfo['coupon_issue_type'],[1,2]) || $store_id && ($couponInfo['applicable_type'] == 0 || ($couponInfo['applicable_type'] == 2 && !in_array($store_id, $applicable_store_id)) || ($couponInfo['coupon_issue_type'] == 1 && $couponInfo['relation_id'] != $store_id))) {
//                        return $cartInfo;
//                    }
//				}
                $promotionsList = [];
                if ($promotions) {
                    $promotionsList = array_combine(array_column($promotions, 'id'), $promotions);
                }
                $isOverlay = function ($cart) use ($promotionsList) {
                    $productInfo = $cart['productInfo'] ?? [];
                    if (!$productInfo) {
                        return false;
                    }
                    //门店独立商品 不使用优惠券
//                    $isBranchProduct = isset($productInfo['type']) && isset($productInfo['pid']) && $productInfo['type'] == 1 && !$productInfo['pid'];
//                    if ($isBranchProduct) {
//                        return false;
//                    }
                    if (isset($cart['promotions_id']) && $cart['promotions_id']) {
                        foreach ($cart['promotions_id'] as $key => $promotions_id) {
                            $promotions = $promotionsList[$promotions_id] ?? [];
                            if ($promotions && $promotions['promotions_type'] != 4) {
                                $overlay = is_string($promotions['overlay']) ? explode(',', $promotions['overlay']) : $promotions['overlay'];
                                if (!in_array(5, $overlay)) {
                                    return false;
                                }
                            }
                        }
                    }
                    return true;
                };
                $type = $couponInfo['coupon_applicable_type'] ?? 0;
                $counpon_id = $couponInfo['id'];
                switch ($type) {
                    case 0:
                        foreach ($cartInfo as $cart) {
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                            $count++;
                        }
                        foreach ($cartInfo as &$cart) {
							$cart['coupon_id'] = 0;
							$cart['coupon_price'] = 0;
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            if ($count > 1) {
                                $coupon_price = $total_price ? bcmul((string)bcdiv((string)$cart['pay_price'], (string)$total_price, 4), (string)$priceData['coupon_price'], 2) : 0;
                                $compute_price = bcadd((string)$compute_price, (string)$coupon_price, 2);
                            } else {
                                $coupon_price = bcsub((string)$priceData['coupon_price'], $compute_price, 2);
                            }
							$cart['coupon_id'] = $counpon_id;
                            $cart['coupon_price'] = $coupon_price;
							$cart['pay_price'] = max(bcsub((string)$cart['pay_price'], (string)$coupon_price, 2), 0);
                            $count--;
                        }
                        break;
                    case 1://品类券
                        /** @var StoreProductCategoryServices $storeCategoryServices */
                        $storeCategoryServices = app()->make(StoreProductCategoryServices::class);
                        $cateGorys = $storeCategoryServices->getAllById((int)$couponInfo['category_id']);
                        if ($cateGorys) {
                            $cateIds = array_column($cateGorys, 'id');
                            foreach ($cartInfo as $cart) {
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['cate_id']) && array_intersect(explode(',', $cart['productInfo']['cate_id']), $cateIds) || isset($cart['productInfo']['store_cate_id']) && array_intersect(explode(',', $cart['productInfo']['store_cate_id']), $cateIds)) {
                                    $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                                    $count++;
                                }
                            }
                            foreach ($cartInfo as &$cart) {
								$cart['coupon_id'] = 0;
								$cart['coupon_price'] = 0;
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['cate_id']) && array_intersect(explode(',', $cart['productInfo']['cate_id']), $cateIds) || isset($cart['productInfo']['store_cate_id']) && array_intersect(explode(',', $cart['productInfo']['store_cate_id']), $cateIds)) {
                                    if ($count > 1) {
                                        $coupon_price = $total_price ? bcmul((string)bcdiv((string)$cart['pay_price'], (string)$total_price, 4), (string)$priceData['coupon_price'], 2) : 0;
                                        $compute_price = bcadd((string)$compute_price, (string)$coupon_price, 2);
                                    } else {
                                        $coupon_price = bcsub((string)$priceData['coupon_price'], $compute_price, 2);
                                    }
                                    $cart['coupon_id'] = $counpon_id;
                                    $cart['coupon_price'] = $coupon_price;
									$cart['pay_price'] = max(bcsub((string)$cart['pay_price'], (string)$coupon_price, 2), 0);
                                    $count--;
                                }
                            }
                        }
                        break;
                    case 2://商品劵
                        foreach ($cartInfo as $cart) {
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $product_id = isset($cart['productInfo']['id']) && $cart['productInfo']['id'] ? $cart['productInfo']['id'] : ($cart['product_id'] ?? 0);
                            if ($product_id && in_array($product_id, explode(',', $couponInfo['product_id']))) {
                                $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                                $count++;
                            }

                        }
                        foreach ($cartInfo as &$cart) {
							$cart['coupon_id'] = 0;
							$cart['coupon_price'] = 0;
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $product_id = isset($cart['productInfo']['pid']) && $cart['productInfo']['pid'] ? $cart['productInfo']['pid'] : ($cart['product_id'] ?? 0);
                            if ($product_id && in_array($product_id, explode(',', $couponInfo['product_id']))) {
                                if ($count > 1) {
                                    $coupon_price = $total_price ? bcmul((string)bcdiv((string)$cart['pay_price'], (string)$total_price, 4), (string)$priceData['coupon_price'], 2) : 0;
                                    $compute_price = bcadd((string)$compute_price, (string)$coupon_price, 2);
                                } else {
                                    $coupon_price = bcsub((string)$priceData['coupon_price'], $compute_price, 2);
                                }
                                $cart['coupon_id'] = $counpon_id;
                                $cart['coupon_price'] = $coupon_price;
								$cart['pay_price'] = max(bcsub((string)$cart['pay_price'], (string)$coupon_price, 2), 0);
                                $count--;
                            }
                        }
                        break;
                    case 3://品牌券
                        /** @var StoreBrandServices $storeBrandServices */
                        $storeBrandServices = app()->make(StoreBrandServices::class);
                        $brands = $storeBrandServices->getAllById((int)$couponInfo['brand_id']);
                        if ($brands) {
                            $brandIds = array_column($brands, 'id');
                            foreach ($cartInfo as $cart) {
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['brand_id']) && in_array($cart['productInfo']['brand_id'], $brandIds)) {
                                    $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                                    $count++;
                                }
                            }
                            foreach ($cartInfo as &$cart) {
								$cart['coupon_id'] = 0;
								$cart['coupon_price'] = 0;
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['brand_id']) && in_array($cart['productInfo']['brand_id'], $brandIds)) {
                                    if ($count > 1) {
                                        $coupon_price = $total_price ? bcmul((string)bcdiv((string)$cart['pay_price'], (string)$total_price, 4), (string)$priceData['coupon_price'], 2) : 0;
                                        $compute_price = bcadd((string)$compute_price, (string)$coupon_price, 2);
                                    } else {
                                        $coupon_price = bcsub((string)$priceData['coupon_price'], $compute_price, 2);
                                    }
                                    $cart['coupon_id'] = $counpon_id;
                                    $cart['coupon_price'] = $coupon_price;
									$cart['pay_price'] = max(bcsub((string)$cart['pay_price'], (string)$coupon_price, 2), 0);
                                    $count--;
                                }
                            }
                        }
                        break;
                }
            }
        }
        return $cartInfo;
    }

    /**
     * 计算实际佣金
     * @param int $uid
     * @param array $cartInfo
     * @param $userInfo
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function computeOrderProductBrokerage(int $uid, array $cartInfo, $userInfo)
    {
        //获取后台一级返佣比例
        $storeBrokerageRatio = sys_config('store_brokerage_ratio');
        //获取二级返佣比例
        $storeBrokerageTwo = sys_config('store_brokerage_two');
        //佣金计算方式
        $brokerageComputeType = sys_config('brokerage_compute_type', 1);
        /** @var AgentLevelServices $agentLevelServices */
        $agentLevelServices = app()->make(AgentLevelServices::class);
        [$one_brokerage_up, $two_brokerage_up, $spread_uid, $spread_two_uid] = $agentLevelServices->getAgentLevelBrokerage($uid, $userInfo);
        // 二级分销开关
        if (sys_config('brokerage_level', 2) == 1) {
            $storeBrokerageTwo = $spread_two_uid = 0;
        }
        foreach ($cartInfo as &$cart) {
            $oneBrokerage = '0';//一级返佣金额
            $twoBrokerage = '0';//二级返佣金额
            $cartNum = (string)$cart['cart_num'] ?? '0';
			$productInfo = $cart['productInfo'] ?? [];
			$isBrokerage = $productInfo['is_brokerage'] ?? 1;
			$cartType = $cart['cart_type'] ?? 0;
			//不是赠品 && 不是卡项权益商品 && 商品参与分佣
            if ($productInfo && $isBrokerage && !$cartType) {
                //指定返佣金额
                if (isset($productInfo['is_sub']) && $productInfo['is_sub'] == 1) {
                    $oneBrokerage = bcmul((string)($productInfo['attrInfo']['brokerage'] ?? '0'), $cartNum, 2);
                    $twoBrokerage = bcmul((string)($productInfo['attrInfo']['brokerage_two'] ?? '0'), $cartNum, 2);
                } else {//比例返佣
                    $price = 0;
                    switch ($brokerageComputeType) {
                        case 1://售价
                            if (isset($productInfo['attrInfo'])) {
                                $price = bcmul((string)($productInfo['attrInfo']['price'] ?? '0'), $cartNum, 4);
                            } else {
                                $price = bcmul((string)($productInfo['price'] ?? '0'), $cartNum, 4);
                            }
                            break;
                        case 2://实付金额
							$price = $cart['pay_price'] ?? 0;
                            break;
                        case 3://商品利润
                            $price = bcsub((string)($cart['pay_price'] ?? 0), bcmul((string)($cart['costPrice'] ?? 0), $cartNum, 2), 2);
                            break;
                    }
                    if ($price > 0) {
                        //一级返佣比例 小于等于零时直接返回 不返佣
                        if ($storeBrokerageRatio > 0) {
                            //计算获取一级返佣比例
                            $brokerageRatio = bcdiv($storeBrokerageRatio, 100, 4);
                            $oneBrokerage = bcmul((string)$price, (string)$brokerageRatio, 2);
                        }
                        //二级返佣比例小于等于0 直接返回
                        if ($storeBrokerageTwo > 0) {
                            //计算获取二级返佣比例
                            $brokerageTwo = bcdiv($storeBrokerageTwo, 100, 4);
                            $twoBrokerage = bcmul((string)$price, (string)$brokerageTwo, 2);
                        }
                    }
                }

				//分销等级上浮佣金
				if ($one_brokerage_up) $oneBrokerage = bcadd((string)$oneBrokerage, (string)bcmul((string)$oneBrokerage, (string)bcdiv((string)$one_brokerage_up, '100', 2), 4), 2);
				if ($two_brokerage_up) $twoBrokerage = bcadd((string)$twoBrokerage, (string)bcmul((string)$twoBrokerage, (string)bcdiv((string)$two_brokerage_up, '100', 2), 4), 2);
            }

            $cart['one_brokerage'] = $oneBrokerage;
            $cart['two_brokerage'] = $twoBrokerage;
        }
        return [$cartInfo, [$spread_uid, $spread_two_uid]];
    }

    /**
     * 计算订单商品新人首单实际抵扣金额
     * @param array $cartInfo
     * @param array $priceData
     * @return array
     */
    public function computeOrderProductFirstDiscount(array $cartInfo, array $priceData)
    {
        $first_order_price = $priceData['first_order_price'] ?? 0;
        if ($first_order_price) {
            $count = 0;
            $total_price = 0.00;
            $compute_price = 0.00;
            $discount_price = 0.00;
            foreach ($cartInfo as $cart) {
                if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {
                    continue;
                }
                $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                $count++;
            }
            if ($total_price == $first_order_price) {
                $ratio = 1;
            } else {
                $ratio = (float)$total_price > 0 ? bcdiv((string)$first_order_price, (string)$total_price, 4) : 0;
            }
            foreach ($cartInfo as &$cart) {
                if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {
                    continue;
                }
                if ($count > 1) {
                    $discount_price = bcmul((string)$cart['pay_price'], (string)$ratio, 2);
                    $compute_price = bcadd((string)$compute_price, (string)$discount_price, 2);
                } else {
                    $discount_price = bcsub((string)$first_order_price, $compute_price, 2);
                }
                $count--;
                $cart['first_order_price'] = $discount_price;
            }
        }
        return $cartInfo;
    }
}

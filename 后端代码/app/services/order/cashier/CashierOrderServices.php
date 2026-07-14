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

namespace app\services\order\cashier;


use app\jobs\activity\StorePromotionsJob;
use app\jobs\user\MicroPayOrderJob;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\product\sku\StoreProductAttrValue;
use app\model\store\SystemStoreStaff;
use app\model\user\UserRecharge;
use app\services\activity\collage\UserCollagePartakeServices;
use app\services\activity\collage\UserCollageCodeServices;
use app\services\BaseServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\order\DeliveryConfigServices;
use app\services\order\OtherOrderServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderComputedServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderStatusServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\order\ValidCashOrderServices;
use app\services\order\StoreReservationOrderServices;
use app\model\yeji\CashType;
use app\services\pay\PayServices;
use app\services\pay\YuePayServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\level\UserLevelServices;
use app\services\user\UserAddressServices;
use app\services\user\UserInvoiceServices;
use app\services\user\UserMoneyServices;
use app\services\user\UserServices;
use app\services\yeji\SatffYejiServices;
use mohe\services\CacheService;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;
use think\facade\Db;
use function Swoole\Coroutine\batch;

/**
 * 收银台订单
 * Class CashierOrderServices
 * @package app\services\order\cashier
 */
class CashierOrderServices extends BaseServices
{

    use OptionTrait;

    //余额支付
    const YUE_PAY = 1;
    //线上支付
    const ONE_LINE_PAY = 2;
    //现金支付
    const CASH_PAY = 3;

    /**
     * 缓存订单信息
     * @param int $uid
     * @param array $cartInfo
     * @param array $priceGroup
     * @param array $other
     * @param array $addr
     * @param array $invalidCartInfo
     * @param array $deduction
     * @param int $cacheTime
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function cacheOrderInfo(int $uid, array $cartInfo, array $priceGroup, array $other = [], array $addr = [], array $invalidCartInfo = [], array $deduction = [], int $cacheTime = 600)
    {
        $key = md5($this->getUniqueId((string)$uid) . substr(implode('', array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8));
        CacheService::redisHandler()->set('admin_user_order_' . $uid . $key, compact('cartInfo', 'priceGroup', 'other', 'addr', 'invalidCartInfo', 'deduction'), $cacheTime);
        return $key;
    }

    /**
     * 获取订单缓存信息
     * @param int $uid
     * @param string $key
     * @return |null
     */
    public function getCacheOrderInfo(int $uid, string $key)
    {
        $cacheName = 'admin_user_order_' . $uid . $key;
        if (!CacheService::redisHandler()->has($cacheName)) return null;
        return CacheService::redisHandler()->get($cacheName);
    }

    /**
     * 获取订单确认数据
     * @param array $user
     * @param $cartId
     * @param bool $new
     * @param int $addressId
     * @param int $shipping_type
     * @param int $store_id
     * @param int $coupon_id
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderConfirmData(int $uid, $cartId, bool $new, int $addressId, int $shipping_type = 1, int $coupon_id = 0, int $store_id = 0, int $is_store_delivery_type = 0, array $addr = [])
    {
        $data = $user = [];
        if ($uid) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $user = $userServices->getUserCacheInfo($uid);
        }
        /** @var UserAddressServices $addressServices */
        $addressServices = app()->make(UserAddressServices::class);
        if ($addressId) {
            $addr = $addressServices->getAdderssCache($addressId);
        }
        //没传地址id或地址已删除未找到 ||获取默认地址
        if (!$addr && $uid) {
            $addr = $addressServices->getUserDefaultAddressCache($uid);
        }
        if ($store_id) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $store = $storeServices->getStoreInfo($store_id);
            if ($shipping_type == 3  && $is_store_delivery_type == 2 && $addr) {
                if(!in_array(3,$store['delivery_type'])) {
                    throw new ValidateException('该门店暂未开启同城配送！');
                }
				$addr['isCityStoreDeliveryScope'] = $storeServices->checkCityStoreDeliveryScope($uid, $store_id, $addr);
				if (!$addr['isCityStoreDeliveryScope']) {
					throw new ValidateException('地址有误、商家自配未开启、不在配送范围内!');
				}
            }
        }
        /** @var StoreCartServices $cartServices */
        $cartServices = app()->make(StoreCartServices::class);
        //获取购物车信息
        $cartGroup = $cartServices->getUserProductCartListV1($uid, $cartId, $new, $addr, $shipping_type, $store_id, $coupon_id,false,$is_store_delivery_type);
        $storeFreePostage = floatval(sys_config('store_free_postage')) ?: 0;//满额包邮金额
        $data['storeFreePostage'] = $storeFreePostage;
        $validCartInfo = $cartGroup['valid'];
        $giveCartList = $cartGroup['giveCartList'] ?? [];
        /** @var StoreOrderComputedServices $computedServices */
        $computedServices = app()->make(StoreOrderComputedServices::class);
        if ($shipping_type == 3 && $is_store_delivery_type == 2 && $store_id) {
            $priceGroup = $computedServices->getOrderPriceShippingCost($uid, $store_id, $validCartInfo, $addr);
        } else {
            $priceGroup = $computedServices->getOrderPriceGroup($uid, $validCartInfo, $addr, $storeFreePostage);
        }
        $priceGroup['couponPrice'] = $cartGroup['couponPrice'] ?? 0;
        $priceGroup['firstOrderPrice'] = $cartGroup['firstOrderPrice'] ?? 0;
        $validCartInfo = array_merge($priceGroup['cartInfo'] ?? $validCartInfo, $giveCartList);
        $other = [
            'offlinePostage' => sys_config('offline_postage'),
            'integralRatio' => sys_config('integral_ratio'),
            'give_integral' => $cartGroup['giveIntegral'] ?? 0,
            'give_coupon' => $cartGroup['giveCoupon'] ?? [],
            'give_product' => $cartGroup['giveProduct'],
            'promotions' => $cartGroup['promotions']
        ];
        $deduction = $cartGroup['deduction'];
        $data['product_type'] = $deduction['product_type'] ?? 0;
        $data['valid_count'] = count($validCartInfo);
        $data['addressInfo'] = $addr;
        $data['type'] = $deduction['type'] ?? 0;
        $data['activity_id'] = $deduction['activity_id'] ?? 0;
        $data['seckill_id'] = $deduction['type'] == 1 ? $deduction['activity_id'] : 0;
        $data['bargain_id'] = $deduction['type'] == 2 ? $deduction['activity_id'] : 0;
        $data['combination_id'] = $deduction['type'] == 3 ? $deduction['activity_id'] : 0;
        $data['storeIntegralId'] = $deduction['type'] == 4 ? $deduction['activity_id'] : 0;
        $data['discount_id'] = $deduction['type'] == 5 ? $deduction['activity_id'] : 0;
        $data['newcomer_id'] = $deduction['type'] == 7 ? $deduction['activity_id'] : 0;
        $data['deduction'] = in_array($deduction['product_type'], [1, 2]) || $deduction['activity_id'] > 0;
        $data['cartInfo'] = array_merge($cartGroup['cartInfo'], $giveCartList);
        // $data['giveCartInfo'] = $giveCartList;
        $data['custom_form'] = [];
        $reservationInfo = ['cart_num' => $validCartInfo[0]['cart_num'] ?? 1, 'reservation_type' => 2, 'reservation_time' => '', 'reservation_time_id' => 0, 'reservation_show_time' => ''];
        if ($data['product_type'] == 6) {
            $reservationTime = $validCartInfo[0]['reservation_time'] ?? '';
            $reservationTimeId = $validCartInfo[0]['reservation_time_id'] ?? 0;
            $reservationTimeInfo = [];
            if ($reservationTimeId) {
                /** @var StoreProductReservationTimeServices $reservationTimeServices */
                $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
                $reservationTimeInfo = $reservationTimeServices->get(['id' => $reservationTimeId]);
                if (!$reservationTimeInfo) {
                    throw new ValidateException('选择的时段无效');
                }
            }
            $reservationInfo = ['cart_num' => $validCartInfo[0]['cart_num'] ?? 1, 'reservation_type' => $validCartInfo[0]['reservation_type'] ?? 2, 'reservation_time' => $reservationTime, 'reservation_time_id' => $reservationTimeId, 'reservation_show_time' => $reservationTimeInfo['show_time'] ?? ''];
        }
        $data['reservationInfo'] = $other['reservationInfo'] = $reservationInfo;
        $data['give_integral'] = $other['give_integral'] ?? 0;
        $data['give_coupon'] = [];
        if ($other['give_coupon']) {
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            $data['give_coupon'] = $couponIssueService->getColumn([['id', 'IN', $other['give_coupon']]], 'id,coupon_title');
        }
        $data['orderKey'] = $this->cacheOrderInfo($uid, $validCartInfo, $priceGroup, $other, $addr, $cartGroup['invalid'] ?? [], $deduction);
        unset($priceGroup['cartInfo']);
        $data['priceGroup'] = $priceGroup;

        $userInfo = ['uid' => $user['uid'] ?? 0, 'nickname' => $user['nickname'] ?? '', 'avatar' => $user['avatar'] ?? '', 'phone' => $user['phone'] ?? '', 'now_money' => $user['now_money'] ?? 0, 'integral' => $user['integral'] ?? 0];
        //会员
        $userInfo['isMember'] = isset($user['is_money_level']) && $user['is_money_level'] > 0 ? 1 : 0;
        //等级
        $userInfo['level'] = $user['level'] ?? 0;
        $userInfo['level_status'] = 0;
        $userInfo['level_grade'] = '';
        $userInfo['vip'] = isset($priceGroup['vipPrice']) && $priceGroup['vipPrice'] > 0;
        $userInfo['vip_id'] = 0;
        $userInfo['discount'] = 0;
        //用户等级是否开启
        if (sys_config('member_func_status', 1)) {
            /** @var UserLevelServices $levelServices */
            $levelServices = app()->make(UserLevelServices::class);
            $userLevel = $levelServices->getUerLevelInfoByUid($uid);
            if ($userInfo['vip'] || $userLevel) {
                $userInfo['vip'] = true;
                $userInfo['vip_id'] = $userLevel['id'] ?? 0;
                $userInfo['discount'] = $userLevel['discount'] ?? 0;
            }
            if ($userInfo['level']) {
                /** @var SystemUserLevelServices $levelServices */
                $levelServices = app()->make(SystemUserLevelServices::class);
                $levelInfo = $levelServices->getOne(['id' => $userInfo['level']], 'id,name,grade');
                $userInfo['level_grade'] = $levelInfo['grade'] ?? '';
                $userInfo['level_status'] = 1;
            }
        }
        $userInfo['real_name'] = $user['real_name'] ?? $user['nickname'] ?? '';
        $userInfo['record_pone'] = $user['record_pone'] ?? $user['phone'] ?? '';
        $data['userInfo'] = $userInfo;
        $data['offlinePostage'] = $other['offlinePostage'];
        $data['integralRatio'] = $other['integralRatio'];
        $data['integral_ratio_status'] = (int)(sys_config('integral_ratio_status', 1) && in_array($data['type'], [0, 6]));

        $data['store_func_status'] = (int)(sys_config('store_func_status', 1));//门店是否开启
        $data['store_self_mention'] = false;//门店核销
        $data['store_delivery_status'] = false;//门店配送
        if ($data['store_func_status']) {
            //门店核销是否开启
            /** @var SystemStoreServices $systemStoreServices */
            $systemStoreServices = app()->make(SystemStoreServices::class);
            $data['store_self_mention'] = sys_config('store_self_mention') && $systemStoreServices->count(['type' => 0, 'delivery_type' => 2]);
            $data['store_delivery_status'] = !!$systemStoreServices->count(['type' => 0, 'delivery_type' => 3]);
        }
        $data['store_func_status'] = $data['store_func_status'] && ($data['store_self_mention'] || $data['store_delivery_status']);

        $data['system_store'] = [];//门店信息
        /** @var UserInvoiceServices $userInvoice */
        $userInvoice = app()->make(UserInvoiceServices::class);
        $invoice_func = $userInvoice->invoiceFuncStatus();
        $data['invoice_func'] = $invoice_func['invoice_func'];
        $data['special_invoice'] = $invoice_func['special_invoice'];
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $data['svip_status'] = $svip_status = $userServices->checkUserIsSvip($uid);
        $svip_price = 0.00;
        //开启付费会员 且用户不是付费会员 //计算 开通付费会员节省金额
        if (sys_config('member_card_status', 1) && !$svip_status) {
            $payPrice = $cartServices->getPayPrice((string)$priceGroup['totalPrice'], (string)($priceGroup['firstOrderPrice'] ?? 0));
            [$vipPayPrice, $payPostage, $storePostageDiscount] = $cartServices->computeUserVipCart($uid, $cartId, $shipping_type, $new, $store_id, false, $coupon_id, $addr, $is_store_delivery_type);
            $svip_price = (float)max(bcadd((string)bcsub((string)$payPrice, (string)$vipPayPrice, 2), (string)$storePostageDiscount, 2), 0);
        }
        $data['svip_price'] = $svip_price;
        return $data;
    }

    /**
     * 计算某个门店中收银台的金额
     * @param int $uid
     * @param int $storeId
     * @param array $cartIds
     * @param bool $integral
     * @param bool $coupon
     * @param array $userInfo
     * @param int $coupon_id
     * @param bool $new
     * @param array $cartGroup
     * @param int $addressId
     * @param string $payType
     * @param int $shippingType
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function computeOrder(int $uid = 0, int $storeId = 0, array $cartIds = [], bool $integral = false, bool $coupon = false, array $userInfo = [], int $coupon_id = 0, bool $new = false, array $cartGroup = [], int $addressId = 0, string $payType = 'yue', int $shippingType = 4, int $is_store_delivery_type = 0, array $addressInfo = [], array $changeCartInfo = [], bool $isPrice = false, float $changePrice = 0.00, array $cartCoupons = [])
    {
        if (!$userInfo && $uid) {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            $userInfo = $userService->getUserInfo($uid);
            if (!$userInfo) {
                throw new ValidateException('用户不存在');
            }
            $userInfo = $userInfo->toArray();
        }
        $payPostage = 0;
        $storePostageDiscount = 0;
        $firstOrderPrice = 0;
        /** @var StoreOrderComputedServices $computeOrderService */
        $computeOrderService = app()->make(StoreOrderComputedServices::class);
        if ($cartGroup && $shippingType != 4) {
            $cartInfo = $cartGroup['cartInfo'];
            $priceGroup = $cartGroup['priceGroup'];
            $deduction = $cartGroup['deduction'];
            $other = $cartGroup['other'];
            $promotions = $other['promotions'] ?? [];
            $payPrice = (float)$priceGroup['totalPrice'];
            $payIntegral = (int)$priceGroup['totalIntegral'] ?? 0;
            $couponPrice = (float)$priceGroup['couponPrice'];
            $firstOrderPrice = (float)$priceGroup['firstOrderPrice'];

            $sumPrice = $priceGroup['sumPrice'];//获取订单原总金额
            $totalPrice = $priceGroup['totalPrice'];//获取订单svip、用户等级优惠之后总金额
			$settlePrice = $priceGroup['settlePrice'] ?? 0;//供应商商品结算金额
            $costPrice = $priceGroup['costPrice'];//获取订单成本价
            $vipPrice = $priceGroup['vipPrice'];//获取订单会员优惠金额
            $changePrice = (float)$priceGroup['changePrice'] ?? 0.00;
            $promotionsPrice = $computeOrderService->getOrderSumPrice($cartInfo, 'promotions_true_price');//优惠活动优惠

            $addr = $cartGroup['addr'] ?? $addressInfo;
            $postage = $priceGroup;
            if($addressId && $addr && isset($addr['id']) && $addr['id'] != $addressId) {
                /** @var UserAddressServices $addressServices */
                $addressServices = app()->make(UserAddressServices::class);
                $addr = $addressServices->getAdderssCache($addressId);
                //改变地址重新计算邮费
                $postage = [];
            }

            $type = (int)$deduction['type'] ?? 0;
            $results = batch([
                'promotions' => function () use ($cartInfo, $type) {
                    $promotionsPrice = 0;
                    if ($type == 8) return $promotionsPrice;
                    foreach ($cartInfo as $key => $cart) {
                        if (isset($cart['promotions_true_price']) && isset($cart['price_type']) && $cart['price_type'] == 'promotions') {
                            $promotionsPrice = bcadd((string)$promotionsPrice, (string)bcmul((string)$cart['promotions_true_price'], (string)$cart['cart_num'], 2), 2);
                        }
                    }
                    return $promotionsPrice;
                },
                'postage' => function () use ($uid, $shippingType, $payType, $cartInfo, $addr, $payPrice, $postage, $other, $type, $computeOrderService,$storeId,$is_store_delivery_type) {
                    return $computeOrderService->computedPayPostage($uid, $shippingType, $payType, $cartInfo, $addr, $payPrice, $postage, $other,$storeId,$is_store_delivery_type);
                },
            ]);

            [$p, $payPostage, $storePostageDiscount, $storeFreePostage, $isStoreFreePostage, $poorFreeShipping, $poorDeliveryPrice, $sameCityStoreFreePostage] = $results['postage'];

        } else {
            /** @var StoreCartServices $cartServices */
            $cartServices = app()->make(StoreCartServices::class);
            $couponComputedOnOrder = false;
            $useCartItemCoupons = !empty($cartCoupons);
            $effectiveCouponId = $useCartItemCoupons ? 0 : $coupon_id;
            // 改价后选券：按改价后的整单金额计算券抵扣（避免沿用原价计算导致 600-200=400）
            if ($isPrice && $changeCartInfo) {
                // 不携带 coupon_id，避免系统把券分摊到商品并基于原价计算
                $cartGroup = $cartServices->getUserProductCartListV1($uid, $cartIds, $new, [], 4, $storeId, 0);
            } else {
                // 普通场景沿用原逻辑（明细用券时不走整单券）
                $cartGroup = $cartServices->getUserProductCartListV1($uid, $cartIds, $new, [], 4, $storeId, $effectiveCouponId);
            }
            $cartInfo = $cartGroup['valid'];
            if (!$cartInfo) {
                throw new ValidateException('购物车暂无货物！');
            }
            $deduction = $cartGroup['deduction'];
            $promotions = $cartGroup['promotions'] ?? [];
            $other = [
                'offlinePostage' => sys_config('offline_postage'),
                'integralRatio' => sys_config('integral_ratio'),
                'give_integral' => $cartGroup['giveIntegral'] ?? 0,
                'give_coupon' => $cartGroup['giveCoupon'] ?? [],
                'give_product' => $cartGroup['giveProduct'],
                'promotions' => $cartGroup['promotions']
            ];
            $reservationInfo = ['cart_num' => $cartInfo[0]['cart_num'] ?? 1, 'reservation_type' => 2, 'service_staff_id' => 0, 'real_name' => '', 'user_phone' => '', 'reservation_time' => '', 'reservation_time_id' => 0, 'reservation_show_time' => ''];
            if (($deduction['product_type'] ?? 0) == 6) {
                $reservationTime = $cartInfo[0]['reservation_time'] ?? '';
                $reservationTimeId = $cartInfo[0]['reservation_time_id'] ?? 0;
                $reservationTimeInfo = [];
                if ($reservationTimeId) {
                    /** @var StoreProductReservationTimeServices $reservationTimeServices */
                    $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
                    $reservationTimeInfo = $reservationTimeServices->get(['id' => $reservationTimeId]);
                    if (!$reservationTimeInfo) {
                        throw new ValidateException('选择的时段无效');
                    }
                }
                $reservationInfo = [
                    'cart_num' => $cartInfo[0]['cart_num'] ?? 1,
                    'reservation_type' => $cartInfo[0]['reservation_type'] ?? 2,
                    'service_staff_id' => $cartInfo[0]['service_staff_id'] ?? 0,
                    'real_name' => $cartInfo[0]['real_name'] ?? '',
                    'user_phone' => $cartInfo[0]['user_phone'] ?? '',
                    'reservation_time' => $reservationTime,
                    'reservation_time_id' => $reservationTimeId,
                    'reservation_show_time' => $reservationTimeInfo['show_time'] ?? '',
                ];
            }
            $other['reservationInfo'] = $reservationInfo;
            $cartGroup['other'] = $other;

            // 应用改价：将前端传来的 true_price 写入 pay_price，使后续计算基于改价后金额
            if ($isPrice && $changeCartInfo) {
                $originPayPrice = (float)$computeOrderService->getOrderSumPrice($cartInfo, 'pay_price', false);
                [, $changedCartInfo] = $this->changeOrderPrice($cartInfo, $changeCartInfo, (float)$changePrice, $originPayPrice);
                $cartInfo = $changedCartInfo;
                $cartGroup['valid'] = $cartInfo;
                $couponComputedOnOrder = true;

                // 计算优惠券抵扣金额（整单口径；明细用券时跳过）
                if (!$useCartItemCoupons && $coupon_id) {
                    try {
                        /** @var \app\services\activity\promotions\StorePromotionsServices $promotionsServices */
                        $promotionsServices = app()->make(\app\services\activity\promotions\StorePromotionsServices::class);
                        [, $trueCouponPrice] = $promotionsServices->useCoupon((int)$coupon_id, (int)$uid, $cartInfo, $promotions, (int)$storeId);
                        $cartGroup['couponPrice'] = (float)$trueCouponPrice;
                    } catch (\Throwable $e) {
                        $cartGroup['couponPrice'] = 0;
                    }
                } else {
                    $cartGroup['couponPrice'] = 0;
                }
            }

            // 明细行优惠券：仅抵扣对应商品行
            if ($useCartItemCoupons) {
                [$cartInfo, $itemCouponTotal] = $this->applyCartItemCoupons($cartInfo, (int)$uid, $cartCoupons, $promotions, (int)$storeId);
                $cartGroup['valid'] = $cartInfo;
                $cartGroup['couponPrice'] = (float)$itemCouponTotal;
                // 券额已写入各行 pay_price，避免后续整单再扣一次
                $couponComputedOnOrder = false;
            }

            // 定制卡壳(8154)仅展示合并价，实付由内部项目承担
            [$cartInfo, $totalPriceOverride] = $this->resolveCustomCardOrderPayPrice($cartInfo, 0);
            $cartGroup['valid'] = $cartInfo;

            $sumPrice = $computeOrderService->getOrderSumPrice($cartInfo, 'sum_price');//获取订单原总金额
            $totalPrice = $computeOrderService->getOrderSumPrice($cartInfo, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额
            if ($totalPriceOverride > 0) {
                $totalPrice = $totalPriceOverride;
            }
			$settlePrice = (float)$computeOrderService->getOrderSumPrice($cartInfo, 'settle_price', false);//结算总价
            $costPrice = $computeOrderService->getOrderSumPrice($cartInfo, 'costPrice');//获取订单成本价
            $vipPrice = $computeOrderService->getOrderSumPrice($cartInfo, 'vip_truePrice');//获取订单会员优惠金额
            $promotionsPrice = $computeOrderService->getOrderSumPrice($cartInfo, 'promotions_true_price');//优惠活动优惠
            $changePrice = (float)$computeOrderService->getOrderSumPrice($cartInfo, 'change_price', false);//获取改价优惠金额

            $payPrice = (float)$totalPrice;
            $couponPrice = floatval($cartGroup['couponPrice'] ?? 0);
			//合并赠品
			$cartInfo = array_merge($cartGroup['valid'], $cartGroup['giveCartList']);
        }
        $promotionsDetail = [];
        if ($promotions) {
            foreach ($promotions as $key => $value) {
                if (isset($value['details']['sum_promotions_price']) && $value['details']['sum_promotions_price']) {
                    $promotionsDetail[] = ['id' => $value['id'], 'name' => $value['name'], 'title' => $value['title'], 'desc' => $value['desc'], 'promotions_price' => $value['details']['sum_promotions_price'], 'promotions_type' => $value['promotions_type']];
                }
            }
            if ($promotionsDetail) {
                $typeArr = array_column($promotionsDetail, 'promotions_type');
                array_multisort($typeArr, SORT_ASC, $promotionsDetail);
            }
        }
        $is_cashier_yue_pay_verify = (int)sys_config('is_cashier_yue_pay_verify'); // 收银台余额支付是否需要验证【是/否】

        if ($firstOrderPrice < $payPrice) {//首单优惠金额
            $payPrice = bcsub((string)$payPrice, (string)$firstOrderPrice, 2);
        } else {
            $payPrice = 0;
        }

        // 改价后选券：couponPrice 没有体现在 payPrice 中，需要这里整单扣减
        if (isset($couponComputedOnOrder) && $couponComputedOnOrder && $couponPrice > 0) {
            $payPrice = max((float)bcsub((string)$payPrice, (string)$couponPrice, 2), 0);
        }
        // 优惠券未分摊到商品行时，整单扣减券额（避免余额仍按券前金额扣款）
        if ($couponPrice > 0 && $cartInfo && !(isset($couponComputedOnOrder) && $couponComputedOnOrder)) {
            $deductedOnLines = '0';
            foreach ($cartInfo as $c) {
                if (isset($c['cart_type']) && (int)$c['cart_type'] > 0) {
                    continue;
                }
                $deductedOnLines = bcadd($deductedOnLines, (string)($c['coupon_price'] ?? 0), 2);
            }
            if (bccomp($deductedOnLines, (string)$couponPrice, 2) < 0) {
                $needDeduct = bcsub((string)$couponPrice, $deductedOnLines, 2);
                $payPrice = max((float)bcsub((string)$payPrice, $needDeduct, 2), 0);
            }
        }
        $SurplusIntegral = $usedIntegral = 0;
        $deductionPrice = '0';
        //使用积分
        if ($userInfo && $integral && sys_config('integral_ratio_status', 0)) {
            [
                $payPrice,
                $deductionPrice,
                $usedIntegral,
                $SurplusIntegral
            ] = $computeOrderService->useIntegral(true, $userInfo, $payPrice, [
                'offlinePostage' => sys_config('offline_postage'),
                'integralRatio' => sys_config('integral_ratio')
            ]);
        }

        $payPrice = (float)bcadd((string)$payPrice, (string)$payPostage, 2);

        $yue_pay_status = (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? (int)1 : (int)2;//余额支付 1 开启 2 关闭
        //验证门店是否开启使用余额支付
        if ($yue_pay_status == 1 && $storeId) {
            /** @var SystemStoreServices $systemStoreServices */
            $systemStoreServices = app()->make(SystemStoreServices::class);
            $storeInfo = $systemStoreServices->get((int)$storeId, ['id', 'use_system_money']);
            $yue_pay_status = $storeInfo['use_system_money'] ? $yue_pay_status : 2;
        }

        return [
            'payPrice' => floatval($payPrice),//支付金额
			'settlePrice' => floatval($settlePrice),//结算金额
            'vipPrice' => floatval($vipPrice),//会员优惠金额
            'totalPrice' => floatval($totalPrice),//会员优惠后订单金额
            'costPrice' => floatval($costPrice),//成本金额
            'sumPrice' => floatval($sumPrice),//订单总金额
            'firstOrderPrice' => floatval($firstOrderPrice),//首单优惠
            'couponPrice' => (float)$couponPrice,//优惠券金额
            'pay_postage' => (float)$payPostage ?? 0,
            'storePostageDiscount' => (float)$storePostageDiscount ?? 0,
            'promotionsPrice' => floatval($promotionsPrice),//优惠活动金额
            'promotionsDetail' => $promotionsDetail,//优惠
            'deductionPrice' => floatval($deductionPrice),//积分抵扣多少钱
            'surplusIntegral' => $SurplusIntegral,//抵扣了多少积分
            'usedIntegral' => $usedIntegral,//使用了多少积分
            'deduction' => $deduction,
            'changePrice' => $changePrice,
            'isStoreFreePostage' => $isStoreFreePostage ?? false,
            'storeFreePostage' => (float)($storeFreePostage ?? 0),
            'sameCityStoreFreePostage' => (float)($sameCityStoreFreePostage ?? 0),
            'poorFreeShipping' => (float)($poorFreeShipping ?? 0),//差多少包邮
            'poorDeliveryPrice' => (float)($poorDeliveryPrice ?? 0),//差多少起送
            'servicePrice' => 0.00,
            'cartInfo' => $cartInfo,//购物列表
            'is_cashier_yue_pay_verify' => $is_cashier_yue_pay_verify,//收银台余额支付验证 1 验证 0不验证
            'cashier_operator_gift_switch' => (int)sys_config('cashier_operator_gift_switch', 1),//前台收银支持操作员赠送 1开启 0关闭
            'cashier_debt_pay_switch' => (int)sys_config('cashier_debt_pay_switch', 0),//前台收银是否允许欠款 1开启 0关闭
            'cartGroup' => $cartGroup,//计算结果
			'offline_pay_status' => (int)sys_config('offline_pay_status'),//线下支付1开启2关闭
            'yue_pay_status' => (int)$yue_pay_status,//余额支付 1 开启 2 关闭
            'ali_pay_status' => (int)sys_config('ali_pay_status'),//支付宝支付 1 开启 0 关闭
            'pay_weixin_open' => (int)sys_config('pay_weixin_open') ?? 0,//微信支付 1 开启 0 关闭
        ];
    }

    /**
     * 定制卡壳商品 ID（8154）
     */
    protected function getCustomCardShellProductId(): int
    {
        return 8154;
    }

    /**
     * 是否定制卡壳行（仅 8154 本身，不含 pid=8154 的内项）
     */
    protected function isCustomCardShellCart(array $cart): bool
    {
        if ((int)($cart['product_id'] ?? 0) === $this->getCustomCardShellProductId()) {
            return true;
        }
        $info = $cart['productInfo'] ?? [];
        if ((int)($info['id'] ?? 0) === $this->getCustomCardShellProductId()) {
            return true;
        }
        $name = (string)($info['store_name'] ?? '');
        if ((int)($info['pid'] ?? 0) === $this->getCustomCardShellProductId() && strpos($name, '定制卡') !== false) {
            return true;
        }
        return false;
    }

    /**
     * 定制卡内项 pay_price 合计（壳行不计）
     */
    protected function sumCustomCardBundlePayPrice(array $cartInfo): float
    {
        $sum = '0';
        foreach ($cartInfo as $cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            if ($this->isCustomCardShellCart($cart)) {
                continue;
            }
            $sum = bcadd($sum, (string)($cart['pay_price'] ?? 0), 2);
        }
        return (float)$sum;
    }

    /**
     * 定制卡内项 pay_price 若为单价则补乘 cart_num
     */
    protected function normalizeCustomCardBundleLinePayPrices(array $cartInfo): array
    {
        if (!$this->hasCustomCardShell($cartInfo)) {
            return $cartInfo;
        }
        foreach ($cartInfo as &$cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            if ($this->isCustomCardShellCart($cart)) {
                continue;
            }
            $num = max((int)($cart['cart_num'] ?? 1), 1);
            if ($num <= 1) {
                continue;
            }
            $unit = (string)($cart['truePrice'] ?? $cart['sum_price'] ?? 0);
            $pp = (string)($cart['pay_price'] ?? 0);
            if (bccomp($unit, '0', 2) <= 0) {
                continue;
            }
            $expected = bcmul($unit, (string)$num, 2);
            if (bccomp($pp, $expected, 2) >= 0) {
                continue;
            }
            if (bccomp($pp, $unit, 2) === 0) {
                $cart['pay_price'] = $expected;
                $cart['total_price'] = $expected;
            }
        }
        unset($cart);
        return $cartInfo;
    }

    /**
     * 定制卡：壳行清零后，订单应付=内项 pay_price 之和
     */
    protected function resolveCustomCardOrderPayPrice(array $cartInfo, float $fallback = 0.00): array
    {
        if (!$this->hasCustomCardShell($cartInfo)) {
            return [$cartInfo, $fallback];
        }
        $cartInfo = $this->normalizeCustomCardBundleLinePayPrices($cartInfo);
        $cartInfo = $this->normalizeCustomCardShellPayPrice($cartInfo);
        $bundlePay = $this->sumCustomCardBundlePayPrice($cartInfo);
        if (bccomp((string)$bundlePay, '0', 2) > 0) {
            return [$cartInfo, (float)$bundlePay];
        }
        return [$cartInfo, $fallback];
    }

    /**
     * 购物车是否含定制卡壳
     */
    protected function hasCustomCardShell(array $cartInfo): bool
    {
        foreach ($cartInfo as $cart) {
            if ($this->isCustomCardShellCart($cart)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 定制卡壳不计入应付，避免与内部项目重复计费（仅清零壳行，内项保留单价×数量）
     * @param array $cartInfo
     * @return array
     */
    protected function normalizeCustomCardShellPayPrice(array $cartInfo): array
    {
        if (!$cartInfo || !$this->hasCustomCardShell($cartInfo)) {
            return $cartInfo;
        }
        foreach ($cartInfo as &$cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            if (!$this->isCustomCardShellCart($cart)) {
                continue;
            }
            $cart['pay_price'] = 0;
            $cart['total_price'] = 0;
            $cart['truePrice'] = 0;
            $cart['sum_price'] = 0;
            $cart['change_price'] = 0;
        }
        unset($cart);
        return $cartInfo;
    }

    /**
     * 收银台明细行优惠券：每张券仅抵扣对应购物车行
     * @param array $cartInfo
     * @param int $uid
     * @param array $cartCoupons [['cart_id'=>1,'coupon_id'=>2], ...]
     * @param array $promotions
     * @param int $storeId
     * @return array [cartInfo, totalCouponPrice]
     */
    protected function applyCartItemCoupons(array $cartInfo, int $uid, array $cartCoupons, array $promotions, int $storeId): array
    {
        if (!$cartCoupons) {
            return [$cartInfo, 0.0];
        }
        $couponMap = [];
        foreach ($cartCoupons as $row) {
            $cartId = (int)($row['cart_id'] ?? 0);
            $couponUserId = (int)($row['coupon_id'] ?? 0);
            if ($cartId && $couponUserId) {
                $couponMap[$cartId] = $couponUserId;
            }
        }
        if (!$couponMap) {
            return [$cartInfo, 0.0];
        }

        /** @var \app\services\activity\promotions\StorePromotionsServices $promotionsServices */
        $promotionsServices = app()->make(\app\services\activity\promotions\StorePromotionsServices::class);
        /** @var StoreOrderCreateServices $orderCreateServices */
        $orderCreateServices = app()->make(StoreOrderCreateServices::class);
        /** @var StoreCouponUserServices $couponUserServices */
        $couponUserServices = app()->make(StoreCouponUserServices::class);

        $totalCouponPrice = 0.0;
        $cartIndexMap = [];
        foreach ($cartInfo as $idx => $cart) {
            $cartIndexMap[(int)($cart['id'] ?? 0)] = $idx;
        }

        foreach ($couponMap as $cartId => $couponUserId) {
            if (!isset($cartIndexMap[$cartId])) {
                continue;
            }
            $idx = $cartIndexMap[$cartId];
            $cart = $cartInfo[$idx];
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }

            $singleCart = [$cart];
            try {
                [$useCoupon, $couponPrice] = $promotionsServices->useCoupon($couponUserId, $uid, $singleCart, $promotions, $storeId);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$couponPrice || $couponPrice <= 0) {
                continue;
            }

            $singleCart = $orderCreateServices->computeOrderProductCoupon(
                $singleCart,
                ['coupon_id' => $couponUserId, 'coupon_price' => $couponPrice],
                $promotions,
                $storeId
            );
            $updated = $singleCart[0];
            $couponTitle = '';
            if ($useCoupon) {
                $couponTitle = $useCoupon['coupon_title'] ?? ($useCoupon['title'] ?? '');
            }
            if (!$couponTitle) {
                $couponRow = $couponUserServices->getOne(['id' => $couponUserId], 'coupon_title');
                $couponTitle = $couponRow['coupon_title'] ?? '';
            }
            $lineCouponPrice = (float)($updated['coupon_price'] ?? $couponPrice);
            $updated['coupon_info'] = [
                'coupon_id' => $couponUserId,
                'coupon_name' => $couponTitle,
                'coupon_amount' => $lineCouponPrice,
            ];
            $cartInfo[$idx] = $updated;
            $totalCouponPrice = (float)bcadd((string)$totalCouponPrice, (string)$lineCouponPrice, 2);
        }

        return [$cartInfo, $totalCouponPrice];
    }

    /**
     * 收银台用户优惠券
     * @param int $uid
     * @param int $storeId
     * @param array $cartIds
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCouponList(int $uid, int $storeId, array $cartIds)
    {
        /** @var StoreCartServices $cartService */
        $cartService = app()->make(StoreCartServices::class);
        $where = ['status' => 1, 'store_id' => $storeId];
        $cart = $cartService->getUserCartList($uid, $where, $cartIds, 4);
        $cartInfo = $cart['valid'];
        if (!$cartInfo) {
            throw new ValidateException('购物车暂无货物！');
        }
        /** @var StoreCouponissueServices $couponIssueServices */
        $couponIssueServices = app()->make(StoreCouponissueServices::class);
        return $couponIssueServices->getCanUseCoupon($uid, $cartInfo, $cart['promotions'] ?? [], $storeId, false);
    }

    /**
     * 二位数组冒泡排序
     * @param array $arr
     * @param string $key
     * @return array
     */
    protected function mpSort(array $arr, string $key)
    {
        for ($i = 0; $i < count($arr); $i++) {
            for ($j = $i; $j < count($arr); $j++) {
                if ($arr[$i][$key] > $arr[$j][$key]) {
                    $temp = $arr[$i];
                    $arr[$i] = $arr[$j];
                    $arr[$j] = $temp;
                }
            }
        }
        return $arr;
    }

    /**
     * 自动解析扫描二维码
     * @param string $barCode
     * @param int $storeId
     * @param int $uid
     * @param int $staff_id
     * @param int $touristUid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAnalysisCode(string $barCode, int $storeId, int $uid, int $staff_id, int $touristUid = 0)
    {
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        $userInfo = $userService->get(['bar_code' => $barCode], ['uid', 'avatar', 'nickname', 'now_money', 'integral']);
        if ($userInfo) {
            return ['userInfo' => $userInfo->toArray()];
        } else {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffServices->getStaffInfo($staff_id);

            /** @var StoreProductAttrValueServices $storeProductAttrService */
            $storeProductAttrService = app()->make(StoreProductAttrValueServices::class);
            $attProductInfo = $storeProductAttrService->getAttrByBarCode((string)$barCode);
            if (!$attProductInfo) {
                throw new ValidateException('没有扫描到商品');
            }
            /** @var StoreProductServices $productService */
            $productService = app()->make(StoreProductServices::class);
            $productInfo = $productService->get(['is_show' => 1, 'is_del' => 0, 'id' => $attProductInfo->product_id], [
                'image', 'store_name', 'store_info', 'bar_code', 'price', 'id as product_id', 'id'
            ]);
            if (!$productInfo) {
                throw new ValidateException('商品未查到');
            }
            /** @var StoreBranchProductServices $storeProductService */
            $storeProductService = app()->make(StoreBranchProductServices::class);
            if (!$storeProductService->count(['store_id' => $storeId, 'product_id' => $attProductInfo->product_id])) {
                throw new ValidateException('该商品在此门店不存在');
            }
            /** @var StoreProductAttrValueServices $valueService */
            $valueService = app()->make(StoreProductAttrValueServices::class);
            $valueInfo = $valueService->getOne(['unique' => $attProductInfo->unique]);
            if (!$valueInfo) {
                throw new ValidateException('商品属性不存在');
            }
            $productInfo = $productInfo->toArray();
            $productInfo['attr_value'] = [
                'ot_price' => $valueInfo->ot_price,
                'price' => $valueInfo->price,
                'sales' => $valueInfo->sales,
                'vip_price' => $valueInfo->vip_price,
                'stock' => $attProductInfo->stock,
            ];
            if ($uid || $touristUid) {
                /** @var StoreCartServices $cartService */
                $cartService = app()->make(StoreCartServices::class);
                $cartService->setItem('store_id', $storeId);
                $cartService->setItem('tourist_uid', $touristUid);
                $cartId = $cartService->addCashierCart($uid, $attProductInfo->product_id, 1, $attProductInfo->unique, $staff_id);
                $cartService->reset();
                if (!$cartId) {
                    throw new ValidateException('自动添加购物车失败');
                }
            } else {
                $cartId = 0;
            }
            return ['productInfo' => $productInfo, 'cartId' => $cartId];
        }
    }

    /**
     * 收银台商品改价
     * @param array $cartInfo
     * @param array $data
     * @param float $changePrice
     * @param float $payPrice
     * @return array
     */
    public function changeOrderPrice(array $cartInfo, array $data = [], float $changePrice = 0.00, float $payPrice = 0.00)
    {
        if (!$cartInfo) {
            return [0.00, $cartInfo];
        }
		$oldPayPrice = $payPrice;
        if ($data) {//单个商品改价
            $ids = array_column($data, 'id');
            $data = array_combine($ids, $data);
            $pay_price = 0.00;
            foreach ($cartInfo as &$cart) {
                $key = $cart['id'];
                if (!isset($data[$key]['true_price'])) continue;
                //改成单类商品，多件的支付金额
                $payPrice = $data[$key]['true_price'];
                $cart['change_price'] = bcsub((string)$cart['pay_price'], (string)$payPrice, 2);
                $cart['pay_price'] = $payPrice;
                $cart['truePrice'] = bcdiv((string)$payPrice, (string)$cart['cart_num'], 2);
                $cart['sum_price'] = $cart['truePrice'];
                $cart['total_price'] = $payPrice;
                $cart['postage_price'] = 0;
                $pay_price = bcadd((string)$pay_price, (string)$payPrice, 2);
            }
        } else {//整体改价需要计算每个商品改价优惠金额
            $rate = $changePrice > 0 ? bcdiv((string)$changePrice, (string)$payPrice, 4) : 0;
            $count = count($cartInfo);
            $computePayPrice = 0.00;
            foreach ($cartInfo as &$cart) {
                $cartPayPrice = (string)$cart['pay_price'];
                if ($count > 1) {
                    $cart['pay_price'] = bcmul((string)$cart['pay_price'], (string)$rate, 2);
                    $computePayPrice = bcadd((string)$computePayPrice, $cart['pay_price'], 2);
                } else {
                    $cart['pay_price'] = bcsub((string)$changePrice, $computePayPrice, 2);
                }
                $cart['truePrice'] = $cart['pay_price'] > 0 ? bcdiv((string)$cart['pay_price'], (string)$cart['cart_num'], 2) : 0;
                $cart['sum_price'] = $cart['truePrice'];
                $cart['total_price'] = $cart['pay_price'];
                $cart['change_price'] = max(bcsub($cartPayPrice, $cart['pay_price'], 2), 0);
                $count--;
            }
            $pay_price = $changePrice;
        }
		//记录改价优惠金额
		$change_price = (float)bcsub((string)$oldPayPrice, (string)$pay_price, 2);
        return [(float)$pay_price, $cartInfo, $change_price];
    }

    /**
     * 解析赠送配置中的商品ID（兼容平台/门店副本；赠送品允许未上架展示）
     */
    protected function resolveSendProductId(int $productId, int $storeId): int
    {
        if ($productId <= 0) {
            throw new ValidateException('赠送商品无效');
        }
        /** @var StoreBranchProductServices $branchServices */
        $branchServices = app()->make(StoreBranchProductServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);

        if ($storeId > 0) {
            $branch = $branchServices->getOne([
                'id' => $productId,
                'type' => 1,
                'relation_id' => $storeId,
                'is_del' => 0,
            ], 'id');
            if ($branch) {
                return (int)$branch['id'];
            }
            $platformId = $branchServices->getStoreProductId($productId) ?: $productId;
            $branch = $branchServices->getOne([
                'pid' => $platformId,
                'type' => 1,
                'relation_id' => $storeId,
                'is_del' => 0,
            ], 'id');
            if ($branch) {
                return (int)$branch['id'];
            }
        }

        if ($productServices->isValidProduct($productId, true)) {
            return $productId;
        }
        $platformId = $branchServices->getStoreProductId($productId);
        if ($platformId > 0 && $platformId !== $productId && $productServices->isValidProduct($platformId, true)) {
            return $platformId;
        }
        if ($productServices->get(['id' => $productId, 'is_del' => 0])) {
            return $productId;
        }
        throw new ValidateException('赠送商品已下架或删除');
    }

    /**
     * 收银台下单是否跳过库存扣减（次卡/卡项/项目/赠送行等）
     */
    protected function cashierShouldSkipStock(array $cart, array $giveIds = []): bool
    {
        $productType = (int)($cart['product_type'] ?? ($cart['productInfo']['product_type'] ?? 0));
        if (in_array($productType, [4, 5, 6], true)) {
            return true;
        }
        $cartItemType = (int)($cart['type'] ?? 0);
        if (in_array($cartItemType, [11, 12], true)) {
            return true;
        }
        if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
            return true;
        }
        $cartId = (int)($cart['id'] ?? 0);
        if ($cartId > 0 && $giveIds && in_array($cartId, $giveIds, true)) {
            return true;
        }
        if (bccomp((string)($cart['pay_price'] ?? '0'), '0', 2) === 0) {
            return true;
        }
        if (array_key_exists('price', $cart) && bccomp((string)$cart['price'], '0', 2) === 0) {
            return true;
        }
        return false;
    }

    /**
     * 收银台扣减/累加库存：无库存概念的品项仅加销量，不阻断下单
     */
    protected function decCashierGoodsStock(array $cartInfo, int $storeId, array $giveIds = []): void
    {
        // 库存改造：收银台下单不扣实物库存、不加销量；支付成功后再处理。
        // 活动 Redis 额度仍由下单前 popStock 处理。
        return;
    }

    //添加赠送单
    public function addSend($sendAll,$uid,$storeId){
        if(empty($sendAll['product'])){
            return [];
        }
        $ids=[];
        foreach ($sendAll['product'] as $nk=>$nv){
            $productId = $this->resolveSendProductId((int)($nv['id'] ?? 0), (int)$storeId);
            $setCard=[
                'cart_type'=>0,
                'productId'=>$productId,
                'cartNum'=>$nv['num'],
                'uniqueId'=>'',//attr
                'price'=>0,
                'force_zero_price'=>true,
                'staff_id'=>'',
                'new'=>0,
                'tourist_uid'=>'',
                'secKillId'=>0,
                'reservation_time'=>'',
                'reservation_time_id'=>0,
                'real_name'=>'',
                'phone'=>'',
                'service_staff_id'=>0,
            ];
            $cartId=$this->addCart($uid,$storeId,$setCard);
            $ids[]=$cartId;
        }
        return $ids;
    }
    public function addCart($uid,$storeId,$where){
        $services=app()->make(StoreCartServices::class);
        $new = !!$where['new'];
        if (!$where['cart_type'] && !$where['productId']) {
            return false;
        }
        //真实用户存在，虚拟用户uid为空
        if ($uid) {
            $where['tourist_uid'] = '';
            /** @var \app\services\user\UserServices $userservice */
            $userServices = app()->make(\app\services\user\UserServices::class);
            $userInfo = $userServices->getUserInfo($uid);
            if (!$userInfo) {
                return false;
            }
        }
        if (!$uid && !$where['tourist_uid']) {
            return false;
        }
        $services->setItem('store_id', $storeId)->setItem('tourist_uid', $where['tourist_uid'])->setItem('staff_id', $where['staff_id']);
        //预约相关参数
        $services->setItem('reservation_type', 2)
            ->setItem('reservation_time', $where['reservation_time'] ?? '')->setItem('reservation_time_id', $where['reservation_time_id'] ?? 0)
            ->setItem('real_name', $where['real_name'] ?? '')
            ->setItem('phone', $where['phone'] ?? '')
            ->setItem('service_staff_id', $where['service_staff_id'] ?? 0)
            ->setItem('is_check_reservation_time', 0);
        //无码商品（sendAll 赠送：force_zero_price + allow_explicit_zero_price，避免 0 被当成未传价）
        if (!empty($where['force_zero_price'])) {
            $services->setItem('allow_explicit_zero_price', true);
            $services->setItem('is_send_gift', true);
        }
        $services->setItem('cart_type', $where['cart_type'])->setItem('price', $where['price'] ?? 0);
        $activityId = $type = 0;
        if ($where['secKillId']) {
            $type = 1;
            $activityId = $where['secKillId'];
        }
        [$cartId, $cartNum] = $services->setCart($uid, (int)$where['productId'], (int)$where['cartNum'], $where['uniqueId'], $type, $new, (int)$activityId);
        $services->reset();
        return $cartId;
    }

    /**
     * 生成订单
     * @param int $uid
     * @param array $userInfo
     * @param array $computeData
     * @param int $storeId
     * @param int $staffId
     * @param array $cartIds
     * @param string $payType
     * @param bool $integral
     * @param bool $coupon
     * @param string $remarks
     * @param string $changePrice
     * @param array $changeCartInfo
     * @param bool $isPrice
     * @param int $coupon_id
     * @param int $seckillId
     * @param $collate_code_id
     * @param int $addressId
     * @param array $addressInfo
     * @param $shippingType
     * @param $clerk_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function createOrder(int $uid, array $userInfo, array $computeData, int $storeId, int $staffId, array $cartIds, string $payType, bool $integral = false, bool $coupon = false, string $remarks = '', string $changePrice = '0', array $changeCartInfo = [], bool $isPrice = false, int $coupon_id = 0, int $seckillId = 0, $collate_code_id = 0, int $addressId = 0, array $addressInfo = [], $shippingType = 4, $clerk_id = 0, $estimate_time = '',$is_store_delivery_type = 0,array $selectedProduct=[],$cashChoose=0,$remarkInfo=[],$combinationInfo=[],$source=0,$setYejiAll=[],$serviceYejiAll=[],$is_budan=0,$budan_time='',$giveIds=[],$sendAll=[],$is_gendan=0,$gendan_staff_id=0)
    {
        /** @var SystemStoreStaffServices $staffService */
        $staffService = app()->make(SystemStoreStaffServices::class);
        $staffInfo = [];
        if ($storeId && $staffId && !$staffInfo = $staffService->getOne(['store_id' => $storeId, 'id' => $staffId, 'is_del' => 0, 'status' => 1])) {
            throw new ValidateException('您选择的店员不存在');
        }
        if ($staffInfo) {
            $clerk_id = $staffInfo['uid'];
        }
        if (!$storeId && $staffId) {
            $clerk_id = $staffId;
            $staffId = 0;
        }
        //兼容门店虚拟用户下单
        $field = ['real_name', 'phone', 'province', 'city', 'district', 'street', 'detail'];
        if ($uid && !$addressId && !$addressInfo) {
            /** @var UserAddressServices $addreService */
            $addreService = app()->make(UserAddressServices::class);
            $addressInfo = $addreService->getUserDefaultAddress($uid, implode(',', $field));
            if ($addressInfo) {
                $addressInfo = $addressInfo->toArray();
            }
        }
        if (!$addressInfo) {
            foreach ($field as $key) {
                $addressInfo[$key] = '';
            }
        }
        $cartGroup = $computeData['cartGroup'] ?? [];
        $cartInfo = $computeData['cartInfo'];
        $totalPrice = $computeData['totalPrice'];
        $couponId = $coupon_id;
        $couponPrice = $computeData['couponPrice'] ?? '0.00';
        $useIntegral = $computeData['usedIntegral'];
        $deduction = $computeData['deduction'];
        $other = $cartGroup['other'];
        $reservationInfo = $other['reservationInfo'] ?? [];
        $gainIntegral = $totalNum = $reservationNum = 0;

        $priceData = [
            'coupon_id' => $couponId,
            'coupon_price' => $couponPrice,
            'usedIntegral' => $useIntegral,
            'deduction_price' => $computeData['deductionPrice'] ?? '0.00',
            'promotions_price' => $computeData['promotionsPrice'],
            'pay_postage' => 0,
            'pay_price' => $computeData['payPrice']
        ];

        $promotions_give = [
            'give_integral' => $other['give_integral'] ?? 0,
            'give_coupon' => $other['give_coupon'] ?? [],
            'give_product' => $other['give_product'] ?? [],
            'promotions' => $other['promotions'] ?? []
        ];

        $type = (int)$deduction['type'] ?? 0;
        $seckillId = $seckillId ?: ($type == 1 ? ($deduction['activity_id'] ?? 0) : 0);

        // 余额支付明细映射（按购物车ID）
        $yuePayMap = [];
        if ($changeCartInfo) {
            foreach ($changeCartInfo as $row) {
                if (!isset($row['id'])) continue;
                $yuePayMap[(int)$row['id']] = isset($row['yue_pay_amount']) ? (float)$row['yue_pay_amount'] : 0.00;
            }
        }

        // 卡升级抵扣明细映射（按购物车ID）
        $cardUpgradeMap = [];
        if ($changeCartInfo) {
            foreach ($changeCartInfo as $row) {
                if (!isset($row['id'])) continue;
                $cardUpgradeMap[(int)$row['id']] = isset($row['card_upgrade_amount']) ? (float)$row['card_upgrade_amount'] : 0.00;
            }
        }

        // 欠款明细映射（按购物车ID）
        $debtPayMap = [];
        if ($changeCartInfo) {
            foreach ($changeCartInfo as $row) {
                if (!isset($row['id'])) continue;
                $debtPayMap[(int)$row['id']] = isset($row['debt_pay_amount']) ? (float)$row['debt_pay_amount'] : 0.00;
            }
        }

        // 项目（product_type=6）服务对象：本人/朋友，写入订单明细 cart_info 供支付后自动核销落库
        $serviceObjectMap = [];
        if ($changeCartInfo) {
            foreach ($changeCartInfo as $row) {
                if (!isset($row['id'])) {
                    continue;
                }
                if (!isset($row['service_object']) || $row['service_object'] === '') {
                    continue;
                }
                $so = trim((string)$row['service_object']);
                $serviceObjectMap[(int)$row['id']] = $so === '朋友' ? '朋友' : '本人';
            }
        }
        if ($serviceObjectMap) {
            foreach ($cartInfo as &$cart) {
                $cid = (int)($cart['id'] ?? 0);
                if ($cid && isset($serviceObjectMap[$cid])) {
                    $pt = (int)($cart['productInfo']['product_type'] ?? $cart['product_type'] ?? 0);
                    if ($pt === 6) {
                        $cart['service_object'] = $serviceObjectMap[$cid];
                    }
                }
            }
            unset($cart);
        }

        // 订单级服务对象：拆单后子单 cart_info、卡项子明细可能不含此项，自动核销/核销子单回退用
        $orderLevelServiceObject = '本人';
        foreach ($cartInfo as $c) {
            if (isset($c['cart_type']) && (int)$c['cart_type'] > 0) {
                continue;
            }
            $pt = (int)($c['productInfo']['product_type'] ?? $c['product_type'] ?? 0);
            if ($pt === 6 && trim((string)($c['service_object'] ?? '')) === '朋友') {
                $orderLevelServiceObject = '朋友';
                break;
            }
        }

        if ($isPrice) {//有改价
            [$payPrice, $cartInfo, $changePrice] = $this->changeOrderPrice($cartInfo, $changeCartInfo, (float)$changePrice, (float)$computeData['payPrice']);
            // 改价会覆盖商品行 pay_price，需重新分摊优惠券
            $couponPriceVal = (float)($computeData['couponPrice'] ?? 0);
            $cartCouponsFromCompute = [];
            foreach (($computeData['cartInfo'] ?? []) as $c) {
                if (!empty($c['coupon_id'])) {
                    $cartCouponsFromCompute[] = [
                        'cart_id' => (int)($c['id'] ?? 0),
                        'coupon_id' => (int)$c['coupon_id'],
                    ];
                }
            }
            if ($cartCouponsFromCompute) {
                [$cartInfo, $couponPriceVal] = $this->applyCartItemCoupons(
                    $cartInfo,
                    (int)($userInfo['uid'] ?? 0),
                    $cartCouponsFromCompute,
                    $cartGroup['promotions'] ?? [],
                    $storeId
                );
                /** @var StoreOrderComputedServices $computedServices */
                $computedServices = app()->make(StoreOrderComputedServices::class);
                $payPrice = (float)$computedServices->getOrderSumPrice($cartInfo, 'pay_price', false);
            } elseif ($couponId && $couponPriceVal > 0) {
                /** @var StoreOrderCreateServices $orderCreateServices */
                $orderCreateServices = app()->make(StoreOrderCreateServices::class);
                $cartInfo = $orderCreateServices->computeOrderProductCoupon(
                    $cartInfo,
                    ['coupon_id' => $couponId, 'coupon_price' => $couponPriceVal],
                    $cartGroup['promotions'] ?? [],
                    $storeId
                );
                /** @var StoreOrderComputedServices $computedServices */
                $computedServices = app()->make(StoreOrderComputedServices::class);
                $payPrice = (float)$computedServices->getOrderSumPrice($cartInfo, 'pay_price', false);
            }
        } else {
            $payPrice = $computeData['payPrice'] ?? 0.00;
        }
        // 定制卡：壳行不计价，以内部各品项 pay_price 之和为订单应付
        [$cartInfo, $payPrice] = $this->resolveCustomCardOrderPayPrice($cartInfo, (float)$payPrice);
        // 把每个明细的余额支付金额挂到cartInfo里，后续写入订单商品表
        if ($yuePayMap) {
            foreach ($cartInfo as &$cart) {
                $cid = (int)($cart['id'] ?? 0);
                $cart['yue_pay_amount'] = $yuePayMap[$cid] ?? 0.00;
            }
            unset($cart);
        }

        // 把每个明细的卡升级抵扣金额挂到cartInfo里，后续写入订单商品表
        if ($cardUpgradeMap) {
            foreach ($cartInfo as &$cart) {
                $cid = (int)($cart['id'] ?? 0);
                $cart['card_upgrade_amount'] = $cardUpgradeMap[$cid] ?? 0.00;
            }
            unset($cart);
        }
        // 把每个明细的欠款金额挂到cartInfo里
        if ($debtPayMap) {
            foreach ($cartInfo as &$cart) {
                $cid = (int)($cart['id'] ?? 0);
                $cart['debt_pay_amount'] = $debtPayMap[$cid] ?? 0.00;
            }
            unset($cart);
        }
        // 单品现金付款金额 = 行实付 - 余额 - 卡升级 - 欠款
        foreach ($cartInfo as &$cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            $pp = (string)($cart['pay_price'] ?? 0);
            if ($this->isCustomCardShellCart($cart)) {
                $pp = (string)$payPrice;
            }
            $yue = (string)($cart['yue_pay_amount'] ?? 0);
            if (bccomp($yue, $pp, 2) > 0) {
                $yue = $pp;
                $cart['yue_pay_amount'] = (float)$pp;
            }
            $cu = (string)($cart['card_upgrade_amount'] ?? 0);
            if (bccomp($cu, $pp, 2) > 0) {
                $cu = $pp;
                $cart['card_upgrade_amount'] = (float)$pp;
            }
            $debt = (string)($cart['debt_pay_amount'] ?? 0);
            $remain = bcsub(bcsub($pp, $yue, 2), $cu, 2);
            if (bccomp($debt, $remain, 2) > 0) {
                $debt = $remain;
                $cart['debt_pay_amount'] = (float)$remain;
            }
            $cash = bcsub($remain, $debt, 2);
            if (bccomp($cash, '0', 2) < 0) {
                $cash = '0.00';
            }
            $cart['cash_pay_amount'] = (float)$cash;
        }
        unset($cart);
        $totalDebtAmount = '0.00';
        foreach ($cartInfo as $cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            $totalDebtAmount = bcadd($totalDebtAmount, (string)($cart['debt_pay_amount'] ?? 0), 2);
        }
        if (bccomp($totalDebtAmount, '0', 2) > 0 && !(int)sys_config('cashier_debt_pay_switch', 0)) {
            throw new ValidateException('当前未开启欠款功能');
        }
        foreach ($cartInfo as $cart) {
            $totalNum += $cart['cart_num'];
            $cartInfoGainIntegral = isset($cart['productInfo']['give_integral']) ? bcmul((string)$cart['cart_num'], (string)$cart['productInfo']['give_integral'], 0) : 0;
            $gainIntegral = bcadd((string)$gainIntegral, (string)$cartInfoGainIntegral, 0);

            if ($seckillId) {
                if (!isset($cart['product_attr_unique']) || !$cart['product_attr_unique']) continue;
                $type = $cart['type'];
                if (in_array($type, [1, 2, 3]) &&
                    (
                        !CacheService::checkStock($cart['product_attr_unique'], (int)$cart['cart_num'], $type) ||
                        !CacheService::popStock($cart['product_attr_unique'], (int)$cart['cart_num'], $type)
                    )
                ) {
                    throw new ValidateException('您购买的商品库存已不足' . $cart['cart_num'] . $cart['productInfo']['unit_name']);
                }
            }
			if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {//赠品跳过
				continue;
			}
			if (isset($cart['productInfo']['product_type']) && $cart['productInfo']['product_type'] == 6) {//预约商品
				$reservationNum += $cart['cart_num'];
			}
        }

        if ($collate_code_id) {
            $collateCodeId = (int)$deduction['collate_code_id'] ?? 0;
            if ($collateCodeId && $type == 10) {
                if ($collateCodeId != $collate_code_id) {
                    foreach ($cartIds as $key) {
                        CacheService::redisHandler()->delete($key);
                    }
                    throw new ValidateException('拼单/桌码ID有误,请刷新页面!');
                }
                $seckillId = $collate_code_id;
            }
        }
        /** @var StoreOrderCreateServices $orderServices */
        $orderServices = app()->make(StoreOrderCreateServices::class);
        $key = md5(json_encode($cartIds) . uniqid() . time());
        $product_type = (int)$deduction['product_type'] ?? 0;
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        $hasGiftProjectShell = false;
        foreach ($cartInfo as $cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            $pid = (int)($cart['productInfo']['id'] ?? $cart['product_id'] ?? 0);
            if ($storeCartServices->isGiftProjectShellByProductId($pid)) {
                $hasGiftProjectShell = true;
                break;
            }
        }
        $giftSelectedIds = [];
        if (is_array($selectedProduct) && $selectedProduct) {
            $giftSelectedIds = array_values(array_filter(array_map('intval', $selectedProduct)));
        }
        if (!$giftSelectedIds && is_array($sendAll) && !empty($sendAll['product'])) {
            foreach ($sendAll['product'] as $row) {
                $pid = (int)($row['id'] ?? 0);
                if ($pid > 0) {
                    $giftSelectedIds[] = $pid;
                }
            }
            $giftSelectedIds = array_values(array_unique($giftSelectedIds));
        }
        if ($hasGiftProjectShell) {
            $type = 11;
            $product_type = 5;
            $shippingType = 2;
        }
        $userLocation = trim(($addressInfo['longitude'] ?? '') . ' ' . ($addressInfo['latitude'] ?? ''));
        if(empty($selectedProduct)){
            $selectedProduct='';
        }else{
            $selectedProduct=implode(",",$selectedProduct);
        }
        // 组合支付：订单级 cash_choose 取明细中记账收款(activePay=2)的类型，避免残留旧卡录入(9)
        if ($payType == PayServices::COMBINATION_PAY && !empty($combinationInfo)) {
            $cashChoose = 0;
            foreach ($combinationInfo as $combRow) {
                if ((int)($combRow['activePay'] ?? 0) !== 2) {
                    continue;
                }
                if ((int)($combRow['type'] ?? 0) === CashType::OLD_CARD_ENTRY) {
                    continue;
                }
                $cashChoose = (int)($combRow['type'] ?? 0);
                break;
            }
        }
        $orderInfo = [
            'uid' => $uid,
            'selected_product' =>$selectedProduct,
            'type' => $type,
            'yeji' => json_encode($setYejiAll),
            'send_all' => json_encode($sendAll),
            'service_yeji' => json_encode($serviceYejiAll),
            'cash_choose' => $cashChoose,
            'source' => $source,
            'gendan_staff_id' => ($gendanStaffId = (int)$gendan_staff_id) > 0 ? $gendanStaffId : 0,
            'is_gendan' => $gendanStaffId > 0 ? 1 : ((int)$is_gendan ? 1 : 0),
            'remark_info' => json_encode($remarkInfo),
            'order_id' => $this->getUniqueId(),
            'real_name' => ($reservationInfo['real_name'] ?? '') ? $reservationInfo['real_name'] : ($addressInfo['real_name'] ? $addressInfo['real_name'] : $userInfo['nickname'] ?? ''),
            'user_phone' => ($reservationInfo['user_phone'] ?? '') ? $reservationInfo['user_phone'] : ($addressInfo['phone'] ? $addressInfo['phone'] : $userInfo['phone'] ?? ''),
            'user_address' => isset($addressInfo['addressInfo']) && $addressInfo['addressInfo'] ? $addressInfo['addressInfo'] : $addressInfo['province'] . ' ' . $addressInfo['city'] . ' ' . $addressInfo['district'] . ' ' . $addressInfo['street'] . ' ' . $addressInfo['detail'],
            'user_location' => $userLocation,
            'cart_id' => $cartIds,
            'clerk_id' => $clerk_id,
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'service_object' => $orderLevelServiceObject,
            'total_num' => $totalNum,
            'total_price' => $computeData['sumPrice'] ?? $totalPrice,
			'settle_price' => $computeData['settlePrice'] ?? 0,
            'total_postage' => $computeData['pay_postage'],
            'coupon_id' => $couponId,
            'coupon_price' => $couponPrice,
            'promotions_price' => $priceData['promotions_price'],
            'pay_price' => $payPrice,
            // 现金/余额支付拆分：除余额支付及组合支付中的余额部分外，其它计入现金支付
            'cash_pay_price' => 0.00,
            'yue_pay_price' => 0.00,
            'pay_postage' => $computeData['pay_postage'],
            'deduction_price' => $computeData['deductionPrice'],
            'change_price' => $changePrice,
            'paid' => 0,
            'pay_type' => $payType === PayServices::YUE_PAY ? 'yue' : '',
            'use_integral' => $useIntegral,
            'gain_integral' => $gainIntegral,
            'mark' => htmlspecialchars($remarks),
            'product_type' => $product_type,
            'activity_id' => $seckillId,
            'pink_id' => 0,
            'cost' => $computeData['costPrice'],
            'is_channel' => 5,
			'channel_type' => $this->getItem('channel_type', '') ?: 'cashier',
            'add_time' => time(),
            'unique' => $key,
            'shipping_type' => $shippingType,
            'province' => '',
            'spread_uid' => 0,
            'spread_two_uid' => 0,
            'custom_form' => json_encode([[]]),
            'promotions_give' => json_encode($promotions_give),
            'give_integral' => $promotions_give['give_integral'] ?? 0,
            'give_coupon' => implode(',', $promotions_give['give_coupon'] ?? []),
            'reservation_type' => $reservationInfo['reservation_type'] ?? 2,
            'reservation_time' => ($reservationInfo['reservation_time'] ?? '') ? strtotime($reservationInfo['reservation_time']) : 0,
            'reservation_time_id' => $reservationInfo['reservation_time_id'] ?? 0,
            'reservation_show_time' => $reservationInfo['reservation_show_time'] ?? 0,
            'service_staff_id' => $reservationInfo['service_staff_id'] ?? 0,
            'estimate_time' => $estimate_time,
            'store_delivery_type' => $is_store_delivery_type,
        ];
        // 计算“余额支付金额”与“欠款金额”（卡升级不计入 yue_pay_price）
        $yuePayPrice = 0.00;
        $debtPayPrice = 0.00;
        if ($payType === PayServices::YUE_PAY) {
            $yuePayPrice = (float)$payPrice;
        } elseif (!empty($combinationInfo) && $payType == PayServices::COMBINATION_PAY) {
            foreach ($combinationInfo as $v) {
                if (($v['activePay'] ?? 0) == 3) {
                    $subType = $v['pay_sub_type'] ?? 'balance';
                    if ($subType === 'debt') {
                        $debtPayPrice = (float)bcadd((string)$debtPayPrice, (string)($v['price'] ?? 0), 2);
                    } elseif ($subType === 'balance') {
                        $yuePayPrice = (float)bcadd((string)$yuePayPrice, (string)($v['price'] ?? 0), 2);
                    }
                }
            }
        }
        $cashPayPrice = ValidCashOrderServices::calcOrderCashPayPrice(
            $payPrice,
            $yuePayPrice,
            (int)$cashChoose,
            is_array($combinationInfo) ? $combinationInfo : [],
            (string)$payType,
            $debtPayPrice
        );
        $orderInfo['yue_pay_price'] = $yuePayPrice;
        $orderInfo['cash_pay_price'] = $cashPayPrice;
        $orderDebtAmount = (float)$totalDebtAmount;
        if ($debtPayPrice > $orderDebtAmount) {
            $orderDebtAmount = $debtPayPrice;
        }
        $orderInfo['debt_amount'] = $orderDebtAmount;
        $orderInfo['repaid_debt_amount'] = 0.00;
        ValidCashOrderServices::scaleCartCashPayAmounts($cartInfo, $cashPayPrice);
        if(!empty($budan_time) && $is_budan == 1){
            $orderInfo['add_time']=strtotime($budan_time);
            $orderInfo['is_budan']=1;
        }
        if (in_array($product_type, [4, 5, 6])) {//次卡、预约商品收银台购买
            $orderInfo['verify_code'] = $orderServices->getStoreCode();
//            $orderInfo['shipping_type'] = 2;//修改门店核销
        }
        if ($shippingType == 2) {
            $orderInfo['verify_code'] = $orderServices->getStoreCode();
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $orderInfo['store_id'] = $storeServices->getStoreDisposeCache($storeId, 'id');
            if (!$orderInfo['store_id']) {
                throw new ValidateException('暂无门店无法选择门店核销');
            }
        }
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        //判断是否跨店消费
        if($payType == 'yue' && $orderInfo['store_id']  > 0){
            $orderInfo['kua_store']=$orderServices->isKuadian($orderInfo['uid'],$orderInfo['store_id']);
        }
        $order = $orderServices->save($orderInfo);
        if (!$order) {
            throw new ValidateException('订单生成失败');
        }
        //使用优惠券（支持多明细各自用券）
        $couponIdsUsed = [];
        foreach ($cartInfo as $c) {
            if (!empty($c['coupon_id'])) {
                $couponIdsUsed[(int)$c['coupon_id']] = true;
            }
        }
        if ($couponIdsUsed) {
            /** @var StoreCouponUserServices $couponServices */
            $couponServices = app()->make(StoreCouponUserServices::class);
            foreach (array_keys($couponIdsUsed) as $usedCouponId) {
                $res1 = $couponServices->useCoupon($usedCouponId, (int)($userInfo['uid'] ?? 0), $cartInfo, [], $storeId);
                if (!$res1) {
                    throw new ValidateException('使用优惠劵失败!');
                }
            }
        } elseif ($couponId) {
            /** @var StoreCouponUserServices $couponServices */
            $couponServices = app()->make(StoreCouponUserServices::class);
            $res1 = $couponServices->useCoupon($couponId, (int)($userInfo['uid'] ?? 0), $cartInfo, [], $storeId);
            if (!$res1) {
                throw new ValidateException('使用优惠劵失败!');
            }
        }
        //积分抵扣
        $orderServices->deductIntegral($userInfo, $useIntegral, [
            'SurplusIntegral' => $computeData['surplusIntegral'],
            'usedIntegral' => $computeData['usedIntegral'],
            'deduction_price' => $computeData['deductionPrice'],
        ], (int)($userInfo['uid'] ?? 0), $key);
        //修改门店库存（收银台：次卡/卡项/项目/赠送行不扣库存）
        $this->decCashierGoodsStock($cartInfo, $storeId, $giveIds);
        // 定制卡：壳不计价；各行 sum_true_price 与 pay_price 对齐，供拆单分摊
        $cartInfo = $this->normalizeCustomCardBundleLinePayPrices($cartInfo);
        $cartInfo = $this->normalizeCustomCardShellPayPrice($cartInfo);
        foreach ($cartInfo as &$cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            $pp = (string)($cart['pay_price'] ?? 0);
            $cart['sum_true_price'] = $pp;
            $num = max((int)($cart['cart_num'] ?? 1), 1);
            $cart['truePrice'] = bccomp($pp, '0', 2) > 0 ? bcdiv($pp, (string)$num, 2) : '0.00';
        }
        unset($cart);
        //保存购物车商品信息
        $cartServices->setCartInfo($order['id'], $cartInfo, $userInfo['uid'] ?? 0, $promotions_give['promotions'] ?? [],$giveIds);
        if ($hasGiftProjectShell) {
            $cartServices->expandGiftProjectShellOrder((int)$order['id'], (int)($userInfo['uid'] ?? 0), $giftSelectedIds);
        }
        $cartServices->syncCustomCardOrderPayPrice((int)$order['id']);
        $order = $order->toArray();

        //保存组合支付信息
        if(!empty($combinationInfo) && $payType == PayServices::COMBINATION_PAY){
            CashType::validateCombinationInfo($combinationInfo);
            ValidCashOrderServices::validateCombinationTotal($combinationInfo, $payPrice);
            foreach ($combinationInfo as $k=>$v){
                $lineCashChoose = (int)($v['type'] ?? 0);
                if (($v['pay_sub_type'] ?? '') === 'debt') {
                    $lineCashChoose = CashType::DEBT_ENTRY;
                }
                $combinationOrder=[
                    'active_pay'=>$v['activePay'],
                    'name'=>$v['name'],
                    'price'=>$v['price'],
                    'remarkInfo'=>json_encode($v['remarkInfo']),
                    'cash_choose'=>$lineCashChoose,
                    // 余额支付/卡升级等子类型（active_pay=3共用）
                    'pay_sub_type'=> $v['pay_sub_type'] ?? 'balance',
                    // 卡升级关联旧卡（可选）
                    'upgrade_old_oid'=> $v['upgrade_old_oid'] ?? 0,
                    'upgrade_old_cart_info_id'=> $v['upgrade_old_cart_info_id'] ?? 0,
                    'order_id'=>$order['id'],
                    'type'=>1,
                    'add_time'=>$orderInfo['add_time']
                 ];
               Db::name("combination_order")->insert($combinationOrder);
            }
        }
        if (in_array($type, [9, 10]) && $collate_code_id > 0 && $order) {
            //关联订单和拼单、桌码
            /** @var UserCollageCodeServices $collageServices */
            $collageServices = app()->make(UserCollageCodeServices::class);
            $collageServices->update($collate_code_id, ['oid' => $order['id'], 'status' => 2]);
            //清除未结算商品
            /** @var UserCollagePartakeServices $partakeService */
            $partakeService = app()->make(UserCollagePartakeServices::class);
            $partakeService->update(['collate_code_id' => $collate_code_id, 'is_settle' => 0], ['status' => 0]);
        }
        //保存预约单
        if ($order['product_type'] == 6 && $order['reservation_time_id']) {
            /** @var StoreReservationOrderServices $reservationOrderService */
            $reservationOrderService = app()->make(StoreReservationOrderServices::class);
            $reservationOrderService->createReservationOrder((int)$order['uid'], $order['id'], ['cart_num' => $reservationNum, 'reservation_time' => $orderInfo['reservation_time'] ? date('Y-m-d', $orderInfo['reservation_time']) : '', 'reservation_time_id' => $orderInfo['reservation_time_id'], 'custom_form' => [], 'service_staff_id' => $orderInfo['service_staff_id'] ?? 0], $orderInfo, false, false);
        }
        $news = false;
        if($type == 10) $news = true;
        $addressId = $type = $activity_id = 0;
        $delCart = $this->getItem('delCart', NULL);
        //订单创建事件
        if (is_null($delCart) && in_array($payType, [PayServices::ALIAPY_PAY, PayServices::WEIXIN_PAY, PayServices::YUE_PAY])) {
            $delCart = false;
        }

        //扣除优惠活动赠品限量
        StorePromotionsJob::dispatchDo('changeGiveLimit', [$promotions_give]);
        $group = compact('cartInfo', 'priceData', 'addressId', 'cartIds', 'news', 'delCart', 'changePrice');
        $orderServices->delCart($group);
        event('order.create', [$order, $group, compact('type', 'activity_id'), 0]);
        return $order;
    }
    /**
     * 组合支付中的余额支付
     */
    public function combinationPay(string $orderId,$combinationInfo){
        CashType::validateCombinationInfo($combinationInfo);
        $orderService = app()->make(StoreOrderSuccessServices::class);
        $orderInfo = $orderService->get(['order_id' => $orderId]);
        if (!$orderInfo) {
            throw new ValidateException('没有查询到订单信息');
        }
        if ($orderInfo->paid) {
            throw new ValidateException('订单已支付');
        }
        if ($orderInfo->is_del) {
            throw new ValidateException('订单已取消');
        }
        $type = 'pay_product';
        if (isset($orderInfo['member_type'])) {
            $type = 'pay_member';
        }
          $uid = (int)$orderInfo['uid'];
          $yuePay=0;
          $cardUpgradePay = 0;
          $upgradeOldOids = [];
          foreach ($combinationInfo as $k=>$v){
                if(($v['activePay'] ?? 0) == 3){
                    $subType = $v['pay_sub_type'] ?? 'balance';
                    if ($subType === 'card_upgrade') {
                        $cardUpgradePay = bcadd((string)$cardUpgradePay, (string)($v['price'] ?? 0), 2);
                        $oldOid = (int)($v['upgrade_old_oid'] ?? 0);
                        if ($oldOid) $upgradeOldOids[$oldOid] = $oldOid;
                    } elseif ($subType === 'debt') {
                        // 欠款不扣余额
                    } else {
                        $yuePay=bcadd((string)$yuePay,(string)($v['price'] ?? 0),2);
                    }
                }
          }
          // 卡升级：不扣余额，仅标记旧卡订单已被用于升级（不修改旧卡退款状态，避免影响历史业绩）
          if ($cardUpgradePay > 0 && $upgradeOldOids) {
              /** @var StoreOrderServices $orderSerives */
              $orderSerives = app()->make(StoreOrderServices::class);
              // 按旧卡订单汇总本次卡升级抵扣金额（组合支付明细）
              $oldCardUpgradeAmount = [];
              foreach ($combinationInfo as $v) {
                  if ((int)($v['activePay'] ?? 0) !== 3) {
                      continue;
                  }
                  if (($v['pay_sub_type'] ?? '') !== 'card_upgrade') {
                      continue;
                  }
                  $oldOid = (int)($v['upgrade_old_oid'] ?? 0);
                  if ($oldOid <= 0) {
                      continue;
                  }
                  $linePrice = (string)($v['price'] ?? 0);
                  $oldCardUpgradeAmount[$oldOid] = bcadd($oldCardUpgradeAmount[$oldOid] ?? '0', $linePrice, 2);
              }
              $newOrderNo = (string)($orderInfo['order_id'] ?? '');
              $req = app()->request;
              $cashierId = ($req->hasMacro('cashierId') && $req->cashierId()) ? (int) $req->cashierId() : 0;
              $mgrType = $cashierId > 0 ? 'store' : 'system';
              $mgrId = $cashierId > 0 ? $cashierId : 0;
              /** @var StoreOrderStatusServices $statusServices */
              $statusServices = app()->make(StoreOrderStatusServices::class);
              foreach ($upgradeOldOids as $oldOid) {
                  // 旧卡若已被用于升级，则不可重复使用
                  $oldOrder = $orderSerives->get((int)$oldOid);
                  if (!$oldOrder) continue;
                  if ((int)($oldOrder['card_upgrade_use_oid'] ?? 0) > 0) {
                      throw new ValidateException('旧卡已用于升级，无法重复使用');
                  }
                  $orderSerives->update((int)$oldOid, [
                      'card_upgrade_use_oid' => (int)($orderInfo['id'] ?? 0),
                  ]);
                  $amt = $oldCardUpgradeAmount[(int) $oldOid] ?? '0';
                  if (bccomp($amt, '0', 2) <= 0 && count($upgradeOldOids) === 1) {
                      $amt = (string) $cardUpgradePay;
                  }
                  if (bccomp($amt, '0', 2) <= 0) {
                      $amt = '0.00';
                  }
                  $msg = sprintf('卡升级：抵扣%s元，用于新订单编号：%s', $amt, $newOrderNo);
                  $statusServices->saveStatus((int) $oldOid, 'card_upgrade_use', ['change_message' => $msg], $mgrId, $mgrType);
              }
          }

          if($yuePay > 0){
              $services = app()->make(UserServices::class);
              $userInfo = $services->getUserInfo($uid);
              if ($userInfo['now_money'] < $yuePay) {
                  throw new ValidateException('余额不足' . floatval($yuePay));
              }
              $this->transaction(function () use ($services, $orderInfo, $userInfo, $type,$yuePay) {
                  $res = false !== $services->bcDec($userInfo['uid'], 'now_money', $yuePay, 'uid');
                  switch ($type) {
                      case 'pay_product'://商品余额
                          $id = $orderInfo['id'] ?? 0;
                          /** @var StoreOrderServices $orderSerives */
                          $orderSerives = app()->make(StoreOrderServices::class);
                          $orderInfo = $orderSerives->get($id);
                          if (!$orderInfo) {
                              throw new ValidateException('订单不存在');
                          }
                          $orderInfo = $orderInfo->toArray();
                          //写入余额记录
                          $now_money = bcsub((string)$userInfo['now_money'], (string)$yuePay, 2);
                          $number = $yuePay;
                          /** @var UserMoneyServices $userMoneyServices */
                          $userMoneyServices = app()->make(UserMoneyServices::class);
                          $res = $res && $userMoneyServices->income('pay_combination', $userInfo['uid'], $number, $now_money, $orderInfo['id']);
                          break;
                  }
                  if (!$res) {
                      throw new ValidateException('余额支付失败!');
                  }
              });
          }
        return ['status' => true];
    }
	/**
	 * 收银台支付
	 * @param string $orderId
	 * @param string $payType
	 * @param string $userCode
	 * @param string $authCode
	 * @param bool $isNewOrderId
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function paySuccess(string $orderId, string $payType, string $userCode, string $authCode = '', bool $isNewOrderId = false)
    {
        /** @var StoreOrderSuccessServices $orderService */
        $orderService = app()->make(StoreOrderSuccessServices::class);
        $orderInfo = $orderService->get(['order_id' => $orderId]);
        if (!$orderInfo) {
            throw new ValidateException('没有查询到订单信息');
        }
        if ($orderInfo->paid) {
            throw new ValidateException('订单已支付');
        }
        if ($orderInfo->is_del) {
            throw new ValidateException('订单已取消');
        }
		$orderInfo = $orderInfo->toArray();
		/** @var \app\services\product\inventory\ProductInventoryChangeServices $inventoryChange */
		$inventoryChange = app()->make(\app\services\product\inventory\ProductInventoryChangeServices::class);
		$inventoryChange->assertOrderCanPay($orderInfo);
		if ($isNewOrderId) {
			$updateData = ['pay_type' => $payType];
			//只要重新支付就更新订单号
			if (in_array($payType, [PayServices::ALIAPY_PAY, PayServices::WEIXIN_PAY])) {
				$newOrderId = $orderService->getUniqueId();
				$orderInfo['order_id'] = $newOrderId;
				$updateData['order_id'] = $newOrderId;
			}
			$orderService->update($orderInfo['id'], $updateData, 'id');
		}

        $uid = (int)$orderInfo['uid'];
        switch ($payType) {
            case PayServices::YUE_PAY://余额支付
                if (!$uid) {
                    throw new ValidateException('余额支付用户信息不存在无法支付');
                }
				/** @var UserServices $userService */
				$userService = app()->make(UserServices::class);
				$userInfo = $userService->getUserInfo($uid, ['uid', 'bar_code']);
				if (!$userInfo) {
					throw new ValidateException('余额支付用户不存在');
				}
				// 收银台余额支付是否需要验证【是/否】
				if ((int)sys_config('is_cashier_yue_pay_verify')) {
					if (!$userCode) {
						throw new ValidateException('缺少扫码支付参数');
					}
					if ($userInfo['bar_code'] != $userCode) {
						throw new ValidateException('身份不一致，请重新支付');
					}
				}
                /** @var YuePayServices $payService */
                $payService = app()->make(YuePayServices::class);
                $pay = $payService->yueOrderPay($orderInfo, $uid);
                if ($pay['status'] === true) {
                    $bar_code = $userService->getBarCode();
                    $userService->update($uid, ['bar_code' => $bar_code], 'uid');
                    $cartServices = app()->make(StoreCartServices::class);
                    $cartServices->deleteCartStatus($orderInfo['cart_id'] ?? []);
                    return ['status' => 'SUCCESS', 'message' => '支付成功', 'order_id' => $orderInfo['order_id']];
                } else if ($pay['status'] === 'pay_deficiency') {
                    throw new ValidateException('余额不足，请重新支付');
                } else {
                    return ['status' => 'ERROR', 'message' => is_array($pay) ? $pay['msg'] ?? '余额支付失败，请重新支付' : $pay, 'order_id' => $orderInfo['order_id']];
                }
            case PayServices::WEIXIN_PAY://微信支付
            case PayServices::ALIAPY_PAY://支付宝支付
                if (!$authCode) {
                    throw new ValidateException('缺少支付付款二维码CODE');
                }

                $pay = new PayServices();
                $site_name = sys_config('site_name');
                /** @var StoreOrderCartInfoServices $orderInfoServices */
                $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
                $body = $orderInfoServices->getCarIdByProductTitle((int)$orderInfo['id']);
                $body = substrUTf8($site_name . '--' . $body, 30);
                try {
                    //扫码支付
                    $response = $pay->setAuthCode($authCode)->pay($payType, '', $orderInfo['order_id'], $orderInfo['pay_price'], 'product', $body);
                } catch (\Throwable $e) {
                    \think\facade\Log::error('收银端' . $payType . '扫码支付失败，原因：' . $e->getMessage());
                    return ['status' => 'ERROR', 'message' => '支付失败，原因：' . $e->getMessage(), 'order_id' => $orderInfo['order_id']];
                }
                //支付成功paid返回1
                if ($response['paid']) {
                    if (!$orderService->paySuccess($orderInfo, $payType, ['trade_no' => $response['payInfo']['transaction_id'] ?? ''])) {
                        return ['status' => 'ERROR', 'message' => '支付失败', 'order_id' => $orderInfo['order_id']];
                    }
					//记录支付原始返回数据
					$orderService->update($orderInfo['id'], ['notify_data' => json_encode($response)]);
                    //支付成功刪除購物車
                    /** @var StoreCartServices $cartServices */
                    $cartServices = app()->make(StoreCartServices::class);
                    $cartServices->deleteCartStatus($orderInfo['cart_id'] ?? []);
                    return ['status' => 'SUCCESS', 'message' => '支付成功', 'order_id' => $orderInfo['order_id']];
                } else {
                    if ($payType === PayServices::WEIXIN_PAY) {
                        if (isset($response['payInfo']['err_code']) && in_array($response['payInfo']['err_code'], ['AUTH_CODE_INVALID', 'NOTENOUGH'])) {
                            return ['status' => 'ERROR', 'message' => '支付失败', 'order_id' => $orderInfo['order_id']];
                        }
                        //微信付款码支付需要同步更改状态
                        $secs = 5;
                        if (isset($order_info['payInfo']['err_code']) && $order_info['payInfo']['err_code'] === 'USERPAYING') {
                            $secs = 10;
                        }
                        //放入队列执行
                        MicroPayOrderJob::dispatchSece($secs, [$orderInfo['order_id'], 0]);
                    }
                    return ['status' => 'PAY_ING', 'message' => $response['message'], 'order_id' => $orderInfo['order_id']];
                }
                break;
            case PayServices::CASH_PAY://收银台现金支付
                if (!$orderService->paySuccess($orderInfo, $payType)) {
                    return ['status' => 'ERROR', 'message' => '支付失败', 'order_id' => $orderInfo['order_id']];
                } else {
                    return ['status' => 'SUCCESS', 'message' => '支付成功', 'order_id' => $orderInfo['order_id']];
                }
                break;
            case PayServices::COMBINATION_PAY://收银台现金支付
                if (!$orderService->paySuccess($orderInfo, $payType)) {
                    return ['status' => 'ERROR', 'message' => '支付失败', 'order_id' => $orderInfo['order_id']];
                } else {
                    return ['status' => 'SUCCESS', 'message' => '支付成功', 'order_id' => $orderInfo['order_id']];
                }
                break;
            default:
                throw new ValidateException('暂无支付方式，无法支付');
        }
    }


}

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

use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\BaseServices;
use app\dao\order\StoreOrderDao;
use app\services\other\CityAreaServices;
use app\services\pay\PayServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\store\SystemStoreServices;
use app\services\user\member\MemberCardServices;
use app\services\user\UserBillServices;
use app\services\user\UserServices;
use mohe\services\DeliverySevices;
use think\exception\ValidateException;
use app\services\user\UserAddressServices;
use app\services\product\shipping\ShippingTemplatesFreeServices;
use app\services\product\shipping\ShippingTemplatesRegionServices;
use app\services\product\shipping\ShippingTemplatesServices;
use function Swoole\Coroutine\batch;

/**
 * 订单计算金额
 * Class StoreOrderComputedServices
 * @package app\services\order
 * @mixin StoreOrderDao
 */
class StoreOrderComputedServices extends BaseServices
{
    /**
     * 支付类型
     * @var string[]
     */
    public $payType = ['weixin' => '微信支付', 'yue' => '余额支付', 'offline' => '线下支付', 'pc' => 'pc'];

    /**
     * 额外参数
     * @var array
     */
    protected $paramData = [];

    /**
     * StoreOrderComputedServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 设置额外参数
     * @param array $paramData
     * @return $this
     */
    public function setParamData(array $paramData)
    {
        $this->paramData = $paramData;
        return $this;
    }

    /**
     * 计算订单金额
     * @param int $uid
     * @param array $userInfo
     * @param array $cartGroup
     * @param int $addressId
     * @param string $payType
     * @param bool $useIntegral
     * @param int $couponId
     * @param int $shippingType
     * @return array
     */
    public function computedOrder(int $uid, array $userInfo, array $cartGroup, int $addressId, string $payType, bool $useIntegral = false, int $couponId = 0, int $shippingType = 1, int $store_id = 0, int $is_store_delivery_type = 0)
    {
        $offlinePayStatus = (int)sys_config('offline_pay_status') ?? 2;
        $systemPayType = PayServices::PAY_TYPE;
        if ($offlinePayStatus == 2) unset($systemPayType['offline']);
        if ($payType && !array_key_exists($payType, $systemPayType)) {
            throw new ValidateException('选择支付方式有误');
        }
        if (!$userInfo) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userInfo = $userServices->getUserCacheInfo($uid);
            if (!$userInfo) {
                throw new ValidateException('用户不存在!');
            }
        }
        $cartInfo = $cartGroup['cartInfo'];
        $priceGroup = $cartGroup['priceGroup'];
        $deduction = $cartGroup['deduction'];
        $other = $cartGroup['other'];
        $promotions = $other['promotions'] ?? [];
        $payPrice = (float)$priceGroup['totalPrice'];
        $payIntegral = (int)$priceGroup['totalIntegral'] ?? 0;
        $couponPrice = (float)$priceGroup['couponPrice'];
        $firstOrderPrice = (float)$priceGroup['firstOrderPrice'];
        $changePrice = (float)$priceGroup['changePrice'] ?? 0.00;
        $servicePrice = (float)$priceGroup['servicePrice'] ?? 0.00;
        $addr = $cartGroup['addr'] ?? [];
        $postage = $priceGroup;
        if (!$addr || $addr['id'] != $addressId) {
            /** @var UserAddressServices $addressServices */
            $addressServices = app()->make(UserAddressServices::class);
            $addr = $addressServices->getAdderssCache($addressId);
            //改变地址重新计算邮费
            $postage = [];
        }
        $combinationId = $this->paramData['combinationId'] ?? 0;
        $seckillId = $this->paramData['seckill_id'] ?? 0;
        $bargainId = $this->paramData['bargainId'] ?? 0;
        $newcomerId = $this->paramData['newcomerId'] ?? 0;
        $isActivity = $combinationId || $seckillId || $bargainId || $newcomerId;
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
            'postage' => function () use ($uid, $shippingType, $payType, $cartInfo, $addr, $payPrice, $postage, $other, $type, $store_id, $is_store_delivery_type) {
                if ($type == 8 || $type == 10) $shippingType = 2;
                return $this->computedPayPostage($uid, $shippingType, $payType, $cartInfo, $addr, $payPrice, $postage, $other, $store_id, $is_store_delivery_type);
            }
        ]);
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

        // [$p, $couponPrice] = $results['coupon'];
        [$p, $payPostage, $storePostageDiscount, $storeFreePostage, $isStoreFreePostage, $poorFreeShipping, $poorDeliveryPrice, $sameCityStoreFreePostage] = $results['postage'];
        if ($type == 8) {
            $firstOrderPrice = 0;
            $payPrice = 0;
        }
        if ($firstOrderPrice < $payPrice) {//首单优惠金额
            $payPrice = bcsub((string)$payPrice, (string)$firstOrderPrice, 2);
        } else {
            $payPrice = 0;
        }
        $deductionPrice=0;
        if (sys_config('integral_ratio_status') && !$isActivity) {
            //使用积分
            [$payPrice, $deductionPrice, $usedIntegral, $SurplusIntegral] = $this->useIntegral($useIntegral, $userInfo, $payPrice, $other);
        }
        //邮费
        $payPrice = (float)bcadd((string)$payPrice, (string)$payPostage, 2);

        //服务费
        $payPrice = (float)bcadd((string)$payPrice, (string)$servicePrice, 2);
        $payPrice = max($payPrice, 0);


        $validProductIds = [];
        $gainIntegral = 0;
        foreach ($cartInfo as &$item) {
            $item['invalid'] = false;
            if ($shippingType === 2 && isset($item['productInfo']['delivery_type']) && in_array(2, $item['productInfo']['delivery_type'])) {
                $item['invalid'] = true;
            }
            if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {//赠品 卡项关联跳过
                continue;
            }
            //订单商品营销设置：赠送积分
            $cartInfoGainIntegral = isset($item['productInfo']['give_integral']) ? bcmul((string)$item['cart_num'], (string)$item['productInfo']['give_integral'], 0) : 0;
            $gainIntegral = bcadd((string)$gainIntegral, (string)$cartInfoGainIntegral, 0);
            $validProductIds[] = $item['productInfo']['id'] ?? 0;
        }

        //赠送积分：优惠活动赠送+订单商品营销设置赠送+下单赠送
        $order_integral = $this->getGiveIntegral($uid, (string)$payPrice);
        $give_integral = (int)$other['give_integral'] + (int)$gainIntegral + (int)$order_integral;
        //赠送优惠券：优惠活动赠送+订单商品营销设置赠送
        $give_coupon = [];
        $giveCouponIds = $other['give_coupon'] ?? [];
        /** @var StoreProductCouponServices $storeProductCouponServices */
        $storeProductCouponServices = app()->make(StoreProductCouponServices::class);
        $giveCouponIds = array_unique(array_merge($giveCouponIds, $storeProductCouponServices->getCouponIdsByProduct($validProductIds)));
        if ($giveCouponIds) {
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            $give_coupon = $couponIssueService->getColumn([['id', 'IN', $giveCouponIds]], 'id,coupon_title');
        }

        $result = [
            'total_price' => (float)$priceGroup['totalPrice'],
            'pay_price' => (float)$payPrice,
            'pay_integral' => max($payIntegral, 0),
            'total_postage' => (float)bcadd((string)$payPostage, (string)($storePostageDiscount ?? 0), 2),
            'pay_postage' => (float)$payPostage,
            'first_order_price' => $firstOrderPrice ?? 0,
            'coupon_price' => $couponPrice ?? 0,
            'promotions_price' => $results['promotions'] ?? 0,
            'promotions_detail' => $promotionsDetail,
            'deduction_price' => (float)$deductionPrice ?? 0,
            'usedIntegral' => $usedIntegral ?? 0,
            'SurplusIntegral' => $SurplusIntegral ?? 0,
            'storePostageDiscount' => (float)$storePostageDiscount ?? 0,
            'isStoreFreePostage' => $isStoreFreePostage ?? false,
            'storeFreePostage' => (float)$storeFreePostage ?? 0,
            'sameCityStoreFreePostage' => (float)$sameCityStoreFreePostage ?? 0,
            'poorFreeShipping' => (float)$poorFreeShipping ?? 0,//差多少包邮
            'poorDeliveryPrice' => (float)(in_array($type, [1, 2, 3, 4]) ? 0 : ($poorDeliveryPrice ?? 0)),//差多少起送
            'change_price' => $changePrice,
            'service_price' => $servicePrice,
            'cartInfo' => $cartInfo,
            'give_integral' => $give_integral,
            'give_coupon' => $give_coupon
        ];
        $this->paramData = [];
        return $result;
    }

    /**
     * 获取下单支付金额赠送积分数量
     * @param int $uid
     * @param string $payPrice
     * @return int
     * @throws \throwable
     */
    public function getGiveIntegral(int $uid = 0, string $payPrice = '0.00')
    {
        $order_integral = 0;
        $order_give_integral = sys_config('order_give_integral');
        //下单支付赠送积分
        if ((float)$payPrice && (float)$order_give_integral) {
            //会员消费返积分翻倍
            if ($uid) {
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $user = $userServices->getUserCacheInfo($uid);
                if ($user && $user['is_money_level'] > 0) {
                    //看是否开启消费返积分翻倍奖励
                    /** @var MemberCardServices $memberCardService */
                    $memberCardService = app()->make(MemberCardServices::class);
                    $integral_rule_number = $memberCardService->isOpenMemberCardCache('integral');
                    if ($integral_rule_number) {
                        $order_integral = bcmul((string)$payPrice, (string)$integral_rule_number, 2);
                    }
                }
            }
            $order_integral = bcmul((string)$order_give_integral, (string)($order_integral ?: $payPrice), 0);
        }
        return (int)$order_integral;
    }


    /**
     * 使用积分
     * @param $useIntegral
     * @param $userInfo
     * @param $payPrice
     * @param $other
     * @return array
     */
    public function useIntegral(bool $useIntegral, $userInfo, string $payPrice, array $other)
    {
        /** @var UserBillServices $userBillServices */
        $userBillServices = app()->make(UserBillServices::class);
        // 可用积分
        $user_integral = $userBillServices->countIntegralBalance((int)$userInfo['integral'], (int)$userInfo['uid']);
        $usable = (int)max($user_integral, 0);

        $deductionPrice = 0;
        $usedIntegral = 0;
        if ($userInfo && $useIntegral && $usable > 0 && $payPrice) {
            $integralMaxType = sys_config('integral_max_type', 1);//积分抵用上限类型1：积分、2：订单金额比例
            if ($integralMaxType == 1) {//最多抵用积分
                $integralMaxNum = sys_config('integral_max_num', 200);
                if ($integralMaxNum > 0 && $usable > $integralMaxNum) {
                    $integral = $integralMaxNum;
                } else {
                    $integral = $usable;
                }
                $deductionPrice = (float)bcmul((string)$integral, (string)$other['integralRatio'], 2);
                if ($deductionPrice < $payPrice) {
                    $payPrice = bcsub((string)$payPrice, (string)$deductionPrice, 2);
                    $usedIntegral = $integral;
                } else {
                    if ($other['integralRatio']) {
                        $deductionPrice = $payPrice;
                        $usedIntegral = (int)ceil(bcdiv((string)$payPrice, (string)$other['integralRatio'], 2));
                    }
                    $payPrice = 0;
                }
            } else {//最高抵用比率
                $integralMaxRate = sys_config('integral_max_rate', 0);
                $deductionPrice = (float)bcmul((string)$usable, (string)$other['integralRatio'], 2);
                if ($integralMaxRate > 0 && $integralMaxRate <= 100) {
                    $integralMaxPrice = (float)bcmul((string)$payPrice, (string)bcdiv((string)$integralMaxRate, '100', 2), 2);
                } else {
                    $integralMaxPrice = $payPrice;
                }
                $deductionPrice = min($deductionPrice, $integralMaxPrice);
                $payPrice = bcsub((string)$payPrice, (string)$deductionPrice, 2);
                if ((float)$other['integralRatio']) {
                    $usedIntegral = ceil(bcdiv((string)$deductionPrice, (string)$other['integralRatio'], 2));
                }
            }
            if ($payPrice <= 0) $payPrice = 0;
        }
        $SurplusIntegral = (int)bcsub((string)$usable, $usedIntegral, 0);
        return [$payPrice, $deductionPrice, $usedIntegral, $SurplusIntegral];
    }

    /**
     * 计算邮费
     * @param int $uid
     * @param int $shipping_type
     * @param string $payType
     * @param array $cartInfo
     * @param array $addr
     * @param string $payPrice
     * @param array $postage
     * @param array $other
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function computedPayPostage(int $uid, int $shipping_type, string $payType, array $cartInfo, array $addr, string $payPrice, array $postage = [], array $other = [], int $store_id = 0, int $is_store_delivery_type = 0)
    {
        $storePostageDiscount = 0;
        $poorDeliveryPrice = 0;
        $poorFreeShipping = 0; // 差多少包邮
        $storeFreePostage = $postage['storeFreePostage'] ?? 0;
        $sameCityStoreFreePostage = $postage['sameCityStoreFreePostage'] ?? 0;
        $isStoreFreePostage = false;
        if (!$storeFreePostage) {
            $storeFreePostage = floatval(sys_config('store_free_postage')) ?: 0;//满额包邮金额
        }
        if (!$sameCityStoreFreePostage) {
            /** @var DeliveryConfigServices $deliveryConfigService */
            $deliveryConfigService = app()->make(DeliveryConfigServices::class);
            $deliveryConfig = $deliveryConfigService->get(['type' => 1, 'relation_id' => $store_id], ['free_shipping_amount']);
            if ($deliveryConfig) {
                $sameCityStoreFreePostage = $deliveryConfig['free_shipping_amount'];//同城配送包邮规则
            }
        }

        if (!$addr || !$cartInfo) {
            $payPostage = 0;
        } else {
            //$shipping_type = 1 快递发货 $shipping_type = 2 门店核销 $shipping_type = 3 门店配送
            if ($shipping_type == 2) {
                if (!sys_config('store_func_status', 1) || !sys_config('store_self_mention', 1)) $shipping_type = 1;
            }
            //$is_store_delivery_type 门店配送方式:1、快递发货，2、同城配送
            if ($shipping_type == 3 && $is_store_delivery_type == 2 && $store_id) { // 门店配送计算运费
                $postage = $this->getOrderPriceShippingCost($uid, $store_id, $cartInfo, $addr);
                $payPostage = $postage['storePostage'];
                $storePostageDiscount = $postage['storePostageDiscount'];
                $isStoreFreePostage = $postage['isStoreFreePostage'] ?? false;
                $poorDeliveryPrice = $postage['poorDeliveryPrice'];
                $sameCityStoreFreePostage = $postage['sameCityStoreFreePostage'];
                if (!$isStoreFreePostage) {
                    $poorFreeShipping = bcsub($sameCityStoreFreePostage, $payPrice, 2);
                }
            } else {
                //门店核销 || （线下支付 && 线下支付包邮） 没有邮费支付
                if ($shipping_type === 2 || ($payType == 'offline' && ((isset($other['offlinePostage']) && $other['offlinePostage']) || sys_config('offline_postage')) == 1)) {
                    $payPostage = 0;
                } else {
                    if (!$postage || !isset($postage['storePostage']) || !isset($postage['storePostageDiscount'])) {
                        $postage = $this->getOrderPriceGroup($uid, $cartInfo, $addr, $storeFreePostage);
                    }
                    $payPostage = $postage['storePostage'];
                    $storePostageDiscount = $postage['storePostageDiscount'];
                    $isStoreFreePostage = $postage['isStoreFreePostage'] ?? false;
                    if (!$isStoreFreePostage) {
                        $poorFreeShipping = bcsub($storeFreePostage, $payPrice, 2);
                    }
                }
            }
            if ($payPostage && $storePostageDiscount) {
                /** @var UserServices $userService */
                $userService = app()->make(UserServices::class);
                //享受svip 运费折扣
                if ($userService->checkUserIsSvip($uid)) {
                    $payPostage = bcsub((string)$payPostage, (string)$storePostageDiscount, 2);
                } else {
                    $storePostageDiscount = 0;
                }
            }

            $payPrice = (float)bcadd((string)$payPrice, (string)$payPostage, 2);
        }
        return [$payPrice, $payPostage, $storePostageDiscount, $storeFreePostage, $isStoreFreePostage, $poorFreeShipping, $poorDeliveryPrice, $sameCityStoreFreePostage];
    }


    /**
     * 运费计算,总金额计算
     * @param int $uid
     * @param $cartInfo
     * @param $addr
     * @param $storeFreePostage
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function getOrderPriceGroup(int $uid, $cartInfo, $addr, $storeFreePostage = null)
    {
        $storePostage = 0;
        $storePostageDiscount = 0;
        $isStoreFreePostage = false;//是否满额包邮
        if (is_null($storeFreePostage)) {
            $storeFreePostage = floatval(sys_config('store_free_postage')) ?: 0;//满额包邮金额
        }
        $sumPrice = $this->getOrderSumPrice($cartInfo, 'sum_price');//获取订单原总金额
        $totalPrice = $this->getOrderSumPrice($cartInfo, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额
        $settlePrice = (float)$this->getOrderSumPrice($cartInfo, 'settle_price', false);//结算总价
        $costPrice = $this->getOrderSumPrice($cartInfo, 'costPrice');//获取订单成本价
        $vipPrice = $this->getOrderSumPrice($cartInfo, 'vip_truePrice');//获取订单会员优惠金额
        $totalIntegral = (int)$this->getOrderSumPrice($cartInfo, 'integral');//获取订单总积分
        $changePrice = (float)$this->getOrderSumPrice($cartInfo, 'change_price', false);//获取改价优惠金额
        $servicePrice = (float)$this->getOrderSumPrice($cartInfo, 'service_price');//获取商品服务费
        $levelPrice = $this->getOrderSumPrice($cartInfo, 'level');//获取会员等级优惠
        $memberPrice = $this->getOrderSumPrice($cartInfo, 'member');//获取付费会员优惠

        //如果满额包邮等于0
        $free_shipping = 0;
        $postageArr = [];
        if (isset($cartInfo[0]['productInfo']['product_type']) && in_array($cartInfo[0]['productInfo']['product_type'], [1, 2])) {
            $storePostage = 0;
        } elseif ($cartInfo && $addr) {
            //优惠套餐包邮判断
            if (isset($cartInfo[0]['type']) && $cartInfo[0]['type'] == 5 && isset($cartInfo[0]['activity_id']) && $cartInfo[0]['activity_id']) {
                /** @var StoreDiscountsServices $discountService */
                $discountService = app()->make(StoreDiscountsServices::class);
                $free_shipping = $discountService->value(['id' => $cartInfo[0]['activity_id']], 'free_shipping');
            }
            if ($free_shipping) {
                $storePostage = 0;
            } else if (sys_config('whole_free_shipping') == 1 && $totalPrice >= $storeFreePostage) {//如果商品实付金额大于等于满额包邮 邮费等于0
                $isStoreFreePostage = true;
                $storePostage = 0;
            } else {

                // 判断商品包邮和固定运费
                foreach ($cartInfo as &$item) {
                    if (!isset($item['productInfo']['freight'])) continue;
                    if ($item['productInfo']['freight'] == 1) {
                        $item['postage_price'] = 0;
                    } elseif ($item['productInfo']['freight'] == 2) {
                        $item['postage_price'] = bcmul((string)$item['productInfo']['postage'], (string)$item['cart_num'], 2);
                        $storePostage = bcadd((string)$storePostage, (string)$item['postage_price'], 2);
                    }
                }

                //按照运费模板计算每个运费模板下商品的件数/重量/体积以及总金额 按照首重倒序排列
                $cityId = (int)($addr['city_id'] ?? 0);
                $ids = [];
                if ($cityId) {
                    /** @var CityAreaServices $cityAreaServices */
                    $cityAreaServices = app()->make(CityAreaServices::class);
                    $ids = $cityAreaServices->getRelationCityIds($cityId);
                }
                $cityIds = array_merge([0], $ids);

                $tempIds[] = 1;
                foreach ($cartInfo as $key_c => $item_c) {
                    if (isset($item_c['productInfo']['freight']) && $item_c['productInfo']['freight'] == 3) {
                        $tempIds[] = $item_c['productInfo']['temp_id'];
                    }
                }
                $tempIds = array_unique($tempIds);
                /** @var ShippingTemplatesServices $shippServices */
                $shippServices = app()->make(ShippingTemplatesServices::class);
                $temp = $shippServices->getShippingColumnCache(['id' => $tempIds], 'appoint,group', 'id');
                /** @var ShippingTemplatesRegionServices $regionServices */
                $regionServices = app()->make(ShippingTemplatesRegionServices::class);
                $regions = $regionServices->getTempRegionListCache($tempIds, $cityIds);
                $temp_num = [];
                foreach ($cartInfo as $cart) {
                    if (isset($cart['productInfo']['freight']) && in_array($cart['productInfo']['freight'], [1, 2])) {
                        continue;
                    }
                    $tempId = $cart['productInfo']['temp_id'] ?? 1;
                    $group = isset($temp[$tempId]['group']) ? $temp[$tempId]['group'] : $temp[1]['group'];
                    if ($group == 1) {
                        $num = $cart['cart_num'];
                    } elseif ($group == 2) {
                        $num = $cart['cart_num'] * $cart['productInfo']['attrInfo']['weight'];
                    } else {
                        $num = $cart['cart_num'] * $cart['productInfo']['attrInfo']['volume'];
                    }
                    $region = isset($regions[$tempId]) ? $regions[$tempId] : ($regions[1] ?? []);
                    if (!$region) {
                        continue;
                    }
                    if (!isset($temp_num[$tempId])) {
                        $temp_num[$tempId] = [
                            'number' => $num,
                            'group' => $group,
                            'price' => $cart['pay_price'],
                            'first' => $region['first'],
                            'first_price' => $region['first_price'],
                            'continue' => $region['continue'],
                            'continue_price' => $region['continue_price'],
                            'temp_id' => $tempId
                        ];
                    } else {
                        $temp_num[$tempId]['number'] += $num;
                        $temp_num[$tempId]['price'] += $cart['pay_price'];
                    }
                }
                if ($temp_num) {
                    /** @var ShippingTemplatesFreeServices $freeServices */
                    $freeServices = app()->make(ShippingTemplatesFreeServices::class);
                    $freeList = $freeServices->isFreeListCache($tempIds, $cityIds);
                    if ($freeList) {
                        foreach ($temp_num as $k => $v) {
                            if (isset($temp[$v['temp_id']]['appoint']) && $temp[$v['temp_id']]['appoint'] && isset($freeList[$v['temp_id']])) {
                                $free = $freeList[$v['temp_id']];
                                $condition = $free['number'] <= $v['number'];
                                if ($free['price'] <= $v['price'] && $condition) {
                                    unset($temp_num[$k]);
                                }
                            }
                        }
                    }
                    //首件运费最大值
                    $maxFirstPrice = $temp_num ? max(array_column($temp_num, 'first_price')) : 0;
                    //初始运费为0
                    $storePostage_arr = [];

                    $i = 0;
                    //循环运费数组
                    foreach ($temp_num as $fk => $fv) {
                        //找到首件运费等于最大值
                        if ($fv['first_price'] == $maxFirstPrice) {
                            //每次循环设置初始值
                            $tempArr = $temp_num;
                            $Postage = 0;
                            //计算首件运费
                            if ($fv['number'] <= $fv['first']) {
                                $Postage = bcadd($Postage, $fv['first_price'], 2);
                            } else {
                                if ($fv['continue'] <= 0) {
                                    $Postage = $Postage;
                                } else {
                                    $Postage = bcadd(bcadd($Postage, $fv['first_price'], 2), bcmul(ceil(bcdiv(bcsub($fv['number'], $fv['first'], 2), $fv['continue'] ?? 0, 2)), $fv['continue_price'], 4), 2);
                                }
                            }
                            $postageArr[$i]['data'][$fk] = $Postage;

                            //删除计算过的首件数据
                            unset($tempArr[$fk]);
                            //循环计算剩余运费
                            foreach ($tempArr as $ck => $cv) {
                                if ($cv['continue'] <= 0) {
                                    $Postage = $Postage;
                                } else {
                                    $one_postage = bcmul(ceil(bcdiv($cv['number'], $cv['continue'] ?? 0, 2)), $cv['continue_price'], 2);
                                    $Postage = bcadd($Postage, $one_postage, 2);
                                    $postageArr[$i]['data'][$ck] = $one_postage;
                                }
                            }
                            $postageArr[$i]['sum'] = $Postage;
                            $storePostage_arr[] = $Postage;
                            $i++;
                        }
                    }
                    $maxStorePostage = $storePostage_arr ? max($storePostage_arr) : 0;
//                //获取运费计算中的最大值
                    $storePostage = bcadd((string)$storePostage, (string)$maxStorePostage, 2);
                }
            }
        }

        //会员邮费享受折扣
        if ($storePostage) {
            //看是否开启会员折扣奖励
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $express_rule_number = $memberCardService->isOpenMemberCardCache('express');
            $express_rule_number = $express_rule_number <= 0 ? 0 : $express_rule_number;

            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            $userIsSvip = $userService->checkUserIsSvip($uid);

            $truePostageArr = [];
            foreach ($postageArr as $postageitem) {
                if ($postageitem['sum'] == ($maxStorePostage ?? 0)) {
                    $truePostageArr = $postageitem['data'];
                    break;
                }
            }
            $cartAlready = [];
            foreach ($cartInfo as &$item) {
                if (isset($item['productInfo']['freight']) && in_array($item['productInfo']['freight'], [1, 2])) {
                    if (isset($item['postage_price']) && $item['postage_price'] && $express_rule_number && $express_rule_number < 100 && $userIsSvip) {
                        $item['postage_price'] = bcmul($item['postage_price'], bcdiv($express_rule_number, 100, 4), 2);
                    }
                    continue;
                }
                $tempId = $item['productInfo']['temp_id'] ?? 0;
                $tempPostage = $truePostageArr[$tempId] ?? 0;
                $tempNumber = $temp_num[$tempId]['number'] ?? 0;
                if (!$tempId || !$tempPostage) continue;
                $group = $temp_num[$tempId]['group'];

                if ($group == 1) {
                    $num = $item['cart_num'];
                } elseif ($group == 2) {
                    $num = $item['cart_num'] * $item['productInfo']['attrInfo']['weight'];
                } else {
                    $num = $item['cart_num'] * $item['productInfo']['attrInfo']['volume'];
                }

                if ((($cartAlready[$tempId]['number'] ?? 0) + $num) >= $tempNumber) {
                    $price = isset($cartAlready[$tempId]['price']) ? bcsub((string)$tempPostage, (string)$cartAlready[$tempId]['price'], 2) : $tempPostage;
                } else {
                    $price = bcmul((string)$tempPostage, bcdiv((string)$num, (string)$tempNumber, 4), 2);
                }
                $cartAlready[$tempId]['number'] = bcadd((string)($cartAlready[$tempId]['number'] ?? 0), (string)$num, 2);
                $cartAlready[$tempId]['price'] = bcadd((string)($cartAlready[$tempId]['price'] ?? 0.00), (string)$price, 2);

                if ($express_rule_number && $express_rule_number < 100 && $userIsSvip) {
                    $price = bcmul($price, bcdiv($express_rule_number, 100, 4), 2);
                }
                $price = sprintf("%.2f", $price);
                $item['postage_price'] = $price;
            }
            if ($express_rule_number && $express_rule_number < 100) {
                $payPostage = bcmul($storePostage, bcdiv($express_rule_number, 100, 4), 2);
                $storePostageDiscount = bcsub($storePostage, $payPostage, 2);
            } else {
                $storePostageDiscount = 0;
            }

        }
        return compact('storePostage', 'storeFreePostage', 'isStoreFreePostage', 'sumPrice', 'totalPrice', 'settlePrice', 'totalIntegral', 'costPrice', 'vipPrice', 'levelPrice', 'memberPrice', 'storePostageDiscount', 'changePrice', 'servicePrice', 'cartInfo');
    }

    /**
     * 运费计算
     * @param int $store_id
     * @param $cartInfo
     * @param $addr
     * @return array|int|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderPriceShippingCost(int $uid, int $store_id, $cartInfo, $addr)
    {
        $storePostageDiscount = 0;
        $isStoreFreePostage = false;//是否满额包邮
        $sumPrice = $this->getOrderSumPrice($cartInfo, 'sum_price');//获取订单原总金额
        $totalPrice = $this->getOrderSumPrice($cartInfo, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额
        $settlePrice = (float)$this->getOrderSumPrice($cartInfo, 'settle_price', false);//结算总价
        $costPrice = $this->getOrderSumPrice($cartInfo, 'costPrice');//获取订单成本价
        $vipPrice = $this->getOrderSumPrice($cartInfo, 'vip_truePrice');//获取订单会员优惠金额
        $totalIntegral = (int)$this->getOrderSumPrice($cartInfo, 'integral');//获取订单总积分
        $changePrice = (float)$this->getOrderSumPrice($cartInfo, 'change_price', false);//获取改价优惠金额
        $servicePrice = (float)$this->getOrderSumPrice($cartInfo, 'service_price');//获取商品服务费
        $levelPrice = $this->getOrderSumPrice($cartInfo, 'level');//获取会员等级优惠
        $memberPrice = $this->getOrderSumPrice($cartInfo, 'member');//获取付费会员优惠
        /** @var DeliveryConfigServices $deliveryConfigService */
        $deliveryConfigService = app()->make(DeliveryConfigServices::class);
        /** @var SystemStoreServices $systemStoreServices */
        $systemStoreServices = app()->make(SystemStoreServices::class);
        /** @var StoreDeliveryOrderServices $storeDeliverOrderServices */
        $storeDeliverOrderServices = app()->make(StoreDeliveryOrderServices::class);
        $systemStore = $systemStoreServices->get($store_id, ['id', 'city_delivery_type']);
        $deliveryConfig = $deliveryConfigService->get(['type' => 1, 'relation_id' => $store_id]);
        $min_delivery_amount = $deliveryConfig['min_delivery_amount'] ?? 0;//起送价
        $base_shipping_fee = $deliveryConfig['base_shipping_fee'] ?? 0;//基础运费
        $free_shipping_amount = $deliveryConfig['free_shipping_amount'] ?? 0;//包邮规则
        $is_premium_stack_enabled = $deliveryConfig['is_premium_stack_enabled'] ?? 0;//是否开启溢价叠加(0:关 1:开)
        $distance_premium_config = $deliveryConfig['distance_premium_config'] ?? [];//距离溢价设置
        $weight_premium_config = $deliveryConfig['weight_premium_config'] ?? [];//重量溢价设置
        $sameCityStoreFreePostage = $free_shipping_amount;
        $poorDeliveryPrice = 0;
        if ($totalPrice > $free_shipping_amount) {
            $isStoreFreePostage = true;
        }
        if ($systemStore && $systemStore['city_delivery_type'] > 0) {
            if ($totalPrice >= $free_shipping_amount) {
                $storePostage = 0;
            } else {
                //获取重量
                $totalWeight = 0;
                foreach ($cartInfo as $cart) {
                    $totalWeight += $cart['cart_num'] * $cart['productInfo']['attrInfo']['weight'];
                }
                $storePostage = $storeDeliverOrderServices->getShippingCost($addr, $store_id, (int)$systemStore['city_delivery_type'], (float)$totalWeight, $totalPrice);
            }
            if ($totalPrice < $min_delivery_amount) {
                $poorDeliveryPrice = bcsub($min_delivery_amount, $totalPrice, 2);
            }
        } else {
            $storePostage = $base_shipping_fee;
            if (!$is_premium_stack_enabled) {
                if ($totalPrice < $min_delivery_amount) {
                    $poorDeliveryPrice = bcsub($min_delivery_amount, $totalPrice, 2);
                } elseif ($totalPrice >= $free_shipping_amount) {
                    $storePostage = 0;
                }
            } else {
                //溢价计算
                //距离溢价计算
                $fee = $deliveryConfigService->distanceStackFee($store_id, $distance_premium_config, $addr);
                //重量溢价计算
                $totalWeight = 0;
                foreach ($cartInfo as $cart) {
                    $totalWeight += $cart['cart_num'] * $cart['productInfo']['attrInfo']['weight'];
                }
                $weight_fee = $deliveryConfigService->weightStackFee($totalWeight, $weight_premium_config);
                $storePostage = bcadd($fee, $weight_fee, 2);
                $storePostage = bcadd($storePostage, $base_shipping_fee, 2);
                if ($totalPrice < $min_delivery_amount) {
                    $poorDeliveryPrice = bcsub($min_delivery_amount, $totalPrice, 2);
                } elseif ($totalPrice >= $free_shipping_amount) {
                    $storePostage = 0;
                }
            }
        }
        //会员邮费享受折扣
        if ($storePostage) {
            //看是否开启会员折扣奖励
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $express_rule_number = $memberCardService->isOpenMemberCardCache('express');
            $express_rule_number = $express_rule_number <= 0 ? 0 : $express_rule_number;

            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            $userIsSvip = $userService->checkUserIsSvip($uid);
            $total_price = 0.00;
            $compute_price = 0.00;
            $count = 0;
            foreach ($cartInfo as $carts) {
                $total_price = bcadd((string)$total_price, (string)$carts['pay_price'], 2);
                $count++;
            }

            foreach ($cartInfo as &$item) {
                if ($count > 1) {
                    $price = $total_price ? bcmul((string)bcdiv((string)$item['pay_price'], (string)$total_price, 4), (string)$storePostage, 2) : 0;
                    $compute_price = bcadd($compute_price,$price,2);
                } else {
                    $price = bcsub((string)$storePostage, $compute_price, 2);
                }

                if ($express_rule_number && $express_rule_number < 100 && $userIsSvip) {
                    $price = bcmul($price, bcdiv($express_rule_number, 100, 4), 2);
                }
                $price = sprintf("%.2f", $price);
                $item['postage_price'] = $price;
                $count--;
            }

            if ($express_rule_number && $express_rule_number < 100 && $userIsSvip) {
                $payPostage = bcmul($storePostage, bcdiv($express_rule_number, 100, 4), 2);
                $storePostageDiscount = bcsub($storePostage, $payPostage, 2);
            } else {
                $storePostageDiscount = 0;
            }
        }

        return compact('storePostage', 'poorDeliveryPrice', 'sumPrice', 'totalPrice', 'settlePrice', 'totalIntegral', 'costPrice', 'vipPrice', 'levelPrice', 'memberPrice', 'storePostageDiscount', 'changePrice', 'servicePrice', 'cartInfo', 'isStoreFreePostage', 'sameCityStoreFreePostage');
    }

    /**
     * 获取某个字段总金额
     * @param $cartInfo
     * @param string $key
     * @param bool $is_unit
     * @return int|string
     */
    public function getOrderSumPrice($cartInfo, $key = 'truePrice', $is_unit = true, $cart_type = [])
    {
        $SumPrice = 0;
        foreach ($cartInfo as $cart) {
            if (isset($cart['cart_info'])) $cart = $cart['cart_info'];
            if ($cart_type) {
                if (isset($cart['cart_type']) && !in_array($cart['cart_type'], $cart_type)) {
                    continue;
                }
            } else {
                if (in_array($key, ['pay_price', 'sum_price', 'change_price'])) {//这几个计算的时候不能跳过无码商品
                    if (isset($cart['cart_type']) && !in_array($cart['cart_type'], [0, 3])) {//跳过赠品 卡项关联
                        continue;
                    }
                } else {
                    if (isset($cart['cart_type']) && $cart['cart_type'] > 0) {//跳过赠品 卡项关联 无码商品
                        continue;
                    }
                }

            }
            if ($is_unit) {
                $SumPrice = bcadd($SumPrice, bcmul($cart['cart_num'] ?? 1, $cart[$key] ?? 0, 2), 2);
            } else {
                $SumPrice = bcadd($SumPrice, $cart[$key] ?? 0, 2);
            }
        }
        return $SumPrice;
    }
}

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
declare (strict_types=1);

namespace app\services\order;

use app\dao\order\StoreCartDao;
use app\services\activity\discounts\StoreDiscountsProductsServices;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\integral\StoreIntegralServices;
use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\activity\newcomer\StoreNewcomerServices;
use app\services\activity\promotions\StorePromotionsServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\BaseServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreProductReservationServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\shipping\ShippingTemplatesServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\store\SystemStoreServices;
use app\services\user\level\UserLevelServices;
use app\services\user\member\MemberCardServices;
use app\services\user\UserAddressServices;
use app\services\user\UserServices;
use app\jobs\product\ProductLogJob;
use mohe\services\CacheService;
use mohe\traits\OptionTrait;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;

/**
 *
 * Class StoreCartServices
 * @package app\services\order
 * @mixin StoreCartDao
 */
class StoreCartServices extends BaseServices
{

    use OptionTrait, ServicesTrait;

    //库存字段比对
    const STOCK_FIELD = 'sum_stock';
    //购物车最大数量
    protected $maxCartNum = 100;

    /**
     * StoreCartServices constructor.
     * @param StoreCartDao $dao
     */
    public function __construct(StoreCartDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取某个用户下的购物车数量
     * @param array $unique
     * @param int $productId
     * @param int $uid
     * @param string $userKey
     * @return array
     */
    public function getUserCartNums(array $unique, int $productId, int $uid, string $userKey = 'uid')
    {
        $where['is_pay'] = 0;
        $where['is_del'] = 0;
        $where['is_new'] = 0;
        $where['product_id'] = $productId;
        $where[$userKey] = $uid;
        return $this->dao->getUserCartNums($where, $unique);
    }

    /**
     * 计算首单优惠
     * @param int $uid
     * @param array $cartInfo
     * @param array $newcomerArr
     * @return array
     */
    public function computedFirstDiscount(int $uid, array $cartInfo, array $newcomerArr = [])
    {
        $first_order_price = $first_discount = $first_discount_limit = 0;
        if ($uid && $cartInfo) {
            if (!$newcomerArr) {
                /** @var StoreNewcomerServices $newcomerServices */
                $newcomerServices = app()->make(StoreNewcomerServices::class);
                $newcomerArr = $newcomerServices->checkUserFirstDiscount($uid);
            }
            if ($newcomerArr) {//首单优惠
                [$first_discount, $first_discount_limit] = $newcomerArr;
                /** @var StoreOrderComputedServices $orderServices */
                $orderServices = app()->make(StoreOrderComputedServices::class);
                $totalPrice = $orderServices->getOrderSumPrice($cartInfo, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额
                $first_discount = bcsub('1', (string)bcdiv($first_discount, '100', 2), 2);
                $first_order_price = (float)bcmul((string)$totalPrice, (string)$first_discount, 2);
                $first_order_price = min($first_order_price, $first_discount_limit, $totalPrice);
            }
        }
        return [$cartInfo, $first_order_price, $first_discount, $first_discount_limit];
    }

    /**
     * 计算商品优惠
     * @param int $uid
     * @param array $valid
     * @param int $couponId
     * @param bool $isCart
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function computedProductPromotion(int $uid, array $valid, int $store_id = 0, int $couponId = 0, bool $isCart = false)
    {
        $promotions = $giveCoupon = $giveCartList = $useCoupon = $giveProduct = [];
        $giveIntegral = $couponPrice = $firstOrderPrice = 0;
        /** @var StoreNewcomerServices $newcomerServices */
        $newcomerServices = app()->make(StoreNewcomerServices::class);
        $newcomerArr = $newcomerServices->checkUserFirstDiscount($uid);
        if ($newcomerArr) {//首单优惠
            //计算首单优惠
            [$valid, $firstOrderPrice, $first_discount, $first_discount_limit] = $this->computedFirstDiscount($uid, $valid, $newcomerArr);
        } else {
            /** @var StorePromotionsServices $storePromotionsServices */
            $storePromotionsServices = app()->make(StorePromotionsServices::class);
            //计算相关优惠活动
            $storePromotionsServices->setItem('isVip', $this->getItem('isVip', 0));
            [$valid, $couponPrice, $useCoupon, $promotions, $giveIntegral, $giveCoupon, $giveCartList] = $storePromotionsServices->computedPromotions($uid, $valid, $store_id, $couponId, $isCart);
            $storePromotionsServices->reset();
            if ($giveCartList) {
                foreach ($giveCartList as $key => $give) {
                    $giveProduct[] = [
                        'promotions_id' => $give['promotions_id'][0] ?? 0,
                        'product_id' => $give['product_id'] ?? 0,
                        'unique' => $give['product_attr_unique'] ?? '',
                        'cart_num' => $give['cart_num'] ?? 1,
                    ];
                }
            }
        }
        return compact('valid', 'couponPrice', 'useCoupon', 'promotions', 'giveCartList', 'giveIntegral', 'giveCoupon', 'giveProduct', 'firstOrderPrice');
    }

    /**
     * 获取用户下的购物车列表
     * @param int $uid
     * @param $cartIds
     * @param bool $new
     * @param array $addr
     * @param int $shipping_type
     * @param int $store_id
     * @param int $coupon_id
     * @param bool $isCart
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserProductCartListV1(int $uid, $cartIds, bool $new, array $addr = [], int $shipping_type = 1, int $store_id = 0, int $coupon_id = 0, bool $isCart = false, int $is_store_delivery_type = 0)
    {
        if ($new) {
            $cartIds = $cartIds && is_string($cartIds) ? explode(',', $cartIds) : (is_array($cartIds) ? $cartIds : []);
            $cartInfo = [];
            if ($cartIds) {
                foreach ($cartIds as $key) {
                    $info = CacheService::redisHandler()->get((string)$key);
                    if ($info) {
                        $cartInfo[] = $info;
                    }
                }
            }
        } else {
            $cartInfo = $this->dao->getCartList(['uid' => $uid, 'status' => 1, 'id' => $cartIds], 0, 0, ['productInfo', 'attrInfo']);
        }
        if (!$cartInfo) {
            throw new ValidateException('获取购物车信息失败');
        }
        foreach ($cartInfo as $cart) {
            //检查限购
            if (!($cart['cart_type'] ?? 0) && !isset($cart['type']) && $cart['type'] != 8 && ($cart['product_id'] ?? 0)) {
                $this->checkLimit($uid, $cart['product_id'] ?? 0, $cart['cart_num'] ?? 1, true, $store_id);
            }
        }

        [$cartInfo, $valid, $invalid] = $this->handleCartList($uid, $cartInfo, $addr, $shipping_type, $store_id, $is_store_delivery_type);
        $orderTypes = array_values(array_unique(array_column($cartInfo, 'type')));
        // 兼容多行购物车：不能只用第一行的 type。若赠送/关联行在前且为预售 type=6，会误判整单为 6 从而跳过优惠券（卡项+赠送仍应收券后价）
        $type = $orderTypes[0] ?? 0;
        $isOnlyPresaleType6 = count($orderTypes) === 1 && (int)$orderTypes[0] === 6;
        //productType 0普通商品  5卡项  6项目  如果有卡项或者项目应该固定是卡项或者项目
        $productTypes=array_unique(array_column($cartInfo, 'product_type'));
        $product_type = $productTypes[0] ?? 0;
        if(in_array(6,$productTypes)){
            $product_type=6;
        }
        if(in_array(5,$productTypes)){
            $product_type=5;
        }
        $activity_id = array_unique(array_column($cartInfo, 'activity_id'))[0] ?? 0;
        $collate_code_id = array_unique(array_column($cartInfo, 'collate_code_id'))[0] ?? 0;
        $deduction = ['product_type' => $product_type, 'type' => $type, 'activity_id' => $activity_id, 'collate_code_id' => $collate_code_id];
        $promotions = $giveCoupon = $giveCartList = $useCoupon = $giveProduct = [];
        $giveIntegral = $couponPrice = $firstOrderPrice = 0;
        if (!$deduction['activity_id'] && !$isOnlyPresaleType6) {
            //计算优惠（仅「整单均为预售 type=6」时跳过，与原先 $type != 6 意图一致）
            $data = $this->computedProductPromotion($uid, $valid, $store_id, $coupon_id, $isCart);
            extract($data);
        }
        return compact('cartInfo', 'valid', 'invalid', 'deduction', 'couponPrice', 'useCoupon', 'promotions', 'giveCartList', 'giveIntegral', 'giveCoupon', 'giveProduct', 'firstOrderPrice');
    }

    /**
     * 验证库存
     * @param int $uid
     * @param int $productId
     * @param int $cartNum
     * @param int $store_id
     * @param string $unique
     * @param bool $new
     * @param int $type
     * @param int $activity_id
     * @param int $discount_product_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkProductStock(int $uid, int $productId, int $cartNum = 1, int $store_id = 0, string $unique = '', bool $new = false, int $type = 0, int $activity_id = 0, int $discount_product_id = 0, int $sum_cart_num = 0, bool $is_show = false)
    {

        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        $isSet = $this->getItem('is_set', 0);
        $tourist_uid = $this->getItem('tourist_uid', 0);
        //验证限量
        if($isSet >= 0) $this->checkLimit($uid, $productId, $cartNum, $new, $store_id);
        switch ($type) {
            case 0://普通
                if ($unique == '') {
                    $unique = $attrValueServices->value(['product_id' => $productId, 'type' => 0], 'unique');
                }
                /** @var StoreProductServices $productServices */
                $productServices = app()->make(StoreProductServices::class);
                $isGiftSend = (bool)$this->getItem('is_send_gift', false);
                $productInfo = $productServices->isValidProduct($productId, $is_show || $isGiftSend);
                if (!$productInfo && $isGiftSend) {
                    $productInfo = $productServices->get(['id' => $productId, 'is_del' => 0]);
                }
                if (!$productInfo) {
                    throw new ValidateException('该商品已下架或删除');
                }
                if ($productInfo['is_vip_product']) {
                    /** @var UserServices $userServices */
                    $userServices = app()->make(UserServices::class);
                    if (!$userServices->checkUserIsSvip($uid)) {
                        throw new ValidateException('该商品为付费会员专享商品');
                    }
                }
                //预售商品
                if ($productInfo['is_presale_product']) {
                    if ($productInfo['presale_start_time'] > time()) throw new ValidateException('预售活动未开始');
                    if ($productInfo['presale_end_time'] < time()) throw new ValidateException('预售活动已结束');
                }
                $attrInfo = $attrValueServices->getOne(['unique' => $unique, 'type' => 0]);
                if (!$unique || !$attrInfo || $attrInfo['product_id'] != $productId) {
                    throw new ValidateException('请选择有效的商品属性');
                }
                if ($productInfo['product_type'] == 6) {//预约商品
                    $reservation_time = $this->getItem('reservation_time', '');
                    $reservation_time_id = (int)$this->getItem('reservation_time_id', 0);
                    $reservation_start = trim((string)$this->getItem('reservation_start', ''));
                    $reservation_end = trim((string)$this->getItem('reservation_end', ''));
                    $isCheckTime = !!$this->getItem('is_check_reservation_time', 1);
                    if ($isCheckTime && $reservation_time && ($reservation_time_id || $reservation_start)) {
                        /** @var StoreReservationOrderServices $reservationOrderServices */
                        $reservationOrderServices = app()->make(StoreReservationOrderServices::class);
                        /** @var StoreProductReservationServices $productReservationServices */
                        $productReservationServices = app()->make(StoreProductReservationServices::class);
                        $reservationOrderServices->resolveReservationTimeInfo(
                            $productReservationServices,
                            $productId,
                            $unique,
                            $cartNum,
                            $reservation_time,
                            [
                                'reservation_time_id' => $reservation_time_id,
                                'reservation_start' => $reservation_start,
                                'reservation_end' => $reservation_end,
                            ],
                            $isCheckTime
                        );
                    } elseif ($isCheckTime && $productInfo['reservation_timing_type'] == 2 && $reservation_time) {
                        $productReservationServices = app()->make(StoreProductReservationServices::class);
                        $productReservationServices->checkReservationProductTimeStock(
                            $productId,
                            $unique,
                            $cartNum,
                            $reservation_time,
                            $reservation_time_id,
                            $productInfo->toArray(),
                            true,
                            $reservation_start
                        );
                    }
                } elseif ($productInfo['product_type'] == 5 && $store_id) {
                    /** @var StoreCardRelatedServices $cardRelatedServices */
                    $cardRelatedServices = app()->make(StoreCardRelatedServices::class);
                    $res = $cardRelatedServices->checkThereProductsStore($productId, $store_id);
                    if (!$res) throw new ValidateException('该卡项商品关联的商品门店不存在');
                } elseif ($isGiftSend || in_array((int)$productInfo['product_type'], [4, 5, 6], true)) {
                    // 赠送加购 / 次卡 / 卡项 / 项目：无库存概念，跳过库存校验
                } else {
                    $nowStock = $attrInfo['stock'];//现有平台库存
                    if ($cartNum > $nowStock) {
                        throw new ValidateException('该商品库存不足' . $cartNum);
                    }
                    $stockNum = 0;
                    //直接设置购物车商品数量
                    if($isSet == 0) {
                        $stockNum = $this->dao->value(['product_id' => $productId, 'product_attr_unique' => $unique, 'uid' => $uid, 'status' => 1, 'store_id' => $store_id, 'tourist_uid' => $tourist_uid], 'cart_num') ?: 0;
                    }
                    if ($nowStock < ($cartNum + $stockNum) && $isSet >= 0) {
                        if ($store_id) {
                            throw new ValidateException('该商品库存不足');
                        }
                        $surplusStock = $nowStock - $cartNum;//剩余库存
                        if ($surplusStock < $stockNum) {
                            $this->dao->update(['product_id' => $productId, 'product_attr_unique' => $unique, 'uid' => $uid, 'status' => 1, 'store_id' => $store_id, 'tourist_uid' => $tourist_uid], ['cart_num' => $surplusStock]);
                        }
                    }
                }
                break;
            case 1://秒杀
                /** @var StoreSeckillServices $seckillService */
                $seckillService = app()->make(StoreSeckillServices::class);
                [$attrInfo, $unique, $productInfo] = $seckillService->checkSeckillStock($uid, $activity_id, $cartNum, $store_id, $unique);
                break;
            case 2://砍价
                /** @var StoreBargainServices $bargainService */
                $bargainService = app()->make(StoreBargainServices::class);
                [$attrInfo, $unique, $productInfo, $bargainUserInfo] = $bargainService->checkBargainStock($uid, $activity_id, $cartNum, $unique);
                break;
            case 3://拼团
                /** @var StoreCombinationServices $combinationService */
                $combinationService = app()->make(StoreCombinationServices::class);
                [$attrInfo, $unique, $productInfo] = $combinationService->checkCombinationStock($uid, $activity_id, $cartNum, $unique);
                break;
            case 4://积分
                /** @var StoreIntegralServices $storeIntegralServices */
                $storeIntegralServices = app()->make(StoreIntegralServices::class);
                [$attrInfo, $unique, $productInfo] = $storeIntegralServices->checkoutProductStock($uid, $activity_id, $cartNum, $unique);
                break;
            case 5://套餐
                /** @var StoreDiscountsProductsServices $discountProduct */
                $discountProduct = app()->make(StoreDiscountsProductsServices::class);
                [$attrInfo, $unique, $productInfo] = $discountProduct->checkDiscountsStock($uid, $discount_product_id, $cartNum, $unique);
                break;
            case 7://新人专享
                /** @var StoreNewcomerServices $newcomerServices */
                $newcomerServices = app()->make(StoreNewcomerServices::class);
                [$attrInfo, $unique, $productInfo] = $newcomerServices->checkNewcomerStock($uid, $activity_id, $cartNum, $unique);
                break;
            case 8://抽奖
                /** @var LuckLotteryRecordServices $luckRecordServices */
                $luckRecordServices = app()->make(LuckLotteryRecordServices::class);
                [$attrInfo, $unique, $productInfo] = $luckRecordServices->checkLotteryPrizeStock($productId, $activity_id, $cartNum, $unique);
                break;
            case 9://拼单
            case 10://桌码
                if ($unique == '') {
                    $unique = $attrValueServices->value(['product_id' => $productId, 'type' => 0], 'unique');
                }
                /** @var StoreProductServices $productServices */
                $productServices = app()->make(StoreProductServices::class);
                $productInfo = $productServices->isValidProduct($productId);
                if (!$productInfo) {
                    throw new ValidateException('该商品已下架或删除');
                }
                $attrInfo = $attrValueServices->getOne(['unique' => $unique, 'type' => 0]);
                if (!$unique || !$attrInfo || $attrInfo['product_id'] != $productId) {
                    throw new ValidateException('请选择有效的商品属性');
                }
                $nowStock = $attrInfo['stock'];//现有平台库存
                if (bcadd((string)$cartNum, (string)$sum_cart_num, 0) > $nowStock) {
                    throw new ValidateException('该商品库存不足' . bcadd((string)$cartNum, (string)$sum_cart_num, 0));
                }
                break;
            default:
                throw new ValidateException('请刷新后重试');
                break;
        }
        if (in_array($type, [1, 2, 3, 4])) {
            //根商品规格库存
            $product_stock = $attrValueServices->value(['product_id' => $productInfo['product_id'], 'suk' => $attrInfo['suk'], 'type' => 0], 'stock');
            if ($product_stock < $cartNum) {
                throw new ValidateException('商品库存不足' . $cartNum);
            }
            if (!CacheService::checkStock($unique, (int)$cartNum, $type)) {
                throw new ValidateException('商品库存不足' . $cartNum . ',无法购买请选择其他商品!');
            }
        }
        return [$attrInfo, $unique, $cartNum, $productInfo];
    }

    /**
     * 添加购物车
     * @param int $uid
     * @param int $product_id
     * @param int $cart_num
     * @param string $product_attr_unique
     * @param int $type
     * @param bool $new
     * @param int $activity_id
     * @param int $discount_product_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setCart(int $uid, int $product_id, int $cart_num = 1, string $product_attr_unique = '', int $type = 0, bool $new = true, int $activity_id = 0, int $discount_product_id = 0)
    {
        if ($cart_num < 1) $cart_num = 1;
        //加入购物车类型0普通3无码商品
        $cart_type = $this->getItem('cart_type', 0);
        $price = $this->getItem('price', 0);
        $store_id = $this->getItem('store_id', 0);
        $staff_id = $this->getItem('staff_id', 0);
        $tourist_uid = $this->getItem('tourist_uid', '');
        $key = $this->getItem('key', '');
        $isSet = $this->getItem('is_set', 0);
        $attrInfo = $productInfo = $reservationTimeInfo = [];
        if ($cart_type == 0) {//正常商品加入购物车
            //检测库存限量
            [$attrInfo, $product_attr_unique, $cart_num, $productInfo] = $this->checkProductStock(
                $uid,
                $product_id,
                $cart_num,
                (int)$store_id,
                $product_attr_unique,
                $new,
                $type, $activity_id,
                $discount_product_id
            );
        }
        $product_type = $productInfo['product_type'] ?? 0;
        // 显式 0 元（收银 sendAll 赠送等）：不能用 ?: 否则 0 会被当成假值而改用 SKU 原价，导致赠送行参与优惠券分摊
        if ($this->getItem('allow_explicit_zero_price', false)) {
            $price = (float)$this->getItem('price', 0);
        } else {
            $price = $this->getItem('price', 0);
            $price = $price ?: $attrInfo['price'] ?? 0;
        }
        //门店商品
        if (!$store_id && isset($productInfo['type']) && $productInfo['type'] == 1 && isset($productInfo['relation_id']) && $productInfo['relation_id']) {
            $store_id = $productInfo['relation_id'];
        }
        if ($new) {
            if (!$key) {
                $key = $this->getUniqueId((string)$uid);
            }
            //普通订单
            if ($type == 0) {
                if (isset($productInfo['is_presale_product']) && $productInfo['is_presale_product']) {//商品是预售商品 订单类型改为预售订单
                    $type = 6;
                } else {
                    switch ($product_type) {
                        case 5://卡项商品
                            $type = 11;
                            break;
                        case 6://预约商品
                            $type = 12;
                            $info['reservation_type'] = $this->getItem('reservation_type', 2);
                            $info['reservation_time'] = $this->getItem('reservation_time', '');
                            $info['reservation_time_id'] = (int)$this->getItem('reservation_time_id', 0);
                            $info['reservation_start'] = trim((string)$this->getItem('reservation_start', ''));
                            $info['reservation_end'] = trim((string)$this->getItem('reservation_end', ''));
                            $info['service_duration_minutes'] = (int)$this->getItem('service_duration_minutes', 0);
                            $info['real_name'] = $this->getItem('real_name', '');
                            $info['user_phone'] = $this->getItem('phone', '');
                            $info['service_staff_id'] = (int)$this->getItem('service_staff_id', 0);
                            $info['addon_items'] = $this->getItem('addon_items', []);
                            $info['sync_all'] = $this->getItem('sync_all', []);
                            if (!is_array($info['addon_items'])) {
                                $info['addon_items'] = [];
                            }
                            if (!is_array($info['sync_all'])) {
                                $info['sync_all'] = [];
                            }
                            if ($info['reservation_start']) {
                                $showTime = $info['reservation_start'] . ($info['reservation_end'] ? '-' . $info['reservation_end'] : '');
                                $info['reservation_show_time'] = $showTime;
                                $reservationTimeInfo = [
                                    [
                                        'id' => 0,
                                        'start' => $info['reservation_start'],
                                        'end' => $info['reservation_end'],
                                        'show_time' => $showTime,
                                        'service_price' => 0,
                                    ]
                                ];
                            } elseif ($info['reservation_time_id']) {
                                /** @var StoreProductReservationTimeServices $reservationTimeServices */
                                $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
                                $reservationTimeInfo = $reservationTimeServices->getList([
                                    'product_id' => $product_id,
                                    'sku_unique' => $product_attr_unique,
                                    'id' => $info['reservation_time_id'],
                                ]);
                                if (!$reservationTimeInfo) {
                                    throw new ValidateException('选择的预约时段无效');
                                }
                                $info['reservation_show_time'] = $reservationTimeInfo[0]['show_time'] ?? '';
                            } else {
                                $reservationTimeInfo = [];
                                $info['reservation_show_time'] = '';
                            }
                            break;
                    }
                }
            }
            $info['id'] = $key;
            $info['uid'] = $uid;
            $info['tourist_uid'] = $tourist_uid;
            $info['cart_type'] = $cart_type;
            $info['type'] = $type;
            $info['store_id'] = $store_id;
            $info['product_type'] = $product_type;
            if ($type == 10 || $type == 9) {
                $info['collate_code_id'] = $activity_id;
                $activity_id = 0;
            }
            $info['activity_id'] = $activity_id;
            $info['discount_product_id'] = $discount_product_id;
            $info['product_id'] = $product_id;
            $info['product_attr_unique'] = $product_attr_unique;
            $info['cart_num'] = $cart_num;
            $info['productInfo'] = [];
            $info['attrInfo'] = [];
            if ($productInfo) {
                $info['productInfo'] = is_object($productInfo) ? $productInfo->toArray() : $productInfo;
            }
            if ($attrInfo) {
                $info['attrInfo'] = is_object($attrInfo) ? $attrInfo->toArray() : $attrInfo;
            }
            $info['productInfo']['attrInfo'] = $info['attrInfo'];
            $info['productInfo']['reservationTimeInfo'] = $reservationTimeInfo;
            $info['price'] = $price;
            try {
                CacheService::redisHandler()->set($key, $info, 3600);
            } catch (\Throwable $e) {
                throw new ValidateException($e->getMessage());
            }
            return [$key, $cart_num];
        } else {//加入购物车记录
            ProductLogJob::dispatch(['cart', ['uid' => $uid, 'product_id' => $product_id, 'cart_num' => $cart_num]]);
            $cart = $this->dao->getOne(['type' => $type, 'cart_type' => $cart_type, 'uid' => $uid, 'tourist_uid' => $tourist_uid, 'product_id' => $product_id, 'product_attr_unique' => $product_attr_unique, 'is_del' => 0, 'is_new' => 0, 'is_pay' => 0, 'status' => 1, 'store_id' => $store_id, 'staff_id' => $staff_id]);
            $cart=false;
            if ($cart) {
                if ($isSet == 1) {//直接修改购物车数量
                    $cart->cart_num = $cart_num;
                } else if ($isSet == 0) {
                    $cart->cart_num = $cart->cart_num + $cart_num;
                } else if ($isSet == -1) {
                    $cart->cart_num = $cart->cart_num - $cart_num;
                }
                if ($cart->cart_num == 0) {
                    return $this->dao->delete($cart->id);
                } else {
                    $cart->save();
                    return [$cart->id, $cart->cart_num];
                }

            } else {
                $add_time = time();
                $id = $this->dao->save(compact('uid', 'tourist_uid', 'cart_type', 'type', 'product_type', 'product_id', 'product_attr_unique', 'cart_num', 'price', 'activity_id', 'store_id', 'staff_id', 'add_time'))->id;
                event('cart.add', [$uid, $tourist_uid, $store_id, $staff_id]);
                return [$id, $cart_num];
            }

        }
    }

    /**
     * 移除购物车商品
     * @param int $uid
     * @param array $ids
     * @return StoreCartDao|bool
     */
    public function removeUserCart(int $uid, array $ids)
    {
        return $this->dao->removeUserCart($uid, $ids) !== false;
    }

    /**
     * 移除门店购物车
     * @param array $where
     * @return bool
     */
    public function removeStoreCart(array $where)
    {
        return $this->dao->removeStoreCart($where) !== false;
    }

    /**
     * 购物车 修改商品数量
     * @param int $id
     * @param int $number
     * @param int $uid
     * @return bool|\mohe\basic\BaseModel
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function changeUserCartNum(int $id, int $number, int $uid)
    {
        if (!$id || !$number) return false;
        $where = ['uid' => $uid, 'id' => $id];
        $carInfo = $this->dao->getOne($where, 'cart_type,product_id,type,activity_id,product_attr_unique,cart_num');
        if (!$carInfo) {
            throw new ValidateException('获取购物车数据失败');
        }
        if ($carInfo->cart_num == $number) return true;
        if ($carInfo['cart_type'] == 0 && $carInfo['product_id']) {
            /** @var StoreProductServices $StoreProduct */
            $StoreProduct = app()->make(StoreProductServices::class);
            $stock = $StoreProduct->getProductStock($carInfo->product_id, $carInfo->product_attr_unique);
            if (!$stock) throw new ValidateException('暂无库存');
            if ($stock < $number) throw new ValidateException('库存不足' . $number);
            $this->checkProductStock($uid, (int)$carInfo->product_id, (int)$number, 0, $carInfo->product_attr_unique, true);
        }
        return $this->dao->changeUserCartNum(['uid' => $uid, 'id' => $id], (int)$number);
    }

    /**
     * 获取购物车列表
     * @param int $uid
     * @param int $status
     * @param array $cartIds
     * @param int $storeId
     * @param int $staff_id
     * @param int $shipping_type
     * @param int $touristUid
     * @param int $numType
     * @param bool $new
     * @param bool $isCart
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     *  public function getUserCartList(int $uid, int $status, array $cartIds = [], int $storeId = -1, int $staff_id = -1, int $shipping_type = -1, int $touristUid = 0, int $numType = 0, bool $new = false, bool $isCart = true)
     */
    public function getUserCartList(int $uid, array $where = [], array $cartIds = [], int $shipping_type = -1, int $numType = 0, bool $new = false)
    {
        $storeId = $where['store_id'] ?? $this->getItem('store_id', 0);
        $storeId = (int)(is_array($storeId) ? end($storeId) : $storeId);
        $status = $where['status'] ?? $this->getItem('status', 1);
        if ($new) {
            $cartIds = $cartIds && is_string($cartIds) ? explode(',', $cartIds) : (is_array($cartIds) ? $cartIds : []);
            $list = [];
            if ($cartIds) {
                foreach ($cartIds as $key) {
                    $info = CacheService::redisHandler()->get((string)$key);
                    if ($info) {
                        $list[] = $info;
                    }
                }
            }
        } else {
            $where['uid'] = $uid;
            $where['cart_ids'] = $cartIds;
            $list = $this->dao->getCartList($where, 0, 0, ['productInfo', 'attrInfo']);
        }
        $count = $promotionsPrice = $coupon_price = $firstOrderPrice = 0;
        $cartList = $valid = $promotions = $coupon = $invalid = $type = $activity_id = [];
        if ($list) {
            [$list, $valid, $invalid] = $this->handleCartList($uid, $list, [], $shipping_type, $storeId);
            $activity_id = array_unique(array_column($list, 'activity_id'))[0] ?? 0;
            $type = array_unique(array_column($list, 'type'))[0] ?? 0;
            //不是活动 且不是预售
            if (!$activity_id && $type != 6) {
                $data = $this->computedProductPromotion($uid, $valid, $storeId, 0, true);
                extract($data);
                $cartList = array_merge($valid, $giveCartList);
                foreach ($cartList as $key => $cart) {
                    if (isset($cart['promotions_true_price']) && isset($cart['price_type']) && $cart['price_type'] == 'promotions') {
                        $promotionsPrice = bcadd((string)$promotionsPrice, (string)bcmul((string)$cart['promotions_true_price'], (string)$cart['cart_num'], 2), 2);
                    }
                }
            }
            if ($numType) {
                $count = count($valid);
            } else {
                $count = array_sum(array_column($valid, 'cart_num'));
            }
        }
        $deduction = ['type' => $type, 'activity_id' => $activity_id];
        $deduction['promotions_price'] = $promotionsPrice;
        $deduction['coupon_price'] = $coupon_price;
        $deduction['first_order_price'] = $firstOrderPrice;

        $user_store_id = $this->getItem('store_id', 0);
        $invalid_key = 'invalid_' . $user_store_id . '_' . $uid;
        //写入缓存
        if ($status == 1) {
            CacheService::redisHandler()->delete($invalid_key);
            if ($invalid) CacheService::redisHandler()->set($invalid_key, $invalid, 60);
        }
        //读取缓存
        if ($status == 0) {
            $other_invalid = CacheService::redisHandler()->get($invalid_key);
            if ($other_invalid) $invalid = array_merge($invalid, $other_invalid);
        }

        return ['promotions' => $promotions, 'coupon' => $coupon, 'valid' => $valid, 'invalid' => $invalid, 'deduction' => $deduction, 'count' => $count];
    }

    /**
     * 购物车重选
     * @param int $cart_id
     * @param int $product_id
     * @param string $unique
     */
    public function modifyCart(int $cart_id, int $product_id, string $unique)
    {
        /** @var StoreProductAttrValueServices $attrService */
        $attrService = app()->make(StoreProductAttrValueServices::class);
        $stock = $attrService->value(['product_id' => $product_id, 'unique' => $unique, 'type' => 0], 'stock');
        if ($stock > 0) {
            $this->dao->update($cart_id, ['product_attr_unique' => $unique, 'cart_num' => 1]);
        } else {
            throw new ValidateException('选择的规格库存不足');
        }
    }

    /**
     * 重选购物车
     * @param $id
     * @param $uid
     * @param $productId
     * @param $unique
     * @param $num
     * @param int $store_id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function resetCart($id, $uid, $productId, $unique, $num, int $store_id = 0)
    {
        $res = $this->dao->getOne(['id' => $id, 'uid' => $uid, 'product_id' => $productId, 'product_attr_unique' => $unique, 'store_id' => $store_id]);
        if ($res) {
            /** @var StoreProductServices $StoreProduct */
            $StoreProduct = app()->make(StoreProductServices::class);
            $stock = $StoreProduct->getProductStock((int)$productId, $unique);
            $cart_num = $res->cart_num + $num;
            if ($cart_num > $stock) {
                $cart_num = $stock;
            }
            $res->cart_num = $cart_num;
            $res->save();
        } else {
            $this->dao->update($id, ['product_attr_unique' => $unique, 'cart_num' => $num]);
        }
    }

    /**
     * 首页加入购物车
     * @param int $uid
     * @param int $productId
     * @param int $num
     * @param string $unique
     * @param int $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setCartNum(int $uid, int $productId, int $num, string $unique, int $type, int $store_id = 0)
    {
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);

        if ($unique == '') {
            $unique = $attrValueServices->value(['product_id' => $productId, 'type' => 0], 'unique');
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $productServices->isValidProduct((int)$productId);
        if (!$productInfo) {
            throw new ValidateException('该商品已下架或删除');
        }
        if (!($unique && $attrValueServices->getAttrvalueCount($productId, $unique, 0))) {
            throw new ValidateException('请选择有效的商品属性');
        }
        $stock = $productServices->getProductStock((int)$productId, $unique);
        if ($stock < $num) {
            throw new ValidateException('该商品库存不足' . $num);
        }
        //预售商品
        if ($productInfo['is_presale_product']) {
            if ($productInfo['presale_start_time'] > time()) throw new ValidateException('预售活动未开始');
            if ($productInfo['presale_end_time'] < time()) throw new ValidateException('预售活动已结束');
        }
        //检查限购
        if($type != 0) $this->checkLimit($uid, $productId, $num);
        if ($productInfo['type'] == 1 && $productInfo['relation_id'] && $store_id == 0) {
            $store_id = $productInfo['relation_id'];
        }

        $cart = $this->dao->getOne(['uid' => $uid, 'product_id' => $productId, 'product_attr_unique' => $unique, 'store_id' => $store_id, 'staff_id' => 0]);
        if ($cart) {
            if ($type == -1) {
                $cart->cart_num = $num;
            } elseif ($type == 0) {
                $cart->cart_num = $cart->cart_num - $num;
            } elseif ($type == 1) {
                if ($cart->cart_num >= $stock) {
                    throw new ValidateException('该商品库存只有' . $stock);
                }
                $new_cart_num = $cart->cart_num + $num;
                if ($new_cart_num > $stock) {
                    $new_cart_num = $stock;
                }
                $cart->cart_num = $new_cart_num;
            }
            if ($cart->cart_num === 0) {
                return $this->dao->delete($cart->id);
            } else {
                $cart->save();
                return $cart->id;
            }
        } else {
            $data = [
                'uid' => $uid,
                'store_id' => $store_id,
                'product_id' => $productId,
                'product_type' => $productInfo['product_type'],
                'cart_num' => $num,
                'product_attr_unique' => $unique,
                'type' => 0,
                'add_time' => time()
            ];
            $id = $this->dao->save($data)->id;
            event('cart.add', [$uid, 0, 0, 0]);
            return $id;
        }
    }

    /**
     * 用户购物车商品统计
     * @param int $uid
     * @param array $where
     * @param string $numType
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserCartCount(int $uid, array $where = [], string $numType = '0')
    {
        $count = 0;
        $ids = [];
        $cartNums = [];
        $sum_price = 0;
        $where['uid'] = $uid;
        $store_id = (int)(isset($where['store_id']) && is_array($where['store_id']) ? end($where['store_id']) : ($where['store_id'] ?? 0));
        $cartList = $this->dao->getUserCartList($where, '*', ['productInfo', 'attrInfo']);
        if ($cartList) {
            [$cartList, $valid, $invalid] = $this->handleCartList($uid, $cartList, [], -1, $store_id);
            /** @var StoreProductServices $storeProductServices */
            $storeProductServices = app()->make(StoreProductServices::class);
            $productInfos = $storeProductServices->getColumn([['id', 'in', array_column($cartList, 'product_id')]], 'id,pid,type,relation_id', 'id');
            /** @var StoreProductAttrValueServices $storePrdouctAttrValueServices */
            $storePrdouctAttrValueServices = app()->make(StoreProductAttrValueServices::class);
            $attrInfos = $storePrdouctAttrValueServices->getColumn([['unique', 'in', array_column($cartList, 'product_attr_unique')]], 'id,unique,price', 'unique');
            foreach ($cartList as $cart) {
                $productInfo = $productInfos[$cart['product_id']] ?? [];
                if (!$productInfo) continue;
                $attrInfo = $attrInfos[$cart['product_attr_unique']] ?? [];
                if (!$attrInfo) continue;
                if ($store_id > 0) {//某门店加入购物车商品数量
                    if (in_array($productInfo['type'], [0, 2]) || ($productInfo['type'] == 1 && $productInfo['relation_id'] == $store_id) || ($productInfo['type'] == 1 && $productInfo['pid'] > 0)) {
                        $ids[] = $cart['id'];
                        $cartNums[] = $cart['cart_num'];
                        $sum_price = bcadd((string)$sum_price, bcmul((string)$cart['cart_num'], (string)($cart['truePrice'] ?? $attrInfo['price']), 4), 2);
                    }
                } else {
                    $ids[] = $cart['id'];
                    $cartNums[] = $cart['cart_num'];
                    $sum_price = bcadd((string)$sum_price, bcmul((string)$cart['cart_num'], (string)($cart['truePrice'] ?? $attrInfo['price']), 4), 2);
                }
            }
            if ($numType) {
                $count = count($ids);
            } else {
                $count = array_sum($cartNums);
            }
        }
        return compact('count', 'ids', 'sum_price');
    }

    /**
     * 处理购物车数据
     * @param int $uid
     * @param array $cartList
     * @param array $addr
     * @param int $shipping_type
     * @param int $store_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function handleCartList(int $uid, array $cartList, array $addr = [], int $shipping_type = 1, int $store_id = 0, int $is_store_delivery_type = 0)
    {
        if (!$cartList) {
            return [$cartList, [], [], [], 0, [], []];
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var MemberCardServices $memberCardService */
        $memberCardService = app()->make(MemberCardServices::class);
        //付费会员状态
        $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price', false);
        /** @var UserLevelServices $userLevelServices */
        $userLevelServices = app()->make(UserLevelServices::class);
        //用户等级折扣
        [$userInfo, $discount] = $userLevelServices->getUserInfoAndLevelDiscount($uid);
        $tempIds = [];
        $cityId = (int)($addr['city_id'] ?? 0);
        //不送达运费模板
        if ($shipping_type == 1 && $cityId) {
            /** @var ShippingTemplatesServices $shippingService */
            $shippingService = app()->make(ShippingTemplatesServices::class);
            $tempIds = $shippingService->getNoDeliveryTempIdsByCartList($cityId, $cartList);
        }
        $latitude = $this->getItem('latitude', '');
        $longitude = $this->getItem('longitude', '');
        $user_store_id = $this->getItem('store_id', 0);
        $store_id = $store_id > 0 ? $store_id : $user_store_id;
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store_mention = $store_delivery = $store_city_delivery_status = true;
        $deliveryType = [];
        if ($store_id) {
            //配送方式:1、平台配送，2、到店自提，3、门店配送
            $deliverySettings = $storeServices->get(['id' => $store_id, 'is_del' => 0], ['delivery_type','city_delivery_status','city_delivery_type']);
            $deliveryType = is_string($deliverySettings['delivery_type']) ? explode(',', $deliverySettings['delivery_type']) : $deliverySettings['delivery_type'];
            $store_mention = in_array(2, $deliveryType);
            $store_delivery = in_array(3, $deliveryType);
            $store_city_delivery_status = $deliverySettings['city_delivery_status'];
        }
        //平台是否开启门店核销
        $store_self_mention = sys_config('store_func_status', 1) && sys_config('store_self_mention') && $store_mention;
        //同城配送开关
        $delivery_status_mention = sys_config('city_delivery_status') && $store_delivery;
        //商家自配开关
        $self_delivery_status = sys_config('self_delivery_status') && $store_city_delivery_status;
        if ($is_store_delivery_type != 2 && $shipping_type == 3) {
            $shipping_type = 1;
        }
        $productIds = $allStock = $attrUniquesArr = [];
        if ($store_id > 0) {//平台商品，在门店购买 验证门店库存
            /** @var StoreProductAttrValueServices $skuValueServices */
            $skuValueServices = app()->make(StoreProductAttrValueServices::class);
            /** @var StoreBranchProductServices $branchProductServics */
            $branchProductServics = app()->make(StoreBranchProductServices::class);
            foreach ($cartList as $cart) {
                $productInfo = $cart['productInfo'] ?? [];
                if (!$productInfo) continue;
                $product_id = 0;
                if (in_array($productInfo['type'], [0, 2])) {
                    $product_id = $productInfo['id'];
                } else {//门店商品
                    if ($productInfo['pid'] && $productInfo['relation_id'] != $store_id) {//平台共享商品到另一个门店购买
                        $product_id = $productInfo['pid'];
                    }
                }
                if (!$product_id) {//自己门店购买不用再次验证库存
                    continue;
                }
                $productIds[] = $cart['product_id'];
                $suk = '';
                //类型 0:普通、1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、6:预售、7:新人礼、8:抽奖、9:拼单、10:桌码、11:卡项、12:预约 、13:配送
                switch ($cart['type']) {
                    case 0:
                    case 6:
                    case 8:
                    case 9:
                    case 10:
                    case 11:
                    case 12:
                        $suk = $skuValueServices->value(['unique' => $cart['product_attr_unique'], 'product_id' => $cart['product_id'], 'type' => 0], 'suk');
                        break;
                    case 1:
                    case 2:
                    case 3:
                    case 4:
                    case 5:
                    case 7:
                        if ($cart['type'] == 5 && isset($cart['discount_product_id'])) {
                            $product_id = $cart['discount_product_id'];
                        } else {
                            $product_id = $cart['activity_id'];
                        }
                        $suk = $skuValueServices->value(['unique' => $cart['product_attr_unique'], 'product_id' => $product_id, 'type' => $cart['type']], 'suk');
                        break;
                }
                $branchProductInfo = $branchProductServics->isValidStoreProduct((int)$cart['product_id'], $store_id);
                if (!$branchProductInfo) {
                    continue;
                }
                $attrValue = '';
                if ($suk) {
                    $attrValue = $skuValueServices->get(['suk' => $suk, 'product_id' => $branchProductInfo['id'], 'type' => 0]);
                }
                if (!$attrValue) {
                    continue;
                }
                $allStock[$attrValue['unique']] = $attrValue['stock'];
                $attrUniquesArr[$cart['product_attr_unique']] = $attrValue['unique'];
            }
        } else {
            $productIds = array_unique(array_column($cartList, 'product_id'));
        }

        $storeInfo = [];
        if ($store_id) {
            $storeInfo = $storeServices->getNearbyStore(['id' => $store_id], '', '', '', 1);
        } else if ($latitude && $longitude) {
            $storeInfo = $storeServices->getNearbyStore([], $latitude, $longitude, '', 1);
        }
        $valid = $invalid = [];
        /** @var StoreProductLabelServices $storeProductLabelServices */
        $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
        $productServices->setItem('isVip', $this->getItem('isVip', 0));
        $siteUrl = sys_config('site_url');
		//验证是否在配送范围
		$isCityStoreDeliveryScope = true;
		if ($shipping_type == 3) {
			if (isset($addr['isCityStoreDeliveryScope'])) {
				$isCityStoreDeliveryScope = $addr['isCityStoreDeliveryScope'];
			} else {
				$isCityStoreDeliveryScope = $storeServices->checkCityStoreDeliveryScope($uid, $store_id, $addr);
			}
		}
        foreach ($cartList as &$item) {
            $item['is_gift'] = 0;
            if (isset($item['cart_type']) && $item['cart_type'] == 3) {//无码商品
                $item['productInfo'] = [
                    'id' => 0, 'pid' => 0, 'type' => 1, 'relation_id' => $item['store_id'], 'store_name' => '无码商品', 'image' => $siteUrl . '/statics/images/product/uncensored_product_image.png', 'price' => $item['price'], 'delivery_type' => [], 'code' => '', 'bar_code' => '',
                    'attrInfo' => ['id' => 0, 'type' => 0, 'product_id' => 0, 'suk' => '默认', 'unique' => $item['product_attr_unique'], 'image' => $siteUrl . '/statics/images/product/uncensored_product_image.png', 'price' => $item['price'], 'stock' => 1, 'code' => '', 'bar_code' => '']
                ];
            }
            if (isset($item['productInfo']['delivery_type'])) {
                $item['productInfo']['delivery_type'] = is_string($item['productInfo']['delivery_type']) ? explode(',', $item['productInfo']['delivery_type']) : $item['productInfo']['delivery_type'];
            } else {
                $item['productInfo']['delivery_type'] = [];
            }
            if (!isset($item['productInfo']['attrInfo']) || !$item['productInfo']['attrInfo']) {
                $item['productInfo']['attrInfo'] = $item['attrInfo'] ?? [];
            }
			$item['productInfo'] = get_thumb_water($item['productInfo']);
			$productInfo = $item['productInfo'];
            $item['productInfo']['attrInfo'] = get_thumb_water($item['productInfo']['attrInfo']);
            $item['attrStatus'] = isset($item['productInfo']['attrInfo']['stock']) && $item['productInfo']['attrInfo']['stock'];
            $item['productInfo']['attrInfo']['image'] = $item['productInfo']['attrInfo']['image'] ?? $item['productInfo']['image'] ?? '';
            $item['productInfo']['attrInfo']['suk'] = $item['productInfo']['attrInfo']['suk'] ?? '已失效';

            //门店独立商品
            $isBranchProduct = isset($productInfo['type']) && isset($productInfo['pid']) && $productInfo['type'] == 1 && !$productInfo['pid'];
            $product_store_id = $isBranchProduct ? $productInfo['relation_id'] : 0;
            $item['costPrice'] = $productInfo['attrInfo']['cost'] ?? $productInfo['cost'] ?? 0;
            $item['trueStock'] = $item['branch_stock'] = $productInfo['attrInfo']['stock'] ?? $productInfo['stock'] ?? 0;
            $item['branch_sales'] = $productInfo['attrInfo']['sales'] ?? $productInfo['sales'] ?? 0;
            $item['vip_price'] = $productInfo['attrInfo']['vip_price'] ?? $productInfo['vip_price'] ?? 0;
            $item['vip_truePrice'] = 0;
            $item['price_type'] = '';
            $item['truePrice'] = $item['sum_price'] = (float)($productInfo['attrInfo']['price'] ?? $productInfo['price'] ?? 0);
            $item['service_price'] = $productInfo['reservationTimeInfo'][0]['service_price'] ?? ($productInfo['reservationTimeInfo']['service_price'] ?? 0);
            $item['total_price'] = bcmul((string)$item['truePrice'], (string)$item['cart_num'], 2);
            $item['settle_price'] = bcmul((string)($productInfo['attrInfo']['settle_price'] ?? 0), (string)$item['cart_num'], 2);
            if ((!$item['type'] || !$item['activity_id']) && !$isBranchProduct) {
                [$truePrice, $vip_truePrice, $price_type] = $productServices->setLevelPrice($item['truePrice'], $uid, $userInfo, $vipStatus, $discount, $item['vip_price'], $productInfo['is_vip'] ?? 0, true, ['level_type' => $productInfo['level_type'] ?? 1, 'level_price' => $productInfo['attrInfo']['level_price'] ?? '']);
                $item['truePrice'] = $truePrice;
                $item['vip_truePrice'] = $vip_truePrice;
                $item['price_type'] = $price_type;
            }
            $item['member_price'] = bcmul((string)$item['vip_truePrice'], (string)$item['cart_num'], 2);
            $item['pay_price'] = bcmul((string)$item['truePrice'], (string)$item['cart_num'], 2);
            // 购物车行 price 存 0：不占实付、不计入优惠券基数（收银 sendAll 赠送项目）
            if (array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '' && bccomp((string)$item['price'], '0', 2) === 0) {
                $item['truePrice'] = 0;
                $item['sum_price'] = 0;
                $item['total_price'] = '0.00';
                $item['settle_price'] = '0.00';
                $item['vip_truePrice'] = 0;
                $item['member_price'] = 0;
                $item['pay_price'] = 0;
            }

            $item['productInfo']['store_label'] = [];
            if (isset($item['productInfo']['store_label_id']) && $item['productInfo']['store_label_id']) {
                $item['productInfo']['store_label'] = $storeProductLabelServices->getLabelCache($item['productInfo']['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
            }
            unset($item['attrInfo']);
            $applicable_type = $item['productInfo']['applicable_type'] ?? 1;
            $applicable_store_id = [];
            if (isset($item['productInfo']['applicable_store_id'])) {
                $applicable_store_id = is_string($item['productInfo']['applicable_store_id']) ? explode(',', $item['productInfo']['applicable_store_id']) : $item['productInfo']['applicable_store_id'];
            }
            $applicableStatus = $store_id <= 0 || $applicable_type == 1 || ($applicable_type == 2 && in_array($store_id, $applicable_store_id));
            if (isset($item['status']) && $item['status'] == 0) {
                $item['is_valid'] = 0;
                $item['invalid_desc'] = '此商品已失效';
                $invalid[] = $item;
            } elseif (($item['productInfo']['type'] ?? 0) == 1 && ($item['productInfo']['pid'] ?? 0) == 0 && $storeInfo && ($item['productInfo']['relation_id'] ?? 0) != $storeInfo['id'] && $item['type'] != 10) {
                $item['is_valid'] = 0;
                $item['invalid_desc'] = '此商品不属于该门店';
                $invalid[] = $item;
            } elseif ((isset($item['productInfo']['delivery_type']) && !$item['productInfo']['delivery_type']) || in_array($item['productInfo']['product_type'], [1, 2, 3])) {
                $item['is_valid'] = 1;
                $valid[] = $item;
            } elseif (!$applicableStatus) {
                $item['is_valid'] = 0;
                $item['invalid_desc'] = '此商品未同步至该门店';
                $invalid[] = $item;
            } else {
                $condition = !in_array(isset($item['productInfo']['product_id']) ? $item['productInfo']['product_id'] : $item['productInfo']['id'], $productIds) || $item['cart_num'] > ($allStock[$attrUniquesArr[$item['product_attr_unique']] ?? ''] ?? 0);
                if ($item['type'] != 10) {
                    switch ($shipping_type) {
                        case -1://购物车列表展示
                            if ($isBranchProduct && $store_id > 0 && ($store_id != $product_store_id)) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品不属于该门店';
                                $invalid[] = $item;
                            } else {
                                $item['is_valid'] = 1;
                                $valid[] = $item;
                            }
                            break;
                        case 1:
                            //快递不送达
                            if ($is_store_delivery_type && $deliveryType) {
                                if (in_array($item['productInfo']['temp_id'], $tempIds) || isset($item['productInfo']['delivery_type']) && !in_array(3, $item['productInfo']['delivery_type']) || isset($item['productInfo']['store_delivery_type']) && !in_array(1, $item['productInfo']['store_delivery_type']) || !in_array(1, $deliveryType)) {
                                  if($item['productInfo']['product_type'] == 0) {
                                      $item['is_valid'] = 0;
                                      $item['invalid_desc'] = '此商品不支持快递配送/在非配送区域/门店不支持快递';
                                      $invalid[] = $item;
                                  }else{
                                      $item['is_valid'] = 1;
                                      $valid[] = $item;
                                  }
                                } elseif ($isBranchProduct && $store_id > 0 && $store_id != $product_store_id) {
                                    $item['is_valid'] = 0;
                                    $item['invalid_desc'] = '此商品不是该门店添加的独立商品';
                                    $invalid[] = $item;
                                } else {
                                    $item['is_valid'] = 1;
                                    $valid[] = $item;
                                }
                            } else {
                                if (in_array($item['productInfo']['temp_id'], $tempIds) || (isset($item['productInfo']['delivery_type']) && !in_array(1, $item['productInfo']['delivery_type']))) {
                                    $item['is_valid'] = 0;
                                    $item['invalid_desc'] = '此商品不支持快递配送/在非配送区域';
                                    $invalid[] = $item;
                                } elseif ($isBranchProduct && $store_id > 0 && ($store_id != $product_store_id || !in_array(1, $item['productInfo']['delivery_type']))) {
                                    $item['is_valid'] = 0;
                                    $item['invalid_desc'] = '此商品不支持门店快递配送/商品是属于该门店';
                                    $invalid[] = $item;
                                } elseif ((in_array($productInfo['type'], [0, 2]) || $productInfo['relation_id'] != $store_id) && $store_id > 0 && ($condition || (!in_array(1, $item['productInfo']['delivery_type'])))) {//平台商品 在门店购买 验证门店库存
                                    $item['is_valid'] = 0;
                                    $item['invalid_desc'] = '此商品在门店中没有库存/不支持门店配送';
                                    $invalid[] = $item;
                                } else {
                                    $item['is_valid'] = 1;
                                    $valid[] = $item;
                                }
                            }
                            break;
                        case 2:
                            //不支持到店核销
                            if (!$store_self_mention) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '平台/门店已关闭核销';
                                $invalid[] = $item;
                            } elseif (isset($item['productInfo']['delivery_type']) && $item['productInfo']['delivery_type'] && !in_array(2, $item['productInfo']['delivery_type'])) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品不支持到店核销';
                                $invalid[] = $item;
                            } elseif ($isBranchProduct && $store_id > 0 && $store_id != $product_store_id) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品不属于该门店';
                                $invalid[] = $item;
                            } elseif ($item['productInfo']['product_type'] == 1) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品是卡密商品';
                                $invalid[] = $item;
                            } elseif ((in_array($productInfo['type'], [0, 2]) || $productInfo['relation_id'] != $store_id) && $store_id > 0 && $condition) {//平台、供应商商品 在门店购买 验证门店库存
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品在门店中没有库存';
                                $invalid[] = $item;
                            } else {
                                $item['is_valid'] = 1;
                                $valid[] = $item;
                            }
                            break;
                        case 3:
                            //不支持同城配送
                            if (!$delivery_status_mention) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '平台/门店已关闭同城配送';
                                $invalid[] = $item;
                            }elseif (!$self_delivery_status) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '平台/门店已关闭了同城配送商家自配';
                                $invalid[] = $item;
                            } elseif (isset($item['productInfo']['delivery_type']) && $item['productInfo']['delivery_type'] && !in_array(3, $item['productInfo']['delivery_type'])) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品不支持门店配送';
                                $invalid[] = $item;
                            } elseif (isset($item['productInfo']['store_delivery_type']) && $item['productInfo']['store_delivery_type'] && !in_array(2, $item['productInfo']['store_delivery_type'])) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品门店配送方式不正确';
                                $invalid[] = $item;
                            } elseif ($isBranchProduct && $store_id > 0 && $store_id != $product_store_id) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品不属于该门店';
                                $invalid[] = $item;
                            } elseif ((in_array($productInfo['type'], [0, 2]) || $productInfo['relation_id'] != $store_id) && $store_id > 0 && $condition) {//平台、供应商商品 在门店购买 验证门店库存
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品在门店中没有库存';
                                $invalid[] = $item;
                            } elseif (!$isCityStoreDeliveryScope) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品超出配送/核销范围';
                                $invalid[] = $item;
                            } else {
                                $item['is_valid'] = 1;
                                $valid[] = $item;
                            }
                            break;
                        case 4:
                            //无库存｜｜下架
                            if ($isBranchProduct && $store_id > 0 && $store_id != $product_store_id) {
                                $item['is_valid'] = 0;
                                $item['invalid_desc'] = '此商品超出配送/核销范围';
                                $invalid[] = $item;
                            } elseif (in_array($productInfo['type'], [0, 2]) && $store_id > 0 && $condition) {
                                $item['is_valid'] = 0;
                                $invalid[] = $item;
                            } else {
                                $item['is_valid'] = 1;
                                $valid[] = $item;
                            }
                            break;
                        default:
                            $item['is_valid'] = 1;
                            $valid[] = $item;
                            break;
                    }
                } else {
                    if ($isBranchProduct && $store_id > 0 && $store_id != $product_store_id) {
                        $item['is_valid'] = 0;
                        $item['invalid_desc'] = '此商品不属于该门店';
                        $invalid[] = $item;
                    } elseif (in_array($productInfo['type'], [0, 2]) && $store_id > 0 && $condition) {
                        $item['is_valid'] = 0;
                        $invalid[] = $item;
                    } else {
                        $item['is_valid'] = 1;
                        $valid[] = $item;
                    }
                }
            }
            unset($item['attrInfo']);
        }


        return [$cartList, $valid, $invalid];
    }


    /**
     * 门店给用户加入购物车
     * @param int $uid
     * @param int $productId
     * @param int $cartNum
     * @param string $unique
     * @param int $staff_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    /** 赠送项目壳商品名称 */
    protected const GIFT_PROJECT_PRODUCT_NAME = '赠送项目';

    /** 定制卡壳商品 ID */
    protected const CUSTOM_CARD_SHELL_PRODUCT_ID = 8154;

    /**
     * 是否「赠送项目」壳商品
     */
    protected function isGiftProjectShellProduct(int $productId, array $productInfo): bool
    {
        $name = (string)($productInfo['store_name'] ?? '');
        if ($name === self::GIFT_PROJECT_PRODUCT_NAME) {
            return true;
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $storeName = (string)$productServices->value(['id' => $productId], 'store_name');
        return $storeName === self::GIFT_PROJECT_PRODUCT_NAME;
    }

    /**
     * 是否「赠送项目」壳商品（按商品 ID）
     */
    public function isGiftProjectShellByProductId(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $productServices->getProductInfo($productId, 'id,store_name');
        if (!$productInfo) {
            return false;
        }
        $productInfo = is_array($productInfo) ? $productInfo : $productInfo->toArray();
        return $this->isGiftProjectShellProduct($productId, $productInfo);
    }

    /**
     * 订单是否含「赠送项目」壳商品
     */
    public function isGiftProjectShellOrder(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $shellProductId = (int)$cartInfoServices->value(['oid' => $orderId, 'cart_type' => 0], 'product_id');
        return $this->isGiftProjectShellByProductId($shellProductId);
    }

    /**
     * 是否定制卡壳（仅 8154 本体，不含 pid=8154 的内项）
     */
    protected function isCustomCardShellProduct(int $productId, array $productInfo): bool
    {
        if ($productId === self::CUSTOM_CARD_SHELL_PRODUCT_ID) {
            return true;
        }
        if ((int)($productInfo['id'] ?? 0) === self::CUSTOM_CARD_SHELL_PRODUCT_ID) {
            return true;
        }
        $name = (string)($productInfo['store_name'] ?? '');
        return (int)($productInfo['pid'] ?? 0) === self::CUSTOM_CARD_SHELL_PRODUCT_ID
            && strpos($name, '定制卡') !== false;
    }

    /**
     * 收银台购物车行查询条件
     */
    protected function cashierCartBaseWhere(int $uid, int $storeId, int $staffId, string $touristUid): array
    {
        return [
            'uid' => $uid,
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'tourist_uid' => $touristUid,
            'is_del' => 0,
            'is_new' => 0,
            'is_pay' => 0,
            'status' => 1,
        ];
    }

    /**
     * 购物车是否已有赠送项目
     */
    protected function cashierCartHasGiftProject(int $uid, int $storeId, int $staffId, string $touristUid): bool
    {
        $rows = $this->dao->getCartList($this->cashierCartBaseWhere($uid, $storeId, $staffId, $touristUid));
        if (!$rows) {
            return false;
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $name = (string)$productServices->value(['id' => (int)$row['product_id']], 'store_name');
            if ($name === self::GIFT_PROJECT_PRODUCT_NAME) {
                return true;
            }
        }
        return false;
    }

    /**
     * 购物车是否已有定制卡壳
     */
    protected function cashierCartHasCustomCardShell(int $uid, int $storeId, int $staffId, string $touristUid): bool
    {
        $rows = $this->dao->getCartList($this->cashierCartBaseWhere($uid, $storeId, $staffId, $touristUid));
        if (!$rows) {
            return false;
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $pid = (int)$row['product_id'];
            if ($pid === self::CUSTOM_CARD_SHELL_PRODUCT_ID) {
                return true;
            }
            $info = $productServices->get($pid);
            if ($info && $this->isCustomCardShellProduct($pid, is_array($info) ? $info : $info->toArray())) {
                return true;
            }
        }
        return false;
    }

    /**
     * 收银台加购前校验：定制卡唯一、赠送项目互斥
     */
    protected function validateCashierCartAdd(int $uid, int $storeId, int $staffId, string $touristUid, int $productId, array $productInfo): void
    {
        $isGiftShell = $this->isGiftProjectShellProduct($productId, $productInfo);
        $isCustomShell = $this->isCustomCardShellProduct($productId, $productInfo);
        $hasGift = $this->cashierCartHasGiftProject($uid, $storeId, $staffId, $touristUid);
        if ($isGiftShell) {
            if ($hasGift) {
                throw new ValidateException('购物车中已有赠送项目');
            }
            $otherIds = [];
            $rows = $this->dao->getCartList($this->cashierCartBaseWhere($uid, $storeId, $staffId, $touristUid));
            foreach ($rows ?: [] as $row) {
                $row = is_array($row) ? $row : $row->toArray();
                if ((int)$row['product_id'] !== $productId) {
                    $otherIds[] = (int)$row['id'];
                }
            }
            if ($otherIds) {
                $this->removeUserCart($uid, $otherIds);
            }
            return;
        }
        if ($hasGift) {
            throw new ValidateException('购物车里有赠送项目，无法添加其他项目');
        }
        if ($isCustomShell && $this->cashierCartHasCustomCardShell($uid, $storeId, $staffId, $touristUid)) {
            throw new ValidateException('定制卡只能选择一张');
        }
    }

    /**
     * 购物车行中是否含「赠送项目」壳商品
     */
    public function cartIdsHasGiftProjectShell(array $cartIds): bool
    {
        if (!$cartIds) {
            return false;
        }
        $cartIds = array_values(array_filter(array_map('intval', $cartIds)));
        if (!$cartIds) {
            return false;
        }
        $rows = $this->dao->getCartList(['id' => $cartIds, 'status' => 1]);
        if (!$rows) {
            return false;
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        foreach ($rows as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $name = (string)$productServices->value(['id' => $pid], 'store_name');
            if ($name === self::GIFT_PROJECT_PRODUCT_NAME) {
                return true;
            }
        }
        return false;
    }

    /**
     * 收银台下单：购物车含赠送项目时必须选择子项目
     */
    public function validateCashierGiftProjectCheckout(array $cartIds, $selectedProduct): void
    {
        if (!$cartIds) {
            return;
        }
        if (!$this->cartIdsHasGiftProjectShell($cartIds)) {
            return;
        }
        $selected = is_array($selectedProduct) ? $selectedProduct : [];
        $selected = array_values(array_filter(array_map('intval', $selected)));
        if (!$selected) {
            throw new ValidateException('请选择赠送哪些项目');
        }
    }

    public function addCashierCart(int $uid, int $productId, int $cartNum, string $unique, int $staff_id = 0)
    {
        $store_id = $this->getItem('store_id', 0);
        $tourist_uid = $this->getItem('tourist_uid', '');
        if (!$store_id) {
            throw new ValidateException('缺少门店ID');
        }
        [$attrInfo, $unique, $cart_num, $productInfo] = $this->checkProductStock($uid, $productId, $cartNum, (int)$store_id, $unique, true);
        $this->validateCashierCartAdd($uid, (int)$store_id, $staff_id, (string)$tourist_uid, $productId, $productInfo);
        $nowStock = $attrInfo['stock'] ?? 0;
        ProductLogJob::dispatch(['cart', ['uid' => $uid, 'product_id' => $productId, 'cart_num' => $cartNum]]);
        $cart = $this->dao->getOne([
            'uid' => $uid,
            'product_id' => $productId,
            'product_attr_unique' => $unique,
            'store_id' => $store_id,
            'staff_id' => $staff_id,
            'tourist_uid' => $tourist_uid,
            'is_del' => 0,
            'is_new' => 0,
            'is_pay' => 0,
            'status' => 1
        ]);
        if ($cart) {
            if ($this->isCustomCardShellProduct($productId, $productInfo) || $this->isGiftProjectShellProduct($productId, $productInfo)) {
                throw new ValidateException($this->isGiftProjectShellProduct($productId, $productInfo) ? '购物车中已有赠送项目' : '定制卡只能选择一张');
            }
            if ($nowStock < ($cartNum + $cart['cart_num'])) {
                $cartNum = $nowStock - $cartNum;//剩余库存
            }
            if ($cartNum == 0) throw new ValidateException('库存不足');
            $cart->cart_num = $cartNum + $cart['cart_num'];
            $cart->add_time = time();
            $cart->save();
            return $cart->id;
        } else {
            $add_time = time();
            $data = compact('uid', 'store_id', 'add_time', 'tourist_uid');
            $data['type'] = 0;
            $data['product_id'] = $productId;
            $data['product_type'] = $productInfo['product_type'];
            $data['cart_num'] = $cartNum;
            $data['product_attr_unique'] = $unique;
            $data['store_id'] = $store_id;
            $data['staff_id'] = $staff_id;
            $id = $this->dao->save($data)->id;
            event('cart.add', [$uid, $tourist_uid, $store_id, $staff_id]);
            return $id;
        }
    }

    /**
     * @param int $id
     * @param int $number
     * @param int $uid
     * @param int $storeId
     * @return bool|\mohe\basic\BaseModel
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function changeCashierCartNum(int $id, int $number, int $uid, int $storeId = 0)
    {
        if (!$id || !$number) return false;
        $where = ['uid' => $uid, 'id' => $id];
        if ($storeId) {
            $where['store_id'] = $storeId;
        }
        $carInfo = $this->dao->getOne($where, 'cart_type,product_id,product_attr_unique,cart_num');
        if (!$carInfo) {
            throw new ValidateException('获取购物车数据失败');
        }
        if ($carInfo->cart_num == $number) return true;
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $productServices->get((int)$carInfo['product_id']);
        $productInfo = $productInfo ? (is_array($productInfo) ? $productInfo : $productInfo->toArray()) : [];
        if ($this->isCustomCardShellProduct((int)$carInfo['product_id'], $productInfo) && $number > 1) {
            throw new ValidateException('定制卡只能选择一张');
        }
        if ($this->isGiftProjectShellProduct((int)$carInfo['product_id'], $productInfo) && $number > 1) {
            throw new ValidateException('赠送项目只能添加一个');
        }
        if ($carInfo['cart_type'] == 0 && $carInfo['product_id']) {
            /** @var StoreBranchProductServices $storeProduct */
            $storeProduct = app()->make(StoreBranchProductServices::class);
            $stock = $storeProduct->getProductStock($carInfo->product_id, $storeId, $carInfo->product_attr_unique);
            if (!$stock) throw new ValidateException('暂无库存');
            if ($stock < $number) throw new ValidateException('库存不足' . $number);
            $this->setItem('is_set', 1);
            $this->checkProductStock($uid, (int)$carInfo->product_id, $number, $storeId, $carInfo->product_attr_unique, true);
            $this->reset();
        }
        return $this->dao->changeUserCartNum(['uid' => $uid, 'id' => $id], (int)$number);
    }

    /**
     * 购物车重选
     * @param int $cart_id
     * @param int $product_id
     * @param string $unique
     */
    public function modifyCashierCart(int $storeId, int $cart_id, int $product_id, string $unique)
    {
        /** @var StoreProductAttrValueServices $attrService */
        $attrService = app()->make(StoreProductAttrValueServices::class);
        $stock = $attrService->value(['product_id' => $product_id, 'unique' => $unique, 'type' => 0], 'stock');
        if ($stock > 0) {
            $this->dao->update($cart_id, ['product_attr_unique' => $unique, 'cart_num' => 1]);
        } else {
            throw new ValidateException('选择的规格库存不足');
        }
    }

    /**
     * 批量加入购物车
     * @param array $cart
     * @param int $storeId
     * @param int $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function batchAddCart(array $cart, int $storeId, int $uid)
    {
        $this->setItem('store_id', $storeId);
        $cartIds = [];
        foreach ($cart as $item) {
            if (!isset($item['productId'])) {
                throw new ValidateException('缺少商品ID');
            }
            if (!isset($item['cartNum'])) {
                throw new ValidateException('缺少购买商品数量');
            }
            if (!isset($item['uniqueId'])) {
                throw new ValidateException('缺少唯一值');
            }
            $cartIds[] = $this->addCashierCart($uid, (int)$item['productId'], (int)$item['cartNum'], $item['uniqueId']);
        }
        $this->reset();
        return $cartIds;
    }

    /**
     * 组合前端购物车需要的数据结构
     * @param array $cartList
     * @param array $protmoions
     * @return array
     */
    public function getReturnCartList(array $cartList, array $promotions)
    {
        $result = [];
        if ($cartList) {
            if ($promotions) $promotions = array_combine(array_column($promotions, 'id'), $promotions);
            $i = 0;
            foreach ($cartList as $key => $cart) {
                $data = ['promotions' => [], 'pids' => [], 'cart' => []];
                if ($result && isset($cart['promotions_id']) && $cart['promotions_id'] && (!isset($cart['collate_code_id']) || $cart['collate_code_id'] <= 0)) {
                    $isTure = false;
                    foreach ($result as $keys => &$res) {
                        if (array_intersect($res['pids'], $cart['promotions_id'])) {
                            $res['pids'] = array_unique(array_merge($res['pids'], $cart['promotions_id'] ?? []));
                            $res['cart'][] = $cart;
                            $isTure = true;
                            break;
                        }
                    }
                    if (!$isTure) {
                        $data['cart'][] = $cart;
                        $data['pids'] = array_unique($cart['promotions_id'] ?? []);
                        $result[$i] = $data;
                        $i++;
                    }
                } else {
                    $data['cart'][] = $cart;
                    $data['pids'] = array_unique($cart['promotions_id'] ?? []);
                    $result[$i] = $data;
                    $i++;
                }
            }

            foreach ($result as $key => &$item) {
                if ($item['pids']) {
                    foreach ($item['pids'] as $key => $id) {
                        $item['promotions'][] = $promotions[$id] ?? [];
                    }
                }
            }
        }
        return $result;
    }


    /**
     * 控制购物车加入商品最大数量
     * @param int $uid
     * @param int $tourist_uid
     * @param int $store_id
     * @param int $staff_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function controlCartNum(int $uid, int $tourist_uid = 0, int $store_id = 0, int $staff_id = 0)
    {
        $maxCartNum = $this->maxCartNum;
        $where = [
            'is_del' => 0,
            'is_new' => 0,
            'is_pay' => 0,
            'status' => 1
        ];
        if ($uid) $where['uid'] = $uid;
        if ($tourist_uid) $where['tourist_uid'] = $tourist_uid;
        if ($store_id) $where['store_id'] = $store_id;
        if ($staff_id) $where['staff_id'] = $staff_id;
        try {
            $count = $this->dao->count($where);
            if ($count >= $maxCartNum) {//删除一个最早加入购物车商品
                $one = $this->dao->search($where)->order('id asc')->find();
                if ($one) {
                    $this->dao->delete($one['id']);
                }
            }
        } catch (\Throwable $e) {
            \think\facade\Log::error('自动控制购物车数量，删除最早加入商品失败：' . $e->getMessage());
        }
        return true;
    }

    /**
     * 检测限购
     * @param int $uid
     * @param int $product_id
     * @param int $num
     * @param bool $new
     * @param int $store_id
     * @return bool
     */
    public function checkLimit(int $uid, int $product_id, int $num, bool $new = false, int $store_id = 0)
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $limitInfo = $productServices->get($product_id, ['id', 'pid', 'is_limit', 'limit_type', 'limit_num']);
        if (!$limitInfo) throw new ValidateException('商品不存在');
        $limitInfo = $limitInfo->toArray();
        if (!$limitInfo['is_limit']) return true;
        $cartNum = 0;
        //收银台游客限购
        $tourist_uid = 0;
        if (!$uid) {
            $tourist_uid = $this->getItem('tourist_uid', '');
        }
        $pid = $limitInfo['pid'] ? $limitInfo['pid'] : $limitInfo['id'];
        $product_ids = $productServices->getColumn(['pid' => $pid], 'id');
        $product_ids[] = $pid;
        if (!$new) {//	购物车商品数量
            $cartNum = $this->dao->sum(['uid' => $uid, 'tourist_uid' => $tourist_uid, 'product_id' => $product_ids, 'store_id' => $store_id, 'status' => 1, 'is_del' => 0], 'cart_num', true);
        }
        if ($limitInfo['limit_type'] == 1) {
            if (($num + $cartNum) > $limitInfo['limit_num']) {
                throw new ValidateException('单次购买不能超过 ' . $limitInfo['limit_num'] . ' 件');
            }
        } else if ($limitInfo['limit_type'] == 2) {
            /** @var StoreOrderCartInfoServices $orderCartServices */
            $orderCartServices = app()->make(StoreOrderCartInfoServices::class);
            /** @var StoreOrderServices $storeOrderServices */
            $storeOrderServices = app()->make(StoreOrderServices::class);
            //取消购买限购数量
            $orderDelNum = $storeOrderServices->search(['paid' => 0, 'is_del' => 1])->column('id');
            //购买数量
            $orderPayNum = $orderCartServices->search(['uid' => $uid, 'product_id' => $product_id])
                ->when($orderDelNum, function ($query) use ($orderDelNum) {
                    $query->whereNotIn('oid', $orderDelNum);
                })->sum('cart_num');
            //退款数量
            $orderRefundNum = $orderCartServices->sum(['uid' => $uid, 'product_id' => $product_ids], 'refund_num');
            $orderNum = $cartNum + $orderPayNum - $orderRefundNum;
            if (($num + $orderNum) > $limitInfo['limit_num']) {
                throw new ValidateException('该商品限购 ' . $limitInfo['limit_num'] . ' 件，您已经购买了' . $limitInfo['limit_num'] . ' 件');
            }
        }
        return true;
    }

    /**
     * 计算用户购物车商品（优惠活动、最优优惠券）
     * @param array $user
     * @param $cartId
     * @param bool $new
     * @param int $addressId
     * @param int $shipping_type
     * @param int $store_id
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function computeUserCart(int $uid, $cartId, bool $new, int $addressId, int $shipping_type = 1, int $store_id = 0)
    {
        $data = [];
        //获取购物车信息
        $cartGroup = $this->getUserProductCartListV1($uid, $cartId, $new, [], $shipping_type, $store_id, 0, true);
        $valid = $cartGroup['valid'] ?? [];
        /** @var StoreOrderComputedServices $computedServices */
        $computedServices = app()->make(StoreOrderComputedServices::class);
        $sumPrice = $computedServices->getOrderSumPrice($valid, 'sum_price');//获取订单原总金额
        $totalPrice = $computedServices->getOrderSumPrice($valid, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额
        $vipPrice = $computedServices->getOrderSumPrice($valid, 'vip_truePrice');//获取订单会员优惠金额

        $deduction = $cartGroup['deduction'] ?? [];
        $coupon = $cartGroup['useCoupon'] ?? [];
        $promotions = [];
        $giveCartList = $cartGroup['giveCartList'] ?? [];
        $couponPrice = $cartGroup['couponPrice'] ?? 0;
        $firstOrderPrice = $cartGroup['firstOrderPrice'];

        $cartList = array_merge($valid, $giveCartList);
        $promotionsPrice = 0;
        if ($cartList) {
            foreach ($cartList as $key => $cart) {
                if (isset($cart['promotions_true_price']) && isset($cart['price_type']) && $cart['price_type'] == 'promotions') {
                    $promotionsPrice = bcadd((string)$promotionsPrice, (string)bcmul((string)$cart['promotions_true_price'], (string)$cart['cart_num'], 2), 2);
                }
            }
        }
        $deduction['promotions_price'] = (float)$promotionsPrice;
        $deduction['coupon_price'] = (float)$couponPrice;
        $deduction['first_order_price'] = (float)$firstOrderPrice;
        $deduction['sum_price'] = (float)$sumPrice;
        $deduction['vip_price'] = (float)$vipPrice;

		$payPrice = $this->getPayPrice((string)$totalPrice, (string)$firstOrderPrice);
        $deduction['pay_price'] = (float)$payPrice;

        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $svip_status = $userServices->checkUserIsSvip($uid);
        $svip_price = 0.00;
        //开启付费会员 且用户不是付费会员 //计算 开通付费会员节省金额
        if (sys_config('member_card_status', 1) && !$svip_status) {
            [$vipPayPrice, $payPostage, $storePostageDiscount] = $this->computeUserVipCart($uid, $cartId, $shipping_type, $new, $store_id);
            $svip_price = (float)max(bcsub((string)$payPrice, (string)$vipPayPrice, 2), 0);
        }
        return compact('promotions', 'coupon', 'deduction', 'svip_status', 'svip_price');
    }

    /**
     * 计算用户是付费会员节省多少钱
     * @param int $uid
     * @param $cartId
     * @param int $shipping_type
     * @param bool $new
     * @param int $store_id
     * @param bool $isCart
     * @param int $couponId
     * @param $addr
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function computeUserVipCart(int $uid, $cartId, int $shipping_type = 1, bool $new = false, int $store_id = 0, bool $isCart = true, int $couponId = 0, array $addr = [], int $is_store_delivery_type = 0)
    {
        //获取购物车信息
        $this->setItem('isVip', 1);
        $cartGroup = $this->getUserProductCartListV1($uid, $cartId, $new, [], $shipping_type, $store_id, $couponId, $isCart, $is_store_delivery_type);
        $this->reset();
        $valid = $cartGroup['valid'] ?? [];
		/** @var StoreOrderComputedServices $computedServices */
		$computedServices = app()->make(StoreOrderComputedServices::class);
		$totalPrice = $computedServices->getOrderSumPrice($valid, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额

        $payPrice = $this->getPayPrice((string)$totalPrice, (string)($cartGroup['firstOrderPrice'] ?? 0));
        //不是购物车 计算运费
        if (!$isCart) {
            //没传地址id或地址已删除未找到 ||获取默认地址
            if (!$addr) {
                /** @var UserAddressServices $addressServices */
                $addressServices = app()->make(UserAddressServices::class);
                $addr = $addressServices->getUserDefaultAddressCache($uid);
            }
            if ($shipping_type == 3 && $is_store_delivery_type == 2 && $store_id) {
                $priceGroup = $computedServices->getOrderPriceShippingCost($uid, $store_id, $valid, $addr);
            } else {
                $storeFreePostage = floatval(sys_config('store_free_postage')) ?: 0;//满额包邮金额
                $priceGroup = $computedServices->getOrderPriceGroup($uid, $valid, $addr, $storeFreePostage);
            }

        }
        return [$payPrice, $priceGroup['payPostage'] ?? 0, $priceGroup['storePostageDiscount'] ?? 0];
    }


    /**
     * 计算实际支付金额
     * @param string $payPrice
     * @param string $firstOrderPrice
     * @return float
     */
    public function getPayPrice(string $payPrice, string $firstOrderPrice)
    {
        if ($firstOrderPrice < $payPrice) {//首单优惠金额
            $payPrice = bcsub((string)$payPrice, (string)$firstOrderPrice, 2);
        } else {
            $payPrice = 0;
        }
        return (float)$payPrice;
    }

}

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

namespace app\services\product\product;

use app\services\BaseServices;
use app\dao\product\product\StoreProductCouponDao;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\user\UserServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use think\exception\ValidateException;

/**
 * 商品关联优惠券
 * Class StoreProductCouponServices
 * @package app\services\coupon
 * @mixin StoreProductCouponDao
 */
class StoreProductCouponServices extends BaseServices
{

    /**
     * StoreProductCouponServices constructor.
     * @param StoreProductCouponDao $dao
     */
    public function __construct(StoreProductCouponDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 商品关联优惠券
     * @param int $id
     * @param array $coupon_ids
     * @return bool
     */
    public function setCoupon(int $id, array $coupon_ids)
    {
        $this->dao->delete(['product_id' => $id]);
        if ($coupon_ids) {
            $data = $data_all = [];
            $data['product_id'] = $id;
            $data['add_time'] = time();
            foreach ($coupon_ids as $cid) {
                if (!empty($cid) && (int)$cid) {
                    $data['issue_coupon_id'] = $cid;
                    $data_all[] = $data;
                }
            }
            $res = true;
            if ($data_all) {
                $res = $this->dao->saveAll($data_all);
            }
            if (!$res) throw new AdminException('关联优惠券失败！');
        }
        return true;
    }

	/**
	 * 根据商品ID获取商品营销设置赠送优惠券IDS
	 * @param array $productIds
	 * @return array
	 */
	public function getCouponIdsByProduct(array $productIds = [])
	{
		$couponIds = [];
		if ($productIds) {
			$pids = [];
			/** @var StoreBranchProductServices $productServices */
			$productServices = app()->make(StoreBranchProductServices::class);
			foreach ($productIds as $id) {
				$pids[] = $productServices->getStoreProductId($id);
			}
			$couponList = $this->dao->getProductCoupon($pids);
			if ($couponList) $couponIds = array_column($couponList, 'issue_coupon_id');
		}
		return $couponIds;
	}

	/**
	 * 获取下单赠送优惠券
	 * @param int $uid
	 * @param $oid
	 * @return mixed
	 */
    public function getOrderProductCoupon(int $uid, $oid)
    {
        $key = 'order_product_coupon_' . $uid . '_' . $oid;
        return CacheService::redisHandler()->get($key, []);
    }

	/**
	 * 下单赠送优惠劵
	 * @param int $uid
	 * @param int $orderId
	 * @return bool
	 */
    public function giveOrderProductCoupon(int $uid, int $orderId)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserInfo($uid);
        if (!$user) {
            throw new ValidateException('用户不存在');
        }
        /** @var StoreOrderServices $storeOrder */
        $storeOrder = app()->make(StoreOrderServices::class);
        $order = $storeOrder->getOne(['id' => $orderId]);
        if (!$order || $order['uid'] != $uid) {
            throw new ValidateException('订单不存在');
        }
        /** @var StoreOrderCartInfoServices $storeOrderCartInfo */
        $storeOrderCartInfo = app()->make(StoreOrderCartInfoServices::class);
        $productIds = $storeOrderCartInfo->getColumn(['oid' => $order['id'], 'cart_type' => 0], 'product_id');
        $list = [];
        if ($productIds) {
			$couponIds = $this->getCouponIdsByProduct($productIds);
            if ($couponIds) {
                /** @var StoreCouponIssueServices $storeCoupon */
                $storeCoupon = app()->make(StoreCouponIssueServices::class);
                $list = $storeCoupon->orderPayGiveCoupon($uid, $couponIds);
                if ($list) {
                    $ids = array_column($list, 'cid');
                    $coupons = $storeCoupon->getColumn(['id' => $ids], 'id,coupon_type', 'id');
                    foreach ($list as &$item) {
                        $item['coupon_type'] = $coupons[$item['cid']]['coupon_type'] ?? 1;
                        $item['add_time'] = date('Y-m-d', $item['add_time']);
                        $item['end_time'] = date('Y-m-d', $item['end_time']);
                    }
                }
            }
        }
        $key = 'order_product_coupon_' . $uid . '_' . $orderId;
        CacheService::redisHandler()->set($key, $list, 7200);
        return true;
    }
}

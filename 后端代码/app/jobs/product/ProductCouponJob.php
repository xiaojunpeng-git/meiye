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

namespace app\jobs\product;


use app\services\product\product\StoreProductCouponServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 商品关联优惠券
 * Class ProductCouponJob
 * @package app\jobs\product
 */
class ProductCouponJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param $orderInfo
     * @return bool
     */
    public function doJob($orderInfo)
    {
        if (!$orderInfo) return true;
        try {
            /** @var StoreProductCouponServices $storeProductCouponServices */
            $storeProductCouponServices = app()->make(StoreProductCouponServices::class);
            $storeProductCouponServices->giveOrderProductCoupon((int)$orderInfo['uid'], (int)$orderInfo['id']);
        } catch (\Throwable $e) {
            Log::error('赠送订单商品关联优惠券发生错误,错误原因:' . $e->getMessage());
        }
        return true;
    }

	/**
	 * 设置保存商品关联优惠券
	 * @param $id
	 * @param $coupon_ids
	 * @return bool|void
	 */
	public function setProductCoupon($id, $coupon_ids)
	{
		if (!$id) return true;
		try {
			/** @var StoreProductCouponServices $storeProductCouponServices */
			$storeProductCouponServices = app()->make(StoreProductCouponServices::class);
			$storeProductCouponServices->setCoupon($id, $coupon_ids);
		} catch (\Throwable $e) {
			response_log_write([
				'message' => '设置保存商品关联优惠券发生错误,错误原因:' . $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
		}
		return true;
	}

}

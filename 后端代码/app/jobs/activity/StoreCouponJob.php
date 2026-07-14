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

namespace app\jobs\activity;


use app\services\activity\coupon\StoreCouponIssueServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 营销：优惠券
 * Class StoreCouponJob
 * @package app\jobs\user
 */
class StoreCouponJob extends BaseJobs
{

    use QueueTrait;

	/**
	* 新人礼赠送优惠券
	* @param $uid
	* @return bool
	 */
	public function newcomerGiveCoupon($uid)
	{
		 try {
            /**@var StoreCouponIssueServices $storeCoupon */
            $storeCoupon = app()->make(StoreCouponIssueServices::class);
            $storeCoupon->newcomerGiveCoupon((int)$uid);
        } catch (\Throwable $e) {
            Log::error('赠送新人礼优惠券失败,失败原因:' . $e->getMessage());
        }
        return true;
	}

	/**
	* 会员卡激活赠送优惠券
	* @param $uid
	* @return bool
	 */
	public function levelGiveCoupon($uid)
	{
		 try {
            /**@var StoreCouponIssueServices $storeCoupon */
            $storeCoupon = app()->make(StoreCouponIssueServices::class);
            $storeCoupon->levelGiveCoupon((int)$uid);
        } catch (\Throwable $e) {
            Log::error('赠送新人礼优惠券失败,失败原因:' . $e->getMessage());
        }
        return true;
	}

    /**
     * 增加新人券
     * @param $uid
     * @return bool
     */
    public function newUserGiveCoupon($uid)
    {
        try {
            /**@var StoreCouponIssueServices $storeCoupon */
            $storeCoupon = app()->make(StoreCouponIssueServices::class);
            $storeCoupon->userFirstSubGiveCoupon((int)$uid);
        } catch (\Throwable $e) {
            Log::error('赠送新人券失败,失败原因:' . $e->getMessage());
        }
        return true;
    }

}

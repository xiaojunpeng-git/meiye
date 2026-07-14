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

namespace app\jobs\user;


use app\services\store\SystemStoreStaffServices;
use app\services\user\UserBelongStoreServices;
use app\services\user\UserVisitStoreServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

class UserBelongStoreJob extends BaseJobs
{

    use QueueTrait;

	/**
	 * 记录用户归属门店
	 * @param $uid
	 * @param $storeId
	 * @param $type
	 * @param $staffId
	 * @return bool
	 */
    public function doJob($uid, $storeId, $type = 'order', $staffId = 0)
    {
        try {
			//记录用户归属门店
			/** @var UserBelongStoreServices $userBelongStoreServices */
			$userBelongStoreServices = app()->make(UserBelongStoreServices::class);
			$userBelongStoreServices->setUserBelongStore((int)$uid, (int)$storeId, (string)$type, (int)$staffId);
        } catch (\Throwable $e) {

        }
        return true;
    }

    /**
     * 记录用户归属店员
     * @param $uid
     * @param $spread_uid
     * @param $type
     * @return bool
     */
    public function belongStoreStaff($uid, $spread_uid, $scene, $staff_id = 0, $store_id = 0)
    {
        try {
            if(!$spread_uid && !$staff_id && !$store_id) return true;
            if($spread_uid && !$staff_id && !$store_id) {
                /** @var SystemStoreStaffServices $staffServices */
                $staffServices = app()->make(SystemStoreStaffServices::class);
                $info = $staffServices->isStaff($spread_uid);
                if(!$info) return true;
                $staff_id = $info['id'];
                $store_id = $info['store_id'];
            }
            /** @var UserBelongStoreServices $belongServices */
            $belongServices = app()->make(UserBelongStoreServices::class);
            $belongServices->setUserBelongStoreStaff((int)$uid, (int)$staff_id, (int)$store_id, (int)$scene);
        } catch (\Throwable $e) {

        }
        return true;
    }

	/**
	 * 记录用户访问
	 * @param $uid
	 * @param $storeId
	 * @param $param
	 * @return bool
	 */
	public function setVisitStore($uid, $storeId, $param = [])
	{
		try {
			//记录用户访问门店
			/** @var UserVisitStoreServices $userVisitStoreServices */
			$userVisitStoreServices = app()->make(UserVisitStoreServices::class);
			$userVisitStoreServices->setVisitStore((int)$uid, (int)$storeId, (array)$param);
		} catch (\Throwable $e) {

		}
		return true;
	}

}

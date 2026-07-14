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

namespace app\services\user;

use app\dao\user\UserVisitStoreDao;
use app\services\BaseServices;
use app\dao\user\UserVisitDao;
use think\facade\Log;

/**
 * 用户访问门店
 * Class UserVisitStoreServices
 * @package app\services\user
 * @mixin UserVisitDao
 */
class UserVisitStoreServices extends BaseServices
{

    /**
     * UserVisitStoreServices constructor.
     * @param UserVisitStoreDao $dao
     */
    public function __construct(UserVisitStoreDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 记录访问门店日志
	 * @param int $uid
	 * @param int $store_id
	 * @param array $param
	 * @return bool
	 */
	public function setVisitStore(int $uid, int $store_id, array $param = [])
	{
		if (!$uid || !$store_id) {
			return false;
		}
		try {
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			$userInfo = $userServices->getUserInfo($uid);
			if (!$userInfo) {
				return false;
			}
			$data = [
				'uid' => $uid,
				'store_id' => $store_id,
				'ip' => $param['ip'] ?? '',
				'channel_type' => $userInfo['user_type'] ?? 'h5',
				'add_time' => time(),
			];
			$this->dao->save($data);
		} catch (\Throwable $e) {
			Log::error('记录访问门店日志错误，错误原因：' . $e->getMessage());
		}
		return true;
	}

	/**
	 * 获取用户最近访问的门店ID
	 * @param int $uid
	 * @param int $time
	 * @return mixed
	 */
	public function getUserNearVisitStore(int $uid, int $time = 90 * 24 * 3600)
	{
		return $this->dao->search(['uid' => $uid])->where('add_time', '>', time() - $time)->order('add_time desc')->value('store_id');
	}



}

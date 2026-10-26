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


use app\services\user\label\UserLabelServices;
use app\services\work\WorkGroupChatAuthServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 用户标签
 * Class UserLabelJob
 * @package app\jobs\user
 */
class UserLabelJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 同步后台标签到企业微信客户后台
     * @param $cateId
     * @param $groupName
     * @return bool
     */
	public function authLabel($cateId, $groupName)
	{
		try{
			/** @var UserLabelServices $make */
			$make = app()->make(UserLabelServices::class);
			$make->addCorpClientLabel($cateId, $groupName);
		} catch (\Throwable $e) {
			\think\facade\Log::error('同步后台标签到企业微信客户后台,失败原因:'.$e->getMessage());
		}
		return true;
	}

	/**
	 * 同步企业微信客户标签到平台
	 * @return bool
	 */
	public function authWorkLabel()
	{
		try {
			/** @var UserLabelServices $make */
			$make = app()->make(UserLabelServices::class);
			$make->authWorkLabel();
		} catch (\Throwable $e) {
			\think\facade\Log::error('同步企业微信客户标签到平台,失败原因:'.$e->getMessage());
		}
		return true;
	}

	/**
	 * 编辑客户标签
	 * @param $userid
	 * @param $externalUserID
	 * @param $groupAuthId
	 * @return bool
	 */
    public function clientAddLabel($userid, $externalUserID, $groupAuthId)
    {
		try {
			/** @var WorkGroupChatAuthServices $chatAuthService */
			$chatAuthService = app()->make(WorkGroupChatAuthServices::class);
			$chatAuthService->clientAddLabel((int)$groupAuthId, $userid, $externalUserID);
		} catch (\Throwable $e) {
			\think\facade\Log::error('编辑客户标签,失败原因:'.$e->getMessage());
		}
		return true;
    }

}

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


use app\services\user\UserServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * Class UserUpdateJob
 * @package app\jobs\user
 */
class UserUpdateJob extends BaseJobs
{

    use QueueTrait;


    /**
     * @param $uid
     * @param $realName
     * @param $userPhone
     * @return bool
     */
    public function updateRealName($uid, $realName, $userPhone)
    {
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
		$userInfo = $userService->getUserCacheInfo((int)$uid);
		if($userInfo['real_name'] != $realName || $userInfo['record_phone'] != $userPhone) {
			$userService->update(['uid' => $uid], ['real_name' => $realName, 'record_phone' => $userPhone]);
		}
        return true;
    }

}

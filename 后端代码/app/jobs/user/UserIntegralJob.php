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


use app\services\user\UserIntegralServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 清除到期积分
 * Class UserIntegralJob
 * @package app\jobs
 */
class UserIntegralJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 执行清除到期积分
     * @param array $uids 待清算会员
     * @param array $period 调度时固定的清算时间窗口
     * @return bool
     */
    public function doJob($uids, $period = [])
    {
        if (!$uids || !is_array($uids)) {
            return true;
        }
        try {
            if (!is_array($period) || !$period) {
                throw new \InvalidArgumentException('积分到期任务缺少固定清算窗口');
            }
            // BaseJobs 仅在 doJob 返回 false 时重试；直接抛出会被其 fire() 删除任务。
            /** @var UserIntegralServices $userIntegralServices */
            $userIntegralServices = app()->make(UserIntegralServices::class);
            return $userIntegralServices->doClearExpireIntegral($uids, $period);
        } catch (\Throwable $e) {
            Log::error('清除用户到期积分失败，等待队列重试，原因：' . $e->getMessage());
            return false;
        }
    }

	/**
 	* 赠送新人礼积分
	* @param $uid
	* @return bool
	 */
	public function newcomerGiveIntegral($uid)
	{
		try {
			/** @var UserIntegralServices $userIntegralServices */
            $userIntegralServices = app()->make(UserIntegralServices::class);
            $userIntegralServices->newcomerGiveIntegral((int)$uid);
        } catch (\Throwable $e) {
            Log::error('赠送新人礼积分失败,失败原因:' . $e->getMessage());
        }
        return true;
	}

	/**
 	* 会员卡激活赠送积分
	* @param $uid
	* @return bool
	 */
	public function levelGiveIntegral($uid)
	{
		try {
			/** @var UserIntegralServices $userIntegralServices */
            $userIntegralServices = app()->make(UserIntegralServices::class);
            $userIntegralServices->levelGiveIntegral((int)$uid);
        } catch (\Throwable $e) {
            Log::error('会员卡激活赠送积分失败,失败原因:' . $e->getMessage());
        }
        return true;
	}
}

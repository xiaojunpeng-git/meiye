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

use mohe\basic\BaseJobs;
use app\services\user\UserIntegralServices;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 自动清空用户积分
 * Class AutoClearIntegralJob
 * @package app\jobs\user
 */
class AutoClearIntegralJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return string
     */
    protected static function queueName()
    {
        return 'MOHE_PRO_TASK';
    }

    public function doJob()
    {
        //清空积分
        try {
            /** @var UserIntegralServices $userIntegralServices */
            $userIntegralServices = app()->make(UserIntegralServices::class);
            [$clear_time, $start_time, $end_time] = $userIntegralServices->getTime();
            //到清空积分的最后一天
            if ($clear_time == strtotime(date('Y-m-d', time()))) {
                $userIntegralServices->clearExpireIntegral();
            }
        } catch (\Throwable $e) {
            Log::error('清空积分,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
        }
		return true;

    }
}

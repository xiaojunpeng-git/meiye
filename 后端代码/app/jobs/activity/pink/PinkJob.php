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

namespace app\jobs\activity\pink;


use app\services\activity\combination\StorePinkServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 拼团失败
 * Class PinkJob
 * @package app\jobs
 */
class PinkJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($pinkId)
    {
        try {
            /** @var StorePinkServices $pinkService */
            $pinkService = app()->make(StorePinkServices::class);
            $info = $pinkService->get((int)$pinkId);
			if (!$info) {
				return true;
			}
			//已经成功 || 失败处理
			if (in_array($info['status'], [2, 3])) {
				return true;
			}
			[$pinkAll, $pinkT, $count, $idAll, $uidAll] = $pinkService->getPinkMemberAndPinkK($info);
			$pinkService->pinkFail($pinkAll, $pinkT, 0);

        } catch (\Throwable $e) {
            Log::error('拼团超时处理失败，原因：' . $e->getMessage());
        }
        return true;
    }

    /**
     * 创建拼团
     * @param $orderInfo
     * @return bool
     */
    public function createPink($orderInfo)
    {
        if (!$orderInfo) {
            return true;
        }
        try {
            /** @var StorePinkServices $pinkServices */
            $pinkServices = app()->make(StorePinkServices::class);
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $resPink = $pinkServices->createPink($orderServices->tidyOrder($orderInfo, true));//创建拼团
        } catch (\Throwable $e) {
            Log::error('创建拼团失败失败，原因：' . $e->getMessage());
        }
        return true;
    }
}

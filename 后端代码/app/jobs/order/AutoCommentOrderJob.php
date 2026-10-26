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

namespace app\jobs\order;


use app\services\order\StoreOrderCommentServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 自动执行默认好评
 * Class AutoCommentOrderJob
 * @package app\jobs\order
 */
class AutoCommentOrderJob extends BaseJobs
{

    use QueueTrait;

    /**
     * @return string
     */
    protected static function queueName()
    {
        return 'MOHE_PRO_TASK';
    }

    /**
     * @param $where
     * @param $page
     * @param $limit
     * @return bool
     */
    public function doJob($where, $page, $limit)
    {
		try {
			/** @var StoreOrderCommentServices $service */
			$service = app()->make(StoreOrderCommentServices::class);
			$service->runAutoCommentOrder($where, $page, $limit);
		} catch (\Throwable $e) {
			Log::error('自动默认好评,失败原因:[' . class_basename($this) . ']' . $e->getMessage());
		}
		return true;
    }
}

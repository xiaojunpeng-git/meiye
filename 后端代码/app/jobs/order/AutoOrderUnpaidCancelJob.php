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


use app\services\order\StoreOrderServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 自动取消未支付订单
 * Class AutoOrderUnpaidCancelJob
 * @package app\jobs\order
 */
class AutoOrderUnpaidCancelJob extends BaseJobs
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
     * @param $page
     * @param $limit
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function doJob($page, $limit)
    {
		try {
			/** @var StoreOrderServices $service */
			$service = app()->make(StoreOrderServices::class);
			$service->runOrderUnpaidCancel((int)$page, (int)$limit);
		} catch (\Throwable $e) {
			response_log_write([
				'message' => '自动取消未支付订单,失败原因:[' . class_basename($this) . ']' . $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
		}
		return true;
    }

}

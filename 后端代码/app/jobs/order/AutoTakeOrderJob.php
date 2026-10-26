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


use app\services\order\StoreOrderTakeServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 自动执行确认收货
 * Class AutoTakeOrderJob
 * @package app\jobs\order
 */
class AutoTakeOrderJob extends BaseJobs
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
			/** @var StoreOrderTakeServices $service */
			$service = app()->make(StoreOrderTakeServices::class);
			$service->runAutoTakeOrder($where, $page, $limit);
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

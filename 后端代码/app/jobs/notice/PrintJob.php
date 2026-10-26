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

namespace app\jobs\notice;


use app\services\activity\collage\UserCollageCodeServices;
use app\services\order\StoreOrderServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;


/**
 * 小票打印
 * Class PrintJob
 * @package app\jobs\notice
 */
class PrintJob extends BaseJobs
{
    use QueueTrait;

	/**
	 * 小票打印
	 * @param $id
	 * @param $printEvent 1：下单后2：支付后
	 * @return bool
	 */
    public function doJob($id, $printEvent = -1)
    {
//        try {
//			if (!$id) {
//				return true;
//			}
//			/** @var StoreOrderServices $orderServices */
//			$orderServices = app()->make(StoreOrderServices::class);
//			$orderServices->orderPrint((int)$id, -1, -1, (int)$printEvent);
//        } catch (\Throwable $e) {
//            Log::error('小票打印失败,失败原因:' . $e->getMessage());
//        }
		return true;
    }

	/**
	 * 桌码流水小票打印
	 * @param $tableId
	 * @param $store_id
	 * @return bool
	 */
    public function tableDoJob($tableId, $store_id, $print_event = -1)
    {
        try {
			if (!(int)$tableId || !(int)$store_id) {
				return true;
			}
			/** @var UserCollageCodeServices $collageServices */
            $collageServices = app()->make(UserCollageCodeServices::class);
			$collageServices->tablePrint((int)$tableId, (int)$store_id, (int)$print_event);
        } catch (\Throwable $e) {
            Log::error('桌码流水小票打印失败,失败原因:' . $e->getMessage());
        }
		return true;
    }


}

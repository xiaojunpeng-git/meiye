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

namespace app\jobs\store;


use app\services\store\finance\StaffFlowingWaterServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 店员流水记录
 * Class StaffFinanceJob
 * @package app\jobs
 */
class StaffFinanceJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 店员流水
     * @param array $order
     * @param int $type
     * @param int $price
     * @return bool
     */
    public function doJob(array $order, int $type, $price = 0)
    {
        try {
            /** @var StaffFlowingWaterServices $staffFinanceFlowServices */
            $staffFinanceFlowServices = app()->make(StaffFlowingWaterServices::class);
            $staffFinanceFlowServices->setFinance($order, $type, $price);
        } catch (\Throwable $e) {
            Log::error('记录店员流水失败:' . $e->getMessage());
        }
        return true;
    }
}

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

namespace app\jobs\supplier;


use app\services\supplier\finance\SupplierFlowingWaterServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 供应商资金流水记录
 * Class SupplierFinanceJob
 * @package app\jobs
 */
class SupplierFinanceJob extends BaseJobs
{
    use QueueTrait;

    /**供应商流水
     * @param int $oid
     * @param int $type
     * @return bool
     */
    public function doJob(int $oid, int $type)
    {
        try {
            /** @var SupplierFlowingWaterServices $supplierFlowServices */
            $supplierFlowServices = app()->make(SupplierFlowingWaterServices::class);
            $supplierFlowServices->setSupplierFinance($oid, $type);
        } catch (\Throwable $e) {
            Log::error('记录供应商流水失败:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 更新完成时间
     * @param array $order
     * @param int $take_time
     * @return bool
     */
    public function takeDoJob(array $order, int $take_time)
    {
        try {
            /** @var SupplierFlowingWaterServices $supplierFlowServices */
            $supplierFlowServices = app()->make(SupplierFlowingWaterServices::class);
            $supplierFlowServices->addFinanceTakeTime($order, $take_time);
        } catch (\Throwable $e) {
            Log::error('更新完成时间失败:' . $e->getMessage());
        }
        return true;
    }
}

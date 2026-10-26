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


use app\services\store\finance\StoreFinanceFlowServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 门店资金流水记录
 * Class StoreFinanceJob
 * @package app\jobs
 */
class StoreFinanceJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 门店流水
     * @param array $order
     * @param int $type
     * @param float $price
     * @return bool
     */
    public function doJob(array $order, int $type, float $price = 0.00)
    {
        try {
            /** @var StoreFinanceFlowServices $storeFinanceFlowServices */
            $storeFinanceFlowServices = app()->make(StoreFinanceFlowServices::class);
            $storeFinanceFlowServices->setFinance($order, $type, $price);
        } catch (\Throwable $e) {
            Log::error('记录门店流水失败:' . $e->getMessage());
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
            /** @var StoreFinanceFlowServices $storeFinanceFlowServices */
            $storeFinanceFlowServices = app()->make(StoreFinanceFlowServices::class);
            $storeFinanceFlowServices->addFinanceTakeTime($order, $take_time);
        } catch (\Throwable $e) {
            Log::error('更新完成时间失败:' . $e->getMessage());
        }
        return true;
    }
}

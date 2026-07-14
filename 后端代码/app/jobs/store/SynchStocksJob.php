<?php


namespace app\jobs\store;

use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 门店同步库存队列（已停用）
 * Class SynchStocksJob
 * @package app\jobs\store
 */
class SynchStocksJob extends BaseJobs
{
    use QueueTrait;

    public function doJob($ids, $storeId)
    {
        // 【库存铁律】门店同步平台库存已停用；即使队列里仍有旧任务也不再写库存
        Log::warning('SynchStocksJob 已停用，忽略执行 ids=' . json_encode($ids) . ' storeId=' . $storeId);
        return true;
        // try {
        //     $services = app()->make(\app\services\product\branch\StoreBranchProductServices::class);
        //     $services->synchStocks($ids, (int)$storeId);
        // } catch (\Throwable $e) {
        // }
        // return true;
    }
}

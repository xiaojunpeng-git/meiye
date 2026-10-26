<?php

namespace app\jobs\query;

use app\services\query\UnifiedQueryExportWorkerServices;
use app\services\query\UnifiedQueryRuntime;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 每个统一查询导出任务独立进入任务队列。
 *
 * 任务本身以数据库 status + lease 领取，重复投递只会有一个 worker 实际执行。
 */
class UnifiedQueryExportJob extends BaseJobs
{
    use QueueTrait;

    public static function queueName()
    {
        return (string)config('queue.connections.' . config('queue.default') . '.queue');
    }

    public function doJob(string $taskNo): bool
    {
        if (!preg_match('/^uqe_[a-f0-9]{32}$/D', $taskNo)) {
            return true;
        }

        $runtime = UnifiedQueryRuntime::runtime();
        $worker = new UnifiedQueryExportWorkerServices(
            $runtime['exports'],
            $runtime['providers'],
            $runtime['workerContextResolvers']
        );
        $worker->processPending(1, $taskNo);
        return true;
    }
}

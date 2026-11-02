<?php
declare(strict_types=1);

namespace app\jobs\report;

use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/** 后台生成不能在 HTTP 超时后回退到仅前 10,000 行的报表结果。 */
final class BasicUnconsumedTaskJob extends BaseJobs
{
    use QueueTrait;

    public static function queueName() { return \app\services\report\BasicUnconsumedTaskQueue::name(); }

    public function doJob(string $taskId): bool
    {
        return (new \app\services\report\BasicUnconsumedTaskServices())->process($taskId);
    }
}

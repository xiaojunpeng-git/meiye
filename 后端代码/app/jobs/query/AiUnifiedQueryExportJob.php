<?php
namespace app\jobs\query;

use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/** Dedicated queue: a task number is the entire message, never credentials/chat/business payloads. */
final class AiUnifiedQueryExportJob extends BaseJobs
{
    use QueueTrait;
    public static function queueName()
    { return \app\services\ai\execution\AiExportQueue::name(\app\services\ai\execution\AiRuntimeFactory::make()['instance']); }
    public function doJob(string $taskNo): bool
    {
        if (!preg_match('/^uqe_[a-f0-9]{32}$/D',$taskNo)) { return true; }
        // Explicit factory supplies current instance, Run fence, current permissions and read-view provider.
        // Never fall back to the ordinary runtime or default queue on configuration errors.
        return \app\services\ai\execution\AiExportRuntime::process($taskNo);
    }
}

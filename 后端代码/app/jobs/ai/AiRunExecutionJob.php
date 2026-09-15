<?php
namespace app\jobs\ai;

use mohe\basic\BaseJobs;

/** Queue payload is exactly one opaque Run id. */
final class AiRunExecutionJob extends BaseJobs
{
    // Do not add QueueTrait: all dispatch must pass through
    // AiRunExecutionQueue::push(), which supplies the dedicated queue name.
    public function doJob(string $runId): bool
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$runId)) return true;
        return \app\services\ai\execution\AiRunExecutionRuntime::process($runId);
    }
}

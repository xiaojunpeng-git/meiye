<?php
namespace app\services\ai\execution;

use app\jobs\ai\AiRunExecutionJob;

/**
 * AI work has a per-instance dedicated queue.  Its message contains only a
 * random Run id; the encrypted request is resolved from instance-private
 * storage by the dedicated worker.
 */
final class AiRunExecutionQueue
{
    /** Queue identity is per customer instance; ordinary jobs never consume AI work. */
    public static function name(string $instance): string
    {
        if (!preg_match('/^instance-[a-f0-9]{64}$/D',$instance)) throw new \RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        return 'MOHE_PRO_AI_EXECUTION:'.substr(hash('sha256',$instance),0,32);
    }

    public static function supported(): bool
    {
        $name=(string)config('queue.default','');
        return $name!=='' && config('queue.connections.'.$name.'.type')==='redis';
    }

    public static function push(string $instance,string $runId): void
    {
        if (!self::supported() || !preg_match('/^[a-f0-9]{48}$/D',$runId)) throw new \RuntimeException('AI_EXECUTION_DISPATCH_UNAVAILABLE');
        try {
            // Do not use QueueTrait's default-queue fallback here. The raw
            // queue message contains exactly one opaque Run id.
            $result=\think\facade\Queue::connection()->push(AiRunExecutionJob::class,
                ['do'=>'doJob','data'=>[$runId],'errorCount'=>3],self::name($instance));
        }
        catch (\Throwable $error) { throw new \RuntimeException('AI_EXECUTION_DISPATCH_UNKNOWN',0,$error); }
        if (!$result) throw new \RuntimeException('AI_EXECUTION_DISPATCH_UNKNOWN');
    }
}

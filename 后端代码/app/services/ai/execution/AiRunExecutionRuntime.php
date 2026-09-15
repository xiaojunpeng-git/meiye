<?php
namespace app\services\ai\execution;

use app\services\ai\AiGatewayServices;

/**
 * Trusted queue boundary.  It rebuilds a fresh permission context instead of
 * serializing HTTP sessions, callbacks or data scope into a job payload.
 */
final class AiRunExecutionRuntime
{
    public static function process(string $runId): bool
    {
        $runtime=AiRuntimeFactory::make();
        $job=$runtime['runs']->queuedExecution($runId);
        if ($job===null) return true; // duplicate, cancelled, expired or already claimed
        $workerId=self::workerId(); $registered=false;
        $enteredGateway=false; $retireEnvelope=false;
        try {
            $runtime['runs']->startQueuedExecutionWorker($runId,$workerId,getmypid(),self::host()); $registered=true;
            $payload=$runtime['private']->read($job['request_ref']);
            if (!is_array($payload) || ($payload['run_id']??null)!==$job['run_id'] || ($payload['generation']??null)!==$job['generation']
                || ($payload['operation']??null)!==$job['operation'] || !is_array($payload['owner']??null)
                || !is_array($payload['binding']??null) || !is_array($payload['input']??null)
                || !hash_equals($job['request_hash'],AiExecutionEnvelope::hash($job['operation'],$payload['input'],$runtime['private']->signingKey()))) {
                throw new \RuntimeException('AI_EXECUTION_REQUEST_CORRUPT');
            }
            $owner=$job['owner'];
            if ($payload['owner']!==$owner) throw new \RuntimeException('AI_EXECUTION_OWNER_MISMATCH');
            $resolver=new AiTrustedPrincipalResolver(); $binding=$payload['binding'];
            $context=$resolver->worker($binding);
            $context['_refresh']=static function () use($resolver,$binding): array { return $resolver->worker($binding); };
            $gateway=new AiGatewayServices($runtime['runs'],$runtime['config'],$runtime['private'],$runtime['instance'],$runtime['views'],null,null,$runtime['management']);
            $enteredGateway=true;
            $result=$gateway->executeQueued($context,$owner,$job['run_id'],$job['generation'],$job['operation'],$payload['input']);
            $retireEnvelope=in_array($result['status']??'',['COMPLETED','PARTIAL_SUCCEEDED','FAILED','CANCELLED','WAITING_CLARIFICATION','WAITING_EXPORT'],true);
            return true;
        } catch (\Throwable $error) {
            // A Run that reached the gateway must never be replayed from this
            // catch path: the gateway may already have admitted a model call.
            // It owns its own claimed-run failure handling.  Before entering
            // the gateway, returning the envelope to QUEUED is safe because
            // no worker token (and therefore no provider admission) exists.
            try {
                if ($enteredGateway) {
                    $runtime['runs']->failQueuedExecution($runId,'AI_EXECUTION_RUNTIME_FAILED');
                    // execute() only propagates before it owns a worker token.
                    // The terminal pre-claim Run no longer needs its input.
                    $retireEnvelope=true;
                } elseif (in_array($error->getMessage(),['AI_EXECUTION_REQUEST_CORRUPT','AI_EXECUTION_OWNER_MISMATCH','AI_EXPORT_PRINCIPAL_UNAVAILABLE','AI_PERMISSION_REVOKED','AI_AUTH_REQUIRED'],true)) {
                    $runtime['runs']->failQueuedExecution($runId,'AI_AUTHORIZATION_CHANGED');
                    $retireEnvelope=true;
                } else $runtime['runs']->restoreQueuedExecution($runId);
            } catch (\Throwable $ignored) {}
            return true;
        } finally {
            // A normal return, a handled error, or a clarification pause has
            // stopped this PHP job.  An abrupt process death skips finally and
            // remains visible for the supervisor's PID-proof recovery path.
            if ($registered) try { $runtime['runs']->finishQueuedExecutionWorker($runId,$workerId); } catch (\Throwable $ignored) {}
            if ($retireEnvelope) try { $runtime['private']->discard($job['request_ref']); } catch (\Throwable $ignored) {}
        }
    }

    /** Called by the independent supervisor; never guesses across hosts. */
    public static function recoverStoppedWorkers(AiRunStore $runs): int
    {
        $settings=function_exists('config')?(array)config('mohe_ai.execution',[]):[];
        $stale=(int)($settings['consumer_stale_seconds']??210);
        // Recovery also runs while an instance is in staged compatibility
        // mode.  Invalid optional configuration must not cause an exception
        // or reclaim a worker too aggressively.
        if ($stale<30 || $stale>300) $stale=210;
        $local=self::host();
        return $runs->recoverStoppedExecutionWorkers($stale,static function(string $host,int $pid) use($local): bool {
            if ($host!==$local || !function_exists('posix_kill')) return false;
            if (@posix_kill($pid,0)) return false;
            // EPERM means the PID may still exist but is owned by another
            // account. Only ESRCH proves that this exact process is gone.
            return function_exists('posix_get_last_error') && posix_get_last_error()===3;
        });
    }

    private static function host(): string
    {
        $host=(string)php_uname('n');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$host)) return 'host-'.substr(hash('sha256',$host),0,32);
        return $host;
    }

    private static function workerId(): string
    {
        $value=(string)getenv('MOHE_AI_EXECUTION_WORKER_ID');
        if (preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$value)) return $value;
        return 'job-'.getmypid().'-'.substr(hash('sha256',self::host().':'.getmypid()),0,24);
    }
}

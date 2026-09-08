<?php
namespace app\services\ai\execution;

use app\jobs\query\AiUnifiedQueryExportJob;
use app\services\query\UnifiedQueryExportTaskServices;
use think\facade\Db;

/** Only this instance's dedicated queue; no auto-resend or generic argument logger. */
final class AiExportQueue
{
    public static function name(string $instance): string { return 'MOHE_PRO_AI_EXPORT:'.substr(hash('sha256',$instance),0,32); }
    public static function supported(): bool
    {
        $name=(string)config('queue.default','');
        return $name!=='' && config('queue.connections.'.$name.'.type')==='redis';
    }
    public static function push(string $instance,string $taskNo): void
    {
        if (!self::supported() || !preg_match('/^uqe_[a-f0-9]{32}$/D',$taskNo)) throw new \RuntimeException('AI_EXPORT_QUEUE_INVALID');
        try {
            $result=\think\facade\Queue::connection()->push(AiUnifiedQueryExportJob::class,
                ['do'=>'doJob','data'=>[$taskNo],'errorCount'=>3],self::name($instance));
        } catch (\Throwable $e) { throw new \RuntimeException('AI_EXPORT_DISPATCH_UNKNOWN'); }
        if (!$result) throw new \RuntimeException('AI_EXPORT_DISPATCH_UNKNOWN');
    }
    public static function cleanup(string $instance): array
    {
        if (!self::supported() || !class_exists('Redis')) throw new \RuntimeException('AI_EXPORT_QUEUE_CLEANUP_UNAVAILABLE');
        $name=(string)config('queue.default'); $cfg=(array)config('queue.connections.'.$name,[]);
        $redis=new \Redis();
        if (!$redis->connect($cfg['host'],(int)$cfg['port'],2.0)) throw new \RuntimeException('AI_EXPORT_QUEUE_CLEANUP_UNAVAILABLE');
        try {
            $redis->setOption(\Redis::OPT_READ_TIMEOUT,2);
            if (($cfg['password']??'')!=='' && !$redis->auth($cfg['password'])) throw new \RuntimeException('AI_EXPORT_QUEUE_CLEANUP_UNAVAILABLE');
            if (!$redis->select((int)($cfg['select']??0))) throw new \RuntimeException('AI_EXPORT_QUEUE_CLEANUP_UNAVAILABLE');
            return self::cleanupMessages($redis,$instance,function ($taskNo) {
                return Db::name(UnifiedQueryExportTaskServices::TABLE)->where('task_no',$taskNo)->field('source_type,expires_at')->find();
            },time());
        } finally { $redis->close(); }
    }
    /** Trusted composition seam; bounded cleanup of this dedicated instance queue only. */
    public static function cleanupMessages($redis,string $instance,callable $lookup,int $now): array
    {
            $base='{queues:'.self::name($instance).'}'; $removed=0; $invalid=0;
            foreach ([$base=>'list',$base.':delayed'=>'zset',$base.':reserved'=>'zset'] as $key=>$type) {
                $payloads=$type==='list'?$redis->lRange($key,0,499):$redis->zRange($key,0,499);
                foreach ((array)$payloads as $payload) {
                    $body=json_decode($payload,true); $args=$body['data']['data']??null;
                    $valid=($body['job']??null)===AiUnifiedQueryExportJob::class && is_array($args) && count($args)===1 && is_string($args[0]??null) && preg_match('/^uqe_[a-f0-9]{32}$/D',$args[0]);
                    if (!$valid) ++$invalid;
                    $task=$valid?$lookup($args[0]):null;
                    if (!$valid || !$task || ($task['source_type']??null)!=='AI' || (int)$task['expires_at']<=$now) {
                        $removed+=(int)($type==='list'?$redis->lRem($key,$payload,0):$redis->zRem($key,$payload));
                    }
                }
            }
            return ['removed_messages'=>$removed,'invalid_messages'=>$invalid];
    }
}

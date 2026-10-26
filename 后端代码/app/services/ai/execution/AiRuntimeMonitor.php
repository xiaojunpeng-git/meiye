<?php
declare(strict_types=1);
namespace app\services\ai\execution;

/** Aggregate-only administrator and supervisor alerts; no subject, Run, payload or external notification sink. */
final class AiRuntimeMonitor
{
    private $profile; private $directory; private $clock;
    public function __construct(array $profile,string $privateDirectory,?callable $clock=null)
    {
        if ($privateDirectory==='' || $privateDirectory[0]!=='/' || strpos($privateDirectory,"\0")!==false) throw new \RuntimeException('AI_MONITOR_STORAGE_INVALID');
        $this->profile=$profile; $this->directory=rtrim($privateDirectory,'/'); $this->clock=$clock?:function(){return time();};
    }
    public function recordCleanup(bool $ok): void
    {
        $now=($this->clock)();
        $this->health(function($file)use($now,$ok){
            $body=json_encode(['checked_at'=>$now,'expires_at'=>$now+86400,'ok'=>$ok]);
            if(!ftruncate($file,0)||!rewind($file)||fwrite($file,$body)!==strlen($body)||!fflush($file))throw new \RuntimeException('AI_MONITOR_STORAGE_UNAVAILABLE');
        });
    }
    public function evaluate(array $stats): array
    {
        $now=($this->clock)(); $health=['status'=>'unknown'];
        try {
            $health=$this->health(function($file)use($now){
                $body=json_decode(stream_get_contents($file),true);
                if(!is_array($body) || !is_int($body['checked_at']??null) || !is_int($body['expires_at']??null) || !is_bool($body['ok']??null)) return ['status'=>'unknown'];
                if($body['expires_at']<=$now || $body['checked_at']>$now || $body['expires_at']>$body['checked_at']+86400) { ftruncate($file,0); fflush($file); return ['status'=>'expired']; }
                return ['status'=>$body['ok']?'ok':'failed','checked_at'=>$body['checked_at']];
            });
        }catch(\Throwable $ignored){$health=['status'=>'unavailable'];}
        $alerts=[];
        if(in_array($health['status'],['failed','unavailable'],true)) $alerts[]=['code'=>'CLEANUP_FAILED','severity'=>'error'];
        $p=$this->profile;
        $ready=self::profileReady($p);
        if(!$ready)return ['status'=>'not_ready','alerts'=>$alerts,'cleanup_health'=>$health,'reason'=>'MONITOR_PROFILE_NOT_REGISTERED'];
        if(($stats['capacity']??0)+($stats['capacity_rejected']??0)>=$p['capacity_count'])$alerts[]=['code'=>'CAPACITY_PRESSURE','severity'=>'warning'];
        if(($stats['technical']??0)>=$p['technical_count'])$alerts[]=['code'=>'QUERY_TECHNICAL_FAILURES','severity'=>'error'];
        if(($stats['security']??0)>=$p['security_count'])$alerts[]=['code'=>'SECURITY_INTEGRITY_FAILURES','severity'=>'error'];
        if(($stats['export_unknown']??0)+($stats['usage']['unknown_attempts']??0)>=$p['unknown_count'])$alerts[]=['code'=>'EXECUTION_OUTCOME_UNKNOWN','severity'=>'error'];
        $exports=$stats['exports']??[];
        if((($exports['eligible']??0)>=$p['export_min_samples'] && ($exports['failure_rate']??0)>=$p['export_failure_rate']) || ($exports['consecutive_failed']??0)>=$p['export_consecutive_failures'])$alerts[]=['code'=>'EXPORT_FILE_FAILURES','severity'=>'error'];
        // Independent Excel completes after the answer, so its task outcomes
        // cannot be inferred from the old Run-level export-delivery counters.
        $tasks=$stats['task_exports']??[];
        if (in_array($tasks['status']??null,['unavailable','not_installed'],true)) $alerts[]=['code'=>'EXPORT_TASK_MONITOR_UNAVAILABLE','severity'=>'error'];
        if ((($tasks['eligible']??0)>=$p['export_min_samples'] && ($tasks['failure_rate']??0)>$p['export_failure_rate'])
            || ($tasks['consecutive_failed']??0)>=$p['export_consecutive_failures']) $alerts[]=['code'=>'EXPORT_TASK_FAILURES','severity'=>'error'];
        if(($stats['duration']['max_ms']??0)>=$p['duration_ms'])$alerts[]=['code'=>'RUN_DURATION_HIGH','severity'=>'warning'];
        if(!isset($health['checked_at']) || $now-$health['checked_at']>$p['cleanup_stale_seconds'])$alerts[]=['code'=>'CLEANUP_NOT_CONFIRMED','severity'=>'error'];
        return ['status'=>$alerts?'alert':'ok','alerts'=>$alerts,'cleanup_health'=>$health,'window_seconds'=>86400];
    }
    public static function profileReady(array $profile): bool
    {
        if (($profile['registered']??null)!==true) return false;
        foreach(['capacity_count','export_min_samples','export_consecutive_failures','security_count','unknown_count','technical_count','cleanup_stale_seconds','duration_ms'] as $key) {
            if (!is_int($profile[$key]??null) || $profile[$key]<1) return false;
        }
        $rate=$profile['export_failure_rate']??null;
        return (is_float($rate)||is_int($rate)) && $rate>0 && $rate<=1;
    }
    private function health(callable $operation)
    {
        if(is_link($this->directory))throw new \RuntimeException('AI_MONITOR_STORAGE_INVALID');
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new \RuntimeException('AI_MONITOR_STORAGE_UNAVAILABLE');
        $path=$this->directory.'/cleanup-health.json';
        if(is_link($path))throw new \RuntimeException('AI_MONITOR_STORAGE_INVALID');
        $old=umask(0077); $file=fopen($path,'c+b'); umask($old);
        if(!$file)throw new \RuntimeException('AI_MONITOR_STORAGE_UNAVAILABLE');
        try { if(!flock($file,LOCK_EX))throw new \RuntimeException('AI_MONITOR_STORAGE_UNAVAILABLE'); return $operation($file); }
        finally {flock($file,LOCK_UN);fclose($file);}
    }
}

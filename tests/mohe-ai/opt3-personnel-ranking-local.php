<?php
/** Explicit local, read-only profiling of one registered ranking's frozen
 * detail profile. This creates no Run, calls no model, and changes no source,
 * budget or business fact. SQL text, bindings and business identities never
 * leave the process. The process requires the known local DB and idle slots.
 * This reconstructs the registered shape; failed model payloads are not saved
 * and are therefore not claimed to have been recovered by this fixture. */
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$mode=$argv[1]??'uncached';$period=$argv[2]??'current';
if (!in_array($mode,['uncached','cached'],true)||!in_array($period,['current','previous'],true)) throw new RuntimeException('PROFILE_MODE_INVALID');
$db=(array)config('database.connections.'.config('database.default'));
$server=think\facade\Db::query('SELECT @@server_uuid uuid,DATABASE() db')[0]??[];
if (($db['hostname']??null)!=='mysql'||($db['database']??null)!=='ruihao'
    ||($server['uuid']??null)!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a'
    ||($server['db']??null)!=='ruihao') throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
$instance='instance-'.hash('sha256',json_encode([(string)config('mohe_ai.instance_id',''),$server['uuid'],$server['db']]));
if (think\facade\Db::name('mohe_ai_run')->where('instance_id',$instance)->where('slot_held',1)->count()) throw new RuntimeException('LOCAL_AI_BUSY');
$run=think\facade\Db::name('mohe_ai_run')->where('instance_id',$instance)
    ->where('run_id','3a43ef2c17bdadcbcf3798562b768db2e0a06316e1a126e7')->find();
if (!$run||$run['status']!=='FAILED') throw new RuntimeException('LOCAL_REFERENCE_RUN_REQUIRED');
$account=(int)$run['account_id'];$start=microtime(true);$phase='authority';$sql=[];$events=[];$authorityMs=0;$authorityCalls=0;$checkpoints=0;
think\facade\Db::listen(static function($statement,$seconds)use(&$sql,&$phase):void {
    $kind=preg_match('/^\s*(SELECT|SET|SHOW)\b/i',$statement,$match)?strtoupper($match[1]):'OTHER';
    $sql[$phase][$kind]['calls']=($sql[$phase][$kind]['calls']??0)+1;
    $sql[$phase][$kind]['ms']=round(($sql[$phase][$kind]['ms']??0)+(float)$seconds*1000,3);
});
$refresh=static function()use($account,&$authorityMs,&$authorityCalls):array {
    $before=microtime(true);$context=(new app\services\ai\execution\AiPlatformPrincipalResolver())->authenticated($account);
    $authorityMs+=(microtime(true)-$before)*1000;++$authorityCalls;
    if (empty($context['can_use'])||empty($context['personnel_data_authorized'])) throw new RuntimeException('LOCAL_AUTH_REQUIRED');
    return $context;
};
$context=$refresh();
$snapshot=json_decode($run['snapshot_json'],true);
if (app\services\ai\execution\AiAuthority::permissionHash($context)!==($snapshot['authorization_version']??null)) throw new RuntimeException('LOCAL_AUTH_CHANGED');
$authorize=static function(string $metric)use($refresh):array {
    $fresh=$refresh();return ['personnel_authorized'=>true,'store_ids'=>$fresh['store_ids'],
        'employee_id'=>($fresh['scope_mode']??null)==='self_participant'?(int)$fresh['employee_id']:0,
        'permission_version'=>app\services\ai\execution\AiAuthority::permissionHash($fresh)];
};
$objects=new app\services\query\metric\PersonnelAnalysisObjectServices(static function(string $table){return think\facade\Db::name($table);},$authorize);
$tenant=(string)$context['tenant_id'];$stores=$context['store_ids'];$range=$period==='current'
    ?['start'=>'2026-10-01','end'=>'2026-10-11']:['start'=>'2026-09-01','end'=>'2026-09-30'];
$rankLimit=$period==='current'?1:3;$primary='staff_sales_yeji';$resultHashes=[];$selectionHashes=[];
$profile=app\services\ai\execution\AiRankingPresentationMetricResolver::detail(app\services\query\metric\MetricReadViewServices::metricCapabilities(),$primary,'person');
$measure=static function(string $label,callable $action)use(&$phase,&$events) {
    $phase=$label;$before=microtime(true);
    try {$value=$action();$events[]=['stage'=>$label,'ms'=>round((microtime(true)-$before)*1000),
        'rows'=>is_array($value)?count($value['pairs']??$value):null,'ok'=>true];return $value;}
    catch (Throwable $error) {$events[]=['stage'=>$label,'ms'=>round((microtime(true)-$before)*1000),'ok'=>false];throw $error;}
};
$error=null;
try {
    $selection=$measure('primary_population',static function()use($objects,$primary,$tenant,$range){return $objects->factParticipantSelection([$primary],$tenant,$range);});
    // Keep the existing 10-second Reader budget. No Run heartbeat is emulated:
    // that would mutate its audit lease; count boundary checks separately.
    (new app\services\query\metric\MetricReadTransaction(10000,static function()use(&$checkpoints){++$checkpoints;}))->run(
        static function($reader)use($measure,$primary,$selection,$tenant,$stores,$range,$profile,$objects,$mode,$rankLimit,&$resultHashes,&$selectionHashes) {
            $points=$measure('primary_totals',static function()use($reader,$tenant,$stores,$range,$primary,$selection){return $reader->personnelTotals($tenant,$stores,$range,$primary,$selection['pairs']);});
            $resultHashes[$primary]=hash('sha256',json_encode($points));$selectionHashes[$primary]=$selection['binding_hash'];
            $totals=[];foreach ($points as $point) $totals[$point['employee_id']]=($totals[$point['employee_id']]??0)+$point['amount_cents'];
            uksort($totals,static function($left,$right)use($totals){return ($totals[$right]<=>$totals[$left])?:($left<=>$right);});
            $ids=array_slice(array_keys($totals),0,$rankLimit);$populationCache=$mode==='cached'?[]:null;
            foreach (array_slice($profile,1) as $code) {
                $selected=$measure($code.'_population',static function()use($objects,$code,$tenant,$range,&$populationCache){return $objects->factParticipantSelection([$code],$tenant,$range,$populationCache);});
                $selectionHashes[$code]=$selected['binding_hash'];
                $pairs=array_values(array_filter($selected['pairs'],static function($pair)use($ids):bool{return in_array($pair['employee_id'],$ids,true);}));
                $values=$measure($code.'_totals',static function()use($reader,$tenant,$stores,$range,$code,$pairs){return $pairs?$reader->personnelTotals($tenant,$stores,$range,$code,$pairs):[];});
                $resultHashes[$code]=hash('sha256',json_encode($values));
            }
            return [];
        });
} catch (Throwable $failure) {
    $error=['class'=>get_class($failure),'reason'=>$failure instanceof app\services\query\metric\MetricQueryContractException
        ?$failure->getErrorCode():(preg_match('/^[A-Z_]+$/D',$failure->getMessage())?$failure->getMessage():'REDACTED')];
}
echo json_encode(['suite'=>'OPT3-personnel-ranking-local','mode'=>$mode,'shape'=>'person-ranking-top-'.$rankLimit,'primary_metric'=>$primary,
    'profile'=>$profile,'store_count'=>count($stores),'range'=>$range,'reader_budget_ms'=>10000,
    'elapsed_ms'=>round((microtime(true)-$start)*1000),'authority_calls'=>$authorityCalls,'authority_ms'=>round($authorityMs),
    'checkpoints'=>$checkpoints,'events'=>$events,'sql_by_stage'=>$sql,'error'=>$error,
    'result_hashes'=>$resultHashes,'selection_hashes'=>$selectionHashes,
    'memory_limit'=>ini_get('memory_limit'),'peak_bytes'=>memory_get_peak_usage(true),
    'limitation'=>'registered shape reconstruction; excludes Run heartbeat/audit writes and model binding'],JSON_UNESCAPED_SLASHES),PHP_EOL;
if ($error!==null) exit(1);

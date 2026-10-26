<?php
/**
 * Read-only local probe for the two registered member predicates. It reports
 * counts/timing only; member identities and business records never leave the DB.
 */
if (getenv('MOHE_R53_OBSERVED_READ') !== 'LOCAL_ONLY') exit("Explicit local opt-in required\n");
require getcwd().'/vendor/autoload.php';
$app=new think\App(); $app->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??'')!=='mysql' || ($db['database']??'')!=='ruihao') exit("Local database mismatch\n");
$uuid=think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null;
if ($uuid!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') exit("Local instance mismatch\n");
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$context=(new app\services\ai\execution\AiPlatformPrincipalResolver())->authenticated($id);
if (empty($context['can_use']) || empty($context['member_data_authorized'])) exit("Principal not authorized\n");
$stores=array_map('intval',$context['store_ids']);
$end=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$set=['subject'=>'member','relation'=>'all','conditions'=>[
    ['metric_code'=>'member_remaining_project_times','operator'=>'gt','value'=>0],
    ['metric_code'=>'member_days_since_last_visit','operator'=>'gt','value'=>90],
]];
if (getenv('MOHE_R53_OBSERVED_PROFILE')==='1') {
    $reader=new app\services\query\metric\RegisteredMetricReadServices();
    $method=new ReflectionMethod($reader,'memberMetricAggregateQuery');
    if (PHP_VERSION_ID<80100) $method->setAccessible(true);
    foreach ($set['conditions'] as $condition) {
        [$query,$expression]=$method->invoke($reader,$condition['metric_code'],
            (string)$context['tenant_id'],$stores,['start'=>$end,'end'=>$end]);
        $query->fieldRaw('s.member_id member_id,'.$expression.' metric_value')
            ->having($expression.' > '.$condition['value']);
        $begin=microtime(true);
        $sql=$query->buildSql();
        $count=(int)think\facade\Db::table([$sql=>'qualified'])->count();
        echo json_encode(['metric_code'=>$condition['metric_code'],'qualified_count'=>$count,
            'elapsed_ms'=>(int)((microtime(true)-$begin)*1000)])."\n";
    }
}
$start=microtime(true);
try {
    $limit=getenv('MOHE_R53_OBSERVED_LIST')==='1'?100:0;
    $result=(new app\services\query\metric\RegisteredMetricReadServices())->conditionMembers(
        (string)$context['tenant_id'],$stores,['start'=>$end,'end'=>$end],$set,$limit);
    echo json_encode(['scope_store_count'=>count($stores),'count'=>$result['count'],'result_row_count'=>count($result['rows']),
        'elapsed_ms'=>(int)((microtime(true)-$start)*1000)])."\n";
} catch (Throwable $error) {
    $message=$error->getMessage();
    echo json_encode(['scope_store_count'=>count($stores),'error_class'=>get_class($error),
        'error_code'=>preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$message)?$message:'non-contract-error',
        'elapsed_ms'=>(int)((microtime(true)-$start)*1000)])."\n";
    exit(1);
}

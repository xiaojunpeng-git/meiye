<?php
/**
 * R8C local Ruihao read-only reconciliation for the reported store/month.
 *
 * This script never invokes a model and prints only metric totals. The
 * database UUID and name guard prevent an accidental run against production
 * or another customer instance.
 */
if (getenv('MOHE_R8C_LOCAL_TEST')!=='LOCAL_READ_ONLY') exit("LOCAL_READ_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();

$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql' || ($db['database']??null)!=='ruihao') {
    throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
}
$uuid=think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null;
if ($uuid!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
    throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
}

think\facade\Db::execute('START TRANSACTION READ ONLY');
try {
    $storeId=179;
    $range=['start'=>'2026-10-01','end'=>'2026-10-16'];
    $store=think\facade\Db::name('system_store')->where('id',$storeId)->field('id,name')->find();
    if (!is_array($store) || trim((string)($store['name']??''))!=='扬州大润发店') {
        throw new RuntimeException('LOCAL_STORE_FIXTURE_MISMATCH');
    }

    $admission=(new app\services\ai\semantic\AiDeterministicSummaryAdmission())->matchQuery(
        '扬州大润发店本月的业绩是多少?消耗多少?',
        app\services\query\metric\MetricSemanticCatalog::entries(),
        [['ref'=>'store:'.$storeId,'kind'=>'store','label'=>(string)$store['name'],'aliases'=>[]]],
        app\services\ai\execution\AiOverviewMetricResolver::MAX_METRICS
    );
    if (($admission['metric_codes']??null)!==['cash_performance','consume_amount']
        || ($admission['store_id']??null)!==$storeId) {
        throw new RuntimeException('REPORTED_QUESTION_NOT_ADMITTED');
    }

    $reader=new app\services\query\metric\RegisteredMetricReadServices();
    $cash=$reader->summary('cash_performance','0',[$storeId],$range);
    $consume=$reader->summary('consume_amount','0',[$storeId],$range);
    $report=(new app\services\report\StoreUnifiedReportServices())->query([$storeId],[
        'report'=>'overview','start_date'=>$range['start'],'end_date'=>$range['end'],
        '_report_scope'=>['mode'=>'all'],
    ]);
    $cards=array_column((array)($report['cards']??[]),null,'code');
    $reportCash=(int)($cards['cash_performance']['value_cents']??PHP_INT_MIN);
    $reportConsume=(int)($cards['consumption_performance']['value_cents']??PHP_INT_MIN);
    if ($cash!==$reportCash || $consume!==$reportConsume) {
        throw new RuntimeException('REGISTERED_READER_REPORT_DRIFT');
    }
    echo json_encode([
        'target_verified'=>true,'store_id'=>$storeId,'range'=>$range,
        'metric_codes'=>$admission['metric_codes'],'cash_cents'=>$cash,
        'consume_cents'=>$consume,'report_parity'=>true,
    ],JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {
    think\facade\Db::execute('ROLLBACK');
}

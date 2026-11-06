<?php
declare(strict_types=1);

/**
 * 已核准历史统计明细导入器。默认仅输出计划；--apply需同时提供固定数据指纹与目标库。
 * 仅写四张报表读模型表，既有业务订单、业绩事实、余额及卡权益均不更新。
 * 建表由独立升级SQL完成；批次事务提交前不可被查询，重试同一指纹幂等。
 */
require getcwd().'/vendor/autoload.php';
(new \think\App())->initialize();
date_default_timezone_set('Asia/Shanghai');
use app\services\report\HistoricalStoreSummaryFacts;
use think\facade\Db;
$options=getopt('',['facts:','target-db:','accepted-sha256:','apply','local-test-database:']);
if(isset($options['local-test-database'])){
    // 只能切到本机专用验证库；不能借该选项选中任何客户业务库。
    $testDb=(string)$options['local-test-database'];
    if(config('database.connections.mysql.hostname')!=='mysql' || !preg_match('/^mohe_report_r18_verify(?:_[a-z0-9]+)?$/D',$testDb))
        throw new RuntimeException('LOCAL_TEST_DATABASE_ONLY');
    $config=config('database');$config['connections']['mysql']['database']=$testDb;
    \think\facade\Config::set($config,'database');
}
$input=$options['facts']??'php://stdin';
$payload=json_decode(file_get_contents($input),true,512,JSON_THROW_ON_ERROR);
$facts=HistoricalStoreSummaryFacts::normalize($payload);unset($payload);
$database=(string)config('database.connections.mysql.database');
if($database===''||$database!==($options['target-db']??''))throw new RuntimeException('TARGET_DATABASE_MISMATCH');
// 这是010该次补导的专用执行器；库名、封存原件及期间同时绑定，防止串客户导入。
if(!isset($options['local-test-database'])&&($database!=='mohe_010_app'
    ||(string)config('database.connections.mysql.hostport')!=='3307'
    ||!in_array(config('database.connections.mysql.hostname'),['127.0.0.1','localhost'],true)))
    throw new RuntimeException('TARGET_INSTANCE_MISMATCH');
if($facts['source_schema']!=='mohe_010_final_20261031'
    ||$facts['source_archive_sha256']!=='a101785a572130cbb7197acfadc612bd78b4f49dc6e4253bda52b7c2b9534724'
    ||$facts['period']!==['start'=>'2026-10-01','end'=>'2026-10-31'])throw new RuntimeException('SOURCE_BATCH_MISMATCH');
$batchId='store-summary-'.substr($facts['payload_sha256'],0,32);
$counts=[];foreach(HistoricalStoreSummaryFacts::DOMAINS as $domain)$counts[$domain]=count($facts[$domain]);
$plan=['target_database'=>$database,'batch_id'=>$batchId,'period'=>$facts['period'],'counts'=>$counts,
    'payload_sha256'=>$facts['payload_sha256'],'source_archive_sha256'=>$facts['source_archive_sha256'],
    'business_tables_changed'=>0,'preview'=>HistoricalStoreSummaryFacts::aggregate($facts,$facts['stores'],$facts['period'])];
if(!isset($options['apply'])){echo json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";return;}
if(!hash_equals($facts['payload_sha256'],(string)($options['accepted-sha256']??'')))throw new RuntimeException('ACCEPTED_PAYLOAD_REQUIRED');
$lock='report-history:'.substr(hash('sha256',$database),0,30);
if((int)(Db::query('SELECT GET_LOCK(?,10) acquired',[$lock])[0]['acquired']??0)!==1)
    throw new RuntimeException('HISTORY_IMPORT_LOCK_BUSY');
try{
    Db::transaction(function()use($facts,$batchId,$counts,&$plan):void{
        $existing=Db::name('report_history_batch')->where('batch_id',$batchId)->lock(true)->find();
        if($existing){
            if($existing['payload_sha256']!==$facts['payload_sha256']||$existing['status']!=='active')
                throw new RuntimeException('EXISTING_BATCH_CONFLICT');
            foreach(HistoricalStoreSummaryFacts::DOMAINS as $domain)verifyHistoryRows($domain,$batchId,$facts[$domain]);
            $plan['status']='already_active';return;
        }
        // 活跃期间不可重叠；不通过替换/删除旧批次来偷偷改变历史统计。
        $overlap=Db::name('report_history_batch')->where('report_code','basic_store_summary')->where('status','active')
            ->where('period_start','<=',$facts['period']['end'])->where('period_end','>=',$facts['period']['start'])->lock(true)->find();
        if($overlap)throw new RuntimeException('ACTIVE_PERIOD_OVERLAP');
        $ids=array_map('intval',array_column($facts['stores'],'id'));
        if(Db::name('system_store')->whereIn('id',$ids)->count()!==count($ids))throw new RuntimeException('TARGET_STORE_IDENTITY_MISSING');
        Db::name('report_history_batch')->insert(['batch_id'=>$batchId,'report_code'=>'basic_store_summary',
            'policy'=>HistoricalStoreSummaryFacts::POLICY,'period_start'=>$facts['period']['start'],'period_end'=>$facts['period']['end'],
            'source_archive_sha256'=>$facts['source_archive_sha256'],'payload_sha256'=>$facts['payload_sha256'],
            'source_schema'=>$facts['source_schema'],'status'=>'preparing','counts_json'=>json_encode($counts),'recorded_at'=>time()]);
        foreach(HistoricalStoreSummaryFacts::DOMAINS as $domain){
            $rows=[];
            foreach($facts[$domain] as $r){$r['batch_id']=$batchId;$rows[]=$r;}
            foreach(array_chunk($rows,200) as $chunk)Db::name('report_history_'.$domain)->insertAll($chunk);
            if(Db::name('report_history_'.$domain)->where('batch_id',$batchId)->count()!==$counts[$domain])
                throw new RuntimeException('INSERT_COUNT_MISMATCH');
            verifyHistoryRows($domain,$batchId,$facts[$domain]);
        }
        Db::name('report_history_batch')->where('batch_id',$batchId)->update(['status'=>'active']);
        $plan['status']='activated';
    });
}finally{Db::query('SELECT RELEASE_LOCK(?) released',[$lock]);}
echo json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";

/** 激活与幂等重试均逐列回读，防止只比较数量而漏过类型截断或同条数内容变化。 */
function verifyHistoryRows(string $domain,string $batchId,array $expected):void
{
    $actual=Db::name('report_history_'.$domain)->where('batch_id',$batchId)->select()->toArray();$byId=[];
    foreach($actual as $r)$byId[($r['fact_kind']??$domain).':'.$r['source_id']]=$r;
    if(count($byId)!==count($expected))throw new RuntimeException('HISTORY_READBACK_COUNT');
    foreach($expected as $r){$key=($r['fact_kind']??$domain).':'.$r['source_id'];
        foreach($r as $field=>$value)if(!isset($byId[$key][$field])||(string)$byId[$key][$field]!== (string)$value)
            throw new RuntimeException('HISTORY_READBACK_VALUE:'.$domain.':'.$key.':'.$field);
    }
}

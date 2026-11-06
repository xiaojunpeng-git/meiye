<?php
declare(strict_types=1);
/**
 * Apply only a fixed accepted 010 historical recovery artifact. Default is read-only.
 * Original rows are locked and compared with evidence, all new facts share one
 * transaction, and native metrics must reconcile before commit. No checkout,
 * event dispatch, asset deduction, original-row update or employee creation occurs.
 */
ini_set('memory_limit', '1024M');
$options = getopt('', ['plan:', 'source:', 'accepted-file-sha:', 'backup-dir:', 'apply', 'rehearse-local']);
require getcwd() . '/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use think\facade\Config;
use app\services\query\metric\RegisteredMetricReadServices;

// Framework CLI exception rendering may return exit status zero. Recovery
// automation must distinguish every rejected precondition from success.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});

function recoveryAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
/** Canonical keys make evidence independent of SELECT column order; values stay exact. */
function recoveryCanonical($value) {
    if (!is_array($value)) return $value;
    if (array_keys($value) !== range(0, count($value)-1)) ksort($value);
    foreach ($value as &$child) $child = recoveryCanonical($child);
    return $value;
}
function recoveryDigest($value): string {
    return hash('sha256', json_encode(recoveryCanonical($value), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
$local = isset($options['rehearse-local']);
$apply = isset($options['apply']);
$config = config('database');
if ($local) {
    recoveryAssert(!$apply && getcwd()==='/var/www/html' && in_array($config['connections']['mysql']['hostname'],['mysql','mohe-mysql'],true),'LOCAL_ONLY');
    $config['connections']['mysql']['database']='codex_d3_010_680';
    Config::set($config,'database');
} else {
    $c=$config['connections']['mysql'];
    recoveryAssert(realpath(getcwd())==='/www/wwwroot/010.cc3798.com' && $c['database']==='mohe_010_app'
        && $c['hostname']==='127.0.0.1' && (string)$c['hostport']==='3307','TARGET_NOT_010');
}
$planPath=(string)($options['plan']??'');
$sourcePath=(string)($options['source']??'');
$accepted=(string)($options['accepted-file-sha']??'');
recoveryAssert(is_file($planPath)&&is_file($sourcePath),'ARTIFACTS_REQUIRED');
recoveryAssert($accepted!==''&&hash_equals($accepted,hash_file('sha256',$planPath)),'ACCEPTED_ARTIFACT_MISMATCH');
$plan=json_decode(file_get_contents($planPath),true,512,JSON_THROW_ON_ERROR);
$source=json_decode(file_get_contents($sourcePath),true,512,JSON_THROW_ON_ERROR);
recoveryAssert($plan['target']==='010.cc3798.com/mohe_010_app' && !$plan['issues']
    && $plan['counts']['services']===680 && $plan['counts']['consumption_cents']===10121600,'PLAN_SCOPE_MISMATCH');
recoveryAssert(hash_equals($plan['source_sha256'],recoveryDigest($source)),'SOURCE_ARTIFACT_MISMATCH');
if ($apply) {
    $backup=(string)($options['backup-dir']??'');
    recoveryAssert(preg_match('#^/www/backups/010/[A-Za-z0-9-]+$#D',$backup)===1
        && is_file($backup.'/affected-tables.sql.gz') && is_file($backup.'/SHA256SUMS'),'PRIVATE_BACKUP_REQUIRED');
    $manifest=trim(file_get_contents($backup.'/SHA256SUMS'));
    recoveryAssert(strpos($manifest,hash_file('sha256',$backup.'/affected-tables.sql.gz'))===0,'BACKUP_CHECKSUM_MISMATCH');
}
Db::execute(($apply||$local)?'START TRANSACTION':'START TRANSACTION READ ONLY');
try {
    // Compare exact evidence rows. Locks protect mutable legacy rights while
    // facts are inserted; nonexistent legacy metadata is retained as missing.
    $allowedSources=['store_order','store_order_cart_info','user_card_holder','store_order_writeoff','cashier_v3_sales_order',
        'cashier_v3_sales_order_line','staff_yeji','system_store_staff','store_product','user','organization',
        'organization_store','system_store','store_product_category','cashier_v3_entitlement_service_fact',
        'cashier_v3_entitlement_writeoff_fact','cashier_v3_performance_fact','cashier_v3_order_lifecycle_operation'];
    // Live redemptions may change remaining counters after evidence collection.
    // These fields never determine the historical candidate. Lock their current
    // values and prove this recovery leaves them unchanged, rather than freezing
    // normal cashier activity to the time of the audit snapshot.
    $liveFields=['store_order_cart_info'=>['write_surplus_times','is_writeoff','writeoff_time'],
        'user_card_holder'=>['write_surplus_times']];
    // Lookup rows contain live login counters and catalog stock unrelated to
    // this plan. Compare exactly the identity/name/category fields consumed by
    // the compiler; changes to these fields still invalidate the candidate.
    $lookupFields=['system_store_staff'=>['id','staff_name','employee_id','is_hezuofang','is_del','status','phone'],
        'store_product'=>['id','store_name','cate_id'], 'system_store'=>['id','name','is_del'],
        'organization'=>['id','pid','name'], 'organization_store'=>['id','store_id','org_id']];
    $liveBefore=[];
    foreach ($source['tables'] as $table=>$rows) {
        recoveryAssert(in_array($table,$allowedSources,true),'INVALID_SOURCE_TABLE');
        $key=$table==='user'?'uid':'id';
        foreach (array_chunk($rows,250) as $chunk) {
            $fields=array_keys($chunk[0]);
            $query=Db::name($table)->whereIn($key,array_column($chunk,$key))->field($fields);
            if ($apply||$local) $query->lock(true);
            $actual=[];
            foreach ($query->select()->toArray() as $row) $actual[(string)$row[$key]]=$row;
            foreach ($chunk as $row) {
                $id=(string)$row[$key];
                recoveryAssert(isset($actual[$id]),'SOURCE_MISSING_'.$table.'_'.$id);
                $observed=$actual[$id];
                if(isset($lookupFields[$table])){
                    $selected=array_fill_keys($lookupFields[$table],true);
                    $row=array_intersect_key($row,$selected);$observed=array_intersect_key($observed,$selected);
                }
                foreach($liveFields[$table]??[]as$field){
                    $liveBefore[$table][$id][$field]=$observed[$field];
                    unset($row[$field],$observed[$field]);
                }
                recoveryAssert(recoveryDigest($observed)===recoveryDigest($row),'SOURCE_CHANGED_'.$table.'_'.$id);
            }
        }
    }
    $ids=array_column($plan['source_links'],'writeoff_id');
    foreach(array_chunk($ids,200)as$chunk) recoveryAssert((int)Db::name('cashier_v3_entitlement_service_fact')
        ->where('tenant_id','0')->whereIn('source_document_type',['legacy_writeoff','legacy_paid_project'])
        ->whereIn('source_document_id',$chunk)->count()===0,'SERVICE_ALREADY_RESTORED');
    $services=$plan['insert_tables']['cashier_v3_entitlement_service_fact'];
    $stores=array_values(array_unique(array_map('intval',array_column($services,'store_id'))));
    $range=['start'=>'2025-01-01','end'=>'2026-11-06'];
    $reader=new RegisteredMetricReadServices();
    $before=[];
    foreach(['consume_amount','staff_labor_yeji','completed_service_item_count']as$metric)
        $before[$metric]=$reader->summary($metric,'0',$stores,$range);
    $result=['mode'=>$local?'local_rollback':($apply?'apply':'read_only_check'),'artifact_sha256'=>$accepted,
        'plan_sha256'=>$plan['plan_sha256'],'counts'=>$plan['counts'],'before'=>$before];
    if($apply||$local){
        $now=time();
        foreach($plan['insert_tables']as$table=>$rows){
            recoveryAssert(in_array($table,['cashier_v3_entitlement_service_fact','cashier_v3_entitlement_writeoff_fact',
                'cashier_v3_performance_fact','cashier_v3_business_event'],true),'INVALID_INSERT_TABLE');
            foreach($rows as$row){
                foreach(['recorded_at','created_at','updated_at']as$timeField)if(array_key_exists($timeField,$row))$row[$timeField]=$now;
                if(array_key_exists('immutable_fingerprint',$row)){
                    unset($row['immutable_fingerprint']);$row['immutable_fingerprint']=recoveryDigest($row);
                }
                Db::name($table)->insert($row);
            }
        }
        $expected=['consume_amount'=>10121600,'staff_labor_yeji'=>2586600,
            'completed_service_item_count'=>array_sum(array_column($services,'quantity'))];
        foreach($expected as$metric=>$delta){
            $after=$reader->summary($metric,'0',$stores,$range);
            recoveryAssert($after-$before[$metric]===$delta,'METRIC_DELTA_'.$metric);
            $result['after'][$metric]=$after;
        }
        $daily=array_sum(array_column($reader->dailyTotals('consume_amount','0',$stores,$range),'metric_value'));
        recoveryAssert($daily===$result['after']['consume_amount'],'DAILY_TOTAL_MISMATCH');
        $result['daily_cents']=$daily;
        foreach($liveBefore as$table=>$records)foreach($records as$id=>$values){
            $nowValues=Db::name($table)->where('id',$id)->field(array_keys($values))->find();
            recoveryAssert(recoveryDigest($values)===recoveryDigest($nowValues),'CURRENT_RIGHTS_CHANGED_'.$table.'_'.$id);
        }
        if($apply)Db::execute('COMMIT');else Db::execute('ROLLBACK');
    }else Db::execute('ROLLBACK');
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $error){
    Db::execute('ROLLBACK');fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);
}

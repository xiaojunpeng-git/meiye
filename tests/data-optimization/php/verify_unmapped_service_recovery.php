<?php
declare(strict_types=1);
/** Local-only transaction rehearsal against the real unified readers; always rolls back. */
require getcwd() . '/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use think\facade\Config;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\HistoricalCardService007ReadServices;

$config = config('database');
if (!in_array($config['connections']['mysql']['hostname'], ['mysql','mohe-mysql','127.0.0.1'], true)
    || getcwd() !== '/var/www/html') throw new RuntimeException('LOCAL_ONLY');
$config['connections']['mysql']['database'] = 'codex_d3_010_680';
Config::set($config, 'database');
$plan = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($plan['issues'] || $plan['counts']['services'] !== 680) throw new RuntimeException('PLAN_INCOMPLETE');
function ensureRecovery(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
/** Source rows, including current rights counters, must be byte-identical after the rehearsal. */
function originalRecoveryHash(): string {
    $hash = hash_init('sha256');
    foreach (['store_order','store_order_cart_info','user_card_holder','store_order_writeoff','staff_yeji','cashier_v3_sales_order','cashier_v3_sales_order_line'] as $table) {
        hash_update($hash, json_encode(Db::name($table)->order('id')->select()->toArray(), JSON_UNESCAPED_UNICODE));
    }
    return hash_final($hash);
}
Db::execute('START TRANSACTION');
try {
    $sourceHash = originalRecoveryHash();
    $stores = array_values(array_unique(array_map('intval',array_column($plan['insert_tables']['cashier_v3_entitlement_service_fact'],'store_id'))));
    $range = ['start'=>'2025-01-01','end'=>'2026-11-06'];
    $reader = new RegisteredMetricReadServices();
    $before = [];
    foreach (['consume_amount','completed_service_item_count','staff_labor_yeji'] as $metric) $before[$metric] = $reader->summary($metric,'0',$stores,$range);
    $beforeCategories = array_sum(array_column($reader->categoryRows('consume_amount','0',$stores,$range),'amount_cents'));
    $candidateIds=array_fill_keys(array_column($plan['source_links'],'writeoff_id'),true);
    $legacyReader=new HistoricalCardService007ReadServices();
    $oldBefore=$legacyReader->readServices($stores,$range);
    $oldBeforeRows=array_values(array_filter($oldBefore['rows'],static fn($row)=>isset($candidateIds[(int)$row['source_writeoff_id']])));
    ensureRecovery(count($oldBeforeRows)===680 && array_sum(array_column($oldBeforeRows,'amount_cents'))===10121600,'HISTORICAL_SOURCE_BEFORE');
    foreach ($plan['insert_tables'] as $table=>$rows) {
        ensureRecovery(in_array($table,['cashier_v3_entitlement_service_fact','cashier_v3_entitlement_writeoff_fact','cashier_v3_performance_fact','cashier_v3_business_event'],true),'UNEXPECTED_TABLE');
        foreach ($rows as $row) Db::name($table)->insert($row);
    }
    $after=[];
    foreach (array_keys($before) as $metric) $after[$metric]=$reader->summary($metric,'0',$stores,$range);
    ensureRecovery($after['consume_amount']-$before['consume_amount']===10121600,'CONSUMPTION_DELTA');
    ensureRecovery($after['staff_labor_yeji']-$before['staff_labor_yeji']===2586600,'LABOR_DELTA');
    ensureRecovery($after['completed_service_item_count']-$before['completed_service_item_count']===array_sum(array_column($plan['insert_tables']['cashier_v3_entitlement_service_fact'],'quantity')),'SERVICE_QUANTITY_DELTA');
    $daily=array_sum(array_column($reader->dailyTotals('consume_amount','0',$stores,$range),'metric_value'));
    ensureRecovery($daily===$after['consume_amount'],'DAILY_SUM');
    $afterCategories=array_sum(array_column($reader->categoryRows('consume_amount','0',$stores,$range),'amount_cents'));
    ensureRecovery($afterCategories-$beforeCategories===10121600,'CATEGORY_MULTIPLIED_OR_MISSING');
    $detail=$reader->detailPage('consume_amount','0',$stores,$range,1,20);
    $components=[];
    foreach ($plan['insert_tables']['cashier_v3_entitlement_writeoff_fact'] as $row) {
        // A physically deleted detail has no current component; its historical
        // fact remains auditable without creating a new spendable entitlement.
        if (!Db::name('store_order_cart_info')->where('id',$row['source_detail_id'])->find()) continue;
        $components[$row['source_detail_id']]=['project_detail_id'=>$row['source_detail_id'],'card_holder_id'=>$row['holder_id'],
            'origin_order_id'=>$row['origin_order_id'],'member_id'=>$row['member_id'],'store_id'=>$row['store_id']];
    }
    $rights=(new HistoricalCardService007ReadServices())->readComponents(array_values($components));
    $newGaps=array_values(array_filter($rights['gaps'],static fn($gap)=>isset($candidateIds[(int)($gap['source_writeoff_id']??0)])));
    ensureRecovery($newGaps===[],'NEW_CARD_SOURCE_GAPS:'.json_encode($newGaps));
    $oldAfter=$legacyReader->readServices($stores,$range);
    $oldAfterRows=array_values(array_filter($oldAfter['rows'],static fn($row)=>isset($candidateIds[(int)$row['source_writeoff_id']])));
    ensureRecovery($oldAfterRows===[],'LEGACY_ADAPTER_DOUBLE_COUNT');
    $first=$plan['insert_tables']['cashier_v3_entitlement_service_fact'][0];
    $duplicateRejected=false;
    try{Db::name('cashier_v3_entitlement_service_fact')->insert($first);}catch(Throwable $duplicate){
        $duplicateRejected=strpos($duplicate->getMessage(),'Duplicate entry')!==false;
    }
    ensureRecovery($duplicateRejected,'DUPLICATE_NOT_REJECTED');
    ensureRecovery(originalRecoveryHash()===$sourceHash,'ORIGINAL_RIGHTS_OR_ORDER_CHANGED');
    $activeStores=array_values(array_filter($stores,static fn($id)=>$id!==31));
    $historicalAmount=(int)Db::name('cashier_v3_performance_fact')->where('store_id',31)
        ->where('rule_code_snapshot','legacy_service_recovery_680')->where('performance_type','consumption_performance_recorded')->sum('amount_cents');
    ensureRecovery($historicalAmount===1240100,'HISTORICAL_HQ_AMOUNT');
    echo json_encode(['before'=>$before,'after'=>$after,'daily_cents'=>$daily,'category_delta_cents'=>$afterCategories-$beforeCategories,
        'detail_total'=>$detail['total'],'new_card_source_gaps'=>$newGaps,'unchanged_original_hash'=>$sourceHash,
        'historical_adapter_remaining_candidate_rows'=>count($oldAfterRows),'duplicate_rejected'=>$duplicateRejected,
        'historical_hq_cents'=>$historicalAmount,'active_store_increment_cents'=>10121600-$historicalAmount,
        'plan_sha256'=>$plan['plan_sha256'],'mode'=>'local_rollback_rehearsal'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
    Db::execute('ROLLBACK');
} catch (Throwable $error) {
    Db::execute('ROLLBACK');
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
}

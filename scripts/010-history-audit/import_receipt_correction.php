<?php
declare(strict_types=1);
/**
 * Explicitly approved import correction, not a checkout/payment command.
 * Writes only four payment-graph tables and the empty imported collection batch.
 * Original records, guards and before/after snapshots are preserved in a private audit.
 */
function bankRepairCanonical(array $row): string {
    unset($row['id']);ksort($row);
    foreach($row as &$value)if($value!==null)$value=(string)$value;
    unset($value);return json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function bankRepairIdentity(string $table):string {
    $keys=['cashier_v3_checkout_payment_draft'=>'payment_draft_id','cashier_v3_payment_collection'=>'collection_id',
        'cashier_v3_payment_fact'=>'fact_id','cashier_v3_payment_sale_allocation_fact'=>'allocation_fact_id',
        'cashier_v3_payment_collection_batch'=>'batch_id'];
    if(!isset($keys[$table]))throw new RuntimeException('table outside approved repair scope');return $keys[$table];
}
/** Return exact financial deltas from the same registered reader used by the live dashboard. */
function bankRepairStats(array $stores):array {
    $reader=new app\services\query\metric\RegisteredMetricReadServices();$result=[];
    foreach(['all'=>['start'=>'2026-01-01','end'=>'2026-12-31'],'october'=>['start'=>'2026-10-01','end'=>'2026-10-31']]as $period=>$range)
        foreach(['cash_performance','actual_performance','refund_performance','recharge_amount','balance_deduction_amount']as $metric)
            $result[$period][$metric]=$reader->summary($metric,'0',$stores,$range);
    return $result;
}
/** Locks and validates every row before any write; a changed or partially applied graph aborts. */
function bankRepairApply(array $plan,string $auditDirectory,bool $commit):array {
    if(!in_array($plan['contract']??'', ['010-bank-import-correction-v1','010-other-income-import-correction-v1','010-import-receipt-correction-v1'],true)||($plan['assetWrites']??null)!==0)throw new RuntimeException('unsupported repair plan');
    $ops=[];$stores=[];$members=[];
    foreach($plan['updates'] as $change){$table=$change['table'];$key=bankRepairIdentity($table);if($key!==$change['key'])throw new RuntimeException('wrong update identity');$ops[]=['kind'=>'update']+$change;}
    foreach($plan['inserts'] as $table=>$rows)foreach($rows as $row){$key=bankRepairIdentity($table);$ops[]=['kind'=>'insert','table'=>$table,'key'=>$key,'value'=>$row[$key],'after'=>$row];}
    if(count($ops)!==(int)($plan['expectedOperationCount']??13)||count($ops)<1||count($ops)>5000)throw new RuntimeException('unexpected approved operation count');
    foreach($ops as $op){
        if($op['table']!=='cashier_v3_payment_collection_batch'&&!in_array($op['after']['payment_method']??'', ['bank_transfer','other_income'],true))throw new RuntimeException('unapproved actual receipt method');
        if($op['kind']==='update'&&$op['table']==='cashier_v3_payment_fact'
            && ((int)$op['before']['amount_cents']!==(int)$op['after']['amount_cents']
                ||$op['updateColumns']!==['payment_method','immutable_fingerprint']))throw new RuntimeException('channel correction cannot change receipt amount');
    }
    foreach($ops as $op){$stores[]=(int)$op['after']['store_id'];$members[]=(int)$op['after']['member_id'];}
    $stores=array_values(array_unique($stores));$members=array_values(array_unique($members));
    think\facade\Db::startTrans();
    try {
        $beforeRows=[];$beforeCount=0;$afterCount=0;
        foreach($ops as $op){
            $actual=think\facade\Db::name($op['table'])->where($op['key'],$op['value'])->lock(true)->find();$actual=$actual?(array)$actual:null;
            $beforeRows[]=['table'=>$op['table'],'key'=>$op['key'],'value'=>$op['value'],'row'=>$actual];
            $before=$op['before']??null;$after=$op['after'];
            if(($actual===null&&$before===null)||($actual!==null&&$before!==null&&bankRepairCanonical($actual)===bankRepairCanonical($before)))++$beforeCount;
            elseif($actual!==null&&bankRepairCanonical($actual)===bankRepairCanonical($after))++$afterCount;
            else throw new RuntimeException('source changed or conflicting repair row: '.$op['value']);
        }
        if($afterCount===count($ops)){think\facade\Db::rollback();return ['status'=>'already_applied','operations'=>0,'assetWrites'=>0];}
        if($beforeCount!==count($ops))throw new RuntimeException('partial repair state requires investigation');
        $assets=think\facade\Db::name('user')->whereIn('uid',$members)->order('uid')->select()->toArray();
        $assetsHash=hash('sha256',json_encode($assets,JSON_THROW_ON_ERROR));$stats=bankRepairStats($stores);
        if(!is_dir($auditDirectory)&&!mkdir($auditDirectory,0700,true))throw new RuntimeException('audit directory creation failed');
        $auditPath=$auditDirectory.'/before.json';$handle=fopen($auditPath,'x');if(!$handle)throw new RuntimeException('audit already exists; refuse overwrite');
        $evidence=['planSHA256'=>hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR)),'sourceProofSHA256'=>$plan['sourceProofSHA256'],
            'rows'=>$beforeRows,'assetsSHA256'=>$assetsHash,'metrics'=>$stats];
        if(fwrite($handle,json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('backup write failed');fclose($handle);chmod($auditPath,0400);
        foreach($ops as $op){
            $row=$op['after'];unset($row['id']);
            if($op['kind']==='insert')think\facade\Db::name($op['table'])->insert($row);
            else {
                // Only the source-proven, explicitly approved imported channel fields are corrected in place.
                // Empty batches are reconstructed from original receipts; amounts/assets are never replayed.
                if(isset($op['updateColumns']))$row=array_intersect_key($row,array_flip($op['updateColumns']));
                think\facade\Db::name($op['table'])->where($op['key'],$op['value'])->update($row);
            }
        }
        foreach($ops as $op){$actual=(array)think\facade\Db::name($op['table'])->where($op['key'],$op['value'])->find();if(bankRepairCanonical($actual)!==bankRepairCanonical($op['after']))throw new RuntimeException('after row verification failed');}
        $afterAssets=think\facade\Db::name('user')->whereIn('uid',$members)->order('uid')->select()->toArray();
        if(hash('sha256',json_encode($afterAssets,JSON_THROW_ON_ERROR))!==$assetsHash)throw new RuntimeException('member data changed');
        $after=bankRepairStats($stores);$deltas=[];
        foreach($stats as $period=>$metrics)foreach($metrics as $metric=>$value){
            $delta=(int)$after[$period][$metric]-(int)$value;$deltas[$period][$metric]=$delta;
            $expected=in_array($metric,['cash_performance','actual_performance'],true)?(int)$plan[$period==='october'?'expectedOctoberCashDeltaCents':'expectedCashDeltaCents']:0;
            if($delta!==$expected)throw new RuntimeException('financial metric mismatch '.$period.':'.$metric.':'.$delta);
        }
        $result=['status'=>$commit?'applied':'dry_run_rolled_back','operations'=>count($ops),'assetWrites'=>0,'assetHashUnchanged'=>true,
            'deltasCents'=>$deltas,'audit'=>$auditDirectory,'sourceProofSHA256'=>$plan['sourceProofSHA256']];
        if(file_put_contents($auditDirectory.'/after.json',json_encode(['plan'=>$plan,'result'=>$result,'metrics'=>$after],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('after audit write failed');
        chmod($auditDirectory.'/after.json',0400);
        if($commit)think\facade\Db::commit();else think\facade\Db::rollback();return $result;
    } catch(Throwable $error){think\facade\Db::rollback();throw $error;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $args=getopt('',['root:','plan:','plan-sha:','audit:','apply']);$root=rtrim((string)($args['root']??''),'/');
    if($root!=='/www/wwwroot/010.cc3798.com')throw new RuntimeException('010 only');
    require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
    if(think\facade\Db::query('SELECT DATABASE() d')[0]['d']!=='mohe_010_app')throw new RuntimeException('wrong production database');
    $commit=array_key_exists('apply',$args);
    $bytes=file_get_contents($args['plan']);
    if(!hash_equals((string)($args['plan-sha']??''),hash('sha256',$bytes)))throw new RuntimeException('fixed approved plan hash required');
    $plan=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    if($commit)foreach(($plan['expectedMethods']??['bank_transfer'])as $method)
        if((int)think\facade\Db::name('cashier_v3_payment_method_config')->where('code',$method)->value('status')!==1)throw new RuntimeException('actual receipt method not enabled');
    echo json_encode(bankRepairApply($plan,$args['audit'],$commit),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}

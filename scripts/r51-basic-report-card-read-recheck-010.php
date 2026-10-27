<?php
declare(strict_types=1);

/** 010-only diagnostic: immutable R51 classes plus three SHA-pinned memory
 * overlays. No menus, identities, grants, cache, or business records are written.
 * Full-row digests are captured in separate native read-only snapshots so the
 * after check cannot accidentally reuse the before transaction's old snapshot.
 */
function r51ReadRecheckSafe(Throwable $e): array
{
    $allowed=['member_balance_identity_invalid','member_balance_provider_not_ready','member_balance_member_not_found',
        'QUERY_CONTRACT_INVALID','QUERY_INCOMPLETE','SALARY_TWELVE_REQUIRED','ENTITLEMENT_NONEMPTY_REQUIRED'];
    $reason=method_exists($e,'reason')?$e->reason():$e->getMessage();
    return ['class'=>get_class($e),'file'=>$e->getFile(),'line'=>$e->getLine(),
        'safe_code'=>in_array($reason,$allowed,true)?$reason:null,
        'message_sha256'=>hash('sha256',$e->getMessage()),
        'code'=>method_exists($e,'getErrorCode')?$e->getErrorCode():$e->getCode(),
        'trace'=>array_map(static function (array $frame): array {
            return array_intersect_key($frame,array_flip(['file','line','class','function']));
        },array_slice($e->getTrace(),0,14))];
}

/** Stream exact values into a framed hash, returning neither rows nor IDs. */
function r51ReadRecheckDigest(PDO $db): array
{
    $tables=['eb_system_store_staff','eb_employee','eb_employee_data_scope','eb_employee_store',
        'eb_staff_job_position','eb_system_admin','eb_system_role','eb_system_menus',
        'eb_permission_catalog','eb_salary_store','eb_user','eb_store_order','eb_store_order_cart_info',
        'eb_store_debt','eb_user_card_holder','eb_cashier_v3_balance_fact',
        'eb_cashier_v3_entitlement_writeoff_fact','eb_cashier_v3_entitlement_service_fact',
        'eb_cashier_v3_card_state','eb_cashier_v3_card_rule_state','eb_cashier_v3_card_operation',
        'eb_cashier_v3_refund_adjustment','eb_cashier_v3_card_purchase_receipt'];
    $required=['eb_system_store_staff','eb_employee','eb_user','eb_store_order_cart_info',
        'eb_user_card_holder','eb_cashier_v3_balance_fact','eb_salary_store'];
    $db->beginTransaction();
    try {
        $out=[];
        foreach ($tables as $table) {
            $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]); $engine=$q->fetchColumn();
            if (!$engine) {
                if (in_array($table,$required,true)) throw new RuntimeException('REQUIRED_DIGEST_TABLE_MISSING');
                $out[$table]=['present'=>false]; continue;
            }
            if ($engine!=='InnoDB') throw new RuntimeException('NON_TRANSACTIONAL_DIGEST_TABLE');
            $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $q->execute([$table]); $columns=$q->fetchAll(PDO::FETCH_COLUMN);
            $q=$db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
            $q->execute([$table]); $pk=$q->fetchAll(PDO::FETCH_COLUMN);
            if (!$pk) throw new RuntimeException('STABLE_DIGEST_KEY_REQUIRED');
            foreach (array_merge($columns,$pk) as $column)
                if (!preg_match('/^[a-zA-Z0-9_]+$/D',$column)) throw new RuntimeException('DIGEST_COLUMN_INVALID');
            $hash=hash_init('sha256');
            hash_update($hash,"r51-readonly-fullrows-v1\n".json_encode($columns,JSON_THROW_ON_ERROR)."\n");
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            $q=$db->query('SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$pk).'`');
            $count=0;
            while ($row=$q->fetch(PDO::FETCH_NUM)) {
                hash_update($hash,'R;');
                foreach ($row as $cell) hash_update($hash,$cell===null?'N;':'S'.strlen((string)$cell).':'.$cell.';');
                hash_update($hash,"\n"); ++$count;
            }
            $q->closeCursor(); $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
            $out[$table]=['present'=>true,'count'=>$count,'sha256'=>hash_final($hash),'complete'=>true];
        }
        return $out;
    } finally { $db->rollBack(); }
}

ini_set('display_errors','0'); ini_set('log_errors','0'); ini_set('zend.exception_ignore_args','1');
$buffer=ob_get_level(); ob_start();
$output=['site'=>'010','business_writes'=>false,'remote_files_written'=>false,
    'authorization_mode'=>'original smoke real platform + Worker resolver; no metadata injection',
    'result'=>'FAIL','results'=>[]];
$native=null; $phase='guard';
$finished=false; $reserve=str_repeat('x',65536);
// Framework fatal handlers must not hide the diagnostic stage or leak messages.
register_shutdown_function(static function () use (&$finished,&$phase,&$reserve): void {
    if ($finished) return;
    $reserve=''; $last=error_get_last();
    if ($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) {
        while (ob_get_level()>0) ob_end_clean();
        file_put_contents('php://stdout',json_encode(['site'=>'010','result'=>'FAIL','business_writes'=>false,
            'phase'=>$phase,'fatal'=>['type'=>$last['type'],'file'=>$last['file'],'line'=>$last['line'],
            'message_sha256'=>hash('sha256',$last['message'])]],JSON_THROW_ON_ERROR).PHP_EOL);
    }
});
try {
    $request=json_decode(base64_decode('__R51_READ_RECHECK_REQUEST__',true),true,512,JSON_THROW_ON_ERROR);
    $root='/www/wwwroot/010.cc3798.com'; $backup='/www/backups/010/20261024-r51-dependency-repair';
    $sections=array_change_key_case(parse_ini_file($root.'/.env',true,INI_SCANNER_RAW),CASE_LOWER);
    $cfg=array_change_key_case($sections['database']??[],CASE_LOWER);
    if (($cfg['database']??'')!=='mohe_010_app' || (int)($cfg['hostport']??0)!==3307
        || ($cfg['prefix']??'')!=='eb_' || !in_array($cfg['hostname']??'',['localhost','127.0.0.1'],true)) throw new RuntimeException('DATABASE_IDENTITY_INVALID');
    $native=new PDO('mysql:host='.$cfg['hostname'].';port=3307;dbname=mohe_010_app;charset=utf8mb4',$cfg['username'],$cfg['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true]);
    unset($cfg,$sections);
    $native->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $native->exec('SET SESSION TRANSACTION READ ONLY');
    $identity=$native->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch(PDO::FETCH_ASSOC);
    if ($identity['db']!=='mohe_010_app' || (int)$identity['port']!==3307 || (int)$identity['ro']!==1) throw new RuntimeException('NATIVE_READONLY_IDENTITY_INVALID');
    $phase='before_digest'; $output['before']=r51ReadRecheckDigest($native);
    define('R51_SMOKE_LIBRARY',true); require $backup.'/r51-basic-report-smoke.php';
    $manifest=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if ($manifest['sourceTree']!=='a50d49c7f6f65988bb47de32559a662488e81235') throw new RuntimeException('PAYLOAD_TREE_INVALID');
    $map=[];
    foreach ($manifest['backend'] as $relative=>$hash) {
        R51BasicReportInstance::relative($relative);
        $file=$backup.'/payload/backend/'.$relative;
        if (realpath($file)!==$file || hash_file('sha256',$file)!==$hash) throw new RuntimeException('PAYLOAD_HASH_INVALID');
        if (strpos($relative,'app/')===0 && substr($relative,-4)==='.php')
            $map[strtolower('app'.chr(92).str_replace('/',chr(92),substr($relative,4,-4)))]=$file;
    }
    $overlays=[]; $loaded=[];
    foreach ($request['overlays'] as $entry) {
        $source=base64_decode($entry['source'],true);
        if (hash('sha256',$source)!==$entry['sha256'] || substr($source,0,5)!=='<?php'
            || strpos($source,'__DIR__')!==false || strpos($source,'__FILE__')!==false) throw new RuntimeException('OVERLAY_SOURCE_INVALID');
        if (!empty($entry['accepted_provider']) && ($manifest['backend'][$entry['relative']]??null)!==$entry['sha256']) throw new RuntimeException('PROVIDER_NOT_ACCEPTED');
        $overlays[strtolower($entry['class'])]=substr($source,5);
    }
    $phase='autoload'; chdir($root); require $root.'/vendor/autoload.php';
    foreach (array_unique(array_merge(array_keys($map),array_keys($overlays))) as $class)
        if (class_exists($class,false) || interface_exists($class,false) || trait_exists($class,false)) throw new RuntimeException('CLASS_ALREADY_LOADED');
    spl_autoload_register(static function (string $class) use ($map,$overlays,&$loaded): void {
        $key=strtolower($class);
        if (isset($overlays[$key])) { eval($overlays[$key]); $loaded[$key]=true; return; }
        if (isset($map[$key])) require $map[$key];
    },true,true);
    (new \think\App($root.'/'))->initialize();
    ini_set('display_errors','0'); ini_set('log_errors','0');
    config(require $backup.'/payload/backend/config/unified_query.php','unified_query');
    \app\services\query\UnifiedQueryRuntime::resetForTests();
    $pdo=r51SmokeReadOnlyPdo(\think\facade\Db::connect());
    $identity=$pdo->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch(PDO::FETCH_ASSOC);
    if ($identity['db']!=='mohe_010_app' || (int)$identity['port']!==3307 || (int)$identity['ro']!==1) throw new RuntimeException('FRAMEWORK_READONLY_IDENTITY_INVALID');
    $output['native_readonly']=true; $output['framework_readonly']=true;
    $samples=$manifest['smokeSamples']['010'];
    $codes=array_column($samples,'code'); $expected=$request['sample_codes']; sort($codes); sort($expected);
    if (count($samples)!==5 || $codes!==$expected) throw new RuntimeException('FIXED_FIVE_SAMPLES_REQUIRED');
    $output['fixed_samples_sha256']=hash('sha256',json_encode($samples,JSON_THROW_ON_ERROR));
    $runtime=\app\services\query\UnifiedQueryRuntime::runtime();
    foreach ($samples as $sample) {
        $phase=$sample['code'].':authorization';
        $queryEvidence=[];
        try {
            $context=r51SmokeAuthorizedContext($sample,$runtime['workerContextResolvers']);
            $phase=$sample['code'].':query';
            $result=r51SmokeReadSample($sample,$context);
            if (!isset($result['rows'],$result['summaries'],$result['pagination'])) throw new RuntimeException('QUERY_CONTRACT_INVALID');
            // Preserve safe observations even for incomplete real reads. A
            // twelve-row payroll response with gaps is not a complete PASS.
            $gapCodes=[];
            foreach (array_merge($result['data_gaps']??[],$result['gaps']??[]) as $gap) {
                $code=is_array($gap)?($gap['code']??null):null;
                if (is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{0,100}$/D',$code)) $gapCodes[]=$code;
            }
            $queryEvidence=['actual_worker_authorization'=>true,'rows'=>count($result['rows']),
                'total'=>$result['pagination']['total']??null,'complete'=>$result['complete']??false,
                'gap_codes'=>array_values(array_unique($gapCodes)),
                'gap_count'=>count($result['data_gaps']??[])+count($result['gaps']??[]),
                'summaries_sha256'=>hash('sha256',json_encode($result['summaries'],JSON_THROW_ON_ERROR))];
            if (($result['complete']??false)!==true || !empty($result['data_gaps']) || !empty($result['gaps'])) throw new RuntimeException('QUERY_INCOMPLETE');
            $count=count($result['rows']);
            if ($sample['code']==='basic_salary_realtime' && $count!==12) throw new RuntimeException('SALARY_TWELVE_REQUIRED');
            if ($sample['code']==='basic_unconsumed_summary' && $count===0) throw new RuntimeException('ENTITLEMENT_NONEMPTY_REQUIRED');
            $output['results'][]=['code'=>$sample['code'],'result'=>'PASS']+$queryEvidence;
            unset($result,$context);
        } catch (Throwable $e) { $output['results'][]=['code'=>$sample['code'],'result'=>'FAIL','phase'=>$phase,'error'=>r51ReadRecheckSafe($e)]+$queryEvidence; }
    }
    $output['overlays_loaded']=$loaded;
    if (count($loaded)!==3) throw new RuntimeException('ALL_OVERLAYS_MUST_EXECUTE');
    $output['result']=count(array_filter($output['results'],static function (array $r): bool { return $r['result']==='PASS'; }))===5?'PASS':'FAIL';
} catch (Throwable $e) { $output['failure']=['phase'=>$phase,'error'=>r51ReadRecheckSafe($e)]; }
finally {
    if ($native && isset($output['before'])) {
        try {
            $output['after']=r51ReadRecheckDigest($native);
            $output['source_tables_unchanged']=$output['before']===$output['after'];
            if (!$output['source_tables_unchanged']) $output['result']='FAIL';
        } catch (Throwable $e) { $output['result']='FAIL'; $output['after_digest_failure']=r51ReadRecheckSafe($e); }
    }
}
$finished=true;
while (ob_get_level()>$buffer) ob_end_clean();
echo json_encode($output,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;

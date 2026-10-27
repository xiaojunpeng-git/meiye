<?php
declare(strict_types=1);

/** Fixed 010 identity evidence. Only structural IDs/status and schema leave
 * the server. Do not read names/phones/accounts, infer people by display values,
 * initialize application services, write mappings or rewrite historical facts. */
ini_set('display_errors','0');
ini_set('log_errors','0');
set_error_handler(static function (): void { throw new RuntimeException('IDENTITY_PROBE_IO_FAILED'); });
try {
    $env=array_change_key_case(parse_ini_file('/www/wwwroot/010.cc3798.com/.env',true,INI_SCANNER_RAW),CASE_LOWER);
    $app=array_change_key_case($env['database']??[],CASE_LOWER);
    if (($app['database']??'')!=='mohe_010_app' || (int)($app['hostport']??0)!==3307) throw new RuntimeException('010_CONFIG_REQUIRED');
    $ini=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW);
    $cfg=array_change_key_case($ini['client']??[],CASE_LOWER);
    if (!in_array($cfg['host']??'127.0.0.1',['127.0.0.1','localhost'],true) || (int)($cfg['port']??3307)!==3307) throw new RuntimeException('FIXED_ENDPOINT_REQUIRED');
    $dsn=!empty($cfg['socket'])?'mysql:unix_socket='.$cfg['socket']:'mysql:host='.($cfg['host']??'127.0.0.1').';port=3307';
    $db=new PDO($dsn.';dbname=mohe_010_app;charset=utf8mb4',$cfg['user'],$cfg['password']??'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $id=$db->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch();
        if ($id['db']!=='mohe_010_app'||(int)$id['port']!==3307||(int)$id['ro']!==1) throw new RuntimeException('010_DATABASE_REQUIRED');
        $schemas=['mohe_010_app','mohe_010'];
        $q=$db->prepare("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA IN (?,?) AND (TABLE_NAME IN ('eb_system_store_staff','eb_employee','eb_staff_job_position','eb_employee_internal_account') OR TABLE_NAME LIKE 'codex\_010\_%' OR TABLE_NAME LIKE '%staff%map%' OR TABLE_NAME LIKE '%employee%map%') ORDER BY TABLE_SCHEMA,TABLE_NAME,ORDINAL_POSITION");
        $q->execute($schemas); $columns=[];
        foreach($q->fetchAll()as$c)$columns[$c['TABLE_SCHEMA']][$c['TABLE_NAME']][]=$c['COLUMN_NAME'];
        $out=['site'=>'010','verifiedAt'=>gmdate('c'),'database'=>$id,'read_databases'=>$schemas,'schema'=>$columns,
            'remote_writes'=>false,'application_booted'=>false,'pii_selected'=>false,'mapping_applied'=>false];
        $out['store_1_staff']=$db->query('SELECT id,store_id,employee_id,status,is_del,level,is_admin,position FROM mohe_010_app.eb_system_store_staff WHERE store_id=1 AND status=1 AND is_del=0 ORDER BY id')->fetchAll();
        $ids=array_map('intval',array_column($out['store_1_staff'],'id'));
        if (!$ids) throw new RuntimeException('STORE_ROSTER_MISSING');
        $list=implode(',',$ids);
        foreach($schemas as$schema){
            $staffCols=$columns[$schema]['eb_system_store_staff']??[];
            $wanted=array_values(array_intersect(['id','store_id','employee_id','status','is_del','level','is_admin','position','add_time'],$staffCols));
            $out['same_primary_key_staff'][$schema]=$wanted?$db->query('SELECT `'.implode('`,`',$wanted).'` FROM `'.$schema.'`.eb_system_store_staff WHERE id IN ('.$list.') ORDER BY id')->fetchAll():[];
            foreach(['eb_employee'=>['id','status','is_del','uid'], 'eb_staff_job_position'=>['id','employee_id','staff_id','store_id','position_id','status','is_del','start_time','end_time','request_token']] as$table=>$wanted){
                $available=$columns[$schema][$table]??[];
                if(!$available){$out['identity_records'][$schema][$table]=['exists'=>false];continue;}
                // Full structural inventory is small and has no names or login
                // fields. It proves candidate ID collisions and multiple jobs.
                $fields=array_values(array_intersect($wanted,$available));
                $out['identity_records'][$schema][$table]=['exists'=>true,'rows'=>$db->query('SELECT `'.implode('`,`',$fields).'` FROM `'.$schema.'`.`'.$table.'` ORDER BY id')->fetchAll()];
            }
        }
        if(isset($columns['mohe_010_app']['codex_010_migration_batch'])){
            $out['migration_batches']=$db->query('SELECT batch_key,source_schema,source_archive_sha256,committed_at FROM mohe_010_app.codex_010_migration_batch ORDER BY batch_key')->fetchAll();
        }
        // Explicit account/person links only; return stable UID relationships,
        // never an account, credential, name or phone-based correlation.
        $out['existing_uid_employee_links']=$db->query('SELECT s.id staff_id,s.uid,e.id employee_id FROM mohe_010_app.eb_system_store_staff s JOIN mohe_010_app.eb_employee e ON e.uid=s.uid WHERE s.id IN ('.$list.') AND s.uid>0 ORDER BY s.id,e.id')->fetchAll();
        echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    } finally {if($db->inTransaction())$db->rollBack();}
} catch(Throwable $e){echo '{"error_code":"READ_ONLY_010_IDENTITY_PROBE_FAILED","remote_writes":false}',"\n";exit(1);}

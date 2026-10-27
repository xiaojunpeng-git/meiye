<?php
declare(strict_types=1);

/** Exact batch-bound 010 source only. Structural ID evidence and phone equality
 * counts may leave the server; names, phone values and credentials may not.
 * No services, writes, account provisioning or historical fact updates. */
ini_set('display_errors','0'); ini_set('log_errors','0');
set_error_handler(static function (): void {throw new RuntimeException('SUPPLEMENT_IO_FAILED');});
try {
    $env=array_change_key_case(parse_ini_file('/www/wwwroot/010.cc3798.com/.env',true,INI_SCANNER_RAW),CASE_LOWER);
    $app=array_change_key_case($env['database']??[],CASE_LOWER);
    if(($app['database']??'')!=='mohe_010_app'||(int)($app['hostport']??0)!==3307)throw new RuntimeException('010_REQUIRED');
    $ini=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW); $cfg=array_change_key_case($ini['client']??[],CASE_LOWER);
    if(!in_array($cfg['host']??'127.0.0.1',['127.0.0.1','localhost'],true)||(int)($cfg['port']??3307)!==3307)throw new RuntimeException('FIXED_ENDPOINT_REQUIRED');
    $dsn=!empty($cfg['socket'])?'mysql:unix_socket='.$cfg['socket']:'mysql:host='.($cfg['host']??'127.0.0.1').';port=3307';
    $db=new PDO($dsn.';dbname=mohe_010_app;charset=utf8mb4',$cfg['user'],$cfg['password']??'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    try {
        $id=$db->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch();
        if($id['db']!=='mohe_010_app'||(int)$id['port']!==3307||(int)$id['ro']!==1)throw new RuntimeException('WRONG_DATABASE');
        // Do not touch the additional source until its exact batch identity and
        // immutable archive binding are established in the target database.
        $batch=$db->query("SELECT batch_key,source_schema,source_archive_sha256,committed_at FROM mohe_010_app.codex_010_migration_batch ORDER BY batch_key")->fetchAll();
        if(count($batch)!==1||$batch[0]['batch_key']!=='010-linlin-20261023-refresh-1'
            ||$batch[0]['source_schema']!=='mohe_010_refresh'
            ||$batch[0]['source_archive_sha256']!=='970dfd1c383862e30dd668bd6dbfec0805a996dc23d1a5673f70283058f8eb36')throw new RuntimeException('EXACT_SOURCE_BATCH_REQUIRED');
        $out=['site'=>'010','verifiedAt'=>gmdate('c'),'database'=>$id,'migration_batch'=>$batch[0],
            'source_whitelist_verified_before_access'=>true,'read_databases'=>['mohe_010_app','mohe_010_refresh'],
            'remote_writes'=>false,'application_booted'=>false,'pii_values_returned'=>false,'mapping_applied'=>false];
        $out['source_staff_count']=(int)$db->query('SELECT COUNT(*) FROM mohe_010_refresh.eb_system_store_staff')->fetchColumn();
        $out['source_missing_target_staff_count']=(int)$db->query('SELECT COUNT(*) FROM mohe_010_refresh.eb_system_store_staff s LEFT JOIN mohe_010_app.eb_system_store_staff t ON t.id=s.id WHERE t.id IS NULL')->fetchColumn();
        // UID may intentionally be member-merged by the original import; raw
        // equality and equality through that explicit map are reported apart.
        $out['staff']=$db->query("SELECT t.id staff_id,t.store_id,t.status,t.is_del,t.employee_id,t.position,
            s.id source_staff_id,
            (t.store_id<=>s.store_id) store_equal,(t.status<=>s.status) status_equal,
            (t.is_del<=>s.is_del) deletion_equal,(t.position<=>s.position) position_equal,
            (t.add_time<=>s.add_time) add_time_equal,(t.uid<=>s.uid) uid_raw_equal,
            (t.uid<=>COALESCE(m.target_uid,s.uid)) uid_import_map_equal,
            (t.phone<=>s.phone) phone_preserved,
            (e.id IS NOT NULL) linked_employee_exists,
            (same_id.id IS NOT NULL) same_id_employee_exists,
            (SELECT COUNT(*) FROM mohe_010_app.eb_system_store_staff z WHERE z.employee_id=t.id AND z.id<>t.id) same_id_employee_other_staff_links,
            (SELECT COUNT(*) FROM mohe_010_app.eb_staff_job_position j WHERE j.staff_id=t.id) existing_job_count,
            (SELECT COUNT(*) FROM mohe_010_app.eb_staff_job_position j WHERE j.staff_id=t.id AND j.employee_id<>t.id) conflicting_job_count,
            (s.phone IS NULL OR TRIM(s.phone)='' OR TRIM(s.phone)='0') source_phone_blank,
            (s.phone REGEXP '^1[3-9][0-9]{9}$') source_phone_mobile_valid,
            (SELECT COUNT(*) FROM mohe_010_app.eb_employee p WHERE s.phone IS NOT NULL AND TRIM(s.phone)<>'' AND TRIM(s.phone)<>'0' AND p.phone=s.phone) employee_phone_match_count,
            (SELECT COUNT(*) FROM mohe_010_app.eb_employee p WHERE s.phone IS NOT NULL AND TRIM(s.phone)<>'' AND TRIM(s.phone)<>'0' AND p.phone=s.phone AND p.status=1 AND p.is_del=0) active_employee_phone_match_count,
            (SELECT COUNT(*) FROM mohe_010_refresh.eb_system_store_staff z WHERE z.id<>s.id AND s.phone IS NOT NULL AND TRIM(s.phone)<>'' AND TRIM(s.phone)<>'0' AND z.phone=s.phone) source_other_staff_phone_count,
            (SELECT COUNT(*) FROM mohe_010_refresh.eb_system_store_staff z WHERE z.id<>s.id AND s.phone IS NOT NULL AND TRIM(s.phone)<>'' AND TRIM(s.phone)<>'0' AND z.phone=s.phone AND z.status=1 AND z.is_del=0) source_other_active_staff_phone_count
            FROM mohe_010_app.eb_system_store_staff t
            LEFT JOIN mohe_010_refresh.eb_system_store_staff s ON s.id=t.id
            LEFT JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=s.uid
            LEFT JOIN mohe_010_app.eb_employee e ON e.id=t.employee_id
            LEFT JOIN mohe_010_app.eb_employee same_id ON same_id.id=t.id ORDER BY t.id")->fetchAll();
        $out['employee_ids']=$db->query('SELECT id,status,is_del FROM mohe_010_app.eb_employee ORDER BY id')->fetchAll();
        $out['employee_indexes']=$db->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='mohe_010_app' AND TABLE_NAME='eb_employee' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll();
        // Historical reference preservation is checked by immutable source PK,
        // not employee guesses, names, recalculation or import execution.
        $tables=['eb_staff_yeji','eb_salary_info','eb_salary_store','eb_staff_flowing_water','eb_store_order','eb_store_order_writeoff','eb_store_order_cart_info'];
        foreach($tables as$table){
            $stmt=$db->prepare("SELECT TABLE_SCHEMA,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA IN ('mohe_010_app','mohe_010_refresh') AND TABLE_NAME=?");$stmt->execute([$table]);$cols=[];
            foreach($stmt->fetchAll()as$c)$cols[$c['TABLE_SCHEMA']][]=$c['COLUMN_NAME'];
            $refs=array_values(array_intersect(['staff_id','service_staff_id','gendan_staff_id'], $cols['mohe_010_refresh']??[], $cols['mohe_010_app']??[]));
            if(!$refs||!in_array('id',$cols['mohe_010_app']??[],true)||!in_array('id',$cols['mohe_010_refresh']??[],true)){$out['historical_staff_references'][$table]=['checked'=>false,'reason'=>'No shared stable PK/staff reference columns'];continue;}
            $mismatch=implode(' OR ',array_map(static function($c){return 'NOT(t.`'.$c.'`<=>s.`'.$c.'`)';},$refs));
            $sql='SELECT COUNT(*) source_rows,SUM(t.id IS NULL) missing_target_rows,SUM(t.id IS NOT NULL AND ('.$mismatch.')) changed_staff_reference_rows FROM mohe_010_refresh.`'.$table.'` s LEFT JOIN mohe_010_app.`'.$table.'` t ON t.id=s.id';
            $row=$db->query($sql)->fetch();
            $row['extra_target_rows']=(int)$db->query('SELECT COUNT(*) FROM mohe_010_app.`'.$table.'` t LEFT JOIN mohe_010_refresh.`'.$table.'` s ON s.id=t.id WHERE s.id IS NULL')->fetchColumn();
            $out['historical_staff_references'][$table]=array_merge(['checked'=>true,'columns'=>$refs],$row);
        }
        echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    }finally{if($db->inTransaction())$db->rollBack();}
}catch(Throwable $e){echo '{"error_code":"READ_ONLY_010_IDENTITY_SUPPLEMENT_FAILED","remote_writes":false}',"\n";exit(1);}

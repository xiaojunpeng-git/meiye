<?php
declare(strict_types=1);

/** Real local MySQL integration, synthetic data only. Run by concatenating the
 * canonical planner + applier + this test to Docker PHP stdin (strip repeated
 * PHP opening/strict declarations). No permanent second library copy is made.
 * Only a uniquely named local fixture DB is created and finally dropped.
 */
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || getenv('R51_IDENTITY_LOCAL_TEST')!=='1')
    throw new RuntimeException('EXPLICIT_LOCAL_DOCKER_TEST_REQUIRED');
if (!class_exists('R51StaffIdentityApplier', false))
    require_once dirname(__DIR__,3).'/scripts/lib/r51-010-staff-identity-apply.php';
require '/var/www/html/vendor/autoload.php';
(new \think\App())->initialize();
// ThinkPHP's CLI handler may return exit 0 for an uncaught exception. A failing
// permanent test must fail the process without printing SQL/profile values.
set_exception_handler(static function (Throwable $error): void {
    $code=$error instanceof RuntimeException && !($error instanceof PDOException)
        && preg_match('/^[A-Za-z0-9_]+$/D',$error->getMessage()) ? $error->getMessage() : 'LOCAL_IDENTITY_TEST_FAILED';
    file_put_contents('php://stderr',json_encode(['result'=>'FAIL','code'=>$code]).PHP_EOL);
    exit(1);
});
$cfg=config('database.connections.mysql');
if (!in_array($cfg['hostname'],['mohe-mysql','mysql'],true)
    || !preg_match('/^[a-zA-Z0-9_]+$/D', $cfg['database'])) throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$connect=static function (string $schema) use ($cfg): PDO {
    return new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['hostport'].';dbname='.$schema.';charset=utf8mb4',
        $cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>true]);
};
$admin=$connect($cfg['database']); $database='r51_identity_test_'.bin2hex(random_bytes(6));
$checks=0;
$check=static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('TEST_FAILED_'.$label);
    ++$checks;
};
$reject=static function (callable $fn, string $code) use ($check): void {
    try { $fn(); } catch (RuntimeException $error) { $check($error->getMessage()===$code,$code); return; }
    throw new RuntimeException('EXPECTED_REJECTION_'.$code);
};
$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    foreach (['eb_employee','eb_system_store_staff','eb_staff_job_position'] as $table)
        $admin->exec('CREATE TABLE `'.$database.'`.`'.$table.'` LIKE `'.$cfg['database'].'`.`'.$table.'`');
    $db=$connect($database);
    $rows=static function (string $table) use ($db): array { return $db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(); };
    $insert=static function (string $table, array $row) use ($db): void {
        $q=$db->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
        $q->execute(array_values($row));
    };
    $reset=static function () use ($db): void {
        foreach (['eb_system_store_staff','eb_employee','eb_staff_job_position'] as $table) $db->exec('DELETE FROM '.$table);
    };
    $staff=static function (int $id, array $replace=[]) : array {
        return array_replace(['id'=>$id,'store_id'=>1,'uid'=>901,'phone'=>'13900000019','staff_name'=>'Synthetic name',
            'avatar'=>'/synthetic.png','status'=>1,'is_del'=>0,'position'=>4,'add_time'=>100,'employee_id'=>null,
            'account'=>'UNCHANGED_ACCOUNT','pwd'=>'UNCHANGED_HASH','roles'=>'UNCHANGED_ROLES'],$replace);
    };
    $plan=static function (array $source) use ($rows): array {
        return R51StaffIdentityPlanner::plan($source,$rows('eb_system_store_staff'),$rows('eb_employee'),$rows('eb_staff_job_position'));
    };
    $apply=static function (array $source, ?string $hash=null) use ($db,$database,$plan): array {
        $hash=$hash??$plan($source)['report']['input_sha256'];
        return R51StaffIdentityApplier::apply($db,$source,$hash,[$database]);
    };
    $snapshot=static function () use ($rows): array {
        return [$rows('eb_system_store_staff'),$rows('eb_employee'),$rows('eb_staff_job_position')];
    };

    // Same exact phone unifies people across stores/statuses; existing identity
    // is reused unchanged even if inactive/deleted and linked to an existing UID.
    $insert('eb_system_store_staff',$staff(19));
    $insert('eb_system_store_staff',$staff(24,['store_id'=>2,'status'=>0,'is_del'=>1,'uid'=>902]));
    $insert('eb_system_store_staff',$staff(31,['phone'=>'13900000031','status'=>0,'is_del'=>1,'employee_id'=>0]));
    $insert('eb_system_store_staff',$staff(61,['phone'=>'13900000061','store_id'=>3]));
    $insert('eb_system_store_staff',$staff(62,['phone'=>'13900000061','store_id'=>4,'status'=>0]));
    $insert('eb_employee',['id'=>70,'name'=>'Existing profile','phone'=>'13900000061','uid'=>777,'status'=>0,'is_del'=>1,
        'employment_type_code'=>'partner','status_version'=>12,'employment_type_version'=>13,'auth_version'=>9]);
    $insert('eb_staff_job_position',['id'=>1,'staff_id'=>61,'employee_id'=>70,'store_id'=>3,'position_id'=>8,'request_token'=>'keep-job']);
    $source=$rows('eb_system_store_staff'); $before=$snapshot();
    $result=$apply($source); $after=$snapshot();
    $check($result['created_employees']===2 && $result['linked_staff']===5,'exact_operation_counts');
    $people=array_column($after[1],null,'id');$targets=array_column($after[0],null,'id');
    $check($targets[19]['employee_id']==='19' && $targets[24]['employee_id']==='19','duplicate_phone_single_identity');
    $check($targets[61]['employee_id']==='70' && $targets[62]['employee_id']==='70','existing_employee_reused');
    $check($people[70]===$before[1][0],'existing_profile_uid_status_versions_unchanged');
    $check($people[19]['uid']===null && $people[31]['uid']===null,'new_employee_uid_null');
    $check($people[19]['status']==='1' && $people[19]['is_del']==='0'
        && $people[31]['status']==='0' && $people[31]['is_del']==='1','group_and_departed_states_preserved');
    $check($people[19]['name']==='Synthetic name' && $people[19]['avatar']==='/synthetic.png','source_profile_copied');
    $check($people[19]['employment_type_code']==='internal' && $people[19]['status_version']==='0'
        && $people[19]['employment_type_version']==='0' && $people[19]['auth_version']==='1','internal_and_schema_version_defaults');
    foreach ($before[0] as $index=>$row) {
        $post=$after[0][$index];unset($row['employee_id'],$post['employee_id'],$row['active_employee_id'],$post['active_employee_id']);
        $check($row===$post,'staff_business_account_permissions_unchanged');
    }
    $check($before[2]===$after[2],'jobs_byte_equal');
    $post=$plan($source);$check(!$post['private']['employee_inserts'] && !$post['private']['staff_links'],'post_plan_zero');
    $replay=$apply($source);$check($replay['created_employees']===0 && $replay['linked_staff']===0 && $snapshot()===$after,'idempotent_with_fresh_hash');
    $encoded=json_encode($result);foreach (['13900000019','Synthetic name','UNCHANGED_ACCOUNT','UNCHANGED_HASH','UNCHANGED_ROLES'] as $secret)
        $check(strpos($encoded,$secret)===false,'pii_free_receipt');
    $changed=$source;$changed[0]['unknown_source_field']='changed';
    $reject(static function () use ($apply,$changed,$post) { $apply($changed,$post['report']['input_sha256']); },'R51_IDENTITY_INPUT_CHANGED');
    $check($snapshot()===$after && !$db->inTransaction(),'source_drift_rollback');
    $reject(static function () use ($db,$source,$post) {
        R51StaffIdentityApplier::apply($db,$source,$post['report']['input_sha256'],['arbitrary_database']);
    },'R51_IDENTITY_DATABASE_NOT_ALLOWED');
    $db->beginTransaction();
    $reject(static function () use ($apply,$source) { $apply($source,str_repeat('a',64)); },'R51_IDENTITY_OUTER_TRANSACTION_FORBIDDEN');
    $check($db->inTransaction(),'outer_transaction_not_rolled_back');$db->rollBack();

    // A target-only field/employee/job drift is part of the full expected hash.
    $hash=$plan($source)['report']['input_sha256'];$db->exec("UPDATE eb_system_store_staff SET roles='CONCURRENT' WHERE id=19");
    $drift=$snapshot();$reject(static function () use ($apply,$source,$hash) {$apply($source,$hash);},'R51_IDENTITY_INPUT_CHANGED');
    $check($snapshot()===$drift,'target_drift_preserved');
    $hash=$plan($source)['report']['input_sha256'];$db->exec("UPDATE eb_employee SET name='CONCURRENT' WHERE id=70");
    $drift=$snapshot();$reject(static function () use ($apply,$source,$hash) {$apply($source,$hash);},'R51_IDENTITY_INPUT_CHANGED');
    $check($snapshot()===$drift,'employee_full_field_drift_preserved');
    $hash=$plan($source)['report']['input_sha256'];$db->exec("UPDATE eb_staff_job_position SET reason='CONCURRENT' WHERE id=1");
    $drift=$snapshot();$reject(static function () use ($apply,$source,$hash) {$apply($source,$hash);},'R51_IDENTITY_INPUT_CHANGED');
    $check($snapshot()===$drift,'job_full_field_drift_preserved');

    $reset();$insert('eb_system_store_staff',$staff(19));$source=$rows('eb_system_store_staff');
    $insert('eb_employee',['id'=>19,'phone'=>'13900000099','name'=>'ID collision']);$before=$snapshot();
    $reject(static function () use ($apply,$source) {$apply($source);},'R51_IDENTITY_PLAN_BLOCKED_OR_ISOLATED');
    $check($snapshot()===$before && !$db->inTransaction(),'id_collision_atomic_rejection');
    $reset();$insert('eb_system_store_staff',$staff(19,['phone'=>'invalid']));$source=$rows('eb_system_store_staff');$before=$snapshot();
    $reject(static function () use ($apply,$source) {$apply($source);},'R51_IDENTITY_PLAN_BLOCKED_OR_ISOLATED');
    $check($snapshot()===$before,'nonfatal_isolation_also_refuses_whole_batch');

    // Real SQL failure after two employee inserts and the first staff link:
    // no callback/mock bypasses the executor's transaction rollback boundary.
    $reset();$insert('eb_system_store_staff',$staff(19));
    $insert('eb_system_store_staff',$staff(24,['phone'=>'13900000024']));
    $source=$rows('eb_system_store_staff');$before=$snapshot();
    $db->exec("CREATE TRIGGER r51_identity_fail BEFORE UPDATE ON eb_system_store_staff FOR EACH ROW
        BEGIN IF NEW.id=24 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_midway_failure'; END IF; END");
    try {
        $reject(static function () use ($apply,$source) {$apply($source);},'R51_IDENTITY_WRITE_FAILED');
        $check($snapshot()===$before && !$db->inTransaction(),'midway_failure_full_rollback');
    } finally { $db->exec('DROP TRIGGER r51_identity_fail'); }
    $result=$apply($source);$check($result['created_employees']===2 && $result['linked_staff']===2,'retry_after_rollback');
    $check($plan($source)['private']===['employee_inserts'=>[],'staff_links'=>[]],'final_plan_zero');
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    $result=$apply($source);
    $check($result['created_employees']===0 && $result['linked_staff']===0,'native_prepares_with_fresh_full_input_hash');
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $db=null;
    $admin->exec('DROP DATABASE `'.$database.'`');
}
$check((int)$admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=".$admin->quote($database))->fetchColumn()===0,'fixture_database_cleaned');
echo json_encode(['result'=>'PASS','checks'=>$checks,'local_mysql'=>true,'temporary_database_removed'=>true,
    'remote_connections'=>false,'business_databases_written'=>false],JSON_THROW_ON_ERROR),PHP_EOL;

<?php
declare(strict_types=1);
/** Four proven missing member-store links only. No member, wallet or card updates. */
require_once __DIR__.'/plan.php';

/** Stable full row hashes guard rollback against edits after this repair. */
function relationRepairHash(array $value): string {
    return hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
/** Query through the same PDO transaction; lock all uid ranges, including absent links. */
function relationRepairState(PDO $db,bool $lock): array {
    $suffix=$lock?' FOR UPDATE':'';
    $members=$db->query('SELECT uid,belong_store_id,status,is_del,delete_time FROM eb_user WHERE uid IN (975,6787,9052,9647) ORDER BY uid'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
    $relations=$db->query('SELECT * FROM eb_store_user WHERE uid IN (975,6787,9052,9647) ORDER BY uid,id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
    return ['members'=>$members,'relations'=>$relations];
}
/** Prepared receipt is durable before commit; failed receipt writes must roll back SQL. */
function relationRepairReceipt(string $path,array $receipt): void {
    if($path===''||$path[0]!=='/'||is_link($path)||!is_dir(dirname($path)))throw new RuntimeException('RECEIPT_PATH_INVALID');
    $bytes=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    $temp=$path.'.writing-'.bin2hex(random_bytes(6));
    $f=fopen($temp,'x');if(!$f)throw new RuntimeException('RECEIPT_CREATE_FAILED');
    chmod($temp,0600);
    try {if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f))throw new RuntimeException('RECEIPT_WRITE_FAILED');}
    finally {fclose($f);}
    if(!rename($temp,$path)||file_get_contents($path)!==$bytes)throw new RuntimeException('RECEIPT_VERIFY_FAILED');
    // PHP7.4 has no fsync(): use a fixed Python primitive, with shell-quoted path.
    $python='import os,sys; f=os.open(sys.argv[1],os.O_RDONLY); os.fsync(f); os.close(f); d=os.open(os.path.dirname(sys.argv[1]),os.O_RDONLY); os.fsync(d); os.close(d)';
    exec('python3 -c '.escapeshellarg($python).' '.escapeshellarg($path).' 2>/dev/null',$ignored,$status);
    if($status!==0)throw new RuntimeException('RECEIPT_FSYNC_FAILED');
}
/** Apply is exact-plan only; retries use the receipt, never silently adopt a changed plan. */
function relationRepairApply(PDO $db,string $expected,string $receiptPath,callable $persist):array {
    if($db->inTransaction())throw new RuntimeException('OUTER_TRANSACTION_NOT_ALLOWED');
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
    try {
        $state=relationRepairState($db,true);$plan=report010MemberRelationPlan($state['members'],$state['relations']);
        if(is_file($receiptPath)){
            $receipt=json_decode(file_get_contents($receiptPath),true,512,JSON_THROW_ON_ERROR);
            if(($receipt['kind']??'')!=='010-four-member-relation'||($receipt['expected']??'')!==$expected)throw new RuntimeException('RECEIPT_IDENTITY_MISMATCH');
            if(relationRepairHash($state)===($receipt['after_hash']??'')){$db->rollBack();return ['status'=>'already_applied','inserted'=>count($receipt['inserted'])];}
            throw new RuntimeException('EXISTING_RECEIPT_REQUIRES_RECONCILIATION');
        }
        if(!hash_equals($expected,$plan['before_sha256'])||$plan['blocked'])throw new RuntimeException('PLAN_CHANGED_OR_BLOCKED');
        if(!$plan['insert']){$db->rollBack();return ['status'=>'already_present','inserted'=>0];}
        $inserted=[];$q=$db->prepare('INSERT INTO eb_store_user(uid,store_id,status,label_id,add_time) VALUES(?,?,1,NULL,?)');
        foreach($plan['insert'] as $item){
            $q->execute([$item['uid'],$item['store_id'],time()]);$id=(int)$db->lastInsertId();
            $row=$db->query('SELECT * FROM eb_store_user WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['uid']!==$item['uid']||(int)$row['store_id']!==4)throw new RuntimeException('INSERT_VERIFY_FAILED');
            $inserted[]=$row;
        }
        $after=relationRepairState($db,true);
        $post=report010MemberRelationPlan($after['members'],$after['relations']);
        if($post['insert']||$post['blocked']||count($post['already_present'])!==4||$state['members']!==$after['members'])throw new RuntimeException('POST_VERIFY_FAILED');
        $receipt=['kind'=>'010-four-member-relation','expected'=>$expected,'status'=>'prepared_before_commit','before'=>$state,'after_hash'=>relationRepairHash($after),'inserted'=>$inserted];
        $persist($receiptPath,$receipt);$db->commit();
        return ['status'=>'applied','inserted'=>count($inserted),'after_hash'=>$receipt['after_hash']];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Rollback only removes this receipt's unchanged inserts; no existing link is touched. */
function relationRepairRollback(PDO $db,string $receiptPath):array {
    $r=json_decode(file_get_contents($receiptPath),true,512,JSON_THROW_ON_ERROR);
    if(($r['kind']??'')!=='010-four-member-relation'||empty($r['inserted'])||empty($r['before']))throw new RuntimeException('INVALID_RECEIPT');
    if($db->inTransaction())throw new RuntimeException('OUTER_TRANSACTION_NOT_ALLOWED');
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
    try {
        $state=relationRepairState($db,true);
        if(relationRepairHash($state)===relationRepairHash($r['before'])){$db->rollBack();return ['status'=>'already_before'];}
        if(relationRepairHash($state)!==$r['after_hash'])throw new RuntimeException('ROLLBACK_STATE_CHANGED');
        $delete=$db->prepare('DELETE FROM eb_store_user WHERE id=? AND uid=? AND store_id=4');
        foreach($r['inserted'] as $row){$delete->execute([$row['id'],$row['uid']]);if($delete->rowCount()!==1)throw new RuntimeException('ROLLBACK_ROW_CHANGED');}
        if(relationRepairHash(relationRepairState($db,true))!==relationRepairHash($r['before']))throw new RuntimeException('ROLLBACK_VERIFY_FAILED');
        $db->commit();return ['status'=>'rolled_back','removed'=>count($r['inserted'])];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(defined('RELATION_REPAIR_LIBRARY_ONLY'))return;
try {
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI_ONLY');
    $options=getopt('',['dry-run','apply','rollback','expected-plan:','receipt:']);
    if(count(array_intersect(['dry-run','apply','rollback'],array_keys($options)))>1)throw new RuntimeException('ONE_MODE_ONLY');
    $root='/www/wwwroot/010.cc3798.com';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
    $config=config('database.connections.mysql');
    if(($config['database']??'')!=='mohe_010_app'||(string)($config['hostport']??'')!=='3307'||($config['prefix']??'')!=='eb_'||!in_array($config['hostname']??'',['127.0.0.1','localhost'],true))throw new RuntimeException('TARGET_IDENTITY_MISMATCH');
    think\facade\Db::query('SELECT DATABASE() AS db');$db=think\facade\Db::connect()->getPdo();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $mode=isset($options['apply'])?'apply':(isset($options['rollback'])?'rollback':'dry-run');
    foreach($db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_user','eb_store_user')")->fetchAll(PDO::FETCH_ASSOC)as $table)if(strtoupper($table['ENGINE'])!=='INNODB')throw new RuntimeException('INNODB_REQUIRED');
    if($mode==='dry-run'){$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();$s=relationRepairState($db,false);$result=report010MemberRelationPlan($s['members'],$s['relations']);$db->rollBack();}
    else {
        $path=(string)($options['receipt']??'');$parent=realpath(dirname($path));
        if(!$parent||strpos($path,'..')!==false||is_link($path)
            ||(strpos($parent.'/', '/www/backups/010/')!==0&&strpos($parent.'/', '/root/010-history-backups/')!==0))throw new RuntimeException('INDEPENDENT_RECEIPT_REQUIRED');
        $path=$parent.'/'.basename($path);
        $lock=$db->query("SELECT GET_LOCK('010-report-member-relation-repair',5)")->fetchColumn();if((int)$lock!==1)throw new RuntimeException('LOCK_BUSY');
        try{$result=$mode==='apply'?relationRepairApply($db,(string)($options['expected-plan']??''),$path,'relationRepairReceipt'):relationRepairRollback($db,$path);}
        finally{$db->query("SELECT RELEASE_LOCK('010-report-member-relation-repair')");}
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}

<?php
declare(strict_types=1);

/** Read-only 010 verification of the canonical proposed provider in memory.
 * Never uploads PHP, changes grants, executes a command or writes member data.
 * Only counts, result flags and hashes leave the server, not member details. */
ini_set('display_errors','0');
ini_set('log_errors','0');
try {
    $request=json_decode(base64_decode('__R51_BALANCE_READ_PROBE__'),true,512,JSON_THROW_ON_ERROR);
    $source=base64_decode($request['source']??'',true);
    if (!is_string($source) || hash('sha256',$source)!==($request['sha256']??'')
        || strpos($source,'final class CashierV3MemberBalanceProvider')===false)
        throw new RuntimeException('FIXED_SOURCE_REQUIRED');
    $root='/www/wwwroot/010.cc3798.com';
    require $root.'/vendor/autoload.php';
    (new think\App($root.'/'))->initialize();
    $cfg=config('database.connections.mysql');
    if ($cfg['database']!=='mohe_010_app' || (int)$cfg['hostport']!==3307
        || !in_array($cfg['hostname'],['localhost','127.0.0.1'],true)) throw new RuntimeException('010_ONLY');
    $class='app\\services\\cashier\\v3\\checkout\\provider\\CashierV3MemberBalanceProvider';
    if (class_exists($class,false)) throw new RuntimeException('PROVIDER_ALREADY_LOADED');
    eval(substr($source,5));
    $connection=think\facade\Db::connect();
    $connection->query('SELECT 1'); $db=$connection->getPdo();
    $db->exec('SET SESSION TRANSACTION READ ONLY'); $db->exec('SET TRANSACTION READ ONLY');
    $connection->startTrans();
    try {
        $id=$db->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch(PDO::FETCH_ASSOC);
        if ($id['db']!=='mohe_010_app' || (int)$id['port']!==3307 || (int)$id['ro']!==1)
            throw new RuntimeException('READ_ONLY_IDENTITY_REQUIRED');
        $grants=$db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN); sort($grants,SORT_STRING);
        $grantsBefore=hash('sha256',json_encode($grants,JSON_THROW_ON_ERROR));
        $member=$db->query('SELECT u.uid,u.now_money,u.ben_money,u.give_money,u.balance_version
            FROM eb_user u JOIN eb_store_user s ON s.uid=u.uid AND s.store_id=1 AND s.status=1
            WHERE u.status=1 AND u.is_del=0 AND u.delete_time IS NULL AND u.balance_version>0
            AND u.now_money=u.ben_money+u.give_money ORDER BY u.uid LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$member) throw new RuntimeException('VISIBLE_REAL_MEMBER_REQUIRED');
        $uid=(int)$member['uid']; $before=hash('sha256',json_encode($member,JSON_THROW_ON_ERROR));
        $scope=new app\services\cashier\v3\CashierV3DataScopeContext(1,0,1,'0','',[1],'stores',[],false,'','r51-balance-read-probe',[],[],true);
        $operator=new app\services\cashier\v3\CashierV3OperatorScope(1,1,'','0');
        $provider=new $class(); $readStatus=$provider->readReadinessStatus(); $writeStatus=$provider->readinessStatus();
        $single=$provider->readSnapshot($uid,$operator,$scope);
        $batch=$provider->readSnapshots([$uid],$operator,$scope);
        $report=$provider->readAiBalanceCents([$uid],[1]);
        if (!$readStatus['ready'] || $writeStatus['ready'] || $batch[$uid]!==$single
            || $report[$uid]!==$single['totalCents'] || $single['principalCents']+$single['giftCents']!==$single['totalCents'])
            throw new RuntimeException('BALANCE_READ_PARITY_FAILED');
        $query=$db->prepare('SELECT uid,now_money,ben_money,give_money,balance_version FROM eb_user WHERE uid=?');
        $query->execute([$uid]); $after=hash('sha256',json_encode($query->fetch(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
        $grants=$db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN); sort($grants,SORT_STRING);
        if ($before!==$after || $grantsBefore!==hash('sha256',json_encode($grants,JSON_THROW_ON_ERROR)))
            throw new RuntimeException('MEMBER_OR_GRANTS_CHANGED');
        echo json_encode(['result'=>'PASS','site'=>'010','source_sha256'=>$request['sha256'],
            'read_only'=>true,'single_batch_report_match'=>true,'money_conserved'=>true,
            'read_schema_ready'=>$readStatus['ready'],'mutation_readiness_still_blocked'=>$writeStatus['ready']===false,
            'member_row_before_sha256'=>$before,'member_row_after_sha256'=>$after,
            'application_grants_unchanged'=>true,'business_writes'=>false,'deployed'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
    } finally { $connection->rollback(); }
} catch (Throwable $e) {
    $safe=preg_match('/^[A-Z][A-Z_]+$/D',$e->getMessage())?$e->getMessage():'READ_ONLY_BALANCE_PROBE_FAILED';
    echo json_encode(['result'=>'FAIL','error'=>$safe,'class'=>get_class($e),'business_writes'=>false]),PHP_EOL;
    exit(1);
}

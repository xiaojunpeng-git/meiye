<?php
declare(strict_types=1);

/** Instance-bound, read-only debt release probe. No credential, member identity,
 * debt amount or contact data is exported; actual query results stay on host. */
$key=$argv[1]??getenv('DEBT_PROBE_SITE');$mode=$argv[2]??getenv('DEBT_PROBE_MODE');
$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($sites[$key])||!in_array($mode,['snapshot','behavior'],true))throw new RuntimeException('Explicit site and mode required');
[$domain,$database,$port]=$sites[$key];$root='/www/wwwroot/'.$domain;
$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($env['DATABASE']??$env['database']??[]);
if(($c['database']??'')!==$database||(int)$c['hostport']!==$port||!in_array($c['hostname'],['localhost','127.0.0.1'],true))throw new RuntimeException('Wrong instance');
if($mode==='snapshot'){
    $pdo=new PDO('mysql:host='.$c['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('START TRANSACTION READ ONLY');
    $out=['site'=>$key,'database'=>$database,'databaseVersion'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'environment'=>hash_file('sha256',$root.'/.env'),'files'=>[],'data'=>[]];
    foreach(['app/controller/api/v1/order/StoreDebt.php','app/services/order/StoreDebtServices.php','route/api.php'] as $f)$out['files'][$f]=hash_file('sha256',$root.'/'.$f);
    foreach(['public/index.html','public/system.html','public/view_cashier_v3/index.html'] as $f){
        $out['files'][$f]=is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null;
        if(!is_file($root.'/'.$f))continue;
        preg_match_all('~(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))~',file_get_contents($root.'/'.$f),$m);
        foreach(array_unique($m[1]) as $asset){
            if(preg_match('~^(?:https?:)?//~',$asset))continue;
            $path=$asset[0]==='/'?$root.'/public'.$asset:dirname($root.'/'.$f).'/'.$asset;
            $out['assets'][$f][$asset]=is_file($path)?hash_file('sha256',$path):null;
        }
    }
    foreach(['eb_store_debt','eb_store_debt_item','eb_store_debt_repay','eb_system_config','eb_diy'] as $t){
        $h=hash_init('sha256');$n=0;$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=$pdo->query('SELECT * FROM '.$t.' ORDER BY id');
        while($row=$q->fetch(PDO::FETCH_ASSOC)){hash_update($h,json_encode($row)."\n");$n++;}
        $q->closeCursor();$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);$out['data'][$t]=['rows'=>$n,'sha256'=>hash_final($h)];
    }
    $out['upgrade']=$pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $pdo->exec('ROLLBACK');
}else{
    require $root.'/vendor/autoload.php';
    $app=new think\App($root.'/');$app->env->load($root.'/.env');$app->initialize();
    if(config('database.connections.mysql.database')!==$database)throw new RuntimeException('Runtime database differs');
    $db=think\facade\Db::connect();$db->execute('START TRANSACTION READ ONLY');
    try{
        $uids=$db->query('SELECT DISTINCT uid FROM eb_store_debt WHERE uid>0 AND status=0 ORDER BY uid LIMIT 2');
        $subjects=array_map(static fn($r)=>(int)$r['uid'],$uids);$subjects[]=-2147483647;
        $controller=new app\controller\api\v1\order\StoreDebt();$services=$app->make(app\services\order\StoreDebtServices::class);$checks=[];
        foreach($subjects as $uid){
            $request=new app\Request();$request->uid=$uid;$app->instance('request',$request);
            $result=$controller->lst($request,$services)->getData();
            if(($result['status']??0)!==200||!is_array($result['data']['list']??null))throw new RuntimeException('Controller list failed');
            $expected=$db->query('SELECT COUNT(*) c FROM eb_store_debt WHERE uid=? AND status=0',[$uid])[0]['c'];
            if((int)$result['data']['count']!==(int)$expected)throw new RuntimeException('Member count mismatch');
            foreach($result['data']['list'] as $row){
                $pending=max(0.0,(float)bcsub((string)$row['total_debt'],(string)$row['repaid_debt'],2));
                if((int)$row['uid']!==$uid||(int)$row['status']!==0||(float)$row['pending_debt']!==$pending)throw new RuntimeException('Scope or amount mismatch');
                $orderId=(int)$row['order_id'];$exists=$orderId>0&&(int)$db->query('SELECT COUNT(*) c FROM eb_store_order WHERE id=?',[$orderId])[0]['c']>0;
                if((bool)$row['can_online_repay']!==$exists)throw new RuntimeException('Repayment guard mismatch');
            }
            $summary=$controller->summary($request,$services)->getData();
            if(($summary['status']??0)!==200)throw new RuntimeException('Existing summary failed');
            $checks[]=['subject'=>$uid<0?'nonexistent-member':'existing-member','authorizedCount'=>(int)$expected,'currentPageRows'=>count($result['data']['list']),'scopeAndAmountValid'=>true,'repaymentGuardValid'=>true,'summaryValid'=>true];
        }
        $out=['site'=>$key,'result'=>'PASS','realControllerServiceAndDatabase'=>true,'readOnlyTransaction'=>true,'authenticatedHttpMemberTested'=>false,'checks'=>$checks,'repaymentActions'=>0];
    }finally{$db->execute('ROLLBACK');}
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;

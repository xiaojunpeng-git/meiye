<?php
declare(strict_types=1);
/** Explicit-instance release inspection. Credentials and customer records stay on their own host; no financial command runs. */
$site=$argv[1]??'';$mode=$argv[2]??'snapshot';
$sites=['rh'=>['rh.cc3798.com','ruihao',3306], '008'=>['008.cc3798.com','mohe_008',3307], '010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($sites[$site])||!in_array($mode,['snapshot','runtime','candidate'],true))throw new RuntimeException('Explicit site/mode required');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
if(($c['database']??'')!==$database||(int)($c['hostport']??3306)!==$port)throw new RuntimeException('Instance database mismatch');
$pdo=new PDO('mysql:host='.$c['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('START TRANSACTION READ ONLY');
$out=['site'=>$site,'domain'=>$domain,'database'=>$database,'version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'environment'=>hash_file('sha256',$root.'/.env')];
$out['migration']=$pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if($mode==='snapshot'){
    $paths=['app/controller/admin/v1/marketing/combination/StoreCombination.php','route/admin.php',
        'app/services/activity/combination','app/services/organization/EmployeeDataScopeServices.php',
        'app/services/organization/OrganizationScopeService.php','app/services/system/SystemRoleServices.php',
        'app/services/order/StoreOrderRefundDomainServices.php','app/services/pay/FuyouCombinationRefundGateway.php',
        'app/jobs/order/OrderPayHandelJob.php','app/jobs/activity/pink/CombinationRecoveryJob.php',
        'app/listener/order/Pay.php','app/services/order/StoreOrderCreateServices.php',
        'app/services/order/StoreOrderDeliveryServices.php','app/services/order/StoreOrderRefundServices.php',
        'mohe/services/wechat/HwcPayService.php',
        'app/controller/api/v1/order/StoreDebt.php'];
    foreach($paths as $rel){
        if(is_dir($root.'/'.$rel))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$rel,FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isFile())$out['backend'][substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());
        }else $out['backend'][$rel]=is_file($root.'/'.$rel)?hash_file('sha256',$root.'/'.$rel):null;
    }
    foreach(['public/system.html','public/index.html','public/view_cashier_v3/index.html'] as $rel)$out['entries'][$rel]=is_file($root.'/'.$rel)?hash_file('sha256',$root.'/'.$rel):null;
    if(is_dir($root.'/public/view_admin'))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/view_admin',FilesystemIterator::SKIP_DOTS)) as $file){
        if($file->isFile())$out['admin_assets'][substr($file->getPathname(),strlen($root.'/public/') )]=hash_file('sha256',$file->getPathname());
    }
    $html=is_file($root.'/public/system.html')?file_get_contents($root.'/public/system.html'):'';
    preg_match_all('~(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))~',$html,$matches);$out['admin_refs']=array_values(array_unique($matches[1]));
    foreach(['store_pink','combination_order_refund','combination_order_fulfillment','combination_group_origin','cashier_v3_report_member_origin_evidence'] as $table){
        $exists=(bool)$pdo->query("SHOW TABLES LIKE 'eb_".$table."'")->fetchColumn();$out['tables'][$table]=['exists'=>$exists];
        if($exists){$out['tables'][$table]['rows']=(int)$pdo->query('SELECT COUNT(*) FROM eb_'.$table)->fetchColumn();$out['tables'][$table]['ddl_sha256']=hash('sha256',$pdo->query('SHOW CREATE TABLE eb_'.$table)->fetch(PDO::FETCH_NUM)[1]);}
    }
}else{
    require $root.'/vendor/autoload.php';$app=new think\App($root.'/');$app->env->load($root.'/.env');$app->initialize();
    if(config('database.connections.mysql.database')!==$database)throw new RuntimeException('Runtime database mismatch');
    // Preflight only the accepted read class in memory; no application file or business row is changed.
    if($mode==='candidate'){
        if(class_exists('app\\services\\activity\\combination\\CombinationGroupListServices'))throw new RuntimeException('Candidate class already exists');
        eval(base64_decode($argv[3]??'',true));
    }
    $db=think\facade\Db::connect();$db->execute('START TRANSACTION READ ONLY');
    try{
        $reader=new app\services\activity\combination\CombinationGroupListServices();$admin=['id'=>1,'level'=>0,'admin_type'=>1];
        $page=$reader->page([],$admin);$export=$reader->page([],$admin,true);
        if(count($page['columns'])!==11||in_array('member_no',array_column($page['columns'],'key'),true)||count($export['export'])!==(int)$page['count'])throw new RuntimeException('List/export contract mismatch');
        $denied=$reader->page([],['id'=>2,'level'=>1,'admin_type'=>1]);if($denied['count']!==0)throw new RuntimeException('Empty grants expanded');
        $out+=['result'=>'PASS','list_count'=>$page['count'],'page_rows'=>count($page['list']),'export_rows'=>count($export['export']),
            'columns'=>array_column($page['columns'],'title'),'deny_all_valid'=>true,'financial_commands'=>0,'authenticated_http_tested'=>false];
    }finally{$db->execute('ROLLBACK');}
}
$pdo->exec('ROLLBACK');echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;

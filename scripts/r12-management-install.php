<?php
/** R12 deployment transaction boundary. Bootstrap and exact customer/database guard are supplied by the release controller. */
$manifest=json_decode(file_get_contents($backup.'/incoming/manifest.json'),true);
if(!$manifest||$manifest['tree']!==$tree)throw new RuntimeException('Fixed payload tree mismatch');
$before=json_decode(file_get_contents($backup.'/guard.json'),true);
function r12AtomicFile(string $source,string $target):void{
    if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);
    $tmp=$target.'.r12-install';if(!copy($source,$tmp))throw new RuntimeException('Copy failed');
    chmod($tmp,0644);chown($tmp,is_file($target)?fileowner($target):'www');chgrp($tmp,is_file($target)?filegroup($target):'www');
    if(!rename($tmp,$target))throw new RuntimeException('Atomic install failed');
}
if($mode==='backup'){
    if(is_file($backup.'/ready.json'))throw new RuntimeException('Backup already fixed');
    foreach($before['files'] as $p=>$h)if((is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null)!==$h)throw new RuntimeException('Live baseline changed '.$p);
    if(hash_file('sha256',$root.'/.env')!==$before['envHash'])throw new RuntimeException('Instance configuration changed');
    $original=[];foreach($manifest['files'] as $p=>$h){
        if(hash_file('sha256',$backup.'/incoming/'.$p)!==$h)throw new RuntimeException('Package fingerprint mismatch '.$p);
        if(is_file($root.'/'.$p)){
            // Hashed/static build assets are immutable; changing an existing asset would break cached older entries.
            if(strpos($p,'public/')===0&&!in_array($p,$manifest['entries'],true)&&hash_file('sha256',$root.'/'.$p)!==$h)throw new RuntimeException('Immutable asset collision '.$p);
            $original[]=$p;
        }
    }
    foreach($manifest['entries'] as $p)foreach(['.gz','.br'] as $suffix)if(is_file($root.'/'.$p.$suffix))$original[]=$p.$suffix;
    $command='tar -czf '.escapeshellarg($backup.'/original-files.tar.gz').' -C '.escapeshellarg($root);
    foreach($original as $p)$command.=' '.escapeshellarg($p);
    exec($command,$unused,$exit);if($exit)throw new RuntimeException('Instance file backup failed');
    $menus=think\facade\Db::name('system_menus')->where('unique_auth','setting-merchant-permissions')->select()->toArray();
    file_put_contents($backup.'/menu-before.json',json_encode($menus,JSON_UNESCAPED_UNICODE));
    file_put_contents($backup.'/ready.json',json_encode(['database'=>$expected,'tree'=>$tree,'original'=>$original,'time'=>time()]));
    echo json_encode(['result'=>'PASS','database'=>$expected,'backup'=>$backup,'files'=>count($original)]);return;
}
if(!is_file($backup.'/ready.json'))throw new RuntimeException('Verified backup required');
if($mode==='install'){
    // Additive migration only: rules/audit tables and this native menu; no historical business or job grant is changed.
    $sql=file_get_contents($backup.'/incoming/'.$manifest['migration']);
    if($cfg['prefix']!=='eb_')throw new RuntimeException('Unexpected table prefix');
    $connection=think\facade\Db::connect();$maintenance=false;
    if($expected==='mohe_010_app'){
        $cnf=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
        $connection=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',$cnf['user']??'root',$cnf['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if($connection->query('SELECT DATABASE()')->fetchColumn()!==$expected)throw new RuntimeException('Maintenance database mismatch');
        $maintenance=true;
    }
    foreach(explode(';',$sql) as $statement)if(trim($statement)!==''){
        if($maintenance)$connection->exec($statement);else $connection->execute($statement);
    }
    foreach($manifest['files'] as $p=>$h)if(!in_array($p,$manifest['entries'],true))r12AtomicFile($backup.'/incoming/'.$p,$root.'/'.$p);
    foreach($manifest['files'] as $p=>$h)if(substr($p,-4)==='.php'){
        exec('/www/server/php/74/bin/php -l '.escapeshellarg($root.'/'.$p),$unused,$exit);if($exit)throw new RuntimeException('PHP syntax failure '.$p);
    }
    echo json_encode(['result'=>'PASS','tree'=>$tree,'database'=>$expected,'migration'=>'additive applied','maintenance'=>$maintenance]);return;
}
if($mode==='switch'){
    // Publish entry points only after backend restart succeeds; retain prior immutable assets for cached clients and rollback.
    foreach($manifest['entries'] as $p){
        r12AtomicFile($backup.'/incoming/'.$p,$root.'/'.$p);
        foreach(['.gz','.br'] as $suffix)if(is_file($root.'/'.$p.$suffix))rename($root.'/'.$p.$suffix,$backup.'/'.str_replace('/','_',$p).$suffix);
        file_put_contents($root.'/'.$p.'.gz',gzencode(file_get_contents($root.'/'.$p),9));chmod($root.'/'.$p.'.gz',0644);
    }
    echo json_encode(['result'=>'PASS','entries'=>$manifest['entries']]);return;
}
if($mode==='verify'){
    foreach($manifest['files'] as $p=>$h)if(!is_file($root.'/'.$p)||hash_file('sha256',$root.'/'.$p)!==$h)throw new RuntimeException('Installed fingerprint mismatch '.$p);
    foreach($before['files'] as $p=>$h)if(!isset($manifest['files'][$p])&&(is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null)!==$h)throw new RuntimeException('Protected file changed '.$p);
    if(hash_file('sha256',$root.'/.env')!==$before['envHash'])throw new RuntimeException('Protected config changed');
    $policy=new app\services\mobile\warehouse\MerchantDataPermissionServices();$snapshot=$policy->snapshot();
    if(count($snapshot['rules'])!==27||!$policy->resolve([401007],'warehouse',$snapshot['rules'])['page'])throw new RuntimeException('Permission runtime mismatch');
    $menu=think\facade\Db::name('system_menus')->where('unique_auth','setting-merchant-permissions')->where('is_del',0)->find();
    if(!$menu||$menu['header']!=='setting')throw new RuntimeException('Native menu missing');
    echo json_encode(['result'=>'PASS','database'=>$expected,'tree'=>$tree,'files'=>count($manifest['files']),'rules'=>count($snapshot['rules']),'nativeMenu'=>true,'protectedUnchanged'=>true]);return;
}
if($mode==='business'){
    // Real read-only path through existing facts and the new projection; no customer benefit, grant or financial command is invoked.
    think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
    try{
        $store=(int)think\facade\Db::name('system_store')->where('is_del',0)->order('id')->value('id');
        if($store<=0)throw new RuntimeException('No store for business verification');
        $board=(new app\services\mobile\warehouse\MobileManagementBoardServices())->read([$store],['start'=>'2026-10-01','end'=>'2026-10-31'],[]);
        $counts=[];foreach(['roleTable','beautyTable'] as $key){
            foreach($board[$key]['rows'] as $row)if($row['category_id']>0&&count(array_filter($row['cells'],static function($c){return (float)$c['value']!=0.0;}))===0)throw new RuntimeException('Zero category visibility regression');
            $counts[$key]=count($board[$key]['rows']);
        }
        echo json_encode(['result'=>'PASS','database'=>$expected,'scopeStoreCount'=>1,'range'=>'2026-10','tableRows'=>$counts,'businessWrites'=>0]);
    }finally{think\facade\Db::rollback();}return;
}
if($mode==='finalize'){
    // Register only after runtime/public checks pass. An existing key can be reused only for identical migration bytes.
    $key='2026-11-06-merchant-data-permissions-r12';$checksum=$manifest['files'][$manifest['migration']];
    $old=think\facade\Db::name('database_upgrade_log')->where('upgrade_key',$key)->find();
    if($old&&$old['sql_checksum']!==$checksum)throw new RuntimeException('Applied migration checksum differs');
    if(!$old)think\facade\Db::name('database_upgrade_log')->insert(['upgrade_key'=>$key,'title'=>'商家端数据指标及页签可见规则','task_file'=>'database/upgrades/2026-11-06-商家端数据权限/02-正式升级.sql','sql_checksum'=>$checksum,'code_version'=>$commit,'executed_at'=>date('Y-m-d H:i:s'),'executed_by'=>'Codex R12','result_note'=>'Verified isolated instance; rollback '.$backup]);
    echo json_encode(['result'=>'PASS','database'=>$expected,'upgrade_key'=>$key,'sql_checksum'=>$checksum]);return;
}
if($mode==='rollback'){
    foreach($manifest['entries'] as $p)foreach(['.gz','.br'] as $suffix)if(is_file($root.'/'.$p.$suffix))unlink($root.'/'.$p.$suffix);
    exec('tar -xzf '.escapeshellarg($backup.'/original-files.tar.gz').' -C '.escapeshellarg($root),$unused,$exit);if($exit)throw new RuntimeException('Restore failed');
    // Additive rules/audit data is retained. Hide only the introduced menu on rollback; never delete administrator edits.
    $old=json_decode(file_get_contents($backup.'/menu-before.json'),true);
    if(!$old)think\facade\Db::name('system_menus')->where('unique_auth','setting-merchant-permissions')->update(['is_show'=>0,'is_del'=>1]);
    echo json_encode(['result'=>'PASS','businessDataDeleted'=>false,'additiveTablesRetained'=>true]);return;
}
throw new RuntimeException('Unknown install mode');

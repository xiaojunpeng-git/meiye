<?php
/** Explicit instance/path allowlist and compare-and-swap install. No database,
 * configuration, other terminals, or historic business data are written here. */
$directory=$argv[1]??''; $mode=$argv[2]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/r95-excel-([a-f0-9]{8})$~',$directory,$match)||!in_array($mode,['install','rollback'],true))throw new RuntimeException('EXPLICIT_SCOPE_REQUIRED');
$site=$match[1]; $root='/www/wwwroot/'.($site==='rh'?'rh.cc3798.com':$site.'.cc3798.com');
$manifest=json_decode(file_get_contents($directory.'/payload/manifest.json'),true);
if($manifest['site']!==$site||substr($manifest['commit'],0,8)!==$match[2])throw new RuntimeException('INSTANCE_MISMATCH');
function excelDigest($path){clearstatcache(true,$path);return is_file($path)?hash_file('sha256',$path):null;}
function excelDestination($key,$root,$site,$preview){
    if(strpos($key,'..')!==false||strpos($key,"\0")!==false)throw new RuntimeException('UNSAFE_PATH');
    $base=$root;$destination=null;
    $parts=explode('/',$key,2);$relative=$parts[1]??'';
    if($parts[0]==='preview'){
        if($site!=='rh'||!$preview)throw new RuntimeException('PREVIEW_NOT_AUTHORIZED');
        $base=$root.'-8080';
    }elseif($parts[0]!=='formal')throw new RuntimeException('UNKNOWN_ROOT');
    if($relative==='public/system.html'||strpos($relative,'public/view_admin/')===0)$destination=$base.'/'.$relative;
    $backend=['app/controller/admin/v1/target/StoreTarget.php','app/services/target/TargetSchemeServices.php','app/services/report/GroupManagementDashboardTargetServices.php'];
    if($parts[0]==='formal'&&in_array($relative,$backend,true))$destination=$base.'/'.$relative;
    if($destination===null)throw new RuntimeException('TARGET_NOT_ALLOWED');
    // RH's existing preview entry points into its own immutable integration
    // directory. Keep the link intact and update only that allowed entry target.
    if(is_link($destination)){
        $resolved=realpath($destination);
        if($site!=='rh'||$parts[0]!=='preview'||$relative!=='public/system.html'||$resolved===false||!preg_match('~^'.preg_quote($base,'~').'/public/\.local-8080-integration-releases/[a-f0-9]+/system\.html$~',$resolved))throw new RuntimeException('TARGET_NOT_ALLOWED');
        $destination=$resolved;
    }
    $parent=dirname($destination);while(!file_exists($parent)&&!is_link($parent))$parent=dirname($parent);
    $real=realpath($parent);if($real===false||($real!==$base&&strpos($real,$base.'/')!==0))throw new RuntimeException('TARGET_ESCAPES_INSTANCE');
    return $destination;
}
function excelPut($source,$destination){
    if(!is_dir(dirname($destination)))mkdir(dirname($destination),0755,true);
    $next=$destination.'.r95-excel-next';if(file_exists($next)||is_link($next))throw new RuntimeException('PENDING_FILE_EXISTS');
    if(!copy($source,$next))throw new RuntimeException('COPY_FAILED');
    chmod($next,0644);chown($next,is_file($destination)?fileowner($destination):'www');chgrp($next,is_file($destination)?filegroup($destination):'www');
    if(!rename($next,$destination))throw new RuntimeException('SWITCH_FAILED');
}
$record=$directory.'/prepared.json';$preview=!empty($manifest['previewAuthorized']);
if($mode==='install'){
    if(file_exists($record))throw new RuntimeException('ALREADY_PREPARED');$targets=[];
    // Validate every original byte and prepare all recovery copies before switching.
    foreach($manifest['targets'] as $key=>$entry){
        $destination=excelDestination($key,$root,$site,$preview);$source=$directory.'/payload/'.$key;
        if(excelDigest($destination)!==$entry['before']||excelDigest($source)!==$entry['after'])throw new RuntimeException('BASELINE_CHANGED_'.$key);
        $backup=$directory.'/before/'.hash('sha256',$key);
        if($entry['before']!==null){if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);if(!copy($destination,$backup)||excelDigest($backup)!==$entry['before'])throw new RuntimeException('BACKUP_FAILED');}
        $targets[$key]=$entry+['backup'=>$backup];
    }
    if(file_put_contents($record,json_encode($targets,JSON_PRETTY_PRINT))===false)throw new RuntimeException('BACKUP_RECORD_FAILED');chmod($record,0600);
    try{
        foreach($targets as $key=>$entry){$destination=excelDestination($key,$root,$site,$preview);if(excelDigest($destination)!==$entry['before'])throw new RuntimeException('CONCURRENT_CHANGE_'.$key);excelPut($directory.'/payload/'.$key,$destination);}
        foreach($targets as $key=>$entry)if(excelDigest(excelDestination($key,$root,$site,$preview))!==$entry['after'])throw new RuntimeException('INSTALLED_HASH_MISMATCH');
    }catch(Throwable $error){
        foreach(array_reverse($targets,true) as $key=>$entry){$destination=excelDestination($key,$root,$site,$preview);if(excelDigest($destination)===$entry['after']){if($entry['before']===null)unlink($destination);else excelPut($entry['backup'],$destination);}}
        throw $error;
    }
}else{
    $targets=json_decode(file_get_contents($record),true);
    foreach($targets as $key=>$entry)if(!in_array(excelDigest(excelDestination($key,$root,$site,$preview)),[$entry['before'],$entry['after']],true))throw new RuntimeException('CONCURRENT_CHANGE_'.$key);
    foreach(array_reverse($targets,true) as $key=>$entry){$destination=excelDestination($key,$root,$site,$preview);if($entry['before']===null){if(file_exists($destination))unlink($destination);}else excelPut($entry['backup'],$destination);}
}
echo 'R95_EXCEL_FILES_'.strtoupper($mode).'_PASS',PHP_EOL;

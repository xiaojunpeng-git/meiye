<?php
/** Explicit instance/file installer: backup every target before mutation; reject concurrent writes on rollback. */
$directory = $argv[1] ?? ''; $mode = $argv[2] ?? '';
if (!preg_match('~^/www/backups/(rh|008|010)/r52-order-query-([a-f0-9]{8})$~', $directory, $match)
    || !in_array($mode, ['install','rollback'], true)) throw new RuntimeException('EXPLICIT_SCOPE_REQUIRED');
$site=$match[1]; $root='/www/wwwroot/'.($site==='rh'?'rh.cc3798.com':$site.'.cc3798.com');
$manifest=json_decode(file_get_contents($directory.'/payload/manifest.json'),true);
if ($manifest['site']!==$site || substr($manifest['commit'],0,8)!==$match[2]) throw new RuntimeException('INSTANCE_MISMATCH');
function r52Digest($file) { clearstatcache(true,$file); return is_file($file)?hash_file('sha256',$file):null; }
function r52Destination($key,$root,$site) {
    if (strpos($key,'..')!==false || strpos($key,"\0")!==false) throw new RuntimeException('UNSAFE_PATH');
    $destination=null; $base=$root;
    if (strpos($key,'formal/public/view_cashier_v3/')===0) $destination=$root.'/'.substr($key,7);
    if ($site==='rh' && strpos($key,'preview/public/view_cashier_v3/')===0) { $base=$root.'-8080'; $destination=$base.'/'.substr($key,8); }
    $allowed=['app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php','app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php'];
    if (strpos($key,'formal/')===0 && in_array(substr($key,7),$allowed,true)) $destination=$root.'/'.substr($key,7);
    if ($destination===null || is_link($destination)) throw new RuntimeException('TARGET_NOT_ALLOWLISTED');
    // Never follow a parent symlink into another customer or outside this authorized entry root.
    $parent=dirname($destination); while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
    $real=realpath($parent);
    if ($real===false || ($real!==$base && strpos($real,$base.'/')!==0)) throw new RuntimeException('TARGET_ESCAPES_INSTANCE');
    return $destination;
}
function r52Put($source,$destination) {
    if (!is_dir(dirname($destination))) mkdir(dirname($destination),0755,true);
    $temporary=$destination.'.r52-next';
    if (!copy($source,$temporary)) throw new RuntimeException('COPY_FAILED');
    chmod($temporary,0644); chown($temporary,is_file($destination)?fileowner($destination):'www'); chgrp($temporary,is_file($destination)?filegroup($destination):'www');
    if (!rename($temporary,$destination)) throw new RuntimeException('SWITCH_FAILED');
}
$record=$directory.'/prepared.json';
if ($mode==='install') {
    if (file_exists($record)) throw new RuntimeException('ALREADY_PREPARED');
    $targets=[];
    foreach ($manifest['targets'] as $key=>$entry) {
        $destination=r52Destination($key,$root,$site); $source=$directory.'/payload/'.$key;
        if (r52Digest($destination)!==$entry['before'] || r52Digest($source)!==$entry['after']) throw new RuntimeException('BASELINE_CHANGED_'.$key);
        $backup=$directory.'/before/'.hash('sha256',$key);
        if ($entry['before']!==null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup),0700,true);
            if (!copy($destination,$backup) || r52Digest($backup)!==$entry['before']) throw new RuntimeException('BACKUP_FAILED');
        }
        $targets[$key]=$entry+['backup'=>$backup];
    }
    if (file_put_contents($record,json_encode($targets,JSON_PRETTY_PRINT))===false) throw new RuntimeException('BACKUP_RECORD_FAILED');
    chmod($record,0600);
    try { foreach ($targets as $key=>$entry) r52Put($directory.'/payload/'.$key,r52Destination($key,$root,$site)); }
    catch (Throwable $error) {
        foreach (array_reverse($targets,true) as $key=>$entry) {
            $destination=r52Destination($key,$root,$site);
            if (r52Digest($destination)===$entry['after']) { if ($entry['before']===null) unlink($destination); else r52Put($entry['backup'],$destination); }
        }
        throw $error;
    }
} else {
    $targets=json_decode(file_get_contents($record),true);
    foreach ($targets as $key=>$entry) if (!in_array(r52Digest(r52Destination($key,$root,$site)),[$entry['before'],$entry['after']],true)) throw new RuntimeException('CONCURRENT_CHANGE_'.$key);
    foreach (array_reverse($targets,true) as $key=>$entry) {
        $destination=r52Destination($key,$root,$site);
        if ($entry['before']===null) { if (file_exists($destination)) unlink($destination); }
        else r52Put($entry['backup'],$destination);
    }
}
echo 'R52_FILES_'.strtoupper($mode).'_PASS',PHP_EOL;

<?php
declare(strict_types=1);
/** Install exactly the accepted member adapter; no full package or other site's
 * files are replaced. Abort on source/dependency drift and keep a private rollback.
 */
set_exception_handler(static function(Throwable$e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
$root='/www/wwwroot/008.cc3798.com';$e=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($e['DATABASE']??$e['database']??[]);if(($c['database']??'')!=='mohe_008'||(int)($c['hostport']??0)!==3307)throw new RuntimeException('INSTANCE_MISMATCH');
$dir='/www/backups/008/m014-card-relations-20261029';$file=$root.'/app/services/member/MemberCommerceServices.php';$candidate=$dir.'/candidate-MemberCommerceServices.php';$before='512092a4f572a120a95790bd860585c489bd5a222a4caa8ed3afaa0317e1149c';$after='33a83c0f0a03dfa27b879c5ca32c138e988788da993eddbfd584fb419268953c';
if(!hash_equals($before,hash_file('sha256',$file))||!hash_equals($after,hash_file('sha256',$candidate)))throw new RuntimeException('ACCEPTED_SOURCE_DRIFT');
if(!hash_equals('9f3bea4381ef6948e1bab757f93d78f172dc8df4f858a1e1b8070d54051835fe',hash_file('sha256',$root.'/app/services/cashier/v3/card/CashierV3OrdinaryCardSaleAdjustment.php')))throw new RuntimeException('GUARD_DEPENDENCY_DRIFT');
$backup=$dir.'/MemberCommerceServices.before-a6bcb56c.php';if(file_exists($backup))throw new RuntimeException('EXISTING_RELEASE_CHECK_RECEIPT');
$stat=stat($file);if(!copy($file,$backup))throw new RuntimeException('BACKUP_FAILED');chmod($backup,0400);
$temp=$file.'.m014-release';if(file_exists($temp))throw new RuntimeException('RELEASE_TEMP_EXISTS');
if(!copy($candidate,$temp))throw new RuntimeException('RELEASE_COPY_FAILED');chmod($temp,$stat['mode']&0777);chown($temp,$stat['uid']);chgrp($temp,$stat['gid']);
exec('/www/server/php/74/bin/php -l '.escapeshellarg($temp).' 2>&1',$lint,$code);if($code!==0){unlink($temp);throw new RuntimeException('LINT_FAILED');}
if(!rename($temp,$file))throw new RuntimeException('ATOMIC_RENAME_FAILED');if(!hash_equals($after,hash_file('sha256',$file)))throw new RuntimeException('INSTALLED_SHA_MISMATCH');
$out=['site'=>'008','commit'=>'a6bcb56ccfa36f16b3ad6392a1d70921ed222582','installedAt'=>date('c'),'file'=>'app/services/member/MemberCommerceServices.php','beforeSHA256'=>$before,'afterSHA256'=>$after,'backup'=>$backup];file_put_contents($dir.'/code-release.json',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($dir.'/code-release.json',0600);echo json_encode($out),PHP_EOL;

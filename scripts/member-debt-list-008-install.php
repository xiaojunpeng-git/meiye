<?php
declare(strict_types=1);

/** Approved restoration of one existing member read endpoint only. No SQL
 * mutation, frontend deployment, payment action or other instance is allowed. */
$directory='/www/backups/008.cc3798.com/20261026-member-debt-list-40147d66';
if(PHP_SAPI!=='cli'||realpath(__DIR__)!==$directory)throw new RuntimeException('Wrong private executor location');
$root='/www/wwwroot/008.cc3798.com';
$file=$root.'/app/controller/api/v1/order/StoreDebt.php';
$old='58a20362d8db5cec0f462c6a64e6c8744a0781e3110e3a02b088daae07d64ce3';
$new='d8e46605b440a1a049d9d10f621f14d89e0b2169ba012ae30a2a64cd5a2b9226';
$service='6c976323f33e6b81cb9a62da39d2b6ee62753f23a18167c8fcba13ac50d88d3a';
$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($env['DATABASE']??$env['database']??[]);
if(($c['database']??'')!=='mohe_008'||(int)($c['hostport']??0)!==3307)throw new RuntimeException('Wrong instance database');
if(hash_file('sha256',$root.'/app/services/order/StoreDebtServices.php')!==$service)throw new RuntimeException('Debt service baseline changed');
$mode=$argv[1]??'';
$copy=static function(string $from,string $to):void{
    $stage=$to.'.debt-list-next';
    if(!copy($from,$stage))throw new RuntimeException('Stage failed');
    chmod($stage,0644);chown($stage,'www');chgrp($stage,'www');
    if(hash_file('sha256',$stage)!==hash_file('sha256',$from)||!rename($stage,$to))throw new RuntimeException('Atomic switch failed');
};
$restart=static function():void{exec('systemctl restart mohe-008-swoole.service 2>&1',$out,$code);if($code!==0)throw new RuntimeException('Scoped restart failed');};
if($mode==='install'){
    if(hash_file('sha256',$file)!==$old||hash_file('sha256',__DIR__.'/candidate.php')!==$new)throw new RuntimeException('Controller baseline or candidate changed');
    if(is_file(__DIR__.'/before.php'))throw new RuntimeException('Original backup already exists');
    exec('/www/server/php/74/bin/php -l '.escapeshellarg(__DIR__.'/candidate.php').' 2>&1',$out,$code);
    if($code!==0)throw new RuntimeException('PHP lint failed');
    if(!copy($file,__DIR__.'/before.php')||hash_file('sha256',__DIR__.'/before.php')!==$old)throw new RuntimeException('Backup verification failed');
    chmod(__DIR__.'/before.php',0600);
    $copy(__DIR__.'/candidate.php',$file);
    try{$restart();}catch(Throwable $e){$copy(__DIR__.'/before.php',$file);$restart();throw $e;}
    if(hash_file('sha256',$file)!==$new)throw new RuntimeException('Installed controller differs');
    $result=['result'=>'INSTALLED','sourceCommit'=>'40147d669ac4783c76146419578d62e416878178','existingFeatureSource'=>'7e77f5849911342db250187f8c4471948010da86','files'=>1,'databaseWrites'=>0,'time'=>date(DATE_ATOM)];
}elseif($mode==='rollback'){
    if(hash_file('sha256',$file)!==$new||hash_file('sha256',__DIR__.'/before.php')!==$old)throw new RuntimeException('Later controller change blocks rollback');
    $copy(__DIR__.'/before.php',$file);$restart();$result=['result'=>'ROLLED_BACK','databaseWrites'=>0];
}else throw new RuntimeException('Use install or rollback');
file_put_contents(__DIR__.'/'.$mode.'-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($result),PHP_EOL;

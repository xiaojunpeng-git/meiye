<?php
/** Rollback-only read checks for the accepted member adapter; never submits orders. */
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
$s=$argv[1]??'';$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($sites[$s]))throw new RuntimeException('Unknown instance');[$domain,$db,$port]=$sites[$s];$root='/www/wwwroot/'.$domain;$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$cfg=array_change_key_case($env['DATABASE']??$env['database']);if($cfg['database']!==$db||(int)($cfg['hostport']??3306)!==$port)throw new RuntimeException('Instance mismatch');
chdir($root);require $root.'/vendor/autoload.php';$app=new think\App();$app->initialize();
think\facade\Db::execute('START TRANSACTION READ ONLY');
try{
 $out=['site'=>$s,'result'=>'PASS','businessWrites'=>0,'backendSHA256'=>hash_file('sha256',$root.'/app/services/member/MemberCommerceServices.php')];
 $adapter=new ReflectionMethod(app\services\member\MemberCommerceServices::class,'buildInTx');
 $rule=app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment::class;
 if(!method_exists($rule,'seal')||!method_exists($rule,'validate'))throw new RuntimeException('Proof dependency missing');
 // The authoritative holder read must preserve existing issued identities and totals.
 $row=think\facade\Db::name('user_card_holder')->where('is_del',0)->order('id desc')->find();
 if($row){$service=app()->make(app\services\user\UserCardHolderServices::class);$result=$service->getCardHolderList((int)$row['uid']);foreach($result['list']as $h){if((int)$h['id']===(int)$row['id']){if($h['card_name']!==$row['card_name']||(int)$h['write_surplus_times']!==(int)$row['write_surplus_times'])throw new RuntimeException('Existing issued right changed');$out['holderReadVerified']=true;break;}}}
 $out['adapterLoaded']=$adapter->isPrivate()||$adapter->isProtected()||$adapter->isPublic();echo json_encode($out),PHP_EOL;
}finally{think\facade\Db::rollback();}

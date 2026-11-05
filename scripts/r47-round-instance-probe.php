<?php
/** R47 read-only schema, deployment provenance and native config preflight. No app bootstrap or writes. */
$site=$argv[1]??'';$sites=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($sites[$site]))throw new RuntimeException('Instance required');$root='/www/wwwroot/'.$site.'.cc3798.com';
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']);
if($c['database']!==$sites[$site][0]||(int)$c['hostport']!==$sites[$site][1])throw new RuntimeException('Wrong instance');
$db=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();try{
$out=['site'=>$site,'database'=>$c['database'],'columns'=>[]];foreach(['cashier_v3_checkout_request','cashier_v3_sales_order'] as$t)$out['columns'][$t]=$db->query("SHOW COLUMNS FROM eb_".$t." LIKE 'project_auto_complete'")->fetchAll();
$out['config']=$db->query("SELECT menu_name,value,config_tab_id,status FROM eb_system_config WHERE is_store=0 AND menu_name IN ('cashier_project_auto_complete_switch','cashier_ordinary_card_sale_edit_switch','reservation_service_voice_mode','cashier_member_default_all_switch') ORDER BY menu_name")->fetchAll();
}finally{$db->rollBack();}
$out['entryMtime']=date('c',filemtime($root.'/public/view_cashier_v3/index.html'));$dirs=[];foreach(['/www/backups/'.$site,'/www/backups/'.$site.'.cc3798.com']as$parent)foreach(glob($parent.'/*',GLOB_ONLYDIR)?:[]as$d)$dirs[$d]=filemtime($d);arsort($dirs);$out['recentReleaseMetadata']=[];
foreach(array_slice($dirs,0,15,true)as$d=>$time){$v=['directory'=>$d,'mtime'=>date('c',$time)];foreach(['manifest.json','payload/manifest.json','release-manifest.json']as$f)if(is_file($d.'/'.$f)){ $m=json_decode(file_get_contents($d.'/'.$f),true);foreach(['commit','sourceTree','tree','sourceCommit','codeVersion']as$k)if(isset($m[$k]))$v[$k]=$m[$k]; }$out['recentReleaseMetadata'][]=$v;}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;

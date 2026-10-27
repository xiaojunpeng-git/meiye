<?php
/** 仅核对本实例配置定义与代码指纹；不读取输出密钥、不写库、不复制其他客户配置。 */
$site=$argv[1]??'';$sites=['008'=>['008.cc3798.com','mohe_008','3307'],'rh'=>['rh.cc3798.com','ruihao','3306']];
if(!isset($sites[$site]))throw new RuntimeException('Explicit instance required');
[$domain,$name,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($env['DATABASE']??$env['database']??[]);
if($c['database']!==$name||(string)$c['hostport']!==$port)throw new RuntimeException('Instance mismatch');
$db=new PDO('mysql:host='.$c['hostname'].';port='.$port.';dbname='.$name.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try{
$keys=['system_product_copy_type','copy_product_apikey','logistics_type','system_express_app_code','config_export_open','config_export_siid','config_export_to_name','config_export_to_tel','tengxun_map_key','system_statistics','config_export_to_address','verify_expire_time','sms_type','aliyun_AccessKeyId','aliyun_AccessKeySecret','aliyun_SignName'];
$q=$db->prepare('SELECT id,menu_name,type,config_tab_id,info,`desc`,status, LENGTH(value) AS stored_length FROM eb_system_config WHERE menu_name IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);
$out=['site'=>$site,'database'=>$name,'configs'=>$q->fetchAll(PDO::FETCH_ASSOC),'files'=>[]];
foreach(['app/services/system/config/SystemConfigServices.php','app/controller/admin/v1/system/config/SystemConfig.php','app/validate/admin/setting/SystemConfigValidate.php','route/admin.php','public/system.html'] as $f)$out['files'][$f]=is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null;
$out['upgrades']=$db->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
}finally{$db->rollBack();}

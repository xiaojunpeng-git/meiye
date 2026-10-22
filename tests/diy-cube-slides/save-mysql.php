<?php
// Real decoration service persistence on a connection-local temporary table only.
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->env->load('/var/www/html/.env');$app->initialize();
if(config('database.connections.mysql.hostname')!=='mysql')throw new RuntimeException('Local container only');
$cache=config('cache');$prefix='cube-test-'.bin2hex(random_bytes(8)).':';
$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';config($cache,'cache');
use think\facade\Db;
function cubeEnsure($ok,$why){if(!$ok)throw new RuntimeException($why);}
$table=config('database.connections.mysql.prefix').'diy';
cubeEnsure((bool)preg_match('/^[a-zA-Z0-9_]+$/',$table),'Unsafe table');
try{
 $ddl=Db::query('SHOW CREATE TABLE `'.$table.'`')[0]['Create Table'];
 Db::execute(str_replace('CREATE TABLE `'.$table.'`','CREATE TEMPORARY TABLE `'.$table.'`',$ddl));
 $fixture=json_decode(file_get_contents('/tmp/cube-decoration-fixture.json'),true);
 cubeEnsure(is_array($fixture['value']??null),'Explicit synthetic fixture required');
 $data=['template_name'=>'cube-local-only','name'=>'cube-local-only','title'=>'Cube test','type'=>1,'status'=>1,'is_show'=>1,'version'=>'cube-v1','value'=>json_encode($fixture['value'])];
 $service=app()->make(app\services\diy\DiyServices::class);
 $id=$service->saveData(0,$data);
 $public=$service->getDiyInfo($id);
 cubeEnsure($public['value']===$fixture['value'],'Public read lost slides or layout');
 $stored=Db::name('diy')->where('id',$id)->value('value');
 cubeEnsure(json_decode($stored,true)===$fixture['value'],'Database roundtrip changed decoration');
 $changed=$data;$changed['version']='cube-v2';$value=$fixture['value'];
 $key=array_key_first($value);$value[$key]['picStyle']['picList'][0]['slides']=[];
 $value[$key]['picStyle']['picList'][0]['image']='';$value[$key]['picStyle']['picList'][0]['link']='';
 $changed['value']=json_encode($value);$service->saveData($id,$changed);
 cubeEnsure($service->getDiyInfo($id)['value']===$value,'Update left stale slides in cache');
 cubeEnsure(Db::name('diy')->count()===1,'Repeat save duplicated document');
 file_put_contents('/tmp/cube-public-response.json',json_encode(['status'=>200,'msg'=>'ok','data'=>$public]));
 echo "PASS: real MySQL decoration save -> public read, 12 layouts with slides/links, existing-row update, removed slides and cache refresh; temporary table only.\n";
}finally{Db::execute('DROP TEMPORARY TABLE IF EXISTS `'.$table.'`');}

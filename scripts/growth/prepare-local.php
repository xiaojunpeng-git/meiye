<?php
declare(strict_types=1);
/** MYDX本地数据：只复制当前本地数据库表结构，绝不复制客户业务记录或渠道密钥。 */
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->initialize();
$cfg=config('database.connections.mysql');
if(!in_array($cfg['hostname'],['mysql','mohe-mysql','127.0.0.1'],true))throw new RuntimeException('只允许本地MySQL');
$target='growth_qa_20261023';$prefix=$cfg['prefix'];
$pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['hostport'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$tables=$pdo->query('SHOW FULL TABLES FROM `'.$cfg['database'].'` WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
foreach($tables as $table){$name=$table[0];if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))continue;$pdo->exec('CREATE TABLE IF NOT EXISTS `'.$target.'`.`'.$name.'` LIKE `'.$cfg['database'].'`.`'.$name.'`');}
$pdo->exec('USE `'.$target.'`');
foreach(glob('/var/www/html/database/upgrades/20261023_growth_*.sql') as $sql)$pdo->exec(file_get_contents($sql));
// 临时环境配置由原本地配置生成并使用独立Redis数据库/前缀、运行目录，不进入交付源码。
$env=['DATABASE'=>['TYPE'=>'mysql','HOSTNAME'=>$cfg['hostname'],'HOSTPORT'=>$cfg['hostport'],'DATABASE'=>$target,'USERNAME'=>$cfg['username'],'PASSWORD'=>$cfg['password'],'PREFIX'=>$prefix], 'REDIS'=>['REDIS_HOSTNAME'=>'mohe-growth-local-redis','PORT'=>'6379','REDIS_PASSWORD'=>'','SELECT'=>'0'],'CACHE'=>['PREFIX'=>'growth_qa:'],'MOBILE_AUTH'=>['HMAC_KEY'=>hash('sha256','growth-local-isolated-test-key-20261023')]];
$env['GROWTH']['ASSET_ROOT']='/var/www/html/runtime/growth-local/private/assets';
$env['APP_DEBUG']='true';$text='';
foreach($env as $key=>$value){if(is_array($value)){ $text.="\n[".$key."]\n";foreach($value as $k=>$v)$text.=$k.' = '.json_encode((string)$v,JSON_UNESCAPED_SLASHES)."\n";}else $text.=$key.' = '.json_encode((string)$value,JSON_UNESCAPED_SLASHES)."\n";}
file_put_contents('/var/www/html/.env.growth-local',$text);chmod('/var/www/html/.env.growth-local',0600);
$insert=function(string $table,array $data)use($pdo,$prefix):void{
    if($table==='system_menus'){$check=$pdo->prepare('SELECT id FROM `'.$prefix.$table.'` WHERE unique_auth=?');$check->execute([$data['unique_auth']]);if($check->fetchColumn()!==false)return;}
    if(isset($data['id'])){$check=$pdo->prepare('SELECT id FROM `'.$prefix.$table.'` WHERE id=?');$check->execute([$data['id']]);if($check->fetchColumn()!==false)return;}
    elseif($table==='system_config'){$check=$pdo->prepare('SELECT id FROM `'.$prefix.$table.'` WHERE menu_name=?');$check->execute([$data['menu_name']]);if($check->fetchColumn()!==false)return;}
    elseif($table==='employee_mobile_auth_state'){$check=$pdo->prepare('SELECT id FROM `'.$prefix.$table.'` WHERE employee_id=?');$check->execute([$data['employee_id']]);if($check->fetchColumn()!==false)return;}
    $sql='INSERT INTO `'.$prefix.$table.'` (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')';$pdo->prepare($sql)->execute(array_values($data));
};
$now=time();$pwd=password_hash('GrowthLocal!2026',PASSWORD_BCRYPT);
$insert('organization',['id'=>91001,'name'=>'成长测试总部','status'=>1]);
$insert('organization',['id'=>91002,'pid'=>91001,'name'=>'成长测试区域','status'=>1]);
$insert('organization',['id'=>91003,'name'=>'隔离区域','status'=>1]);
$insert('system_store',['id'=>91001,'name'=>'成长体验店','business_status'=>1,'is_show'=>1]);
$insert('system_store',['id'=>91002,'name'=>'隔离测试店','business_status'=>1,'is_show'=>1]);
$insert('organization_store',['id'=>91001,'org_id'=>91002,'store_id'=>91001]);
$insert('organization_store',['id'=>91002,'org_id'=>91003,'store_id'=>91002]);
$insert('position',['id'=>91001,'name'=>'成长学习岗','use_mobile'=>1,'use_platform'=>1,'status'=>1]);
$insert('job_position_channel_rule',['id'=>91001,'position_id'=>91001,'channel'=>'mobile','rules'=>'401001,401030','status'=>1]);
foreach([91001=>'体验管理员',91002=>'美容师小美',91003=>'组织员工小雅',91004=>'隔离员工',91005=>'带教师傅'] as $id=>$name){
    $insert('employee',['id'=>$id,'name'=>$name,'phone'=>'199000'.$id,'status'=>1,'add_time'=>$now]);
    $insert('employee_internal_account',['id'=>$id,'employee_id'=>$id,'account'=>'emp'.$id,'pwd'=>$pwd,'status'=>1,'add_time'=>$now]);
    $insert('employee_mobile_auth',['id'=>$id,'employee_id'=>$id,'status'=>1,'scope_mode'=>'personal','rules'=>'401001,401030','request_token'=>'growth-qa-auth-'.$id]);
    $insert('employee_mobile_auth_state',['employee_id'=>$id,'auth_version'=>1,'phone_binding_version'=>1]);
    $insert('organization_employee',['id'=>$id,'org_id'=>$id===91004?91003:91002,'employee_id'=>$id,'status'=>1]);
    $insert('employee_data_scope',['id'=>$id,'employee_id'=>$id,'scope_mode'=>in_array($id,[91001,91005],true)?'org':'personal','org_ids'=>in_array($id,[91001,91005],true)?'[91001]':'[]','status'=>1,'request_token'=>'growth-qa-scope-'.$id]);
    if($id!==91003){$insert('system_store_staff',['id'=>$id,'employee_id'=>$id,'store_id'=>$id===91004?91002:91001,'staff_name'=>$name,'account'=>'emp'.$id,'pwd'=>$pwd,'status'=>1]);$insert('staff_job_position',['id'=>$id,'employee_id'=>$id,'store_id'=>$id===91004?91002:91001,'staff_id'=>$id,'position_id'=>91001,'status'=>1,'request_token'=>'growth-qa-job-'.$id]);}
}
$insert('system_admin',['id'=>65535,'employee_id'=>91001,'account'=>'growth_admin','pwd'=>$pwd,'real_name'=>'成长体验管理员','status'=>1,'level'=>0,'roles'=>'','add_time'=>$now]);
$pdo->exec("DELETE FROM eb_system_menus WHERE id=65535 AND unique_auth='admin-growth'");
// 测试平台只提供成长菜单；既有业务数据和密钥均不进入此库。
$insert('system_menus',['id'=>55000,'pid'=>0,'type'=>1,'icon'=>'md-school','menu_name'=>'成长管理','module'=>'admin','is_show'=>1,'access'=>1,'menu_path'=>'/admin/growth','path'=>'','auth_type'=>1,'header'=>'growth','is_header'=>1,'unique_auth'=>'admin-growth','params'=>'[]']);
$menuId=55010;
foreach(['content'=>'课程与知识','tasks'=>'学习任务','settings'=>'成长设置','exams'=>'练习与考试','plans'=>'培养计划','practice'=>'带教与实操','inspection'=>'巡检整改','profiles'=>'成长档案','reports'=>'成长统计'] as $code=>$title){$insert('system_menus',['id'=>$menuId++,'pid'=>55000,'type'=>1,'menu_name'=>$title,'module'=>'admin','is_show'=>1,'access'=>1,'menu_path'=>'/admin/growth/'.$code,'path'=>'55000','auth_type'=>1,'header'=>'growth','is_header'=>0,'unique_auth'=>'admin-growth-'.$code,'params'=>'[]']);}
foreach(['station_open'=>1,'system_login_error_num'=>5,'system_login_lock_time'=>5,'site_name'=>'美业大学 · 本地体验','site_url'=>'http://127.0.0.1:18115','login_logo'=>'','organization_source_mode'=>'organization'] as $key=>$value)$insert('system_config',['menu_name'=>$key,'value'=>json_encode($value,JSON_UNESCAPED_UNICODE),'type'=>'text','status'=>1]);
$pdo->exec('UPDATE eb_position SET use_platform=1 WHERE id=91001');
$pdo->exec('UPDATE eb_growth_setting SET enabled=1 WHERE id=1');
echo json_encode(['database'=>$target,'schema_tables'=>count($tables),'test_employees'=>5,'remote_access'=>false],JSON_UNESCAPED_UNICODE).PHP_EOL;

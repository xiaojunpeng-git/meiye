<?php
declare(strict_types=1);
/** 发布准备只读探测：只返回版本/文件指纹和元数据计数，不输出会员内容或实例凭据。 */
if(PHP_SAPI!=='cli')exit(2);
$site=$argv[1]??'';$databases=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'];if(!isset($databases[$site]))throw new RuntimeException('explicit instance required');
$root='/www/wwwroot/'.$site.'.cc3798.com';chdir($root);require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use think\facade\Db;
if(config('database.connections.mysql.database')!==$databases[$site])throw new RuntimeException('instance database mismatch');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$files=[];
foreach($input['files']as$file){if(strpos($file,'..')!==false||!preg_match('~^(app/|route/|config/|database/|public/|\.env$)~',$file))throw new RuntimeException('invalid probe path');$files[$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):null;}
$assets=[];foreach(['public/view_admin','public/static/js','public/static/css']as$folder){if(!is_dir($folder))continue;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder,FilesystemIterator::SKIP_DOTS))as$file){if(!$file->isFile()||!preg_match('/\.(js|css|html)$/D',$file->getPathname()))continue;$assets[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());}}
foreach(['public/system.html','public/index.html']as$file)$assets[$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):null;
$tables=[];foreach(Db::query("SHOW TABLES LIKE 'eb_member_survey_%'")as$row){$table=array_values($row)[0];if(!preg_match('/^eb_member_survey_[a-z_]+$/D',$table))throw new RuntimeException('table name invalid');$tables[$table]=['count'=>(int)Db::query('SELECT COUNT(*) n FROM `'.$table.'`')[0]['n'],'columns'=>array_column(Db::query('SHOW COLUMNS FROM `'.$table.'`'),'Field')];}
$dependencies=[];foreach(['cashier_v3_direct_gift_authority','cashier_v3_direct_gift_item','cashier_v3_gift_fact','cashier_v3_business_event','cashier_v3_outbox','organization_store']as$table)$dependencies[$table]=count(Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['eb_'.$table]))>0;
$baseUrl=Db::name('system_config')->where('menu_name','site_url')->value('value');
$menus=Db::name('system_menus')->whereIn('unique_auth',['admin-user','admin-user-survey'])->where('type',1)->where('is_del',0)->field('id,unique_auth,menu_path')->select()->toArray();
$entries=[];foreach(['public/system.html','public/index.html']as$file)$entries[$file]=is_file($root.'/'.$file)?file_get_contents($root.'/'.$file):null;
ksort($assets);echo json_encode(['site'=>$site,'database'=>$databases[$site],'files'=>$files,'frontend_assets'=>$assets,'entry_html'=>$entries,'survey_tables'=>$tables,'dependencies'=>$dependencies,'site_url'=>$baseUrl,'menus'=>$menus,'read_only'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

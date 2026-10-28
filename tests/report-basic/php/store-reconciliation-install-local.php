<?php
/** 真实安装脚本的升级前目录测试：使用全新隔离数据库，结束即销毁，不写任何已有库。 */
$root=getenv('BACKEND_ROOT')?:getcwd();require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'app\\')!==0)return;$p=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($p))require$p;},true,true);
$app=new think\App($root.'/');$app->env->load(getenv('LOCAL_TEST_ENV'));$app->initialize();
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,(string)$error);exit(1);});
use think\facade\Db;
use app\services\organization\StorePermissionCatalogServices as Catalog;
if(!in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$tables=['system_menus','system_role','job_position_channel_rule','store_v3_permission_catalog','store_v3_permission_audit'];
$checks=0;
$original=Db::query('SELECT DATABASE() db')[0]['db'];$database='r11_install_'.bin2hex(random_bytes(5));
if(!preg_match('/^[a-zA-Z0-9_]+$/D',$original))throw new RuntimeException('invalid local database');
function installCheck11(bool $ok,string $label):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($label);}
/** 仅复用脚本的安装主体，保留其环境/数据库身份门禁；Think应用复用已加载的隔离测试连接。 */
function installRun11(string $root):array{
 $path=getenv('R11_INSTALL_SCRIPT');$code=file_get_contents($path);$offset=strpos($code,'$expected=');
 if($offset===false)throw new RuntimeException('installer boundary missing');
 ob_start();try{eval('use think\\facade\\Db;use app\\services\\organization\\StorePermissionCatalogServices as Catalog;'.substr($code,$offset));return json_decode(ob_get_contents(),true,512,JSON_THROW_ON_ERROR);}finally{ob_end_clean();}
}
try{
 Db::execute('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
 foreach($tables as$t)Db::execute('CREATE TABLE `'.$database.'`.`eb_'.$t.'` LIKE `'.$original.'`.`eb_'.$t.'`');
 Db::execute('USE `'.$database.'`');
 $defaults=Catalog::definitions()+Catalog::navigationGroups()+Catalog::navigationItems();
 // 与既有显式大类迁移后的目录一致；不能用尚未归类的源码默认节点冒充现网目录。
 foreach([301001=>-2801,301002=>-2801,301003=>-2801,301004=>-2801,301005=>-2801,301006=>-2806,301008=>-2801,
 301100=>-2802,301009=>-2801,301062=>-2804,301201=>-2804,301200=>-2804,301007=>-2805]as$id=>$parent)$defaults[$id]['parent_id']=$parent;
 $rows=array_values($defaults);
 $rows=array_values(array_filter($rows,static function($r){return(int)$r['id']!==-33022;}));
 foreach($rows as&$row)if((int)$row['id']===-33021){$row['enabled']=0;$row['sort']=87;}unset($row);
 Db::name(Catalog::TABLE)->insert(['id'=>1,'rules_json'=>json_encode($rows),'revision'=>17,'updated_at'=>1]);
 Db::name('system_menus')->insert(['id'=>201,'pid'=>0,'type'=>1,'menu_name'=>'门店统计','module'=>'admin','unique_auth'=>'admin-report-basic-group-store','is_del'=>0,'path'=>'0','params'=>'[]','methods'=>'[]']);
 putenv('R11_METADATA_INSTALL=1');putenv('R11_EXPECTED_DATABASE='.$database);
 $old=null;try{(new Catalog())->snapshot();}catch(RuntimeException$error){$old=$error;}
 installCheck11($old!==null,'前置目录确实缺少新节点，运行时snapshot不可提前使用');
 $first=installRun11($root);installCheck11($first['existing_grants_unchanged']&&$first['business_rows_written']===0,'安装不扩权或写业务事实');
 $after=Db::name(Catalog::TABLE)->where('id',1)->find();$map=array_column((new Catalog())->snapshot()['rules'],null,'id');
 installCheck11(isset($map[-33022]),'新增单表目录');
 installCheck11($map[-33021]['enabled']===0&&$map[-33021]['sort']===87,'保留原配置');
 installCheck11(count($map)===count($rows)+1,'仅新增一节点');
 installCheck11(Db::name('system_menus')->where('unique_auth','admin-report-basic_store_reconciliation')->count()===1,'新增唯一菜单');
 $audit=Db::name('store_v3_permission_audit')->count();installRun11($root);
 installCheck11(Db::name(Catalog::TABLE)->where('id',1)->find()===$after&&Db::name('store_v3_permission_audit')->count()===$audit,'重复安装完全幂等');
 installCheck11(Db::name('system_role')->count()===0&&Db::name('job_position_channel_rule')->count()===0,'未自动创建角色授权');
 putenv('R11_EXPECTED_DATABASE=not_this_instance');$rejected=false;try{installRun11($root);}catch(RuntimeException$error){$rejected=true;}
 installCheck11($rejected&&Db::name(Catalog::TABLE)->where('id',1)->find()===$after,'错误实例拒绝且不改元数据');
 echo json_encode(['passed'=>true,'checks'=>$checks,'isolated_local_database'=>true])."\n";
}finally{Db::execute('USE `'.$original.'`');Db::execute('DROP DATABASE IF EXISTS `'.$database.'`');}

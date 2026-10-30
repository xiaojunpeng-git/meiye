<?php
/**
 * 已验收并取得目标实例安装授权后使用。仅登记门店对账单菜单/岗位功能目录，
 * 不授予任何旧角色或岗位，不写业务数据；必须事先独立备份配置和审计表。
 */
$root=getenv('BACKEND_ROOT')?:getcwd();require$root.'/vendor/autoload.php';
spl_autoload_register(static function(string$class)use($root):void{if(strpos($class,'app\\')!==0)return;$file=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require$file;},true,true);
(new think\App($root.'/'))->initialize();
use think\facade\Db;
use app\services\organization\StorePermissionCatalogServices as Catalog;
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,$error->getMessage()."\n");exit(1);});
$expected=(string)getenv('R11_EXPECTED_DATABASE');
if(getenv('R11_METADATA_INSTALL')!=='1'||!preg_match('/^[a-zA-Z0-9_]+$/D',$expected)
 ||Db::query('SELECT DATABASE() db')[0]['db']!==$expected)throw new RuntimeException('Explicit authorized target database required');
$fingerprint=static function():string{return hash('sha256',json_encode([
 Db::name('system_role')->field('id,rules')->order('id')->select()->toArray(),
 Db::name('job_position_channel_rule')->field('id,channel,rules,version')->order('id')->select()->toArray(),
],JSON_THROW_ON_ERROR));};
$result=Db::transaction(static function()use($fingerprint,$root):array{
 $parents=Db::name('system_menus')->where('unique_auth','admin-report-basic-group-store')->where('type',1)->where('is_del',0)->lock(true)->select()->toArray();
 if(count($parents)!==1)throw new RuntimeException('基础报表门店统计分组缺失或不唯一');
 // 安装前仍是旧目录，不能用要求新节点总数的运行时snapshot拒绝合法的前一版本。
 // 先锁定并核对旧节点；显式初始化完成后再由snapshot执行完整新目录校验。
 $catalog=new Catalog();$catalogRow=Db::name(Catalog::TABLE)->where('id',1)->lock(true)->find();
 $oldRules=$catalogRow?json_decode((string)$catalogRow['rules_json'],true):null;
 if(!is_array($oldRules))throw new RuntimeException('原门店权限目录缺失或格式异常');
 $rules=array_column($oldRules,null,'id');
 foreach(Catalog::basicReportGrantDefinitions()as$grant)if($grant['navigation_id']!==-33022&&!isset($rules[$grant['navigation_id']]))throw new RuntimeException('须先完成原有基础报表权限目录迁移');
 if(!isset($rules[-29023])||($rules[-29023]['navigation_key']??null)!=='')throw new RuntimeException('原基础报表目录尚未迁移');
 $before=$fingerprint();
 $existing=Db::name('system_menus')->where('unique_auth','admin-report-basic_store_reconciliation')->lock(true)->select()->toArray();
 if(count($existing)>1)throw new RuntimeException('门店对账单菜单身份重复');
 if(!$existing)Db::connect()->getPdo()->exec(file_get_contents($root.'/database/upgrades/2026-10-28-门店对账单/01-平台菜单.sql'));
 elseif(($existing[0]['menu_path']??'')!=='/report/basic-reports/basic_store_reconciliation'||(int)$existing[0]['type']!==1)throw new RuntimeException('现有菜单身份或路径不符');
 $catalog->initializeBasicReportNavigation();
 if($fingerprint()!==$before)throw new RuntimeException('岗位或角色授权发生意外变化');
 return ['menu_id'=>(int)Db::name('system_menus')->where('unique_auth','admin-report-basic_store_reconciliation')->value('id'),
  'catalog_revision'=>$catalog->snapshot()['revision'],'existing_grants_unchanged'=>true,'business_rows_written'=>0];
});
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";

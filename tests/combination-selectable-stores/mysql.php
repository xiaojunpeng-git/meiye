<?php
/** Real controller/DAO scope queries on connection-local tables; no production or permanent business writes. */
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->env->load('/var/www/html/.env');$app->initialize();
use think\facade\Db;
use app\services\store\SystemStoreServices;
use app\dao\store\SystemStoreDao;
if(getenv('COMBINATION_STORES_LOCAL_TEST')!=='1'||config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker only');
class CombinationStoreRequest extends app\Request{
 public $input=[];
 public function param($name='',$default=null,$filter=''){return $this->input[explode('/',$name)[0]]??$default;}
 public function getMore(array$params,bool$list=false,bool$filter=true):array{$out=[];foreach($params as$p)$out[$p[0]]=$this->input[$p[0]]??$p[1];return $list?array_values($out):$out;}
}
class CombinationStoreContext extends SystemStoreServices{
 public $entry=1;
 public function getStoreIdByEntryRules(int$uid=0,array$param=[],bool$isCache=false):array{return ['store_id'=>$this->entry,'shop_operation_type'=>1];}
 public function getRegionStoreIds(int$storeId){return [1,4,7,8,11];}
}
function tableReadFingerprint($table){return hash('sha256',json_encode([Db::name($table)->count(),Db::name($table)->max('id'),Db::name($table)->where('id','>',0)->order('id')->limit(2)->select()->toArray()]));}
$checks=0;function checkStores($ok,$msg){if(!$ok)throw new RuntimeException($msg);++$GLOBALS['checks'];echo 'PASS ',$msg,PHP_EOL;}
$tables=['system_store','store_product','store_combination'];$templates=[];$digests=[];
foreach($tables as$t){$templates[$t]=Db::name($t)->where('id','>',0)->find();checkStores((bool)$templates[$t],$t.' schema fixture available');$digests[$t]=tableReadFingerprint($t);$ddl=Db::query('SHOW CREATE TABLE eb_'.$t)[0]['Create Table'];Db::execute(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl,1));}
$storeNames=[1=>'万象城店',2=>'滨北店',3=>'禾祥西店',4=>'吕厝店',6=>'星河店',7=>'南湖店',8=>'海沧店',9=>'大悦城店',10=>'天虹店',11=>'湾悦城店',132=>'观音山店',134=>'宝龙一城店'];
foreach($storeNames as$id=>$name)Db::name('system_store')->insert(array_merge($templates['system_store'],['id'=>$id,'name'=>$name,'is_show'=>1,'is_del'=>0,'is_alone'=>0,'is_region_alone'=>1,'province'=>1,'city'=>2,'area'=>3]));
foreach([200=>['is_show'=>0],201=>['is_del'=>1],202=>['is_alone'=>1]]as$id=>$extra)Db::name('system_store')->insert(array_merge($templates['system_store'],['id'=>$id,'name'=>'EXCLUDED-'.$id,'is_show'=>1,'is_del'=>0,'is_alone'=>0],$extra));
$p=array_merge($templates['store_product'],['id'=>8996,'pid'=>0,'type'=>0,'relation_id'=>0,'is_del'=>0,'is_show'=>1,'applicable_type'=>1,'applicable_store_id'=>'']);Db::name('store_product')->insert($p);
$a=array_merge($templates['store_combination'],['id'=>4,'product_id'=>8996,'is_del'=>0,'is_show'=>1,'applicable_type'=>1,'applicable_store_id'=>'']);Db::name('store_combination')->insert($a);
$request=new CombinationStoreRequest();$request->uid=17;$app->instance('request',$request);$service=new CombinationStoreContext(new SystemStoreDao());$controller=new app\controller\api\v1\store\Store($service);
$app->instance(app\services\store\StoreUserServices::class,new class extends app\services\store\StoreUserServices{public function __construct(){}public function getColumn($where,$field){return [1,134];}});
$api=function($input=[])use($request,$controller){$request->input=$input;return $controller->getStoreList($request)->getData();};$rows=function($input=[])use($api){$r=$api($input);if($r['status']!==200)throw new RuntimeException(json_encode($r));return $r['data'];};
checkStores(count($rows(['product_id'=>4]))===5,'ordinary entry still shows its original five-store region');
$all=$rows(['combination_id'=>4,'product_id'=>4]);$ids=array_map('intval',array_column($all,'id'));sort($ids);$expected=array_keys($storeNames);sort($expected);checkStores($ids===$expected,'valid combination binds source product8996 and returns all12 active non-isolated stores');
checkStores(count($rows(['combination_id'=>4,'product_id'=>999999]))===12,'caller cannot redirect activity identity to another or missing product');
// 008真实发布边界：原商品下架，活动独立上架，选店不能因此失败。
Db::name('store_product')->where('id',8996)->update(['is_show'=>0]);checkStores(count($rows(['combination_id'=>4]))===12,'offline source product with active combination still exposes eligible stores');Db::name('store_product')->where('id',8996)->update(['is_show'=>1]);
checkStores(count($rows(['combination_id'=>-1]))===5,'invalid negative activity cannot bypass normal region');
checkStores($api(['combination_id'=>999999])['status']!==200,'missing combination fails before widening scope');
checkStores($rows(['combination_id'=>4,'keyword'=>'滨北'])[0]['name']==='滨北店','search finds an allowed store outside entry region');
checkStores(array_map('intval',array_column($rows(['combination_id'=>4,'store_type'=>2]),'id'))===[134,1],'common-store filter remains active after lifting region');
$request->uid=0;checkStores($rows(['combination_id'=>4,'store_type'=>2])===[],'anonymous common stores remain empty');$request->uid=17;
Db::name('store_combination')->where('id',4)->update(['applicable_type'=>2,'applicable_store_id'=>'2,134']);
checkStores(array_map('intval',array_column($rows(['combination_id'=>4]),'id'))===[134,2],'partial activity retains exact configured stores');
checkStores(array_map('intval',array_column($rows(['combination_id'=>4,'store_type'=>2]),'id'))===[134],'common and activity scopes intersect before query');
Db::name('store_product')->where('id',8996)->update(['applicable_type'=>2,'applicable_store_id'=>'2']);
checkStores(array_map('intval',array_column($rows(['combination_id'=>4]),'id'))===[2],'activity and source-product scopes intersect');
Db::name('store_combination')->where('id',4)->update(['applicable_store_id'=>'']);checkStores($rows(['combination_id'=>4])===[],'empty partial activity never falls back to all stores');
Db::name('store_combination')->where('id',4)->update(['applicable_type'=>0]);checkStores($rows(['combination_id'=>4])===[],'platform-only activity has no store pickup choices');
Db::name('store_combination')->where('id',4)->update(['applicable_type'=>1,'is_show'=>0]);checkStores($api(['combination_id'=>4])['status']!==200,'offline activity is rejected');
Db::name('store_combination')->where('id',4)->update(['is_show'=>1]);Db::name('store_product')->where('id',8996)->update(['is_del'=>1]);checkStores($api(['combination_id'=>4])['status']!==200,'deleted source product cannot become unrestricted activity');
Db::name('store_product')->where('id',8996)->update(['is_del'=>0,'applicable_type'=>1,'applicable_store_id'=>'']);$service->entry=202;
checkStores(array_map('intval',array_column($rows(['combination_id'=>4]),'id'))===[202],'independent entry isolation remains unchanged');$service->entry=1;
checkStores($api(['combination_id'=>4,'is_sum'=>1])===app('json')->success(12)->getData(),'count uses the same activity-store scope');
// Persist only derived read results for the local browser fixture; temporary DB rows disappear on close.
file_put_contents('/tmp/combination-selectable-stores-page.json',json_encode($all,JSON_UNESCAPED_UNICODE));
foreach($tables as$t){Db::execute('DROP TEMPORARY TABLE eb_'.$t);checkStores(tableReadFingerprint($t)===$digests[$t],$t.' permanent count, identity and samples unchanged');}
echo 'COMBINATION_STORES_MYSQL_PASS checks=',$checks,PHP_EOL;

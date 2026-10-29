<?php
declare(strict_types=1);
/** Real canonical category reader against connection-local shadow tables only.
 * All permanent category switches, products and issued card rows remain untouched. */
if(getenv('M015_CATEGORY_LOCAL')!=='1')throw new RuntimeException('Local test guard required');
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
$c=config('database.connections.mysql');if($c['hostname']!=='mysql'||(int)$c['hostport']!==3306)throw new RuntimeException('Only local Docker MySQL');
$db=Db::connect();$cardFingerprint=static function(){return Db::query('SELECT COUNT(*) n,COALESCE(SUM(write_times),0) total,COALESCE(SUM(write_surplus_times),0) remaining FROM eb_user_card_holder');};$before=$cardFingerprint();
$product=(array)Db::name('store_product')->order('id')->find();$category=(array)Db::name('store_product_category')->order('id')->find();if(!$product||!$category)throw new RuntimeException('Local schema fixtures required');
$tables=['store_product','store_product_category','store_product_relation'];$created=[];
try{
 foreach($tables as $t){$ddl=$db->query('SHOW CREATE TABLE eb_'.$t)[0]['Create Table'];$db->execute(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));$created[]=$t;}
 $cat=static function(int $id,int $pid,int $on,int $sort,string $name)use($category){$r=$category;$r['id']=$id;$r['pid']=$pid;$r['mobile_card_show']=$on;$r['sort']=$sort;$r['cate_name']=$name;Db::name('store_product_category')->insert($r);};
 $prod=static function(int $id,string $ids,int $pid=0)use($product){$r=$product;$r['id']=$id;$r['pid']=$pid;$r['cate_id']=$ids;Db::name('store_product')->insert($r);};
 $cat(1,0,0,95,'美容项目');$cat(2,1,1,3,'面部');$cat(3,1,1,4,'身体');$cat(4,0,1,1,'活动组合');$cat(5,4,1,999,'体验项目');$cat(6,0,0,2,'关闭大类');$cat(7,6,0,2,'关闭子类');$cat(8,999,1,3,'孤立分类');$cat(9,10,1,4,'循环甲');$cat(10,9,1,5,'循环乙');$cat(11,1,1,3,'同序分类');
 $prod(101,'2');$prod(102,'2,3');$prod(103,'2,5');$prod(104,'7');$prod(105,'8');$prod(106,'9');$prod(107,'11,2');$prod(108,'');$prod(109,'',101);$prod(110,'0,2,2,-1,abc');$prod(111,'999');
 $services=app()->make(app\services\user\UserCardHolderServices::class);$products=app()->make(app\services\product\product\StoreProductServices::class);$categories=app()->make(app\services\product\category\StoreProductCategoryServices::class);
 $m=new ReflectionMethod($services,'resolveProductCardPackCategory');$m->setAccessible(true);$read=static function(int $id)use($m,$services,$products,$categories){return $m->invoke($services,$id,$products,$categories);};
 $checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS ',$label,PHP_EOL;};
 $check($read(101)['cate_id']===2,'parent off child on => child');
 Db::name('store_product_category')->where('id',1)->update(['mobile_card_show'=>1]);$check($read(101)['cate_id']===1,'both levels on => parent');
 Db::name('store_product_category')->where('id',2)->update(['mobile_card_show'=>0]);$check($read(101)['cate_id']===1,'parent on child off => parent');
 Db::name('store_product_category')->where('id',1)->update(['mobile_card_show'=>0]);$check($read(101)['uncategorized']===true,'both off => ungrouped right remains visible');
 Db::name('store_product_category')->where('id',2)->update(['mobile_card_show'=>1]);$check($read(102)['cate_id']===3,'same level higher sort wins');$check($read(107)['cate_id']===2,'same level same sort uses stable ID');
 $check($read(103)['cate_id']===4,'enabled ancestor wins over unrelated deeper category regardless of sort');
 $check($read(104)['uncategorized']===true,'closed classification does not invent a group');$check($read(105)['uncategorized']===true,'missing ancestor fails closed');$check($read(106)['uncategorized']===true,'category cycle terminates');
 $check($read(109)['cate_id']===2,'store product inherits master classification when empty');$check($read(110)['cate_id']===2,'invalid and repeated category IDs do not duplicate rights');$check($read(111)['uncategorized']===true,'missing category remains ungrouped');$check($read(0)['uncategorized']===true,'missing product identity remains ungrouped');
 Db::name('store_product_relation')->insert(['product_id'=>108,'relation_id'=>2,'type'=>1,'status'=>1]);$check($read(108)['cate_id']===2,'relation-table fallback retained');
 Db::name('store_product_category')->where('id',2)->update(['is_show'=>0]);$check($read(101)['cate_id']===2,'dedicated card-pack switch independent from mall visibility');
 $dto=[];
 foreach(['child'=>0,'parent'=>1]as $mode=>$enabled){
  Db::name('store_product_category')->where('id',1)->update(['mobile_card_show'=>$enabled]);$dto[$mode]=[];
  foreach([101,102,104]as $id){$dto[$mode][]=array_merge(['id'=>$id,'card_name'=>'已购卡'.$id,'product_type'=>5,'status'=>1,'write_surplus_times'=>1,'write_times'=>1],$read($id));}
 }
 echo 'MEMBER_CATEGORY_DTO=',json_encode($dto,JSON_UNESCAPED_UNICODE),PHP_EOL;
 $check($before===$cardFingerprint(),'permanent issued card totals unchanged');echo 'M015_CATEGORY_MYSQL_PASS checks=',$checks,PHP_EOL;
}finally{foreach(array_reverse($created)as $t)$db->execute('DROP TEMPORARY TABLE eb_'.$t);}

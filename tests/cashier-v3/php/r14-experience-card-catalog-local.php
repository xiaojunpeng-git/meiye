<?php
/** Real local authority/projection reads; configuration probes roll back, no financial writes. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\cashier\ThinkPhpCashierV3SaleCatalogAuthority;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('LOCAL_ONLY');
$checks=0;
function r14check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$authority=new ThinkPhpCashierV3SaleCatalogAuthority();$service=new CashierV3SaleCatalogServices($authority);
$op=new CashierV3OperatorScope(80,858,'1','0');
$scope=new CashierV3DataScopeContext(858,858,80,'0','1',[80],CashierV3DataScopeContext::MODE_STORES,[],false,'','r14-local',['cashier.v3.cashier'],['name'=>'R14 local']);
$normalizer=new ReflectionMethod($service,'normalizeAuthorityRow');$normalizer->setAccessible(true);
$find=static function($rows){foreach($rows as $row)if((int)$row['product_id']===626758)return $row;throw new RuntimeException('LOCAL_CARD_REQUIRED');};
$beforeParent=Db::name('store_product')->where('id',626705)->value('card_statistics_type');
$beforeChild=Db::name('store_product')->where('id',626758)->value('card_statistics_type');
Db::startTrans();try{
 $rows=$authority->listStoreItems(80);r14check(count(array_filter($rows,static function($r){return (int)$r['product_relation_id']!==80;}))===0,'本店目录范围');
 $row=$find($rows);$before=$normalizer->invoke($service,$row,true);
 r14check((int)$row['product_card_statistics_type']===1,'读取总部体验卡配置');
 $catalog=$service->catalog($op,$scope);$matches=array_values(array_filter($catalog['items'],static function($i){return $i['productId']===626758;}));
 r14check(count($matches)===1&&$matches[0]['cardStatisticsType']===1&&$matches[0]['kind']==='卡项','目录透传分类且保留卡项身份');
 r14check(isset($matches[0]['cardPurchaseSnapshot'])&&!$matches[0]['disabled'],'体验卡保留完整购买快照');
 Db::name('store_product')->where('id',626758)->update(['card_statistics_type'=>0]);
 r14check((int)$find($authority->listStoreItems(80))['product_card_statistics_type']===1,'门店旧副本不覆盖总部配置');
 Db::name('store_product')->where('id',626705)->update(['card_statistics_type'=>0]);
 $after=$normalizer->invoke($service,$find($authority->listStoreItems(80)),true);
 r14check($after['cardStatisticsType']===0,'总部普通卡设置生效');
 unset($before['cardStatisticsType'],$after['cardStatisticsType']);
 r14check($before===$after,'分类切换不改变价格次数卡规则及唯一快照');
 Db::name('store_product')->where('id',626758)->update(['pid'=>0,'card_statistics_type'=>1]);
 r14check((int)$find($authority->listStoreItems(80))['product_card_statistics_type']===1,'独立门店卡使用自身配置');
}finally{Db::rollback();}
r14check((int)Db::name('store_product')->where('id',626705)->value('card_statistics_type')===(int)$beforeParent&&(int)Db::name('store_product')->where('id',626758)->value('card_statistics_type')===(int)$beforeChild,'配置探测全部回滚');
echo "R14 EXPERIENCE CATALOG PASS checks=$checks\n";

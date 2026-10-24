<?php
/** 已部署类的只读业务回归：边界比较、固定价、多件单价及真实SKU字段，不写生产草稿或资金。 */
$site=$argv[1]??'';$domain=$site==='rh'?'rh.cc3798.com':$site.'.cc3798.com';$dbNames=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'];if(!isset($dbNames[$site]))throw new RuntimeException('INSTANCE_REQUIRED');
$root='/www/wwwroot/'.$domain;require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();if(config('database.connections.mysql.database')!==$dbNames[$site])throw new RuntimeException('INSTANCE_MISMATCH');
$class=app\services\cashier\v3\cashier\CashierV3CashierMoreActionServices::class;$checks=0;
foreach(['卡项','产品','项目']as $name){foreach([0,19800,40200]as $v){try{$class::assertSalePriceRangeCents(10000,20000,$name,$v,2);throw new RuntimeException('OUTSIDE_ACCEPTED');}catch(app\services\cashier\v3\CashierV3CommandException $e){if($e->getMessage()!==$name.'的售价区间是:最低100元,最高200元')throw $e;$checks++;}}foreach([20000,40000]as $v){$class::assertSalePriceRangeCents(10000,20000,$name,$v,2);$checks++;}}
$class::assertSalePriceRangeCents(298000,298000,'卡项',596000,2);$class::assertSalePriceRangeCents(0,0,'产品',0,2);
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();try{$sample=think\facade\Db::name('store_product_attr_value')->field('id,price_range_min,price_range_max')->order('id desc')->find();$rows=$sample?1:0;}finally{think\facade\Db::rollback();}
echo json_encode(['site'=>$site,'result'=>'PASS','rangeChecks'=>$checks+2,'realSkuRead'=>$rows,'productionWrites'=>0]),PHP_EOL;

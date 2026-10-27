<?php
declare(strict_types=1);

/** 仅本地镜像随机前缀表：真实调用商品解析、草稿保存/读回；不写原表、不启用目标。 */
if (getenv('R95_EXCEL_LOCAL_TEST') !== '1') throw new RuntimeException('Local test opt-in required');
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
use think\facade\Db;
use think\facade\Config;
use app\services\target\TargetSchemeServices;
use app\controller\admin\v1\target\StoreTarget;
$config = config('database'); $connection = $config['connections'][$config['default']];
if ($connection['hostname'] !== 'mysql' || $connection['database'] !== 'ruihao') throw new RuntimeException('Only local mysql/ruihao allowed');
$lines = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (count($lines) !== 2) throw new RuntimeException('Expected real JS import payload');
$source = Db::connect(); $prefix = 'r95excel_' . bin2hex(random_bytes(5)) . '_'; $created = []; $checks = 0; $failed = false;
function okExcel(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; }
function rejectExcel(callable $fn, string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { okExcel(true, $label); return; } throw new RuntimeException('Expected rejection: '.$label); }
try {
    foreach (['target_scheme','target_scheme_line','target_scheme_command','target_scheme_audit','target_report_line_binding','system_store','store_product_category','store_product'] as $table) {
        $source->execute("CREATE TABLE `{$prefix}{$table}` LIKE `eb_{$table}`"); $created[] = $prefix.$table;
    }
    $source->execute("INSERT INTO `{$prefix}system_store` SELECT * FROM `eb_system_store` WHERE id IN (23,24)");
    $cfg = $config; $cfg['connections']['r95excel'] = array_merge($connection, ['prefix'=>$prefix]); $cfg['default'] = 'r95excel'; Config::set($cfg, 'database');
    Db::name('store_product_category')->insert(['id'=>1,'pid'=>0,'type'=>0,'relation_id'=>0,'cate_name'=>'生美','is_show'=>1]);
    Db::name('store_product_category')->insert(['id'=>2,'pid'=>1,'type'=>0,'relation_id'=>0,'cate_name'=>'卡项','is_show'=>1]);
    // 测试商品全部写隔离表，准备超过旧查询100条上限的同店数据。
    for ($i = 1; $i <= 110; $i++) Db::name('store_product')->insert(['id'=>$i,'type'=>1,'relation_id'=>23,'store_name'=>$i===1?'精华产品':($i<4?'护理':'项目'.$i),'is_del'=>0]);
    Db::name('store_product')->insert(['id'=>111,'type'=>1,'relation_id'=>24,'store_name'=>'另一门店产品','is_del'=>0]);
    $controller = (new ReflectionClass(StoreTarget::class))->newInstanceWithoutConstructor();
    $set = static function (string $name, $value) use ($controller): void { $p = new ReflectionProperty($controller,$name); $p->setAccessible(true); $p->setValue($controller,$value); };
    $set('adminId',95); $set('adminInfo',['level'=>0]); $set('adminType',0); $set('agentId',0);
    $query = static function (array $params) use ($controller,$set): array {
        $request = new app\Request(); $request->withGet($params); $set('request',$request);
        return $controller->schemeProducts()->getData();
    };
    $result = $query(['store_id'=>23,'exact_name'=>'精华产品']);
    okExcel($result['status']===200 && count($result['data'])===1 && (int)$result['data'][0]['id']===1,'exact name finds old product beyond first100');
    $result = $query(['store_id'=>23,'exact_name'=>'护理']);
    okExcel(count($result['data'])===2,'exact duplicate query exposes ambiguity');
    okExcel(count($query(['store_id'=>23])['data'])===100,'existing manual picker retains limit100');
    okExcel(count($query(['store_id'=>23,'exact_name'=>'精华'])['data'])===0,'exact query is not fuzzy');
    okExcel(count($query(['store_id'=>23,'exact_name'=>'%'])['data'])===0,'wildcards are literal names');
    okExcel(count($query(['store_id'=>23,'product_id'=>1])['data'])===1,'legacy and qualified ID use same scoped lookup');
    okExcel(count($query(['store_id'=>23,'product_id'=>111])['data'])===0,'cross-store product ID blocked');
    okExcel($query(['store_id'=>999999,'exact_name'=>'精华产品'])['status']!==200,'out of scope store blocked');
    okExcel($query(['store_id'=>23,'product_id'=>'1 or 1=1'])['status']!==200,'invalid product ID rejected');
    $set('adminInfo',['level'=>1,'roles'=>[]]);
    okExcel($query(['store_id'=>23,'exact_name'=>'精华产品'])['status']!==200,'missing target permission blocked');
    $set('adminInfo',['level'=>0]);
    $ctx=['tenant_id'=>'0','store_ids'=>[23,24],'actor_id'=>95]; $service=new TargetSchemeServices();
    $id=$service->create($ctx,['name'=>'Excel isolated test','idempotency_key'=>'create'])['id'];
    $input=['expected_version'=>1,'idempotency_key'=>'save','lines'=>$lines];
    $saved=$service->replaceLines($ctx,$id,$input);
    $detail=(new TargetSchemeServices())->detail($ctx,$id);
    okExcel(count($detail['lines'])===2,'actual imported payload persists two targets and survives fresh read');
    okExcel((int)$detail['lines'][0]['target_value']===1000000 && (int)$detail['lines'][1]['target_value']===1234,'stored fen values match Excel yuan values exactly');
    okExcel($detail['lines'][0]['metric_code']==='actual_performance' && (int)$detail['lines'][1]['scope_id']===2,'saved metric and category identities unchanged');
    okExcel($detail['scheme']['status']==='draft' && !$service->effective($ctx,'group_dashboard')['lines'],'saving imported draft does not change dashboard effective goals');
    okExcel($service->replaceLines($ctx,$id,$input)===$saved,'repeated save is idempotent');
    rejectExcel(static fn()=> $service->replaceLines($ctx,$id,['expected_version'=>1,'idempotency_key'=>'stale','lines'=>$lines]),'stale version cannot overwrite');
    $bad=$lines; $bad[1]['store_id']=999999;
    rejectExcel(static fn()=> $service->replaceLines($ctx,$id,['expected_version'=>2,'idempotency_key'=>'outside','lines'=>$bad]),'server rechecks store permissions');
    $bad=$lines; $bad[1]['scope_type']='product';$bad[1]['scope_id']=111;
    rejectExcel(static fn()=> $service->replaceLines($ctx,$id,['expected_version'=>2,'idempotency_key'=>'cross','lines'=>$bad]),'server rechecks cross-store product');
    okExcel($service->detail($ctx,$id)['lines']===$detail['lines'],'failed save leaves all existing targets unchanged');
    echo 'R95_EXCEL_DB_CHECKS='.$checks."\n";
} catch (Throwable $e) {
    $failed = true; fwrite(STDERR, (string)$e."\n");
} finally {
    foreach (array_reverse($created) as $table) $source->execute("DROP TABLE `{$table}`");
    Config::set($config,'database');
    echo "ISOLATED_TABLES_REMOVED\n";
}
exit($failed ? 1 : 0);

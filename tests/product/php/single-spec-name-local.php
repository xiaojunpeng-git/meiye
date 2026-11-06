<?php
declare(strict_types=1);

/** 本地真实保存/读取回归：仅标记夹具，禁止客户库；使用保存命令自有事务，结束按身份清理。 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->initialize();
set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', (string)$e); exit(1); });
use think\facade\Db;
use app\services\product\product\StoreProductServices;
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || !getenv('MOHE_LOCAL_DB_HOSTNAME')
    || Db::query('SELECT @@hostname host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('LOCAL_ONLY');
}
$checks = 0;
function snCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks; echo "PASS $label\n";
}
// 仅隔离测试进程中的异步通知，真实保存事务和目录锁照常执行；不向队列留下回滚夹具。
final class SingleSpecTestQueue { public function push(...$args) { return true; } public function later(...$args) { return true; } }
$queue = new ReflectionProperty(mohe\utils\Queue::class, 'instance');
$queue->setAccessible(true); $oldQueue = $queue->getValue(); $queue->setValue(null, new SingleSpecTestQueue());
$app->event->remove('product.create'); $app->event->remove('product.status');
$id = 0;
try {
    $template = Db::name('store_product')->where('id', 498505)->where('product_type', 0)->find();
    if (!$template) $template = Db::name('store_product')->where('product_type', 0)->where('spec_type', 0)->where('is_del', 0)->order('id')->find();
    if (!$template) throw new RuntimeException('LOCAL_TEMPLATE_MISSING');
    $originalId = (int)$template['id']; unset($template['id']);
    $tag = 'R52-SPEC-' . bin2hex(random_bytes(5));
    $template = array_replace($template, ['pid'=>0, 'type'=>0, 'relation_id'=>0, 'spec_type'=>0,
        'store_name'=>$tag, 'keyword'=>$tag, 'is_del'=>0, 'is_verify'=>1, 'is_inventory'=>0]);
    $id = (int)Db::name('store_product')->insertGetId($template);
    foreach (['store_product_attr','store_product_attr_value','store_product_attr_result','store_product_description'] as $table) {
        foreach (Db::name($table)->where('product_id', $originalId)->select()->toArray() as $row) {
            unset($row['id']); $row['product_id']=$id;
            if ($table === 'store_product_attr_value') $row['unique']=substr(hash('sha256',$tag),0,8);
            Db::name($table)->insert($row);
        }
    }
    $service = $app->make(StoreProductServices::class);
    $sku = Db::name('store_product_attr_value')->where('product_id',$id)->where('type',0)->find();
    if (!$sku) throw new RuntimeException('LOCAL_SKU_MISSING');
    $payload = array_replace(['coupon_ids'=>[], 'ensure_id'=>[], 'specs_id'=>0, 'specs'=>[], 'soure_link'=>'',
        'send_config'=>[], 'is_sync_show'=>0], $service->getInfo($id)['productInfo']);
    $payload['single_spec_name']='瓶装规格';
    $service->saveData($id,$payload,0,0,1);
    snCheck(Db::name('store_product_attr')->where('product_id',$id)->where('type',0)->value('attr_name')==='瓶装规格','名称写入规格主表');
    $info=$service->getInfo($id)['productInfo'];
    snCheck(($info['single_spec_name']??null)==='瓶装规格','保存后重新读取回显名称');
    snCheck($info['items']===[] && $info['attrs']===[], '保持单规格编辑契约');
    $skuAfter=Db::name('store_product_attr_value')->where('product_id',$id)->where('type',0)->find();
    snCheck($skuAfter['unique']===$sku['unique'] && $skuAfter['suk']===$sku['suk'], '仅改名称不新建或更换SKU身份');
    foreach (['stock','price','cost','ot_price'] as $field) snCheck((string)$skuAfter[$field]===(string)$sku[$field], '仅改名称不改变'.$field);
    $payload=array_replace($payload,$info); $service->saveData($id,$payload,0,0,1);
    snCheck($service->getInfo($id)['productInfo']['single_spec_name']==='瓶装规格','刷新后再次保存不退回默认名称');
    $payload['single_spec_name']='  旅行装  '; $service->saveData($id,$payload,0,0,1);
    snCheck($service->getInfo($id)['productInfo']['single_spec_name']==='旅行装','再次改名沿用既有去空格保存规则');
    // 缺少派生规格结果仍从规格主表恢复名称；GET不能补写缺失结果。
    Db::name('store_product_attr_result')->where('product_id',$id)->delete();
    snCheck($service->getInfo($id)['productInfo']['single_spec_name']==='旅行装','缺规格结果仍从主表读取已存名称');
    snCheck(Db::name('store_product_attr_result')->where('product_id',$id)->count()===0,'详情读取不补写规格结果');
    Db::name('store_product_attr')->where('product_id',$id)->delete();
    snCheck($service->getInfo($id)['productInfo']['single_spec_name']==='规格','无名称旧档使用默认名称');
    snCheck(Db::name('store_product_attr')->where('product_id',$id)->count()===0,'默认回显不补写规格主表');
    echo "TOTAL $checks checks passed\n";
} finally {
    $queue->setValue(null,$oldQueue);
    // 目录命令必须拥有最外层事务，不能用测试外层事务绕过；清理只限本次标记商品。
    if ($id) {
        Db::startTrans();
        try {
            if (Db::name('store_product')->where('id',$id)->value('keyword') !== $tag) throw new RuntimeException('CLEANUP_OWNERSHIP');
            foreach (['store_product_attr','store_product_attr_value','store_product_attr_result','store_product_description'] as $table) Db::name($table)->where('product_id',$id)->delete();
            Db::name('store_product')->where('id',$id)->where('keyword',$tag)->delete();
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
    }
}
snCheck(!$id || !Db::name('store_product')->where('id',$id)->count(),'临时商品已清理');

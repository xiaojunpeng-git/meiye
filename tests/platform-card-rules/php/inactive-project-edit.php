<?php
declare(strict_types=1);

// 本地数据库事务中模拟下架关联，回滚所有测试写入；生产数据不参与测试。
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html');
$app->initialize();
$db = think\facade\Db::connect();
$db->startTrans();
try {
    $relation = $db->name('store_card_related')->alias('r')
        ->join('store_product p', 'p.id=r.product_id')
        ->join('store_product_attr_value a', 'a.unique=r.product_attr_unique')
        ->where('r.status', 1)->where('p.is_verify', 1)->where('p.is_del', 0)
        ->field('r.*')->find();
    if (!$relation) throw new RuntimeException('缺少本地卡项关联测试数据');
    $db->name('store_card_related')->where('id', $relation['id'])->update(['status' => 0]);
    $service = $app->make(app\services\product\product\StoreCardRelatedServices::class);
    $id = (int)$relation['card_product_id'];
    $edit = $service->getCardRelatedProductForEdit($id);
    $found = array_values(array_filter($edit, static function ($row) use ($relation) {
        return (int)$row['id'] === (int)$relation['id'];
    }));
    if (count($found) !== 1 || (int)$found[0]['status'] !== 0 ||
        (int)$found[0]['write_times'] !== (int)$relation['write_times']) {
        throw new RuntimeException('编辑未完整回显停用关联');
    }
    $active = $service->getCardRelatedProduct($id);
    foreach ($active as $row) {
        if ((int)$row['id'] === (int)$relation['id']) throw new RuntimeException('销售读取包含停用关联');
    }
    $normalize = new ReflectionMethod($service, 'normalizeRelatedRows');
    $normalize->setAccessible(true);
    $saved = $normalize->invoke($service, $id, [$found[0]]);
    if ((int)$saved[0]['status'] !== 0 || (int)$saved[0]['write_times'] !== (int)$relation['write_times']) {
        throw new RuntimeException('保存处理改变停用状态或次数');
    }
    echo "PASS 停用关联编辑回显、销售隔离、保存数据口径\n";
} finally {
    $db->rollback();
}

<?php
declare(strict_types=1);

// 本地数据库事务验证筛选口径，结束后回滚，不修改线上商品。
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html');
$app->initialize();
$db = think\facade\Db::connect();
$db->startTrans();
try {
    $ids = $db->name('store_product')->limit(5)->column('id');
    if (count($ids) < 5) throw new RuntimeException('本地商品测试数据不足');
    $types = [[0, 1], [0, 0], [4, 0], [5, 0], [6, 0]];
    foreach ($types as $i => $pair) {
        $db->name('store_product')->where('id', $ids[$i])->update([
            'product_type' => $pair[0], 'is_inventory' => $pair[1], 'stock' => 0,
            'is_police' => 1, 'is_sold' => 1, 'is_show' => 1, 'is_verify' => 1, 'is_del' => 0,
        ]);
        $model = app\model\product\product\StoreProduct::find($ids[$i]);
        if (app\services\product\product\ProductStockEligibility::appliesTo($model) !== ($i === 0)) {
            throw new RuntimeException('库存资格判断错误：' . $i);
        }
    }
    foreach ([4, 5] as $status) {
        $list = app\model\product\product\StoreProduct::withSearch(['status'], ['status' => $status])
            ->whereIn('id', $ids)->column('id');
        if (count($list) !== 1 || (int)$list[0] !== (int)$ids[0]) {
            throw new RuntimeException('状态 ' . $status . ' 的筛选错误：' . json_encode($list));
        }
    }
    $job = $app->make(app\jobs\product\ProductStockTips::class);
    foreach (array_slice($ids, 1) as $id) {
        $job->doJob($id, 0);
        $after = $db->name('store_product')->where('id', $id)->field('is_police,is_sold')->find();
        if ((int)$after['is_police'] !== 1 || (int)$after['is_sold'] !== 1) {
            throw new RuntimeException('不管理库存的商品被库存任务改写');
        }
    }
    echo "PASS 商品库存资格、售罄与预警列表口径、库存任务隔离\n";
} finally {
    $db->rollback();
}

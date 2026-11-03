<?php
declare(strict_types=1);
/**
 * R44 本地页面验收夹具。只创建显式虚构、下架的活动及其项目规格，不创建订单、支付或赠送。
 * create 输出精确所有权清单；inspect/cleanup 必须传原清单，禁止借用或修改既有活动/商品。
 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\activity\combination\StoreCombinationServices;
$app = new think\App('/var/www/html/'); $app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local Docker ruihao database is allowed');
}
function uiSeed(string $table, array $data): int {
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $field) {
        $key = $field['Field'];
        if (array_key_exists($key, $data) || $field['Default'] !== null || $field['Null'] === 'YES' || strpos($field['Extra'], 'auto_increment') !== false) continue;
        $data[$key] = preg_match('/int|decimal|float|double/', $field['Type']) ? 0 : '';
    }
    return (int)Db::name($table)->insertGetId($data);
}
function protectedRowsHash(): string {
    return hash('sha256', json_encode([
        Db::name('store_combination')->where('id', 3)->find(),
        Db::name('store_product')->where('id', 8354)->find(),
    ], JSON_UNESCAPED_UNICODE));
}
$action = $argv[1] ?? 'inspect';
$before = protectedRowsHash();
Db::startTrans();
try {
    if ($action === 'create') {
        $tag = 'R44-UI-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $title = $tag . ' 仅本地验收虚构项目';
        $image = 'http://127.0.0.1:8080/pages/store/static/card.png';
        $pid = uiSeed('store_product', ['store_name' => $title, 'store_info' => 'R44赠送设置页面验收，不购买不发奖',
            'type' => 0, 'relation_id' => 0, 'product_type' => 6, 'image' => $image, 'slider_image' => json_encode([$image]),
            'price' => 100, 'stock' => 10, 'unit_name' => '次', 'is_show' => 1, 'is_verify' => 1, 'is_del' => 0,
            'delivery_type' => '2', 'freight' => 1, 'applicable_type' => 1, 'add_time' => time()]);
        $cid = uiSeed('store_combination', ['title' => $tag . ' 仅本地验收虚构拼团', 'product_id' => $pid, 'product_type' => 6,
            'image' => $image, 'images' => json_encode([$image]), 'info' => 'R44赠送设置页面验收，不购买不发奖',
            'stock' => 10, 'price' => 100, 'quota' => 10, 'quota_show' => 10, 'people' => 2, 'effective_time' => 24,
            'num' => 1, 'once_num' => 1, 'virtual' => 100, 'unit_name' => '次', 'is_show' => 0, 'is_del' => 0,
            'delivery_type' => '2', 'freight' => 1, 'start_time' => strtotime('today'), 'stop_time' => strtotime('+30 days'), 'add_time' => time()]);
        $skus = [];
        foreach ([[$pid, 0], [$cid, 3]] as [$id, $type]) {
            $unique = substr(hash('sha256', $tag . '-' . $type), 0, 8);
            $skus[] = uiSeed('store_product_attr_value', ['product_id' => $id, 'product_type' => 6, 'type' => $type,
                'suk' => '默认', 'unique' => $unique, 'image' => $image, 'stock' => 10, 'price' => 100, 'ot_price' => 100,
                'quota' => 10, 'quota_show' => 10, 'write_times' => 1]);
            uiSeed('store_product_attr', ['product_id' => $id, 'type' => $type, 'attr_name' => '规格', 'attr_values' => '默认']);
            uiSeed('store_product_attr_result', ['product_id' => $id, 'type' => $type, 'change_time' => time(),
                'result' => json_encode(['attr' => [['value' => '规格', 'detail' => ['默认']]], 'value' => []], JSON_UNESCAPED_UNICODE)]);
            Db::name('store_product_description')->insert(['product_id' => $id, 'type' => $type, 'description' => '<p>' . $title . '</p>']);
        }
        $coupon = uiSeed('store_coupon_issue', ['title' => $tag . ' 仅本地验收虚构券', 'relation_id' => 0, 'status' => 1,
            'receive_type' => 3, 'is_verify' => 1, 'coupon_issue_type' => 0, 'applicable_type' => 1,
            'is_del' => 0, 'is_permanent' => 1, 'coupon_type' => 1, 'coupon_price' => 10, 'coupon_time' => 30,
            'is_give_subscribe' => 0, 'is_full_give' => 0, 'total_count' => 10, 'remain_count' => 10]);
        $manifest = ['tag' => $tag, 'activity_id' => $cid, 'product_id' => $pid, 'sku_ids' => $skus, 'coupon_id' => $coupon,
            'protected_rows_sha256' => $before];
    } else {
        $manifest = json_decode(file_get_contents($argv[2] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $tag = (string)$manifest['tag']; $cid = (int)$manifest['activity_id']; $pid = (int)$manifest['product_id']; $coupon = (int)$manifest['coupon_id'];
        if (strpos($tag, 'R44-UI-') !== 0 || $cid === 3 || $pid === 8354 ||
            strpos((string)Db::name('store_combination')->where('id', $cid)->value('title'), $tag) !== 0 ||
            strpos((string)Db::name('store_product')->where('id', $pid)->value('store_name'), $tag) !== 0 ||
            strpos((string)Db::name('store_coupon_issue')->where('id', $coupon)->value('title'), $tag) !== 0) {
            throw new RuntimeException('Fixture ownership verification failed');
        }
    }
    if ($action === 'cleanup') {
        // Once any real use exists, refuse deletion instead of treating new business as disposable test data.
        if (Db::name('store_order')->where('activity_id', $cid)->count() || Db::name('store_pink')->where('cid', $cid)->count() ||
            Db::name('combination_leader_reward')->where('activity_id', $cid)->count() ||
            Db::name('store_coupon_user')->where('cid', $coupon)->count()) throw new RuntimeException('Fixture has business use; do not delete');
        foreach ([[$pid, 0], [$cid, 3]] as [$id, $type]) {
            foreach (['store_product_attr_value', 'store_product_attr', 'store_product_attr_result', 'store_product_description'] as $table) {
                Db::name($table)->where('product_id', $id)->where('type', $type)->delete();
            }
        }
        Db::name('combination_leader_reward_config')->where('activity_id', $cid)->delete();
        Db::name('store_combination')->where('id', $cid)->delete();
        Db::name('store_product')->where('id', $pid)->delete();
        Db::name('store_coupon_issue')->where('id', $coupon)->delete();
        $manifest['cleaned'] = true;
    } elseif ($action === 'create' || $action === 'inspect') {
        $info = app()->make(StoreCombinationServices::class)->getInfo($cid)->toArray();
        if (count($info['attrs']['value']) !== 1 || !$info['attrs']['value'][0]['_checked']) throw new RuntimeException('Fixture getInfo must return one complete selected SKU');
        $manifest['getInfo_verified'] = true;
        $manifest['leader_reward'] = $info['leader_reward'];
        $manifest['edit_url'] = 'http://127.0.0.1:18081/admin/marketing/store_combination/create/' . $cid . '/0';
    } else throw new RuntimeException('Expected create, inspect or cleanup');
    if (protectedRowsHash() !== $before) throw new RuntimeException('Protected existing rows changed');
    Db::commit();
    echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $error) { Db::rollback(); throw $error; }

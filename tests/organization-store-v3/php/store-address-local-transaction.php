<?php

/**
 * 本机真实地区字典和门店读写回归；默认事务回滚，UI 模式仅创建带固定标识的测试门店。
 * 禁止连接线上，禁止复用或修改既有门店做 UI 保存测试。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\store\SystemStoreServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || getenv('STORE_ADDRESS_LOCAL_TEST') !== '1') {
    throw new RuntimeException('仅允许本机 Docker ruihao 测试库');
}
$fixtureName = 'R7地址回显测试';
$mode = getenv('STORE_ADDRESS_UI_MODE') ?: '';
if ($mode === 'cleanup') {
    // 只软归档本测试专用门店，不物理删除、不触碰任职或真实门店。
    $count = Db::name('system_store')->where('name', $fixtureName)->where('introduction', 'r7-address-ui-fixture')
        ->update(['is_del' => 1, 'is_show' => 0]);
    echo "UI fixture archived: $count\n";
    exit;
}
function addressDbAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
Db::startTrans();
try {
    $row = Db::name('system_store')->where('id', 179)->find();
    if (!$row) throw new RuntimeException('本地库缺少门店字段模板');
    unset($row['id']);
    $row = array_merge($row, ['name' => $fixtureName, 'introduction' => 'r7-address-ui-fixture',
        'address' => '江苏省扬州市邗江区竹西街道大润发(维扬店)二楼诺曼姿', 'detailed_address' => '',
        'province' => 0, 'city' => 0, 'area' => 0, 'street' => 0, 'is_del' => 0,
        'phone' => '19900007201', 'image' => '/static/images/store.png',
        'background_image' => '/static/images/store.png', 'delivery_type' => '3',
        'product_category_status' => 0, 'product_change_price_status' => 0]);
    $id = (int)Db::name('system_store')->insertGetId($row);
    $service = app()->make(SystemStoreServices::class);
    $before = Db::name('system_store')->where('id', $id)->find();
    $info = $service->getStoreDispose($id);
    addressDbAssert(count($info['addressSelect']) === 4 && $info['province'] > 0
        && $info['city'] > 0 && $info['area'] > 0 && $info['street'] > 0,
        '真实地区字典匹配江苏省/扬州市/邗江区/竹西街道');
    addressDbAssert($info['address'] === '江苏省扬州市邗江区竹西街道'
        && $info['detailed_address'] === '大润发(维扬店)二楼诺曼姿', '真实编辑读取正确拆分地区与门牌');
    addressDbAssert(Db::name('system_store')->where('id', $id)->find() === $before, '读取投影不回填主表');
    if ($mode === 'prepare') {
        Db::commit();
        echo 'UI fixture id=', $id, "\n";
        exit;
    }
    $write = array_intersect_key($info, array_flip(['address', 'detailed_address', 'province', 'city', 'area', 'street']));
    $write['product_category_status'] = 0;
    $write['product_change_price_status'] = 0;
    $service->saveStore($id, $write);
    $saved = Db::name('system_store')->where('id', $id)->find();
    addressDbAssert($saved['address'] === $info['address'] && $saved['detailed_address'] === $info['detailed_address']
        && (int)$saved['street'] === $info['street'], '既有保存服务正确落库地区 ID 与详细地址');
    $again = $service->getStoreDispose($id);
    addressDbAssert($again['addressSelect'] === $info['addressSelect']
        && $again['detailed_address'] === $info['detailed_address'], '保存后重新读取恢复同一地址');
    $service->saveStore($id, $write);
    addressDbAssert(Db::name('system_store')->where('id', $id)->find() === $saved, '重复保存地址不产生差异');
    foreach (['longitude', 'latitude', 'region_id', 'name'] as $key) {
        addressDbAssert($saved[$key] === $before[$key], '地址保存不改变其他资料：' . $key);
    }
} finally {
    Db::rollback();
}
addressDbAssert(!Db::name('system_store')->where('id', $id)->find(), '事务结束测试门店回滚，无真实业务变更');

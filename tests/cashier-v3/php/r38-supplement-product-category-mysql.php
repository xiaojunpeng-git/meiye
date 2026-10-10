<?php
declare(strict_types=1);

use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use think\facade\Db;

require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fixture = (array)Db::name('store_debt_item')->alias('i')
    ->join('store_product p', 'p.id=i.product_id AND p.is_del=0')
    ->where('i.product_id', '>', 0)
    ->field('i.debt_id,p.id product_id,p.cate_id')
    ->order('i.id desc')->find();
$category = (array)Db::name('store_product_category')->alias('child')
    ->join('store_product_category parent', 'parent.id=child.pid')
    ->where('child.type', 0)->where('child.relation_id', 0)
    ->where('parent.type', 0)->where('parent.relation_id', 0)
    ->field('child.id,child.cate_name,parent.cate_name parent_name')->order('child.id asc')->find();
if (!$fixture || !$category) {
    echo "SKIP no debt product/category fixture\n";
    exit(0);
}

$original = (string)$fixture['cate_id'];
Db::startTrans();
try {
    // The test changes only one current platform classification inside a
    // rollback transaction; no repayment or immutable business fact is touched.
    Db::name('store_product')->where('id', (int)$fixture['product_id'])
        ->update(['cate_id' => (string)$category['id']]);
    $actual = (new CashierV3MemberDebtProjectionServices())
        ->categorySummariesForDebts([(int)$fixture['debt_id']]);
    $expected = (string)$category['parent_name'] . '/' . (string)$category['cate_name'];
    if (($actual[(int)$fixture['debt_id']] ?? '') !== $expected) {
        throw new RuntimeException('current platform category was not projected: ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS supplement category follows current platform product: {$expected}\n";
} finally {
    Db::rollback();
}

if ((string)Db::name('store_product')->where('id', (int)$fixture['product_id'])->value('cate_id') !== $original) {
    throw new RuntimeException('rollback did not restore platform product category');
}

<?php
declare(strict_types=1);

use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use think\facade\Db;

require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fixture = (array)Db::name('store_debt_item')->alias('i')
    ->join('store_product p', 'p.id=i.product_id AND p.type=1')
    ->join('store_product parent', 'parent.id=p.pid AND parent.type=0')
    ->field('i.debt_id,p.id product_id,p.cate_id,parent.id platform_product_id,parent.cate_id platform_cate_id')
    ->order('i.id desc')->find();
$categories = Db::name('store_product_category')
    ->where('type', 0)->where('relation_id', 0)->where('pid', 0)
    ->field('id,cate_name')->order('id asc')->limit(2)->select()->toArray();
if (!$fixture || count($categories) < 2 || $categories[0]['cate_name'] === $categories[1]['cate_name']) {
    echo "SKIP no store SKU/platform product/category fixture\n";
    exit(0);
}

$originalStore = (string)$fixture['cate_id'];
$originalPlatform = (string)$fixture['platform_cate_id'];
Db::startTrans();
try {
    // Deliberately disagreeing catalog rows prove a debt's store SKU cannot
    // override its platform parent; neither the repayment nor facts are changed.
    Db::name('store_product')->where('id', (int)$fixture['platform_product_id'])
        ->update(['cate_id' => (string)$categories[0]['id']]);
    Db::name('store_product')->where('id', (int)$fixture['product_id'])
        ->update(['cate_id' => (string)$categories[1]['id']]);
    $actual = (new CashierV3MemberDebtProjectionServices())
        ->categorySummariesForDebts([(int)$fixture['debt_id']]);
    $expected = (string)$categories[0]['cate_name'];
    if (($actual[(int)$fixture['debt_id']] ?? '') !== $expected) {
        throw new RuntimeException('platform parent category was not projected: ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS supplement category follows platform parent, not store SKU: {$expected}\n";
    Db::name('store_product')->where('id', (int)$fixture['platform_product_id'])
        ->update(['cate_id' => '999999999']);
    $missing = (new CashierV3MemberDebtProjectionServices())
        ->categorySummariesForDebts([(int)$fixture['debt_id']]);
    if (($missing[(int)$fixture['debt_id']] ?? '') !== '未分类') {
        throw new RuntimeException('missing platform category must remain unclassified: ' . json_encode($missing, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS missing platform category remains unclassified\n";
} finally {
    Db::rollback();
}

if ((string)Db::name('store_product')->where('id', (int)$fixture['product_id'])->value('cate_id') !== $originalStore
    || (string)Db::name('store_product')->where('id', (int)$fixture['platform_product_id'])->value('cate_id') !== $originalPlatform) {
    throw new RuntimeException('rollback did not restore both product categories');
}

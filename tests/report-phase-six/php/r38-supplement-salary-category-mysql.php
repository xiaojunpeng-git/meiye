<?php
declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$repayment = (array)Db::name('cashier_v3_debt_repayment')->alias('r')
    ->join('cashier_v3_performance_fact pf', "pf.order_id=r.repayment_id AND pf.performance_type='sales_performance_allocated' AND pf.fact_direction='forward' AND pf.status='effective'")
    ->where('r.status', 'succeeded')->where('pf.employee_id', '>', 0)
    ->field('r.repayment_id,r.repayment_no,r.debt_id,r.store_id,r.business_date,r.member_name_snapshot,r.repayment_amount_cents')
    ->order('r.id desc')->find();
if (!$repayment) {
    echo "SKIP no sales-debt supplement salesperson fixture\n";
    exit(0);
}
$orderIds = Db::name('store_debt_item')->where('debt_id', (int)$repayment['debt_id'])
    ->where('product_type', 5)->column('order_id');
$componentProductIds = $orderIds === [] ? [] : Db::name('store_order_cart_info')
    ->whereIn('oid', array_values(array_unique(array_map('intval', $orderIds))))
    ->where('cart_type', 2)->where('product_type', 6)->column('product_id');
$category = (array)Db::name('store_product_category')->alias('child')
    ->join('store_product_category parent', 'parent.id=child.pid')
    ->where('child.type', 0)->where('child.relation_id', 0)
    ->where('parent.type', 0)->where('parent.relation_id', 0)
    ->field('child.id,child.cate_name,parent.cate_name parent_name')->order('child.id asc')->find();
if ($componentProductIds === [] || !$category) {
    echo "SKIP no historical card components/category fixture\n";
    exit(0);
}

$before = Db::name('store_product')->whereIn('id', $componentProductIds)->column('cate_id', 'id');
Db::startTrans();
try {
    // Only current catalog categories change inside this rollback test. The
    // repayment, employee allocation and historical monetary facts stay immutable.
    Db::name('store_product')->whereIn('id', $componentProductIds)
        ->update(['cate_id' => (string)$category['id']]);
    $result = (new StoreUnifiedReportPhaseSixServices())->query('phase_six_salary_detail',
        [(int)$repayment['store_id']], ['start'=>(string)$repayment['business_date'],'end'=>(string)$repayment['business_date']],
        ['_internal_all'=>true]);
    $matches = array_values(array_filter((array)($result['records'] ?? []), static function (array $row) use ($repayment): bool {
        return (string)($row['document_no'] ?? '') === (string)$repayment['repayment_no'];
    }));
    if (count($matches) !== 1) throw new RuntimeException('supplement salary row missing or duplicated');
    $row = $matches[0];
    $expectedCategory = (string)$category['parent_name'] . '/' . (string)$category['cate_name'];
    if ((string)$row['performance_category'] !== $expectedCategory
        || (string)$row['member_name'] !== (string)$repayment['member_name_snapshot']
        || (string)$row['store_name'] === '' || (string)$row['detail'] === ''
        || (int)$row['cash_amount_cents'] !== (int)$repayment['repayment_amount_cents']) {
        throw new RuntimeException('supplement salary dimensions invalid: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS supplement salary follows card-component current categories: {$expectedCategory}\n";
} finally {
    Db::rollback();
}

foreach ($before as $productId => $cateId) {
    if ((string)Db::name('store_product')->where('id', (int)$productId)->value('cate_id') !== (string)$cateId) {
        throw new RuntimeException('rollback did not restore component category');
    }
}

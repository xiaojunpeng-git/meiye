<?php
declare(strict_types=1);

/** 错误旧名称只能在订单中心读模型中标注，不能改写收款代码或原始快照。 */
$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';

$service = new \app\services\cashier\v3\order\CashierV3SalesOrderQueryServices();
$method = new ReflectionMethod($service, 'paymentMethodName');
$cases = [
    [['payment_method' => 'other_collection', 'payment_method_name_snapshot' => '现金'], '其他收款（原记录：现金）'],
    [['payment_method' => 'other_collection', 'payment_method_name_snapshot' => '其他收款'], '其他收款'],
    [['payment_method' => 'other_collection', 'payment_method_name_snapshot' => '自定义收款'], '自定义收款'],
    [['payment_method' => 'other_collection', 'payment_method_name_snapshot' => ''], '其他收款'],
    [['payment_method' => 'cash', 'payment_method_name_snapshot' => '现金'], '现金'],
    [['payment_method' => 'partner_collection', 'payment_method_name_snapshot' => ''], '合作方收款'],
];
foreach ($cases as [$collection, $expected]) {
    if ($method->invoke($service, $collection) !== $expected) {
        throw new RuntimeException('payment method label mismatch for ' . $collection['payment_method']);
    }
}
echo "R44_OTHER_COLLECTION_NAME_CONTRACT_OK\n";

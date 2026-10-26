<?php
declare(strict_types=1);

/** 销售列表展示占位符与更正命令原值必须分离，来源优先级保持不变。 */
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

$method = new ReflectionMethod(CashierV3SalesOrderQueryServices::class, 'businessSourceLabel');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$service = new CashierV3SalesOrderQueryServices();
$cases = [
    [['business_source_secondary_name_snapshot' => '', 'business_source_primary_name_snapshot' => '', 'business_source_label_snapshot' => ''], '—', ''],
    [['business_source_secondary_name_snapshot' => '', 'business_source_primary_name_snapshot' => '一级', 'business_source_label_snapshot' => '兜底'], '一级', '一级'],
    [['business_source_secondary_name_snapshot' => '二级', 'business_source_primary_name_snapshot' => '一级', 'business_source_label_snapshot' => '兜底'], '二级', '二级'],
];
foreach ($cases as [$row, $display, $expected]) {
    if ($method->invoke($service, $row) !== $display || $method->invoke($service, $row, false) !== $expected) {
        throw new RuntimeException('销售来源展示值与更正原值不符');
    }
}
echo "sales source display and comparison values passed\n";

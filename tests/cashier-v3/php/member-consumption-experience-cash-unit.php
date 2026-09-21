<?php

declare(strict_types=1);

// 纯内存回归：金额按分入库、按元展示，不能触碰真实报表或客户数据。
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

$annotation = new ReflectionClass(\app\services\report\StoreOperationsReportAnnotationServices::class);
$annotationInstance = $annotation->newInstanceWithoutConstructor();
$fieldType = $annotation->getMethod('fieldType');
$validate = $annotation->getMethod('validateFieldValue');
$type = $fieldType->invoke($annotationInstance, 'experience_cash');
if ($type !== 'integer_cents') {
    throw new RuntimeException('体验现金业绩必须按分保存');
}
$validate->invoke($annotationInstance, $type, '89100');
$validate->invoke($annotationInstance, $type, '');
try {
    $validate->invoke($annotationInstance, $type, '891.00');
    throw new RuntimeException('服务端接受了非整数分值');
} catch (InvalidArgumentException $expected) {
    // 页面负责元转分，服务端只接收整数分值。
}

$report = new ReflectionClass(\app\services\report\StoreUnifiedReportServices::class);
$reportInstance = $report->newInstanceWithoutConstructor();
$display = $report->getMethod('annotationDisplayValue');
foreach ([
    ['member_consumption_detail', 'experience_cash', '89100', '891'],
    ['member_consumption_detail', 'experience_cash', '0', '0'],
    ['member_consumption_detail', 'experience_cash', '-89100', '-891'],
    ['member_consumption_detail', 'experience_cash', '89123', '891'],
    ['member_consumption_detail', 'experience_cash', '', ''],
    ['member_consumption_detail', 'experience_payment_method', '89100', '89100'],
    ['partner_item_detail', 'experience_cash', '89100', '89100'],
] as [$reportCode, $field, $saved, $expected]) {
    $actual = $display->invoke($reportInstance, $reportCode, $field, $saved);
    if ($actual !== $expected) {
        throw new RuntimeException("补充字段读回金额错误：{$reportCode}/{$field}，期望 {$expected}，实际 {$actual}");
    }
}
if ($display->invoke($reportInstance, 'member_consumption_detail', 'experience_cash', '89123', true) !== '891.23') {
    throw new RuntimeException('导出必须保留手动金额的分精度');
}

echo "member consumption experience cash unit: PASS\n";

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$dimension = file_get_contents($root . '/后端代码/app/services/report/StoreOperationsReportDimensionServices.php');
$annotation = file_get_contents($root . '/后端代码/app/services/report/StoreOperationsReportAnnotationServices.php');
$controller = file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');

$codes = [
    'partner_item_summary',
    'partner_item_detail',
    'member_consumption_detail',
    'store_item_analysis',
    'store_craftsman_consumption',
    'store_salesperson_performance',
    'market_performance',
];

foreach ($codes as $code) {
    if (strpos($service, "'{$code}'") === false) {
        throw new RuntimeException("catalog missing {$code}");
    }
    if (strpos($service, "case '{$code}'") === false) {
        throw new RuntimeException("query dispatch missing {$code}");
    }
}

if (strpos($dimension, "->where('is_show', 1)") === false) {
    throw new RuntimeException('new dimension snapshots must ignore hidden categories');
}
foreach (['medical_elevation', 'medical_followup', 'expert_name', 'remark'] as $field) {
    if (strpos($annotation, "'{$field}'") === false) {
        throw new RuntimeException("annotation whitelist missing {$field}");
    }
}
foreach (['catalog', 'query', 'export', 'operationsCategories', 'saveCategory', 'annotations', 'saveAnnotation'] as $method) {
    if (strpos($controller, "function {$method}") === false) {
        throw new RuntimeException("cashier report controller missing {$method}");
    }
}

echo "store operations report contract: " . count($codes) . "/" . count($codes) . " PASS\n";

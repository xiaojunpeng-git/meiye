<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$dimension = file_get_contents($root . '/后端代码/app/services/report/StoreOperationsReportDimensionServices.php');
$annotation = file_get_contents($root . '/后端代码/app/services/report/StoreOperationsReportAnnotationServices.php');
$controller = file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
$categoryPage = file_get_contents($root . '/前端代码/admin/src/pages/product/productClassify/index.vue');

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
foreach ([
    'enabled' => 'array_key_exists(\'enabled\', $payload)',
    'switch_rejects_name' => "合作方配置只支持开关，不支持输入名称",
    'server_derived_partner_label' => '$partnerLabel = mb_substr($this->categoryPath',
] as $name => $needle) {
    if (strpos($annotation, $needle) === false) {
        throw new RuntimeException("partner category switch contract missing {$name}");
    }
}
foreach ([
    'switch' => 'v-model="row.partner_enabled"',
    'boolean_payload' => 'enabled: Number(row.partner_enabled) === 1 ? 1 : 0',
    'no_name_payload' => 'partner_name:',
] as $name => $needle) {
    if ($name === 'no_name_payload' ? strpos($categoryPage, $needle) !== false : strpos($categoryPage, $needle) === false) {
        throw new RuntimeException("partner category frontend contract failed {$name}");
    }
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

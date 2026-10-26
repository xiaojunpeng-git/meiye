<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$phaseTwo = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$normalDataScope = file_get_contents($root . '/后端代码/app/services/report/StoreReportNormalDataScopeServices.php');
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
];

foreach ($codes as $code) {
    if (strpos($service, "'{$code}'") === false) {
        throw new RuntimeException("catalog missing {$code}");
    }
    if (strpos($service, "case '{$code}'") === false) {
        throw new RuntimeException("query dispatch missing {$code}");
    }
}

foreach ([
    'void_operation_lookup' => "normal_void_operation.source_order_id = ",
    'sales_scope' => "->where('normal_void_operation.source_type', 'sales')",
    'succeeded_only' => "->where('normal_void_operation.status', 'succeeded')",
    'service_sale_line_bridge' => "->whereRaw('normal_service_sale.source_line_id = ' . \$serviceAlias . '.source_line_id')",
] as $name => $needle) {
    if (strpos($normalDataScope, $needle) === false) {
        throw new RuntimeException("normal report data scope missing {$name}");
    }
}
if (strpos($normalDataScope, "' AND normal_service_sale.source_line_id=' . \$serviceAlias") !== false) {
    throw new RuntimeException('outer service alias must not be referenced from the nested JOIN ON clause on MySQL 5.7');
}
if (strpos($service, 'excludeVoidedSalesOrderFacts') === false
    || strpos($phaseTwo, 'excludeVoidedSalesOrderServices') === false) {
    throw new RuntimeException('normal report queries must apply the successful-void visibility scope');
}

if (strpos($phaseTwo, 'COUNT(DISTINCT member_visit_service.business_date) total_visits') === false
    || strpos($phaseTwo, 'COUNT(DISTINCT member_visit_service.business_date) month_visits') === false
    || strpos($phaseTwo, "COUNT(DISTINCT CONCAT(annual_visit_service.member_id, '|', annual_visit_service.business_date))") === false
    || strpos($phaseTwo, "->where('annual_visit_service.member_id', '>', 0)") === false) {
    throw new RuntimeException('member visit reports must deduplicate the same member on the same business date');
}

if (strpos($phaseTwo, "\$this->projectOrganization(\$rows[\$id], '', '', \$range['end']);") === false
    || strpos($phaseTwo, "'store_id' => \$id, 'division_name' => ''") === false) {
    throw new RuntimeException('market performance must resolve the company from its reporting-store organization dimension');
}

if (strpos($dimension, "->where('is_show', 1)") === false) {
    throw new RuntimeException('new dimension snapshots must ignore hidden categories');
}
foreach ([
    'enabled' => 'array_key_exists(\'enabled\', $payload)',
    'partner_default_ratio' => 'partner_default_ratio',
    'ratio_validation' => '合作方默认比例必须是 0 到 100 的整数',
    'switch_rejects_name' => "合作方配置只支持开关，不支持输入名称",
    'server_derived_partner_label' => '$partnerLabel = mb_substr($this->categoryPath',
] as $name => $needle) {
    if (strpos($annotation, $needle) === false) {
        throw new RuntimeException("partner category switch contract missing {$name}");
    }
}
if (strpos($annotation, "'partner_default_ratio' => " . '$partnerDefaultRatio') === false
    || strpos($annotation, "'partnerDefaultRatio' => " . '$ratio') === false) {
    throw new RuntimeException('partner category ratio projection contract missing');
}
foreach ([
    'switch' => 'v-model="row.partner_enabled"',
    'boolean_payload' => 'enabled: Number(row.partner_enabled) === 1 ? 1 : 0',
    // The ratio is edited in the category dialog; the table intentionally only
    // exposes the partner enable/disable switch.
    'ratio_input' => "field: 'partner_default_ratio'",
    'ratio_payload' => 'partner_default_ratio:',
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
foreach (['experience_cash', 'experience_payment_method'] as $field) {
    if (strpos($annotation, "'{$field}'") === false) {
        throw new RuntimeException("annotation whitelist missing {$field}");
    }
}
if (strpos($annotation, "'experience_cash' => 'integer_cents'") === false
    || strpos($service, "\$reportCode === 'member_consumption_detail' && \$field === 'experience_cash' && \$text !== ''") === false
    || strpos($service, ': $this->money((int)$text)') === false) {
    throw new RuntimeException('experience cash annotation must persist cents and project integer yuan, preserving a cleared value');
}
if (strpos($annotation, "\$value = (string)(\$payload['field_value'] ?? '');") === false
    || strpos($service, "\$value !== ''") === false
    || strpos($service, "if (!empty(\$value))") !== false) {
    throw new RuntimeException('manual annotation empty-string clear contract missing');
}
$memberDetailStart = strpos($service, 'private function memberConsumptionDetail');
$memberDetailEnd = strpos($service, 'private function storeItemAnalysis', $memberDetailStart);
$memberDetail = substr($service, $memberDetailStart, $memberDetailEnd - $memberDetailStart);
if (strpos($memberDetail, "having('SUM(member_receipt.amount_cents)>0')") !== false
    || strpos($memberDetail, '收款为 0 的有效销售明细也会展示') === false) {
    throw new RuntimeException('member detail must not hide valid sales merely because allocated receipt is zero');
}
$partnerSummaryStart = strpos($service, 'private function partnerItemSummary');
$partnerSummaryEnd = strpos($service, 'private function partnerItemDetail', $partnerSummaryStart);
$partnerSummary = substr($service, $partnerSummaryStart, $partnerSummaryEnd - $partnerSummaryStart);
if (strpos($partnerSummary, "'performance_type','label'=>'分类'") === false) {
    throw new RuntimeException('partner summary performance type label must be 分类');
}
$defaultsPos = strpos($memberDetail, "\$row['experience_payment_method'] =");
$annotationsPos = strpos($memberDetail, "\$rows = \$this->attachAnnotations(\$rows, 'member_consumption_detail', \$storeId, !empty(\$input['_internal_all']));");
if ($defaultsPos === false || $annotationsPos === false || $annotationsPos < $defaultsPos) {
    throw new RuntimeException('member detail annotations must override fact defaults after decoration');
}
foreach ([
    'member_phone_source' => "leftJoin('user u', 'u.uid = s.member_id')",
    'member_label' => "'member_name_snapshot','label'=>'会员'",
    'phone_label' => "'member_phone','label'=>'手机'",
    'private_beauty_followup_label' => "'medical_elevation','label'=>'复诊'",
    'private_beauty_type_label' => "'medical_followup','label'=>'类型'",
    'partner_manual_fields' => "'medical_elevation', 'medical_followup', 'expert_name'",
] as $name => $needle) {
    $source = $name === 'partner_manual_fields' ? $annotation : $service;
    if (strpos($source, $needle) === false) {
        throw new RuntimeException("partner detail contract missing {$name}");
    }
}
foreach (['catalog', 'query', 'export', 'operationsCategories', 'saveCategory', 'annotations', 'saveAnnotation'] as $method) {
    if (strpos($controller, "function {$method}") === false) {
        throw new RuntimeException("cashier report controller missing {$method}");
    }
}

echo "store operations report contract: " . count($codes) . "/" . count($codes) . " PASS\n";

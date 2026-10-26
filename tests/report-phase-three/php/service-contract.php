<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php');
$foundation = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseThreeFoundationServices.php');
$controller = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$storeController = (string)file_get_contents($root . '/后端代码/app/controller/store/report/UnifiedReport.php');
$cashierV3Controller = (string)file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
$annotations = (string)file_get_contents($root . '/后端代码/app/services/report/StoreOperationsReportAnnotationServices.php');
$participants = (string)file_get_contents($root . '/后端代码/app/services/report/StoreReportParticipantScopeServices.php');
$paymentAllocations = (string)file_get_contents($root . '/后端代码/app/services/report/StoreReportPaymentSaleAllocationFactServices.php');
$lifecycle = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php');
$scopeResolver = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3ScopeResolver.php');

function phaseThreeReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

function phaseThreeMethodSource(string $source, string $method): string
{
    $pattern = '/\\n    (?:public|private|protected) function ' . preg_quote($method, '/')
        . '\\b[\\s\\S]*?(?=\\n    (?:public|private|protected) function |\\n})/';
    return preg_match($pattern, $source, $matches) === 1 ? (string)$matches[0] : '';
}

$reports = [
    'six_dimension_item_deal_analysis' => '品项成交分析表',
    'six_dimension_cash_consumption_analysis' => '现金消费分析表',
    'six_dimension_consumption_refund_detail' => '消耗及退款明细',
    'six_dimension_performance_deal' => '业绩成交表',
    'six_dimension_performance_distribution' => '业绩分布表',
    'six_dimension_performance_market_distribution' => '业绩市场分布表',
];
foreach ($reports as $code => $title) {
    phaseThreeReportAssert(
        str_contains($service, "'{$code}'") && str_contains($service, "'{$title}'"),
        "{$title} has a stable platform report code"
    );
}

foreach ([
    'experience_people' => '体验人数', 'purchase_people' => '购买人数',
    'conversion_rate' => '成交率', 'purchase_count' => '购买次数',
    'purchase_amount' => '购买金额', 'average_sale_price' => '平均销售价格',
    'average_unit_output' => '平均单产', 'total_consumption_amount' => '总消费金额',
    'complaint_count' => '客诉数量', 'refund_amount' => '退款金额',
    'new_unit_output_purchase' => '新客成交单产', 'previous_rank' => '完成率排名',
    'group_total' => '集团六维完成业绩',
] as $key => $label) {
    phaseThreeReportAssert(
        str_contains($service, "'{$key}'") && str_contains($service, "'{$label}'"),
        "field {$key} keeps the confirmed label"
    );
}

phaseThreeReportAssert(
    str_contains($service, "'source_explanation' => \$explanation")
        && str_contains($service, "'source_explanations'")
        && str_contains($service, "array_map(static function (array \$column)"),
    'every column and exported metadata use the same business source explanation'
);
phaseThreeReportAssert(
    str_contains($service, "'sticky_query' => true")
        && str_contains($service, "'sticky_header' => true")
        && str_contains($service, "'sticky_summary' => true")
        && str_contains($service, "'result_scroll' => true")
        && str_contains($service, "'summary_row' => \$summary"),
    'fixed query, header, total and result scrolling are declared by the backend'
);
phaseThreeReportAssert(
    str_contains($service, "'新客见诊人次'")
        && str_contains($service, "'新客成交人次'")
        && str_contains($service, "'新客成交业绩'")
        && str_contains($service, "'新客成交单产'")
        && str_contains($service, "'上月'") && str_contains($service, "'本月'"),
    'confirmed two-row headers are backend metadata rather than frontend guesses'
);
phaseThreeReportAssert(
    str_contains($service, "'colspan' => count(\$keys), 'rowspan' => 1")
        && str_contains($service, "\$column['header_rowspan']")
        && str_contains($service, "=== '' ? 2 : 1"),
    'multi-level headers explicitly declare grouped colspan and base-column rowspan'
);
phaseThreeReportAssert(
    str_contains($service, "cashier_v3_payment_sale_allocation_fact")
        && str_contains($service, "cashier_v3_card_sale_item_allocation_fact")
        && str_contains($service, "cashier_v3_entitlement_service_fact")
        && str_contains($service, "cashier_v3_performance_fact")
        && str_contains($service, "cashier_v3_card_operation"),
    'queries consume payment allocation, card item, service, performance and transfer facts'
);
phaseThreeReportAssert(
    str_contains($service, "original_allocation.allocation_fact_id=p.reversal_of")
        && str_contains($service, "COALESCE(original_allocation.sale_fact_id,p.sale_fact_id)")
        && str_contains($service, "fact_direction")
        && str_contains($service, "allocateAmountBySaleFact"),
    'refund and card item allocation remain line-exact and cent-conserving'
);
phaseThreeReportAssert(
    str_contains($foundation, "memberStoreAssignmentsAt")
        && str_contains($foundation, "memberOrigins(")
        && str_contains($foundation, "memberIdentityKeys")
        && str_contains($service, "COVERAGE_START = '2026-08-17'")
        && str_contains($service, 'private function coverageStart()')
        && !str_contains($service, '所选日期早于第三阶段完整事实覆盖开始日')
        && !str_contains($service, '所选月份的上月早于第三阶段完整事实覆盖期'),
    'first-purchase cutoff, historical member evidence and phone identity are explicit without rejecting query dates'
);
phaseThreeReportAssert(
    str_contains($service, "'complaint_count_version'")
        && str_contains($service, "'editable_fields'")
        && str_contains($service, "'subject_type' => 'business_event_line'")
        && str_contains($service, "cashier_v3_report_annotation"),
    'complaint count uses stable event-line manual input and refresh readback'
);
phaseThreeReportAssert(
    str_contains($controller, 'StoreUnifiedReportPhaseThreeServices')
        && str_contains($controller, "'platform_only' => true") === false
        && str_contains($controller, 'canAccessSixDimensionReport')
        && !str_contains($storeController, 'StoreUnifiedReportPhaseThreeServices'),
    'phase three reports are integrated only by the platform controller'
);
$platformAnnotations = phaseThreeMethodSource($controller, 'annotations');
$platformSaveAnnotation = phaseThreeMethodSource($controller, 'saveAnnotation');
$platformAnnotationPermission = phaseThreeMethodSource($controller, 'canAccessAnnotationReport');
phaseThreeReportAssert(
    str_contains($platformAnnotations, 'canAccessAnnotationReport')
        && str_contains($platformSaveAnnotation, 'canAccessAnnotationReport')
        && str_contains($platformAnnotationPermission, 'self::SIX_DIMENSION_REPORTS')
        && str_contains($platformAnnotationPermission, 'canAccessSixDimensionReport'),
    'platform annotation reads and writes require an explicit registered report with its menu permission'
);
phaseThreeReportAssert(
    str_contains(phaseThreeMethodSource($annotations, 'listAnnotations'), "if (\$scope['store_ids'] === []) return []")
        && str_contains(phaseThreeMethodSource($annotations, 'listAnnotations'), "assertReportCode(\$filter['report_code'] ?? '')")
        && str_contains(phaseThreeMethodSource($annotations, 'scope'), "'store_ids' => \$authorizationMode === 'self_participant' ? null : \$ids"),
    'annotation reads fail closed for a missing report code or an empty authorized store range'
);
phaseThreeReportAssert(
    str_contains($storeController, 'private const PLATFORM_ONLY_REPORTS')
        && str_contains($storeController, "return app('json')->fail('该报表仅支持平台端访问')")
        && str_contains($storeController, 'isPlatformOnlyReport'),
    'store query export and annotation endpoints explicitly reject all platform-only report codes'
);
$storeCatalog = phaseThreeMethodSource($storeController, 'catalog');
$storeQuery = phaseThreeMethodSource($storeController, 'query');
$storeExport = phaseThreeMethodSource($storeController, 'export');
$storeAnnotations = phaseThreeMethodSource($storeController, 'annotations');
$storeSaveAnnotation = phaseThreeMethodSource($storeController, 'saveAnnotation');
foreach (array_keys($reports) as $reportCode) {
    phaseThreeReportAssert(
        str_contains($storeController, "'{$reportCode}'"),
        "store platform-only denylist contains {$reportCode}"
    );
}
phaseThreeReportAssert(
    str_contains($storeCatalog, 'isPlatformOnlyReport')
        && (str_contains($storeCatalog, 'array_filter') || str_contains($storeCatalog, 'foreach')),
    'store catalog explicitly filters platform-only report codes even if the shared catalog changes'
);
foreach ([
    'query' => $storeQuery,
    'export' => $storeExport,
    'annotations' => $storeAnnotations,
    'saveAnnotation' => $storeSaveAnnotation,
] as $endpoint => $methodSource) {
    phaseThreeReportAssert(
        str_contains($methodSource, 'isPlatformOnlyReport')
            && str_contains($methodSource, '该报表仅支持平台端访问'),
        "store {$endpoint} rejects every platform-only report code before service access"
    );
}
$cashierCatalog = phaseThreeMethodSource($cashierV3Controller, 'catalog');
$cashierRespond = phaseThreeMethodSource($cashierV3Controller, 'respond');
$cashierAnnotations = phaseThreeMethodSource($cashierV3Controller, 'annotations');
$cashierSaveAnnotation = phaseThreeMethodSource($cashierV3Controller, 'saveAnnotation');
foreach (array_keys($reports) as $reportCode) {
    phaseThreeReportAssert(
        str_contains($cashierV3Controller, "'{$reportCode}'"),
        "cashier-v3 platform-only denylist contains {$reportCode}"
    );
}
phaseThreeReportAssert(
    str_contains($cashierCatalog, 'isPlatformOnlyReport') && str_contains($cashierCatalog, 'array_filter'),
    'cashier-v3 catalog explicitly filters platform-only report codes'
);
foreach (['query/export' => $cashierRespond, 'annotations' => $cashierAnnotations, 'saveAnnotation' => $cashierSaveAnnotation] as $endpoint => $methodSource) {
    phaseThreeReportAssert(
        str_contains($methodSource, 'isPlatformOnlyReport')
            && str_contains($methodSource, '该报表仅支持平台端访问'),
        "cashier-v3 {$endpoint} rejects every platform-only report code before service access"
    );
}
phaseThreeReportAssert(
    str_contains($storeAnnotations, 'catch (\\InvalidArgumentException $e)')
        && str_contains($cashierAnnotations, 'catch (\\InvalidArgumentException $e)')
        && str_contains($storeAnnotations, "return app('json')->fail(\$e->getMessage())")
        && str_contains($cashierAnnotations, "return app('json')->fail(\$e->getMessage())"),
    'store and cashier annotation reads convert invalid report codes into business failures'
);
phaseThreeReportAssert(
    str_contains($service, "\$result['param_map'] = \$paramMap")
        && str_contains($service, "['store_id' => 'store_id', 'item_id' => 'item_id']")
        && str_contains($service, "['city_manager_dimension_id' => 'city_manager_dimension_id']")
        && str_contains($service, "['drill_month' => 'month']")
        && str_contains($service, "private function drilldownStores")
        && str_contains($service, "private function drilldownRange"),
    'declared drilldowns carry exact row mappings and narrow the authorized detail scope'
);
phaseThreeReportAssert(
    !str_contains($service, "\$this->drill('purchase_people')")
        && !str_contains($service, "\$this->drill('tier_members')")
        && !str_contains($service, "\$this->drill('new_deal_purchase')"),
    'counts without an exactly reconcilable event-detail projection are not falsely declared drillable'
);
phaseThreeReportAssert(
    str_contains($service, 'private function saleLineKey')
        && str_contains($service, 'private function purchaseEventSortKey')
        && str_contains($service, "str_pad((string)max(0, (int)(\$row['occurred_at'] ?? 0))")
        && str_contains($service, "=== (string)\$event['event_sort_key']")
        && !str_contains($service, "substr((string)\$firstPurchase[\$memberKey], 0, 10) === \$date"),
    'performance deal classifies first purchase by the exact ordered business event'
);
phaseThreeReportAssert(
    str_contains($service, "->where('op.operation_type', 'card_upgrade')")
        && str_contains($service, "->whereIn('op.operation_type', ['project_upgrade', 'project_replacement'])")
        && str_contains($service, "->where('line.line_role', 'source_project')")
        && str_contains($service, "'card-operation:' . (string)\$row['operation_id']"),
    'card upgrade uses one header event while project transfer amounts use source project lines only'
);
phaseThreeReportAssert(
    str_contains($service, "->where('fact_direction', 'forward')")
        && str_contains($service, 'consumptionByServiceLine')
        && str_contains($service, 'managerBySaleLine')
        && str_contains($service, 'MAX(transfer_manager.sales_manager_name_snapshot) sales_manager_name')
        && str_contains($service, "(string)(\$transfer['sales_manager_name'] ?: '-')"),
    'service consumption and sales managers are pre-aggregated independently without multiplication'
);
phaseThreeReportAssert(
    str_contains($paymentAllocations, 'persistLifecycleReversalsInTx')
        && str_contains($paymentAllocations, 'lifecycle_payment_allocation_reversal_target_missing')
        && str_contains($paymentAllocations, '-(int)($allocations[$allocationId] ?? 0)')
        && str_contains($lifecycle, 'persistLifecycleReversalsInTx')
        && str_contains($lifecycle, '$paymentReversals = $this->writeFactReversals'),
    'refund and void lifecycle transactions append exact negative payment-sale allocations'
);
phaseThreeReportAssert(
    str_contains($service, 'MAX(financial_reversal.reversal_type) reversal_type')
        && str_contains($service, "=== 'refund'")
        && str_contains($service, "=== 'void'")
        && str_contains($service, "'event_type' => \$isRefund ? '退款' : (\$isVoid ? '作废' : '收款')"),
    'refund detail derives refund versus void from lifecycle financial reversal authority'
);
phaseThreeReportAssert(
    substr_count(phaseThreeMethodSource($service, 'purchaseItemRows'), '$this->excludeVoidedSalesOrders(') === 2
        && !str_contains(phaseThreeMethodSource($service, 'paymentAllocationQuery'), '$this->excludeVoidedSalesOrders(')
        && str_contains(phaseThreeMethodSource($service, 'excludeVoidedSalesOrders'), 'whereNotExists(')
        && str_contains(phaseThreeMethodSource($service, 'excludeVoidedSalesOrders'), "->where('void_operation.source_type', 'sales')")
        && str_contains(phaseThreeMethodSource($service, 'excludeVoidedSalesOrders'), "->where('void_operation.operation_type', 'void')")
        && str_contains(phaseThreeMethodSource($service, 'excludeVoidedSalesOrders'), "->where('void_operation.status', 'succeeded')"),
    'void cash remains append-only by business date while voided orders are excluded from successful purchase counts'
);
phaseThreeReportAssert(
    strpos($service, '$allocations = $this->allocateSigned') !== false
        && strpos($service, "!isset(\$categoryFilter[(int)\$item['category_id_snapshot']])")
            > strpos($service, '$allocations = $this->allocateSigned'),
    'card payments allocate across every configured component before category filtering'
);
phaseThreeReportAssert(
    str_contains($service, 'private function purchaseBusinessEventKey')
        && str_contains($service, "return 'event:' . \$eventNo")
        && str_contains($service, "return 'order:' . (string)(\$row['order_id'] ?? '')")
        && str_contains($service, "\$paidEvents[(string)\$cashMeta['event_key']] = true"),
    'new or returning and purchase or gift classification is consistent for the whole sale event'
);
phaseThreeReportAssert(
    str_contains($service, "'start' => max(\$range['start'], \$monthRange['start'])")
        && str_contains($service, "'end' => min(\$range['end'], \$monthRange['end'])")
        && str_contains($service, 'return $this->validRange($narrowed)'),
    'month drilldown is revalidated and cannot expand the original authorized date range'
);
phaseThreeReportAssert(
    str_contains($controller, "\$input['_authorized_store_ids'] = \$this->allowedStoreIds(0, 0)")
        && str_contains($service, "\$input['_authorized_store_ids']")
        && str_contains($service, 'sameStoreScope'),
    'cash tier totals use every authorized store while selected scope only narrows visible assignment rows'
);
phaseThreeReportAssert(
    str_contains($service, '$memberIds = $this->activeMemberIds($authorizedStores, $range)')
        && str_contains($service, '$activeByAssignmentStore[$storeId][(string)$memberId] = true')
        && str_contains($service, '$activeByAssignmentStore[(int)$row[\'store_id\']]'),
    'cash tier cohort and denominator both use period-active members grouped by cutoff assignment store'
);
phaseThreeReportAssert(
    str_contains($annotations, "'complaint_count' => 'nonnegative_integer'")
        && str_contains($annotations, "客诉数量不能小于 0")
        && str_contains($annotations, "if (\$value === ''")
        && str_contains($annotations, "客诉数量必须绑定真实业务事件")
        && str_contains($annotations, "业务事件不存在或无权编辑")
        && str_contains($participants, 'int $employeeId = 0')
        && str_contains($participants, "strpos(\$subjectKey, 'card-operation:') === 0"),
    'complaint count supports clear and resolves the real authorized business event before saving'
);
phaseThreeReportAssert(
    str_contains($service, "->where('pid', 0)")
        && str_contains($service, 'count($roots) !== 1')
        && str_contains($service, '存在多个启用的六维根商品分类'),
    'six-dimension category lookup is root-scoped and fails closed on duplicate configuration'
);
phaseThreeReportAssert(
    str_contains(phaseThreeMethodSource($service, 'performanceMarketDistribution'), "configuredDimensionsAcrossRange('company', \$range, \$stores)")
        && str_contains(phaseThreeMethodSource($service, 'configuredDimensionsAcrossRange'), 'organizationDimensions($type, $stores, $date)'),
    'market distribution dynamic company columns are clipped to authorized descendant stores'
);
phaseThreeReportAssert(
    str_contains(phaseThreeMethodSource($participants, 'applyOrder'), 'report_participant_pf.tenant_id=')
        && str_contains(phaseThreeMethodSource($participants, 'applyOrder'), 'report_participant_gf.tenant_id=')
        && str_contains(phaseThreeMethodSource($participants, 'applyOrder'), 'report_participant_mf.tenant_id=')
        && str_contains(phaseThreeMethodSource($participants, 'applyCheckout'), 'report_checkout_pf.tenant_id=')
        && str_contains(phaseThreeMethodSource($participants, 'applyCheckout'), 'report_checkout_gf.tenant_id=')
        && str_contains(phaseThreeMethodSource($participants, 'applyCheckout'), 'report_checkout_mf.tenant_id=')
        && str_contains($participants, 'subject_operation.tenant_id=subject_operation_line.tenant_id'),
    'participant permission joins bind tenant as well as order or checkout identity'
);
phaseThreeReportAssert(
    str_contains($foundation, "['company', 'city_manager']")
        && str_contains($foundation, 'matchConsumptionTier')
        && str_contains($foundation, '$matchAmount = max(0, $netCashCents);'),
    'organization dimensions and negative tier classification use the shared foundation'
);

$cashItemRows = phaseThreeMethodSource($service, 'cashItemRows');
$cashByDimension = phaseThreeMethodSource($service, 'cashByDimension');
$marketDistribution = phaseThreeMethodSource($service, 'performanceMarketDistribution');
phaseThreeReportAssert(
    str_contains($cashItemRows, "leftJoin('cashier_v3_business_event payment_event'")
        && str_contains($cashItemRows, "COALESCE(MAX(payment_event.organization_path),MAX(payment_request.organization_path),'') organization_path_snapshot")
        && str_contains($cashByDimension, '$this->dimensionForEvent(')
        && str_contains($cashByDimension, "(string)(\$row['organization_path_snapshot'] ?? '')")
        && str_contains($marketDistribution, '$this->dimensionForEvent(')
        && str_contains($marketDistribution, "(string)(\$cash['organization_path_snapshot'] ?? '')"),
    'distribution aggregation resolves company and city-manager dimensions from event organization snapshots first'
);

$tenantBoundMethods = [
    'purchaseItemRows' => ["->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)", "->where('ci.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
    'cashItemRows' => ["->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
    'paymentAllocationQuery' => ["->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
    'completedServices' => ["->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
    'serviceEventRows' => [
        "->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)",
        "->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)",
    ],
    'transferEventRows' => ["->where('op.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
    'historicalStoreIds' => ["->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
    'annotations' => ["->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)"],
];
phaseThreeReportAssert(
    str_contains($scopeResolver, "public const TENANT_SCOPE_ID = '0'"),
    'third-phase reports bind their server-owned tenant scope to tenant 0'
);
foreach ($tenantBoundMethods as $method => $needles) {
    $methodSource = phaseThreeMethodSource($service, $method);
    foreach ($needles as $needle) {
        phaseThreeReportAssert(
            str_contains($methodSource, $needle),
            "{$method} applies the explicit server tenant predicate {$needle}"
        );
    }
}

phaseThreeReportAssert(
    str_contains($service, "['key' => 'category_path', 'label' => '商品分类'")
        && str_contains($service, '=== $requestedPath')
        && str_contains($service, "\$queue = array_values(array_unique(array_map('intval', \$roots)))"),
    'category filter groups duplicate configured ids by complete path and includes every descendant'
);

echo "phase three report service contract: PASS\n";

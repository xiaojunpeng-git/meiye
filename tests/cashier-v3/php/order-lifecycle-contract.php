<?php

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php');
$reversalService = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php');
$cardOperationReversal = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3CardOperationReversalServices.php');
$inventoryReversalService = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderInventoryReversalServices.php');
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php');
$manifest = file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-收银V3订单生命周期权威/02-正式升级.sql');
$frontend = file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');
$detailFrontend = file_get_contents($root . '/前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue');
$bridgeFrontend = file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js');
$orderPartition = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterPartitionProvider.php');
$queryModule = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderQueryModule.php');
$dashboard = file_get_contents($root . '/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardReadModel.php');
$salesQuery = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$serviceVoid = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3ServiceRecordVoidServices.php');
$entitlementVersionProvider = file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementResourceVersionProvider.php');

$attributionAdjustmentStart = strpos($service, 'private function adjustAttributionFact');
$attributionAdjustmentEnd = strpos($service, 'private function attributionFactLineId');
$attributionAdjustment = $attributionAdjustmentStart !== false && $attributionAdjustmentEnd !== false
    ? substr($service, $attributionAdjustmentStart, $attributionAdjustmentEnd - $attributionAdjustmentStart)
    : '';

$checks = [
    'append_only_operation_table' => strpos($migration, 'cashier_v3_order_lifecycle_operation') !== false && strpos($migration, 'UNIQUE KEY `uk_tenant_command`') !== false,
    'reopen_keeps_source_order' => strpos($service, 'cashier_v3_order_reopen_draft') !== false && strpos($service, 'source_order_id') !== false,
    'personnel_reverses_then_reallocates' => strpos($service, 'insertReversal') !== false && strpos($service, 'insertAdjustedPerformance') !== false && strpos($service, 'personnel_adjustment_staff_ineligible') !== false
        && strpos($service, 'effectivePersonnelFactsForLine') !== false && strpos($service, 'cashPerformanceForLine') !== false
        && strpos($service, "'ORDER-PERSONNEL-ADJUST-V1'") !== false,
    'attribution_adjustment_bridges_checkout_and_order_line_ids' => strpos($service, "field('order_line_id,checkout_line_id,item_type,item_name_snapshot,sale_amount_cents')") !== false
        && strpos($service, 'attributionLineToOrderLine') !== false
        && strpos($service, 'attributionFactLineId') !== false
        && strpos($service, 'attributionFactLineIds') !== false
        && strpos($service, "->whereIn('source_line_id', \$this->attributionFactLineIds(\$line))") !== false,
    'sales_manager_adjustment_is_order_attribution_only' => strpos($attributionAdjustment, "Db::name('employee')->where('id', \$staffId)") !== false
        && strpos($service, 'personnel_adjustment_sales_manager_single_required') !== false
        && strpos($attributionAdjustment, 'cashPerformanceForLine') === false
        && strpos($attributionAdjustment, "'amount_cents' =>") === false
        && strpos($attributionAdjustment, "'allocation_weight_numerator' =>") === false,
    // 门店预置候选人与集团搜索使用同一导购资格，提交时重验以防绕过前端。
    'group_attribution_roles_match_save_eligibility' => strpos($service, "'attributionRole' => (int)(\$row['employment_type_version'] ?? 0) > 0") !== false
        && strpos($attributionAdjustment, "->field('id,name,employment_type_code,employment_type_version')") !== false
        && strpos($attributionAdjustment, "if (\$role === 'guide' && (int)(\$employee['employment_type_version'] ?? 0) <= 0)") !== false,
    'sales_void_always_closes_effective_attribution_facts' => strpos($service, "if (\$action === 'void-sales-order') {\n                // Attribution facts") !== false
        && strpos($service, "if (\$action === 'void-sales-order' && \$occurredEvents['attribution'])") === false,
    'personnel_adjustment_fact_ids_are_staff_scoped' => strpos($service, "'personnel_adjustment_duplicate_staff'") !== false
        && strpos($service, '$allocationIdentity = $operationId . \'|\' . $lineId . \'|\' . $role . \'|\' . $staffId;') !== false
        && strpos($service, 'hash_hmac(\'sha256\', $allocationIdentity') !== false
        && strpos($service, 'hash(\'sha256\', $allocationIdentity)') !== false,
    'strict_void_restores_supported_authorities_and_inventory' => strpos($service, 'CashierV3SalesOrderInventoryReversalServices') !== false
        && strpos($inventoryReversalService, "'sourceType' => 'cashier_sale_void'") !== false
        && strpos($inventoryReversalService, "'reversalOf' => (int)\$fact['id']") !== false
        && strpos($inventoryReversalService, 'sales_void_inventory_already_reversed') !== false
        && strpos($service, 'CashierV3SalesOrderReversalServices') !== false
        && strpos($service, "cashier_v3_order_lifecycle_financial_reversal") !== false
        && strpos($reversalService, 'creditBenGive') !== false
        && strpos($reversalService, 'sales_reversal_debt_already_repaid') !== false
        && strpos($reversalService, 'sales_reversal_card_benefit_used') !== false
        && strpos($migration, 'cashier_v3_order_lifecycle_debt_reversal') !== false
        && strpos($migration, 'cashier_v3_order_lifecycle_benefit_reversal') !== false,
    'reversal_uses_negative_signed_fact_amounts' => strpos($service, 'Readers sum') !== false
        && strpos($service, "foreach (\$columns as \$column) \$row[\$column] = -(int)") !== false
        && strpos($service, "'sale_amount_cents' => -\$amount") !== false
        && strpos($service, "['amount_cents' => -(int)\$amounts[\$index]]") !== false
        && strpos($service, "'allocation_base_amount_cents' => -\$amount, 'amount_cents' => -\$amount") !== false
        && strpos($dashboard, "SUM(CASE WHEN fact_direction = 'reversal'") === false,
    'event_and_context_registered' => strpos($module, 'configureServerResourceDiscovery') !== false
        && strpos($module, "\$requiredKinds = \$isReopen ? ['sales_order', 'cashier_workspace'] : ['sales_order'];") !== false
        && strpos($module, "'role' => 'sales_order'") !== false
        && strpos($module, "'role' => 'cashier_workspace'") !== false
        && strpos($module, "(array)(\$result['touchedRoles'] ?? ['sales_order'])") !== false
        && strpos($module, "['sales_order', 'member_balance'], ['sales_order', 'member_balance']") !== false
        && strpos($manifest, 'sales_order.personnel_adjusted') !== false && strpos($manifest, 'sales_order.reopened') !== false,
    'order_debt_entry_reuses_member_repayment' => strpos($service, "'nextAction' => 'open-member-debt-repayment'") !== false
        && strpos($service, "whereRaw('d.total_debt > d.repaid_debt')") !== false,
    'frontend_unwraps_action_business_data' => strpos($frontend, 'function actionData(result)') !== false
        && strpos($frontend, 'businessData.orderPersonnelAdjustment') !== false
        && strpos($frontend, 'businessData.orderLifecycle?.cashierDraft') !== false,
    'order_debt_entry_opens_member_repayment' => strpos($frontend, 'cashier-v3:open-member-debt-repayment') !== false,
    'frontend_surfaces_failed_lifecycle_action' => strpos($detailFrontend, "['failed', 'conflict', 'result_unknown'].includes(status)") !== false
        && strpos($detailFrontend, 'envelope?.result?.message') !== false,
    'frontend_releases_terminal_lifecycle_idempotency' => strpos($frontend, 'isTerminalActionStatus(actionStatus(result))') !== false,
    'service_void_uses_recorded_current_data_without_versions' => strpos($serviceVoid, "Db::name('cashier_v3_entitlement_resource_version')") === false
        && strpos($serviceVoid, 'service_void_entitlement_version_missing') === false
        && strpos($serviceVoid, "Db::name('store_order_cart_info')->where('id', \$detailId)->lock(true)") !== false
        && strpos($serviceVoid, "Db::name('user_card_holder')->where('id', \$holderId)->lock(true)") !== false
        && strpos($serviceVoid, "'write_surplus_times' => \$remaining") !== false
        && strpos($serviceVoid, "\$touchedRoles = [];") !== false
        && strpos($module, "'void-service-record',\n            [],\n            [],") !== false
        && strpos($module, "\$serviceVoidPolicy->configureServerResourceDiscovery") === false
        && strpos($entitlementVersionProvider, "\$serviceVoidMutation") === false,
    'service_void_frontend_sends_no_version_context' => strpos($bridgeFrontend, "if (action === 'void-service-record')") !== false
        && strpos($bridgeFrontend, "['void-order-center-supplement', 'void-service-record'].includes(canonicalAction)") !== false,
    'lifecycle_commands_send_sales_order_version' => strpos($bridgeFrontend, "'adjust-sales-order-personnel', 'refund-sales-order', 'void-sales-order', 'reopen-sales-order'") !== false
        && strpos($bridgeFrontend, "buildCommandContext('sales_order', payload.orderId)") !== false
        && strpos($bridgeFrontend, "'reopen-sales-order',") !== false,
    'order_center_publishes_lifecycle_sales_order_versions' => strpos($orderPartition, "'public_versions' => \$this->recordPublicVersions(\$payload, \$dataScope)") !== false
        && strpos($orderPartition, 'CashierV3OrderLifecycleServices::OPERATION_TABLE') !== false
        && strpos($orderPartition, "'source_type', 'sales'") !== false
        && (strpos($orderPartition, "'version' => 1 + (int)(\$operationCounts[\$id] ?? 0)") !== false
            || strpos($orderPartition, "'version' => \$knownVersions[\$id] ??") !== false),
    'legacy_display_ids_do_not_replace_sales_order_resource_ids' => strpos($salesQuery, "'lifecycleOrderId' => \$v3Ready ? (string)\$v3Header['order_id'] : ''") !== false
        && strpos($orderPartition, "\$order['lifecycleOrderId'] ?? \$order['lifecycle_order_id']") !== false
        && strpos($frontend, 'function salesOrderLifecycleId(record)') !== false
        && strpos($frontend, 'const salesOrderLifecycleResourceActions = new Set([') !== false
        && strpos($frontend, 'orderId: requestOrderId,') !== false,
    'sales_order_main_item_exposes_upgrade_type_tag' => strpos($salesQuery, "'upgradeTypeLabel' => \$upgradeTypeLabel") !== false
        && strpos($salesQuery, "\$upgradeType === 'project_upgrade' ? '项目升级'") !== false
        && strpos($salesQuery, "\$upgradeType === 'card_upgrade' ? '卡升级'") !== false
        && strpos($frontend, 'record.upgradeTypeLabel') !== false,
    'refund_line_allocation_uses_whole_yuan_largest_remainder' => strpos($service, 'if ($target % 100 === 0)') !== false
        && strpos($service, '$targetYuan = intdiv($target, 100);') !== false
        && strpos($service, '$result[$remainders[$index][\'index\']] += 100;') !== false
        && strpos($service, 'Preserve cent-level accounting for a non-whole-yuan component') !== false,
    'sales_order_queries_refresh_lifecycle_versions' => substr_count($queryModule, "'versions' => (new CashierV3OrderCenterPartitionProvider") >= 2,
    'sales_order_authority_filters_terminal_lifecycle_status' => strpos($salesQuery, 'applyAuthorityStatusFilter') !== false
        && strpos($salesQuery, "['', 'normal', 'refunded', 'voided']") !== false
        && strpos($salesQuery, "where('olo.operation_type', \$operationType)") !== false
        && substr_count($salesQuery, "whereRaw('olo.tenant_id = o.tenant_id')") === 2,
    'sales_order_detail_derives_terminal_status_and_actions' => strpos($salesQuery, "['refund', 'void']") !== false
        && strpos($salesQuery, "'orderStatus' => \$orderStatus") !== false
        && strpos($salesQuery, "\$terminal ? ['reopen-sales-order']") !== false
        && strpos($salesQuery, "'operationLogs' => \$operationRecords") !== false,
    'sales_order_related_status_has_chinese_label' => strpos($salesQuery, "'statusLabel' => \$this->operationStatusLabel(\$status)") !== false
        && strpos($salesQuery, "'succeeded' => '已完成'") !== false
        && strpos($detailFrontend, "const statusLabel = pickValue(record, ['statusLabel', 'status_label'])") !== false,
    'terminal_reversal_keeps_personnel_snapshot' => strpos($salesQuery, "->where('operation_type', 'personnel_adjustment')") !== false
        && strpos($salesQuery, "\$adjustmentCommandKeys") !== false
        && strpos($salesQuery, "isset(\$adjustmentCommandKeys[(string)(\$row['command_idempotency_key'] ?? '')])") !== false
        && strpos($salesQuery, "effectivePersonnelFacts(\$orderIds, \$tenantIds[0])") !== false,
    'financial_reversal_records_cash_reversal_separately' => strpos($service, "'reversed_cash_cents' => \$reversedCash") !== false
        && strpos($service, "'cash_refund_cents' => \$cash") !== false,
    'zero_value_sales_void_remains_auditable_without_a_fake_financial_reversal' => strpos($reversalService, "\$isZeroValueVoid = \$action === 'void-sales-order'") !== false
        && strpos($reversalService, "&& (int)\$source['amountCents'] === 0") !== false
        && strpos($reversalService, "(!\$isZeroValueVoid && \$economicReversal <= 0)") !== false,
    'reversal_audits_cover_debt_and_benefits' => strpos($reversalService, 'DEBT_REVERSAL_TABLE') !== false
        && strpos($reversalService, 'BENEFIT_REVERSAL_TABLE') !== false
        && strpos($reversalService, "'cancelled_debt_cents'") !== false
        && strpos($reversalService, "'benefit_detail_ids_json'") !== false,
    'sales_order_lifecycle_detail_is_tenant_scoped_and_auditable' => strpos($salesQuery, "->where('tenant_id', \$tenantIds[0])") !== false
        && strpos($salesQuery, 'reversed_cash_cents') !== false
        && strpos($salesQuery, "'reversedCashAmount'") !== false
        && strpos($salesQuery, "'actionLabel'") !== false
        && strpos($salesQuery, "'operatorName' => '操作人#'") !== false,
    'refund_is_financial_only_and_allows_inventory_sales' => strpos($service, "if (\$action === 'refund-sales-order')") !== false
        && strpos($reversalService, 'prepareFinancialRefund') !== false
        && strpos($salesQuery, "'refund-sales-order',") !== false
        && strpos($salesQuery, '已退款作废') !== false,
    'upgrade_reversal_restores_source_rights_without_member_balance_credit' => strpos($service, 'CashierV3CardOperationReversalServices') !== false
        && strpos($cardOperationReversal, "'settlement_status' => 'reversed'") !== false
        && strpos($cardOperationReversal, "'card_upgrade_use_oid' => 0") !== false
        && strpos($cardOperationReversal, "'write_surplus_times' => \$before") !== false
        && strpos($cardOperationReversal, 'UserBalanceAtomicServices') === false
        && strpos($reversalService, 'entitlementCreditCents') !== false
        && strpos($service, 'card_operation_upgrade_refund_must_reverse_full_order') !== false,
    'lifecycle_eligibility_and_reopen_queries_are_tenant_scoped' => strpos($service, "Db::name('cashier_v3_entitlement_service_fact')\n            ->where('tenant_id', \$scope->tenantId())") !== false
        && strpos($service, 'voidOrderAttributionFactsInTx') !== false
        && strpos($service, 'sales_void_attribution_reversal_race') !== false
        && strpos($service, ':service:') !== false
        && strpos($service, "newExecution(\n                'void-service-record'") !== false
        && strpos($service, "whereIn('reversal_of', \$forwardFactIds)") !== false
        && strpos($service, '尚无 reversal_of 的同单业绩事实') !== false
        && strpos($service, "Db::name(self::OPERATION_TABLE)->where('tenant_id', \$scope->tenantId())") !== false
        && strpos($service, "Db::name('cashier_v3_sales_order_line')->where('tenant_id', \$scope->tenantId())") !== false,
    'void_cascade_follows_checkout_event_set' => strpos($service, 'occurredCheckoutEvents') !== false
        && strpos($service, "\$action === 'void-sales-order' && \$occurredEvents['inventory']") !== false
        && strpos($service, "\$action === 'void-sales-order' && \$occurredEvents['service']") !== false
        && strpos($service, "\$action === 'void-sales-order') {\n                // Attribution facts") !== false
        && strpos($service, "\$nested->where('source_id', \$requestId)") !== false
        && strpos($service, "'checkout' => isset(\$types['checkout.completed'])") !== false
        && strpos($service, "'attribution' => isset(\$types['service.completed'])") === false,
    'detail_reads_optional_domains_from_event_set' => strpos($salesQuery, 'eventTypesByOrder') !== false
        && strpos($salesQuery, "checkout.completed") !== false
        && strpos($salesQuery, "if (\$refundOrderIds !== [])") !== false
        && strpos($salesQuery, "isset(\$eventTypesByOrder[\$orderId]['debt.recorded'])") !== false
        && strpos($salesQuery, "'eventTypes' => array_keys") !== false,
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo $failed === 0 ? "ORDER_LIFECYCLE_CONTRACT=PASS\n" : "ORDER_LIFECYCLE_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);

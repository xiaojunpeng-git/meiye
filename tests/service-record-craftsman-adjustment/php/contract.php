<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$adjustmentFile = $root . '/后端代码/app/services/cashier/v3/order/CashierV3ServiceRecordCraftsmanAdjustmentServices.php';
$adjustment = (string)file_get_contents($adjustmentFile);
$orderQuery = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$orderModule = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php');
$resourceCatalog = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3ResourceKindCatalog.php');
$versionProvider = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider.php');
$bridge = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js');
$backendManifest = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php');
$frontendManifest = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3ActionManifest.js');
$orderView = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');
$personnelOverlay = (string)file_get_contents($root . '/前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue');
$reportFile = $root . '/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php';
$report = (string)file_get_contents($reportFile);
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-20-服务记录手艺人调整/02-正式升级.sql');

$passed = 0;
$failed = 0;
function adjustmentCheck(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

adjustmentCheck(
    'entry and save share service detail permission snapshot',
    str_contains($backendManifest, "'open-service-record-craftsman-adjustment' => self::FEATURE_ORDER_SERVICE_DETAIL")
    && str_contains($backendManifest, "\$command['adjust-service-record-craftsmen'] = self::FEATURE_ORDER_SERVICE_DETAIL")
    && str_contains($frontendManifest, "'open-service-record-craftsman-adjustment': FEATURE_ORDER_SERVICE_DETAIL")
    && str_contains($frontendManifest, "'adjust-service-record-craftsmen': FEATURE_ORDER_SERVICE_DETAIL")
);
adjustmentCheck(
    'existing personnel overlay is reused in history mode',
    str_contains($orderView, "import PersonnelPerformanceOverlay from '@/components/cashier/PersonnelPerformanceOverlay.vue'")
    && str_contains($orderView, '<PersonnelPerformanceOverlay')
    && str_contains($orderView, 'history-adjustment')
    && str_contains($orderView, ':data-service-fact-id="record.serviceFactId || record.id"')
    && str_contains($orderView, '>修改手艺人</button>')
    && str_contains($orderView, '@click="openServiceCraftsmanAdjustment(record)"')
    && str_contains($personnelOverlay, "historyAdjustment: { type: Boolean, default: false }")
);
adjustmentCheck(
    'history adjustment retains local selections and supports organisation support craftsmen',
    str_contains($personnelOverlay, "mergeWithLocalSelections(candidates, selected, craftsmen.value, 'craftsmen')")
    && str_contains($personnelOverlay, '<div class="personnel-performance-mode" aria-label="分配模式">')
    && !str_contains($personnelOverlay, 'v-if="!historyAdjustment" class="personnel-performance-mode"')
    && str_contains($orderView, 'allow-other-craftsmen')
    && str_contains($orderView, ':other-craftsman-candidates="serviceCraftsmanEntry.otherCraftsmanCandidates || []"')
    && str_contains($orderView, '@search-personnel="searchServiceCraftsmen"')
    && str_contains($orderView, "selectorEntry: 'order_center'")
    && str_contains($adjustment, 'CashierV3PersonnelIdentity::organizationStaffId($employeeId)')
    && str_contains($adjustment, "'personnelSource' => 'other'")
    && str_contains($adjustment, '所选支援手艺人已停用或不在当前组织范围内。')
);
adjustmentCheck(
    'checkout-selected support craftsmen remain visible when a zero-value labor fact is intentionally absent',
    str_contains($adjustment, 'if ($byEmployee === []) return $this->presentSnapshotAllocations($source);')
    && str_contains($adjustment, 'private function presentSnapshotAllocations(array $source): array')
    && str_contains($adjustment, 'private function decodeLockedCraftsmenSnapshot(string $json): array')
    && str_contains($adjustment, "'staff_id', 'employee_id', 'staff_name_snapshot'")
    && str_contains($adjustment, 'CashierV3CheckoutCraftsmenSnapshot::decode($json)')
    && str_contains($adjustment, "'personnelSource' => \$sourceKind")
    && str_contains($adjustment, '不创建用于展示的零值业绩事实')
);
adjustmentCheck(
    'reason is collected by a second dialog after assignment confirmation',
    str_contains($orderView, '@confirm="prepareServiceCraftsmanReason"')
    && str_contains($orderView, 'serviceCraftsmanPendingAssignment')
    && str_contains($orderView, '填写修改原因')
    && str_contains($orderView, 'maxlength="255"')
);
adjustmentCheck(
    'history ratio supports decimals without legacy integer validation',
    str_contains($personnelOverlay, ":step=\"historyAdjustment ? '0.01' : '1'\"")
    && str_contains($personnelOverlay, "!props.historyAdjustment && !allocationIsValid(selectedCraftsmen, 'craftsmen')")
    && str_contains($personnelOverlay, '!props.historyAdjustment && ![\'guides\', \'salesManagers\'].includes(role)')
);
adjustmentCheck(
    'amount and ratio are linked while final manual allocations are retained',
    str_contains($personnelOverlay, 'syncHistoryAmountFromRatio(item)')
    && str_contains($personnelOverlay, 'syncHistoryRatioFromAmount(item)')
    && !str_contains($adjustment, 'service_adjust_amount_total_mismatch')
    && !str_contains($personnelOverlay, 'historyAmountIsAutoBalanced(item)')
    && str_contains($personnelOverlay, 'step="1" inputmode="numeric" aria-label="分配消耗业绩"')
    && str_contains($adjustment, 'service_adjust_amount_not_whole_yuan')
    && str_contains($adjustment, "'allocationInputMode' => 'manual'")
    && str_contains($personnelOverlay, '保存时以最终输入金额为准')
);
adjustmentCheck(
    'labor-only history adjustment skips consumption allocation and explains it in the UI',
    str_contains($personnelOverlay, 'const historyOnlyLaborFee = computed')
    && str_contains($personnelOverlay, '仅手工费，不记录消耗业绩')
    && str_contains($adjustment, "if (\$type === 'labor' && \$amount !== 0)")
    && !str_contains($adjustment, 'service_adjust_amount_total_mismatch')
);
adjustmentCheck(
    'legacy service records fall back to the effective labor-performance net amount',
    str_contains($adjustment, "->where('performance_type', 'labor_performance_allocated')->where('status', 'effective')")
    && str_contains($adjustment, "->sum('amount_cents')")
    && str_contains($adjustment, '兼容已存在的历史服务记录')
);
adjustmentCheck(
    'project count uses signed half-unit facts and one-decimal UI',
    str_contains($migration, '`project_count_half_units` bigint(20) NOT NULL DEFAULT 0')
    && !str_contains($migration, '`project_count_half_units` bigint(20) unsigned')
    && str_contains($personnelOverlay, 'step="0.5"')
    && str_contains($adjustment, "'projectCountStep' => '0.5'")
    && str_contains($adjustment, "'projectCountDecimals' => 1")
);
adjustmentCheck(
    'zero is an explicit adjusted project count instead of falling back to service quantity',
    str_contains($adjustment, "'hasExplicitProjectCount' =>")
    && str_contains($orderQuery, "'hasExplicitProjectCount' => false")
    && str_contains($report, "['has_explicit_project_count']=false;")
);
adjustmentCheck(
    'adjustment is versioned idempotent and audited',
    str_contains($adjustment, 'command_idempotency_key')
    && str_contains($adjustment, 'service_adjust_version_conflict')
    && str_contains($adjustment, 'before_snapshot_json')
    && str_contains($adjustment, 'after_snapshot_json')
    && str_contains($adjustment, 'reason_snapshot')
    && str_contains($adjustment, 'operator_name_snapshot')
);
adjustmentCheck(
    'old facts are reversed and replacement facts are appended',
    str_contains($adjustment, "\$row['fact_direction'] = 'reversal'")
    && str_contains($adjustment, "\$row['reversal_of'] = (string)\$source['fact_id']")
    && str_contains($adjustment, "\$row['fact_direction'] = 'forward'")
    && str_contains($adjustment, "'event_type' => 'service_record.craftsmen_adjusted'")
);
adjustmentCheck(
    'command is registered with a transaction policy',
    str_contains($orderModule, "registerCommand('adjust-service-record-craftsmen'")
    && str_contains($orderModule, "new CashierV3ContextPolicy(\n            'adjust-service-record-craftsmen'")
    && str_contains($adjustment, "assertInTransaction('serviceRecordCraftsmanAdjustment.executeInTx')")
);
adjustmentCheck(
    'history adjustment uses the service record version instead of cashier workspace state',
    str_contains($resourceCatalog, "'service_record' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 61")
    && str_contains($versionProvider, 'implements CashierV3DataScopedVersionProvider')
    && str_contains($orderModule, 'CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider::KIND')
    && str_contains($orderModule, "'adjust-service-record-craftsmen',\n            ['service_record']")
    && str_contains($orderModule, "'required_touched_roles' => ['service_record']")
    && str_contains($orderModule, "'versions' => [[")
    && str_contains($bridge, "buildCommandContext('service_record', payload.serviceFactId)")
    && str_contains($orderView, 'mergeCashierV3PublicVersions([{')
    && !str_contains($orderModule, "'adjust-service-record-craftsmen',\n            ['cashier_workspace']")
);
adjustmentCheck(
    'adjustment service does not write entitlement order refund or service source tables',
    !preg_match("/Db::name\\('(?:cashier_v3_entitlement_service_fact|cashier_v3_sale_fact|cashier_v3_refund_fact)'\\)\s*->(?:update|delete|insert)/", $adjustment)
    && str_contains($adjustment, '会员权益次数、')
    && str_contains($adjustment, '销售订单及')
    && str_contains($adjustment, '退款状态均不是本动作的写域')
);
adjustmentCheck(
    'salary report reads active labor facts and exact half-unit project counts',
    str_contains($report, "->where('pf.performance_type','labor_performance_allocated')")
    && str_contains($report, "\$sum+=(int)round((float)\$v*2)")
    && str_contains($report, "number_format(\$sum/2,1,'.','')")
    && str_contains($report, "(string)\$row['fact_direction']!=='forward'")
);

require_once $reportFile;
require_once $adjustmentFile;
adjustmentCheck(
    'manual final allocation does not use a fixed project-total gate',
    !str_contains($adjustment, 'allocationTotalMatches(')
    && !str_contains($adjustment, 'service_adjust_amount_total_mismatch')
    && str_contains($adjustment, "'allocationInputMode' => 'manual'")
);
$reportService = new \app\services\report\StoreUnifiedReportPhaseSixServices();
$resultMethod = new ReflectionMethod($reportService, 'result');
$summary = $resultMethod->invoke($reportService, '半项汇总', [[
    'key' => 'project_count', 'label' => '项目数', 'source_explanation' => '',
    'summable' => true, 'width' => 120,
]], [['project_count' => '0.5'], ['project_count' => '1.0']], ['start' => '2026-08-01', 'end' => '2026-08-31']);
adjustmentCheck('0.5 plus 1.0 remains 1.5 in report summary', ($summary['summary_row']['project_count'] ?? null) === '1.5');

echo "SERVICE_RECORD_CRAFTSMAN_ADJUSTMENT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

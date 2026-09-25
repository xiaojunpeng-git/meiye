import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')
const router = read('前端代码/cashier-v3/src/router/index.js')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const view = read('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue')
const orderCenterView = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const selectorService = read('后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php')
const api = read('前端代码/cashier-v3/src/services/storeBusinessReportApi.js')
const route = read('后端代码/route/cashier-v3.php')
const controller = read('后端代码/app/controller/cashier/v3/Report.php')
const adminRoute = read('后端代码/route/admin.php')
const adminController = read('后端代码/app/controller/admin/v1/report/UnifiedReport.php')
const reportService = read('后端代码/app/services/report/StoreUnifiedReportServices.php')
const phaseTwoReportService = read('后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php')
const participantScope = read('后端代码/app/services/report/StoreReportParticipantScopeServices.php')
const annotationService = read('后端代码/app/services/report/StoreOperationsReportAnnotationServices.php')
const platformReport = read('前端代码/admin/src/pages/report/data/store_business.vue')
const viteConfig = read('前端代码/cashier-v3/vite.config.js')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) { passed += 1; console.log(`PASS ${name}`) } else { failed += 1; console.log(`FAIL ${name}`) }
}

check('data navigation targets the Cashier V3 report route', shell.includes("to: { name: 'cashier-v3-store-business-reports' }") && router.includes("name: 'cashier-v3-store-business-reports'"))
const reportCodes = [
  'partner_item_summary',
  'partner_item_detail',
  'member_consumption_detail',
  'store_item_analysis',
  'store_craftsman_consumption',
  'store_salesperson_performance',
  'market_performance',
  'market_detail',
  'member_visit_analysis',
  'member_visit_annual_summary',
  'field_acquisition_detail',
  'field_acquisition_summary',
  'cross_industry_customer_detail',
  'cross_industry_customer_summary',
  'new_customer_analysis',
  'new_customer_analysis_summary',
  'salesperson_large_order_statistics',
  'store_refund_ledger'
]
check('report functions are upper-page tabs', reportCodes.every((code) => view.includes(`code: '${code}'`)) && view.includes('const REPORT_TABS') && view.includes('store-business-report__tabs') && view.includes('v-for="item in reportTabs"') && !view.includes("activeReport === 'overview'"))
check('craftsman consumption keeps the audited fact-detail API but does not expose it as a navigation tab', view.includes("code: 'store_craftsman_consumption_detail', name: '手艺人消耗明细', hidden: true") && view.includes('allowedReportTabs.value.filter((tab) => !tab.hidden)') && view.includes('function drilldownConfig'))
check('craftsman consumption opens exact service records directly instead of the old modal', view.includes("request.report === 'order_center_service'") && view.includes("name: isPlatformReport.value ? 'cashier-v3-platform-order-center' : 'cashier-v3-order-center'") && view.includes('report_employee_id: request.params.craftsman_id') && view.includes('report_day_of_month: request.params.day_of_month') && !view.includes('craftsmanDrilldown.open'))
check('service-record drilldown can return to the original report filters', view.includes('report_return_to: returnTo') && view.includes('query: currentRouteQuery()') && orderCenterView.includes('返回报表') && orderCenterView.includes('function returnToServiceReport()') && orderCenterView.includes("resolved.params.report === 'store_craftsman_consumption'"))
check('phase-two reports are dispatched by the unified report service', reportService.includes('StoreUnifiedReportPhaseTwoServices') && reportCodes.slice(6).every((code) => phaseTwoReportService.includes(`'${code}'`)))
check('report tabs preserve the active runtime in stable deep links', router.includes("path: 'data/reports/:report?'") && router.includes("path: '/platform/reports/:report?'") && view.includes("router.push({ name: reportRouteName.value, params: { report: code } })"))
check('all 22 store-operation reports default to the first day of the current month', view.includes('All 22 store-operation reports use one date-range default') && view.includes('!route.query?.start_date && !route.query?.end_date') && view.includes("startDate.value = `${today().slice(0, 7)}-01`") && view.includes('endDate.value = today()'))
check('new-customer reports explain guide attribution', phaseTwoReportService.includes("'guide'=>'导购'") && phaseTwoReportService.includes("'key' => 'guide_id'") && phaseTwoReportService.includes('guideFilterOptions') && view.includes('guide: \'本笔新客业务在结账时确认的导购人员'))
check('browser uses the explicit store or platform report adapter, never a legacy store endpoint', api.includes('/cashierapi/v3/report/${suffix}') && api.includes('/adminapi/report/${suffix}') && !api.includes('/storeapi/report/'))
check('V3 route exposes catalog, scope, query and export', route.includes("Route::get('report/unified/catalog', 'Report/catalog')") && route.includes("Route::get('report/unified/scope', 'Report/scope')") && route.includes("Route::get('report/unified/query', 'Report/query')") && route.includes("Route::get('report/unified/export', 'Report/export')"))
check('controller resolves employee scope and narrows requested stores', controller.includes('dataScopeFactory()->build') && controller.includes('scopeStoreIds') && controller.includes('array_intersect($requested, $allowed)'))
const controllerInputRules = controller.slice(controller.indexOf('private function inputRules'), controller.indexOf('private function dataScope'))
check('self participant employee identity is server-derived and client spoofing is ignored', controller.includes('$dataScope->employeeId()') && controller.includes("$input['_report_scope']") && !controllerInputRules.includes('participant_employee_id'))
check('NONE report scope is rejected before query execution', controller.includes('CashierV3DataScopeContext::MODE_NONE') && reportService.includes("($reportScope['mode'] ?? '') === 'none'"))
check('participant provider covers salesperson guide manager and service participation', participantScope.includes('cashier_v3_performance_fact') && participantScope.includes('cashier_v3_customer_guide_round_fact') && participantScope.includes('cashier_v3_sales_manager_fact') && participantScope.includes('applyCheckout'))
check('SELF organization picker is limited to stores with own participation', controller.includes('participatingStoreIds') && participantScope.includes('public function participatingStoreIds'))
check('query export drilldown and manual annotations share participant scope', controller.includes('return $this->respond($request, $services, $phaseFour, $phaseSix, false)') && controller.includes('return $this->respond($request, $services, $phaseFour, $phaseSix, true)') && view.includes('openDrilldown') && view.includes('queryStoreBusinessReport') && controller.includes('$this->annotationContext(true)') && annotationService.includes('resolveSubject') && annotationService.includes('annotationIsVisible'))
check('scope picker matches the platform twin-pane organization and store interaction', view.includes('store-business-report__scope-panel-body') && view.includes('store-business-report__scope-tree') && view.includes('store-business-report__scope-stores') && view.includes('selectScopeOrganization') && view.includes('selectScopeStore') && view.includes('选择组织查询其全部下级门店；选择门店仅查询该门店。'))
check('status metadata is not rendered while pending metrics remain visible', !view.includes('aria-label="报表数据状态"') && view.includes('pendingMetrics'))
check('person filters use keyword-gated controlled selector modal', view.includes("requestCashierV3Action('query-query-entities'") && view.includes('openPersonnelPicker') && view.includes('personnelPicker.open') && view.includes('默认不加载人员') && selectorService.includes("'group_sales_managers'") && selectorService.includes("'group_guides'") && selectorService.includes("mb_strlen($keyword) < 2"))
check('report columns and dynamic payment groups come from server metadata', view.includes('result.value.column_groups') && view.includes('result.value.columnGroups') && view.includes('const columnGroups = computed') && view.includes('buildColumnGroupHeaderRows') && !view.includes('paymentMethods.reduce'))
check('wide report headers distinguish business sections without changing column metadata', view.includes('function tableHeaderTone(column)') && view.includes("payment: '支付现金业绩方式'") && view.includes("partner: '合作方分成业绩'") && view.includes("result: '经营结果'") && view.includes('store-business-report__header-group--payment') && view.includes('reportColumnGroupEndKeys') && view.includes('store-business-report__cell--group-end') && view.includes('border-right: 8px solid #f5f7fa'))
check('every report can show current-column business explanations', view.includes('列名取值来源') && view.includes('currentFieldExplanations') && view.includes('function fieldLogic(column)') && view.includes("key.startsWith('payment_')") && view.includes("/^day_\\d+_consume$/"))
check('dynamic partner share columns explain the frozen category-rate calculation', view.includes("key.startsWith('partner_category_')") && view.includes('合作方默认比例') && view.includes('不会改写已成交数据'))
check('editable report rows use line-level stable keys', view.includes(':key="rowKey(row) || `${activeReport}:${rowIndex}`"') && view.includes('row?.annotation_subject_key || row?.source_line_id'))
check('partner detail uses the manual input solution fields', view.includes('PARTNER_MANUAL_FIELDS') && view.includes('saveEdit') && view.includes('editDraft') && view.includes("label: '复诊'") && view.includes("label: '类型'") && view.includes('expert_name'))
check('manual input solution can persist an explicit empty value', view.includes('changedFields') && view.includes('field_value: value') && view.includes("String(row[key] ?? '') !== String(editDraft.value[key] ?? '').trim()"))
check('shared report view keeps the annotation key and version contract', view.includes('row?.annotation_subject_key || row?.source_line_id || row?.order_line_id || row?.order_id') && view.includes('expected_version') && view.includes('idempotency_key') && view.includes('editable_fields'))
check('market detail accepts backend-issued guest subjects without inventing member identity', view.includes('市场明细的会员日行和游客订单行都由后端下发稳定主键') && !view.includes('本行缺少会员编号'))
check('guest market edits have server-verified order subjects and feed the performance summary', phaseTwoReportService.includes("'market_guest_order'") && phaseTwoReportService.includes('marketGuestOrderKey') && participantScope.includes("$subjectType === 'market_guest_order'") && annotationService.includes("['market_member_day', 'market_guest_order']"))
check('platform report route bypasses the cashier session and workbench bootstrap', router.includes("path: '/platform/reports/:report?'") && router.includes("platformReport: true") && router.includes('if (to.meta.platformReport === true) return true'))
check('platform six-dimension deep links cannot fall back to the store report runtime and do not impose a date cutoff', view.includes("String(route.path || '').startsWith('/platform/')") && view.includes('SIX_DIMENSION_REPORT_TABS') && !view.includes('SIX_DIMENSION_COVERAGE_START'))
check('platform adapter uses the administrator cookie and platform report endpoints only in platform mode', api.includes('readPlatformAdminToken') && api.includes("/adminapi/report/${suffix}") && api.includes("credentials: platform ? 'same-origin' : 'omit'") && api.includes("'unified/personnel'"))
check('local Vue 3 platform report requests use the platform gateway without rerouting other admin calls', viteConfig.includes("const platformApiTarget") && viteConfig.includes("'/adminapi/report'") && viteConfig.includes('target: platformApiTarget'))
check('local starter injects the host platform gateway into the Vue 3 container', read('scripts/start-local.sh').includes('PLATFORM_API_PROXY_TARGET="http://host.docker.internal:${PLATFORM_API_PORT}"'))
check('platform scope and personnel endpoints are server-clipped to the administrator range', adminRoute.includes("Route::get('unified/scope', 'v1.report.UnifiedReport/scope')") && adminRoute.includes("Route::get('unified/personnel', 'v1.report.UnifiedReport/personnel')") && adminController.includes('buildPickerTree($allowed)') && adminController.includes('resolveScopedStoreIdsFromRequest') && adminController.includes("whereIn('ss.store_id', $allowed)"))
check('platform reports reuse employee personal store and organization data scope', adminController.includes('EmployeeDataScopeServices') && adminController.includes('resolveEffectiveStoreIds($employeeId') && adminController.includes('resolvePrimaryHqScopeMode($employeeId)') && adminController.includes("participatingStoreIds('0', $employeeId)") && adminController.includes("$input['_report_scope']") && adminController.includes("'participant_employee_id' => $selfParticipant") && !adminController.slice(adminController.indexOf('private function inputRules'), adminController.indexOf('private function scopedStoreIds')).includes('participant_employee_id'))
check('Vue 2 report pages only redirect to the Vue 3 platform route', platformReport.includes("name: 'StoreBusinessReportFrame'") && platformReport.includes('/view_cashier_v3/') && platformReport.includes('#/platform/reports/') && !platformReport.includes('unifiedBusinessReportQuery'))
check('platform manual input sends the source row store scope', view.includes('function annotationStoreScope(row)') && view.includes('...annotationStoreScope(row)') && reportService.includes('s.store_id,s.business_date'))
const salespersonReport = reportService.slice(reportService.indexOf('private function salespersonPerformance'), reportService.indexOf('private function storeIds'))
check('salesperson report has only one daily performance column and no labor columns', salespersonReport.includes("'day_'.$day.'_performance'") && salespersonReport.includes("'label'=>$day.'日业绩'") && salespersonReport.includes("'total_performance','label'=>'合计业绩'") && !salespersonReport.includes("'day_'.$day.'_labor'") && !salespersonReport.includes('日手工'))
const beautyLargeOrderReport = phaseTwoReportService.slice(phaseTwoReportService.indexOf('private function salespersonBeautyLargeOrder'), phaseTwoReportService.indexOf('private function storeRefundLedger'))
check('beauty large-order report consumes frozen second-level category allocations without duplicating amounts', beautyLargeOrderReport.includes('cashier_v3_card_sale_category_allocation_fact') && beautyLargeOrderReport.includes('bc.beauty_cash_amount_cents') && beautyLargeOrderReport.includes("TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX") && beautyLargeOrderReport.includes('ccf.tenant_id') && beautyLargeOrderReport.includes('cc.tenant_id=s.tenant_id') && beautyLargeOrderReport.includes('cc.allocated_sale_total_cents=s.sale_amount_cents') && beautyLargeOrderReport.includes('cashier_v3_sales_manager_fact') && beautyLargeOrderReport.includes('cashier_v3_sales_order_line') && beautyLargeOrderReport.includes('checkout_line_id') && beautyLargeOrderReport.includes('sales_manager_name_snapshot') && !beautyLargeOrderReport.includes("whereLike('s.category_name_snapshot','%生美%')"))
check('large-order report keeps salesperson and sales-manager rows independent', beautyLargeOrderReport.includes("$fact['role_snapshot'] = 'salesperson'") && beautyLargeOrderReport.includes("$managerFact['role_snapshot'] = 'sales_manager'") && beautyLargeOrderReport.includes("$fact['role_snapshot'] ?? ''"))
check('beauty large-order winner is one highest second-level category per salesperson-month', beautyLargeOrderReport.includes("beautyLargeOrderMonthlyAwards") && beautyLargeOrderReport.includes("'beauty_category'=>'生美二级分类'") && beautyLargeOrderReport.includes("$winner['amount_cents'] < 3000000") && beautyLargeOrderReport.includes("$winner['amount_cents'] >= 5000000 ? 5000000 : 3000000"))

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)

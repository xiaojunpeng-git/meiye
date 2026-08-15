import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')
const router = read('前端代码/cashier-v3/src/router/index.js')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const view = read('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue')
const selectorService = read('后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php')
const api = read('前端代码/cashier-v3/src/services/storeBusinessReportApi.js')
const route = read('后端代码/route/cashier-v3.php')
const controller = read('后端代码/app/controller/cashier/v3/Report.php')
const adminRoute = read('后端代码/route/admin.php')
const adminController = read('后端代码/app/controller/admin/v1/report/UnifiedReport.php')
const reportService = read('后端代码/app/services/report/StoreUnifiedReportServices.php')
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
  'store_salesperson_performance'
]
check('report functions are upper-page tabs', reportCodes.every((code) => view.includes(`code: '${code}'`)) && view.includes('const REPORT_TABS') && view.includes('store-business-report__tabs') && view.includes('v-for="item in reportTabs"') && !view.includes("activeReport === 'overview'"))
check('the removed market report is absent from the catalog and the Vue 3 tabs', !view.includes("code: 'market_performance'") && !reportService.includes("'market_performance'"))
check('report tabs preserve the active runtime in stable deep links', router.includes("path: 'data/reports/:report?'") && router.includes("path: '/platform/reports/:report?'") && view.includes("router.push({ name: reportRouteName.value, params: { report: code } })"))
check('browser uses the explicit store or platform report adapter, never a legacy store endpoint', api.includes('/cashierapi/v3/report/${suffix}') && api.includes('/adminapi/report/${suffix}') && !api.includes('/storeapi/report/'))
check('V3 route exposes catalog, scope, query and export', route.includes("Route::get('report/unified/catalog', 'Report/catalog')") && route.includes("Route::get('report/unified/scope', 'Report/scope')") && route.includes("Route::get('report/unified/query', 'Report/query')") && route.includes("Route::get('report/unified/export', 'Report/export')"))
check('controller resolves employee scope and narrows requested stores', controller.includes('dataScopeFactory()->build') && controller.includes('scopeStoreIds') && controller.includes('array_intersect($requested, $allowed)'))
check('scope picker matches the platform twin-pane organization and store interaction', view.includes('store-business-report__scope-panel-body') && view.includes('store-business-report__scope-tree') && view.includes('store-business-report__scope-stores') && view.includes('selectScopeOrganization') && view.includes('selectScopeStore') && view.includes('选择组织查询其全部下级门店；选择门店仅查询该门店。'))
check('status metadata is not rendered while pending metrics remain visible', !view.includes('aria-label="报表数据状态"') && view.includes('pendingMetrics'))
check('person filters use keyword-gated controlled selector modal', view.includes("requestCashierV3Action('query-query-entities'") && view.includes('openPersonnelPicker') && view.includes('personnelPicker.open') && view.includes('默认不加载人员') && selectorService.includes("'group_sales_managers'") && selectorService.includes("'group_guides'") && selectorService.includes("mb_strlen($keyword) < 2"))
check('report columns and dynamic payment groups come from server metadata', view.includes('result.value.column_groups') && view.includes('result.value.columnGroups') && view.includes('hasGroupedColumns') && view.includes('columnGroups') && !view.includes('paymentMethods.reduce'))
check('every report can show current-column business explanations', view.includes('列名取值来源') && view.includes('currentFieldExplanations') && view.includes('function fieldLogic(column)') && view.includes("key.startsWith('payment_')") && view.includes("/^day_\\d+_consume$/"))
check('editable report rows use line-level stable keys', view.includes(':key="row.source_line_id || `${row.order_id || \'\'}:${rowIndex}`"'))
check('partner detail uses the manual input solution fields', view.includes('PARTNER_MANUAL_FIELDS') && view.includes('savePartnerEdit') && view.includes('partnerEditDraft') && view.includes("label: '私美复诊'") && view.includes("label: '私美类型'") && view.includes('expert_name'))
check('manual input solution can persist an explicit empty value', view.includes('changedFields') && view.includes('field_value: value') && view.includes("String(row[key] ?? '') !== String(partnerEditDraft.value[key] ?? '').trim()"))
check('shared report view keeps the annotation key and version contract', view.includes('row?.source_line_id || row?.order_id || row?.order_no_snapshot') && view.includes('expected_version') && view.includes('medical_elevation') && view.includes('medical_followup'))
check('platform report route bypasses the cashier session and workbench bootstrap', router.includes("path: '/platform/reports/:report?'") && router.includes("platformReport: true") && router.includes('if (to.meta.platformReport === true) return true'))
check('platform adapter uses the administrator cookie and platform report endpoints only in platform mode', api.includes('readPlatformAdminToken') && api.includes("/adminapi/report/${suffix}") && api.includes("credentials: platform ? 'same-origin' : 'omit'") && api.includes("'unified/personnel'"))
check('local Vue 3 platform report requests use the platform gateway without rerouting other admin calls', viteConfig.includes("const platformApiTarget") && viteConfig.includes("'/adminapi/report'") && viteConfig.includes('target: platformApiTarget'))
check('local starter injects the host platform gateway into the Vue 3 container', read('scripts/start-local.sh').includes('PLATFORM_API_PROXY_TARGET="http://host.docker.internal:${PLATFORM_API_PORT}"'))
check('platform scope and personnel endpoints are server-clipped to the administrator range', adminRoute.includes("Route::get('unified/scope', 'v1.report.UnifiedReport/scope')") && adminRoute.includes("Route::get('unified/personnel', 'v1.report.UnifiedReport/personnel')") && adminController.includes('buildPickerTree($allowed)') && adminController.includes('resolveScopedStoreIdsFromRequest') && adminController.includes("whereIn('ss.store_id', $allowed)"))
check('Vue 2 report pages only redirect to the Vue 3 platform route', platformReport.includes('StoreBusinessReportCompatibilityRedirect') && platformReport.includes('/view_cashier_v3/#/platform/reports/') && !platformReport.includes('unifiedBusinessReportQuery'))
check('platform manual input sends the source row store scope', view.includes('function annotationStoreScope(row)') && view.includes('...annotationStoreScope(row)') && reportService.includes('s.store_id,s.business_date'))
const salespersonReport = reportService.slice(reportService.indexOf('private function salespersonPerformance'), reportService.indexOf('private function marketPerformance'))
check('salesperson report has only one daily performance column and no labor columns', salespersonReport.includes("'day_'.$day.'_performance'") && salespersonReport.includes("'label'=>$day.'日业绩'") && salespersonReport.includes("'total_performance','label'=>'合计业绩'") && !salespersonReport.includes("'day_'.$day.'_labor'") && !salespersonReport.includes('日手工'))

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)

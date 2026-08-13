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
const reportService = read('后端代码/app/services/report/StoreUnifiedReportServices.php')

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
  'market_performance'
]
check('seven report functions are upper-page tabs', reportCodes.every((code) => view.includes(`code: '${code}'`)) && view.includes('const REPORT_TABS') && view.includes('store-business-report__tabs') && view.includes('v-for="item in reportTabs"') && !view.includes("activeReport === 'overview'"))
check('report tabs have stable deep links', router.includes("path: 'data/reports/:report?'") && view.includes("router.push({ name: 'cashier-v3-store-business-reports', params: { report: code } })"))
check('browser uses only Cashier V3 report endpoints', api.includes('/cashierapi/v3/report/unified/catalog') && api.includes('/cashierapi/v3/report/unified/query') && api.includes('/cashierapi/v3/report/unified/export') && !api.includes('/storeapi/report/'))
check('V3 route exposes catalog query and export', route.includes("Route::get('report/unified/catalog', 'Report/catalog')") && route.includes("Route::get('report/unified/query', 'Report/query')") && route.includes("Route::get('report/unified/export', 'Report/export')"))
check('controller injects session store scope and has no client store input', controller.includes('services->query((int)$this->storeId, $input)') && controller.includes('services->export((int)$this->storeId, $input)') && !controller.includes("['store_id'"))
check('status metadata is displayed while pending metrics remain visible', view.includes('result.coverage_start') && view.includes('result.data_as_of') && view.includes('result.metric_version') && view.includes('aggregation_caught_up') && view.includes('pendingMetrics'))
check('person filters use keyword-gated controlled selector modal', view.includes("requestCashierV3Action('query-query-entities'") && view.includes('openPersonnelPicker') && view.includes('personnelPicker.open') && view.includes('默认不加载人员') && selectorService.includes("'group_sales_managers'") && selectorService.includes("'group_guides'") && selectorService.includes("mb_strlen($keyword) < 2"))
check('report columns and dynamic payment groups come from server metadata', view.includes('result.value.column_groups') && view.includes('result.value.columnGroups') && view.includes('hasGroupedColumns') && view.includes('columnGroups') && !view.includes('paymentMethods.reduce'))

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)

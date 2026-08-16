import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const currentDir = path.dirname(fileURLToPath(import.meta.url))
const repo = process.env.REPO_ROOT || path.resolve(currentDir, '../../..')
const read = (relative) => fs.readFileSync(path.join(repo, relative), 'utf8')

const adminReports = read('前端代码/admin/src/libs/sixDimensionReports.js')
const adminRouter = read('前端代码/admin/src/router/modules/report.js')
const adminSettingRouter = read('前端代码/admin/src/router/modules/setting.js')
const adminFrame = read('前端代码/admin/src/pages/report/data/six_dimension.vue')
const cashierRouter = read('前端代码/cashier-v3/src/router/index.js')
const reportView = read('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue')
const reportApi = read('前端代码/cashier-v3/src/services/storeBusinessReportApi.js')
const configApi = read('前端代码/cashier-v3/src/services/sixDimensionReportConfigApi.js')
const configView = read('前端代码/cashier-v3/src/views/ConsumptionTierConfigView.vue')
const reportTemplate = reportView.slice(reportView.indexOf('<template>'), reportView.indexOf('</template>'))

const reports = [
  ['six_dimension_item_deal_analysis', '品项成交分析表'],
  ['six_dimension_cash_consumption_analysis', '现金消费分析表'],
  ['six_dimension_consumption_refund_detail', '消耗及退款明细'],
  ['six_dimension_performance_deal', '业绩成交表'],
  ['six_dimension_performance_distribution', '业绩分布表'],
  ['six_dimension_performance_market_distribution', '业绩市场分布表']
]

let failed = 0
function check(name, condition) {
  if (condition) console.log(`PASS ${name}`)
  else { failed += 1; console.error(`FAIL ${name}`) }
}

for (const [code, title] of reports) {
  check(`stable report registration ${code}`, adminReports.includes(`code: '${code}'`) && adminReports.includes(`title: '${title}'`))
  check(`platform runtime registration ${code}`, reportView.includes(`code: '${code}'`) && reportView.includes(`name: '${title}'`))
}

check('six reports have independent platform permission routes',
  adminRouter.includes('...SIX_DIMENSION_REPORTS.map')
    && adminRouter.includes('path: `six-dimension-center/${report.code}`')
    && adminRouter.includes('auth: [`admin-report-six-dimension-${report.code}`]'))
check('consumption tier config has its own permission route',
  adminSettingRouter.includes("path: 'shop/six-dimension-consumption-tier'")
    && adminSettingRouter.includes("auth: ['setting-shop-six-dimension-consumption-tier']")
    && adminSettingRouter.includes("settingsPage: 'consumption-tiers'"))
check('platform iframe targets the platform-only report runtime',
  adminFrame.includes('/platform/reports/${encodeURIComponent(this.reportCode)}')
    && adminFrame.includes("'/platform/six-dimension/consumption-tiers'"))
check('platform legacy and six-dimension report routes keep independent tab directories',
  reportView.includes('function isSixDimensionRoute()')
    && reportView.includes("SIX_DIMENSION_REPORT_TABS.some((tab) => tab.code === requested)")
    && reportView.includes('isSixDimensionRoute() ? SIX_DIMENSION_REPORT_TABS : REPORT_TABS')
    && reportView.includes('tabs.filter((tab) => serverCatalogByCode.value.has(tab.code))'))
check('platform-only routes bypass store session while store report route remains separate',
  cashierRouter.includes("path: '/platform/six-dimension/consumption-tiers'")
    && cashierRouter.includes('meta: { platformReport: true'))
check('field explanations read server business metadata first',
  reportView.includes('column?.source_explanation || column?.logic')
    && reportView.includes('function columnDisplayLabel(column)')
    && reportView.includes('`${group}/${label}`')
    && reportView.includes('label: columnDisplayLabel(column)')
    && reportView.includes('csvValue(columnDisplayLabel(column))'))
check('six-dimension reports allow the selected historical date and month without frontend cutoff',
  reportView.includes("const LEGACY_COVERAGE_START = '2026-08-10'")
    && reportView.includes('isSixDimensionReport.value && startDate.value === LEGACY_COVERAGE_START')
    && reportView.includes(':min="isFirstPhaseReport ? LEGACY_COVERAGE_START : undefined"')
    && !reportView.includes('SIX_DIMENSION_COVERAGE_START')
    && !reportView.includes('PERFORMANCE_DISTRIBUTION_MIN_MONTH'))
check('performance distribution uses one natural-month selector',
  reportView.includes("activeReport.value === 'six_dimension_performance_distribution'")
    && reportView.includes('type="month"')
    && reportView.includes('monthBounds(selectedMonth.value)'))
check('multi-level headers honor backend rowspan metadata for ungrouped business columns',
  reportView.includes('column?.header_rowspan || column?.headerRowspan')
    && reportView.includes('rowspan: declaredRowspan')
    && reportView.includes('group?.colspan || group?.col_span')
    && reportView.includes('(group.colspan || group.columns.length)'))
check('table column widths are rendered from backend layout metadata',
  reportView.includes('column?.width || column?.fixed_width')
    && reportView.includes('minWidth: `${declaredWidth}px`'))
check('category filter uses server category ids and descendant-aware backend contract',
  reportView.includes("field?.type || '') === 'category_tree'")
    && reportView.includes('queryStoreBusinessReportCategories')
    && reportApi.includes("endpoint(runtime, 'operations/categories')"))
check('complaint count uses common row edit and save interaction without visible version field',
  reportView.includes("'complaint_count'")
    && reportView.includes('@click="beginEdit(row)"')
    && reportView.includes('@click="saveEdit"')
    && reportView.includes('source_fact_id: Number(row?.source_fact_id || 0)')
    && reportView.includes("source_order_id: String(row?.source_order_id || '')")
    && reportView.includes("source_line_id: String(row?.source_line_id || '')")
    && reportView.includes(':key="rowKey(row) || `${activeReport}:${rowIndex}`"')
    && reportView.slice(reportView.indexOf('async function saveEdit()'), reportView.indexOf('function drilldownConfig')).includes('await loadReport()')
    && !reportTemplate.includes('版本'))
check('query and scope filters persist in the route before drilldown navigation',
  reportView.includes('function syncCurrentFiltersToRoute()')
    && reportView.includes('router.resolve(target).fullPath === route.fullPath')
    && reportView.includes('return router.replace(target)')
    && reportView.includes('query: currentRouteQuery()'))
check('consumption tiers support list save and ordering through platform APIs',
  configApi.includes('/adminapi/report/six-dimension/consumption-tiers')
    && configApi.includes('/adminapi/report/six-dimension/consumption-tiers/sort')
    && configApi.includes('tiers.map((tier) => ({ tier_code: tier.tier_code, expected_version: tier.version }))'))
check('consumption tier page supports add edit disable auditable delete and sort',
  configView.includes('新增分级')
    && configView.includes('openEdit(tier)')
    && configView.includes('Math.max(0, ...sortedRecords.value.map')
    && configView.includes("tier.enabled ? '停用' : '启用'")
    && configView.includes('await setTierEnabled(tier, false, true)')
    && configView.includes('sortConsumptionTiers'))

if (failed) {
  console.error(`\n${failed} frontend contract check(s) failed`)
  process.exit(1)
}
console.log('\nPASS six-dimension frontend contract')

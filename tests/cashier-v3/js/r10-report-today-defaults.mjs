import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { Blob } from 'node:buffer'

// 执行正本组件的响应式状态、路由恢复和查询/导出，不用静态文案代替日期链路验证。
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { computed, ref, reactive, nextTick } = require('vue')
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue', root), 'utf8')
const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1]
  .replace(/^import\s+[\s\S]*?\sfrom\s+['"][^'"]+['"]\s*\n/gm, '')
  .replace(/^import\s+['"][^'"]+['"]\s*\n/gm, '')
const constants = ['phaseFourReports', 'phaseSixReports'].map(name =>
  fs.readFileSync(new URL(`前端代码/cashier-v3/src/constants/${name}.js`, root), 'utf8').replace(/export /g, '')
).join('\n')
const route = reactive({ path: '/data/reports', params: {}, query: {}, meta: {}, name: 'store-business-report' })
const requests = []
const exports = []
const window = { location: { hash: '#/data/reports' }, sessionStorage: { getItem: () => null } }
window.top = window
const document = { body: { appendChild() {} }, createElement: () => ({ click() {}, remove() {} }) }
const context = vm.createContext({
  computed, ref, reactive, nextTick, watch() {}, onMounted() {}, onBeforeUnmount() {},
  useRoute: () => route, useRouter: () => ({ resolve: () => ({ fullPath: '' }) }), window, document,
  Blob, URL: { createObjectURL: () => 'blob:test', revokeObjectURL() {} },
  STORE_BUSINESS_REPORT_RUNTIME: { STORE: 'store', PLATFORM: 'platform' },
  queryStoreBusinessReport: async (params, runtime) => { requests.push({ params, runtime }); return { records: [], columns: [] } },
  exportStoreBusinessReport: async (params, runtime) => { exports.push({ params, runtime }); return { records: [], columns: [] } }
})
vm.runInContext(constants + '\n' + script + '\nthis.test = { STORE_REPORT_TABS, today, startDate, endDate, selectedMonth, selectedYear, reportPeriodMode, reportDateRange, syncActiveReportFromRoute, syncFiltersFromRoute, reportParams, loadReport, exportReport, currentRouteQuery, applyReportDateRange, errorMessage };', context)
const t = context.test
const day = t.today()
assert.equal(t.startDate.value, day)
assert.equal(t.endDate.value, day)
assert.equal(t.today(new Date('2026-10-10T16:01:00Z')), '2026-10-11')
assert.equal(t.today(new Date('2026-12-31T16:01:00Z')), '2027-01-01')
assert.equal(t.today(new Date('2024-02-28T16:01:00Z')), '2024-02-29')

for (const { code } of t.STORE_REPORT_TABS) {
  // R13 单独确认异业汇总改为自然年，其余门店报表保留 R10 的当天默认。
  const annual = code === 'cross_industry_customer_summary'
  const expectedStart = annual ? `${day.slice(0, 4)}-01-01` : day
  const expectedEnd = annual ? `${day.slice(0, 4)}-12-31` : day
  route.params = { report: code }
  route.query = {}
  t.startDate.value = '2026-09-01'
  t.endDate.value = '2026-09-30'
  t.syncActiveReportFromRoute()
  t.syncFiltersFromRoute()
  assert.equal(t.reportPeriodMode.value, annual ? 'year' : 'range', code)
  assert.equal(t.reportDateRange.value.min, expectedStart, code)
  assert.equal(t.reportDateRange.value.max, expectedEnd, code)
  await t.loadReport()
  await t.exportReport()
  assert.equal(t.errorMessage.value, '', code)
  for (const request of [requests.at(-1), exports.at(-1)]) {
    assert.equal(request.runtime, 'store', code)
    assert.equal(request.params.report, code)
    assert.equal(request.params.start_date, expectedStart, code)
    assert.equal(request.params.end_date, expectedEnd, code)
    assert.equal(request.params.year, annual ? day.slice(0, 4) : undefined, code)
    assert.equal(request.params.month, undefined, code)
  }
  assert.equal(t.currentRouteQuery().start_date, expectedStart, code)
}

// 显式日期与下钻范围不可被“当天默认值”覆盖，单边日期不能残留另一张报表的日期。
route.query = { start_date: '2026-09-03', end_date: '2026-09-08' }
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.reportParams().start_date, '2026-09-03')
assert.equal(t.reportParams().end_date, '2026-09-08')
route.query = { end_date: '2026-09-10' }
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.reportParams().start_date, '2026-09-10')
assert.equal(t.reportParams().end_date, '2026-09-10')
t.applyReportDateRange({ min: '2026-10-01', max: '2026-10-05' })
assert.equal(t.reportParams().start_date, '2026-10-01')
assert.equal(t.reportParams().end_date, '2026-10-05')

// 年份不是只改外观：查询、导出和路由全部提交所选年的1月1日至12月31日，刷新及旧链接同口径恢复。
route.params = { report: 'cross_industry_customer_summary' }
route.query = {}
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
t.selectedYear.value = '2025'
await t.loadReport(); await t.exportReport()
for (const request of [requests.at(-1), exports.at(-1)]) {
  assert.equal(request.params.year, '2025')
  assert.equal(request.params.start_date, '2025-01-01')
  assert.equal(request.params.end_date, '2025-12-31')
}
route.query = t.currentRouteQuery()
t.selectedYear.value = '2024'
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.selectedYear.value, '2025')
route.query = { start_date: '2024-12-01', end_date: '2025-02-01' }
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.selectedYear.value, '2025')
assert.equal(t.reportParams().start_date, '2025-01-01')
route.query = { end_date: '2024-06-01' }
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.selectedYear.value, '2024')
route.query = {}
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.selectedYear.value, day.slice(0, 4))
route.params = { report: 'cross_industry_customer_detail' }
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.reportPeriodMode.value, 'range')
assert.equal(t.reportParams().start_date, day)
assert.equal(t.reportParams().year, undefined)
assert.ok(source.includes('v-if="isCrossIndustryAnnualSummary || (isPlatformReport && usesAnnualYearFilter)"'))

// 共享正本仅改变门店默认值；平台 iframe 自然年/月限制及月初默认继续保持。
window.top = {}
route.path = '/platform/reports/operations_health_data'
route.meta = { platformReport: true }
route.params = { report: 'operations_health_data' }
route.query = {}
t.syncActiveReportFromRoute(); t.syncFiltersFromRoute()
assert.equal(t.reportPeriodMode.value, 'year')
assert.equal(t.reportParams().start_date, `${day.slice(0, 4)}-01-01`)
assert.equal(t.reportParams().end_date, `${day.slice(0, 4)}-12-31`)
route.params = { report: 'operations_beauty_item' }
t.syncActiveReportFromRoute()
assert.equal(t.reportPeriodMode.value, 'month')
assert.equal(t.reportParams().start_date, `${day.slice(0, 7)}-01`)
route.path = '/platform/reports/partner_item_summary'
route.params = { report: 'partner_item_summary' }
t.syncActiveReportFromRoute()
assert.equal(t.reportParams().start_date, `${day.slice(0, 7)}-01`)
assert.equal(t.reportParams().end_date, day)
console.log(`PASS ${t.STORE_REPORT_TABS.length} store reports: today defaults except R13 annual summary; year query/export/route/restore, explicit ranges, Shanghai midnight, platform unchanged`)

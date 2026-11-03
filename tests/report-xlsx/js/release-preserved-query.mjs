/** 固定发布树执行真实导出函数，防止切换XLSX时丢失已上线的大单可见月份绑定。 */
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
const tree = process.env.R6_FIXED_TREE
if (!/^[a-f0-9]{40}$/.test(tree || '')) throw Error('Fixed canonical Git tree required')
const source = execFileSync('git', ['show', `${tree}:前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`], { encoding: 'utf8', cwd: fileURLToPath(new URL('../../../', import.meta.url)) })
const bounds = source.slice(source.indexOf('function monthBounds('), source.indexOf('function yearBounds('))
const params = source.slice(source.indexOf('function reportParams('), source.indexOf('async function loadReport('))
const exported = source.slice(source.indexOf('async function exportReport('), source.indexOf('/** Round signed report amounts'))
let captured, saved
const context = {
  exporting: { value: false }, errorMessage: { value: '' }, isLargeOrderReport: { value: true },
  result: { value: { range: { start: '2026-10-01' } } }, route: { query: { month: '2026-11' } },
  selectedMonth: { value: '2026-12' }, reportPeriodMode: { value: 'month' },
  selectedYear: { value: '2026' }, activeReport: { value: 'large-order' }, categoryId: { value: '3' },
  categoryPath: { value: '六维/合作' }, productType: { value: 'project' }, partnerName: { value: '合作方' },
  personFilters: { value: { employee_id: '9' } }, dynamicFilters: { value: { field: 'kept' } },
  encodedResultFilters: () => '[exact-filter]', scopePicker: { value: { selectedStoreIds: [23] } },
  page: { value: 2 }, DEFAULT_LIMIT: 20, reportRuntime: { value: 'platform' }, currentReportName: { value: '大单报表' },
  today: () => '2026-11-03', exportStoreBusinessReportXlsx: async (p, runtime) => { captured = { ...p, runtime }; return 'real-xlsx' },
  saveReportWorkbook: (value, name) => { saved = { value, name } }
}
vm.createContext(context)
vm.runInContext(bounds + params + exported, context)
await context.exportReport()
assert.equal(captured.month, '2026-10'); assert.equal(captured.start_date, '2026-10-01'); assert.equal(captured.end_date, '2026-10-31')
assert.equal(captured.store_ids, '23'); assert.equal(captured.employee_id, '9'); assert.equal(captured.row_filters, '[exact-filter]')
assert.equal(captured.page, 1); assert.equal(captured.runtime, 'platform'); assert.equal(saved.value, 'real-xlsx'); assert.match(saved.name, /\.xlsx$/)
context.result.value = {}; context.route.query = { month: '2024-02' }
await context.exportReport(); assert.equal(captured.end_date, '2024-02-29')
context.route.query = {}; await context.exportReport(); assert.equal(captured.month, '2026-12')
context.exportStoreBusinessReportXlsx = async () => { throw Error('server failure') }
await context.exportReport(); assert.equal(context.exporting.value, false); assert.equal(context.errorMessage.value, 'server failure')
console.log('PASS fixed XLSX export preserves visible month, leap month, organization/filter scope and failure cleanup')

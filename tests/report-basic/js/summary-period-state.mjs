/** Execute the canonical page setup with reactive state and synthetic API results.
 * Date edits must stay drafts; query, paging and export retain the executed scope.
 * No business records, auth or remote requests are created by this regression.
 */
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import vm from 'node:vm'
import { webcrypto } from 'node:crypto'
import { formatReportMoney } from '../../../前端代码/shared/unified-query-vue3/src/contracts/reportPresentation.js'
import assert from 'node:assert/strict'
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const require = createRequire(path.join(repo, '前端代码/cashier-v3/package.json'))
const vue = require('vue'), { parse } = require('@babel/parser')
const source = readFileSync(path.join(repo, '前端代码/cashier-v3/src/views/BasicReportsView.vue'), 'utf8').split('<script setup>')[1].split('</script>')[0]
const imports = parse(source, { sourceType: 'module' }).program.body.filter(n => n.type === 'ImportDeclaration')
let script = source
for (const n of imports.reverse()) script = script.slice(0, n.start) + script.slice(n.end)
const clone = x => JSON.parse(JSON.stringify(x))
const syntheticFields = [{ key: 'store', label: '门店', defaultVisible: true }, { key: 'customers', label: '总客数', type: 'decimal', defaultVisible: true }, { key: 'quantity', label: '项目数', type: 'decimal', display_precision: 2, defaultVisible: true }]
const result = q => ({ rows: [{ row_id: 'synthetic', store: '合成门店', customers: '899.000000' }], pagination: { total: 21, page: q.page }, queryFields: syntheticFields, range: { start_date: q.start_date, end_date: q.end_date }, summaries: { 'customers:sum': '899.000000' }, summary_cards: [{ key: 'customers', label: '总客数', value: '899.000000' }] })
async function check(platform) {
  const requests = [], exports = [], mounted = [], deletions = []
  let respond = async q => result(q)
  const ctx = { ...vue, watch: () => {}, onBeforeUnmount: () => {}, onMounted: fn => mounted.push(fn),
    useRoute: () => ({ params: { report: 'basic_store_summary' }, meta: { platformReport: platform } }), useCashierV3State: () => ({ currentStore: { id: 1 } }),
    cloneUnifiedQuerySnapshot: clone, formatReportMoney, crypto: webcrypto,
    deleteBasicRegistration: async (host, body) => { deletions.push({ host, body: clone(body) }) },
    loadBasicReportCatalog: async () => [{ code: 'basic_store_summary', name: '门店统计表', available: true, time_mode: 'range', queryFields: syntheticFields }],
    loadBasicReportScope: async () => ({ tree: [], allowed_store_ids: [1], authorization_mode: 'all' }),
    loadBasicReport: async (host, q, stores) => { requests.push({ host, q: clone(q), stores: clone(stores) }); return respond(clone(q)) },
    downloadBasicReportXlsx: async (host, q) => { exports.push({ host, q: clone(q) }); return {} }, saveReportWorkbook: () => {},
    document: { documentElement: { classList: { add() {} } } }, AbortController, clearTimeout, setTimeout, Intl, console }
  vm.createContext(ctx)
  vm.runInContext(script + '\nthis.page = { dates, data, loading, executedQuery, resultPeriod, applyPeriod, submitQuery, run, exportRows, displaySummaryCard, summary, selectScope, catalog, display, requestDelete, confirmDelete, deleteDialog };', ctx)
  await mounted[0]()
  const p = ctx.page, initial = clone(p.executedQuery.value)
  p.applyPeriod({ min: '2026-10-01', max: '2026-10-31' })
  assert.equal(requests.length, 1, 'date editing must not auto-query')
  assert.equal(p.resultPeriod.value.start, initial.start_date)
  assert.equal(p.resultPeriod.value.pending, initial.start_date !== '2026-10-01' || initial.end_date !== '2026-10-31')
  await p.exportRows(); assert.equal(exports[0].q.start_date, initial.start_date, 'export must not adopt pending date')
  assert.equal(p.displaySummaryCard({ key: 'customers', value: '899.000000' }), '899')
  assert.equal(p.displaySummaryCard({ key: 'customers', value: '0.000000' }), '0')
  assert.equal(p.displaySummaryCard({ key: 'customers', value: '2.125000' }), '2.125')
  assert.equal(p.displaySummaryCard({ key: 'quantity', value: '2.125000' }), '2.13')
  assert.equal(p.displaySummaryCard({ key: 'customers', value: null }), '-')
  assert.equal(p.data.value.summary_cards[0].value, '899.000000', 'formatting must not rewrite source values')
  let finish
  respond = q => new Promise(resolve => { finish = () => resolve(result(q)) })
  p.submitQuery(initial)
  assert.equal(p.loading.value, true)
  assert.equal(p.resultPeriod.value, null, 'old period must not label pending results')
  assert.equal(p.summary(syntheticFields[1], 1), '-')
  assert.equal(requests.at(-1).q.start_date, '2026-10-01', 'new dates override toolbar executed snapshot')
  finish(); await new Promise(resolve => setTimeout(resolve, 0))
  assert.equal(p.executedQuery.value.end_date, '2026-10-31')
  assert.equal(p.resultPeriod.value.pending, false)
  respond = async q => result(q)
  p.applyPeriod({ min: '2026-09-01', max: '2026-09-30' })
  await p.run({ ...p.executedQuery.value, page: 2 })
  assert.equal(requests.at(-1).q.start_date, '2026-10-01', 'paging keeps executed dates')
  await p.exportRows(); assert.equal(exports.at(-1).q.start_date, '2026-10-01')
  assert.equal(p.resultPeriod.value.pending, true)
  // Even a non-abortable earlier response may not replace the newer query.
  const pending = []
  respond = q => new Promise(resolve => pending.push(() => resolve(result(q))))
  const first = p.run({ start_date: '2026-09-01', end_date: '2026-09-30', page: 1 })
  const second = p.run({ start_date: '2026-10-01', end_date: '2026-10-31', page: 1 })
  pending[1](); await second; pending[0](); await first
  assert.equal(p.executedQuery.value.start_date, '2026-10-01')
  assert.ok(requests.every(r => r.host === platform))
  // 已上线刷单删除必须仍经确认提交行证明；历史报表修复不得删除此入口或改其金额精度。
  // 独立执行刷单页实例，路由保持真实响应式字段，确保测试实际走刷单格式分支。
  const brush = { ...ctx, useRoute: () => ({ params: { report: 'basic_brush_registration' }, meta: { platformReport: platform } }), onMounted: () => {} }
  vm.createContext(brush)
  vm.runInContext(script + '\nthis.page = { catalog, display, summary, requestDelete, confirmDelete, deleteDialog, data, executedQuery };', brush)
  brush.page.catalog.value = [{ code: 'basic_brush_registration', time_mode: 'registration_date' }]
  assert.equal(brush.page.display({ amount: '39.90' }, { key: 'amount', money: true }), '¥39.90')
  brush.page.executedQuery.value = { start_date: '2026-10-01', end_date: '2026-10-31', page: 1 }
  brush.page.requestDelete({ registration_id: 18, store_id: 1, record_version: 3, source_hash: 'proof', store_name: '合成门店', coupon_code: 'test' })
  assert.equal(deletions.length, 0, 'opening confirmation must not delete')
  await brush.page.confirmDelete()
  assert.equal(deletions.length, 1)
  assert.equal(deletions[0].host, platform)
  assert.equal(deletions[0].body.expected_version, 3)
  assert.equal(deletions[0].body.expected_source_hash, 'proof')
  assert.equal(brush.page.deleteDialog.value, null)
  return { host: platform ? 'platform' : 'store', requests: requests.length, exports: exports.length, deletionConfirmation: true, brushAmountPrecision: true, passed: true }
}
console.log(JSON.stringify({ checks: await Promise.all([check(true), check(false)]) }, null, 2))

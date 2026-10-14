import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const require = createRequire(path.join(root, '前端代码/cashier-v3/package.json'))
const { parse: parseVue } = require('@vue/compiler-sfc')
const { parse: parseJavaScript } = require('@babel/parser')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue'), 'utf8')
const script = parseVue(source).descriptor.scriptSetup?.content || ''
const functions = new Map(parseJavaScript(script, { sourceType: 'module' }).program.body
  .filter((node) => node.type === 'FunctionDeclaration')
  .map((node) => [node.id.name, script.slice(node.start, node.end)]))
const needed = [
  'resolveColumnGroups', 'declaredHeaderPath', 'headerPathPart', 'sameHeaderPrefix',
  'buildHeaderRows', 'buildColumnGroupHeaderRows', 'exportHeaderRows'
]
needed.forEach((name) => assert.ok(functions.has(name), `${name} must be defined in the report view`))
assert.match(script, /exportHeaderRows\(exportColumns, exported\)/, 'the download must use its own export response')

// Execute the actual report-view projection functions; this test checks header placement,
// not amounts or any browser-side recalculation of report metrics.
const context = vm.createContext({ activeReport: { value: 'store_item_analysis' }, derivedHeaderGroupLabel: () => '基础信息' })
vm.runInContext(needed.map((name) => functions.get(name)).join('\n'), context)
const headersFor = (columns, groups) => JSON.parse(JSON.stringify(vm.runInContext(
  'exportHeaderRows(columns, exported)',
  vm.createContext({ ...context, columns, exported: { column_groups: groups } })
)))

const columns = [
  { key: 'division_name', label: '分公司' },
  { key: 'city_manager', label: '城市经理' },
  { key: 'store_name', label: '门店' },
  { key: 'item_analysis_cash_today', label: '当日' },
  { key: 'item_analysis_cash_cumulative', label: '累计' },
  { key: 'item_analysis_share_today', label: '当日' },
  { key: 'item_analysis_share_cumulative', label: '累计' },
  { key: 'category_17_cash', label: '现金业绩' },
  { key: 'category_17_after_split', label: '分成后业绩' },
  { key: 'category_17_consume', label: '消耗业绩' }
]
const groups = [
  { label: '分公司', column_keys: ['division_name'], rowspan: 2 },
  { label: '城市经理', column_keys: ['city_manager'], rowspan: 2 },
  { label: '门店', column_keys: ['store_name'], rowspan: 2 },
  { label: '现金业绩', column_keys: ['item_analysis_cash_today', 'item_analysis_cash_cumulative'] },
  { label: '分成业绩', column_keys: ['item_analysis_share_today', 'item_analysis_share_cumulative'] },
  { label: '生美', column_keys: ['category_17_cash', 'category_17_after_split', 'category_17_consume'] }
]
assert.deepEqual(headersFor(columns, groups), [
  ['分公司', '城市经理', '门店', '现金业绩', '', '分成业绩', '', '生美', '', ''],
  ['', '', '', '当日', '累计', '当日', '累计', '现金业绩', '分成后业绩', '消耗业绩']
])

const changedColumns = columns.slice(0, 7).concat([
  { key: 'category_42_cash', label: '现金业绩' },
  { key: 'category_42_after_split', label: '分成后业绩' },
  { key: 'category_42_consume', label: '消耗业绩' }
])
const changedGroups = groups.slice(0, 5).concat([{ label: '自营', column_keys: ['category_42_cash', 'category_42_after_split', 'category_42_consume'] }])
assert.equal(headersFor(changedColumns, changedGroups)[0][7], '自营', 'export response controls dynamic category headers')

context.activeReport.value = 'member_consumption_detail'
assert.deepEqual(headersFor(columns, groups), [columns.map((column) => column.label)], 'other report exports keep their existing header path')
console.log('PASS store item analysis export includes aligned group and leaf headers; other reports unchanged')

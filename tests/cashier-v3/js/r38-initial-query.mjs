import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { normalizeUnifiedQuerySettings } from '../../../前端代码/shared/unified-query-vue3/src/contracts/unifiedQueryContract.js'

// 执行真实首屏函数，覆盖未保存偏好和旧偏好；不依赖只检查字符串的假阳性。
const source = readFileSync('前端代码/cashier-v3/src/views/OrderCenterView.vue', 'utf8')
const body = source.match(/function initialOrderCenterQuery\(\) \{([^]*?)\n\}/)?.[1]
assert.ok(body)
const settings = { value: {} }
const date = { dateFrom: '2026-09-27', dateTo: '2026-09-27' }
const initial = new Function('querySettings', 'normalizeUnifiedQuerySettings', 'defaultOrderCenterDateQuery', 'reportServiceDateQuery', 'reportSalesDateQuery', body)
for (const [raw, expected] of [[{}, 'all'], [{ filterRelation: 'and' }, 'all'], [{ filterRelation: 'all' }, 'all'], [{ filterRelation: 'any' }, 'any']]) {
  settings.value = raw
  const result = initial(settings, normalizeUnifiedQuerySettings, () => date, () => null, () => null)
  assert.equal(result.filterRelation, expected)
  assert.equal(result.dateFrom, date.dateFrom)
  assert.deepEqual(result.filters, [])
}
settings.value = { filterRelation: 'any', filters: [{ field: 'salesperson', operator: 'eq', value: '7' }], sorts: [{ field: 'sales_order_no', direction: 'desc' }] }
const result = initial(settings, normalizeUnifiedQuerySettings, () => date, () => null, () => null)
assert.equal(result.filterRelation, 'any')
assert.equal(result.filters.length, 1)
assert.equal(result.sorts[0].field, 'sales_order_no')
const drill = { filterRelation: 'all', storeIds: [133] }
assert.equal(initial(settings, normalizeUnifiedQuerySettings, () => date, () => drill, () => null), drill)
console.log('PASS R38: initial empty/legacy/all/any, filters/sorts/date and drilldown preserved')

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { queryPreferenceKey, readQueryPreferences, writeQueryPreferences, mergeQueryRefresh } from '../../../前端代码/shared/unified-query-vue3/src/contracts/localQueryPreferences.js'

// 本地偏好隔离、损坏恢复、刷新快照深拷贝；不执行任何业务写命令。
const values = new Map()
const storage = { getItem: k => values.get(k) || null, setItem: (k, v) => values.set(k, v) }
const key = queryPreferenceKey('account', 1, 'sales')
writeQueryPreferences(key, { settings: { quickFields: ['salesperson'] }, expanded: false }, storage)
assert.deepEqual(readQueryPreferences(key, storage).settings.quickFields, ['salesperson'])
assert.equal(readQueryPreferences(queryPreferenceKey('other', 1, 'sales'), storage), null)
assert.equal(readQueryPreferences(queryPreferenceKey('account', 2, 'sales'), storage), null)
assert.equal(readQueryPreferences(queryPreferenceKey('account', 1, 'service'), storage), null)
values.set('broken', '{')
assert.equal(readQueryPreferences('broken', storage), null)
assert.throws(() => writeQueryPreferences('', {}, storage))
const previous = { page: 3, dataScope: 'all', topFilters: [{ field: 'business_date', value: '2026-09-20' }, { field: 'salesperson', value: 7 }] }
const refreshed = mergeQueryRefresh(previous, { silent: true })
assert.deepEqual(refreshed.topFilters, previous.topFilters)
refreshed.topFilters[0].value = 'changed'
assert.equal(previous.topFilters[0].value, '2026-09-20')
assert.deepEqual(mergeQueryRefresh(previous, { topFilters: [], filters: [], page: 1 }).topFilters, [])

// 强边界回归：与本轮基线逐函数比较，作废和人员调整操作必须原样保留。
const path = '前端代码/cashier-v3/src/views/OrderCenterView.vue'
const before = execFileSync('git', ['show', `ba7ebad8:${path}`], { encoding: 'utf8' })
const after = readFileSync(path, 'utf8')
for (const name of ['submitSalesPersonnelAdjustment', 'submitRecordPersonnelAdjustment', 'submitServiceCraftsmanAdjustment', 'submitServiceVoid', 'applySalesOrderVoidLocally']) {
  const extract = text => text.match(new RegExp(`(?:async )?function ${name}\\([^]*?(?=\\n(?:async )?function |\\n(?:const|watch|onMounted) )`))?.[0]
  assert.ok(extract(before), name)
  assert.equal(extract(after), extract(before), `${name} operation must remain unchanged`)
}
console.log('PASS: local preference isolation, refresh snapshot preservation, operation boundaries')

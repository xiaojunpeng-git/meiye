import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'

// Execute the real selector against intentionally conflicting cash/service data.
const view = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/MemberDashboardView.vue', import.meta.url), 'utf8')
const start = view.indexOf('function categoryRankRows(')
const end = view.indexOf('\nfunction scopeNodeStoreIds(', start)
const context = vm.createContext({ categoryRankMode: () => 'products', rankRows: () => [{ name: 'wrong-global' }] })
vm.runInContext(view.slice(start, end), context)
const category = { rankings: { products: [{ name: 'upper-product' }], consumption_projects: [{ name: 'served-project', consumption_cents: 1250 }], consumption_products: [{ name: 'obsolete-product' }] } }
assert.equal(context.categoryRankRows(category, 'consumptionAmount')[0].name, 'served-project')
assert.equal(context.categoryRankRows(category, 'consumption')[0].name, 'upper-product')
assert.equal(context.categoryRankRows({}, 'consumptionAmount').every(row => row.__empty), true)
assert.equal(context.categoryRankRows(category, 'consumptionAmount').length, 5)
const heading = view.indexOf('<h3>消耗排行</h3>')
assert.ok(heading > 0)
const lower = view.slice(heading, view.indexOf('</article>', heading))
assert.ok(!lower.includes('<button'))
assert.ok(lower.includes('money(row.consumption_cents)'))
assert.ok(!view.includes('实际业绩排行'))
assert.ok(!view.includes('consumption_products'))
assert.ok(view.includes('<h3>消费排行</h3>'))
console.log('PASS 10 R94 frontend selection/display checks')

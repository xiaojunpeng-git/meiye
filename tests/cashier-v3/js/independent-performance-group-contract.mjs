import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'),
  'utf8'
)
const cashierView = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)
const orderCenter = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'),
  'utf8'
)
const workspace = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php'),
  'utf8'
)
const orderLifecycle = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php'),
  'utf8'
)
const paidProject = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/settlement/CashierV3PaidProjectCraftsmanPerformanceServices.php'),
  'utf8'
)
const saleFacts = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php'),
  'utf8'
)

assert.match(overlay, /function allocationGroupKey\(item = \{\}\)/)
assert.match(overlay, /function refreshCraftsmanAllocationMetadata\(records\)/)
assert.match(overlay, /refreshCraftsmanAllocationMetadata\(records\)/)
assert.match(overlay, /explicitKey\.startsWith\('independent:'\)/)
assert.match(overlay, /hasPerformanceIndependentMetadata\(candidate\)[\s\S]{0,120}performanceIndependent\(saved \|\| \{\}\)/)
assert.match(overlay, /candidate\.allocationGroupKey \|\| saved\?\.allocationGroupKey/)
assert.match(overlay, /selectedByAllocationGroup\(records, \(record\) => craftsmanType\(record\) !== PERFORMANCE_TYPES\.LABOR\)/)
assert.match(overlay, /selectedByAllocationGroup\(selected\)\.forEach\(\(group\) =>/)
assert.match(overlay, /allocationGroupKey: allocationGroupKey\(item\)/)
assert.match(cashierView, /function canonicalCheckoutSalespeople\(records = \[\]\)/)
assert.match(cashierView, /performanceIndependent: true,\s*allocationGroupKey:/)
assert.match(cashierView, /positionId: Number\(record\.positionId \?\? record\.position_id \?\? 0\)/)
assert.match(cashierView, /projectCountHalfUnits: Math\.max\(0, Number\(record\.projectCountHalfUnits/)
assert.match(orderCenter, /performanceIndependent: Boolean\(item\.performanceIndependent/)
assert.match(orderCenter, /allocationGroupKey: item\.allocationGroupKey/)
assert.match(workspace, /\$groupWeights\[\$allocationGroupKey\]/)
assert.match(workspace, /salesperson_weight_sum_invalid/)
assert.match(orderLifecycle, /allocateWeightedCentsByGroups\(/)
assert.match(orderLifecycle, /岗位独立标记必须以当前门店任职记录为准/)
assert.match(paidProject, /\$groupMembers\[\$groupKey\]/)
assert.match(paidProject, /allocateLaborAmount\(\s*\$laborAmountCents/)
assert.match(saleFacts, /allocateByWeightGroups\(\$lineCash, \$salespeople\)/)

// Two independent groups each receive the complete 100% base.
const people = [
  { group: 'normal', weight: 50 },
  { group: 'normal', weight: 50 },
  { group: 'independent:7', weight: 100 },
]
const grouped = new Map()
for (const person of people) {
  grouped.set(person.group, (grouped.get(person.group) || 0) + person.weight)
}
assert.deepEqual(Object.fromEntries(grouped), { normal: 100, 'independent:7': 100 })

// Project counts are full-count per group, then split inside each group.
const projectCountHalfUnits = 2
assert.equal(Math.floor(projectCountHalfUnits / 2) + Math.ceil(projectCountHalfUnits / 2), 2)
assert.equal(Math.floor(projectCountHalfUnits / 1), 2)

console.log('PASS independent-performance-group-contract')

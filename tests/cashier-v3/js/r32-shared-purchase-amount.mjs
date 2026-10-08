import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const view = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const source = view.match(/function localEntitlementAmount\(lines = \[\], line = \{\}\) \{[\s\S]*?\n\}/)?.[0]
assert.ok(source, 'local entitlement allocator exists')
const localEntitlementAmount = new Function('moneyToCents', 'centsToMoney', 'isEntitlementLine',
  `${source}; return localEntitlementAmount`)(
  (value) => Math.round(Number(value) * 100),
  (cents) => cents / 100,
  (line) => line?.lineRole === 'entitlement_service'
)
const line = (detailId, consumed = 0, version = 'shared-card-purchase-amount-whole-yuan-floor-final-remainder-v1') => ({
  lineRole: 'entitlement_service', entitlementInstanceType: 'card_holder', entitlementInstanceId: 100,
  entitlementSourceDetailId: detailId, amountCalculationVersion: version, purchaseAmount: '2000.00',
  totalPurchaseTimes: 30, consumedTimesAtSelection: consumed, quantity: 1, displaySnapshot: {}
})
assert.equal(localEntitlementAmount([], line(1)), 66)
assert.equal(localEntitlementAmount([line(1)], line(2)), 66, 'different projects use same shared cursor')
assert.equal(localEntitlementAmount([], line(2, 29)), 86, 'last shared use gets yuan remainder')
assert.equal(localEntitlementAmount([line(1, 28)], line(2, 28)), 86, 'two projects in same checkout share last cursor')
assert.equal(localEntitlementAmount([line(1)], line(2, 0, 'independent-v1')), 66,
  'independent detail does not consume shared card cursor')
console.log('R32_SHARED_PURCHASE_AMOUNT_UI_PASS')

import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'

// Execute the actual Vue entry/append handlers without HTTP or financial writes.
const source = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const extract = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a)
  assert.ok(a >= 0 && b > a)
  return source.slice(a, b)
}
const entry = extract('async function selectCatalogItem(', '\nfunction openCustomCardUpgradeTarget(')
const confirm = extract('async function confirmGuidedBusiness(', '\nasync function appendMultiCardUpgradeCustomTarget(')
const originalRows = [
  { id: 'ordinary', lineRole: 'sale', amount: 398 },
  { id: 'product', lineRole: 'sale', amount: 200 },
  { id: 'service', lineRole: 'entitlement_service', quantity: 1 },
]
const draft = { lines: JSON.parse(JSON.stringify(originalRows)) }
const events = []
const ref = value => ({ value })
const context = vm.createContext({
  activeCardOperationUpgrade: ref(null), previewCardOperation: ref(null),
  currentCustomerMode: ref('member'), hasCartLines: ref(true),
  pendingCustomCardEntry: ref(false), guidedBusinessMode: ref(''),
  isCreatingCustomCard: ref(false), clonePlain: value => JSON.parse(JSON.stringify(value)),
  customCardPurchaseSnapshot: value => ({ sourceKind: 'custom_card', components: value.components }),
  appendLocalCashierDraftOperation: (_operation, apply) => apply(draft),
  reportEntitlementContractError: value => events.push(value),
  window: { dispatchEvent: value => events.push(value) },
  CustomEvent: class { constructor(type, data) { this.type = type; this.detail = data.detail } },
})
vm.runInContext(entry + '\n' + confirm, context)
await vm.runInContext('selectCatalogItem({id:"custom-card-entry"})', context)
assert.equal(context.guidedBusinessMode.value, 'custom-card')
assert.deepEqual(draft.lines, originalRows, 'opening preserves every existing row')
context.guidedBusinessMode.value = ''
assert.deepEqual(draft.lines, originalRows, 'cancelling changes no existing row')
context.result = { mode: 'custom-card', amount: 1000, payload: { cardName: 'R50', components: [{ times: 3, amount: 1000 }] } }
await vm.runInContext('confirmGuidedBusiness(result)', context)
assert.deepEqual(draft.lines.slice(0, 3), originalRows)
assert.equal(draft.lines.length, 4)
assert.equal(draft.lines[3].finalAmount, 1000)
assert.equal(draft.lines[3].localCustomCardConfiguration.cardName, 'R50')
assert.equal(context.guidedBusinessMode.value, '')
assert.equal(context.isCreatingCustomCard.value, false)
context.currentCustomerMode.value = 'guest'
await vm.runInContext('selectCatalogItem({id:"custom-card-entry"})', context)
assert.equal(context.pendingCustomCardEntry.value, true)
assert.equal(events.at(-1).type, 'cashier-v3:open-member-selector')
assert.equal(draft.lines.length, 4, 'member selection never clears the cart')
context.currentCustomerMode.value = 'member'
context.activeCardOperationUpgrade.value = { mode: 'card-upgrade' }
context.guidedBusinessMode.value = ''
await vm.runInContext('selectCatalogItem({id:"custom-card-entry"})', context)
assert.equal(context.guidedBusinessMode.value, '', 'existing upgrade exclusivity stays intact')
console.log('R50_CUSTOM_CARD_MIXED_ENTRY=PASS')

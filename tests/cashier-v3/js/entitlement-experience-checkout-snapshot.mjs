import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { serialize, deserialize } from 'node:v8'

const structuredClone = (value) => deserialize(serialize(value))

const source = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const names = [
  'recalculateLocalCashierDraft', 'localCheckoutPreviewSnapshot', 'buildCheckoutSnapshot',
  'cartLineExperienceSelected', 'checkoutSnapshotBusinessLines', 'checkoutSnapshotPaymentLines',
  'checkoutLineRole', 'checkoutSnapshotReceivableAmount', 'localDraftLineTotalAmountCents',
  'moneyToCents', 'centsToMoney', 'isLocalCashierDraftLine', 'localCheckoutPaymentMethods'
]
// Execute the real browser snapshot functions with local refs; no HTTP writes,
// server draft, or independently reimplemented checkout logic can mask a loss.
const functions = names.map((name) => {
  const start = source.indexOf(`function ${name}(`)
  assert.ok(start >= 0, name)
  const tail = source.slice(start)
  const end = tail.slice(1).search(/\n(?:async )?function /)
  return end < 0 ? tail : tail.slice(0, end + 1)
}).join('\n')
const identity = (value = []) => structuredClone(value)
const context = vm.createContext({
  structuredClone,
  clonePlain: structuredClone,
  localLineServiceSettings: { value: {} },
  localPersonnelAssignments: { value: {} },
  localCashierDraft: { value: {} },
  cashier: { value: { checkout: {} } },
  member: { value: { id: 1 } },
  currentMemberId: { value: 1 },
  currentCustomerMode: { value: 'member' },
  localCheckoutBusinessDate: { value: '2026-10-09' },
  localCheckoutBusinessDateReason: { value: '' },
  localCheckoutBusinessSource: { value: { primarySourceId: 1 } },
  cashierToday: '2026-10-09',
  localDraftBase: () => ({ lines: [] }),
  cartLineRole: (line) => line.lineRole,
  isProjectLine: (line) => line.kind === '项目',
  getLineAmount: (line) => line.finalAmount ?? line.amount ?? 0,
  lineDebtAmountCents: () => 0,
  canonicalCheckoutEntitlementCraftsmen: identity,
  canonicalCheckoutCraftsmen: identity,
  canonicalCheckoutSalespeople: identity,
  canonicalCheckoutAttributions: identity,
  cartLineServiceObject: (line) => line.serviceObject,
  cartLineFriendCountsAsCustomer: (line) => line.friendCountsAsCustomer,
  isInventoryManagedProductLine: (line) => line.kind === '产品',
  cartLinePresaleSelected: (line) => line.isPresale,
  cartLineInventoryOutboundRequired: (line) => line.inventoryOutboundRequired,
  isCustomCardPurchase: () => false,
  isLocalUpgradeOperationLine: () => false,
  checkoutBalancePreview: () => ({}),
  checkoutSourceSnapshot: () => ({ primarySourceId: 1 }),
  checkoutCardPurchaseSnapshot: () => ({}),
  isRecord: (value) => value !== null && typeof value === 'object' && !Array.isArray(value)
})
vm.runInContext(functions, context)
let checks = 0
const equal = (actual, expected, message) => {
  assert.equal(actual, expected, message)
  checks++
}
const benefit = (id, experience = false) => ({
  id, lineRole: 'entitlement_service', kind: '项目', quantity: 1,
  name: '权益测试项目', isExperience: experience, serviceObject: 'self',
  friendCountsAsCustomer: true, actualAmount: 349, amount: 349, finalAmount: 349,
  craftsmen: [{ staffId: 11 }]
})

// Cover true and explicit false, per-line isolation, and mixed checkout. The
// source cart stays unchanged: only the submitted clone freezes these values.
for (const [selected, initial] of [[true, false], [false, true]]) {
  for (const mixed of [false, true]) {
    const original = { memberId: 1, lines: [benefit('local-benefit', initial), benefit('local-other')] }
    if (mixed) original.lines.push({
      id: 'local-sale', lineRole: 'sale', kind: '项目', quantity: 1,
      amount: 120, finalAmount: 120, isExperience: true, craftsmen: []
    })
    context.localCashierDraft.value = original
    context.localLineServiceSettings.value = { 'local-benefit': { isExperience: selected } }
    const recalculated = context.recalculateLocalCashierDraft(structuredClone(original))
    equal(recalculated.lines[0].isExperience, selected, 'recalculation must retain the selected flag')
    equal(original.lines[0].isExperience, initial, 'recalculation must not mutate the source cart')
    const preview = context.localCheckoutPreviewSnapshot()
    equal(preview.lines[0].isExperience, selected, 'confirmation preview must show the selected flag')
    equal(preview.lines[1].isExperience, false, 'another entitlement line must not inherit the flag')
    equal(preview.composition.hasSale, mixed, 'composition must stay unchanged')
    const submitted = context.buildCheckoutSnapshot(preview)
    equal(submitted.lines[0].isExperience, selected, 'HTTP snapshot must retain the flag')
    equal(submitted.lines[1].isExperience, false, 'HTTP snapshot must preserve non-experience lines')
    equal(submitted.lines[0].actualAmount, 349, 'experience selection must not change entitlement valuation')
    equal(submitted.lines[0].quantity, 1, 'experience selection must not change entitlement usage')
    if (mixed) equal(submitted.lines[2].isExperience, true, 'purchased experience projects must keep working')
  }
}
context.localLineServiceSettings.value = {}
context.localCashierDraft.value = { memberId: 1, lines: [benefit('local-default')] }
equal(context.buildCheckoutSnapshot(context.localCheckoutPreviewSnapshot()).lines[0].isExperience, false,
  'an untouched project must remain non-experience')

// The common recalculation must retain adjacent deferred settings without
// sharing mutable personnel arrays back into the browser selection refs.
context.localLineServiceSettings.value = {
  'local-default': { serviceObject: 'friend', friendCountsAsCustomer: false, isExperience: true }
}
context.localPersonnelAssignments.value = {
  'local-default': { craftsmen: [{ staffId: 22 }], salespeople: [{ staffId: 23 }],
    guideSelections: [{ staffId: 24 }], salesManagerSelections: [{ staffId: 25 }] }
}
const merged = context.recalculateLocalCashierDraft(structuredClone(context.localCashierDraft.value))
equal(merged.lines[0].serviceObject, 'friend', 'service recipient must survive the merged draft')
equal(merged.lines[0].friendCountsAsCustomer, false, 'explicit non-counting choice must survive')
for (const [field, staffId] of [['craftsmen', 22], ['salespeople', 23], ['guideSelections', 24], ['salesManagerSelections', 25]]) {
  equal(merged.lines[0][field][0].staffId, staffId, `${field} must survive the merged draft`)
}
merged.lines[0].craftsmen[0].staffId = 99
equal(context.localPersonnelAssignments.value['local-default'].craftsmen[0].staffId, 22,
  'snapshot mutation must not alter the selected personnel')
console.log(`ENTITLEMENT_EXPERIENCE_CHECKOUT_SNAPSHOT=PASS checks=${checks}`)

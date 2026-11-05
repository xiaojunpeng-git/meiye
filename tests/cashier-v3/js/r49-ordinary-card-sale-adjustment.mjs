import assert from 'node:assert/strict'
import { ordinaryCardRows, applyOrdinaryCardRows, validateOrdinaryCardSale } from '../../../前端代码/cashier-v3/src/services/ordinaryCardSaleAdjustment.js'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
const structuredClone = value => JSON.parse(JSON.stringify(value))
const components = [
  { relationId: 1, nameSnapshot: '项目A', writeTimes: 3, configuredPriceCents: 10000 },
  { relationId: 2, nameSnapshot: '项目B', writeTimes: 2, configuredPriceCents: 20000 }
]
const item = {
  price: 600, ordinaryCardSaleEditable: true,
  cardPreview: { components: structuredClone(components) },
  cardPurchaseSnapshot: { ruleType: 'normal', components: structuredClone(components),
    ordinarySaleBaseline: { definition: { priceCents: 60000, purchase: { components: structuredClone(components) } } } }
}
const rows = ordinaryCardRows(item)
assert.equal(Object.hasOwn(rows[0], 'amount'), false)
rows[0].times = '7'
const result = applyOrdinaryCardRows(item, rows, '800')
assert.equal(result.price, 800)
assert.equal(result.cardPurchaseSnapshot.components[0].configuredAmountCents, 30000)
assert.equal(item.price, 600)
assert.equal(item.cardPurchaseSnapshot.components[0].writeTimes, 3)
assert.equal(applyOrdinaryCardRows(result, ordinaryCardRows(result)).price, 800)
assert.equal(applyOrdinaryCardRows(item, rows).price, 600)
for (const [field, value] of [['amount', '500'], ['times', '2'], ['times', '3.5'], ['times', '100000000']]) {
  const invalid = ordinaryCardRows(item); invalid[0][field] = value
  assert.throws(() => applyOrdinaryCardRows(item, invalid))
}
assert.throws(() => applyOrdinaryCardRows({ ...item, ordinaryCardSaleEditable: false }, rows))
validateOrdinaryCardSale(result.cardPurchaseSnapshot, 80000)
validateOrdinaryCardSale(result.cardPurchaseSnapshot, 160000, 2)
validateOrdinaryCardSale(item.cardPurchaseSnapshot, 1) // Unmodified manual pricing is not part of this feature.
assert.throws(() => validateOrdinaryCardSale(result.cardPurchaseSnapshot, 59900))
assert.throws(() => validateOrdinaryCardSale(result.cardPurchaseSnapshot, 119900, 2))
const tampered = structuredClone(result.cardPurchaseSnapshot)
tampered.components[1].configuredAmountCents = 39900
assert.throws(() => validateOrdinaryCardSale(tampered, 100000))
tampered.components[1].configuredAmountCents = 40000
tampered.components[1].writeTimes = 1
assert.throws(() => validateOrdinaryCardSale(tampered, 100000))
const view = readFileSync('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', 'utf8')
const add = view.slice(view.indexOf('async function confirmPendingCardPurchase()'), view.indexOf('async function handleOpenCardOperation'))
assert.ok(add.indexOf('validateOrdinaryCardSale(') < add.indexOf('await appendCatalogItemToDraft('))
const checkout = view.slice(view.indexOf('async function openCheckout()'), view.indexOf('function guardedOpenCheckout('))
assert.ok(checkout.indexOf('validateOrdinaryCardSale(') < checkout.indexOf('isCheckoutOpen.value = true'))
// Execute the actual entry handlers: invalid values must stop before cart append
// or opening the payment steps. No backend write or alternate snapshot is used.
let appended = 0
let errors = 0
const context = vm.createContext({
  projectAutoComplete: { value: true },
  pendingCardPurchase: { value: { ...result, id: 49 } }, isConfirmingCardPurchase: { value: false },
  validateOrdinaryCardSale, appendCatalogItemToDraft: async () => { appended++; return { status: 'success' } },
  resultStatus: value => value.status, reportEntitlementContractError: () => { errors++ },
  isRefreshingLineCoupon: { value: false }, localCheckoutBusinessSource: { value: { primarySourceId: 1 } },
  firstCartLineMissingCraftsmen: () => null, previewCardOperation: { value: null }, hasCartLines: { value: true },
  localCheckoutPreviewSnapshot: () => ({ lines: [{ lineRole: 'sale', quantity: 1, amount: 599,
    cardPurchaseSnapshot: result.cardPurchaseSnapshot }] }),
  localDraftLineTotalAmountCents: line => line.amount * 100,
  reportCheckoutEntryFailure: () => { errors++ }, isCheckoutOpen: { value: false }
})
vm.runInContext(add + '\n' + checkout, context)
context.pendingCardPurchase.value.cardPurchaseSnapshot = tampered
await vm.runInContext('confirmPendingCardPurchase()', context)
assert.equal(appended, 0)
assert.equal(errors, 1)
assert.equal(context.isConfirmingCardPurchase.value, false)
await vm.runInContext('openCheckout()', context)
assert.equal(context.isCheckoutOpen.value, false)
assert.equal(errors, 2)
context.pendingCardPurchase.value = { ...result, id: 49 }
await vm.runInContext('confirmPendingCardPurchase()', context)
assert.equal(appended, 1)
assert.equal(context.pendingCardPurchase.value, null)
assert.equal(errors, 2)
console.log('PASS R49 one card price, times-only rows, preserved signed weights, whole yuan and repeated edits')

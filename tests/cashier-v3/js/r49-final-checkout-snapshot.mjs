import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

// No HTTP or money writes: real PHP seal -> real Vue final snapshot -> real PHP verification.
const root = new URL('../../../', import.meta.url)
const phpTest = fileURLToPath(new URL('../php/r49-ordinary-card-sale-adjustment.php', import.meta.url))
const fixture = JSON.parse(execFileSync('php', [phpTest], {
  env: { ...process.env, R49_EXPORT_FIXTURE: '1' }, encoding: 'utf8'
}))
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const start = source.indexOf('function buildCheckoutSnapshot(')
const end = source.indexOf('\nasync function openCheckout(', start)
assert.ok(start >= 0 && end > start)
const clone = value => JSON.parse(JSON.stringify(value))
const identity = value => value || []
const context = vm.createContext({
  projectAutoComplete: { value: true },
  clonePlain: clone, isRecord: v => v !== null && typeof v === 'object' && !Array.isArray(v),
  checkoutSnapshotBusinessLines: lines => lines.filter(line => line.lineRole === 'sale'),
  cartLineRole: line => line.lineRole, checkoutLineRole: line => line.lineRole,
  isLocalUpgradeOperationLine: () => false,
  canonicalCheckoutEntitlementCraftsmen: identity, canonicalCheckoutCraftsmen: identity,
  canonicalCheckoutSalespeople: identity, canonicalCheckoutAttributions: identity,
  localDraftLinePayableAmountCents: line => line.finalAmount * 100,
  localDraftLineTotalAmountCents: (line, amount) => amount * 100,
  getLineAmount: line => line.amount,
  isProjectLine: () => false, isInventoryManagedProductLine: () => false,
  isCustomCardPurchase: () => false,
  checkoutCardPurchaseSnapshot: line => clone(line.cardPurchaseSnapshot),
  checkoutSnapshotReceivableAmount: () => 800,
  checkoutSnapshotPaymentLines: () => [], moneyToCents: value => value * 100,
  checkoutSourceSnapshot: () => ({})
})
vm.runInContext(source.slice(start, end), context)
context.input = { memberId: 49, customerMode: 'member', source: {}, lines: [{
  id: 'local-r49-transport', lineRole: 'sale', quantity: 1, amount: 800, finalAmount: 800,
  originalAmount: 800, cardPurchaseSnapshot: fixture.purchase,
  productVersion: 999, nestedProjection: { recordVersion: 999, ordinaryValue: 1 }
}] }
// Fulfillment is part of the same final business snapshot; a refreshed root
// must not replace the choice shown when checkout opened.
context.input.projectAutoComplete = false
context.projectAutoComplete.value = true
const frozenProjectMode = JSON.parse(JSON.stringify(vm.runInContext('buildCheckoutSnapshot(input)', context)))
assert.equal(frozenProjectMode.projectAutoComplete, false)
delete context.input.projectAutoComplete
console.log('PASS final checkout retains confirmed deferred project mode')
const actual = JSON.parse(JSON.stringify(vm.runInContext('buildCheckoutSnapshot(input)', context)))
assert.deepEqual(actual.lines[0].cardPurchaseSnapshot, fixture.purchase)
assert.equal(actual.lines[0].productVersion, undefined)
assert.equal(actual.lines[0].nestedProjection.recordVersion, undefined)
assert.equal(actual.lines[0].nestedProjection.ordinaryValue, 1)
assert.ok(!/"[^"\n]*(?:version|revision)"\s*:/i.test(JSON.stringify(actual)))
const result = execFileSync('php', [phpTest], {
  input: JSON.stringify(actual.lines[0].cardPurchaseSnapshot), encoding: 'utf8',
  env: { ...process.env, R49_VALIDATE_TRANSPORT: '1' }
})
process.stdout.write(result)
console.log('PASS final unique snapshot contains no versions and preserves signed row amounts/times')

// Repeat against the live local catalogue/key. New snapshots start from the
// card sale allocation (2380), while an old signed 2368 floor stays compatible.
const localTest = fs.readFileSync(new URL('../php/r49-ordinary-card-local.php', import.meta.url))
const dockerArgs = ['exec', '-i', '-e', 'R49_LOCAL_ONLY=1', '-e', 'R49_CONFIG_FIXTURE=enabled', '-e', 'R49_PRODUCT_ID=92820', '-e', 'R49_INCREASE_CENTS=100000']
const live = JSON.parse(execFileSync('docker', [...dockerArgs, '-e', 'R49_SETUP=export-transport', 'mohe-cashier-app', 'php'], {
  input: localTest, encoding: 'utf8'
}))
context.input.lines[0].cardPurchaseSnapshot = live.purchase
context.input.lines[0].amount = context.input.lines[0].finalAmount = context.input.lines[0].originalAmount = live.amountCents / 100
const liveFinal = JSON.parse(JSON.stringify(vm.runInContext('buildCheckoutSnapshot(input)', context)))
assert.equal(liveFinal.lines[0].lineAmountCents, 338000)
assert.equal(liveFinal.lines[0].cardPurchaseSnapshot.components[0].configuredAmountCents,
  (live.purchase.ordinarySaleBaseline.definition.componentSaleAmountsCents?.[0] ?? 236800) + 100000)
assert.equal(liveFinal.lines[0].cardPurchaseSnapshot.components[0].writeTimes, 18)
assert.ok(!/"[^"\n]*(?:version|revision)"\s*:/i.test(JSON.stringify(liveFinal)))
process.stdout.write(execFileSync('docker', [...dockerArgs, '-e', 'R49_SETUP=verify-transport',
  '-e', `R49_TRANSPORT_JSON=${JSON.stringify({ purchase: liveFinal.lines[0].cardPurchaseSnapshot, amountCents: 338000 })}`,
  'mohe-cashier-app', 'php'], { input: localTest, encoding: 'utf8' }))

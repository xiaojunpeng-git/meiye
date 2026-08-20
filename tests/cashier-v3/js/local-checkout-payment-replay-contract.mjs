import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root),
  'utf8'
)
const checkoutOverlay = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', root),
  'utf8'
)

const finalization = workbench.match(
  /async function finalizeLocalCheckoutPreview\(event = \{\}\) \{[\s\S]*?\n}\n\nfunction localCheckoutPaymentAmount/
)[0]
assert.match(finalization, /const checkoutSnapshot = buildCheckoutSnapshot\(preview \|\| \{\}\)/)
assert.match(finalization, /requestCheckoutAction\(\{[\s\S]*action: 'submit-checkout'[\s\S]*checkoutSnapshot/)
assert.doesNotMatch(finalization, /persistLocalCheckoutPaymentPreview|persistLocalCheckoutBusinessSourcePreview|for \(const operation of paymentOperations\)/)

const localPaymentBranch = workbench.slice(
  workbench.indexOf('function enqueueCheckoutAction'),
  workbench.indexOf('function checkoutDraftConflict')
)
assert.match(localPaymentBranch, /if \(action === 'add-payment-method'\)/)
assert.match(localPaymentBranch, /const checkoutLines = Array\.isArray\(preview\.lines\) \? preview\.lines : \[\]/)
assert.match(localPaymentBranch, /checkoutLines\.push\(\{ id: `local-payment-\$\{Date\.now\(\)\}`, lineRole: 'payment'/)
assert.match(localPaymentBranch, /preview\.lines = checkoutLines\.filter/)
assert.match(localPaymentBranch, /action === 'update-payment-line'/)
assert.match(localPaymentBranch, /localCheckoutPreview\.value = preview/)
assert.match(localPaymentBranch, /cashier-v3:checkout-draft-mutation-result/)
assert.doesNotMatch(localPaymentBranch, /requestAction\('prepare-checkout|prepare-checkout-submission/)
assert.doesNotMatch(localPaymentBranch, /payment\.selectedLines/)

const quantityChanges = workbench.match(
  /async function changeLineQuantity\(line, delta\) \{[\s\S]*?\n}\n\nfunction entitlementLineMaximum/
)[0]
assert.match(quantityChanges, /mutateCashierDraft\('change-cart-line-quantity'/)
assert.doesNotMatch(quantityChanges, /mutateRootCashierDraft\('change-cart-line-quantity'/)
assert.match(checkoutOverlay, /function queryOriginalCheckoutResult\(\)[\s\S]*request\('query-checkout-result'/)
assert.match(checkoutOverlay, /const canQueryCheckoutResult = computed\(/)
assert.match(workbench, /const queryCanUseOverlayIdentity = action === 'query-checkout-result'/)

console.log('LOCAL_CHECKOUT_PAYMENT_REPLAY_CONTRACT passed=12 failed=0')

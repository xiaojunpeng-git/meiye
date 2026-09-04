import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root),
  'utf8'
)
const checkoutEntry = workbench.match(
  /async function openCheckout\(\) \{[\s\S]*?\n}\n\nfunction guardedOpenCheckout/
)?.[0] || ''
const entitlementGuard = workbench.match(
  /function localCheckoutEntitlementAvailabilityFailure\(snapshot = \{\}\) \{[\s\S]*?\n}\n\nasync function setLineQuantity/
)?.[0] || ''

assert.match(entitlementGuard, /const usageBySource = new Map\(\)/)
assert.match(entitlementGuard, /if \(!isEntitlementLine\(line\)\) continue/)
assert.match(entitlementGuard, /CHECKOUT_ENTITLEMENT_TIMES_EXCEEDED/)
assert.match(entitlementGuard, /source\.quantity > source\.availableTimes/)
assert.doesNotMatch(entitlementGuard, /requestAction\(|requestCashierV3Action\(|fetch\(/)
assert.match(checkoutEntry, /const preview = localCheckoutPreviewSnapshot\(\)/)
assert.match(checkoutEntry, /const entitlementFailure = localCheckoutEntitlementAvailabilityFailure\(preview\)/)
assert.match(checkoutEntry, /localCheckoutPreview\.value = preview/)
assert.doesNotMatch(checkoutEntry, /prepare-checkout|synchronizeLocalCashierDraft|requestAction\(/)

console.log('LOCAL_CHECKOUT_ENTITLEMENT_SNAPSHOT_CONTRACT=PASS')

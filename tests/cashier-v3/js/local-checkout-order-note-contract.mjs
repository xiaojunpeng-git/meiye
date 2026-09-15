import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(
  new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root),
  'utf8'
)

const preview = workbench.match(
  /function localCheckoutPreviewSnapshot\(\) \{([\s\S]*?)\n}\n\nfunction checkoutSourceSnapshot/
)?.[1] || ''
const finalSnapshot = workbench.match(
  /function buildCheckoutSnapshot\(preview = \{\}\) \{([\s\S]*?)\n}\n\nasync function openCheckout/
)?.[1] || ''

assert.match(preview, /const draft = recalculateLocalCashierDraft\(/)
assert.match(preview, /orderNote: String\(draft\.orderNote \|\| ''\)/)
assert.match(finalSnapshot, /orderNote: String\(snapshot\.orderNote \|\| ''\)/)

console.log('LOCAL_CHECKOUT_ORDER_NOTE_CONTRACT=PASS')
